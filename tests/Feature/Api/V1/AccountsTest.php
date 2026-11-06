<?php

use App\Enums\AccountType;
use App\Enums\PropertyType;
use App\Models\Account;
use App\Models\Bank;
use App\Models\CreditCardDetail;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\withHeaders;

/**
 * @return array<string, string>
 */
function accountsWriter(User $user): array
{
    return apiV1Bearer($user, ['api:read', 'api:write']);
}

function sharedSpaceWith(User $member, User $owner): Space
{
    $space = Space::factory()->create(['owner_id' => $owner->id, 'name' => 'Household', 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return $space;
}

describe('list', function () {
    it('lists the accounts of the personal space, newest first', function () {
        $user = User::factory()->create();
        $bank = Bank::factory()->create(['name' => 'BBVA']);
        $older = Account::factory()->create(['user_id' => $user->id, 'name' => 'Older', 'type' => AccountType::Checking, 'bank_id' => $bank->id, 'created_at' => now()->subDay()]);
        $newer = Account::factory()->connected()->create(['user_id' => $user->id, 'name' => 'Newer', 'type' => AccountType::Checking]);
        Account::factory()->create(['name' => 'Someone else']);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/accounts')
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.is_connected', true)
            ->assertJsonPath('data.0.credit_card_usage', null)
            ->assertJsonPath('data.1', [
                'id' => $older->id,
                'space_id' => $user->personalSpace->id,
                'name' => 'Older',
                'type' => $older->type->value,
                'currency' => $older->currency_code,
                'bank' => ['id' => $bank->id, 'name' => 'BBVA'],
                'is_connected' => false,
                'ownership_percentage' => 100,
                'ownership_applies_to_balance' => $older->fresh()->ownership_applies_to_balance,
                'credit_card_detail' => null,
                'credit_card_statement' => null,
                'credit_card_usage' => null,
                'archived_at' => null,
                'created_at' => $older->created_at->toIso8601String(),
            ])
            ->assertJsonCount(2, 'data');
    });

    it('lists only your own accounts of another space when asked, as the app does', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedSpaceWith($user, $owner);
        $mine = Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
        Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        Account::factory()->create(['user_id' => $user->id]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/accounts?space='.$space->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    });

    it('answers 404 for another member\'s account of a shared space', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedSpaceWith($user, $owner);
        $account = Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/accounts/'.$account->id.'?space='.$space->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });

    it('refuses a space the user cannot access', function () {
        $user = User::factory()->create();
        $foreign = Space::factory()->create(['owner_id' => User::factory()->create()->id, 'personal' => false]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/accounts?space='.$foreign->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('space');
    });

    it('pages through the accounts with a cursor', function () {
        $user = User::factory()->create();
        $accounts = collect(range(1, 3))->map(fn (int $days): Account => Account::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays($days),
        ]));
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson('/api/v1/accounts?limit=2')
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('data.*.id', [$accounts[0]->id, $accounts[1]->id]);

        withHeaders($headers)->getJson('/api/v1/accounts?limit=2&starting_after='.$accounts[1]->id)
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$accounts[2]->id]);

        withHeaders($headers)->getJson('/api/v1/accounts?starting_after='.Account::factory()->create()->id)
            ->assertBadRequest()
            ->assertJsonPath('code', 'invalid_cursor');
    });

    it('shows a credit card with its limit and usage, without the daily series', function () {
        $user = User::factory()->create();
        $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);
        CreditCardDetail::factory()->create(['account_id' => $card->id, 'credit_limit' => 300_000]);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/accounts/'.$card->id)
            ->assertOk()
            ->assertJsonPath('data.credit_card_detail.credit_limit', 300_000)
            ->assertJsonPath('data.credit_card_usage.limit', 300_000)
            ->assertJsonPath('data.credit_card_usage.used', 0);

        expect($response->json('data.credit_card_usage'))->not->toHaveKey('daily');
    });
});

describe('show', function () {
    it('retrieves an account of the space', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Savings']);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/accounts/'.$account->id)
            ->assertOk()
            ->assertJsonPath('data.id', $account->id)
            ->assertJsonPath('data.name', 'Savings');
    });

    it('answers 404 for an account of another user, another space or no account at all', function (string $case) {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedSpaceWith($user, $owner);

        $id = match ($case) {
            'another user' => Account::factory()->create()->id,
            'another space' => Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id,
            'unknown' => (string) Str::uuid(),
        };

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/accounts/'.$id)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not Found', 'code' => 'not_found']);
    })->with(['another user', 'another space', 'unknown']);

    it('answers 404 for an id that is not a uuid', function () {
        withHeaders(apiV1Bearer(User::factory()->create()))
            ->getJson('/api/v1/accounts/not-a-uuid')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });
});

describe('create', function () {
    it('creates a manual account with a balance for today', function () {
        $user = User::factory()->create();

        $response = withHeaders(accountsWriter($user))
            ->postJson('/api/v1/accounts', [
                'name' => 'Rainy day savings',
                'type' => 'savings',
                'currency_code' => 'EUR',
                'balance' => 250_000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Rainy day savings')
            ->assertJsonPath('data.type', 'savings')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.is_connected', false)
            ->assertJsonPath('data.ownership_percentage', 100)
            ->assertJsonPath('data.space_id', $user->personalSpace->id);

        $account = $user->accounts()->sole();

        expect($response->json('data.id'))->toBe($account->id)
            ->and($account->balances()->where('balance_date', now()->toDateString())->value('balance'))->toBe(250_000)
            ->and($user->fresh()->currency_code)->toBe('EUR');
    });

    it('creates the account in the space it is asked to', function () {
        $user = User::factory()->create();
        $space = sharedSpaceWith($user, User::factory()->create());

        withHeaders(accountsWriter($user))
            ->postJson('/api/v1/accounts', ['name' => 'Shared', 'type' => 'checking', 'currency_code' => 'EUR', 'space' => $space->id])
            ->assertCreated()
            ->assertJsonPath('data.space_id', $space->id);
    });

    it('creates a loan with its details', function () {
        $user = User::factory()->create();

        withHeaders(accountsWriter($user))
            ->postJson('/api/v1/accounts', [
                'name' => 'Mortgage',
                'type' => 'loan',
                'currency_code' => 'EUR',
                'annual_interest_rate' => 3.25,
                'loan_term_months' => 300,
                'original_amount' => 20_000_000,
                'loan_start_date' => now()->subMonths(2)->toDateString(),
                // Detail of another type is ignored, not stored.
                'property_type' => 'residential',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'loan');

        $loan = $user->accounts()->sole();

        expect($loan->loanDetail->loan_term_months)->toBe(300)
            ->and($loan->realEstateDetail)->toBeNull();
    });

    it('validates the account', function () {
        $user = User::factory()->create();

        withHeaders(accountsWriter($user))
            ->postJson('/api/v1/accounts', ['type' => 'spaceship', 'currency_code' => 'XXX'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'type', 'currency_code']);

        expect($user->accounts()->count())->toBe(0);
    });

    it('never creates a bank-connected account', function () {
        $user = User::factory()->create();

        withHeaders(accountsWriter($user))
            ->postJson('/api/v1/accounts', ['name' => 'Fake bank', 'type' => 'checking', 'currency_code' => 'EUR', 'is_connected' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_connected' => 'consent flow']);

        expect($user->accounts()->count())->toBe(0);
    });

    it('refuses a balance on a credit card', function () {
        $user = User::factory()->create();

        withHeaders(accountsWriter($user))
            ->postJson('/api/v1/accounts', ['name' => 'Visa', 'type' => 'credit_card', 'currency_code' => 'EUR', 'balance' => 1000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['balance' => 'credit_limit']);
    });

    it('refuses a read-only token', function () {
        $user = User::factory()->create();

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/accounts', ['name' => 'Savings', 'type' => 'savings', 'currency_code' => 'EUR'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($user->accounts()->count())->toBe(0);
    });

    it('replays a POST with the same idempotency key instead of creating a second account', function () {
        $user = User::factory()->create();
        $headers = [...accountsWriter($user), 'Idempotency-Key' => 'create-savings'];
        $body = ['name' => 'Savings', 'type' => 'savings', 'currency_code' => 'EUR'];

        $first = withHeaders($headers)->postJson('/api/v1/accounts', $body)->assertCreated();
        $replay = withHeaders($headers)->postJson('/api/v1/accounts', $body)->assertCreated();

        $replay->assertHeader('Idempotent-Replayed', 'true');
        expect($replay->json('data.id'))->toBe($first->json('data.id'))
            ->and($user->accounts()->count())->toBe(1);
    });
});

describe('update', function () {
    it('changes only the fields it is sent', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Old', 'type' => 'checking', 'currency_code' => 'EUR']);

        withHeaders(accountsWriter($user))
            ->patchJson('/api/v1/accounts/'.$account->id, ['name' => 'New', 'ownership_percentage' => 50])
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.ownership_percentage', 50)
            ->assertJsonPath('data.type', 'checking')
            ->assertJsonPath('data.currency', 'EUR');
    });

    it('renames a connected account but refuses what the bank sync owns', function () {
        $user = User::factory()->create();
        $account = Account::factory()->connected()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $headers = accountsWriter($user);

        withHeaders($headers)
            ->patchJson('/api/v1/accounts/'.$account->id, ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        withHeaders($headers)
            ->patchJson('/api/v1/accounts/'.$account->id, ['currency_code' => 'USD'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency_code' => 'connected to a bank']);

        withHeaders($headers)
            ->patchJson('/api/v1/accounts/'.$account->id, ['type' => 'loan'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type' => 'transaction ledger']);

        expect($account->fresh())->currency_code->toBe('EUR')->type->toBe(AccountType::Checking);
    });

    it('validates the fields', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        withHeaders(accountsWriter($user))
            ->patchJson('/api/v1/accounts/'.$account->id, ['ownership_percentage' => 0, 'type' => 'spaceship'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ownership_percentage', 'type']);
    });

    it('refuses partial loan details whole, saving nothing', function () {
        $user = User::factory()->create();
        $loan = Account::factory()->loan()->create(['user_id' => $user->id, 'name' => 'Loan']);

        withHeaders(accountsWriter($user))
            ->patchJson('/api/v1/accounts/'.$loan->id, ['name' => 'Car loan', 'annual_interest_rate' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['loan_term_months', 'original_amount']);

        expect($loan->fresh()->name)->toBe('Loan')
            ->and($loan->fresh()->loanDetail)->toBeNull();
    });

    it('refuses a field it does not take instead of ignoring it', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

        withHeaders(accountsWriter($user))
            ->patchJson('/api/v1/accounts/'.$account->id, ['currency' => 'USD', 'space' => $user->personalSpace->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['currency' => 'does not take'])
            ->assertJsonMissingValidationErrors('space');

        expect($account->fresh()->currency_code)->toBe('EUR');
    });

    it('answers 404 for an account owned by another member of the space', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedSpaceWith($user, $owner);
        $account = Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Theirs']);

        withHeaders(accountsWriter($user))
            ->patchJson('/api/v1/accounts/'.$account->id.'?space='.$space->id, ['name' => 'Mine now'])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        expect($account->fresh()->name)->toBe('Theirs');
    });

    it('answers 404 for an account of another user', function () {
        $account = Account::factory()->create(['name' => 'Theirs']);

        withHeaders(accountsWriter(User::factory()->create()))
            ->patchJson('/api/v1/accounts/'.$account->id, ['name' => 'Mine now'])
            ->assertNotFound();

        expect($account->fresh()->name)->toBe('Theirs');
    });

    it('refuses a read-only token', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Old']);

        withHeaders(apiV1Bearer($user))
            ->patchJson('/api/v1/accounts/'.$account->id, ['name' => 'New'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($account->fresh()->name)->toBe('Old');
    });
});

describe('property details', function () {
    it('asks for the property type when a change would create the details without it', function (array $account, array $change) {
        $user = User::factory()->create();
        $model = Account::factory()->create(['user_id' => $user->id, ...$account]);

        withHeaders(accountsWriter($user))
            ->patchJson("/api/v1/accounts/{$model->id}", $change)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('property_type');

        expect($model->fresh()->type)->toBe($account['type'])
            ->and($model->realEstateDetail()->exists())->toBeFalse();
    })->with([
        'turning an account into a property' => [['type' => AccountType::Checking], ['type' => 'real_estate', 'address' => 'Main St 1']],
        'a property with no details yet' => [['type' => AccountType::RealEstate], ['address' => 'Main St 1']],
    ]);

    it('creates the details once the property type comes along', function () {
        $user = User::factory()->create();
        $model = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking]);

        withHeaders(accountsWriter($user))
            ->patchJson("/api/v1/accounts/{$model->id}", ['type' => 'real_estate', 'address' => 'Main St 1', 'property_type' => PropertyType::cases()[0]->value])
            ->assertOk();

        expect($model->realEstateDetail()->sole()->address)->toBe('Main St 1');
    });

    it('needs no property type to change a property that has its details, or a change that adds none', function () {
        $user = User::factory()->create();
        $property = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::RealEstate]);
        $property->realEstateDetail()->create(['property_type' => PropertyType::cases()[0]->value]);
        $bare = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking]);

        withHeaders(accountsWriter($user))->patchJson("/api/v1/accounts/{$property->id}", ['address' => 'Main St 1'])->assertOk();
        withHeaders(accountsWriter($user))->patchJson("/api/v1/accounts/{$bare->id}", ['type' => 'real_estate'])->assertOk();
    });
});

describe('malformed input', function () {
    it('answers a list where a type or a space goes with a 422, not a 500', function () {
        $headers = accountsWriter(User::factory()->create());

        withHeaders($headers)
            ->postJson('/api/v1/accounts', ['name' => 'Main', 'type' => ['checking'], 'currency_code' => 'EUR'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        withHeaders($headers)
            ->postJson('/api/v1/accounts', ['name' => 'Main', 'type' => 'checking', 'currency_code' => 'EUR', 'space' => ['anything']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('space');
    });
});

describe('encrypted names', function () {
    it('answers a name still encrypted on the device as empty, never as ciphertext', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'c2VjcmV0LWNpcGhlcnRleHQ=', 'name_iv' => 'abcdefghijklmnop', 'encrypted' => true]);

        $list = withHeaders(apiV1Bearer($user))->getJson('/api/v1/accounts')->assertOk();
        $show = withHeaders(apiV1Bearer($user))->getJson("/api/v1/accounts/{$account->id}")->assertOk();

        expect($list->json('data.0.name'))->toBe('')
            ->and($show->json('data.name'))->toBe('')
            ->and($list->getContent())->not->toContain('c2VjcmV0LWNpcGhlcnRleHQ=');
    });

    it('keeps the name marked as ciphertext when a change leaves it alone', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'c2VjcmV0LWNpcGhlcnRleHQ=', 'name_iv' => 'abcdefghijklmnop', 'encrypted' => true]);

        withHeaders(accountsWriter($user))
            ->patchJson("/api/v1/accounts/{$account->id}", ['ownership_percentage' => 50])
            ->assertOk()
            ->assertJsonPath('data.name', '')
            ->assertJsonPath('data.ownership_percentage', 50);

        expect($account->fresh())->name_iv->toBe('abcdefghijklmnop')->encrypted->toBeTrue();
    });

    it('takes a new plain name over the ciphertext', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'c2VjcmV0LWNpcGhlcnRleHQ=', 'name_iv' => 'abcdefghijklmnop', 'encrypted' => true]);

        withHeaders(accountsWriter($user))
            ->patchJson("/api/v1/accounts/{$account->id}", ['name' => 'Savings'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Savings');

        expect($account->fresh())->name_iv->toBeNull()->encrypted->toBeFalse();
    });
});

describe('references', function () {
    it('refuses the private bank of another user, which would show its name', function () {
        $user = User::factory()->create();
        $private = Bank::factory()->create(['user_id' => User::factory()->create()->id, 'name' => 'Their private bank']);
        $account = Account::factory()->create(['user_id' => $user->id]);

        withHeaders(accountsWriter($user))
            ->postJson('/api/v1/accounts', ['name' => 'Main', 'type' => 'checking', 'currency_code' => 'EUR', 'bank_id' => $private->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bank_id');

        withHeaders(accountsWriter($user))
            ->patchJson("/api/v1/accounts/{$account->id}", ['bank_id' => $private->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bank_id');

        expect($account->fresh()->bank_id)->not->toBe($private->id);
    });

    it('stores a linked loan as it is, whatever case it was sent in', function () {
        $user = User::factory()->create();
        $loan = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Loan]);
        $property = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::RealEstate]);
        $property->realEstateDetail()->create(['property_type' => PropertyType::cases()[0]->value]);

        withHeaders(accountsWriter($user))
            ->patchJson("/api/v1/accounts/{$property->id}", ['linked_loan_account_id' => strtoupper($loan->id)])
            ->assertOk();

        expect($property->realEstateDetail()->sole()->linked_loan_account_id)->toBe($loan->id);
    });

    it('takes a public bank and one of the user\'s own', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        foreach ([Bank::factory()->create(['user_id' => null]), Bank::factory()->create(['user_id' => $user->id])] as $bank) {
            withHeaders(accountsWriter($user))
                ->patchJson("/api/v1/accounts/{$account->id}", ['bank_id' => $bank->id])
                ->assertOk()
                ->assertJsonPath('data.bank.id', $bank->id);
        }
    });

    it('only links a mortgage to a property of the request\'s space', function () {
        $user = User::factory()->create();
        $elsewhere = Space::factory()->create(['owner_id' => $user->id, 'personal' => false]);
        $property = Account::factory()->create(['user_id' => $user->id, 'space_id' => $elsewhere->id, 'type' => AccountType::RealEstate]);
        $property->realEstateDetail()->create(['property_type' => PropertyType::cases()[0]->value]);

        withHeaders(accountsWriter($user))
            ->postJson('/api/v1/accounts', ['name' => 'Mortgage', 'type' => 'loan', 'currency_code' => 'EUR', 'linked_real_estate_account_id' => $property->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('linked_real_estate_account_id');

        expect($property->realEstateDetail()->sole()->linked_loan_account_id)->toBeNull();
    });

    it('only links a property to a loan of the request\'s space', function () {
        $user = User::factory()->create();
        $elsewhere = Space::factory()->create(['owner_id' => $user->id, 'personal' => false]);
        $loan = Account::factory()->create(['user_id' => $user->id, 'space_id' => $elsewhere->id, 'type' => AccountType::Loan]);
        $property = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::RealEstate]);

        withHeaders(accountsWriter($user))
            ->patchJson("/api/v1/accounts/{$property->id}", ['linked_loan_account_id' => $loan->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('linked_loan_account_id');
    });
});
