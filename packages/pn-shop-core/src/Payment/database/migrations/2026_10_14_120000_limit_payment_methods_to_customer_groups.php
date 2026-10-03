<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment method can be offered to some customer groups only (e.g. "pay on invoice" for
 * wholesale customers). Null: every customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->json('customer_group_ids')->nullable()->after('countries');
        });
    }

    public function down(): void
    {
        Schema::table('payment_methods', function (Blueprint $table) {
            $table->dropColumn('customer_group_ids');
        });
    }
};
