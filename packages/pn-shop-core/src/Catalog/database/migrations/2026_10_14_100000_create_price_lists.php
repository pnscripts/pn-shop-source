<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Price lists: prices per customer group (or for everyone), currency and dates, with
 * quantity tiers (min_quantity). A customer pays the lowest price that applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // Null: every customer, guests included (quantity discounts for everyone).
            $table->foreignId('customer_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('currency', 3);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'currency', 'customer_group_id']);
        });

        Schema::create('price_list_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_quantity')->default(1);
            $table->bigInteger('price');
            $table->timestamps();

            $table->unique(['price_list_id', 'product_variant_id', 'min_quantity'], 'price_list_entries_list_variant_qty_unique');
            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_entries');
        Schema::dropIfExists('price_lists');
    }
};
