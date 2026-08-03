<?php

namespace App\Mail;

use App\Data\RecurringPriceRise;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * One digest per run, like the upcoming-charges reminder: a message per
 * subscription would arrive in a clump the month several providers put their
 * prices up together, which is exactly when it must stay readable.
 */
class RecurringPriceChangesEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @var int */
    public $tries = 5;

    /** @var array<int, int> */
    public $backoff = [2, 5, 10, 30];

    /**
     * @param  Collection<int, RecurringPriceRise>  $rises
     */
    public function __construct(
        public User $user,
        public Collection $rises,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('emails')];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->rises->count() === 1
                ? __(':name has gone up', [
                    'name' => $this->rises->first()->series->display_name,
                ])
                : __(':count of your recurring charges have gone up', [
                    'count' => $this->rises->count(),
                ]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.recurring-price-changes', with: [
            'user' => $this->user,
            'rises' => $this->rises,
        ]);
    }
}
