<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingSyncTrigger;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Requests\OpenBanking\MapAccountsRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\AccountUserCurrencyService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AccountMappingController extends Controller
{
    use CreatesAccountsFromPending;

    public function show(BankingConnection $connection, AccountUserCurrencyService $accountUserCurrencyService): Response|RedirectResponse
    {
        if ($connection->user_id !== auth()->id()) {
            abort(403);
        }

        $user = auth()->user();

        if (! $connection->hasPendingAccounts()) {
            $redirect = $user->isOnboarded() ? 'settings.connections.index' : 'onboarding';
            $params = $user->isOnboarded() ? [] : ['step' => 'create-account'];

            return redirect()->route($redirect, $params);
        }

        // During onboarding, skip the mapping UI — auto-create all accounts directly
        if (! $user->isOnboarded()) {
            $this->createAccountsFromPending($user, $connection, $accountUserCurrencyService);
            SyncBankingConnectionJob::dispatch($connection, trigger: BankingSyncTrigger::Connect);

            return redirect()->route('onboarding', ['step' => 'create-account'])
                ->with('success', 'Bank account connected successfully.');
        }

        $mappableAccounts = $connection->mappablePendingAccounts();

        // Nothing the bank gave us can be mapped, so there is no decision left for the
        // user to make. Close the connection instead of leaving it awaiting a mapping
        // that can never happen.
        if ($mappableAccounts === []) {
            $this->createAccountsFromPending($user, $connection, $accountUserCurrencyService);

            return redirect()->route('settings.connections.index')
                ->with('error', __('Your bank did not provide an identifier for any of its accounts, so they cannot be synced.'));
        }

        $existingAccounts = $user
            ->accounts()
            ->whereNull('banking_connection_id')
            ->with('bank')
            ->get();

        return Inertia::render('open-banking/map-accounts', [
            'connection' => $connection,
            'bankAccounts' => $mappableAccounts,
            'existingAccounts' => $existingAccounts,
            'unmappableAccountNames' => $connection->unmappablePendingAccountNames(),
        ]);
    }

    public function store(MapAccountsRequest $request, BankingConnection $connection, AccountUserCurrencyService $accountUserCurrencyService): RedirectResponse
    {
        if ($connection->user_id !== auth()->id()) {
            abort(403);
        }

        $user = auth()->user();
        $mappings = $request->validated()['mappings'];

        $bank = $this->resolveConnectionBank($connection);

        $pendingAccounts = collect($connection->pending_accounts_data)
            ->keyBy('uid');

        foreach ($mappings as $mapping) {
            $accountData = $pendingAccounts->get($mapping['bank_account_uid']);

            if (! $accountData) {
                continue;
            }

            if ($mapping['action'] === 'create') {
                $this->createAccountFromPendingData($user, $connection, $bank, $accountData, $mapping['bank_account_uid'], $accountUserCurrencyService);
            } elseif ($mapping['action'] === 'link') {
                $this->linkMappedAccount($user, $connection, $bank, $accountData, $mapping, $accountUserCurrencyService);
            }
        }

        $connection->update([
            'status' => BankingConnectionStatus::Active,
            'pending_accounts_data' => null,
            'error_message' => null,
            'consecutive_sync_failures' => 0,
        ]);

        SyncBankingConnectionJob::dispatch($connection, trigger: BankingSyncTrigger::AccountMapping);

        $successRedirect = $user->isOnboarded() ? 'settings.connections.index' : 'onboarding';
        $redirectParams = $user->isOnboarded() ? [] : ['step' => 'create-account'];

        return redirect()->route($successRedirect, $redirectParams)
            ->with('success', 'Bank account connected successfully.');
    }

    /**
     * @param  array<string, mixed>  $accountData
     * @param  array<string, mixed>  $mapping
     */
    private function linkMappedAccount(
        User $user,
        BankingConnection $connection,
        Bank $bank,
        array $accountData,
        array $mapping,
        AccountUserCurrencyService $accountUserCurrencyService,
    ): void {
        $existingAccount = $user->accounts()->find($mapping['existing_account_id']);

        if (! $existingAccount) {
            return;
        }

        $existingAccount->update([
            'banking_connection_id' => $connection->id,
            'external_account_id' => $mapping['bank_account_uid'],
            'iban' => $accountData['account_id']['iban'] ?? $existingAccount->iban,
            'bank_id' => $bank->id,
            'linked_at' => now(),
        ]);

        $accountUserCurrencyService->syncFromFirstAccount($existingAccount);
    }
}
