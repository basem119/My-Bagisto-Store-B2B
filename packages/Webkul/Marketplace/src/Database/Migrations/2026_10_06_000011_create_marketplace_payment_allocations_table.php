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
        Schema::create('marketplace_payment_allocations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('payment_id');
            $table->unsignedInteger('installment_id');
            $table->string('currency', 3);
            $table->decimal('amount', 18, 4)->unsigned();
            $table->timestamps();

            $table->foreign('payment_id', 'mkt_allocation_payment_fk')->references('id')->on('marketplace_payments')->restrictOnDelete();
            $table->foreign('installment_id', 'mkt_allocation_installment_fk')->references('id')->on('marketplace_payment_installments')->restrictOnDelete();
            $table->index('installment_id', 'mkt_allocation_installment_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE marketplace_payment_allocations ADD CONSTRAINT mkt_allocation_currency_check CHECK (currency = '%s')",
                FinancialCurrency::EGP->value
            ));
            DB::statement('ALTER TABLE marketplace_payment_allocations ADD CONSTRAINT mkt_allocation_amount_check CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_payment_allocations');
    }
};
