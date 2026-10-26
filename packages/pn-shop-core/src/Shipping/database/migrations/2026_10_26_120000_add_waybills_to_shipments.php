<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waybills issued by the carrier for a shipment (since 1.11): where to download them, the
 * privately stored PDF, and the cash-on-delivery amount the courier collects (minor units
 * of the order's currency).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('label_url', 2048)->nullable()->after('tracking_url');
            $table->string('label_path')->nullable()->after('label_url');
            $table->bigInteger('cash_on_delivery')->nullable()->after('label_path');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn(['label_url', 'label_path', 'cash_on_delivery']);
        });
    }
};
