<?php

namespace Webkul\Marketplace\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Webkul\Marketplace\Models\Vendor;

/**
 * Sent to platform admins when a new vendor application is submitted.
 */
class VendorApplicationReceived extends Notification
{
    use Queueable;

    public function __construct(protected Vendor $vendor) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Vendor Application: '.$this->vendor->name)
            ->line("A new vendor application has been submitted: {$this->vendor->name} ({$this->vendor->email}).")
            ->line('Please review it in the admin panel.');
    }
}
