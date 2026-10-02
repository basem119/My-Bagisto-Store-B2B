<?php

namespace Webkul\Marketplace\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Webkul\Marketplace\Models\Vendor;

/**
 * Sent to the vendor owner on a status-lifecycle change (approved/rejected/
 * suspended/reactivated). One class covers all four — the message simply
 * follows the vendor's current status.
 */
class VendorStatusChanged extends Notification
{
    use Queueable;

    public function __construct(protected Vendor $vendor, protected ?string $note = null) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Your vendor application status: {$this->vendor->status->label()}")
            ->line("The status of your vendor account \"{$this->vendor->name}\" is now: {$this->vendor->status->label()}.");

        if ($this->note) {
            $message->line("Note: {$this->note}");
        }

        return $message;
    }
}
