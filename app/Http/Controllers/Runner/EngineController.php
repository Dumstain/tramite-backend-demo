<?php

namespace App\Http\Controllers\Runner;

use App\Http\Controllers\Controller;
use App\Models\ProcedureBlueprint;
use App\Models\ProcedureInstance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http; // Para los Webhooks

class EngineController extends Controller
{
    // --- START y CURRENT se quedan igual que antes, los omito por brevedad ---
    // (Asegúrate de mantener los métodos start() y currentStep() que ya funcionaban)

    public function start(Request $request) {
        // ... (Tu código existente de start) ...
        // Te lo repongo rápido por si acaso:
        $request->validate(['blueprint_id' => 'required|exists:blueprints,id']);
        $blueprint = ProcedureBlueprint::find($request->blueprint_id);

        // Buscar inicio
        $nodes = collect($blueprint->schema['nodes']);
        $startNode = $nodes->firstWhere('type', 'start');

        if (!$startNode) return response()->json(['error' => 'Sin nodo de inicio'], 500);

        // Crear instancia
        $instance = ProcedureInstance::create([
            'blueprint_id' => $blueprint->id,
            'user_identifier' => 'demo_user',
            'status' => 'IN_PROGRESS',
            'current_step_id' => $startNode['next'], // Salto inmediato al primero
            'state_store' => [],
        ]);

        // Auto-avanzar si el primero es lógico o webhook (Recursividad simple)
        $this->processAutoSteps($instance);

        return response()->json([
            'message' => 'Trámite iniciado',
            'instance_id' => $instance->id,
        ]);
    }


public function currentStep($instanceId)
    {
        $instance = ProcedureInstance::findOrFail($instanceId);
        $blueprint = $instance->blueprint;

        // Buscamos el nodo donde se quedó el trámite (puede ser el nodo Fin)
        $nodes = collect($blueprint->schema['nodes']);
        $currentNode = $nodes->firstWhere('id', $instance->current_step_id);

        // CASO DE RESPALDO:
        // Si por alguna razón el trámite se marcó como completado pero perdió el puntero al nodo,
        // entonces sí mandamos la respuesta genérica.
        if (!$currentNode && ($instance->status === 'COMPLETED' || $instance->status === 'REJECTED')) {
            return response()->json(['type' => 'completed', 'status' => $instance->status]);
        }

        if (!$currentNode) {
            return response()->json(['error' => 'Paso perdido o configuración corrupta'], 500);
        }

        // RESPUESTA COMPLETA (Incluye estado + configuración del nodo + variables)
        return response()->json([
            'instance_id' => $instance->id,
            'status' => $instance->status,
            'step_structure' => $currentNode, // Aquí viene tu mensaje con {{variables}}
            'current_data' => $instance->state_store // Aquí viene la URL del webhook
        ]);
    }

    /**
     * EL CORAZÓN DE LA LÓGICA
     */
    public function submitStep(Request $request, $instanceId)
    {
        $instance = ProcedureInstance::findOrFail($instanceId);
        $blueprint = $instance->blueprint;
        $nodes = collect($blueprint->schema['nodes']);

        // 1. Identificar Nodo Actual
        $currentNode = $nodes->firstWhere('id', $instance->current_step_id);
        if (!$currentNode) return response()->json(['error' => 'Error de sincronización'], 500);

        // 2. VALIDACIÓN ESTRICTA (Si es formulario)
        if (($currentNode['type'] ?? '') === 'form') {
            $rules = [];
            $customMessages = [];

            // Iterar campos configurados en el JSON
            foreach ($currentNode['props']['fields'] ?? [] as $field) {
                // Usamos 'bind' (nombre variable) si existe, sino el 'label' sanitizado
                $key = $field['bind'] ?? $field['id']; // El Frontend debe mandar este mismo Key

                if (isset($field['required']) && $field['required'] === true) {
                    $rules["data.$key"] = 'required';
                    $customMessages["data.$key.required"] = "El campo '{$field['label']}' es obligatorio.";
                }
            }

            $validator = Validator::make($request->all(), $rules, $customMessages);
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
        }

        // 3. GUARDAR DATOS
        $incomingData = $request->input('data', []);
        $instance->state_store = array_merge($instance->state_store ?? [], $incomingData);
        $instance->save();

        // 4. CALCULAR SIGUIENTE PASO
        $nextStepId = $this->calculateNextStep($currentNode, $instance->state_store);

        // 5. ACTUALIZAR PUNTERO
        $instance->current_step_id = $nextStepId;
        $instance->save();

        // 6. PROCESAR PASOS AUTOMÁTICOS (Webhooks, Lógica consecutiva)
        // Si el siguiente paso es un Webhook o Lógica, no esperamos al usuario, lo ejecutamos YA.
        $this->processAutoSteps($instance);

        return response()->json([
            'status' => 'success',
            'action' => $instance->status === 'IN_PROGRESS' ? 'reload' : 'finish'
        ]);
    }

/**
     * Motor Recursivo para Webhooks y Logic Gates
     */
    private function processAutoSteps($instance) {
        $limit = 0;

        while ($limit < 10 && $instance->status === 'IN_PROGRESS') {
            // Refrescamos el blueprint para asegurar consistencia
            $blueprint = $instance->blueprint;
            $nodes = collect($blueprint->schema['nodes']);
            $node = $nodes->firstWhere('id', $instance->current_step_id);

            // Si no encuentra el nodo, salimos
            if (!$node) break;

            $type = $node['type'] ?? '';

            // --- 1. SI ES REVISIÓN: DETENERSE INMEDIATAMENTE ---
            if ($type === 'review' || $type === 'reviewNode') {
                $instance->status = 'REVIEW_PENDING';
                $instance->save();
                return; // Rompemos el ciclo para esperar al humano
            }

            // --- 2. SI ES WEBHOOK: EJECUTAR Y GUARDAR DATA ---
            if ($type === 'webhook' || $type === 'webhookNode') {
                $url = $node['props']['url'] ?? null;
                $method = $node['props']['method'] ?? 'POST';

                if ($url) {
                    try {
                        if ($method === 'GET') {
                            $response = Http::get($url, $instance->state_store);
                        } else {
                            $response = Http::post($url, $instance->state_store);
                        }

                        // LÓGICA DE GUARDADO DE RESPUESTA
                        if ($response && $response->successful()) {
                            $responseData = $response->json();
                            $store = $instance->state_store;

                            // A. Desempaquetar si es lista (Formato n8n: [{data}])
                            // Si es un array indexado (0, 1...), tomamos el primero
                            if (is_array($responseData) && array_is_list($responseData) && !empty($responseData)) {
                                $responseData = $responseData[0];
                            }

                            // B. Fusionar variables
                            if (is_array($responseData)) {
                                $store = array_merge($store, $responseData);
                            } else {
                                // Si es texto plano, guardarlo como raw
                                $store['webhook_response'] = $responseData;
                            }

                            $instance->state_store = $store;
                            $instance->save();
                        }
                    } catch (\Exception $e) {
                        // Log error silencioso para no romper el flujo visual
                    }
                }

                // Avanzar al siguiente paso
                $instance->current_step_id = $node['next'] ?? null;
                $instance->save();
            }
            // --- 3. SI ES LÓGICA (IF/ELSE) ---
            elseif ($type === 'logic_gate' || $type === 'logicNode') {
                $next = $this->calculateNextStep($node, $instance->state_store);
                $instance->current_step_id = $next;
                $instance->save();
            }
            // --- 4. SI ES FIN ---
            elseif ($type === 'end' || $type === 'endNode') {
                $isRejected = ($node['props']['type'] ?? '') === 'rejected';
                $instance->status = $isRejected ? 'REJECTED' : 'COMPLETED';
                $instance->save();
                return;
            }
            // --- 5. SI ES INICIO O FORMULARIO ---
            else {
                if ($type === 'start' || $type === 'startNode') {
                     // El inicio es automático, avanzamos una vez
                     $instance->current_step_id = $node['next'] ?? null;
                     $instance->save();
                } else {
                    // Es un formulario, nos detenemos para esperar al usuario
                    return;
                }
            }
            $limit++;
        }

        // Si salimos del while y no hay siguiente paso, terminamos por seguridad
        if (!$instance->current_step_id && $instance->status === 'IN_PROGRESS') {
             $instance->status = 'COMPLETED';
             $instance->save();
        }
    }

    private function calculateNextStep($node, $store) {
        if (($node['type'] ?? '') === 'logic_gate') {
            foreach ($node['props']['rules'] ?? [] as $rule) {
                $val = $store[$rule['variable']] ?? null;
                $target = $rule['value'];

                // Lógica simple
                $match = false;
                if ($rule['operator'] == '==' && $val == $target) $match = true;
                if ($rule['operator'] == '>' && $val > $target) $match = true;
                if ($rule['operator'] == '<' && $val < $target) $match = true;

                if ($match) return $rule['target'];
            }
        }
        return $node['next'] ?? null;
    }
}
