<?php

namespace App\Providers;

use App\Contracts\BankingProviderInterface;
use App\Http\Responses\RegisterResponse;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;
use App\Services\Ai\Contracts\TransactionMatcher;
use App\Services\Ai\LaravelAiRuleSuggestionGenerator;
use App\Services\Ai\UncategorizedTransactionMatcher;
use App\Services\Banking\EnableBankingProvider;
use App\Services\Discord\DiscordWebhook;
use App\Support\QueueWorkerLoop;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Cashier::keepPastDueSubscriptionsActive();

        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);

        $this->app->bind(BankingProviderInterface::class, function ($app) {
            return new EnableBankingProvider(
                config('services.enablebanking.app_id'),
                base_path(config('services.enablebanking.private_key_path')),
            );
        });

        $this->app->bind(DiscordWebhook::class, function () {
            return new DiscordWebhook(config('services.discord.webhook_url'));
        });

        // A singleton because the reporting rule in bootstrap/app.php and the
        // queue events below have to be looking at the same flags.
        $this->app->singleton(QueueWorkerLoop::class);

        $this->app->bind(TransactionMatcher::class, UncategorizedTransactionMatcher::class);
        $this->app->bind(RuleSuggestionGenerator::class, LaravelAiRuleSuggestionGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Event listeners are registered automatically via Laravel's event
        // discovery of the App\Listeners directory (matched by their handle()
        // type-hint). Do not also register them explicitly here — doing both
        // registers every listener twice, so each queued listener is dispatched
        // twice per event.
        RateLimiter::for('emails', function (object $job): Limit {
            return Limit::perSecond(30);
        });

        // Closures rather than App\Listeners classes on purpose: those are
        // auto-discovered, so a class here would be registered twice. These only
        // flip a flag the exception reporting rule reads, so they must not queue.
        // Blocks rather than arrow functions because `Looping` is dispatched with
        // `until()`: a listener that returned anything but null would stop the
        // worker from picking up its next job.
        Event::listen(Looping::class, function (): void {
            $this->app->make(QueueWorkerLoop::class)->enterLoop();
        });
        Event::listen(JobProcessing::class, function (): void {
            $this->app->make(QueueWorkerLoop::class)->enterJob();
        });
        // Only the success event, never JobExceptionOccurred: the worker dispatches
        // that one *before* it reports, so clearing the flag there would silence
        // exactly the lost connections that happen inside a job. A job that threw
        // is cleared by the next turn's `Looping` instead.
        Event::listen(JobProcessed::class, function (): void {
            $this->app->make(QueueWorkerLoop::class)->leaveJob();
        });

        // Render the OAuth consent screen (Claude Desktop / ChatGPT connecting
        // to the MCP server) with our own on-brand Blade view.
        Passport::authorizationView(fn (array $parameters) => response()->view('mcp.authorize', $parameters));
    }
}
