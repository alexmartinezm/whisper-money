<?php

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Enums\RuleOrigin;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiRuleLearner;
use App\Services\Ai\CategorizationOutcome;
use App\Services\AutomationRuleService;
use Illuminate\Support\Facades\DB;

function expenseCategory(User $user): Category
{
    return Category::factory()->for($user)->create([
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
}

function merchantTransaction(User $user, string $creditor): Transaction
{
    return Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -4300,
        'creditor_name' => $creditor,
        'description' => "{$creditor} compra",
    ]);
}

function outcome(Transaction $transaction, string $categoryId, float $confidence = 0.95, bool $unambiguous = true): CategorizationOutcome
{
    return new CategorizationOutcome($transaction, $categoryId, $confidence, $unambiguous, true);
}

/**
 * Production holds rules both ways: a JSON object, and the string a web save
 * used to wrap it in, which the model's array cast hands back undecoded.
 *
 * @param  array<string, mixed>  $conditions
 * @return array<string, mixed>|string
 */
function rulesJsonStoredAs(string $form, array $conditions): array|string
{
    return $form === 'double-encoded' ? (string) json_encode($conditions) : $conditions;
}

function creditorClause(string $merchant): array
{
    return ['==' => [['var' => 'creditor_name'], $merchant]];
}

it('creates an ai-owned rule at the lowest priority and links the transaction', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);
    AutomationRule::factory()->for($user)->create(['priority' => 5]);
    $transaction = merchantTransaction($user, 'Mercadona');

    $rule = app(AiRuleLearner::class)->learn(outcome($transaction, $category->id));

    expect($rule)->not->toBeNull()
        ->and($rule->origin)->toBe(RuleOrigin::Ai)
        ->and($rule->action_category_id)->toBe($category->id)
        ->and($rule->priority)->toBe(6)
        ->and($rule->rules_json)->toBe(['==' => [['var' => 'creditor_name'], 'mercadona']])
        ->and($transaction->refresh()->categorized_by_rule_id)->toBe($rule->id);
});

it('resolves a fresh instance per container lookup so the memoized corpus cannot leak or go stale', function () {
    // The per-user corpus cache has no invalidation and is safe only while the
    // learner is never a singleton. Guard that invariant.
    expect(app(AiRuleLearner::class))->not->toBe(app(AiRuleLearner::class));
});

it('learns each correction correctly across a batch while loading the corpus once', function () {
    $user = User::factory()->create();
    $target = expenseCategory($user);
    // A separate category keeps the corrected txns out of the "uncategorized"
    // count, so the overbroad guard (which needs uncategorized rows) is a no-op
    // and each correction actually learns a description rule.
    $existing = expenseCategory($user);

    // Merchant-less, plaintext transactions force the description-token path,
    // which is what loads the per-user description corpus.
    $makeTxn = fn (string $description): Transaction => Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => $existing->id,
        'creditor_name' => null,
        'debtor_name' => null,
        'description' => $description,
    ]);

    $first = $makeTxn('Netflix subscription');
    $second = $makeTxn('Spotify premium');

    // One instance across the batch, mirroring the bulkUpdate loop where the
    // handler (and its learner) is resolved once.
    $learner = app(AiRuleLearner::class);

    DB::enableQueryLog();
    $firstRule = $learner->learnFromCorrection($first, $target->id);
    $secondRule = $learner->learnFromCorrection($second, $target->id);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    // The second learning ran against the memoized corpus and still produced a
    // distinct, valid clause: both corrections live in the one target rule.
    expect($firstRule)->not->toBeNull()
        ->and($secondRule)->not->toBeNull()
        ->and($secondRule->id)->toBe($firstRule->id)
        ->and($secondRule->refresh()->rules_json)->toHaveKey('or')
        ->and($secondRule->rules_json['or'])->toHaveCount(2);

    // The corpus is the pluck of the `description` column (not the matcher's
    // count(*) probes, which also filter on description_iv), loaded once.
    $corpusLoads = $queries->filter(fn (array $q): bool => str_starts_with(strtolower(ltrim($q['query'])), 'select')
        && str_contains($q['query'], 'description_iv')
        && ! str_contains(strtolower($q['query']), 'count(')
    );

    expect($corpusLoads)->toHaveCount(1);
});

it('does not learn a description rule from a single short token', function () {
    $user = User::factory()->create();
    $target = expenseCategory($user);
    // Corrected txn parked in another category, so the overbroad guard (which
    // measures uncategorized rows) is a no-op and only the token guard decides.
    $existing = expenseCategory($user);

    // Merchant-less so the description-token path runs. A lone short token like
    // "suc" (sucursal) is a generic banking abbreviation and must not become a
    // rule, even when it is rare in this user's corpus.
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => $existing->id,
        'creditor_name' => null,
        'debtor_name' => null,
        'description' => 'suc',
    ]);

    expect(app(AiRuleLearner::class)->learnFromCorrection($transaction, $target->id))->toBeNull();
});

it('learns a description rule from a single sufficiently long token', function () {
    $user = User::factory()->create();
    $target = expenseCategory($user);
    $existing = expenseCategory($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => $existing->id,
        'creditor_name' => null,
        'debtor_name' => null,
        'description' => 'netflix',
    ]);

    $rule = app(AiRuleLearner::class)->learnFromCorrection($transaction, $target->id);

    expect($rule)->not->toBeNull()
        ->and($rule->rules_json)->toBe(['in' => ['netflix', ['var' => 'description']]]);
});

it('learns a description rule from two short tokens', function () {
    $user = User::factory()->create();
    $target = expenseCategory($user);
    $existing = expenseCategory($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => $existing->id,
        'creditor_name' => null,
        'debtor_name' => null,
        'description' => 'abc def',
    ]);

    $rule = app(AiRuleLearner::class)->learnFromCorrection($transaction, $target->id);

    expect($rule)->not->toBeNull()
        ->and($rule->rules_json)->toBe(['and' => [
            ['in' => ['abc', ['var' => 'description']]],
            ['in' => ['def', ['var' => 'description']]],
        ]]);
});

it('appends a new merchant to the existing ai rule for the same category', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);

    $first = app(AiRuleLearner::class)->learn(outcome(merchantTransaction($user, 'Mercadona'), $category->id));
    $second = app(AiRuleLearner::class)->learn(outcome(merchantTransaction($user, 'Carrefour'), $category->id));

    expect($second->id)->toBe($first->id)
        ->and(AutomationRule::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($second->refresh()->rules_json)->toBe([
            'or' => [
                ['==' => [['var' => 'creditor_name'], 'mercadona']],
                ['==' => [['var' => 'creditor_name'], 'carrefour']],
            ],
        ]);
});

it('does not duplicate a merchant already on the rule', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);

    app(AiRuleLearner::class)->learn(outcome(merchantTransaction($user, 'Mercadona'), $category->id));
    $rule = app(AiRuleLearner::class)->learn(outcome(merchantTransaction($user, 'mercadona'), $category->id));

    expect($rule->refresh()->rules_json)->toBe(['==' => [['var' => 'creditor_name'], 'mercadona']]);
});

it('learns a rule that categorizes a future transaction from the same merchant', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);

    app(AiRuleLearner::class)->learn(outcome(merchantTransaction($user, 'Mercadona'), $category->id));

    $future = merchantTransaction($user, 'Mercadona');
    app(AutomationRuleService::class)->applyRules($future);

    expect($future->refresh()->category_id)->toBe($category->id);
});

it('does not learn an ambiguous merchant', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);

    $rule = app(AiRuleLearner::class)->learn(
        outcome(merchantTransaction($user, 'Amazon'), $category->id, unambiguous: false),
    );

    expect($rule)->toBeNull()
        ->and(AutomationRule::query()->where('user_id', $user->id)->count())->toBe(0);
});

it('does not learn below the rule confidence bar', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);

    $rule = app(AiRuleLearner::class)->learn(
        outcome(merchantTransaction($user, 'Mercadona'), $category->id, confidence: 0.8),
    );

    expect($rule)->toBeNull();
});

it('does not learn when the transaction has no merchant key', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'category_id' => null,
        'amount' => -1000,
        'creditor_name' => null,
        'debtor_name' => null,
        'description' => 'card payment 1234',
    ]);

    expect(app(AiRuleLearner::class)->learn(outcome($transaction, $category->id)))->toBeNull();
});

it('never reuses a user-owned rule, even for the same category', function () {
    $user = User::factory()->create();
    $category = expenseCategory($user);
    $userRule = AutomationRule::factory()->for($user)->create(['action_category_id' => $category->id]);

    $aiRule = app(AiRuleLearner::class)->learn(outcome(merchantTransaction($user, 'Mercadona'), $category->id));

    expect($aiRule->id)->not->toBe($userRule->id)
        ->and($aiRule->origin)->toBe(RuleOrigin::Ai);
});

it('keeps the generated title within the column when the bank stuffs the statement line into the merchant name', function () {
    $user = User::factory()->create();
    $target = Category::factory()->for($user)->create([
        'name' => 'Hobbies y otras actividades de ocio',
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]);
    $existing = expenseCategory($user);

    // Real payload from PHP-LARAVEL-53: the bank sends the whole statement line
    // (dates, concept, amount, running balance, card number) as the merchant, so
    // two corrections alone already overflow the title column.
    $statementLines = [
        "15/06 12/06 Pago Con Tarjeta En Discos, Libros, Fotos Y Pc's -59,00 189,27 | 4940197152806468 Classpass* Monthly",
        '01/06 31/05 Pago Con Tarjeta En Restaurantes Y Cafeterias -9,00 1.819,59 | 4940197152806468 Dirty Castelldefels Castelldefelses',
    ];
    $learner = app(AiRuleLearner::class);
    $rule = null;

    foreach ($statementLines as $statementLine) {
        $rule = $learner->learnFromCorrection(
            Transaction::factory()->plaintext()->create([
                'user_id' => $user->id,
                'category_id' => $existing->id,
                'creditor_name' => $statementLine,
            ]),
            $target->id,
        );
    }

    // Each merchant is shortened enough that the whole title stays well inside
    // the column and the "→ Category" tail survives.
    expect($rule)->not->toBeNull()
        ->and(mb_strlen($rule->refresh()->title))->toBeLessThan(AutomationRule::MAX_TITLE_LENGTH)
        ->and($rule->title)->toContain($target->name)
        ->and($rule->title)->toStartWith('15/06 12/06 Pago Con Tarjeta En Discos,…, ')
        ->and($rule->rules_json['or'])->toHaveCount(2);
});

it('stores the two forms the learner has to read as different columns', function (string $form, string $expectedType) {
    $user = User::factory()->create();
    $rule = AutomationRule::factory()->for($user)->create([
        'rules_json' => rulesJsonStoredAs($form, creditorClause('mercadona')),
    ]);

    $stored = json_decode((string) DB::table('automation_rules')->where('id', $rule->id)->value('rules_json'), true);

    expect(get_debug_type($stored))->toBe($expectedType);
})->with([
    'object' => ['object', 'array'],
    'double-encoded' => ['double-encoded', 'string'],
]);

it('keeps the conditions when a correction lands on a rule', function (string $form) {
    $user = User::factory()->create();
    $target = expenseCategory($user);
    $existing = expenseCategory($user);
    $rule = AutomationRule::factory()->for($user)->create([
        'origin' => RuleOrigin::Correction,
        'action_category_id' => $target->id,
        'rules_json' => rulesJsonStoredAs($form, ['or' => [creditorClause('mercadona'), creditorClause('lidl')]]),
    ]);
    $corrected = merchantTransaction($user, 'Carrefour');
    $corrected->update(['category_id' => $existing->id]);

    $learned = app(AiRuleLearner::class)->learnFromCorrection($corrected, $target->id);

    expect($learned->id)->toBe($rule->id)
        ->and($rule->refresh()->rules_json)->toBe(['or' => [
            creditorClause('mercadona'),
            creditorClause('lidl'),
            creditorClause('carrefour'),
        ]]);
})->with(['object', 'double-encoded']);

it('keeps a single condition when a correction lands on a rule', function (string $form) {
    $user = User::factory()->create();
    $target = expenseCategory($user);
    $rule = AutomationRule::factory()->for($user)->create([
        'origin' => RuleOrigin::Correction,
        'action_category_id' => $target->id,
        'rules_json' => rulesJsonStoredAs($form, creditorClause('mercadona')),
    ]);

    app(AiRuleLearner::class)->learnFromCorrection(merchantTransaction($user, 'Carrefour'), $target->id);

    expect($rule->refresh()->rules_json)->toBe(['or' => [creditorClause('mercadona'), creditorClause('carrefour')]]);
})->with(['object', 'double-encoded']);

it('does not repeat a condition the rule already has', function (string $form) {
    $user = User::factory()->create();
    $target = expenseCategory($user);
    $conditions = ['or' => [creditorClause('mercadona'), creditorClause('lidl')]];
    $rule = AutomationRule::factory()->for($user)->create([
        'origin' => RuleOrigin::Correction,
        'action_category_id' => $target->id,
        'rules_json' => rulesJsonStoredAs($form, $conditions),
    ]);

    app(AiRuleLearner::class)->learnFromCorrection(merchantTransaction($user, 'Lidl'), $target->id);

    expect($rule->refresh()->rules_json)->toBe(rulesJsonStoredAs($form, $conditions));
})->with(['object', 'double-encoded']);

it('keeps the conditions when an ai categorization lands on a rule', function (string $form) {
    $user = User::factory()->create();
    $category = expenseCategory($user);
    $rule = AutomationRule::factory()->for($user)->create([
        'origin' => RuleOrigin::Ai,
        'action_category_id' => $category->id,
        'rules_json' => rulesJsonStoredAs($form, creditorClause('mercadona')),
    ]);

    app(AiRuleLearner::class)->learn(outcome(merchantTransaction($user, 'Carrefour'), $category->id));

    expect($rule->refresh()->rules_json)->toBe(['or' => [creditorClause('mercadona'), creditorClause('carrefour')]]);
})->with(['object', 'double-encoded']);

it('moves a merchant out of the correction rule it was in', function (string $form) {
    $user = User::factory()->create();
    $before = expenseCategory($user);
    $after = expenseCategory($user);
    $rule = AutomationRule::factory()->for($user)->create([
        'origin' => RuleOrigin::Correction,
        'action_category_id' => $before->id,
        'rules_json' => rulesJsonStoredAs($form, ['or' => [creditorClause('mercadona'), creditorClause('lidl')]]),
    ]);

    app(AiRuleLearner::class)->learnFromCorrection(merchantTransaction($user, 'Mercadona'), $after->id);

    expect($rule->refresh()->rules_json)->toBe(creditorClause('lidl'));
})->with(['object', 'double-encoded']);

it('deletes a correction rule left with nothing when its merchant moves', function (string $form) {
    $user = User::factory()->create();
    $rule = AutomationRule::factory()->for($user)->create([
        'origin' => RuleOrigin::Correction,
        'action_category_id' => expenseCategory($user)->id,
        'rules_json' => rulesJsonStoredAs($form, creditorClause('mercadona')),
    ]);

    app(AiRuleLearner::class)->learnFromCorrection(merchantTransaction($user, 'Mercadona'), expenseCategory($user)->id);

    expect(AutomationRule::query()->whereKey($rule->id)->exists())->toBeFalse();
})->with(['object', 'double-encoded']);

it('forgets a merchant and keeps the rest of the rule', function (string $form) {
    $user = User::factory()->create();
    $rule = AutomationRule::factory()->for($user)->create([
        'origin' => RuleOrigin::Ai,
        'action_category_id' => expenseCategory($user)->id,
        'rules_json' => rulesJsonStoredAs($form, ['or' => [creditorClause('mercadona'), creditorClause('lidl'), creditorClause('aldi')]]),
    ]);

    app(AiRuleLearner::class)->forget($rule, merchantTransaction($user, 'Lidl'));

    expect($rule->refresh()->rules_json)->toBe(['or' => [creditorClause('mercadona'), creditorClause('aldi')]]);
})->with(['object', 'double-encoded']);

it('deletes the rule when the merchant forgotten was its last condition', function (string $form) {
    $user = User::factory()->create();
    $rule = AutomationRule::factory()->for($user)->create([
        'origin' => RuleOrigin::Ai,
        'action_category_id' => expenseCategory($user)->id,
        'rules_json' => rulesJsonStoredAs($form, creditorClause('mercadona')),
    ]);

    app(AiRuleLearner::class)->forget($rule, merchantTransaction($user, 'Mercadona'));

    expect(AutomationRule::query()->whereKey($rule->id)->exists())->toBeFalse();
})->with(['object', 'double-encoded']);

it('releases a merchant from every ai rule that carries it', function (string $form) {
    $user = User::factory()->create();
    $narrowed = AutomationRule::factory()->for($user)->create([
        'origin' => RuleOrigin::Ai,
        'action_category_id' => expenseCategory($user)->id,
        'rules_json' => rulesJsonStoredAs($form, ['or' => [creditorClause('mercadona'), creditorClause('lidl')]]),
    ]);
    $emptied = AutomationRule::factory()->for($user)->create([
        'origin' => RuleOrigin::Ai,
        'action_category_id' => expenseCategory($user)->id,
        'rules_json' => rulesJsonStoredAs($form, creditorClause('mercadona')),
    ]);

    app(AiRuleLearner::class)->forgetFromAiRules(merchantTransaction($user, 'Mercadona'));

    expect($narrowed->refresh()->rules_json)->toBe(creditorClause('lidl'))
        ->and(AutomationRule::query()->whereKey($emptied->id)->exists())->toBeFalse();
})->with(['object', 'double-encoded']);

it('leaves a rule alone when it does not carry the merchant to forget', function (string $form) {
    $user = User::factory()->create();
    $conditions = creditorClause('lidl');
    $rule = AutomationRule::factory()->for($user)->create([
        'origin' => RuleOrigin::Ai,
        'action_category_id' => expenseCategory($user)->id,
        'rules_json' => rulesJsonStoredAs($form, $conditions),
    ]);

    app(AiRuleLearner::class)->forget($rule, merchantTransaction($user, 'Mercadona'));

    expect($rule->refresh()->rules_json)->toBe(rulesJsonStoredAs($form, $conditions));
})->with(['object', 'double-encoded']);
