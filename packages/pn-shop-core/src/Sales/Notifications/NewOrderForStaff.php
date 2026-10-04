<?php

namespace PnShop\Sales\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use PnShop\Sales\Models\Order;

/**
 * Tells the store a new order came in (in the store's default language).
 */
class NewOrderForStaff extends Notification implements ShouldQueue
{
    use Queueable, RetriesDelivery;

    public function __construct(public Order $order)
    {
        $this->afterCommit();
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
        $order = $this->order;
        StoreMailIdentity::apply();

        return (new MailMessage)
            ->subject(__('New order :number', ['number' => $order->number]))
            ->line(__(':name placed order :number for :amount.', ['name' => $order->name, 'number' => $order->number, 'amount' => $order->grandTotal()->formatToLocale(app()->getLocale())]))
            ->action(__('Open in the admin'), route('filament.admin.resources.orders.view', ['record' => $order->id]));
    }
}
