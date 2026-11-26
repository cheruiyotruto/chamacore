<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 40);
            $table->integer('offset_days')->default(-3);
            $table->date('specific_date')->nullable();
            $table->string('audience', 20)->default('auto');
            $table->json('member_ids')->nullable();
            $table->string('template_key', 80)->default('admin_reminder');
            $table->string('subject', 190)->nullable();
            $table->text('body')->nullable();
            $table->json('channels')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['group_id', 'is_active']);
            $table->index(['type', 'is_active']);
        });

        Schema::create('reminder_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reminder_rule_id')->constrained('reminder_rules')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->date('sent_on');
            $table->timestamp('created_at')->nullable();

            $table->unique(['reminder_rule_id', 'member_id', 'sent_on'], 'reminder_delivery_unique');
            $table->index(['group_id', 'sent_on']);
        });

        $now = now();

        DB::table('reminder_rules')->insert([
            [
                'group_id' => null, 'name' => 'Merry-go-round contribution', 'type' => 'mgr_contribution',
                'offset_days' => -3, 'audience' => 'auto', 'template_key' => 'mgr_contribution_reminder',
                'channels' => json_encode(['in_app', 'sms']), 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'group_id' => null, 'name' => 'Merry-go-round payout', 'type' => 'mgr_payout',
                'offset_days' => -1, 'audience' => 'auto', 'template_key' => 'mgr_payout_reminder',
                'channels' => json_encode(['in_app', 'sms']), 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'group_id' => null, 'name' => 'Loan repayment', 'type' => 'loan_repayment',
                'offset_days' => -3, 'audience' => 'auto', 'template_key' => 'loan_due_reminder',
                'channels' => json_encode(['in_app', 'sms', 'push']), 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'group_id' => null, 'name' => 'Bill due', 'type' => 'bill_due',
                'offset_days' => -3, 'audience' => 'auto', 'template_key' => 'bill_due_reminder',
                'channels' => json_encode(['in_app', 'sms']), 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_deliveries');
        Schema::dropIfExists('reminder_rules');
    }
};
