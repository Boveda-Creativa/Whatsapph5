<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiResponder
{
    public function respond(string $message, array $history = []): ?string
    {
        $apiKey = config('services.openai.api_key');

        if (!$apiKey) {
            return null;
        }

        $messages = array_merge(
            [
                ['role' => 'system', 'content' => 'Eres asistente de un salón de eventos. Responde de forma amable y breve. No inventes información: si alguna pregunta no está en la información proporcionada, indícale al usuario que complete su perfil para que un asesor pueda ayudarle. No agendes visitas ni menciones fechas disponibles; eso lo gestiona un asesor. Después de contestar, guía al usuario a seguir contestando las preguntas de su evento (nombre, tipo de evento, fecha, número de personas, presupuesto, paquete, fecha alternativa, tipo de cliente).'],
            ],
            $history,
            [
                [
                    'role' => 'user',
                    'content' => $message
                ]
            ]
        );

        try {
            $response = Http::withToken($apiKey)
                ->timeout(20)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'messages' => $messages,
                    'temperature' => 0.6,
                ])
                ->throw()
                ->json();

            return trim((string) data_get($response, 'choices.0.message.content'));
        } catch (\Throwable $e) {
            Log::error('Error en AiResponder', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}