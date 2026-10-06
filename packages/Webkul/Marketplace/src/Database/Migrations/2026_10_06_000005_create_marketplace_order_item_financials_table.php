<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Marketplace\Enums\FinancialCurrency;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_order_item_financials', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('order_financial_id');
            $table->unsignedInteger('order_item_id')->unique();
            $table->unsignedInteger('vendor_id');
            $table->unsignedInteger('vendor_product_id');
            $table->unsignedInteger('product_id')->nullable();
            $table->string('vendor_name');
            $table->string('vendor_sku')->nullable();
            $table->decimal('quantity', 12, 4)->unsigned();
            $table->decimal('vendor_price', 18, 4)->unsigned();
            $table->decimal('discounted_product_basis', 18, 4)->unsigned();
            $table->decimal('commission_rate', 8, 4)->unsigned();
            $table->decimal('commission_amount', 18, 4)->unsigned();
            $table->decimal('vendor_net_amount', 18, 4)->unsigned();
            $table->string('currency', 3);
            $table->timestamps();

            $table->foreign('order_financial_id', 'mkt_order_item_fin_header_fk')->references('id')->on('marketplace_order_financials')->restrictOnDelete();
            $table->foreign('order_item_id', 'mkt_order_item_fin_item_fk')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('vendor_id', 'mkt_order_item_fin_vendor_fk')->references('id')->on('marketplace_vendors')->restrictOnDelete();
            $table->index(['vendor_id', 'order_financial_id'], 'mkt_order_item_fin_vendor_header_idx');
            $table->index('vendor_product_id', 'mkt_order_item_fin_offer_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE marketplace_order_item_financials ADD CONSTRAINT mkt_order_item_fin_currency_check CHECK (currency = '%s')",
                FinancialCurrency::EGP->value
            ));
            DB::statement('ALTER TABLE marketplace_order_item_financials ADD CONSTRAINT mkt_order_item_fin_rate_check CHECK (commission_rate <= 100)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_item_financials');
    }
};