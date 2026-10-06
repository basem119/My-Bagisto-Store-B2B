<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_vendors', function (Blueprint $table) {
            $table->decimal('commission_rate', 8, 4)->unsigned()->nullable();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE marketplace_vendors ADD CONSTRAINT marketplace_vendors_commission_rate_check CHECK (commission_rate IS NULL OR commission_rate <= 100)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE marketplace_vendors DROP CHECK marketplace_vendors_commission_rate_check');
        }

        Schema::table('marketplace_vendors', function (Blueprint $table) {
            $table->dropColumn('commission_rate');
        });
    }
};