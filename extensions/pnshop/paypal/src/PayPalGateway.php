<?php

namespace PnShop\Plugins\PayPal;

use Brick\Money\Money;
use Illuminate\Support\Str;
use PnShop\Payment\Contracts\PaymentGateway;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\Models\PaymentMethod;
use PnShop\Payment\PaymentContext;
use PnShop\Payment\PaymentResult;
use PnShop\Payment\PaymentState;
use PnShop\Sales\OrderLinks;
use PnShop\Settings\Settings;

/**
 * PayPal Checkout (Orders API v2): the customer approves the payment on PayPal and comes
 * back; the shop then captures it (or the CHECKOUT.ORDER.APPROVED webhook does). Nothing
 * is charged until the capture, so an order cancelled meanwhile is never charged.
 */
class PayPalGateway implements PaymentGateway
{
    /** Currencies PayPal accepts with decimal amounts (HUF and TWD need whole amounts and are left out). */
    public const CURRENCIES = ['AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'ILS', 'JPY', 'MYR', 'MXN', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD'];

    public function __construct(private PayPalClient $paypal) {}

    public function code(): string
    {
        return 'paypal';
    }

    public function label(): string
    {
        return 'PayPal';
    }

    public function settings(): array
    {
        // The credentials are plugin settings (the secret encrypted), shared by every PayPal method.
        return [];
    }

    public function isAvailable(PaymentContext $context, PaymentMethod $method): bool
    {
        return $this->paypal->configured()
            && $context->total->isPositive()
            && in_array($context->total->getCurrency()->getCurrencyCode(), self::CURRENCIES, true);
    }

    public function initiate(Payment $payment, PaymentMethod $method): PaymentResult
    {
        $order = $payment->order;

        if ($order === null) {
            return PaymentResult::failed(__('The order for this payment was not found.'));
        }

        try {
            $created = $this->paypal->post('/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => (string) $order->number,
                    // Ties PayPal's order and capture back to this payment (webhooks).
                    'custom_id' => (string) $payment->id,
                    // Unique per PayPal account: a retried payment of the same order gets its own.
                    'invoice_id' => $order->number.'-'.$payment->id,
                    'description' => mb_substr((string) __('Order :number', ['number' => $order->number]), 0, 127),
                    'amount' => ['currency_code' => $payment->currency, 'value' => self::value($payment->amount)],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'brand_name' => mb_substr((string) app(Settings::class)->get('store.name'), 0, 127),
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                    'return_url' => route('paypal.return', ['payment' => $payment->id]),
                    'cancel_url' => OrderLinks::signedShow($order),
                ]]],
            ], self::startKey($payment));
        } catch (PayPalException $e) {
            report($e);

            return PaymentResult::failed(__('The PayPal payment could not be started. Please try again or choose another payment method.'));
        }

        // Where the customer approves: "payer-action" (with payment_source), "approve" in older responses.
        $approve = null;

        foreach (is_array($created['links'] ?? null) ? $created['links'] : [] as $link) {
            if (is_array($link) && in_array($link['rel'] ?? null, ['payer-action', 'approve'], true) && is_string($link['href'] ?? null)) {
                $approve = $link['href'];
                break;
            }
        }

        if ($approve === null || ! is_string($created['id'] ?? null)) {
            return PaymentResult::failed(__('The PayPal payment could not be started. Please try again or choose another payment method.'));
        }

        return PaymentResult::redirect($approve, $created['id']);
    }

    /**
     * The PayPal-Request-Id for creating the PayPal order. Each payment is started once, so
     * the id only has to cover the client's retries of that request; it is random because
     * payment ids repeat across shops (and reinstalls) sharing a PayPal account, and PayPal
     * answers a repeated id with the first request's result. Captures and refunds use
     * PayPal's own (unique) order and capture ids.
     */
    public static function startKey(Payment $payment): string
    {
        return 'pnshop-payment-'.$payment->id.'-'.Str::lower(Str::random(20));
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function refund(Payment $payment, Money $amount, PaymentMethod $method): PaymentResult
    {
        // Once captured, a payment's reference is the capture id (before, the PayPal order id).
        $capture = (string) $payment->reference;

        if (! in_array($payment->status, [PaymentState::Paid, PaymentState::PartiallyRefunded], true) || $capture === '') {
            return PaymentResult::failed(__('This payment has not been completed on PayPal.'));
        }

        try {
            $refund = $this->paypal->post('/v2/payments/captures/'.rawurlencode($capture).'/refund', [
                'amount' => ['currency_code' => $amount->getCurrency()->getCurrencyCode(), 'value' => self::value($amount)],
            ], 'pnshop-refund-'.$capture.'-'.$payment->refunded_amount->getMinorAmount()->toInt().'-'.$amount->getMinorAmount()->toInt());
        } catch (PayPalException $e) {
            return PaymentResult::failed($e->getMessage());
        }

        return in_array($refund['status'] ?? null, ['COMPLETED', 'PENDING'], true)
            ? PaymentResult::refunded((string) ($refund['id'] ?? ''), ['status' => $refund['status']])
            : PaymentResult::failed(__('PayPal did not accept the refund.'));
    }

    public function instructions(Payment $payment, PaymentMethod $method): ?string
    {
        return null;
    }

    /** PayPal's amount format: "24.00" (no decimals for JPY). */
    public static function value(Money $money): string
    {
        return (string) $money->getAmount();
    }
}
