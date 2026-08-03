<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;

/**
 * The one thing the projection knows that is worth interrupting someone for.
 *
 * Every other figure on the recurring screen is informative; this one is the
 * only one that changes what you do this week, and until now it only existed
 * for whoever happened to open the page.
 */
class RunwayShortfallEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @var int */
    public $tries = 5;

    /** @var array<int, int> */
    public $backoff = [2, 5, 10, 30];

    /**
     * @param  array{date: string, balance: int}  $lowest
     */
    public function __construct(
        public User $user,
        public array $lowest,
        public string $currencyCode,
        public bool $includesEverydaySpending,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('emails')];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Heads up: your balance runs out before payday'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.runway-shortfall', with: [
            'user' => $this->user,
            'lowest' => $this->lowest,
            'currencyCode' => $this->currencyCode,
            'includesEverydaySpending' => $this->includesEverydaySpending,
        ]);
    }
}
