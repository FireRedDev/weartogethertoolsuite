<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_onboardings', function (Blueprint $table) {
            // Einmal hochgeladenes Beitragsbild wiederverwenden, statt bei jedem
            // Anlageversuch eine Dublette in der Mediathek zu erzeugen.
            $table->unsignedBigInteger('featured_media_id')->nullable();

            // Der ECHTE Kategorie-Slug aus dem Shop. Die Bestelladresse für den
            // QR-Code wurde bisher aus dem Schulnamen abgeleitet — Umlaute
            // schreibt WordPress aber anders um, und ein falscher QR-Code fällt
            // erst auf dem gedruckten Aushang auf.
            $table->string('woo_category_slug')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('school_onboardings', function (Blueprint $table) {
            $table->dropColumn(['featured_media_id', 'woo_category_slug']);
        });
    }
};
