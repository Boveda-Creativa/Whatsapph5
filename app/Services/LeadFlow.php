<?php

namespace App\Services;

use App\Models\WaConversation;
use App\Models\WaLead;
use App\Models\WaMessage;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * LeadFlow gestiona el flujo de conversación del bot de WhatsApp.
 *
 * En esta versión:
 * - La IA NO inventa información comercial.
 * - Las respuestas informativas salen solo de FaqMatcher.
 * - Cuando no hay dato exacto, el bot deriva a asesor y empuja a completar el formulario.
 * - La IA puede seguir existiendo inyectada, pero aquí ya no se usa para contestar contenido comercial.
 * - Los leads calificados se notifican por Telegram para evitar las limitaciones de WhatsApp.
 */
class LeadFlow
{
    public function __construct(
        private WhatsAppCloud $wa,
        private FaqMatcher $faq,
        private AiResponder $ai,
        private TelegramNotifier $telegram
    ) {}

    public function handle(WaConversation $c, string $text): void
    {
        $this->logConversationEntry($c, 'user', $text);

        if ($c->mode === 'human' || $c->state === 'handoff') {
            $this->handlePostHandoff($c, $text);
            return;
        }

        if ($this->wantsHuman($text)) {
            $this->handoff($c, "El prospecto pidió hablar con alguien.");
            return;
        }

        switch ($c->state) {
            case 'new':
                $faqAnswer = $this->faq->match($text);
                if ($faqAnswer) {
                    $this->reply($c, $faqAnswer . "\n\nSi gustas, también puedo ayudarte a cotizar. ¿Qué tipo de evento estás planeando?");
                    $c->update(['state' => 'ask_event_type']);
                    return;
                }

                $this->askEventType($c);
                return;

            case 'ask_event_type':
                $faqAnswer = $this->faq->match($text);
                if ($faqAnswer) {
                    $this->reply($c, $faqAnswer . "\n\nY para ayudarte mejor, ¿qué tipo de evento estás planeando?");
                    return;
                }
                $this->saveEventTypeAskName($c, $text);
                return;

            case 'ask_name':
                $faqAnswer = $this->faq->match($text);
                if ($faqAnswer) {
                    $this->reply($c, $faqAnswer . "\n\nAhora sí, ¿me compartes tu nombre completo?");
                    return;
                }
                $this->saveNameAskDate($c, $text);
                return;

            case 'ask_event_date':
                $faqAnswer = $this->faq->match($text);
                if ($faqAnswer) {
                    $this->reply($c, $faqAnswer . "\n\nCuando gustes, compárteme la fecha del evento en formato día/mes/año.");
                    return;
                }
                $this->saveDateAskPeople($c, $text);
                return;

            case 'ask_people_count':
                $faqAnswer = $this->faq->match($text);
                if ($faqAnswer) {
                    $this->reply($c, $faqAnswer . "\n\nY para continuar, ¿para cuántas personas sería aproximadamente tu evento?");
                    return;
                }
                $this->savePeopleAskBudget($c, $text);
                return;

            case 'ask_budget_range':
                $faqAnswer = $this->faq->match($text);
                if ($faqAnswer) {
                    $this->reply($c, $faqAnswer . "\n\nY para orientarte mejor, ¿con qué presupuesto aproximado cuentas?");
                    return;
                }
                $this->saveBudgetAskPackage($c, $text);
                return;

            case 'ask_package_type':
                $faqAnswer = $this->faq->match($text);
                if ($faqAnswer) {
                    $this->reply($c, $faqAnswer . "\n\n¿Cuál paquete te interesa más o qué tipo de opción estás buscando?");
                    return;
                }
                $this->savePackageAskAltDate($c, $text);
                return;

            case 'ask_alt_date':
                $faqAnswer = $this->faq->match($text);
                if ($faqAnswer) {
                    $this->reply($c, $faqAnswer . "\n\n¿Tienes alguna fecha alternativa en caso de que la principal no esté disponible?");
                    return;
                }
                $this->saveAltDateAskCustomerType($c, $text);
                return;

            case 'ask_customer_type':
                $faqAnswer = $this->faq->match($text);
                if ($faqAnswer) {
                    $this->reply($c, $faqAnswer . "\n\n¿El evento sería para empresa o persona física?");
                    return;
                }
                $this->saveCustomerTypeAskSource($c, $text);
                return;

            case 'ask_source':
                $faqAnswer = $this->faq->match($text);
                if ($faqAnswer) {
                    $this->reply($c, $faqAnswer . "\n\nY por último, ¿cómo te enteraste de nosotros?");
                    return;
                }
                $this->saveSourceAndHandoff($c, $text);
                return;
        }

        $faqAnswer = $this->faq->match($text);
        if ($faqAnswer) {
            $this->reply($c, $faqAnswer);
            $this->askNextQuestion($c);
            return;
        }

        $this->replyUnknownAndContinue($c);
    }

    private function handlePostHandoff(WaConversation $c, string $text): void
    {
        $faqAnswer = $this->faq->match($text);
        if ($faqAnswer) {
            $this->reply(
                $c,
                $faqAnswer . "\n\nTu conversación ya fue compartida con un asesor y te dará seguimiento en breve."
            );
            return;
        }

        $this->reply(
            $c,
            "No tengo esa información exacta y prefiero no darte un dato incorrecto.\n\n"
            . "Tu conversación ya fue compartida con un asesor para darte seguimiento."
        );
    }

    private function askName(WaConversation $c): void
    {
        $c->update(['state' => 'ask_name']);
        $this->reply($c, "¿Me compartes tu nombre completo?");
    }

    private function askEventType(WaConversation $c): void
    {
        $c->update(['state' => 'ask_event_type']);
        $this->reply(
            $c,
            "Gracias por comunicarte con Hacienda Cinco 🙌\n\n"
            . "Cuéntanos, ¿cuál es tu tipo de evento?\n"
            . "- Boda\n"
            . "- Postboda\n"
            . "- XV años\n"
            . "- Bautizo\n"
            . "- Shower\n"
            . "- Evento empresarial\n"
            . "- Sesión de fotos\n"
            . "- Otro"
        );
    }

    private function ensureLead(WaConversation $c): WaLead
    {
        if ($c->lead_id) {
            return WaLead::findOrFail($c->lead_id);
        }

        $lead = WaLead::create(['phone' => $c->phone]);
        $c->update(['lead_id' => $lead->id]);

        return $lead;
    }

    private function saveEventTypeAskName(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $lead->update(['event_type' => $this->normalizeEventType($text)]);

        $c->update(['state' => 'ask_name']);
        $this->reply($c, "Perfecto. ¿Me compartes tu nombre completo?");
    }

    private function saveNameAskDate(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $lead->update(['name' => $text]);

        $c->update(['state' => 'ask_event_date']);
        $this->reply($c, "Gracias, {$lead->name} 🙌\n\n¿Cuál es la fecha del evento? (día/mes/año)");
    }

    private function askNextQuestion(WaConversation $c): void
    {
        switch ($c->state) {
            case 'new':
            case 'ask_event_type':
                $this->askEventType($c);
                break;
            case 'ask_name':
                $this->askName($c);
                break;
            case 'ask_event_date':
                $this->reply($c, "¿Cuál es la fecha del evento? (día/mes/año)");
                break;
            case 'ask_people_count':
                $this->reply($c, "¿Para cuántas personas sería aproximadamente tu evento?");
                break;
            case 'ask_budget_range':
                $this->reply($c, "¿Con qué presupuesto aproximado cuentas?\n- \$30k a \$50k\n- \$50k a \$100k\n- \$100k a \$150k\n- \$150k a \$200k\n- \$200k+");
                break;
            case 'ask_package_type':
                $this->reply($c, "¿Qué opción te interesa más?\n1) Paquete Primavera\n2) Full Inclusive\n3) Los Adobes\n4) Paquete a la medida");
                break;
            case 'ask_alt_date':
                $this->reply($c, "¿Tienes alguna fecha alternativa en caso de que la principal no esté disponible? (Sí/No)");
                break;
            case 'ask_customer_type':
                $this->reply($c, "¿El evento sería para empresa o persona física?");
                break;
            case 'ask_source':
                $this->reply($c, "¿Cómo te enteraste de nosotros? (Facebook, Instagram, recomendación, Google, etc.)");
                break;
        }
    }

    private function reply(WaConversation $c, string $text): void
    {
        $this->wa->sendText($c->phone, $text);

        WaMessage::create([
            'conversation_id' => $c->id,
            'direction' => 'out',
            'body' => $text,
        ]);

        $this->logConversationEntry($c, 'bot', $text);
    }

    private function wantsHuman(string $text): bool
    {
        $t = mb_strtolower($text);

        return str_contains($t, 'asesor')
            || str_contains($t, 'persona')
            || str_contains($t, 'humano')
            || str_contains($t, 'llamar')
            || str_contains($t, 'hablar con alguien');
    }

    private function handoff(WaConversation $c, string $reason): void
    {
        $lead = $this->ensureLead($c);
        $lead->update(['status' => 'handoff']);

        $c->update(['mode' => 'human', 'state' => 'handoff']);

        $this->reply($c, "Perfecto 🙌 Ya tengo la información. Te conecto con un asesor para darte seguimiento.");

        $summary = $this->buildLeadSummary($lead, $reason);

        $this->logSummary($summary);
        $this->telegram->send($summary);

        $internalNumber = config('services.whatsapp.internal_notify_number');
        if ($internalNumber) {
            try {
                $this->wa->sendText($internalNumber, $summary);
            } catch (\Throwable $e) {
                Log::warning('WhatsApp internal notification failed.', [
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    private function buildLeadSummary(WaLead $lead, string $reason): string
    {
        return "Nuevo lead calificado 🚨\n\n"
            . "Motivo: {$reason}\n"
            . "Nombre: " . ($lead->name ?? 'N/D') . "\n"
            . "Teléfono: " . ($lead->phone ?? 'N/D') . "\n"
            . "Evento: " . ($lead->event_type ?? 'N/D') . "\n"
            . "Fecha: " . ($lead->event_date ?? 'N/D') . "\n"
            . "Personas: " . ($lead->people_count ?? 'N/D') . "\n"
            . "Presupuesto: " . ($lead->budget_range ?? 'N/D') . "\n"
            . "Paquete: " . ($lead->package_type ?? 'N/D') . "\n"
            . "Tipo cliente: " . ($lead->customer_type ?? 'N/D') . "\n"
            . "Origen: " . ($lead->source ?? 'N/D') . "\n"
            . "Score: " . ($lead->score ?? 0) . "\n\n"
            . "La información también queda guardada en base de datos y logs del sistema.";
    }

    private function normalizeEventType(string $text): string
    {
        $t = mb_strtolower($text);
        if (str_contains($t, 'xv')) return 'xv';
        if (str_contains($t, 'boda')) return 'boda';
        if (str_contains($t, 'post')) return 'postboda';
        if (str_contains($t, 'baut')) return 'bautizo';
        if (str_contains($t, 'shower')) return 'shower';
        if (str_contains($t, 'empres')) return 'empresarial';
        if (str_contains($t, 'foto')) return 'sesion_fotos';
        return 'otro';
    }

    private function saveDateAskPeople(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $date = $this->parseDateMx($text);

        if (!$date) {
            $this->reply($c, "Para confirmar, ¿me compartes la fecha en formato día/mes/año? (Ej: 15/05/2026)");
            return;
        }

        $lead->update(['event_date' => $date->toDateString()]);
        $c->update(['state' => 'ask_people_count']);
        $this->reply($c, "Gracias. ¿Para cuántas personas sería aproximadamente tu evento?");
    }

    private function savePeopleAskBudget(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $people = $this->parsePeopleCount($text);

        if (!$people) {
            $this->reply($c, "¿Me ayudas con un número aproximado de personas? (Ej: 80)");
            return;
        }

        $lead->update(['people_count' => $people]);
        $c->update(['state' => 'ask_budget_range']);
        $this->reply($c, "Perfecto. ¿Con qué presupuesto aproximado cuentas?\n- \$30k a \$50k\n- \$50k a \$100k\n- \$100k a \$150k\n- \$150k a \$200k\n- \$200k+");
    }

    private function saveBudgetAskPackage(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $budget = $this->normalizeBudgetRange($text);

        if (!$budget) {
            $this->reply($c, "¿Cuál de estas opciones se acerca más a tu presupuesto?\n- \$30k a \$50k\n- \$50k a \$100k\n- \$100k a \$150k\n- \$150k a \$200k\n- \$200k+");
            return;
        }

        $lead->update(['budget_range' => $budget]);
        $c->update(['state' => 'ask_package_type']);
        $this->reply($c, "Gracias. ¿Qué opción te interesa más?\n1) Paquete Primavera\n2) Full Inclusive\n3) Los Adobes\n4) Paquete a la medida");
    }

    private function savePackageAskAltDate(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $package = $this->normalizePackageType($text);

        if (!$package) {
            $this->reply($c, "Para asegurarme, elige una opción:\n1) Paquete Primavera\n2) Full Inclusive\n3) Los Adobes\n4) Paquete a la medida");
            return;
        }

        $lead->update(['package_type' => $package]);
        $c->update(['state' => 'ask_alt_date']);
        $this->reply($c, "¿Tienes alguna fecha alternativa en caso de que la principal no esté disponible? (Sí/No)");
    }

    private function saveAltDateAskCustomerType(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $yn = $this->parseYesNo($text);

        if ($yn === null) {
            $date = $this->parseDateMx($text);
            if ($date) {
                $lead->update(['alt_date' => $date->toDateString()]);
                $c->update(['state' => 'ask_customer_type']);
                $this->reply($c, "Perfecto. ¿El evento sería para empresa o persona física?");
                return;
            }

            $this->reply($c, "¿Tienes una fecha alternativa? Responde Sí o No (o envíame la fecha alternativa día/mes/año).");
            return;
        }

        if ($yn === false) {
            $lead->update(['alt_date' => null]);
            $c->update(['state' => 'ask_customer_type']);
            $this->reply($c, "Perfecto. ¿El evento sería para empresa o persona física?");
            return;
        }

        $this->reply($c, "Buenísimo. ¿Cuál sería tu fecha alternativa? (día/mes/año)");
    }

    private function saveCustomerTypeAskSource(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $type = $this->normalizeCustomerType($text);

        if (!$type) {
            $this->reply($c, "¿Sería para:\n1) Empresa\n2) Persona física?");
            return;
        }

        $lead->update(['customer_type' => $type]);
        $c->update(['state' => 'ask_source']);
        $this->reply($c, "¿Cómo te enteraste de nosotros? (Facebook, Instagram, recomendación, Google, etc.)");
    }

    private function saveSourceAndHandoff(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $source = trim($text);

        if (mb_strlen($source) < 2) {
            $this->reply($c, "¿Me lo repites por favor? ¿Cómo te enteraste de nosotros?");
            return;
        }

        $lead->update([
            'source' => $source,
            'status' => 'qualified',
            'score' => $this->scoreLead($lead),
        ]);

        $this->handoff($c, "Lead calificado automáticamente. Score: {$lead->score}");
    }

    private function replyUnknownAndContinue(WaConversation $c): void
    {
        $this->ensureLead($c);

        $message = "No tengo esa información exacta y prefiero no darte un dato incorrecto.\n\n"
            . "Si te parece, te ayudo a completar tus datos para que un asesor te contacte con la información correcta.";

        $this->reply($c, $message);

        if ($c->state === 'new') {
            $this->askEventType($c);
            return;
        }

        $this->askNextQuestion($c);
    }

    private function parseDateMx(string $text): ?Carbon
    {
        $t = trim($text);

        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})\b/', $t, $m)) {
            $d = (int)$m[1];
            $mo = (int)$m[2];
            $y = (int)$m[3];
            if ($y < 100) $y += 2000;

            try {
                $dt = Carbon::createFromDate($y, $mo, $d);
                return $dt->isValid() ? $dt : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        $months = [
            'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4,
            'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
            'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10,
            'noviembre' => 11, 'diciembre' => 12,
        ];

        $lower = mb_strtolower($t);
        foreach ($months as $name => $num) {
            if (str_contains($lower, $name)) {
                if (preg_match('/\b(\d{1,2})\b/', $lower, $dm) && preg_match('/\b(20\d{2})\b/', $lower, $ym)) {
                    try {
                        return Carbon::createFromDate((int)$ym[1], $num, (int)$dm[1]);
                    } catch (\Throwable $e) {
                        return null;
                    }
                }
            }
        }

        return null;
    }

    private function parsePeopleCount(string $text): ?int
    {
        if (preg_match('/\b(\d{1,4})\b/', $text, $m)) {
            $n = (int)$m[1];
            if ($n >= 1 && $n <= 2000) return $n;
        }

        return null;
    }

    private function normalizeBudgetRange(string $text): ?string
    {
        $t = mb_strtolower($text);
        $clean = str_replace(['$', 'mxn', 'pesos', 'peso', 'de', 'aprox', 'aproximado', 'aproximada', ','], ' ', $t);

        preg_match_all('/(\d+(?:\.\d+)?)\s*(k|mil|m)?/u', $clean, $matches);

        $values = [];
        foreach ($matches[1] as $idx => $num) {
            $suf = $matches[2][$idx] ?? '';
            $val = (float)$num;
            $mult = 1;
            if ($suf === 'k' || $suf === 'mil') $mult = 1000;
            elseif ($suf === 'm') $mult = 1000000;
            $values[] = (int) round($val * $mult);
        }

        if (empty($values)) return null;

        $avg = (int) round(array_sum($values) / count($values));
        if ($avg < 1000) $avg *= 1000;

        if ($avg >= 30000 && $avg < 50000) return '30-50';
        if ($avg >= 50000 && $avg < 100000) return '50-100';
        if ($avg >= 100000 && $avg < 150000) return '100-150';
        if ($avg >= 150000 && $avg < 200000) return '150-200';
        if ($avg >= 200000) return '200+';

        return null;
    }

    private function normalizePackageType(string $text): ?string
    {
        $t = mb_strtolower($text);

        if (preg_match('/\b1\b/', $t) || str_contains($t, 'primavera')) return 'primavera';
        if (preg_match('/\b2\b/', $t) || str_contains($t, 'full inclusive') || (str_contains($t, 'full') && str_contains($t, 'inclusive'))) return 'full_inclusive';
        if (preg_match('/\b3\b/', $t) || str_contains($t, 'adobes')) return 'los_adobes';
        if (preg_match('/\b4\b/', $t) || str_contains($t, 'a la medida') || str_contains($t, 'personalizado') || str_contains($t, 'personalizada')) return 'a_la_medida';

        return null;
    }

    private function parseYesNo(string $text): ?bool
    {
        $t = mb_strtolower(trim($text));
        $yes = ['si', 'sí', 'simon', 'claro', 'ok', 'va', 'de acuerdo', 'afirmativo', 'claro que sí'];
        $no = ['no', 'nel', 'nop', 'para nada', 'negativo', 'nope'];

        foreach ($yes as $w) if ($t === $w || str_contains($t, $w)) return true;
        foreach ($no as $w) if ($t === $w || str_contains($t, $w)) return false;

        return null;
    }

    private function normalizeCustomerType(string $text): ?string
    {
        $t = mb_strtolower($text);
        if (preg_match('/\b1\b/', $t) || str_contains($t, 'empresa') || str_contains($t, 'empresarial')) return 'empresa';
        if (preg_match('/\b2\b/', $t) || str_contains($t, 'persona') || str_contains($t, 'física') || str_contains($t, 'fisica')) return 'persona_fisica';
        return null;
    }

    private function scoreLead(WaLead $lead): int
    {
        $score = 0;
        if ($lead->event_date) $score += 15;
        if ($lead->people_count) $score += 15;
        if ($lead->budget_range) $score += 25;
        if ($lead->package_type) $score += 10;
        if ($lead->people_count >= 100) $score += 10;
        if ($lead->people_count >= 200) $score += 10;
        if (in_array($lead->budget_range, ['150-200', '200+'], true)) $score += 10;
        if (in_array($lead->event_type, ['boda', 'xv', 'empresarial'], true)) $score += 10;
        return min(100, $score);
    }

    private function logConversationEntry(WaConversation $c, string $direction, string $text): void
    {
        $record = [
            'conversation_id' => $c->id,
            'phone' => $c->phone,
            'direction' => $direction,
            'body' => $text,
            'timestamp' => now()->toDateTimeString(),
        ];

        $line = json_encode($record, JSON_UNESCAPED_UNICODE);

        try {
            file_put_contents(storage_path('logs/conversation.log'), $line . PHP_EOL, FILE_APPEND);
        } catch (\Throwable $e) {
            Log::info('Conversación: ' . $line);
        }
    }

    private function logSummary(string $summary): void
    {
        try {
            file_put_contents(storage_path('logs/lead_summary.log'), $summary . PHP_EOL . str_repeat('-', 40) . PHP_EOL, FILE_APPEND);
        } catch (\Throwable $e) {
            Log::info('Resumen lead: ' . $summary);
        }
    }
}
