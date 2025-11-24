<?php

namespace Database\Seeders;

use App\Models\ProcedureBlueprint;
use Illuminate\Database\Seeder;

class DemoTramiteSeeder extends Seeder
{
    public function run(): void
    {
        $jsonFlow = [
            "nodes" => [
                [
                    "id" => "paso_inicio",
                    "type" => "start",
                    "next" => "paso_datos"
                ],
                [
                    "id" => "paso_datos",
                    "type" => "form",
                    "props" => [
                        "title" => "Datos Generales",
                        "fields" => [
                            ["id" => "nombre", "type" => "text", "label" => "Nombre Completo", "bind" => "solicitante_nombre"],
                            ["id" => "edad", "type" => "number", "label" => "Edad", "bind" => "solicitante_edad"]
                        ]
                    ],
                    "next" => "paso_webhook"
                ],
                [
                    "id" => "paso_webhook",
                    "type" => "external_action", // Aquí simularemos llamar a n8n
                    "props" => [
                        "endpoint" => "https://hook.eu1.n8n.cloud/webhook-test", // URL Falsa o Real
                        "method" => "POST",
                        "payload_map" => [
                            "cliente" => "{{solicitante_nombre}}",
                            "status" => "nuevo"
                        ]
                    ],
                    "next" => "paso_fin"
                ],
                [
                    "id" => "paso_fin",
                    "type" => "end",
                    "props" => [
                        "message" => "¡Gracias {{solicitante_nombre}}! Trámite recibido."
                    ]
                ]
            ]
        ];

        ProcedureBlueprint::create([
            'name' => 'Demo Solicitud Beca 2025',
            'description' => 'Trámite de prueba generado por Seeder',
            'schema' => $jsonFlow,
            'is_active' => true
        ]);
    }
}
