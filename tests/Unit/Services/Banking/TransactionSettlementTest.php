<?php

use App\Services\Banking\TransactionSettlement;

test('a delivery the bank has not settled is reported as unsettled', function (string $status) {
    expect(TransactionSettlement::isUnsettled(['status' => $status]))->toBeTrue();
})->with(['PDNG', 'HOLD', 'SCHD', 'CNCL', 'RJCT']);

test('a settled, unknown or absent status is reported as settled', function (?string $status) {
    $data = $status === null ? [] : ['status' => $status];

    expect(TransactionSettlement::isUnsettled($data))->toBeFalse();
})->with([
    'booked' => 'BOOK',
    'other' => 'OTHR',
    'absent' => null,
]);

test('a lowercase status is not mistaken for an unsettled one', function () {
    expect(TransactionSettlement::isUnsettled(['status' => 'pdng']))->toBeFalse();
});

test('the un-posted card code is canonicalized to its settled form', function () {
    expect(TransactionSettlement::canonicalCardCode(['MCRD', 'UPCT']))->toBe(['CCRD', 'POSD']);
});

test('any other card code keeps discriminating', function (array $code) {
    expect(TransactionSettlement::canonicalCardCode($code))->toBe($code);
})->with([
    'already settled' => [['CCRD', 'POSD']],
    'unrelated' => [['PMNT', 'RCDT']],
    'code matches, sub_code does not' => [['MCRD', 'RCDT']],
    'sub_code matches, code does not' => [['PMNT', 'UPCT']],
    'absent' => [['', '']],
]);

test('a pending delivery is imported when the bank re-delivers it under the same id', function (string $status) {
    expect(TransactionSettlement::waitsForSettlement(['status' => $status], 'Revolut', true))->toBeFalse();
})->with(['pending' => 'PDNG', 'hold' => 'HOLD', 'scheduled' => 'SCHD']);

test('the bank that re-delivers under the same id is recognised whatever the casing', function () {
    expect(TransactionSettlement::waitsForSettlement(['status' => 'PDNG'], 'revolut', true))->toBeFalse();
});

test('a pending delivery waits when nothing identifies it', function () {
    expect(TransactionSettlement::waitsForSettlement(['status' => 'PDNG'], 'Revolut', false))->toBeTrue();
});

test('a pending delivery waits for a bank whose ids are not known to survive settlement', function (?string $bankName) {
    expect(TransactionSettlement::waitsForSettlement(['status' => 'PDNG'], $bankName, true))->toBeTrue();
})->with(['n26' => 'N26', 'another bank' => 'Santander', 'no bank at all' => null]);

test('a terminal delivery stays out of the ledger even under an id that survives settlement', function (string $status) {
    expect(TransactionSettlement::waitsForSettlement(['status' => $status], 'Revolut', true))->toBeTrue();
})->with(['cancelled' => 'CNCL', 'rejected' => 'RJCT']);

test('a settled delivery never waits', function (?string $status) {
    $data = $status === null ? [] : ['status' => $status];

    expect(TransactionSettlement::waitsForSettlement($data, 'Revolut', false))->toBeFalse();
})->with(['booked' => 'BOOK', 'other' => 'OTHR', 'absent' => null]);
