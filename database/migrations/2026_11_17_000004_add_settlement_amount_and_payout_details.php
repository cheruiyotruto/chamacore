<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->unsignedBigInteger('settlement_amount')->default(0)->after('amount');
        });

        // Existing collections were fully available for onward settlement.
        DB::table('bill_payments')->update(['settlement_amount' => DB::raw('amount')]);

        Schema::table('payouts', function (Blueprint $table) {
            $table->string('source', 20)->default('bill_collections')->after('destination_id');
            $table->unsignedBigInteger('net_amount')->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn(['source', 'net_amount']);
        });

        Schema::table('bill_payments', function (Blueprint $table) {
            $table->dropColumn('settlement_amount');
        });
    }
};
