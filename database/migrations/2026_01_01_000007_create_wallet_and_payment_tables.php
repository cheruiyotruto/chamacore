<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance')->default(0);
            $table->unsignedBigInteger('available_balance')->default(0);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['group_id', 'member_id']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('direction', 10); // credit, debit
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            $table->string('status', 20)->default('completed'); // pending, completed, failed, reversed
            $table->string('provider', 30)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->string('reference', 100);
            $table->string('idempotency_key', 120)->nullable();
            $table->string('description')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'idempotency_key']);
            $table->index(['group_id', 'member_id', 'created_at']);
        });

        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 40); // wallet_topup, loan_repayment, savings_deposit, subscription
            $table->unsignedBigInteger('amount');
            $table->string('provider', 30)->default('mpesa');
            $table->string('phone', 30)->nullable();
            $table->string('status', 30)->default('pending'); // pending, processing, succeeded, failed, cancelled
            $table->string('provider_reference', 100)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->json('payload')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'member_id', 'status']);
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 255)->unique();
            $table->string('platform', 20); // android, ios
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('payment_intents');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
