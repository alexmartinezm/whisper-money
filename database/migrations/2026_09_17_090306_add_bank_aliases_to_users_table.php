<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // The names a bank writes for this person. Some banks put the
            // account holder in the counterparty field of a direct debit, and
            // that field then says nothing about who was paid — but only the
            // user knows how their bank spells them, which is rarely the name
            // they signed up with.
            $table->json('bank_aliases')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('bank_aliases');
        });
    }
};
