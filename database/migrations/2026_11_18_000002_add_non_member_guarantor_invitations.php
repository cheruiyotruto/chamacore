<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the constraints that assume every guarantor is already a member
        // before making the member link optional.
        Schema::table('guarantors', function (Blueprint $table) {
            $table->dropForeign(['guarantor_member_id']);
            $table->dropUnique(['loan_id', 'guarantor_member_id']);
        });

        Schema::table('guarantors', function (Blueprint $table) {
            $table->foreignId('guarantor_member_id')->nullable()->change();
            $table->foreignId('invitation_id')->nullable()->after('guarantor_member_id');
            $table->string('invitee_email')->nullable()->after('invitation_id');
            $table->string('invitee_phone', 30)->nullable()->after('invitee_email');
            $table->unsignedBigInteger('required_savings_amount')->nullable()->after('guaranteed_amount');
            $table->timestamp('invitation_sent_at')->nullable()->after('requested_at');
            $table->timestamp('funded_at')->nullable()->after('invitation_sent_at');
        });

        Schema::table('guarantors', function (Blueprint $table) {
            $table->foreign('guarantor_member_id')
                ->references('id')->on('members')
                ->cascadeOnDelete();
            $table->foreign('invitation_id')
                ->references('id')->on('member_invitations')
                ->nullOnDelete();
            $table->unique(['loan_id', 'guarantor_member_id']);
            $table->unique(['loan_id', 'invitation_id']);
        });
    }

    public function down(): void
    {
        // Non-member guarantees cannot survive a rollback to the old schema.
        DB::table('guarantors')->whereNull('guarantor_member_id')->delete();

        Schema::table('guarantors', function (Blueprint $table) {
            $table->dropForeign(['invitation_id']);
            $table->dropForeign(['guarantor_member_id']);
            $table->dropUnique(['loan_id', 'invitation_id']);
            $table->dropUnique(['loan_id', 'guarantor_member_id']);
        });

        Schema::table('guarantors', function (Blueprint $table) {
            $table->foreignId('guarantor_member_id')->nullable(false)->change();
            $table->dropColumn([
                'invitation_id',
                'invitee_email',
                'invitee_phone',
                'required_savings_amount',
                'invitation_sent_at',
                'funded_at',
            ]);
        });

        Schema::table('guarantors', function (Blueprint $table) {
            $table->foreign('guarantor_member_id')
                ->references('id')->on('members')
                ->cascadeOnDelete();
            $table->unique(['loan_id', 'guarantor_member_id']);
        });
    }
};
