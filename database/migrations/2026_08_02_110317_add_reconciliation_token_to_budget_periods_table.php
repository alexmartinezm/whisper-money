<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Identifies which reconciliation a period is currently waiting on.
     *
     * `processing_historical` alone cannot tell one run from another, so two
     * tracking edits in quick succession queue two jobs and the first to finish
     * declares the period settled — clearing the flag while the second is still
     * rewriting the assignments behind it. Stamping the period with a token
     * lets a job recognise it has been superseded and leave both the data and
     * the flag to whoever came after it.
     */
    public function up(): void
    {
        Schema::table('budget_periods', function (Blueprint $table): void {
            $table->uuid('reconciliation_token')->nullable()->after('processing_historical');
        });
    }

    public function down(): void
    {
        Schema::table('budget_periods', function (Blueprint $table): void {
            $table->dropColumn('reconciliation_token');
        });
    }
};
