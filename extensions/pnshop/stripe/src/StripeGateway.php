<?php

namespace PnShop\Plugins\Stripe;

use Brick\Money\Money;
use Illuminate\Support\Str;
use PnShop\Payment\Contracts\PaymentGateway;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\PaymentContext;
use PnShop\Payment\PaymentResult;
use PnShop\Sales\OrderLinks;

/**
 * Stripe Checkout: the customer pays on Stripe's page and comes back; the payment is
 * confirmed by the return visit or the webhook, whichever comes first.
 */
class StripeGateway implements PaymentGateway
{
    public function __construct(private StripeClient $stripe) {}

    public function code(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Stripe (cards and wallets)';
    }

    public function settings(): array
    {
        // The API keys are plugin settings (encrypted), shared by every Stripe method.
        return [];
    }

    public function isAvailable(PaymentContext $context, PaymentMethod $method): bool
    {
        return $this->stripe->configured() && $context->total->isPositive();
    }

    public function initiate(Payment $payment, PaymentMethod $method): PaymentResult
    {
        $order = $payment->order;

        if ($order === null) {
            return PaymentResult::failed(__('The order for this payment was not found.'));
        }

        try {
            $session = $this->stripe->post('checkout/sessions', [
                'mode' => 'payment',
                'success_url' => route('stripe.return', ['payment' => $payment->id]).'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => OrderLinks::signedShow($order),
                'client_reference_id' => $order->number,
                'customer_email' => $order->email,
                'locale' => 'auto',
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower($payment->currency),
                        'unit_amount' => $payment->amount->getMinorAmount()->toInt(),
                        'product_data' => ['name' => __('Order :number', ['number' => $order->number])],
                    ],
                ]],
                'metadata' => ['payment_id' => (string) $payment->id, 'order_number' => (string) $order->number],
                // The shop is the seller and has already added tax: Stripe Managed Payments
                // (Stripe as merchant of record, on by default for new accounts) would ask for
                // product tax codes and tax the order again.
                'managed_payments' => ['enabled' => 'false'],
                'payment_intent_data' => ['metadata' => ['payment_id' => (string) $payment->id, 'order_number' => (string) $order->number]],
            ], self::startKey($payment));
        } catch (StripeException $e) {
            report($e);

            return PaymentResult::failed(__('The card payment could not be started. Please try again or choose another payment method.'));
        }

        return PaymentResult::redirect((string) $session['url'], (string) $session['id']);
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function refund(Payment $payment, Money $amount, PaymentMethod $method): PaymentResult
    {
        if (! str_starts_with((string) $payment->reference, 'pi_')) {
            return PaymentResult::failed(__('This payment has not been completed on Stripe.'));
        }

        try {
            $refund = $this->stripe->post('refunds', [
                'payment_intent' => $payment->reference,
                'amount' => $amount->getMinorAmount()->toInt(),
                'metadata' => ['payment_id' => (string) $payment->id],
            ], 'pnshop-refund-'.$payment->reference.'-'.$payment->refunded_amount->getMinorAmount()->toInt().'-'.$amount->getMinorAmount()->toInt());
        } catch (StripeException $e) {
            return PaymentResult::failed($e->getMessage());
        }

        return in_array($refund['status'] ?? null, ['succeeded', 'pending'], true)
            ? PaymentResult::refunded((string) $refund['id'], ['status' => $refund['status']])
            : PaymentResult::failed(__('Stripe did not accept the refund.'));
    }

    /**
     * The idempotency key for starting a payment. Each payment is started once, so the key
     * only has to cover the client's retries of that request; it is random because payment
     * ids repeat across shops (and reinstalls) sharing a Stripe account, and Stripe refuses,
     * or answers with the first result, a key used again within 24 hours. Refund keys use the
     * PaymentIntent id, which is unique on Stripe.
     */
    public static function startKey(Payment $payment): string
    {
        return 'pnshop-payment-'.$payment->id.'-'.Str::lower(Str::random(20));
    }

    public function instructions(Payment $payment, PaymentMethod $method): ?string
    {
        return null;
    }
}
