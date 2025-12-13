<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TradingNotification extends Mailable
{
    use Queueable, SerializesModels;

    public string $action; // 'entry' or 'exit'
    public string $side; // 'long' or 'short'
    public string $symbol;
    public float $price;
    public float $quantity;
    public ?float $profitLoss;
    public ?float $profitLossPercent;
    public ?string $reason;
    public ?string $strategyName;
    public array $additionalData;

    /**
     * Create a new message instance.
     */
    public function __construct(
        string $action,
        string $side,
        string $symbol,
        float $price,
        float $quantity,
        ?float $profitLoss = null,
        ?float $profitLossPercent = null,
        ?string $reason = null,
        ?string $strategyName = null,
        array $additionalData = []
    ) {
        $this->action = $action;
        $this->side = $side;
        $this->symbol = $symbol;
        $this->price = $price;
        $this->quantity = $quantity;
        $this->profitLoss = $profitLoss;
        $this->profitLossPercent = $profitLossPercent;
        $this->reason = $reason;
        $this->strategyName = $strategyName;
        $this->additionalData = $additionalData;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $emoji = $this->action === 'entry' ? '📈' : '💰';
        $actionText = $this->action === 'entry' ? 'エントリー' : 'エグジット';
        $sideText = $this->side === 'long' ? 'ロング' : 'ショート';

        return new Envelope(
            subject: "{$emoji} {$this->symbol} {$sideText}{$actionText}通知",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.trading-notification',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
