<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permission_groups', function (Blueprint $table) {
            $table->boolean('only_self_checkout')->default(false);
            $table->boolean('only_edit_placeholders')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('permission_groups', function (Blueprint $table) {
            $table->dropColumn(['only_self_checkout', 'only_edit_placeholders']);
        });
    }
};
