<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Locations get an address (for choosing the nearest one and for pickup), an order, and
 * whether their stock is sold online.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->boolean('sells_online')->default(true)->after('is_active');
            $table->unsignedInteger('position')->default(0)->after('sells_online');
            $table->string('address')->nullable()->after('position');
            $table->string('city')->nullable()->after('address');
            $table->string('postcode', 32)->nullable()->after('city');
            $table->char('country_code', 2)->nullable()->after('postcode');
        });
    }

    public function down(): void
    {
        Schema::table('stock_locations', function (Blueprint $table) {
            $table->dropColumn(['sells_online', 'position', 'address', 'city', 'postcode', 'country_code']);
        });
    }
};
