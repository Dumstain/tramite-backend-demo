<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        // 1. Validar que sea un archivo real
        $request->validate([
            'file' => 'required|file|max:10240', // Max 10MB
        ]);

        // 2. Guardar en disco local (public)
        // Crea una carpeta por año/mes para no saturar un solo directorio
        $path = $request->file('file')->store('uploads/' . date('Y/m'), 'public');

        // 3. Generar URL absoluta para que el Front la pueda mostrar
        // En local: http://localhost:8000/storage/uploads/2025/11/archivo.pdf
        $url = asset('storage/' . $path);

        return response()->json([
            'original_name' => $request->file('file')->getClientOriginalName(),
            'path' => $path,
            'url' => $url
        ]);
    }
}
