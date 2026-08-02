<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt-in for price-rise warnings, plus the price each series was last
     * announced at.
     *
     * Storing the announced amount rather than a date is what lets a second
     * rise speak up. A subscription that goes 12.99 → 15.99 is announced once
     * and then stays quiet at 15.99 forever; when it later moves to 17.99 the
     * stored figure no longer matches and the warning fires again.
     */
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table): void {
            $table->boolean('notify_price_changes')->default(false)->after('runway_alerted_low_on');
        });

        Schema::table('recurring_series', function (Blueprint $table): void {
            $table->bigInteger('price_alerted_amount')->nullable()->after('expected_amount');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table): void {
            $table->dropColumn('notify_price_changes');
        });

        Schema::table('recurring_series', function (Blueprint $table): void {
            $table->dropColumn('price_alerted_amount');
        });
    }
};
