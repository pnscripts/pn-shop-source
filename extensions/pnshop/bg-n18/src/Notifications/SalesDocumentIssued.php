<?php

namespace PnShop\Plugins\BgN18\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use PnShop\Plugins\BgN18\DocumentRenderer;
use PnShop\Plugins\BgN18\Models\SalesDocument;
use PnShop\Sales\Notifications\RetriesDelivery;
use PnShop\Sales\Notifications\StoreMailIdentity;

/**
 * Sends the customer their sale document (attached as a page they can open and print).
 */
class SalesDocumentIssued extends Notification implements ShouldQueue
{
    use Queueable, RetriesDelivery;

    public function __construct(public SalesDocument $document)
    {
        $this->afterCommit();
        $this->locale($document->order->locale);
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
        $renderer = app(DocumentRenderer::class);
        $number = $this->document->data['order_number'] ?? '';

        return (new MailMessage)
            ->subject(__('Sales document for order :number', ['number' => $number]))
            ->line(__('Attached is the sales document for your order :number.', ['number' => $number]))
            ->attachData($renderer->html($this->document), $renderer->filename($this->document), ['mime' => 'text/html']);
    }
}
