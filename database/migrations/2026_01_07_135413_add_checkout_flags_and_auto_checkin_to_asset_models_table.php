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
        Schema::table('asset_models', function (Blueprint $table) {
            $table->boolean('allow_checkout_to_user')->default(true)->after('require_serial');
            $table->boolean('allow_checkout_to_asset')->default(false)->after('allow_checkout_to_user');
            $table->boolean('allow_checkout_to_location')->default(false)->after('allow_checkout_to_asset');
            $table->boolean('auto_checkin')->default(false)->after('allow_checkout_to_location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('asset_models', function (Blueprint $table) {
            $table->dropColumn(['allow_checkout_to_user', 'allow_checkout_to_asset', 'allow_checkout_to_location', 'auto_checkin']);
        });
    }
};
