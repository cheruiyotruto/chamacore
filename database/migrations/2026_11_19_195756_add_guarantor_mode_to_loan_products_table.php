<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loan_products', function (Blueprint $table) {
            $table->string('guarantor_mode', 20)->nullable()->after('requires_guarantors');
        });

        DB::table('loan_products')
            ->where('requires_guarantors', true)
            ->update(['guarantor_mode' => 'required']);

        DB::table('loan_products')
            ->where(function ($q) {
                $q->where('requires_guarantors', false)->orWhereNull('requires_guarantors');
            })
            ->update(['guarantor_mode' => 'optional']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table) {
            $table->dropColumn('guarantor_mode');
        });
    }
};
