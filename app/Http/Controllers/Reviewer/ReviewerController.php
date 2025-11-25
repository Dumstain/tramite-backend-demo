<?php

namespace App\Http\Controllers\Reviewer;

use App\Http\Controllers\Controller;
use App\Models\ProcedureInstance;
use Illuminate\Http\Request;

class ReviewerController extends Controller
{
    /**
     * BANDEJA DE ENTRADA
     * Busca trámites pausados (REVIEW_PENDING)
     */
    public function inbox()
    {
        // CORRECCIÓN: Buscamos explícitamente el estado que pone el Engine
        $instances = ProcedureInstance::with('blueprint')
            ->where('status', 'REVIEW_PENDING')
            ->latest()
            ->get();

        return response()->json($instances);
    }

    /**
     * DETALLE DEL TRÁMITE
     */
    public function show($instanceId)
    {
        $instance = ProcedureInstance::with('blueprint')->findOrFail($instanceId);

        // Recuperamos la configuración del paso actual para saber qué instrucciones dar
        // (Aunque el state_store ya tiene los datos, a veces necesitamos la config del nodo)
        $currentNode = collect($instance->blueprint->schema['nodes'])
            ->firstWhere('id', $instance->current_step_id);

        return response()->json([
            'instance' => $instance,
            'node_config' => $currentNode
        ]);
    }

    /**
     * TOMA DE DECISIÓN
     */
    public function decide(Request $request, $instanceId)
    {
        $request->validate([
            'verdict' => 'required|in:approve,reject',
            'comments' => 'nullable|string'
        ]);

        $instance = ProcedureInstance::findOrFail($instanceId);
        $blueprint = $instance->blueprint;

        // 1. Buscar el nodo de revisión en el JSON
        $node = collect($blueprint->schema['nodes'])
            ->firstWhere('id', $instance->current_step_id);

        // 2. Obtener a dónde ir (Next Step ID)
        // El JSON guarda: props -> actions -> approve -> next_step_id
        $actionMap = $node['props']['actions'] ?? [];
        $nextStepId = $actionMap[$request->verdict]['next_step_id'] ?? null;

        // 3. Guardar log de la revisión
        $store = $instance->state_store;
        $store['revision_history'][] = [
            'date' => now()->toIso8601String(),
            'verdict' => $request->verdict,
            'comments' => $request->comments,
            'reviewer' => 'DemoUser'
        ];
        $instance->state_store = $store;

        // 4. Mover el trámite
        if ($nextStepId) {
            $instance->current_step_id = $nextStepId;

            // Reactivamos el flujo (quitamos el estado pendiente)
            $instance->status = 'IN_PROGRESS';
            $instance->save();

            // Opcional: Verificar si el siguiente paso es Fin o Automático inmediatamente
            // (Para esta demo, dejemos que el usuario refresque su pantalla y el Engine procese)
        } else {
            // Si no hay paso siguiente configurado, terminamos o rechazamos globalmente
            $instance->status = $request->verdict === 'approve' ? 'COMPLETED' : 'REJECTED';
            $instance->save();
        }

        return response()->json(['message' => 'Revisión procesada']);
    }
}
