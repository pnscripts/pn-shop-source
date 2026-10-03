<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B options per customer group: a minimum order value, and whether its customers see
 * prices with or without tax (null: as the shop's catalog prices are entered).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_groups', function (Blueprint $table) {
            $table->bigInteger('min_order_total')->nullable()->after('is_default');
            $table->boolean('prices_include_tax')->nullable()->after('min_order_total');
        });
    }

    public function down(): void
    {
        Schema::table('customer_groups', function (Blueprint $table) {
            $table->dropColumn(['min_order_total', 'prices_include_tax']);
        });
    }
};
