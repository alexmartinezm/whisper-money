<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\CursorPaginator;
use App\Http\Requests\Api\V1\DeleteTransactionRequest;
use App\Http\Requests\Api\V1\SearchTransactionsRequest;
use App\Http\Requests\Api\V1\StoreTransactionRequest;
use App\Http\Requests\Api\V1\UpdateTransactionRequest;
use App\Http\Resources\V1\TransactionResource;
use App\Services\Transactions\TransactionSearch;
use App\Services\Transactions\TransactionWriter;
use App\Services\Transactions\WrittenTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionController extends Controller
{
    public function __construct(private TransactionWriter $writer) {}

    /**
     * List transactions
     *
     * Your transactions in a space, the most recent day first, narrowed by any
     * of the filters as the app's list is: `category_id` takes the whole
     * subtree, a category and `label_ids` sent together match either, and each
     * other filter narrows further.
     *
     * A split transaction is one row. A filter that matches one line returns
     * the whole transaction: `amount` stays the full amount and `splits` holds
     * every line, the ones outside the filter included, so to add up a category
     * read its lines, not `amount`. Labels sit on the transaction, not on a
     * line, so `label_ids` matches every line of it.
     *
     * `query` reads what the server can read: an encrypted description or note
     * never matches, though the row can still match on the counterparty names
     * or any other filter, and reads as `''` in those fields.
     */
    public function index(SearchTransactionsRequest $request, TransactionSearch $search): AnonymousResourceCollection
    {
        return CursorPaginator::paginate(
            $search->query($this->user($request), $this->space($request), $request->filters())->with(TransactionResource::Relations),
            $request,
            [['transaction_date', 'desc'], ['id', 'desc']],
            TransactionResource::class,
        );
    }

    /**
     * Retrieve a transaction
     *
     * @param  string  $transaction  The transaction's id.
     */
    public function show(Request $request, string $transaction): TransactionResource
    {
        return new TransactionResource($this->transaction($request, $transaction));
    }

    /**
     * Create a transaction
     *
     * Records a transaction by hand on one of your accounts in the space,
     * bank-connected ones included: a sync never removes it, and nothing
     * deduplicates it against what the bank sends. Automation rules run on it
     * unless it comes with a category, and `label_ids` go on top of what a
     * rule tags it with. The response adds `balance_updated`, whether
     * `update_balance` moved the account's balance snapshots.
     */
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $written = $this->writer->create(
            $this->user($request),
            $this->space($request),
            $this->account($request, $request->fields()['account_id']),
            $request->fields(),
            $request->labelIds(),
            $request->boolean('update_balance'),
        );

        return $this->written($written)->response()->setStatusCode(201);
    }

    /**
     * Update a transaction
     *
     * Changes only the fields you send, and also sets or clears the category.
     * The labels are not changed here. The notes, the category and the date can
     * change on any transaction; the rest (description, amount, currency,
     * account and counterparty names) only on one you recorded by hand: on one
     * from a bank or an import, sending them is a `422` and nothing is saved.
     * The category and amount of a split transaction belong to its lines, so
     * they are a `422` under `splits`. A new account brings its currency unless
     * `currency_code` picks another.
     *
     * A description or note encrypted on the device reads as `''`. New text
     * replaces it, and leaves the other field as it is; a blank `notes` on an
     * encrypted note is a `422`, so writing back what was read erases nothing.
     *
     * The response adds `balance_updated`, whether `update_balance` moved the
     * account's balance snapshots. It only moves them for one you recorded by
     * hand.
     *
     * @param  string  $transaction  The transaction's id.
     */
    public function update(UpdateTransactionRequest $request, string $transaction): TransactionResource
    {
        return $this->written($this->writer->update(
            $request->transaction(),
            $request->changes(),
            $request->boolean('update_balance'),
        ));
    }

    /**
     * Delete a transaction
     *
     * Deletes a transaction, one that came from a bank or an import included:
     * as in the app, the next sync does not bring it back.
     *
     * @param  string  $transaction  The transaction's id.
     */
    public function destroy(DeleteTransactionRequest $request, string $transaction): JsonResponse
    {
        $model = $request->transaction();

        return response()->json([
            'id' => $model->id,
            'deleted' => true,
            'balance_updated' => $this->writer->delete($model, $request->boolean('update_balance')),
        ]);
    }

    private function written(WrittenTransaction $written): TransactionResource
    {
        return (new TransactionResource($written->transaction))->additional([
            'balance_updated' => $written->balanceUpdated,
        ]);
    }
}
