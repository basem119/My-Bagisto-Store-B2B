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
        Schema::create('marketplace_order_financials', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('order_id')->unique();
            $table->unsignedInteger('company_id');
            $table->string('currency', 3);
            $table->decimal('product_subtotal', 18, 4)->unsigned();
            $table->decimal('product_discount_amount', 18, 4)->unsigned();
            $table->decimal('discounted_product_subtotal', 18, 4)->unsigned();
            $table->decimal('company_fee_rate', 8, 4)->unsigned();
            $table->decimal('company_fee_amount', 18, 4)->unsigned();
            $table->decimal('delivery_amount', 18, 4)->unsigned();
            $table->decimal('delivery_discount_amount', 18, 4)->unsigned();
            $table->decimal('product_tax_amount', 18, 4)->unsigned();
            $table->decimal('delivery_tax_amount', 18, 4)->unsigned();
            $table->decimal('tax_amount', 18, 4)->unsigned();
            $table->decimal('customer_financial_total', 18, 4)->unsigned();
            $table->timestamps();

            $table->foreign('order_id', 'mkt_order_financial_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('company_id', 'mkt_order_financial_company_fk')->references('id')->on('customers')->restrictOnDelete();
            $table->index('company_id', 'mkt_order_financial_company_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE marketplace_order_financials ADD CONSTRAINT mkt_order_financial_currency_check CHECK (currency = '%s')",
                FinancialCurrency::EGP->value
            ));
            DB::statement('ALTER TABLE marketplace_order_financials ADD CONSTRAINT mkt_order_financial_fee_rate_check CHECK (company_fee_rate <= 100)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_order_financials');
    }
};