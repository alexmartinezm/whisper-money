<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opt-in for the shortfall warning, plus the state that keeps it quiet.
     *
     * The projection is recomputed every night and will keep reporting the same
     * dip every morning until the day arrives, so the alert has to remember
     * which one it already announced. Storing the projected low date rather
     * than the send date is what makes it edge-triggered: one message per dip,
     * and a new one only once that dip has passed or a fresh one appears.
     */
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table): void {
            $table->boolean('notify_runway_shortfall')->default(false)->after('notify_upcoming_recurring');
            $table->date('runway_alerted_low_on')->nullable()->after('notify_runway_shortfall');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table): void {
            $table->dropColumn(['notify_runway_shortfall', 'runway_alerted_low_on']);
        });
    }
};
