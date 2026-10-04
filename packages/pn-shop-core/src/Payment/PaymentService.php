<?php

namespace PnShop\Payment;

use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PnShop\Catalog\Pricing\PriceResolver;
use PnShop\Localization\CurrencyConverter;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\Models\PaymentTransaction;
use PnShop\Sales\Models\Order;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\PaymentStatus;
use Throwable;

/**
 * Chooses payment methods for a checkout, starts payments through their gateways and
 * applies the gateway's answer to the payment and the order's payment state.
 */
class PaymentService
{
    public function __construct(private OrderWorkflow $workflow) {}

    /**
     * Active methods whose gateway is installed and whose rules (amount, country,
     * gateway checks) accept this checkout, in display order.
     *
     * @return Collection<int, PaymentMethod>
     */
    public function availableMethods(PaymentContext $context): Collection
    {
        return PaymentMethod::query()->active()->orderBy('position')->orderBy('id')->get()
            ->filter(fn (PaymentMethod $method) => $this->accepts($method, $context))
            ->values();
    }

    public function accepts(PaymentMethod $method, PaymentContext $context): bool
    {
        $gateway = $method->gatewayInstance();
        $total = $context->total;
        // Limits are entered in the default currency.
        $limit = fn (?Money $amount) => $amount === null ? null : app(CurrencyConverter::class)->convert($amount, $total->getCurrency()->getCurrencyCode());
        $min = $limit($method->min_total);
        $max = $limit($method->max_total);

        return $method->is_active
            && $gateway !== null
            && ! ($min !== null && $total->isLessThan($min))
            && ! ($max !== null && $total->isGreaterThan($max))
            && ($method->countries === null || $method->countries === [] || $context->countryCode === null || in_array($context->countryCode, $method->countries, true))
            && ($method->customer_group_ids === null || $method->customer_group_ids === [] || in_array(app(PriceResolver::class)->contextFor($context->customer)->customerGroupId, array_map('intval', $method->customer_group_ids), true))
            && $gateway->isAvailable($context, $method);
    }

    /**
     * Start paying for a freshly placed order. A gateway error never loses the order:
     * the payment is marked failed and the customer can pay later or contact the store.
     */
    public function start(Order $order): PaymentResult
    {
        $method = $order->paymentMethod;

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $method?->id,
            'gateway' => $method->gateway ?? 'manual',
            'currency' => $order->currency,
            'amount' => $order->grandTotal(),
        ]);

        // Nothing to collect (a 100% discount): the order is paid as placed.
        if ($order->grandTotal()->isZero()) {
            return $this->apply($payment, PaymentResult::paid(), 'initiate');
        }

        $gateway = $method?->gatewayInstance();

        if ($method === null || $gateway === null) {
            return $this->apply($payment, PaymentResult::pending(), 'initiate');
        }

        try {
            $result = $gateway->initiate($payment, $method);
        } catch (Throwable $e) {
            Log::error('Payment gateway failed to start a payment.', ['gateway' => $gateway->code(), 'order' => $order->id, 'exception' => $e]);
            $result = PaymentResult::failed(__('The payment could not be started. Please contact us to complete your order.'));
        }

        return $this->apply($payment, $result, 'initiate');
    }

    /**
     * Record what the gateway reported (on start, a return from the provider or a webhook)
     * and move the order's payment state to match.
     *
     * Safe to call more than once with the same answer (a retried webhook, the return page
     * and the webhook both arriving): it is recorded once. A settled payment (paid or
     * refunded) never goes back to failed, authorized or pending.
     */
    public function apply(Payment $payment, PaymentResult $result, string $type, ?Model $actor = null): PaymentResult
    {
        $state = match ($result->outcome) {
            PaymentOutcome::Paid => PaymentState::Paid,
            PaymentOutcome::Authorized => PaymentState::Authorized,
            PaymentOutcome::Failed => PaymentState::Failed,
            default => null,
        };

        $applied = DB::transaction(function () use ($payment, $result, $type, $actor, $state): bool {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $repeated = $state !== null && $locked->status === $state
                && ($result->reference === null || $locked->reference === null || $locked->reference === $result->reference);
            $backwards = $state !== null && $state !== PaymentState::Paid
                && in_array($locked->status, [PaymentState::Paid, PaymentState::PartiallyRefunded, PaymentState::Refunded], true);

            if ($repeated || $backwards) {
                return false;
            }

            $this->record($locked, $type, $result->outcome->value, $locked->amount, $result->reference, $result->message, $result->data, $actor);

            // The gateway's id (a session on redirect, a charge when paid) is kept as soon as it is known.
            $changes = array_filter(['status' => $state, 'reference' => $result->reference], fn (mixed $value) => $value !== null);

            if ($changes !== []) {
                $locked->forceFill($changes)->save();
            }

            return true;
        });

        $payment->refresh();

        if (! $applied) {
            return $result;
        }

        $orderState = match ($result->outcome) {
            PaymentOutcome::Paid => PaymentStatus::Paid,
            PaymentOutcome::Authorized => PaymentStatus::Authorized,
            PaymentOutcome::Failed => PaymentStatus::Failed,
            default => null,
        };

        if ($orderState !== null && $payment->order !== null && $payment->order->payment_status !== $orderState) {
            $this->workflow->transition($payment->order, $orderState, $actor, $result->message);
        }

        return $result;
    }

    /**
     * Settle the order's open payments after staff recorded the order as paid; orders
     * without any payment row get one, so the payment ledger always matches the order.
     * When a payment is already paid (a gateway confirmed it), the ledger matches already
     * and the order's other open payments (an abandoned attempt) are left alone.
     */
    public function settle(Order $order, ?Model $actor = null): void
    {
        if ($order->payments()->whereIn('status', [PaymentState::Paid, PaymentState::PartiallyRefunded, PaymentState::Refunded])->exists()) {
            return;
        }

        $open = $order->payments()->get()->filter(fn (Payment $payment) => $payment->status->isOpen());

        if ($open->isEmpty()) {
            $open = collect([Payment::query()->create([
                'order_id' => $order->id,
                'payment_method_id' => $order->payment_method_id,
                'gateway' => $order->paymentMethod->gateway ?? 'manual',
                'currency' => $order->currency,
                'amount' => $order->grandTotal(),
            ])]);
        }

        foreach ($open as $payment) {
            $payment->forceFill(['status' => PaymentState::Paid])->save();
            $this->record($payment, 'manual', PaymentOutcome::Paid->value, $payment->amount, null, 'Recorded as paid by staff', [], $actor);
        }
    }

    /**
     * Cancel the open payments of a cancelled order.
     */
    public function cancelOpen(Order $order, ?Model $actor = null): void
    {
        foreach ($order->payments()->get()->filter(fn (Payment $payment) => $payment->status->isOpen()) as $payment) {
            $payment->forceFill(['status' => PaymentState::Cancelled])->save();
            $this->record($payment, 'cancel', PaymentState::Cancelled->value, null, null, 'Order cancelled', [], $actor);
        }
    }

    /**
     * What the customer should do to pay, for the order page.
     */
    public function instructions(Order $order): ?string
    {
        $payment = $order->payments()->latest('id')->first();

        if ($payment === null || $payment->status !== PaymentState::Pending) {
            return null;
        }

        $method = $payment->method;

        return $method === null ? null : $method->gatewayInstance()?->instructions($payment, $method);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(Payment $payment, string $type, string $outcome, ?Money $amount, ?string $reference, ?string $message, array $data, ?Model $actor): PaymentTransaction
    {
        return PaymentTransaction::query()->create([
            'payment_id' => $payment->id,
            'type' => $type,
            'outcome' => $outcome,
            'currency' => $payment->currency,
            'amount' => $amount,
            'reference' => $reference,
            'message' => $message,
            'data' => $data === [] ? null : $data,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
        ]);
    }
}
