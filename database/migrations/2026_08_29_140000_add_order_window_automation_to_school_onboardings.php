<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_onboardings', function (Blueprint $table) {
            // Sammelbestellfenster nach Ablauf automatisch verlängern
            $table->boolean('auto_extend')->default(true);
            $table->unsignedSmallInteger('auto_extend_days')->default(7);
            $table->timestamp('auto_extended_at')->nullable();   // einmalig je Fenster
            $table->date('auto_extend_from')->nullable();        // ursprüngliches Ende, für die Anzeige

            // Wann wurden zuletzt Auftragsdokumente für diese Schule erzeugt?
            $table->timestamp('documents_exported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('school_onboardings', function (Blueprint $table) {
            $table->dropColumn([
                'auto_extend', 'auto_extend_days', 'auto_extended_at', 'auto_extend_from',
                'documents_exported_at',
            ]);
        });
    }
};
