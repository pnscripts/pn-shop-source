<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A return's label, issued by the order's carrier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->string('return_label_url', 500)->nullable();
            $table->string('return_tracking_number')->nullable();
            $table->string('return_carrier')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropColumn(['return_label_url', 'return_tracking_number', 'return_carrier']);
        });
    }
};
