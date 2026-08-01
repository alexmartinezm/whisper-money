<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_series', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('space_id')->index();
            $table->string('match_field', 20);
            $table->string('merchant_key', 191);
            $table->string('display_name');
            $table->string('cadence', 20);
            $table->unsignedSmallInteger('interval_days');
            $table->bigInteger('expected_amount');
            $table->boolean('amount_is_variable')->default(false);
            $table->char('currency_code', 3);
            $table->foreignUuid('account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 10);
            $table->date('first_occurred_on');
            $table->date('last_occurred_on');
            $table->date('next_expected_on');
            $table->unsignedInteger('occurrence_count');
            $table->string('status', 20);
            $table->string('user_state', 20)->default('detected');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['user_id', 'space_id', 'match_field', 'merchant_key', 'direction', 'currency_code'],
                'recurring_series_identity_unique',
            );
            $table->index(['user_id', 'status', 'next_expected_on'], 'recurring_series_upcoming');
        });

        Schema::create('recurring_series_transaction', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recurring_series_id')->constrained('recurring_series')->cascadeOnDelete();
            $table->foreignUuid('transaction_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_series_transaction');
        Schema::dropIfExists('recurring_series');
    }
};
