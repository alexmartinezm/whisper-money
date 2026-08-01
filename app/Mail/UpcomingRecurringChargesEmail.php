<?php

namespace App\Mail;

use App\Models\RecurringSeries;
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
 * One digest per run rather than one email per charge: the budget notifications
 * taught us that a message per event reads as spam and gets switched off.
 */
class UpcomingRecurringChargesEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @var int */
    public $tries = 5;

    /** @var array<int, int> */
    public $backoff = [2, 5, 10, 30];

    /**
     * @param  Collection<int, RecurringSeries>  $series
     */
    public function __construct(
        public User $user,
        public Collection $series,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('emails')];
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Coming up: :count recurring charges', [
                'count' => $this->series->count(),
            ], $this->user->locale ?? 'en'),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.upcoming-recurring-charges', with: [
            'user' => $this->user,
            'series' => $this->series,
        ]);
    }
}
