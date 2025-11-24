<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    // 1. EL PLANO (Blueprint): Define cómo es el trámite
    Schema::create('blueprints', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('name');
        $table->string('description')->nullable();
        $table->jsonb('schema')->default('{}'); // Aquí vive el flujo y componentes
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    // 2. LA INSTANCIA: Es un trámite siendo realizado por un usuario
    Schema::create('procedure_instances', function (Blueprint $table) {
        $table->uuid('id')->primary();

        // Relación
        $table->foreignUuid('blueprint_id')->constrained('blueprints')->cascadeOnDelete();

        // Datos del Usuario (Simulados por ahora)
        $table->string('user_identifier')->index(); // email, curp, etc.

        // Estado y Control
        $table->string('status')->default('DRAFT'); // DRAFT, IN_PROGRESS, REVIEW, APPROVED, REJECTED
        $table->string('current_step_id')->nullable(); // En qué paso del JSON está

        // EL CEREBRO: Almacén de variables (Inputs + Respuestas Webhook)
        $table->jsonb('state_store')->default('{}');

        $table->timestamps();
    });

    // 3. AUDITORIA: Para ver quién movió qué
    Schema::create('audit_logs', function (Blueprint $table) {
        $table->id();
        $table->uuid('instance_id');
        $table->string('action'); // 'submitted', 'webhook_success', 'rejected'
        $table->text('details')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('procedure_instances');
        Schema::dropIfExists('blueprints');
    }
};
