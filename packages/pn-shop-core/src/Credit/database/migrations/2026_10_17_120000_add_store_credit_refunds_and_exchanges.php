<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a refund's money goes (the original payment, store credit, or an exchange), the
 * balance it went to, and the order a return was exchanged for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('destination', 20)->default('original');
            $table->string('credit_reference', 64)->nullable();
        });

        Schema::table('return_requests', function (Blueprint $table) {
            $table->foreignId('exchange_order_id')->nullable()->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exchange_order_id');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropColumn(['destination', 'credit_reference']);
        });
    }
};
