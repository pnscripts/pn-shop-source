<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pnshop_prior_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            // The price a guest paid from this moment on, in minor units of the default currency.
            $table->bigInteger('amount');
            $table->string('currency', 3);
            // Whether it was a sale price (a reduction from the regular price).
            $table->boolean('on_sale');
            $table->timestamp('effective_from');
            $table->index(['product_variant_id', 'effective_from'], 'pnshop_prior_price_variant_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pnshop_prior_price_history');
    }
};
