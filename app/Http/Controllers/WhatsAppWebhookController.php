<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
{
    Log::info('Webhook VERIFY hit!');

    $mode = $request->get('hub_mode') ?? $request->get('hub.mode');
    $token = $request->get('hub_verify_token') ?? $request->get('hub.verify_token');
    $challenge = $request->get('hub_challenge') ?? $request->get('hub.challenge');

    if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
        return response($challenge, 200);
    }

    return response('Forbidden', 403);
}

    public function receive(Request $request)
{
    // 👇 LOG AQUÍ (lo más arriba posible)
    Log::info('WA webhook payload', $request->all());

    $value = data_get($request->all(), 'entry.0.changes.0.value');
    $message = data_get($value, 'messages.0');

    if(!$message) {
        Log::info('No message found in payload');
        return response('OK',200);
    }

    $from = data_get($message, 'from');
    $text = trim((string) data_get($message, 'text.body', ''));

    Log::info('Parsed message', [
        'from' => $from,
        'text' => $text,
    ]);

    $conversation = \App\Models\WaConversation::firstOrCreate(
        ['phone' => $from],
        ['state' => 'new', 'mode' => 'bot', 'data' => []]
    );

    $conversation->update([
        'last_inbound_at' => now(),
        'window_open_until' => now()->addHours(24),
    ]);

    \App\Models\WaMessage::create([
        'conversation_id' => $conversation->id,
        'direction' => 'in',
        'body' => $text,
        'raw' => $request->all(),
        'wa_message_id' => data_get($message, 'id'),
    ]);

    \App\Jobs\ProcessIncomingWaMessage::dispatchSync($conversation->id, $text);

    return response('OK',200);
}
}
