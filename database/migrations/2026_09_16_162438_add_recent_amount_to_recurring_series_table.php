<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_series', function (Blueprint $table): void {
            // What the charge costs now, beside what it has cost over its life.
            // Signed like `expected_amount`, and unlike `price_alerted_amount`
            // two columns along, which stores a magnitude.
            //
            // Nullable with no backfill: null reads as "no recent figure yet"
            // and falls back to the lifetime median, which is what every caller
            // used before. The next scan fills it in.
            $table->bigInteger('recent_amount')->nullable()->after('expected_amount');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_series', function (Blueprint $table): void {
            $table->dropColumn('recent_amount');
        });
    }
};
