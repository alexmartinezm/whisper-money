<?php

namespace App\Mcp\Tools;

use App\Features\TransactionSplitting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\ReplaceTransactionSplits;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Pennant\Feature;

#[IsDestructive]
#[Description('Create, replace, or remove the category split of a transaction. This changes category postings only; the parent ledger amount and bank data stay unchanged.')]
class SplitTransaction extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()
                ->description('Transaction id to split. Call search_transactions to find ids.')
                ->required(),
            'splits' => $schema->array()
                ->items($schema->object([
                    'category_id' => $schema->string()->required(),
                    'amount' => $schema->integer()->required(),
                ])->withoutAdditionalProperties())
                ->description('Complete ordered replacement. Use [] to remove the split and provide fallback_category_id.')
                ->required(),
            'fallback_category_id' => $schema->string()
                ->description('Required only when splits is empty; becomes the parent manual category.'),
            'space' => $schema->string()
                ->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $data = $request->validate([
            'transaction_id' => ['required', 'uuid'],
            'splits' => ['present', 'array', 'list'],
            'splits.*' => ['array:category_id,amount'],
            'splits.*.category_id' => ['required', 'uuid'],
            'splits.*.amount' => ['required', 'integer', 'not_in:0'],
            'fallback_category_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        /** @var array<int, array{category_id: string, amount: int}> $splits */
        $splits = array_values($data['splits']);

        if ($splits !== [] && $request->has('fallback_category_id')) {
            return Response::error('fallback_category_id is only valid when splits is empty.');
        }

        $space = $this->resolveSpace($request, $user);
        $transaction = $this->transactionInSpace($request, $space);

        if ($splits !== [] && ! Feature::for($user)->active(TransactionSplitting::class)) {
            return Response::error('Creating or replacing transaction splits is currently disabled. Existing splits may still be removed.');
        }

        $updated = DB::transaction(
            fn (): Transaction => app(ReplaceTransactionSplits::class)->replace(
                $transaction,
                $splits,
                $data['fallback_category_id'] ?? null,
            ),
            attempts: 5,
        );

        return $this->json(['transaction' => $this->presentTransaction($updated)]);
    }
}
