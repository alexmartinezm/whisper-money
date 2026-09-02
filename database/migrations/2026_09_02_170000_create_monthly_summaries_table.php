<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One frozen snapshot per user, space and month.
     *
     * The payload is deliberately immutable. The analysis is generated on
     * demand, but once it exists the figures behind it never move — so "August
     * was this" keeps meaning the same thing next time it is opened, and the
     * paid-for AI paragraph is not re-bought on every visit. Recategorising an
     * old transaction afterwards does not rewrite a month already closed.
     */
    public function up(): void
    {
        Schema::create('monthly_summaries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('space_id')->constrained('spaces')->cascadeOnDelete();
            // The month reported on, as YYYY-MM.
            $table->char('period', 7);
            $table->json('payload');
            $table->text('ai_analysis')->nullable();
            $table->timestamp('ai_generated_at')->nullable();
            // Whether every source had reported in when the snapshot was frozen.
            // An incomplete month can be regenerated; a complete one is final.
            $table->boolean('complete')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'space_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_summaries');
    }
};
