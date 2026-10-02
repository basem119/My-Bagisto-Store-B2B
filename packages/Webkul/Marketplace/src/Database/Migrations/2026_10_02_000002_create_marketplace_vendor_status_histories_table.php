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
        Schema::create('marketplace_vendor_status_histories', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('vendor_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->unsignedInteger('changed_by_admin_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('marketplace_vendors')->onDelete('cascade');
            $table->foreign('changed_by_admin_id')->references('id')->on('admins')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('marketplace_vendor_status_histories');
    }
};
