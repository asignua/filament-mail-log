<?php

declare(strict_types=1);

namespace Workbench\App\Mail;

use Asignua\FilamentMailLog\Concerns\HasMailLogRelations;
use Asignua\FilamentMailLog\Contracts\RelatesToMailLog;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Workbench\App\Models\Order;

class OrderShipped extends Mailable implements RelatesToMailLog
{
    use HasMailLogRelations;

    public function __construct(public Order $order) {}

    public function mailLogRelations(): iterable
    {
        return [$this->order, ...$this->mailLogRelated];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your order '.$this->order->number.' has shipped');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Order '.e($this->order->number).' is on its way.</p>');
    }
}
