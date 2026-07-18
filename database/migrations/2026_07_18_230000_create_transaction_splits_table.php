<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_splits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount');
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['transaction_id', 'position']);
            $table->index(['category_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_splits');
    }
};
