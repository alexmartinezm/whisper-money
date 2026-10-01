<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remembers the regular allocation of a period that holds a one-off amount.
     *
     * Null for every period that runs on the budget's regular allocation, which
     * is all of them until a single period is given its own figure. While it is
     * set, `allocated_amount` is the one-off amount and this column is what the
     * period would otherwise have: the periods generated after it are seeded
     * from it, and a later change to the budget's allocation moves it instead
     * of overwriting the one-off amount.
     */
    public function up(): void
    {
        Schema::table('budget_periods', function (Blueprint $table): void {
            $table->bigInteger('regular_allocated_amount')->nullable()->after('allocated_amount');
        });
    }

    public function down(): void
    {
        Schema::table('budget_periods', function (Blueprint $table): void {
            $table->dropColumn('regular_allocated_amount');
        });
    }
};
