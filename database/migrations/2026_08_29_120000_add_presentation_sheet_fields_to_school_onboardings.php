<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_onboardings', function (Blueprint $table) {
            // Hochgeladene Mockups für das Präsentationsblatt
            $table->string('sheet_back_path')->nullable();    // Rückenansicht, oben rechts
            $table->string('sheet_front_path')->nullable();   // Vorderansicht, unten links
            $table->string('sheet_detail_path')->nullable();  // optionales eigenes Bild für den Kreis

            // Bildausschnitt je Mockup (Placeit-Vorlagen sind unterschiedlich
            // angeschnitten, deshalb pro Bild einstellbar)
            foreach (['back', 'front'] as $slot) {
                $table->float("sheet_{$slot}_focus_x")->default(0.5);
                $table->float("sheet_{$slot}_focus_y")->default(0.5);
                $table->float("sheet_{$slot}_zoom")->default(1.0);
            }

            // Ausschnitt für den Detailkreis, falls er aus der Vorderansicht kommt
            $table->float('sheet_detail_focus_x')->default(0.5);
            $table->float('sheet_detail_focus_y')->default(0.42);
            $table->float('sheet_detail_zoom')->default(3.0);

            $table->string('sheet_first_name')->nullable();   // Vorname im „Print your name!"-Kreis
            $table->string('sheet_shop_url')->nullable();     // überschreibt die aus dem Namen abgeleitete Adresse
            $table->json('sheet_products')->nullable();       // überschreibt die Produktzeilen
        });
    }

    public function down(): void
    {
        Schema::table('school_onboardings', function (Blueprint $table) {
            $table->dropColumn([
                'sheet_back_path', 'sheet_front_path', 'sheet_detail_path',
                'sheet_back_focus_x', 'sheet_back_focus_y', 'sheet_back_zoom',
                'sheet_front_focus_x', 'sheet_front_focus_y', 'sheet_front_zoom',
                'sheet_detail_focus_x', 'sheet_detail_focus_y', 'sheet_detail_zoom',
                'sheet_first_name', 'sheet_shop_url', 'sheet_products',
            ]);
        });
    }
};
