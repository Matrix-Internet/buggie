<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the current run of failed payments began, so a past-due workspace keeps its
 * plan for a grace period and not for ever. Cleared by the next successful payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->timestamp('payment_failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('payment_failed_at');
        });
    }
};
