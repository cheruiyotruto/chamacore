<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->boolean('employer_finance_required')->default(false);
            $table->string('employer_finance_status', 30)->nullable();
            $table->timestamp('employer_finance_form_sent_at')->nullable();
            $table->timestamp('employer_finance_confirmed_at')->nullable();
            $table->string('employer_finance_officer_name')->nullable();
            $table->string('employer_finance_reference')->nullable();
            $table->text('employer_finance_notes')->nullable();

            $table->index(['group_id', 'employer_finance_status']);
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropIndex(['group_id', 'employer_finance_status']);
            $table->dropColumn([
                'employer_finance_required',
                'employer_finance_status',
                'employer_finance_form_sent_at',
                'employer_finance_confirmed_at',
                'employer_finance_officer_name',
                'employer_finance_reference',
                'employer_finance_notes',
            ]);
        });
    }
};
