<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AiResponder
{
    public function respond(string $message): ?string
    {
        $apiKey = config('services.openai.api_key');

        if (!$apiKey) {
            return null;
        }

        $response = Http::withToken($apiKey)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Eres asistente de un salón de eventos. Responde de forma amable, clara y breve.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $message
                    ]
                ],
                'temperature' => 0.6
            ])
            ->json();

        return data_get($response, 'choices.0.message.content');
    }
}