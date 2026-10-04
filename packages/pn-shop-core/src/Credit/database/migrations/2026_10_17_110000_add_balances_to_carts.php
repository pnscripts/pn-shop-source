<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift cards entered in a cart, and whether the customer spends their store credit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->json('gift_card_ids')->nullable();
            $table->boolean('use_store_credit')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn(['gift_card_ids', 'use_store_credit']);
        });
    }
};
