<?php

namespace App\Http\Controllers\OpenBanking\Concerns;

use App\Enums\BankingConnectionStatus;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\AccountUserCurrencyService;

trait CreatesAccountsFromPending
{
    /**
     * Auto-create all pending accounts from a banking connection without user interaction.
     *
     * This resolves the correct account type based on the provider (Investment for
     * Indexa Capital, Binance, Bitpanda, and Coinbase; Checking for everything else) and clears the
     * pending data once accounts have been created.
     */
    private function createAccountsFromPending(User $user, BankingConnection $connection, AccountUserCurrencyService $accountUserCurrencyService): void
    {
        $bank = $this->resolveConnectionBank($connection);

        foreach ($connection->mappablePendingAccounts() as $accountData) {
            $this->createAccountFromPendingData($user, $connection, $bank, $accountData, $accountData['uid'], $accountUserCurrencyService);
        }

        $connection->update([
            'status' => BankingConnectionStatus::Active,
            'pending_accounts_data' => null,
        ]);
    }

    /**
     * The shared bank row for this ASPSP, backfilling a logo the first time the
     * connection carries one.
     */
    private function resolveConnectionBank(BankingConnection $connection): Bank
    {
        $bank = Bank::firstOrCreate(
            ['name' => $connection->aspsp_name, 'user_id' => null],
            ['name' => $connection->aspsp_name, 'logo' => $connection->aspsp_logo],
        );

        if (! $bank->logo && $connection->aspsp_logo) {
            $bank->update(['logo' => $connection->aspsp_logo]);
        }

        return $bank;
    }

    /**
     * One account from a connection's pending payload. The account type follows
     * the provider (Investment for Indexa Capital, Binance, Bitpanda and
     * Coinbase; Checking for everything else).
     *
     * @param  array<string, mixed>  $accountData
     */
    private function createAccountFromPendingData(
        User $user,
        BankingConnection $connection,
        Bank $bank,
        array $accountData,
        string $uid,
        AccountUserCurrencyService $accountUserCurrencyService,
    ): void {
        $account = $user->accounts()->create([
            'name' => $accountData['name']
                ?? $accountData['account_id']['iban']
                ?? $connection->aspsp_name.' Account',
            'name_iv' => null,
            'encrypted' => false,
            'bank_id' => $bank->id,
            'currency_code' => $accountUserCurrencyService->resolveImportedCurrency($accountData['currency'] ?? null, $user),
            'type' => $connection->provider->defaultAccountType()->value,
            'banking_connection_id' => $connection->id,
            'external_account_id' => $uid,
            'iban' => $accountData['account_id']['iban'] ?? null,
        ]);

        $accountUserCurrencyService->syncFromFirstAccount($account);
    }
}
