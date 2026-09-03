<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_goals', function (Blueprint $table) {
            $table->id();

            // Ein Eintrag je Schuljahr, Schlüssel wie SchoolYear::key() („2026-27").
            $table->string('school_year')->unique();

            // Der Zielumsatz ist kein Filter, sondern eine Vorgabe: einmal
            // eingetragen bleibt er stehen, bis ihn jemand ändert.
            $table->decimal('target_revenue', 12, 2)->nullable();

            // Umsätze außerhalb des Webshops. `manual_revenue` ist bereits
            // erzielt und zählt zum Ist; `manual_forecast` ist zusätzlich
            // erwartet und zählt nur in die Hochrechnung.
            $table->decimal('manual_revenue', 12, 2)->default(0);
            $table->decimal('manual_forecast', 12, 2)->default(0);
            $table->string('manual_note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_goals');
    }
};
