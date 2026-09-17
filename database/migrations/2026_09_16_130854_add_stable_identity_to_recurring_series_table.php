<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_series', function (Blueprint $table): void {
            // Stable identity is separate from the latest observed bank field.
            // Nullable keeps old rows readable until the first safe rescan.
            $table->string('identity_key', 191)->nullable()->after('merchant_key');
            $table->json('identity_aliases')->nullable()->after('identity_key');
        });

        Schema::table('recurring_series', function (Blueprint $table): void {
            // Account is evidence for separating contracts. deleted_at keeps a
            // user deletion from blocking a deliberate future review candidate.
            $table->dropUnique('recurring_series_identity_unique');
            $table->unique(
                ['user_id', 'space_id', 'match_field', 'merchant_key', 'direction', 'currency_code', 'account_id', 'deleted_at'],
                'recurring_series_identity_unique',
            );
        });
    }

    public function down(): void
    {
        $hasIncompatibleRows = DB::table('recurring_series')
            ->select(['user_id', 'space_id', 'match_field', 'merchant_key', 'direction', 'currency_code'])
            ->groupBy(['user_id', 'space_id', 'match_field', 'merchant_key', 'direction', 'currency_code'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasIncompatibleRows) {
            throw new LogicException(
                'Cannot roll back recurring identity migration: multiple account/deleted rows share the legacy identity.',
            );
        }

        Schema::table('recurring_series', function (Blueprint $table): void {
            $table->dropUnique('recurring_series_identity_unique');
            $table->unique(
                ['user_id', 'space_id', 'match_field', 'merchant_key', 'direction', 'currency_code'],
                'recurring_series_identity_unique',
            );
            $table->dropColumn(['identity_key', 'identity_aliases']);
        });
    }
};
