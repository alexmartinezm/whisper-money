<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table): void {
            // Opt-in. A previous release learned the hard way that a
            // notification enabled by default reads as spam.
            $table->boolean('notify_upcoming_recurring')->default(false);
        });

        Schema::table('recurring_series', function (Blueprint $table): void {
            // The occurrence a reminder was last sent for, so a daily job can
            // remind once per charge instead of once per day.
            $table->date('last_reminded_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table): void {
            $table->dropColumn('notify_upcoming_recurring');
        });

        Schema::table('recurring_series', function (Blueprint $table): void {
            $table->dropColumn('last_reminded_on');
        });
    }
};
