<?php

namespace App\Services\Banking;

use App\Models\BankingConnection;

/**
 * What an EnableBanking session body contains, described without anything in it
 * that points at a person or their accounts.
 *
 * A bank that authorizes a session and then hands back no accounts leaves us
 * nothing to act on and, until this existed, nothing to read either: the
 * provider only logs failed responses, so a successful-but-empty session was
 * invisible. Trade Republic has been doing exactly that, and the four
 * explanations - our own recovery failing, the connector genuinely exposing no
 * account, the uids arriving under `accounts_data` instead, or `/details`
 * refusing them - are told apart by fields that are all safe to write down.
 *
 * Safe because none of them is a value. Counts, field *names*, the session
 * status, the consent's expiry and the bank's own name go in; uids, IBANs,
 * `identification_hash` and account names never do. These logs leave the
 * building, and the same rule already governs {@see EnableBankingProvider} -
 * where a failing request keeps the shape of its path and loses the ids in it.
 */
final class EnableBankingSessionShape
{
    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    public static function describe(array $session): array
    {
        $accounts = is_array($session['accounts'] ?? null) ? $session['accounts'] : [];
        $accountsData = is_array($session['accounts_data'] ?? null) ? $session['accounts_data'] : [];

        return [
            'session_status' => is_string($session['status'] ?? null) ? $session['status'] : null,
            'keys' => array_keys($session),
            // Whether there is one, never which one.
            'has_session_id' => is_string($session['session_id'] ?? null),
            'accounts_count' => count($accounts),
            'accounts_entry_type' => self::entryType($accounts),
            'accounts_data_count' => count($accountsData),
            'accounts_data_keys' => self::firstEntryKeys($accountsData),
            ...self::accessShape($session),
            ...self::aspspShape($session),
        ];
    }

    /**
     * Whether `accounts` arrived as bare uid strings or as objects.
     *
     * The recovery in {@see EnableBankingProvider::createSession()} accepts
     * both, so a `mixed` or `other` here is the first thing to look at when
     * uids are present and still nothing gets through.
     *
     * @param  array<int|string, mixed>  $accounts
     */
    private static function entryType(array $accounts): ?string
    {
        $types = collect($accounts)
            ->map(fn (mixed $account): string => match (true) {
                is_string($account) => 'string',
                is_array($account) => 'object',
                default => 'other',
            })
            ->unique();

        return match ($types->count()) {
            0 => null,
            1 => (string) $types->first(),
            default => 'mixed',
        };
    }

    /**
     * The field names of the first entry, never their values.
     *
     * This is what says whether `accounts_data` is carrying the uids that
     * `accounts` did not.
     *
     * @param  array<int|string, mixed>  $entries
     * @return list<string>|null
     */
    private static function firstEntryKeys(array $entries): ?array
    {
        $first = reset($entries);

        return is_array($first)
            ? array_map(strval(...), array_keys($first))
            : null;
    }

    /**
     * The consent behind the session: what it covers, and until when.
     *
     * `access_valid_until` against the clock is the difference between a
     * consent the bank still honours and one that has quietly gone stale.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private static function accessShape(array $session): array
    {
        $access = is_array($session['access'] ?? null) ? $session['access'] : [];
        $validUntil = $access['valid_until'] ?? null;

        return [
            'access_keys' => array_keys($access),
            'access_valid_until' => is_string($validUntil) ? $validUntil : null,
        ];
    }

    /**
     * The bank the session belongs to, as the session itself reports it - which
     * is already in every other line this flow logs, through
     * {@see BankingConnection::logContext()}.
     *
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private static function aspspShape(array $session): array
    {
        $aspsp = is_array($session['aspsp'] ?? null) ? $session['aspsp'] : [];

        return [
            'aspsp_name' => is_string($aspsp['name'] ?? null) ? $aspsp['name'] : null,
            'aspsp_country' => is_string($aspsp['country'] ?? null) ? $aspsp['country'] : null,
        ];
    }
}
