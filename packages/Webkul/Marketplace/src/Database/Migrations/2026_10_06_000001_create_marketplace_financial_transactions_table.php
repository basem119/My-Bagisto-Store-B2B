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
        Schema::create('marketplace_financial_transactions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('event_type', 64);
            $table->string('currency', 3);
            $table->string('idempotency_key', 191)->unique('marketplace_financial_transactions_idempotency_unique');
            $table->string('source_type', 64);
            $table->unsignedInteger('source_id');
            $table->unsignedInteger('order_id')->nullable();
            $table->unsignedInteger('order_item_id')->nullable();
            $table->unsignedInteger('vendor_id')->nullable();
            $table->unsignedInteger('company_id')->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('order_id', 'mkt_fin_tx_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('order_item_id', 'mkt_fin_tx_item_fk')->references('id')->on('order_items')->restrictOnDelete();
            $table->foreign('vendor_id', 'mkt_fin_tx_vendor_fk')->references('id')->on('marketplace_vendors')->restrictOnDelete();
            $table->foreign('company_id', 'mkt_fin_tx_company_fk')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('created_by', 'mkt_fin_tx_creator_fk')->references('id')->on('admins')->nullOnDelete();

            $table->index(['source_type', 'source_id', 'event_type'], 'mkt_fin_tx_source_event_idx');
            $table->index(['event_type', 'created_at'], 'mkt_fin_tx_event_created_idx');
            $table->index('order_id', 'mkt_fin_tx_order_idx');
            $table->index('order_item_id', 'mkt_fin_tx_item_idx');
            $table->index('vendor_id', 'mkt_fin_tx_vendor_idx');
            $table->index('company_id', 'mkt_fin_tx_company_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE marketplace_financial_transactions ADD CONSTRAINT mkt_fin_tx_currency_check CHECK (currency = '%s')",
                FinancialCurrency::EGP->value
            ));

            DB::statement('ALTER TABLE marketplace_financial_transactions ADD CONSTRAINT mkt_fin_tx_item_order_check CHECK (order_item_id IS NULL OR order_id IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_financial_transactions');
    }
};
