<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('method', 10);              // GET | POST
            $table->string('ip')->nullable();
            $table->string('content_type')->nullable();
            $table->boolean('secret_ok')->default(false);
            $table->string('outcome')->nullable();     // z. B. "onboarding #12 angelegt", "Secret falsch"
            $table->text('body_snippet')->nullable();  // gekürzter Rohbody, für die Diagnose
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
