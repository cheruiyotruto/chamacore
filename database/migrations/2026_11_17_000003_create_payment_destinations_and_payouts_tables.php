<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_destinations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('type', 30);
            $table->string('bank_name', 120)->nullable();
            $table->string('account_name', 150)->nullable();
            $table->string('account_number', 100)->nullable();
            $table->string('shortcode', 30)->nullable();
            $table->string('paybill_number', 30)->nullable();
            $table->string('till_number', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->char('currency', 3)->default('KES');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'is_active']);
            $table->index(['group_id', 'member_id']);
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('payment_destinations')->cascadeOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('charge_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->json('charge_breakdown')->nullable();
            $table->string('borne_by', 20)->nullable();
            $table->char('currency', 3)->default('KES');
            $table->string('provider', 30);
            $table->string('status', 20)->default('pending');
            $table->string('provider_reference', 120)->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'status']);
            $table->index(['group_id', 'destination_id']);
            $table->index(['bill_id']);
            $table->unique(['group_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('payment_destinations');
    }
};
