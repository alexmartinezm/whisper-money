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

    /**
     * Bank and processor words carry no merchant identity in a payment
     * description. This is deliberately a small, recognised vocabulary rather
     * than a fuzzy or global stopword engine.
     *
     * @var list<string>
     */
    private const STRUCTURAL_TOKENS = [
        'card',
        'dd',
        'debit',
        'direct',
        'payment',
        'payments',
        'paypal',
        'pay',
        'pal',
        'pos',
        'purchase',
        'ref',
        'reference',
        'sepa',
        'standing',
        'transfer',
    ];

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
        $key = $this->canonicalKey($raw);

        if ($key === '' && $field === 'description') {
            $key = $this->tokenizer->distinctiveKey($raw, $documentFrequency, $noiseThreshold);
        }

        if ($key === '') {
            return null;
        }

        return [$field, mb_substr($key, 0, self::MAX_KEY_LENGTH)];
    }

    /**
     * Identity key independent of whether the bank populated a counterparty
     * field on this occurrence.
     *
     * @param  array<string, int>  $documentFrequency
     */
    public function stableKeyFor(Transaction $transaction, array $documentFrequency, float $noiseThreshold): ?string
    {
        $key = $this->keyFor($transaction, $documentFrequency, $noiseThreshold);

        return $key === null ? null : $key[1];
    }

    /**
     * All deterministic aliases observed on a transaction. They are stored as
     * metadata on the series so a later creditor-name change does not create a
     * second UUID.
     *
     * @param  array<string, int>  $documentFrequency
     * @return list<string>
     */
    public function aliasKeysFor(Transaction $transaction, array $documentFrequency, float $noiseThreshold): array
    {
        $rawValues = [
            $this->signal($transaction)[1],
            $transaction->description,
            $transaction->creditor_name,
            $transaction->debtor_name,
        ];
        $keys = [];

        foreach ($rawValues as $raw) {
            if (! filled($raw)) {
                continue;
            }

            $key = $this->canonicalKey((string) $raw);

            if ($key === '') {
                $key = $this->tokenizer->distinctiveKey((string) $raw, $documentFrequency, $noiseThreshold);
            }

            if ($key !== '') {
                $keys[] = mb_substr($key, 0, self::MAX_KEY_LENGTH);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Canonicalize an already stored legacy merchant key for compatibility
     * matching. The result is intentionally exact and deterministic.
     */
    public function canonicalKey(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $tokens = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter($tokens, function (string $token): bool {
            if (in_array($token, self::STRUCTURAL_TOKENS, true)) {
                return false;
            }

            return true;
        }));

        $lastToken = array_key_last($tokens);
        if ($lastToken !== null && $this->isRecognizedProcessorReference($tokens[$lastToken])) {
            unset($tokens[$lastToken]);
        }

        $tokens = array_values(array_unique($tokens));
        sort($tokens, SORT_STRING);

        return mb_substr(implode(' ', $tokens), 0, self::MAX_KEY_LENGTH);
    }

    public function matchesCounterparty(string $observed, ?string $ownerName): bool
    {
        if (trim($observed) === '' || ! filled($ownerName)) {
            return false;
        }

        return $this->canonicalKey($observed) === $this->canonicalKey((string) $ownerName);
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
        $amount = (int) $transaction->amount;

        if ($amount > 0 && filled($transaction->debtor_name)) {
            return ['debtor_name', (string) $transaction->debtor_name];
        }

        if ($amount < 0 && filled($transaction->creditor_name)) {
            return ['creditor_name', (string) $transaction->creditor_name];
        }

        // Preserve a useful counterparty fallback for incomplete bank rows.
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

    private function isRecognizedProcessorReference(string $token): bool
    {
        return preg_match('/^(?=.*[a-z])(?=.*\d)[a-z0-9]{8}$/i', $token) === 1;
    }
}
