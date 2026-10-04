<?php

namespace PnShop\Returns\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use PnShop\Returns\Models\ReturnRequest;
use PnShop\Sales\Notifications\OrderMail;

/**
 * The return label is ready: print it and stick it on the parcel.
 */
class ReturnLabelIssued extends OrderMail
{
    public function __construct(public ReturnRequest $return)
    {
        parent::__construct($return->order()->firstOrFail());
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = $this->message()
            ->subject(__('Return label for :number', ['number' => $this->return->number]))
            ->line(__('Print the return label and attach it to the parcel with the items for return :number.', ['number' => $this->return->number]))
            ->action(__('Download the return label'), (string) $this->return->return_label_url);

        if ($this->return->return_tracking_number !== null) {
            $message->line(__('Tracking number: :number', ['number' => $this->return->return_tracking_number]));
        }

        return $message;
    }
}
