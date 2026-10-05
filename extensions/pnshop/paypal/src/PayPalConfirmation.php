<?php

namespace PnShop\Plugins\PayPal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\PaymentResult;
use PnShop\Payment\PaymentService;
use PnShop\Sales\OrderWorkflow;

/**
 * Captures an approved PayPal order once (the return visit and the webhook may both
 * arrive) and applies the capture to its payment.
 */
class PayPalConfirmation
{
    public function __construct(private PayPalClient $paypal, private PaymentService $payments) {}

    /**
     * Capture the PayPal order of an open payment. A closed payment (the order was
     * cancelled) is never captured: the customer is not charged.
     */
    public function capture(Payment $payment, string $source): void
    {
        $orderId = (string) $payment->reference;

        if (! $payment->fresh()?->status->isOpen() || preg_match('/^[A-Z0-9]{5,40}$/', $orderId) !== 1) {
            return;
        }

        try {
            $order = $this->paypal->post('/v2/checkout/orders/'.$orderId.'/capture', [], 'pnshop-capture-'.$payment->id, ['Prefer' => 'return=representation']);
        } catch (PayPalException $e) {
            if ($e->issue === 'ORDER_ALREADY_CAPTURED') {
                $order = $this->paypal->get('/v2/checkout/orders/'.$orderId);
            } elseif ($e->issue === 'ORDER_NOT_APPROVED') {
                // The customer left PayPal without approving: nothing to do.
                return;
            } else {
                throw $e;
            }
        }

        if ((string) ($order['purchase_units'][0]['custom_id'] ?? $payment->id) !== (string) $payment->id) {
            throw new PayPalException('The PayPal order does not belong to this payment.');
        }

        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;

        if (is_array($capture)) {
            $this->apply($payment, $capture, $source);
        }
    }

    /**
     * Apply a capture (from the capture response or a PAYMENT.CAPTURE.* webhook).
     *
     * @param  array<string, mixed>  $capture  a PayPal capture object
     */
    public function apply(Payment $payment, array $capture, string $source): void
    {
        DB::transaction(function () use ($payment, $capture, $source) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $id = (string) ($capture['id'] ?? '');
            $status = (string) ($capture['status'] ?? '');

            if (! $payment->status->isOpen()) {
                $this->flagLateCapture($payment, $capture, $source);

                return;
            }

            $amountMatches = (string) ($capture['amount']['value'] ?? '') === PayPalGateway::value($payment->amount)
                && strtoupper((string) ($capture['amount']['currency_code'] ?? '')) === strtoupper($payment->currency);
            $data = ['capture' => $id, 'status' => $status, 'source' => $source];

            $result = match (true) {
                $status === 'COMPLETED' && $amountMatches => PaymentResult::paid($id, $data),
                $status === 'COMPLETED' => PaymentResult::failed(__('The amount paid on PayPal does not match the order.'), $data),
                in_array($status, ['DECLINED', 'FAILED'], true) => PaymentResult::failed(__('PayPal declined the payment.'), $data),
                // Under review at PayPal: PAYMENT.CAPTURE.COMPLETED arrives later.
                $status === 'PENDING' => PaymentResult::pending(null, $data),
                default => null,
            };

            if ($result !== null) {
                $this->payments->apply($payment, $result, $source);
            }
        });
    }

    /**
     * Money captured for a payment that was already closed: recorded once and flagged in the
     * order history, so staff can refund it in PayPal.
     *
     * @param  array<string, mixed>  $capture
     */
    private function flagLateCapture(Payment $payment, array $capture, string $source): void
    {
        $id = (string) ($capture['id'] ?? '');

        if (($capture['status'] ?? null) !== 'COMPLETED' || $id === '' || $payment->reference === $id || $payment->transactions()->where('type', 'late_payment')->where('reference', $id)->exists()) {
            return;
        }

        $payment->transactions()->create([
            'type' => 'late_payment',
            'outcome' => 'paid',
            'currency' => $payment->currency,
            'amount' => $payment->amount,
            'reference' => $id,
            'message' => 'Captured on PayPal after the payment was closed.',
            'data' => ['capture' => $id, 'source' => $source],
        ]);

        app(OrderWorkflow::class)->addNote(
            $payment->order()->firstOrFail(),
            __('PayPal captured a payment (:reference) after this order\'s payment was closed. Refund it in PayPal or reopen the order.', ['reference' => $id]),
        );

        Log::warning('PayPal capture received for a closed payment.', ['payment_id' => $payment->id, 'capture' => $id]);
    }
}
