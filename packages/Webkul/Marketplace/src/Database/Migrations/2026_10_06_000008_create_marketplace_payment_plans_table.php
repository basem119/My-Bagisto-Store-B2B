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
        Schema::create('marketplace_payment_plans', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('order_id')->unique();
            $table->unsignedInteger('company_id');
            $table->unsignedInteger('requested_term_days');
            $table->unsignedInteger('approved_term_days');
            $table->date('due_date');
            $table->string('currency', 3);
            $table->decimal('total_amount', 18, 4)->unsigned();
            $table->string('status', 20);
            $table->timestamps();

            $table->foreign('order_id', 'mkt_payment_plan_order_fk')->references('id')->on('orders')->restrictOnDelete();
            $table->foreign('company_id', 'mkt_payment_plan_company_fk')->references('id')->on('customers')->restrictOnDelete();
            $table->index('company_id', 'mkt_payment_plan_company_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE marketplace_payment_plans ADD CONSTRAINT mkt_payment_plan_currency_check CHECK (currency = '%s')",
                FinancialCurrency::EGP->value
            ));
            DB::statement("ALTER TABLE marketplace_payment_plans ADD CONSTRAINT mkt_payment_plan_status_check CHECK (status IN ('active', 'completed', 'cancelled'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_payment_plans');
    }
};
