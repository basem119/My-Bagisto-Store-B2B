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
        Schema::create('marketplace_invoice_financials', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('invoice_id')->unique();
            $table->unsignedInteger('order_financial_id');
            $table->string('currency', 3);
            $table->decimal('company_fee_amount', 18, 4)->unsigned();
            $table->timestamps();

            $table->foreign('invoice_id', 'mkt_invoice_fin_invoice_fk')->references('id')->on('invoices')->restrictOnDelete();
            $table->foreign('order_financial_id', 'mkt_invoice_fin_header_fk')->references('id')->on('marketplace_order_financials')->restrictOnDelete();
            $table->index('order_financial_id', 'mkt_invoice_fin_header_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE marketplace_invoice_financials ADD CONSTRAINT mkt_invoice_fin_currency_check CHECK (currency = '%s')",
                FinancialCurrency::EGP->value
            ));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_invoice_financials');
    }
};