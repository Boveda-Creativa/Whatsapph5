<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;

class WhatsAppCloud
{
    public function sendText(string $to, string $text): void
    {
        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $token = config('services.whatsapp.token');

        Http::withToken($token)
            ->post("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages", [
                "messaging_product" => "whatsapp",
                "to" => $to,
                "type" => "text",
                "text" => ["body" => $text],
            ])->throw();
    }
}