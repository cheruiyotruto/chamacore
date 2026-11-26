<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inviter_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('note', 500)->nullable();
            $table->json('channels')->nullable();
            $table->json('delivery')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'status']);
            $table->index(['inviter_member_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_invitations');
    }
};
