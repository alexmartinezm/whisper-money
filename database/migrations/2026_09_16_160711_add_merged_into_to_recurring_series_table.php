<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_series', function (Blueprint $table): void {
            // Where a duplicate went. A series absorbed into another is soft
            // deleted like one the user removed, and the two have to stay
            // distinguishable: a deleted identity is a decision detection must
            // never undo, while an absorbed one is detection's own bookkeeping
            // and must not block the survivor from being recognised again.
            $table->foreignUuid('merged_into_id')
                ->nullable()
                ->after('identity_aliases')
                ->constrained('recurring_series')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('recurring_series', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merged_into_id');
        });
    }
};
