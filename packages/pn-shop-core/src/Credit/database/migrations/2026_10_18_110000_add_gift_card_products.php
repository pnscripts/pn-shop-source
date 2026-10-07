<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift cards sold as products: a product flagged as a gift card (its variants are the card
 * values); per cart line and order line, who receives each card; and on each issued card,
 * the order line that bought it and the buyer's message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_gift_card')->default(false)->after('type');
        });

        // One entry per card in the line's quantity: {email, name, message}; missing entries go to the buyer.
        Schema::table('cart_lines', function (Blueprint $table) {
            $table->json('gift_card_recipients')->nullable()->after('quantity');
        });

        // Null for other products; a list (possibly empty) on gift card lines.
        Schema::table('order_items', function (Blueprint $table) {
            $table->json('gift_card_recipients')->nullable()->after('discount_amount');
        });

        Schema::table('gift_cards', function (Blueprint $table) {
            $table->foreignId('order_item_id')->nullable()->after('user_id')->constrained('order_items')->nullOnDelete();
            $table->text('message')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_item_id');
            $table->dropColumn('message');
        });

        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('gift_card_recipients'));
        Schema::table('cart_lines', fn (Blueprint $table) => $table->dropColumn('gift_card_recipients'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('is_gift_card'));
    }
};
