<?php

namespace PnShop\Payment;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PnShop\Catalog\Models\ProductVariant;
use PnShop\Inventory\InventoryService;
use PnShop\Inventory\OrderStockStatus;
use PnShop\Inventory\StockMovementReason;
use PnShop\Payment\Events\RefundCompleted;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\Models\PaymentTransaction;
use PnShop\Payment\Models\Refund;
use PnShop\Sales\Exceptions\OrderException;
use PnShop\Sales\Models\Order;
use PnShop\Sales\Models\OrderItem;
use PnShop\Sales\OrderWorkflow;
use PnShop\Sales\States\PaymentStatus;
use PnShop\Sales\StockAllocations;
use Throwable;

/**
 * Returns money to the customer through the payment's gateway.
 *
 * Refunded units that had not shipped are cancelled (their reservation is released and
 * they never ship); refunded units that had shipped are returns, put back on the shelf
 * when `restock` is on. The order's payment state becomes partially refunded or refunded.
 */
class RefundService
{
    public function __construct(
        private InventoryService $inventory,
        private OrderWorkflow $workflow,
    ) {}

    /**
     * @param  array<int, int>  $quantities  order item id => units to refund
     * @param  Money|null  $extra  an amount on top of the lines, e.g. the shipping
     *
     * @throws OrderException when nothing can be refunded, the amount is too high or the gateway fails.
     */
    public function refund(Order $order, array $quantities, ?Money $extra = null, bool $restock = false, ?string $reason = null, ?Model $actor = null): Refund
    {
        // One refund of an order at a time: the checks below must see the previous refund.
        $lock = Cache::lock('pnshop:refund:order:'.$order->id, 120);

        if (! $lock->block(15)) {
            throw new OrderException(__('Another refund of this order is in progress. Try again in a moment.'));
        }

        try {
            return $this->refundLocked($order, $quantities, $extra, $restock, $reason, $actor);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<int, int>  $quantities
     */
    private function refundLocked(Order $order, array $quantities, ?Money $extra, bool $restock, ?string $reason, ?Model $actor): Refund
    {
        $order->refresh()->load(['items', 'payments.method']);

        if (! in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
            throw new OrderException(__('Only paid orders can be refunded.'));
        }

        [$lines, $amount] = $this->linesAndAmount($order, $quantities, $extra);

        if (! $amount->isPositive()) {
            throw new OrderException(__('Choose what to refund.'));
        }

        $payment = $order->payments
            ->filter(fn (Payment $payment) => in_array($payment->status, [PaymentState::Paid, PaymentState::PartiallyRefunded], true))
            ->sortByDesc('id')
            ->first(fn (Payment $payment) => $payment->refundable()->isGreaterThanOrEqualTo($amount));

        if ($payment === null) {
            throw new OrderException(__('The refund is more than can be refunded from one payment (:amount).', [
                'amount' => $order->payments->reduce(fn (Money $max, Payment $p) => $p->refundable()->isGreaterThan($max) ? $p->refundable() : $max, Money::zero($order->currency))->formatToLocale(app()->getLocale()),
            ]));
        }

        $method = $payment->method;
        $gateway = $method?->gatewayInstance();

        if ($method === null || $gateway === null || ! $gateway->supportsRefunds()) {
            throw new OrderException(__('This payment method cannot refund from the shop. Return the money directly to the customer.'));
        }

        // Recorded first: if anything fails after the provider has returned the money, the
        // pending refund shows staff what to check instead of the refund going unrecorded.
        $refund = Refund::query()->create([
            'order_id' => $order->id,
            'payment_id' => $payment->id,
            'currency' => $order->currency,
            'amount' => $amount,
            'status' => Refund::PENDING,
            'restock' => $restock,
            'reason' => $reason,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
        ]);

        try {
            $result = $gateway->refund($payment, $amount, $method);
        } catch (Throwable $e) {
            Log::error('Payment gateway failed to refund.', ['gateway' => $gateway->code(), 'payment' => $payment->id, 'exception' => $e]);
            $result = PaymentResult::failed(__('The payment provider did not accept the refund.'));
        }

        DB::transaction(fn () => $this->record($refund, $order, $payment, $lines, $amount, $result, $reason, $actor));

        if ($refund->status === Refund::FAILED) {
            throw new OrderException((string) ($result->message ?: __('The payment provider did not accept the refund.')));
        }

        $fullyRefunded = $order->payments()->get()->every(fn (Payment $p) => ! in_array($p->status, [PaymentState::Paid, PaymentState::PartiallyRefunded], true));

        $this->workflow->transition($order, $fullyRefunded ? PaymentStatus::Refunded : PaymentStatus::PartiallyRefunded, $actor, $reason);

        RefundCompleted::dispatch($refund);

        return $refund;
    }

    /**
     * @param  array<int, int>  $quantities
     * @return array{list<array{OrderItem, int, Money}>, Money}
     */
    private function linesAndAmount(Order $order, array $quantities, ?Money $extra): array
    {
        $lines = [];
        $amount = $extra ?? Money::zero($order->currency);

        if ($amount->isNegative()) {
            throw new OrderException(__('The extra amount cannot be negative.'));
        }

        // With net prices the tax was added on top, so it is refunded with the line.
        $taxAdded = collect($order->totals ?? [])->contains(fn (array $line) => str_starts_with($line['code'], 'tax') && ! $line['included']);

        foreach ($order->items as $item) {
            $quantity = (int) ($quantities[$item->id] ?? 0);
            $left = $item->quantity - $item->quantity_refunded;

            if ($quantity > $left) {
                throw new OrderException(__('Only :left of :product can still be refunded.', ['left' => $left, 'product' => (string) $item->product_title]));
            }

            if ($quantity > 0) {
                // What was paid for these units: the price less their share of any promotion discount.
                $lineAmount = $item->unitPrice()->multipliedBy($quantity)->minus($item->discountFor($quantity));

                if ($taxAdded && $item->quantity > 0) {
                    $lineAmount = $lineAmount->plus($item->tax_amount->multipliedBy($quantity)->dividedBy($item->quantity, RoundingMode::HalfUp));
                }

                $lines[] = [$item, $quantity, $lineAmount];
                $amount = $amount->plus($lineAmount);
            }
        }

        return [$lines, $amount];
    }

    /**
     * @param  list<array{OrderItem, int, Money}>  $lines
     */
    private function record(Refund $refund, Order $order, Payment $payment, array $lines, Money $amount, PaymentResult $result, ?string $reason, ?Model $actor): Refund
    {
        $succeeded = $result->outcome === PaymentOutcome::Refunded;
        $restock = (bool) $refund->restock;

        $refund->forceFill([
            'status' => $succeeded ? Refund::COMPLETED : Refund::FAILED,
            'reference' => $result->reference,
        ])->save();

        PaymentTransaction::query()->create([
            'payment_id' => $payment->id,
            'type' => 'refund',
            'outcome' => $result->outcome->value,
            'currency' => $payment->currency,
            'amount' => $amount,
            'reference' => $result->reference,
            'message' => $result->message ?? $reason,
            'data' => $result->data === [] ? null : $result->data,
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
        ]);

        if (! $succeeded) {
            return $refund;
        }

        foreach ($lines as [$item, $quantity, $lineAmount]) {
            $refund->lines()->create(['order_item_id' => $item->id, 'quantity' => $quantity, 'amount' => $lineAmount->getMinorAmount()->toInt()]);
            $this->moveStock($order, $item, $quantity, $restock);
        }

        $refunded = $payment->refunded_amount->plus($amount);
        $payment->forceFill([
            'refunded_amount' => $refunded,
            'status' => $refunded->isGreaterThanOrEqualTo($payment->amount) ? PaymentState::Refunded : PaymentState::PartiallyRefunded,
        ])->save();

        return $refund;
    }

    private function moveStock(Order $order, OrderItem $item, int $quantity, bool $restock): void
    {
        $cancel = min($quantity, $item->quantityToShip());
        $returned = $quantity - $cancel;
        $variant = $item->product_variant_id === null ? null : ProductVariant::withTrashed()->find($item->product_variant_id);

        // Units that will never ship stop being held at their locations; returned ones go
        // back where they shipped from.
        if ($item->product_variant_id !== null && $cancel > 0) {
            app(StockAllocations::class)->cancel($item, $variant, $cancel, $order->stock_status === OrderStockStatus::Reserved);
        }

        if ($variant !== null && $returned > 0 && $restock) {
            $this->inventory->adjust($variant, $returned, StockMovementReason::Return, $order, location: app(StockAllocations::class)->returnLocation($item));
        }

        $item->forceFill([
            'quantity_refunded' => $item->quantity_refunded + $quantity,
            'quantity_cancelled' => $item->quantity_cancelled + $cancel,
        ])->save();
    }
}
