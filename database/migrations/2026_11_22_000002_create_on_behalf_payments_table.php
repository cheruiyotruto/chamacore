<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('on_behalf_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payer_member_id')->constrained('members');
            $table->foreignId('member_id')->constrained('members');
            $table->string('type', 30);
            $table->unsignedBigInteger('amount');
            $table->string('method', 20);
            $table->string('status', 20)->default('completed');
            $table->string('reference')->nullable();
            $table->nullableMorphs('payable');
            $table->foreignId('payment_intent_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'created_at']);
            $table->index(['payer_member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('on_behalf_payments');
    }
};
