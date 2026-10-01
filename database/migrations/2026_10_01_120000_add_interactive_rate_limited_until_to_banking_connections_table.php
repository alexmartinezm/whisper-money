<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banking_connections', function (Blueprint $table): void {
            $table->dateTime('interactive_rate_limited_until')->nullable()->after('rate_limited_until');
        });
    }

    public function down(): void
    {
        Schema::table('banking_connections', function (Blueprint $table): void {
            $table->dropColumn('interactive_rate_limited_until');
        });
    }
};
