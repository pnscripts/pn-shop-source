<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an order line's units are held: one row per stock location, with how many of
 * them have shipped. Orders placed before 1.4 get theirs at the default location the
 * first time they are needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_item_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('quantity_shipped')->default(0);
            $table->timestamps();

            $table->unique(['order_item_id', 'stock_location_id'], 'order_item_allocations_item_location_unique');
            $table->index('stock_location_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_allocations');
    }
};
