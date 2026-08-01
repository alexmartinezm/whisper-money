<?php

namespace App\Services\Recurring;

use App\Models\Transaction;
use App\Services\Ai\DescriptionTokenizer;

/**
 * Turns a transaction into a stable merchant identity two charges from the same
 * provider will share.
 *
 * Mirrors the grouping signal of the AI rule suggestions
 * (`App\Services\Ai\RuleSuggestionAggregator`): counterparty names populated by
 * bank sync are trusted directly, and free-text descriptions fall back to the
 * language-agnostic distinctive tokens of `DescriptionTokenizer` so no
 * hardcoded stopword list is needed for a pan-European user base.
 */
class MerchantKeyBuilder
{
    /** Matches the `merchant_key` column width. */
    private const MAX_KEY_LENGTH = 191;

    public function __construct(private readonly DescriptionTokenizer $tokenizer) {}

    /**
     * How often each description token appears across the user's corpus, so
     * structural noise ("pago", "carte", a city) can be dropped by frequency.
     *
     * @param  iterable<int, string|null>  $descriptions
     * @return array<string, int>
     */
    public function documentFrequency(iterable $descriptions): array
    {
        return $this->tokenizer->documentFrequency($descriptions);
    }

    /**
     * @param  array<string, int>  $documentFrequency
     * @return array{0: string, 1: string}|null [matchField, merchantKey]
     */
    public function keyFor(Transaction $transaction, array $documentFrequency, float $noiseThreshold): ?array
    {
        [$field, $raw] = $this->signal($transaction);

        $key = $field === 'description'
            ? $this->tokenizer->distinctiveKey($raw, $documentFrequency, $noiseThreshold)
            : mb_strtolower($this->collapse($raw));

        if ($key === '') {
            return null;
        }

        return [$field, mb_substr($key, 0, self::MAX_KEY_LENGTH)];
    }

    /**
     * The human-facing name for a series. Prefers the counterparty over the raw
     * description, which is usually padded with terminal ids and dates.
     */
    public function displayNameFor(Transaction $transaction): string
    {
        [, $raw] = $this->signal($transaction);

        return mb_substr($this->collapse($raw), 0, 255);
    }

    /**
     * @return array{0: string, 1: string} [field, rawValue]
     */
    private function signal(Transaction $transaction): array
    {
        if (filled($transaction->creditor_name)) {
            return ['creditor_name', (string) $transaction->creditor_name];
        }

        if (filled($transaction->debtor_name)) {
            return ['debtor_name', (string) $transaction->debtor_name];
        }

        return ['description', (string) $transaction->description];
    }

    private function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
