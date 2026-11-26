<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('float_purchases', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 40)->unique();
            $table->string('network', 20)->default('mpesa');
            $table->string('phone', 30);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('charge_amount')->default(0);
            $table->unsignedBigInteger('total_amount')->default(0);
            $table->unsignedBigInteger('group_fee_amount')->default(0);
            $table->char('currency', 3)->default('KES');
            $table->string('status', 20)->default('pending');
            $table->string('provider', 30)->default('manual');
            $table->string('provider_reference', 120)->nullable();
            $table->string('idempotency_key', 120)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('fulfilled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'status']);
            $table->index(['group_id', 'member_id']);
            $table->unique(['group_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('float_purchases');
    }
};
