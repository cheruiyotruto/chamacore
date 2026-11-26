<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type', 30)->default('regular');
            $table->dateTime('scheduled_at');
            $table->string('location')->nullable();
            $table->text('agenda')->nullable();
            $table->longText('minutes')->nullable();
            $table->string('status', 30)->default('scheduled');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'scheduled_at']);
        });

        Schema::create('meeting_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('present'); // present, absent, late, excused
            $table->unsignedBigInteger('fine_amount')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'member_id']);
        });

        Schema::create('meeting_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('savings_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30)->default('savings'); // savings, contribution, share, welfare, fine
            $table->unsignedBigInteger('amount');
            $table->string('reference', 80)->nullable();
            $table->unsignedBigInteger('savings_transaction_id')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'meeting_id']);
        });

        Schema::create('fines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('loan_id')->nullable()->index();
            $table->string('type', 50); // late_contribution, absenteeism, late_loan_repayment, custom
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->string('reason')->nullable();
            $table->string('status', 20)->default('pending'); // pending, paid, waived, partially_paid
            $table->date('due_date')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fines');
        Schema::dropIfExists('meeting_collections');
        Schema::dropIfExists('meeting_attendances');
        Schema::dropIfExists('meetings');
    }
};
