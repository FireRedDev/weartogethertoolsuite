<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_onboardings', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('neu'); // neu | in_bearbeitung | angelegt | abgeschlossen
            $table->string('source')->default('webhook'); // webhook | manuell
            $table->string('school_name');
            $table->string('org_type')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_preference')->nullable();
            $table->string('contact_role')->nullable();
            $table->json('address')->nullable();
            $table->unsignedInteger('student_count')->nullable();
            $table->unsignedInteger('expected_orders')->nullable();
            $table->string('delivery_type')->default('collective'); // collective | ondemand | list
            $table->json('products')->nullable(); // Konfigurator-Zustand je Produkt
            $table->json('print_areas')->nullable(); // ["Frontprint","Backprint"]
            $table->text('class_list')->nullable();
            $table->date('window_start')->nullable();
            $table->date('window_end')->nullable();
            $table->json('logo_files')->nullable(); // URLs aus FluentForms
            $table->text('logo_notes')->nullable();
            $table->text('design_notes')->nullable();
            $table->text('notes')->nullable();
            $table->json('raw_entry')->nullable(); // kompletter Webhook-Payload
            $table->unsignedBigInteger('woo_category_id')->nullable();
            $table->unsignedBigInteger('pods_post_id')->nullable();
            $table->json('woo_product_ids')->nullable();
            $table->json('printify_product_ids')->nullable();
            $table->json('provision_log')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_onboardings');
    }
};
