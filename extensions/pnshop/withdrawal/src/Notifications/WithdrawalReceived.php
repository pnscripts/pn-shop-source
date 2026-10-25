<?php

namespace PnShop\Plugins\Withdrawal\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use PnShop\Plugins\Withdrawal\Models\Withdrawal;
use PnShop\Sales\Notifications\RetriesDelivery;
use PnShop\Sales\Notifications\StoreMailIdentity;
use PnShop\Settings\Settings;

/**
 * The customer's confirmation that their withdrawal statement was received: what it said,
 * and the date and time it was sent (in the store's timezone), in their language.
 */
class WithdrawalReceived extends Notification implements ShouldQueue
{
    use Queueable, RetriesDelivery;

    public function __construct(public Withdrawal $withdrawal)
    {
        $this->afterCommit();
        $this->locale($withdrawal->locale);
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        StoreMailIdentity::apply();
        $w = $this->withdrawal;
        $timezone = (string) (app(Settings::class)->get('localization.timezone') ?: config('app.timezone', 'UTC'));
        $at = $w->submitted_at->copy()->setTimezone($timezone);

        $mail = (new MailMessage)
            ->subject(__('We received your withdrawal from the contract (:reference)', ['reference' => $w->reference]))
            ->greeting(__('Hello :name,', ['name' => $w->name]))
            ->line(__('We received your statement that you withdraw from the contract. This email confirms it.'))
            ->line(__('Reference: :reference', ['reference' => $w->reference]))
            ->line(__('Sent on :date at :time (:timezone).', ['date' => $at->format('d.m.Y'), 'time' => $at->format('H:i:s'), 'timezone' => $timezone]))
            ->line(__('Statement: I withdraw from the contract for order :number.', ['number' => $w->order_number]))
            ->line(__('Name: :name', ['name' => $w->name]))
            ->line(__('Email for this confirmation: :email', ['email' => $w->email]));

        if ($w->items !== null) {
            $mail->line(__('Products concerned: :items', ['items' => $w->items]));
        }

        return $mail->line(__('We will tell you how to return the goods, if any, and refund you as the law provides.'));
    }
}
