<?php

namespace PnShop\Plugins\PayPal\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PnShop\Payment\Models\Payment;
use PnShop\Payment\PaymentState;
use PnShop\Plugins\PayPal\PayPalClient;
use PnShop\Plugins\PayPal\PayPalConfirmation;
use PnShop\Plugins\PayPal\PayPalException;
use PnShop\Plugins\PayPal\WebhookSignature;
use PnShop\Sales\OrderLinks;
use PnShop\Settings\Settings;

class PayPalController
{
    /**
     * The customer comes back from PayPal with the PayPal order id (`token`). Only that id
     * proves the visitor is the customer: without it (anyone can guess payment ids) the
     * redirect goes to the plain order page, never to a signed link. The payment is then
     * captured through PayPal's API.
     */
    public function return(Request $request, int $payment, PayPalConfirmation $confirmation): RedirectResponse
    {
        $token = (string) $request->query('token', '');
        $model = Payment::query()->where('gateway', 'paypal')->find($payment);

        if ($model === null || preg_match('/^[A-Z0-9]{5,40}$/', $token) !== 1 || ! hash_equals((string) $model->reference, $token)) {
            return $model === null
                ? redirect()->route('home')
                : redirect()->route('orders.show', ['order' => $model->order_id]);
        }

        $order = $model->order()->firstOrFail();

        try {
            $confirmation->capture($model, 'return');
        } catch (PayPalException $e) {
            report($e);
        }

        $paid = $model->fresh()?->status === PaymentState::Paid;

        return redirect()->to(OrderLinks::signedShow($order))
            ->with($paid ? 'success' : 'error', $paid ? __('Thank you! Your payment was received.') : __('The payment is not confirmed yet. If you completed it, it will appear shortly.'));
    }

    /**
     * PayPal's webhook: verified with PayPal's certificate, then applied once.
     */
    public function webhook(Request $request, Settings $settings, PayPalClient $paypal, PayPalConfirmation $confirmation): Response
    {
        if (! WebhookSignature::valid($request, (string) $settings->get('plugin.pnshop_paypal.webhook_id'), $paypal)) {
            return response('Invalid signature', 400);
        }

        $event = json_decode($request->getContent(), true);
        $resource = is_array($event) ? ($event['resource'] ?? null) : null;

        if (! is_array($resource)) {
            return response('Ignored', 200);
        }

        try {
            match ((string) ($event['event_type'] ?? '')) {
                // Approved but the customer never came back: capture it now.
                'CHECKOUT.ORDER.APPROVED' => $this->approved($resource, $confirmation),
                'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.PENDING', 'PAYMENT.CAPTURE.DECLINED', 'PAYMENT.CAPTURE.DENIED' => $this->captured($resource, $confirmation),
                default => null,
            };
        } catch (PayPalException $e) {
            report($e);

            // PayPal retries later.
            return response('Retry', 503);
        }

        return response('OK', 200);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function approved(array $order, PayPalConfirmation $confirmation): void
    {
        $payment = Payment::query()->where('gateway', 'paypal')->find((int) ($order['purchase_units'][0]['custom_id'] ?? 0));

        if ($payment !== null && $payment->reference === ($order['id'] ?? null)) {
            $confirmation->capture($payment, 'webhook');
        }
    }

    /**
     * @param  array<string, mixed>  $capture
     */
    private function captured(array $capture, PayPalConfirmation $confirmation): void
    {
        $payment = Payment::query()->where('gateway', 'paypal')->find((int) ($capture['custom_id'] ?? 0));
        $orderId = $capture['supplementary_data']['related_ids']['order_id'] ?? null;

        // The capture belongs to the PayPal order of this payment (or is its recorded capture).
        if ($payment !== null && ($payment->reference === $orderId || $payment->reference === ($capture['id'] ?? null))) {
            $confirmation->apply($payment, $capture, 'webhook');
        }
    }
}
