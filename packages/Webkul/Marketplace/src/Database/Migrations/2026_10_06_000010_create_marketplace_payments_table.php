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
        Schema::create('marketplace_payments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id');
            $table->string('currency', 3);
            $table->decimal('amount', 18, 4)->unsigned();
            $table->decimal('allocated_amount', 18, 4)->unsigned()->default(0);
            $table->date('payment_date');
            $table->string('reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('recorded_by_admin_id')->nullable();
            $table->timestamps();

            $table->foreign('company_id', 'mkt_payment_company_fk')->references('id')->on('customers')->restrictOnDelete();
            $table->foreign('recorded_by_admin_id', 'mkt_payment_admin_fk')->references('id')->on('admins')->nullOnDelete();
            $table->index('company_id', 'mkt_payment_company_idx');
            $table->index('reference', 'mkt_payment_reference_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE marketplace_payments ADD CONSTRAINT mkt_payment_currency_check CHECK (currency = '%s')",
                FinancialCurrency::EGP->value
            ));
            DB::statement('ALTER TABLE marketplace_payments ADD CONSTRAINT mkt_payment_amount_check CHECK (amount > 0)');
            DB::statement('ALTER TABLE marketplace_payments ADD CONSTRAINT mkt_payment_allocated_not_exceed_check CHECK (allocated_amount <= amount)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_payments');
    }
};
