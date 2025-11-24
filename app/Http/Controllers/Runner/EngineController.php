<?php

namespace App\Http\Controllers\Runner;

use App\Http\Controllers\Controller;
use App\Models\ProcedureBlueprint;
use App\Models\ProcedureInstance;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EngineController extends Controller
{
    /**
     * INICIA EL TRÁMITE
     * Simula el click en "Comenzar Trámite".
     * Busca el nodo 'start' en el JSON y mueve el cursor al siguiente paso inmediatamente.
     */
    public function start(Request $request)
    {
        $request->validate(['blueprint_id' => 'required|exists:blueprints,id']);

        $blueprint = ProcedureBlueprint::find($request->blueprint_id);

        // 1. Buscamos el nodo de inicio en el Array del Schema
        // Usamos colecciones de Laravel para filtrar el JSON fácilmente
        $nodes = collect($blueprint->schema['nodes']);
        $startNode = $nodes->firstWhere('type', 'start');

        if (!$startNode) {
            return response()->json(['error' => 'Este trámite está mal configurado (no tiene inicio)'], 500);
        }

        // 2. Determinamos cual es el PRIMER paso real (Ej: el formulario)
        // La lógica es: El Start Node solo apunta al siguiente.
        $firstStepId = $startNode['next'] ?? null;

        if (!$firstStepId) {
            return response()->json(['error' => 'El inicio no lleva a ningun lado'], 500);
        }

        // 3. Creamos la instancia en Base de Datos
        $instance = ProcedureInstance::create([
            'blueprint_id' => $blueprint->id,
            'user_identifier' => 'invitado_demo@test.com', // En prod, esto viene del Auth::user()
            'status' => 'IN_PROGRESS',
            'current_step_id' => $firstStepId, // Aquí empieza la magia
            'state_store' => [], // Iniciamos memoria vacía
        ]);

        return response()->json([
            'message' => 'Trámite iniciado exitosamente',
            'instance_id' => $instance->id,
            'next_endpoint' => "/api/engine/{$instance->id}/current" // HATEOAS: Le decimos al front a donde ir
        ], 201);
    }

    /**
     * DAME EL PASO ACTUAL
     * El frontend llama esto cada vez que carga la pantalla.
     * Retorna el JSON parcial del componente que se debe pintar.
     */
    public function currentStep($instanceId)
    {
        $instance = ProcedureInstance::findOrFail($instanceId);

        // Verificamos si ya terminó
        if ($instance->status === 'COMPLETED') {
            return response()->json(['type' => 'completed', 'message' => 'Este trámite ya finalizó']);
        }

        // Recuperamos el Blueprint completo
        $blueprint = $instance->blueprint;

        // Buscamos el nodo activo en el JSON
        $currentNode = collect($blueprint->schema['nodes'])
            ->firstWhere('id', $instance->current_step_id);

        if (!$currentNode) {
            return response()->json(['error' => 'Paso perdido en el espacio (Error de integridad JSON)'], 500);
        }

        // INTERPOLACIÓN DE VARIABLES (Opcional para Demo Avanzada)
        // Si el título dice "Hola {{nombre}}", aquí lo reemplazamos usando el state_store
        // Nota: Esto se hace convirtiendo a string, reemplazando y volviendo a json, o manualmente.
        // Para la demo mantendremos la estructura raw, pero añadimos el state_store para que el front lo use.

        return response()->json([
            'instance_id' => $instance->id,
            'step_structure' => $currentNode, // El Frontend dibuja esto
            'current_data' => $instance->state_store // Valores previos si existen
        ]);
    }

    /**
     * PROCESAR PASO (SUBMIT)
     * Recibe datos del form, actualiza variables y calcula el siguiente paso.
     */
public function submitStep(Request $request, $instanceId)
{
    $instance = ProcedureInstance::findOrFail($instanceId);
    $blueprint = $instance->blueprint;

    // 1. Obtener nodo actual
    $nodes = collect($blueprint->schema['nodes']);
    $currentNode = $nodes->firstWhere('id', $instance->current_step_id);

    if (!$currentNode) {
        return response()->json(['error' => 'Paso actual no existe en la definición'], 500);
    }

    // --- A. VALIDACIÓN DINÁMICA DE DATOS ---
    // Si el nodo es tipo 'form', validamos que los campos requeridos vengan en el request.
    if (($currentNode['type'] ?? '') === 'form') {
        $rules = [];
        // Iteramos los campos definidos en el JSON del trámite
        foreach ($currentNode['props']['fields'] ?? [] as $field) {
            // Ejemplo de regla: si el JSON dice required: true, agregamos la regla 'required'
            if (isset($field['required']) && $field['required'] === true) {
                // Usamos el 'bind' como nombre del campo (ej: solicitante_nombre)
                $fieldName = $field['bind'] ?? $field['id'];
                $rules["data.$fieldName"] = 'required';
            }
            // Aquí podrías agregar más reglas tipo regex, min, max, numeric mapeando desde el JSON
        }

        // Ejecutamos validación
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
    }

    // --- B. PERSISTENCIA DE DATOS ---
    $incomingData = $request->input('data', []);
    // IMPORTANTE: Merge recursivo para no borrar datos de pasos anteriores
    $newStore = array_merge($instance->state_store ?? [], $incomingData);
    $instance->state_store = $newStore;

    // --- C. MOTOR DE NAVEGACIÓN (LOGIC GATES) ---
    // Determinamos cual es el siguiente paso ID
    $nextStepId = $this->calculateNextStep($currentNode, $newStore);

    if (!$nextStepId) {
        $instance->status = 'COMPLETED';
        $instance->save();
        return response()->json(['action' => 'finish', 'message' => 'Trámite finalizado']);
    }

    // Actualizamos puntero
    $instance->current_step_id = $nextStepId;

    // Verificamos si llegamos al fin
    $nextNode = $nodes->firstWhere('id', $nextStepId);
    if (isset($nextNode['type']) && $nextNode['type'] === 'end') {
        $instance->status = 'COMPLETED';
    }

    $instance->save();

    return response()->json([
        'status' => 'success',
        'next_step_id' => $nextStepId,
        'action' => 'reload'
    ]);
}

/**
 * Función auxiliar para decidir a dónde ir.
 * Soporta saltos directos y compuertas lógicas.
 */
private function calculateNextStep($currentNode, $stateStore)
{
    // 1. Si es una Compuerta Lógica (Logic Gate)
    if (($currentNode['type'] ?? '') === 'logic_gate') {
        $rules = $currentNode['props']['rules'] ?? [];

        foreach ($rules as $rule) {
            // Ejemplo regla: { "variable": "edad", "operator": ">", "value": 18, "target": "paso_adulto" }
            $variableValue = $stateStore[$rule['variable']] ?? null;
            $targetValue = $rule['value'];

            // Evaluar condición (Simplificado para demo)
            $matched = false;
            switch ($rule['operator']) {
                case '>': $matched = $variableValue > $targetValue; break;
                case '<': $matched = $variableValue < $targetValue; break;
                case '==': $matched = $variableValue == $targetValue; break;
                // Agregar más operadores...
            }

            if ($matched) {
                return $rule['target']; // SALTO CONDICIONAL
            }
        }
        // Si ninguna regla cumple, ir al fallback (default)
        return $currentNode['next'] ?? null;
    }

    // 2. Si es paso normal, solo retornar 'next'
    return $currentNode['next'] ?? null;
}
}
