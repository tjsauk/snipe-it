<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Is this a placeholder-type category?
            $table->boolean('is_placeholder')->default(false);

            // What can assets in this category be checked out TO?
            $table->boolean('allow_checkout_to_user')->default(true);
            $table->boolean('allow_checkout_to_asset')->default(true);
            $table->boolean('allow_checkout_to_location')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn([
                'is_placeholder',
                'allow_checkout_to_user',
                'allow_checkout_to_asset',
                'allow_checkout_to_location',
            ]);
        });
    }
};
