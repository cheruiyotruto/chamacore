<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30);
            $table->string('category', 40)->default('business');
            $table->text('description')->nullable();

            $table->string('interest_method', 40)->default('reducing_annuity');
            $table->unsignedBigInteger('interest_rate_bps');
            $table->string('interest_period', 20)->default('monthly');
            $table->string('accrual_mode', 20)->default('effective');
            $table->string('day_count', 20)->default('actual_365');

            $table->string('repayment_frequency', 20)->default('monthly');
            $table->unsignedTinyInteger('repayment_day')->nullable();

            $table->unsignedInteger('duration_min')->default(1);
            $table->unsignedInteger('duration_max')->default(12);
            $table->string('duration_unit', 20)->default('months');

            $table->unsignedBigInteger('min_amount')->default(0);
            $table->unsignedBigInteger('max_amount');

            $table->unsignedBigInteger('multiplier_bps')->default(30000);
            $table->unsignedBigInteger('guarantee_multiplier_bps')->default(30000);
            $table->json('qualifying_savings_product_ids')->nullable();
            $table->boolean('include_shares')->default(false);
            $table->unsignedInteger('share_weight_bps')->default(10000);

            $table->boolean('requires_guarantors')->default(true);
            $table->unsignedTinyInteger('min_guarantors')->default(1);
            $table->unsignedInteger('guarantor_coverage_bps')->default(10000);

            $table->unsignedInteger('penalty_rate_bps')->default(200);
            $table->string('penalty_period', 20)->default('daily');
            $table->unsignedInteger('penalty_grace_days')->default(3);

            $table->unsignedInteger('processing_fee_bps')->default(0);
            $table->unsignedInteger('insurance_fee_bps')->default(0);

            $table->json('allocation_policy')->nullable();
            $table->unsignedInteger('min_membership_days')->default(0);
            $table->unsignedTinyInteger('max_concurrent_loans')->default(1);
            $table->boolean('requires_no_arrears')->default(true);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['group_id', 'code']);
            $table->index(['group_id', 'is_active']);
        });

        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_product_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('steps'); // [{role, max_amount, required}]
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['group_id', 'loan_product_id']);
        });

        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_product_id')->constrained()->restrictOnDelete();
            $table->string('loan_no', 40);
            $table->string('status', 40)->default('draft');
            $table->string('category', 40)->default('business');

            $table->unsignedBigInteger('requested_amount');
            $table->unsignedBigInteger('approved_amount')->default(0);
            $table->unsignedBigInteger('principal')->default(0);
            $table->unsignedBigInteger('total_interest')->default(0);
            $table->unsignedBigInteger('total_fees')->default(0);
            $table->unsignedBigInteger('total_repayable')->default(0);
            $table->unsignedBigInteger('outstanding_principal')->default(0);
            $table->unsignedBigInteger('outstanding_interest')->default(0);
            $table->unsignedBigInteger('outstanding_fees')->default(0);
            $table->unsignedBigInteger('outstanding_penalties')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);

            $table->unsignedInteger('duration');
            $table->string('duration_unit', 20);
            $table->string('repayment_frequency', 20);
            $table->unsignedBigInteger('interest_rate_bps');
            $table->string('interest_period', 20);
            $table->string('interest_method', 40);
            $table->string('accrual_mode', 20);
            $table->string('day_count', 20);
            $table->unsignedInteger('installment_count')->default(0);

            $table->string('purpose')->nullable();
            $table->text('purpose_description')->nullable();
            $table->json('multiplier_breakdown')->nullable();
            $table->json('terms_snapshot')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('first_due_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('written_off_at')->nullable();
            $table->foreignId('restructured_from_id')->nullable()->constrained('loans')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['group_id', 'loan_no']);
            $table->index(['group_id', 'status']);
            $table->index(['group_id', 'member_id']);
            $table->index(['group_id', 'maturity_date']);
        });

        Schema::create('loan_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('installment_no');
            $table->date('due_date');
            $table->unsignedBigInteger('principal_due')->default(0);
            $table->unsignedBigInteger('interest_due')->default(0);
            $table->unsignedBigInteger('fee_due')->default(0);
            $table->unsignedBigInteger('penalty_due')->default(0);
            $table->unsignedBigInteger('total_due')->default(0);
            $table->unsignedBigInteger('principal_paid')->default(0);
            $table->unsignedBigInteger('interest_paid')->default(0);
            $table->unsignedBigInteger('fee_paid')->default(0);
            $table->unsignedBigInteger('penalty_paid')->default(0);
            $table->string('status', 20)->default('pending'); // pending, partial, paid, overdue
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'installment_no']);
            $table->index(['loan_id', 'due_date']);
            $table->index(['group_id', 'status']);
        });

        Schema::create('loan_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('step_index');
            $table->string('role_key');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30); // approved, rejected, info_requested, conditional, submitted, disbursed
            $table->text('comments')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['loan_id', 'step_index']);
        });

        Schema::create('guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guarantor_member_id')->constrained('members')->cascadeOnDelete();
            $table->unsignedBigInteger('guaranteed_amount');
            $table->string('status', 30)->default('pending'); // pending, accepted, rejected, released, called
            $table->unsignedBigInteger('capacity_at_request')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('response_note')->nullable();
            $table->timestamps();

            $table->unique(['loan_id', 'guarantor_member_id']);
            $table->index(['group_id', 'guarantor_member_id', 'status']);
        });

        Schema::create('loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('method', 30); // cash, mpesa, bank, wallet
            $table->string('reference', 100);
            $table->string('idempotency_key', 100)->nullable();
            $table->json('allocation')->nullable();
            $table->string('status', 30)->default('confirmed'); // pending, confirmed, reversed
            $table->timestamp('paid_at');
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'idempotency_key']);
            $table->index(['group_id', 'member_id', 'paid_at']);
        });

        Schema::create('loan_penalty_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('installment_id')->constrained('loan_schedules')->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('pending'); // pending, paid, waived
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['installment_id', 'period_start'], 'penalty_installment_period_unique');
            $table->index(['group_id', 'loan_id', 'status']);
        });

        Schema::create('loan_restructures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->json('old_terms');
            $table->json('new_terms');
            $table->text('reason');
            $table->date('effective_date');
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'loan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_restructures');
        Schema::dropIfExists('loan_penalty_charges');
        Schema::dropIfExists('loan_payments');
        Schema::dropIfExists('guarantors');
        Schema::dropIfExists('loan_approvals');
        Schema::dropIfExists('loan_schedules');
        Schema::dropIfExists('loans');
        Schema::dropIfExists('approval_workflows');
        Schema::dropIfExists('loan_products');
    }
};
