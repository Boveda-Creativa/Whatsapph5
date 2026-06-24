<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIncomingWaMessage;
use App\Models\WaConversation;
use App\Models\WaMessage;
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
        Log::info('WA webhook payload', $request->all());

        $value = data_get($request->all(), 'entry.0.changes.0.value');
        $message = data_get($value, 'messages.0');

        if (!$message) {
            Log::info('No message found in payload');
            return response('OK', 200);
        }

        $from = data_get($message, 'from');
        $text = trim((string) data_get($message, 'text.body', ''));
        $waMessageId = data_get($message, 'id');

        Log::info('Parsed message', [
            'from' => $from,
            'text' => $text,
            'wa_message_id' => $waMessageId,
        ]);

        if ($waMessageId && WaMessage::where('wa_message_id', $waMessageId)->exists()) {
            Log::info('Duplicate WhatsApp message ignored', [
                'from' => $from,
                'wa_message_id' => $waMessageId,
            ]);

            return response('OK', 200);
        }

        $conversation = WaConversation::firstOrCreate(
            ['phone' => $from],
            ['state' => 'new', 'mode' => 'bot', 'data' => []]
        );

        if ($this->shouldRestartStaleHandoff($conversation)) {
            Log::info('Restarting stale handoff conversation', [
                'conversation_id' => $conversation->id,
                'phone' => $conversation->phone,
                'previous_state' => $conversation->state,
                'previous_mode' => $conversation->mode,
                'last_inbound_at' => optional($conversation->last_inbound_at)->toDateTimeString(),
            ]);

            $conversation->update([
                'state' => 'new',
                'mode' => 'bot',
                'lead_id' => null,
                'data' => [],
            ]);
        }

        $conversation->update([
            'last_inbound_at' => now(),
            'window_open_until' => now()->addHours(24),
        ]);

        WaMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'body' => $text,
            'raw' => $request->all(),
            'wa_message_id' => $waMessageId,
        ]);

        ProcessIncomingWaMessage::dispatchSync($conversation->id, $text);

        return response('OK', 200);
    }

    private function shouldRestartStaleHandoff(WaConversation $conversation): bool
    {
        if (!in_array($conversation->mode, ['human'], true) && !in_array($conversation->state, ['handoff'], true)) {
            return false;
        }

        if (!$conversation->last_inbound_at) {
            return false;
        }

        return $conversation->last_inbound_at->lt(now()->subDays(2));
    }
}
