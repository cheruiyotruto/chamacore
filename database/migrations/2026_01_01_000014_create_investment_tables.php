<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30);
            $table->string('type', 30)->default('fixed_term'); // fixed_term, notice, unit_trust
            $table->text('description')->nullable();
            $table->unsignedBigInteger('interest_rate_bps')->default(0);
            $table->string('interest_period', 20)->default('monthly'); // monthly, quarterly, semi_annual, annual, at_maturity
            $table->string('interest_method', 20)->default('simple'); // simple, compound
            $table->unsignedInteger('duration_months')->default(12);
            $table->unsignedBigInteger('min_amount')->default(0);
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->boolean('early_withdrawal_allowed')->default(false);
            $table->unsignedInteger('early_withdrawal_penalty_bps')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('terms')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'code']);
            $table->index(['group_id', 'type', 'is_active']);
        });

        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_product_id')->constrained()->cascadeOnDelete();
            $table->string('reference_no', 40);
            $table->unsignedBigInteger('principal');
            $table->unsignedBigInteger('interest_rate_bps');
            $table->string('interest_period', 20);
            $table->string('interest_method', 20);
            $table->unsignedInteger('duration_months');
            $table->date('start_date');
            $table->date('maturity_date');
            $table->unsignedBigInteger('expected_interest')->default(0);
            $table->unsignedBigInteger('accrued_interest')->default(0);
            $table->unsignedBigInteger('paid_interest')->default(0);
            $table->string('status', 20)->default('active'); // active, matured, withdrawn, cancelled
            $table->timestamp('last_accrued_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('terms_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'reference_no']);
            $table->index(['group_id', 'member_id', 'status']);
            $table->index(['group_id', 'status', 'maturity_date']);
        });

        Schema::create('investment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('period_no');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_date');
            $table->unsignedBigInteger('opening_balance');
            $table->unsignedBigInteger('interest_amount');
            $table->unsignedBigInteger('closing_balance');
            $table->string('status', 20)->default('scheduled'); // scheduled, accrued
            $table->timestamp('accrued_at')->nullable();
            $table->unsignedBigInteger('investment_transaction_id')->nullable();
            $table->timestamps();

            $table->unique(['investment_id', 'period_no']);
            $table->index(['group_id', 'status', 'due_date']);
        });

        Schema::create('investment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('investment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40); // deposit, interest, withdrawal, maturity, penalty, reversal
            $table->string('direction', 10); // credit, debit
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('reference', 80)->nullable();
            $table->string('description')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->date('transaction_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'idempotency_key']);
            $table->index(['group_id', 'member_id', 'transaction_date']);
            $table->index(['group_id', 'investment_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_transactions');
        Schema::dropIfExists('investment_schedules');
        Schema::dropIfExists('investments');
        Schema::dropIfExists('investment_products');
    }
};
