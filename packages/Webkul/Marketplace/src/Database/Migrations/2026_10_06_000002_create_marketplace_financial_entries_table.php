<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Marketplace\Enums\FinancialAccountCode;
use Webkul\Marketplace\Enums\FinancialCurrency;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_financial_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('financial_transaction_id');
            $table->string('account_code', 32);
            $table->decimal('debit_amount', 18, 4)->unsigned()->default(0);
            $table->decimal('credit_amount', 18, 4)->unsigned()->default(0);
            $table->string('currency', 3);
            $table->unsignedInteger('vendor_id')->nullable();
            $table->unsignedInteger('company_id')->nullable();
            $table->unsignedInteger('order_id')->nullable();
            $table->unsignedInteger('order_item_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('financial_transaction_id', 'mkt_fin_entry_tx_fk')->references('id')->on('marketplace_financial_transactions')->restrictOnDelete();
            $table->foreign('vendor_id', 'mkt_fin_entry_vendor_fk')->references('id')->on('marketplace_vendors')->restrictOnDelete();
            $table->foreign('company_id', 'mkt_fin_entry_company_fk')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('order_id', 'mkt_fin_entry_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('order_item_id', 'mkt_fin_entry_item_fk')->references('id')->on('order_items')->restrictOnDelete();

            $table->index(['account_code', 'created_at'], 'mkt_fin_entry_account_created_idx');
            $table->index(['vendor_id', 'created_at'], 'mkt_fin_entry_vendor_created_idx');
            $table->index(['company_id', 'created_at'], 'mkt_fin_entry_company_created_idx');
            $table->index('order_id', 'mkt_fin_entry_order_idx');
            $table->index('order_item_id', 'mkt_fin_entry_item_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            $accounts = implode(', ', array_map(
                fn (string $account) => DB::getPdo()->quote($account),
                FinancialAccountCode::values()
            ));

            DB::statement("ALTER TABLE marketplace_financial_entries ADD CONSTRAINT mkt_fin_entry_account_check CHECK (account_code IN ({$accounts}))");
            DB::statement(sprintf(
                "ALTER TABLE marketplace_financial_entries ADD CONSTRAINT mkt_fin_entry_currency_check CHECK (currency = '%s')",
                FinancialCurrency::EGP->value
            ));
            DB::statement('ALTER TABLE marketplace_financial_entries ADD CONSTRAINT mkt_fin_entry_one_sided_check CHECK ((debit_amount > 0 AND credit_amount = 0) OR (credit_amount > 0 AND debit_amount = 0))');
            DB::statement('ALTER TABLE marketplace_financial_entries ADD CONSTRAINT mkt_fin_entry_item_order_check CHECK (order_item_id IS NULL OR order_id IS NOT NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_financial_entries');
    }
};
