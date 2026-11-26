<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_charges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('category', 40)->default('*');
            $table->string('basis', 10)->default('fixed');
            $table->unsignedBigInteger('amount')->default(0);
            $table->unsignedInteger('rate_bps')->default(0);
            $table->unsignedBigInteger('min_amount')->default(0);
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('applies_to', 20)->default('all');
            $table->json('member_categories')->nullable();
            $table->string('income_account_code', 20)->default('4050');
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'category', 'is_active']);
            $table->index(['group_id', 'is_active', 'sort_order']);
        });

        Schema::create('transaction_charge_member', function (Blueprint $table) {
            $table->foreignId('transaction_charge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->primary(['transaction_charge_id', 'member_id']);
        });

        Schema::table('teller_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('charge_amount')->default(0);
            $table->unsignedBigInteger('waived_charge_amount')->default(0);
            $table->json('charge_breakdown')->nullable();
            $table->foreignId('charge_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teller_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('charge_journal_entry_id');
            $table->dropColumn(['charge_amount', 'waived_charge_amount', 'charge_breakdown']);
        });

        Schema::dropIfExists('transaction_charge_member');
        Schema::dropIfExists('transaction_charges');
    }
};
