<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_company_payment_policies', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('company_id')->unique();
            $table->unsignedInteger('maximum_term_days');
            $table->boolean('installments_allowed')->default(false);
            $table->unsignedInteger('maximum_installments')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('company_id', 'mkt_payment_policy_company_fk')->references('id')->on('customers')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_company_payment_policies');
    }
};
