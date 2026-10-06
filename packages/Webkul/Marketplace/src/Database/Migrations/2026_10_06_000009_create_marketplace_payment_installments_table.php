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
        Schema::create('marketplace_payment_installments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('payment_plan_id');
            $table->unsignedInteger('installment_number');
            $table->string('currency', 3);
            $table->decimal('amount', 18, 4)->unsigned();
            $table->decimal('paid_amount', 18, 4)->unsigned()->default(0);
            $table->date('due_date');
            $table->string('status', 20);
            $table->timestamps();

            $table->foreign('payment_plan_id', 'mkt_installment_plan_fk')->references('id')->on('marketplace_payment_plans')->restrictOnDelete();
            $table->unique(['payment_plan_id', 'installment_number'], 'mkt_installment_plan_number_unique');
            $table->index(['status', 'due_date'], 'mkt_installment_status_due_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE marketplace_payment_installments ADD CONSTRAINT mkt_installment_currency_check CHECK (currency = '%s')",
                FinancialCurrency::EGP->value
            ));
            DB::statement("ALTER TABLE marketplace_payment_installments ADD CONSTRAINT mkt_installment_status_check CHECK (status IN ('pending', 'partially_paid', 'paid', 'overdue', 'cancelled'))");
            DB::statement('ALTER TABLE marketplace_payment_installments ADD CONSTRAINT mkt_installment_amount_check CHECK (amount > 0)');
            DB::statement('ALTER TABLE marketplace_payment_installments ADD CONSTRAINT mkt_installment_paid_not_exceed_check CHECK (paid_amount <= amount)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_payment_installments');
    }
};
