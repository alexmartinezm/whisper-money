<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionSyncController extends Controller
{
    /**
     * Fetch transactions for client-side IndexedDB sync.
     * Supports delta sync via 'since' parameter.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Transaction::query()
            ->where('user_id', $request->user()->id);

        if ($request->has('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        }

        $transactions = $query
            ->with(['labels', 'splits.category'])
            ->orderBy('transaction_date', 'desc')
            ->orderBy('updated_at', 'desc')
            ->get();

        $transactions->each->append(['is_split', 'split_count']);

        return response()->json([
            'data' => $transactions,
        ]);
    }
}
