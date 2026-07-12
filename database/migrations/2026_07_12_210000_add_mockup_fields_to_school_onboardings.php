<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_onboardings', function (Blueprint $table) {
            $table->boolean('mockups_enabled')->default(false);      // optionaler Schritt, Standard AUS
            $table->string('mockup_placement')->default('brust_links');
            $table->json('mockup_images')->nullable();               // key => bereits erzeugte Bild-URLs (verhindert doppelte Credits)
        });
    }

    public function down(): void
    {
        Schema::table('school_onboardings', function (Blueprint $table) {
            $table->dropColumn(['mockups_enabled', 'mockup_placement', 'mockup_images']);
        });
    }
};
