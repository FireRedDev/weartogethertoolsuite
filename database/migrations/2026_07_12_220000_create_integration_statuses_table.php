<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // z. B. "woocommerce_write"
            $table->boolean('configured')->default(false);
            $table->boolean('ok')->default(false);
            $table->text('message')->nullable();
            $table->timestamp('checked_at')->nullable();
            // Zeitpunkt des letzten Benachrichtigungsversuchs für die AKTUELLE
            // Ausfall-Episode — wird bei Wiederherstellung zurückgesetzt, damit
            // ein erneuter Ausfall wieder genau einmal meldet.
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_statuses');
    }
};
