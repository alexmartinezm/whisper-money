<?php

namespace App\Services\Banking;

/**
 * Whether an EnableBanking payload is the settled delivery of a transaction or
 * an earlier, un-settled form of the same one.
 *
 * Banks signal this in one of two fields, and never in both. Which one they use
 * decides which consumer acts on it: `status` gates the import in
 * TransactionSyncService, `bank_transaction_code` is canonicalized into the
 * content hash in TransactionFingerprint. They live together here because they
 * answer the same question, and because reading them apart invites the wrong
 * conclusion — that either one made the other redundant.
 *
 * `status` is read from the payload here, because this question is the only
 * reason anything reads it. The card code arrives as a value instead, because
 * TransactionFingerprint already extracts it alongside every other field it
 * hashes and only needs the mapping.
 */
class TransactionSettlement
{
    /**
     * Statuses describing a delivery that has not settled: PDNG pending, HOLD
     * card authorisation hold, SCHD scheduled, CNCL cancelled, RJCT rejected.
     * A bank hands a purchase over as one of these first and re-sends it later
     * as BOOK with different content, so storing the un-settled form
     * guarantees a duplicate once the settled form lands.
     *
     * HOLD and SCHD have no production rows yet and come from the Berlin Group
     * status set rather than an observed payload. CNCL and RJCT are terminal
     * rather than waiting to settle: no BOOK copy is coming and no money
     * moved, so the ledger is right to omit them too.
     *
     * Read here rather than through the API's own `transaction_status` query
     * parameter because not every ASPSP populates `status`; filtering
     * server-side risks empty responses from the banks that never send it.
     * Anything absent from this list — BOOK, OTHR, or no field at all —
     * imports as before.
     *
     * @var list<string>
     */
    private const array UNSETTLED_STATUSES = ['PDNG', 'HOLD', 'SCHD', 'CNCL', 'RJCT'];

    /**
     * The subset of the above still waiting for a settled copy, as opposed to
     * the terminal CNCL and RJCT. Only these can be imported early, and only
     * under the conditions waitsForSettlement() states: a cancelled or
     * rejected delivery is money that never moved, so it stays out of the
     * ledger no matter how stable the bank's id is.
     *
     * @var list<string>
     */
    private const array AWAITING_SETTLEMENT_STATUSES = ['PDNG', 'HOLD', 'SCHD'];

    /**
     * Banks that re-deliver a settled transaction under the same upstream id
     * its un-settled copy already carried. There the copy costs nothing to
     * import: the settled delivery dedups against the stored row instead of
     * landing beside it, which is the duplicate this whole class exists to
     * prevent.
     *
     * Revolut is measured rather than assumed. On one production install all
     * 2,036 of its EnableBanking rows carry an `external_transaction_id`, and
     * not one of the 92 stored as PDNG ever acquired a settled twin — the
     * re-delivery was being deduped away, which only happens if the id
     * survives settlement. So skipping its pending copies prevents nothing and
     * costs the user roughly 36 hours of blindness per card purchase, which is
     * how long Revolut takes to move one to BOOK.
     *
     * A bank joins this list once its ids are shown to survive settlement, not
     * before. N26 mints a fresh one per delivery — see UNSTABLE_ID_BANKS in
     * TransactionFingerprint — and is the reason the skip exists at all.
     *
     * @var list<string>
     */
    private const array REDELIVERS_UNDER_THE_SAME_ID = ['revolut'];

    /**
     * The un-posted form of a card payment, and the settled form of the same
     * purchase. N26 delivers both, flipping this one content field in between;
     * it is the only field that ever varies inside a duplicate group (verified
     * across all 59 production groups whose code moves). Canonicalizing the
     * pair keeps every other transaction code discriminating, where dropping
     * the field would not.
     *
     * @var list<string>
     */
    private const array PENDING_CARD_CODE = ['MCRD', 'UPCT'];

    /** @var list<string> */
    private const array SETTLED_CARD_CODE = ['CCRD', 'POSD'];

    /**
     * Whether the bank is handing over a delivery that has not settled, so the
     * caller can wait for the BOOK copy instead of storing both.
     *
     * Banks that populate `status` leave the card code alone — Revolut,
     * Santander and Sabadell hold 5.6k of the 7.6k PDNG rows in production.
     * N26 is the mirror image: `status` is BOOK on both copies of every row
     * since July, and only `bank_transaction_code` moves. So this catches
     * nothing for N26, and canonicalCardCode() catches nothing for the others.
     *
     * @param  array<string, mixed>  $data
     */
    public static function isUnsettled(array $data): bool
    {
        return in_array($data['status'] ?? null, self::UNSETTLED_STATUSES, true);
    }

    /**
     * Whether this delivery has to be held back until the bank settles it.
     *
     * Holding it back is the default, because a bank that re-sends the same
     * purchase under a new id and different content leaves nothing for dedup
     * to match on, and the ledger ends up with both copies. Two conditions
     * lift that, together: the payload carries an upstream id, and this bank
     * is known to re-deliver under it. Then the settled copy collapses onto
     * the row the pending copy wrote, and waiting buys the user nothing.
     *
     * `$identifiedByUpstreamId` is TransactionFingerprint's verdict on this
     * same payload — asked there rather than re-read here, so a change to what
     * counts as a usable id cannot leave the two disagreeing.
     *
     * @param  array<string, mixed>  $data
     */
    public static function waitsForSettlement(array $data, ?string $bankName, bool $identifiedByUpstreamId): bool
    {
        if (! self::isUnsettled($data)) {
            return false;
        }

        if (! $identifiedByUpstreamId || ! self::redeliversUnderTheSameId($bankName)) {
            return true;
        }

        return ! in_array($data['status'] ?? null, self::AWAITING_SETTLEMENT_STATUSES, true);
    }

    /**
     * The settled form of a card payment code, so the un-posted delivery of the
     * same purchase hashes identically. Any other code is returned untouched,
     * which keeps it discriminating.
     *
     * @param  list<string>  $code
     * @return list<string>
     */
    public static function canonicalCardCode(array $code): array
    {
        return $code === self::PENDING_CARD_CODE ? self::SETTLED_CARD_CODE : $code;
    }

    private static function redeliversUnderTheSameId(?string $bankName): bool
    {
        return $bankName !== null && in_array(mb_strtolower($bankName), self::REDELIVERS_UNDER_THE_SAME_ID, true);
    }
}
