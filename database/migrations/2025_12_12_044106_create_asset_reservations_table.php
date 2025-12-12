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
        if (Schema::hasTable('asset_reservations')) {
            // Table already exists (maybe created manually or by a previous run)
            // Just return so Laravel can mark this migration as "run" without errors.
            return;
        }

        Schema::create('asset_reservations', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('user_id');

            $table->date('reserved_from');
            $table->date('reserved_until')->nullable();

            $table->enum('status', ['active', 'fulfilled', 'cancelled'])->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('asset_id')->references('id')->on('assets')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index(['asset_id', 'reserved_from', 'reserved_until']);
            $table->index(['user_id', 'status']);
        });
    }



    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_reservations');
    }

};
