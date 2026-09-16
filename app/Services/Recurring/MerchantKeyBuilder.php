<?php

namespace App\Services\Recurring;

use App\Models\Transaction;
use App\Services\Ai\DescriptionTokenizer;

/**
 * Turns a transaction into a stable merchant identity two charges from the same
 * provider will share.
 *
 * Identity is decided by the shape of each token, not by the rest of the
 * ledger. `DescriptionTokenizer` drops whatever is frequent across a user's
 * corpus, which reads well until the corpus grows: the key for an unchanged
 * contract moves on its own, and the series it belongs to is re-identified for
 * no reason. So frequency is kept only as a last resort, for a value whose
 * tokens are all structural, and the key itself is derived from what a bank
 * demonstrably rewrites between two charges — references, dates, terminal ids —
 * which is stable whatever else the user imports later.
 */
class MerchantKeyBuilder
{
    /** Matches the `merchant_key` column width. */
    private const MAX_KEY_LENGTH = 191;

    /**
     * A token shorter than this is kept even when it carries a digit, because
     * short alphanumerics are merchant names rather than references: O2, 3, M6.
     */
    private const MIN_REFERENCE_LENGTH = 6;

    /**
     * Bank and processor words carry no merchant identity in a payment
     * description. The vocabulary spans the languages this app is used in
     * rather than English alone, and includes the company-form suffixes a bank
     * appends to a trading name — "DIGI" and "DIGI SPAIN TELECOM SLU" are one
     * provider.
     *
     * @var list<string>
     */
    private const STRUCTURAL_TOKENS = [
        // Card, transfer and direct-debit vocabulary.
        'card', 'dd', 'debit', 'direct', 'payment', 'payments', 'paypal', 'pay',
        'pal', 'pos', 'purchase', 'ref', 'reference', 'sepa', 'standing',
        'transfer',
        'adeudo', 'compra', 'cuota', 'domiciliacion', 'liquidacion', 'mandato',
        'pago', 'recibo', 'tarj', 'tarjeta', 'transferencia', 'traspaso',
        'pagament', 'quota', 'rebut', 'targeta', 'traspas',
        // Company forms. A trading name is the identity; the suffix is not.
        'sa', 'sl', 'slu', 'sau', 'scp', 'sarl', 'sas', 'srl', 'spa', 'gmbh',
        'bv', 'nv', 'ltd', 'limited', 'limitada', 'inc', 'plc', 'oy', 'ab',
        'as', 'ug', 'sociedad',
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
     * The identity a value carries, with everything the bank rewrites between
     * two charges of the same contract removed. Deterministic and independent
     * of the rest of the ledger, so the same contract keys the same way today
     * and after another year of history.
     */
    public function canonicalKey(string $value): string
    {
        return mb_substr(implode(' ', $this->identityTokens($value)), 0, self::MAX_KEY_LENGTH);
    }

    /**
     * The identity tokens of a value, deduplicated and sorted so that word
     * order cannot split one provider in two.
     *
     * @return list<string>
     */
    public function identityTokens(string $value): array
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $tokens = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter(
            $tokens,
            fn (string $token): bool => ! $this->isVolatile($token)
                && ! in_array($token, self::STRUCTURAL_TOKENS, true),
        ));

        $tokens = array_values(array_unique($tokens));
        sort($tokens, SORT_STRING);

        return $tokens;
    }

    /**
     * Whether one value's identity is contained in another's.
     *
     * Banks shorten and lengthen the same name at will: a contract seen as
     * "DIGI" in a description and as "DIGI SPAIN TELECOM" once the creditor
     * field arrives is one provider, and requiring the two keys to be equal is
     * what splits it. Containment is deliberately not used for grouping — only
     * for recognising a series that already exists — so two genuinely different
     * contracts at one provider ("Generali Vida", "Generali Auto") stay apart:
     * neither contains the other.
     */
    public function shareIdentity(string $left, string $right): bool
    {
        $leftTokens = $this->identityTokens($left);
        $rightTokens = $this->identityTokens($right);

        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        [$shorter, $longer] = count($leftTokens) <= count($rightTokens)
            ? [$leftTokens, $rightTokens]
            : [$rightTokens, $leftTokens];

        // A one- or two-letter token in common is a coincidence, not a name.
        $hasSubstantialToken = array_filter($shorter, fn (string $token): bool => mb_strlen($token) >= 3) !== [];

        return $hasSubstantialToken && array_diff($shorter, $longer) === [];
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

    /**
     * A token the bank rewrites between two charges of the same contract:
     * mandate and processor references, an embedded date, a terminal id, the
     * last digits of a card.
     *
     * Recognising volatility by shape rather than by matching known reference
     * formats is the whole point. There is no finite list of formats — PayPal
     * alone writes both `4F1A9B27` and `35314369001` — and a rule that only
     * knows one of them leaves every charge under its own identity.
     */
    private function isVolatile(string $token): bool
    {
        if (preg_match('/^\p{N}+$/u', $token) === 1) {
            return true;
        }

        return mb_strlen($token) >= self::MIN_REFERENCE_LENGTH
            && preg_match('/\p{N}/u', $token) === 1;
    }
}
