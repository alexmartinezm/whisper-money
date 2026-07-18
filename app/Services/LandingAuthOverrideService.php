<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\URL;

class LandingAuthOverrideService
{
    public function authButtonsHidden(Request $request): bool
    {
        if (! config('landing.hide_auth_buttons', false)) {
            return false;
        }

        return ! $this->allowsAuthentication();
    }

    private function allowsAuthentication(): bool
    {
        if (! config('landing.hide_auth_buttons', false)) {
            return true;
        }

        return false;
    }

    public function generateSignedUrl(int $days): string
    {
        $path = $this->signedPath(now()->addDays($days));

        return rtrim(config('app.url'), '/').$path;
    }

    /**
     * Generate a signed landing URL for a specific user lead.
     */
    public function generateInvitationUrl(string $leadId, int $days = 30): string
    {
        $path = $this->signedPath(now()->addDays($days), ['lead' => $leadId]);

        return rtrim(config('app.url'), '/').$path;
    }

    /**
     * @param  array<string, scalar>  $extraParameters
     */
    public function signedPath(\DateTimeInterface|\DateInterval|int $expiration, array $extraParameters = []): string
    {
        $parameters = $extraParameters + [
            $this->queryParameter() => 1,
            'expires' => $this->availableAt($expiration),
        ];

        ksort($parameters);

        $signature = hash_hmac('sha256', $this->originalString('/', $parameters), $this->signingKey());

        return '/?'.Arr::query($parameters + ['signature' => $signature]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function originalString(string $path, array $parameters): string
    {
        $normalizedPath = $path === '' ? '/' : $path;
        $query = Arr::query($parameters);

        return rtrim($normalizedPath.'?'.$query, '?');
    }

    private function signingKey(): string
    {
        $key = app('config')->get('app.key');

        if (! is_string($key) || $key === '') {
            $url = URL::to('/');

            throw new \RuntimeException("Unable to sign landing auth URL for {$url} without app.key.");
        }

        return $key;
    }

    private function availableAt(\DateTimeInterface|\DateInterval|int $delay): int
    {
        if ($delay instanceof \DateTimeInterface) {
            return $delay->getTimestamp();
        }

        if ($delay instanceof \DateInterval) {
            return now()->add($delay)->getTimestamp();
        }

        return now()->addSeconds($delay)->getTimestamp();
    }

    private function queryParameter(): string
    {
        return (string) config('landing.auth_override.query_parameter', 'signup');
    }
}
