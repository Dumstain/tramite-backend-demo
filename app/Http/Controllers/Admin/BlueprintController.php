<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProcedureBlueprint;
use Illuminate\Http\Request;

class BlueprintController extends Controller
{
    // Guarda el JSON del diseñador
    public function store(Request $request)
    {
        // Validación básica (en prod sería más estricta)
        $validated = $request->validate([
            'name' => 'required|string',
            'schema_definition' => 'required|array', // Laravel verifica que sea un JSON válido
        ]);

        $blueprint = ProcedureBlueprint::create([
            'name' => $validated['name'],
            'description' => $request->input('description'),
            'schema' => $validated['schema_definition'], // Mapeamos al campo 'schema' de la BD
            'is_active' => true
        ]);

        return response()->json(['message' => 'Trámite guardado', 'id' => $blueprint->id], 201);
    }

    // Listar trámites
    public function index()
    {
        return ProcedureBlueprint::select('id', 'name', 'created_at')->get();
    }

    // Obtener un trámite específico (para editar)
    public function show($id)
    {
        return ProcedureBlueprint::findOrFail($id);
    }
    // Actualizar el JSON del diseñador
    public function update(Request $request, $id) {
    $bp = ProcedureBlueprint::findOrFail($id);
    $bp->schema = $request->input('schema_definition');
    $bp->save();
    return $bp;
    }
}
