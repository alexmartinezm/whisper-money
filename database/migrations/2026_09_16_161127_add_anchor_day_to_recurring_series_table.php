<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_series', function (Blueprint $table): void {
            // The day of the month the charge is really billed on, so a
            // projection can return to it after a short month clipped it.
            // Null for weekly and biweekly series, which have no such day, and
            // for rows written before detection recorded one — those keep
            // projecting from the day of their last occurrence until the next
            // scan fills this in.
            $table->unsignedTinyInteger('anchor_day')->nullable()->after('interval_days');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_series', function (Blueprint $table): void {
            $table->dropColumn('anchor_day');
        });
    }
};
