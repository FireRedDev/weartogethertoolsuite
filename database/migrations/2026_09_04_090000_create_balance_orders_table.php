<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die Auftragsbilanz — eine Zeile je Auftrag, so wie bisher eine Zeile je
 * Auftrag in der Excel stand.
 *
 * Beträge liegen als DECIMAL vor, nicht als FLOAT: Bei Geld darf sich kein
 * Rundungsfehler einschleichen, und über 800.000 € Gesamtumsatz summieren sich
 * auch kleine Fehler sichtbar auf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('balance_orders', function (Blueprint $table) {
            $table->id();

            // Die laufende Auftragsnummer der Excel („348"). Bewusst Text:
            // die Altdaten führen führende Nullen („000") und Unternummern.
            $table->string('number')->nullable()->index();
            $table->string('school_name');

            // Startjahr des Schuljahres — 2025 heißt „2025/26".
            $table->unsignedSmallInteger('school_year')->index();
            $table->date('ordered_on');
            // Altdaten haben kein Datum; für sie steht hier das Schuljahresende.
            $table->boolean('date_is_estimate')->default(false);

            // Verknüpfung mit der Shop-Welt. Beides darf leer bleiben: ein
            // händisch erfasster Auftrag muss keinen Antrag und keine Kategorie
            // haben (Barverkauf, Verein, Altbestand).
            $table->foreignId('school_onboarding_id')->nullable()
                ->constrained('school_onboardings')->nullOnDelete();
            $table->unsignedBigInteger('woo_category_id')->nullable()->index();

            // 'collective' | 'ondemand' | null (unbekannt, v. a. Altdaten)
            $table->string('delivery_type')->nullable();

            // 'shop'   — die Online-Einnahmen werden aus dem Webshop gefüllt
            // 'manual' — sie werden von Hand gepflegt
            $table->string('online_source')->default('manual');

            $table->decimal('revenue_online', 12, 2)->default(0);
            $table->decimal('revenue_cash', 12, 2)->default(0);
            // Der Wert, der in der Excel stand — bleibt als Vergleichswert
            // stehen, damit eine Abweichung zum Shop auffällt.
            $table->decimal('revenue_online_excel', 12, 2)->nullable();

            $table->decimal('commission', 12, 2)->default(0);
            $table->decimal('expenses', 12, 2)->default(0);
            $table->decimal('vat', 12, 2)->default(0);

            // Stückzahlen je Produktart (Schlüssel aus config('auftragsbilanz.product_types'))
            $table->json('products')->nullable();
            $table->unsignedInteger('individual')->default(0);

            $table->text('note')->nullable();
            // 'excel' (aus der Altdatei übernommen) | 'manual' (im Tool angelegt)
            $table->string('source')->default('manual');

            $table->timestamps();

            $table->index(['school_year', 'ordered_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('balance_orders');
    }
};
