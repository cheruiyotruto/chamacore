<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30);
            $table->string('type', 30)->default('voluntary'); // mandatory, voluntary, emergency, shares, welfare, special
            $table->text('description')->nullable();
            $table->unsignedBigInteger('interest_rate_bps')->default(0);
            $table->string('interest_period', 20)->default('annual');
            $table->unsignedBigInteger('min_balance')->default(0);
            $table->unsignedBigInteger('contribution_amount')->nullable();
            $table->string('contribution_frequency', 20)->nullable(); // weekly, monthly, none
            $table->boolean('allows_withdrawal')->default(true);
            $table->boolean('qualifies_for_loan')->default(true);
            $table->unsignedInteger('share_weight_bps')->default(10000);
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['group_id', 'code']);
            $table->index(['group_id', 'type']);
        });

        Schema::create('savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_product_id')->constrained()->cascadeOnDelete();
            $table->string('account_no', 40);
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedBigInteger('available_balance')->default(0);
            $table->unsignedBigInteger('pledged_amount')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'account_no']);
            $table->unique(['member_id', 'savings_product_id']);
            $table->index(['group_id', 'status']);
        });

        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40); // deposit, contribution, withdrawal, interest, pledge, release, transfer_in, transfer_out
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
            $table->index(['group_id', 'type']);
        });

        Schema::create('merry_go_round_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('contribution_amount');
            $table->string('frequency', 20)->default('monthly'); // weekly, monthly
            $table->date('start_date');
            $table->date('end_date');
            $table->string('rotation_type', 20)->default('fixed'); // fixed, random
            $table->string('status', 20)->default('draft'); // draft, active, completed, cancelled
            $table->unsignedInteger('penalty_rate_bps')->default(0);
            $table->unsignedInteger('penalty_grace_days')->default(0);
            $table->boolean('payout_requires_contributions')->default(true);
            $table->unsignedInteger('min_contributions_before_payout')->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'status']);
        });

        Schema::create('merry_go_round_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merry_go_round_cycle_id')->constrained('merry_go_round_cycles')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->date('payout_date');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('total_contributed')->default(0);
            $table->unsignedBigInteger('total_received')->default(0);
            $table->timestamps();

            $table->unique(['merry_go_round_cycle_id', 'member_id']);
            $table->unique(['merry_go_round_cycle_id', 'position']);
        });

        Schema::create('merry_go_round_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merry_go_round_cycle_id')->constrained('merry_go_round_cycles')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('merry_go_round_participants')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('due_date');
            $table->timestamp('paid_at')->nullable();
            $table->string('status', 20)->default('pending'); // pending, paid, late, missed
            $table->unsignedBigInteger('penalty_amount')->default(0);
            $table->unsignedBigInteger('savings_transaction_id')->nullable();
            $table->string('reference', 80)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['merry_go_round_cycle_id', 'member_id', 'due_date'], 'mgr_contribution_unique');
            $table->index(['group_id', 'status']);
        });

        Schema::create('merry_go_round_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merry_go_round_cycle_id')->constrained('merry_go_round_cycles')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('merry_go_round_participants')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('scheduled_date');
            $table->timestamp('paid_at')->nullable();
            $table->string('status', 20)->default('pending'); // pending, paid, cancelled
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->string('reference', 80)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['merry_go_round_cycle_id', 'participant_id']);
            $table->index(['group_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merry_go_round_payouts');
        Schema::dropIfExists('merry_go_round_contributions');
        Schema::dropIfExists('merry_go_round_participants');
        Schema::dropIfExists('merry_go_round_cycles');
        Schema::dropIfExists('savings_transactions');
        Schema::dropIfExists('savings_accounts');
        Schema::dropIfExists('savings_products');
    }
};
