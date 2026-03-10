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
                [
                    'role' => 'system',
                    'content' => 'Eres asistente de un salón de eventos. Responde de forma amable, clara, breve y natural. Ayuda a resolver dudas y a recopilar datos del prospecto sin sonar robótico.'
                ]
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