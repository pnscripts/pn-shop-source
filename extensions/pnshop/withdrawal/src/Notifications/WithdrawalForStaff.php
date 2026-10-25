<?php

namespace PnShop\Plugins\Withdrawal\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use PnShop\Localization\Localization;
use PnShop\Plugins\Withdrawal\Models\Withdrawal;
use PnShop\Sales\Notifications\RetriesDelivery;
use PnShop\Sales\Notifications\StoreMailIdentity;

/**
 * Tells the store a customer withdrew from a contract (in the store's default language).
 */
class WithdrawalForStaff extends Notification implements ShouldQueue
{
    use Queueable, RetriesDelivery;

    public function __construct(public Withdrawal $withdrawal)
    {
        $this->afterCommit();
        $this->locale(app(Localization::class)->defaultLocale());
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

        return (new MailMessage)
            ->subject(__('Withdrawal from contract :reference (order :number)', ['reference' => $w->reference, 'number' => $w->order_number]))
            ->line(__(':name (:email) withdrew from the contract for order :number.', ['name' => $w->name, 'email' => $w->email, 'number' => $w->order_number]))
            ->line($w->order_id !== null ? __('The order number and email match an order.') : __('No order matches this order number and email: please check it.'))
            ->action(__('Open in the admin'), route('filament.admin.resources.withdrawals.index'));
    }
}
