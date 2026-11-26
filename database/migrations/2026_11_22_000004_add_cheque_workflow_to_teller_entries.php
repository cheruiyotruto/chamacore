<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teller_entries', function (Blueprint $table) {
            $table->string('status', 20)->default('posted')->after('method');
            $table->json('cheque_details')->nullable()->after('narration');
            $table->json('payload')->nullable()->after('cheque_details');
            $table->timestamp('cleared_at')->nullable()->after('payload');
            $table->timestamp('bounced_at')->nullable()->after('cleared_at');
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete()->after('bounced_at');
            $table->foreignId('bounced_by')->nullable()->constrained('users')->nullOnDelete()->after('cleared_by');
            $table->text('clearance_notes')->nullable()->after('bounced_by');

            $table->index(['group_id', 'status', 'method']);
        });
    }

    public function down(): void
    {
        Schema::table('teller_entries', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'status', 'method']);
            $table->dropConstrainedForeignId('bounced_by');
            $table->dropConstrainedForeignId('cleared_by');
            $table->dropColumn([
                'status',
                'cheque_details',
                'payload',
                'cleared_at',
                'bounced_at',
                'clearance_notes',
            ]);
        });
    }
};
