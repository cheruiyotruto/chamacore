<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merry_go_round_contributions', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_intent_id')->nullable()->after('reference');
            $table->string('method', 20)->default('cash')->after('payment_intent_id');
            $table->unsignedBigInteger('charge_amount')->default(0)->after('method');
            $table->json('charge_breakdown')->nullable()->after('charge_amount');
            $table->string('borne_by', 20)->nullable()->after('charge_breakdown');
            $table->unsignedBigInteger('settlement_amount')->default(0)->after('borne_by');

            $table->foreign('payment_intent_id')
                ->references('id')->on('payment_intents')
                ->nullOnDelete();
        });

        \Illuminate\Support\Facades\DB::table('merry_go_round_contributions')
            ->whereNull('settlement_amount')
            ->update(['settlement_amount' => \Illuminate\Support\Facades\DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('merry_go_round_contributions', function (Blueprint $table) {
            $table->dropForeign(['payment_intent_id']);
            $table->dropColumn([
                'payment_intent_id', 'method', 'charge_amount',
                'charge_breakdown', 'borne_by', 'settlement_amount',
            ]);
        });
    }
};
