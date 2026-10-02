<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('marketplace_vendor_products', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('vendor_id');
            $table->unsignedInteger('product_id');
            $table->string('vendor_sku')->nullable();
            $table->decimal('price', 12, 4)->nullable();
            $table->integer('quantity')->unsigned()->default(0);
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('marketplace_vendors')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');

            $table->unique(['vendor_id', 'product_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_vendor_products');
    }
};
