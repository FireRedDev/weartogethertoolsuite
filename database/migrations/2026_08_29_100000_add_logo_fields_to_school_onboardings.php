<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_onboardings', function (Blueprint $table) {
            // Im Tool hochgeladene Logos je Druck. *_path = lokale Kopie (Vorschau/
            // Download im Tool), *_url = öffentlich erreichbare Adresse aus der
            // WordPress-Mediathek (Printify/Dynamic Mockups laden sie selbst herunter).
            $table->string('logo_front_path')->nullable();
            $table->string('logo_front_url')->nullable();
            $table->string('logo_back_path')->nullable();
            $table->string('logo_back_url')->nullable();

            // Welche Drucke es gibt. NULL = aus den Formularwünschen (print_areas)
            // abgeleitet, sobald im Konfigurator gespeichert wird explizit gesetzt.
            $table->boolean('print_front')->nullable();
            $table->boolean('print_back')->nullable();

            // Platzierung/Größe je Druck (Schlüssel aus config('schoolshop.logo_positions'/'logo_sizes'))
            $table->string('logo_front_position')->nullable();
            $table->string('logo_front_size')->nullable();
            $table->string('logo_back_position')->nullable();
            $table->string('logo_back_size')->nullable();
        });

        // Die Mockup-Platzierung wird jetzt aus dem Frontprint übernommen —
        // ein zweites, davon abweichendes Feld wäre nur eine Fehlerquelle.
        Schema::table('school_onboardings', function (Blueprint $table) {
            $table->dropColumn('mockup_placement');
        });
    }

    public function down(): void
    {
        Schema::table('school_onboardings', function (Blueprint $table) {
            $table->string('mockup_placement')->default('brust_links');
            $table->dropColumn([
                'logo_front_path', 'logo_front_url', 'logo_back_path', 'logo_back_url',
                'print_front', 'print_back',
                'logo_front_position', 'logo_front_size', 'logo_back_position', 'logo_back_size',
            ]);
        });
    }
};
