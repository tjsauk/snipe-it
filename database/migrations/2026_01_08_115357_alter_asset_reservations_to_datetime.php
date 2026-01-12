<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_reservations', function (Blueprint $table) {
            $table->dateTime('reserved_from')->change();
            $table->dateTime('reserved_until')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('asset_reservations', function (Blueprint $table) {
            $table->date('reserved_from')->change();
            $table->date('reserved_until')->nullable()->change();
        });
    }
};

