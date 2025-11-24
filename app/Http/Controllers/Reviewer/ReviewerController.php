<?php

namespace App\Http\Controllers\Reviewer;

use App\Http\Controllers\Controller;
use App\Models\ProcedureInstance;
use Illuminate\Http\Request;

class ReviewerController extends Controller
{
    /**
     * BANDEJA DE ENTRADA
     * Devuelve todos los trámites que están esperando acción de un humano.
     */
    public function inbox()
    {
        // Traemos las instancias activas y su blueprint
        $instances = ProcedureInstance::with('blueprint')
            ->where('status', 'IN_PROGRESS')
            ->latest()
            ->get();

        // Filtramos en memoria: Solo queremos las que están en un nodo tipo 'review'
        $pendingReviews = $instances->filter(function ($instance) {
            $blueprint = $instance->blueprint;
            // Buscamos el nodo actual en el JSON
            $node = collect($blueprint->schema['nodes'])
                ->firstWhere('id', $instance->current_step_id);

            return ($node['type'] ?? '') === 'review';
        })->values(); // Re-indexar array

        return response()->json($pendingReviews);
    }

    /**
     * VER DETALLE DEL TRÁMITE
     */
    public function show($instanceId)
    {
        $instance = ProcedureInstance::findOrFail($instanceId);

        return response()->json([
            'id' => $instance->id,
            'tramite' => $instance->blueprint->name,
            'fecha_inicio' => $instance->created_at,
            'datos' => $instance->state_store, // Aquí el revisor ve lo que llenó el usuario
            'nodo_actual' => $instance->current_step_id
        ]);
    }

    /**
     * TOMAR DECISIÓN (APROBAR / RECHAZAR)
     */
    public function decide(Request $request, $instanceId)
    {
        // 1. Validar input del revisor
        $request->validate([
            'verdict' => 'required|in:approve,reject', // Solo aceptamos estas dos palabras
            'comments' => 'nullable|string'
        ]);

        $instance = ProcedureInstance::findOrFail($instanceId);
        $blueprint = $instance->blueprint;

        // 2. Obtener config del paso actual
        $currentNode = collect($blueprint->schema['nodes'])
            ->firstWhere('id', $instance->current_step_id);

        if (($currentNode['type'] ?? '') !== 'review') {
            return response()->json(['error' => 'Este trámite no está en revisión'], 400);
        }

        // 3. Buscar a dónde ir según la decisión
        // En el JSON esperamos: props -> actions -> approve -> next_step_id
        $actionConfig = $currentNode['props']['actions'][$request->verdict] ?? null;

        if (!$actionConfig) {
            return response()->json(['error' => 'Acción no configurada en el Blueprint'], 500);
        }

        $nextStepId = $actionConfig['next_step_id'];

        // 4. Guardar Historial de Revisión en el State Store
        $store = $instance->state_store;
        $store['revisiones'][] = [
            'fecha' => now()->toIso8601String(),
            'veredicto' => $request->verdict,
            'comentario' => $request->comments,
            'paso_revisado' => $currentNode['id']
        ];
        $instance->state_store = $store;

        // 5. Avanzar
        $instance->current_step_id = $nextStepId;
        $instance->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Revisión procesada correctamente',
            'next_step' => $nextStepId
        ]);
    }
}
