<?php

namespace App\Services;

use App\Models\WaConversation;
use App\Models\WaLead;
use App\Models\WaMessage;
use Carbon\Carbon;
use App\Services\AiResponder;

class LeadFlow
{
    public function __construct(
    private WhatsAppCloud $wa,
    private FaqMatcher $faq,
    private AiResponder $ai
) {}

    public function handle(WaConversation $c, string $text): void
    {
        if ($c->mode === 'human') return;

        // 1) Handoff inmediato si lo pide
        if ($this->wantsHuman($text)) {
            $this->handoff($c, "El prospecto pidió hablar con alguien.");
            return;
        }

        // 2) FAQs
        $faqAnswer = $this->faq->match($text);
        if ($faqAnswer) {
            $this->reply($c, $faqAnswer . "\n\nPara cotizar, ¿me compartes tu nombre completo?");
            if ($c->state === 'new') $c->update(['state' => 'ask_name']);
            return;
        }

        $aiReply = $this->ai->respond($text);

        if ($aiReply) {
            $this->reply($c, $aiReply);
            return;
        }

        // 3) Flujo por estados
        match ($c->state) {
            'new' => $this->askName($c),
            'ask_name' => $this->saveNameAskEventType($c, $text),
            'ask_event_type' => $this->saveEventTypeAskDate($c, $text),
            'ask_event_date' => $this->saveDateAskPeople($c, $text),
            'ask_people_count' => $this->savePeopleAskBudget($c, $text),
            'ask_budget_range' => $this->saveBudgetAskPackage($c, $text),
            'ask_package_type' => $this->savePackageAskAltDate($c, $text),
            'ask_alt_date' => $this->saveAltDateAskCustomerType($c, $text),
            'ask_customer_type' => $this->saveCustomerTypeAskSource($c, $text),
            'ask_source' => $this->saveSourceAndHandoff($c, $text),
            default => $this->askName($c),
        };
    }

    private function askName(WaConversation $c): void
    {
        $c->update(['state' => 'ask_name']);
        $this->reply($c, "Hola 👋 Muchas gracias por comunicarte con Hacienda Cinco.\n\n¿Me compartes tu nombre completo?");
    }

    private function ensureLead(WaConversation $c): WaLead
    {
        if ($c->lead_id) return WaLead::findOrFail($c->lead_id);

        $lead = WaLead::create(['phone' => $c->phone]);
        $c->update(['lead_id' => $lead->id]);
        return $lead;
    }

    private function saveNameAskEventType(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $lead->update(['name' => $text]);

        $c->update(['state' => 'ask_event_type']);
        $this->reply(
            $c,
            "Gracias, {$lead->name} 🙌\n\n¿Qué tipo de evento estás buscando?\n- Boda\n- Postboda\n- XV años\n- Bautizo\n- Shower\n- Evento empresarial\n- Sesión de fotos\n- Otro"
        );
    }

    private function saveEventTypeAskDate(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $lead->update(['event_type' => $this->normalizeEventType($text)]);

        $c->update(['state' => 'ask_event_date']);
        $this->reply($c, "Perfecto. ¿Cuál es la fecha del evento? (día/mes/año)");
    }

    // ... y así con cada estado

    private function reply(WaConversation $c, string $text): void
    {
        $this->wa->sendText($c->phone, $text);

        WaMessage::create([
            'conversation_id' => $c->id,
            'direction' => 'out',
            'body' => $text,
        ]);
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

        // TODO: notificar al humano (Telegram/Email/CRM) con resumen
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

        // Filtro útil: si es menor a 50, ya empiezas a orientar (sin cortar en seco)
        if ($people < 50) {
            $lead->update(['people_count' => $people]);

            $c->update(['state' => 'ask_budget_range']);
            $this->reply(
                $c,
                "Perfecto. Solo como referencia, el mínimo sugerido para eventos en nuestro local es de 50 personas en adelante.\n\n" .
                    "¿Con qué presupuesto aproximado cuentas?\n" .
                    "- $30k a $50k\n- $50k a $100k\n- $100k a $150k\n- $150k a $200k\n- $200k+"
            );
            return;
        }

        $lead->update(['people_count' => $people]);

        $c->update(['state' => 'ask_budget_range']);
        $this->reply(
            $c,
            "Perfecto. ¿Con qué presupuesto aproximado cuentas?\n" .
                "- $30k a $50k\n- $50k a $100k\n- $100k a $150k\n- $150k a $200k\n- $200k+"
        );
    }

    private function saveBudgetAskPackage(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);

        $budget = $this->normalizeBudgetRange($text);
        if (!$budget) {
            $this->reply(
                $c,
                "¿Cuál de estas opciones se acerca más a tu presupuesto?\n" .
                    "- $30k a $50k\n- $50k a $100k\n- $100k a $150k\n- $150k a $200k\n- $200k+"
            );
            return;
        }

        $lead->update(['budget_range' => $budget]);

        $c->update(['state' => 'ask_package_type']);
        $this->reply(
            $c,
            "Gracias. ¿Qué estás buscando?\n" .
                "1) Solo renta del local\n" .
                "2) Paquete con banquete\n" .
                "3) Paquete sin banquete"
        );
    }

    private function savePackageAskAltDate(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);

        $package = $this->normalizePackageType($text);
        if (!$package) {
            $this->reply(
                $c,
                "Para asegurarme, elige una opción:\n" .
                    "1) Solo renta del local\n" .
                    "2) Paquete con banquete\n" .
                    "3) Paquete sin banquete"
            );
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
            // Puede que el usuario ya te haya dado una fecha directa
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

        // Sí: pide la fecha
        $this->reply($c, "Buenísimo. ¿Cuál sería tu fecha alternativa? (día/mes/año)");
        // Mantén el estado en ask_alt_date para que el próximo mensaje capture la fecha
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

    private function parseDateMx(string $text): ?Carbon
    {
        $t = trim($text);

        // 15/05/2026 o 15-05-2026
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

        // “15 de mayo 2026” (simple, sin NLP fancy)
        $months = [
            'enero' => 1,
            'febrero' => 2,
            'marzo' => 3,
            'abril' => 4,
            'mayo' => 5,
            'junio' => 6,
            'julio' => 7,
            'agosto' => 8,
            'septiembre' => 9,
            'setiembre' => 9,
            'octubre' => 10,
            'noviembre' => 11,
            'diciembre' => 12,
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
        // Extrae el primer número razonable
        if (preg_match('/\b(\d{1,4})\b/', $text, $m)) {
            $n = (int)$m[1];
            if ($n >= 1 && $n <= 2000) return $n;
        }
        return null;
    }

    private function normalizeBudgetRange(string $text): ?string
    {
        $t = mb_strtolower($text);

        // Acepta: "1", "30-50", "30 a 50", "50k-100k", "200+", etc.
        if (preg_match('/\b1\b/', $t) || str_contains($t, '30') && (str_contains($t, '50') || str_contains($t, 'cincuenta'))) {
            return '30-50';
        }
        if (preg_match('/\b2\b/', $t) || (str_contains($t, '50') && str_contains($t, '100'))) {
            return '50-100';
        }
        if (preg_match('/\b3\b/', $t) || (str_contains($t, '100') && str_contains($t, '150'))) {
            return '100-150';
        }
        if (preg_match('/\b4\b/', $t) || (str_contains($t, '150') && str_contains($t, '200'))) {
            return '150-200';
        }
        if (preg_match('/\b5\b/', $t) || str_contains($t, '200') && (str_contains($t, '+') || str_contains($t, 'mas') || str_contains($t, 'más'))) {
            return '200+';
        }

        return null;
    }

    private function normalizePackageType(string $text): ?string
    {
        $t = mb_strtolower($text);

        if (preg_match('/\b1\b/', $t) || str_contains($t, 'solo') || str_contains($t, 'renta') || str_contains($t, 'local solamente')) {
            return 'local';
        }
        if (preg_match('/\b2\b/', $t) || (str_contains($t, 'paquete') && str_contains($t, 'banquete')) || str_contains($t, 'con banquete')) {
            return 'paquete_con_banquete';
        }
        if (preg_match('/\b3\b/', $t) || (str_contains($t, 'paquete') && str_contains($t, 'sin')) || str_contains($t, 'sin banquete')) {
            return 'paquete_sin_banquete';
        }

        return null;
    }

    private function parseYesNo(string $text): ?bool
    {
        $t = mb_strtolower(trim($text));
        $yes = ['si', 'sí', 'simon', 'claro', 'ok', 'va', 'de acuerdo', 'afirmativo'];
        $no  = ['no', 'nel', 'nop', 'para nada', 'negativo'];

        foreach ($yes as $w) if ($t === $w || str_contains($t, $w)) return true;
        foreach ($no as $w)  if ($t === $w || str_contains($t, $w)) return false;

        return null;
    }

    private function normalizeCustomerType(string $text): ?string
    {
        $t = mb_strtolower($text);

        if (preg_match('/\b1\b/', $t) || str_contains($t, 'empresa') || str_contains($t, 'empresarial')) {
            return 'empresa';
        }
        if (preg_match('/\b2\b/', $t) || str_contains($t, 'persona') || str_contains($t, 'física') || str_contains($t, 'fisica')) {
            return 'persona_fisica';
        }
        return null;
    }

    private function scoreLead(\App\Models\WaLead $lead): int
    {
        $score = 0;

        if ($lead->event_date) $score += 15;
        if ($lead->people_count) $score += 15;
        if ($lead->budget_range) $score += 25;
        if ($lead->package_type) $score += 10;

        // Más personas, más serio
        if ($lead->people_count >= 100) $score += 10;
        if ($lead->people_count >= 200) $score += 10;

        // Presupuesto
        if (in_array($lead->budget_range, ['150-200', '200+'], true)) $score += 10;

        // Evento fuerte
        if (in_array($lead->event_type, ['boda', 'xv', 'empresarial'], true)) $score += 10;

        return min(100, $score);
    }
}
