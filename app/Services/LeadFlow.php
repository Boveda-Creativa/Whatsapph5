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
 * Este flujo ha sido diseñado para:
 * 1. Calificar a los prospectos recabando la información clave (tipo de evento, nombre,
 *    fecha, número de personas, presupuesto, tipo de paquete, fecha alternativa,
 *    tipo de cliente y origen).
 * 2. Responder preguntas frecuentes de forma automática sin interrumpir el flujo.
 * 3. Apoyarse en IA solo cuando no exista una coincidencia en las FAQs ni se esté
 *    esperando una respuesta concreta; la IA es breve, no inventa información y
 *    redirige al usuario a completar su perfil.
 * 4. Permitir que, tras un handoff, el bot siga resolviendo dudas rápidas sin
 *    volver a iniciar el cuestionario completo.
 * 5. Registrar cada mensaje de la conversación en un log y guardar el resumen
 *    enviado al asesor en otro log.
 */
class LeadFlow
{
    public function __construct(
        private WhatsAppCloud $wa,
        private FaqMatcher $faq,
        private AiResponder $ai
    ) {}

    /**
     * Punto de entrada principal para cada mensaje recibido.
     */
    public function handle(WaConversation $c, string $text): void
    {
        // Registrar mensaje entrante en log de conversación
        $this->logConversationEntry($c, 'user', $text);

        // Si la conversación está en modo humano o en estado de handoff, responder como bot de soporte
        if ($c->mode === 'human' || $c->state === 'handoff') {
            $this->handlePostHandoff($c, $text);
            return;
        }

        // Si el usuario pide expresamente hablar con un humano, hacemos handoff inmediato
        if ($this->wantsHuman($text)) {
            $this->handoff($c, "El prospecto pidió hablar con alguien.");
            return;
        }

        // Procesar según el estado actual del flujo antes de intentar FAQs o IA
        switch ($c->state) {
            case 'new':
                // Primera interacción: preguntar el tipo de evento
                $this->askEventType($c);
                return;

            case 'ask_event_type':
                $this->saveEventTypeAskName($c, $text);
                return;

            case 'ask_name':
                $this->saveNameAskDate($c, $text);
                return;

            case 'ask_event_date':
                $this->saveDateAskPeople($c, $text);
                return;

            case 'ask_people_count':
                $this->savePeopleAskBudget($c, $text);
                return;

            case 'ask_budget_range':
                $this->saveBudgetAskPackage($c, $text);
                return;

            case 'ask_package_type':
                $this->savePackageAskAltDate($c, $text);
                return;

            case 'ask_alt_date':
                $this->saveAltDateAskCustomerType($c, $text);
                return;

            case 'ask_customer_type':
                $this->saveCustomerTypeAskSource($c, $text);
                return;

            case 'ask_source':
                $this->saveSourceAndHandoff($c, $text);
                return;
        }

        // Si no estamos esperando una respuesta concreta, revisamos las FAQs
        $faqAnswer = $this->faq->match($text);
        if ($faqAnswer) {
            $this->reply($c, $faqAnswer);
            // Después de una FAQ, guía al usuario a continuar con su perfil
            $this->askNextQuestion($c);
            return;
        }

        // Fallback IA: construir historial y contexto
        $history = WaMessage::where('conversation_id', $c->id)
            ->orderBy('id')
            ->take(10)
            ->get()
            ->map(fn(WaMessage $m) => [
                'role'    => $m->direction === 'in' ? 'user' : 'assistant',
                'content' => $m->body,
            ])
            ->toArray();

        // Construir contexto básico con el estado actual
        $lead = $this->ensureLead($c);
        $context = "Estado actual: {$c->state}. ";
        if ($lead->name) {
            $context .= "Ya tengo su nombre ({$lead->name}). ";
        }
        if ($lead->phone) {
            $context .= "Ya tengo su teléfono ({$lead->phone}). ";
        }
        if ($lead->event_date) {
            $context .= "La fecha del evento es {$lead->event_date}. ";
        }
        if ($lead->event_type) {
            $context .= "El tipo de evento es {$lead->event_type}. ";
        }

        $aiReply = $this->ai->respond($context . $text, $history);
        if ($aiReply) {
            $this->reply($c, $aiReply);
            // Después de la IA, guía al usuario a completar su perfil
            $this->askNextQuestion($c);
            return;
        }

        // Si todo falla, preguntamos de nuevo el tipo de evento
        $this->askEventType($c);
    }

    /**
     * Maneja las respuestas cuando ya se ha hecho handoff o el modo es humano.
     * El bot sigue resolviendo dudas rápidas pero no reinicia el cuestionario.
     */
    private function handlePostHandoff(WaConversation $c, string $text): void
    {
        // Revisar FAQs para dudas rápidas
        $faqAnswer = $this->faq->match($text);
        if ($faqAnswer) {
            $this->reply(
                $c,
                $faqAnswer . "\n\nTu conversación ya fue compartida con un asesor y te dará seguimiento en breve."
            );
            return;
        }

        // Respuesta genérica post-handoff
        $this->reply(
            $c,
            "Gracias por tu mensaje 🙌 Ya compartimos tu información con un asesor para darte seguimiento.\n\n" .
                "En lo que te contacta, también puedo ayudarte con dudas rápidas sobre capacidad, ubicación, paquetes, apartado o servicios."
        );
    }

    /**
     * Pregunta el nombre del prospecto.
     */
    private function askName(WaConversation $c): void
    {
        $c->update(['state' => 'ask_name']);
        $this->reply($c, "¿Me compartes tu nombre completo?");
    }

    /**
     * Primer paso del flujo: preguntar tipo de evento con saludo inicial.
     */
    private function askEventType(WaConversation $c): void
    {
        $c->update(['state' => 'ask_event_type']);
        $this->reply(
            $c,
            "Gracias por comunicarte con Hacienda Cinco 🙌\n\n" .
                "Cuéntanos, ¿cuál es tu tipo de evento?\n" .
                "- Boda\n" .
                "- Postboda\n" .
                "- XV años\n" .
                "- Bautizo\n" .
                "- Shower\n" .
                "- Evento empresarial\n" .
                "- Sesión de fotos\n" .
                "- Otro"
        );
    }

    /**
     * Garantiza que exista un lead asociado a la conversación.
     */
    private function ensureLead(WaConversation $c): WaLead
    {
        if ($c->lead_id) return WaLead::findOrFail($c->lead_id);

        $lead = WaLead::create(['phone' => $c->phone]);
        $c->update(['lead_id' => $lead->id]);
        return $lead;
    }

    /**
     * Guarda el tipo de evento y pide el nombre.
     */
    private function saveEventTypeAskName(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $lead->update(['event_type' => $this->normalizeEventType($text)]);

        $c->update(['state' => 'ask_name']);
        $this->reply($c, "Perfecto. ¿Me compartes tu nombre completo?");
    }

    /**
     * Guarda el nombre y solicita la fecha del evento.
     */
    private function saveNameAskDate(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $lead->update(['name' => $text]);

        $c->update(['state' => 'ask_event_date']);
        $this->reply($c, "Gracias, {$lead->name} 🙌\n\n¿Cuál es la fecha del evento? (día/mes/año)");
    }

    /**
     * Después de una respuesta de IA o FAQ, dirige al usuario a la siguiente pregunta según el estado actual.
     */
    private function askNextQuestion(WaConversation $c): void
    {
        switch ($c->state) {
            case 'new':
                $this->askEventType($c);
                break;
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
                $this->reply(
                    $c,
                    "¿Con qué presupuesto aproximado cuentas?\n" .
                        "- $30k a $50k\n" .
                        "- $50k a $100k\n" .
                        "- $100k a $150k\n" .
                        "- $150k a $200k\n" .
                        "- $200k+"
                );
                break;
            case 'ask_package_type':
                $this->reply(
                    $c,
                    "¿Qué estás buscando?\n" .
                        "1) Solo renta del local\n" .
                        "2) Paquete con banquete\n" .
                        "3) Paquete sin banquete"
                );
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
            default:
                // No hacemos nada si el estado es indefinido
                break;
        }
    }

    /**
     * Envía un mensaje por WhatsApp y registra la salida en la base de datos y en el log.
     */
    private function reply(WaConversation $c, string $text): void
    {
        $this->wa->sendText($c->phone, $text);
        // Registrar mensaje saliente en base de datos
        WaMessage::create([
            'conversation_id' => $c->id,
            'direction' => 'out',
            'body' => $text,
        ]);
        // Registrar en log
        $this->logConversationEntry($c, 'bot', $text);
    }

    /**
     * Determina si el usuario solicita hablar con un humano.
     */
    private function wantsHuman(string $text): bool
    {
        $t = mb_strtolower($text);
        return str_contains($t, 'asesor')
            || str_contains($t, 'persona')
            || str_contains($t, 'humano')
            || str_contains($t, 'llamar')
            || str_contains($t, 'hablar con alguien');
    }

    /**
     * Realiza el handoff al asesor, actualiza el estado y envía el resumen.
     */
    private function handoff(WaConversation $c, string $reason): void
    {
        $lead = $this->ensureLead($c);
        $lead->update(['status' => 'handoff']);

        $c->update(['mode' => 'human', 'state' => 'handoff']);

        $this->reply($c, "Perfecto 🙌 Ya tengo la información. Te conecto con un asesor para darte seguimiento.");

        $summary = $this->buildLeadSummary($lead, $reason);

        // Registrar resumen en log aparte
        $this->logSummary($summary);

        $internalNumber = config('services.whatsapp.internal_notify_number');
        if ($internalNumber) {
            $this->wa->sendText($internalNumber, $summary);
        }
    }

    /**
     * Construye un resumen del lead para enviarlo al asesor.
     */
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
            . "Score: " . ($lead->score ?? 0);
    }

    /**
     * Normaliza los distintos textos que representan el tipo de evento.
     */
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

    /**
     * Guarda la fecha del evento y pide el número de personas.
     */
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

    /**
     * Guarda la cantidad de personas y pide el presupuesto.
     */
    private function savePeopleAskBudget(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $people = $this->parsePeopleCount($text);
        if (!$people) {
            $this->reply($c, "¿Me ayudas con un número aproximado de personas? (Ej: 80)");
            return;
        }
        // Filtro útil: si es menor a 50, orientar sin cortar en seco
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

    /**
     * Guarda el presupuesto y solicita el tipo de paquete.
     */
    private function saveBudgetAskPackage(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $budget = $this->normalizeBudgetRange($text);
        if (!$budget) {
            // Si no pudo normalizar, inténtalo de nuevo preguntando opciones
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

    /**
     * Guarda el tipo de paquete y pide fecha alternativa.
     */
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

    /**
     * Guarda la fecha alternativa (o ausencia) y solicita tipo de cliente.
     */
    private function saveAltDateAskCustomerType(WaConversation $c, string $text): void
    {
        $lead = $this->ensureLead($c);
        $yn = $this->parseYesNo($text);
        if ($yn === null) {
            // Puede que el usuario ya haya dado una fecha directa
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
        // Si respondió que sí, solicita la fecha alternativa
        $this->reply($c, "Buenísimo. ¿Cuál sería tu fecha alternativa? (día/mes/año)");
        // Mantén el estado en ask_alt_date para capturar la fecha en el siguiente mensaje
    }

    /**
     * Guarda el tipo de cliente (empresa/persona física) y pide la fuente del lead.
     */
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

    /**
     * Guarda la fuente del lead y hace handoff al asesor.
     */
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

    /**
     * Analiza fechas en formatos mexicanos (dd/mm/aaaa o dd de mes aaaa).
     */
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
        // "15 de mayo 2026" (simple, sin NLP fancy)
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

    /**
     * Extrae y normaliza la cantidad de personas. Devuelve null si no es reconocida.
     */
    private function parsePeopleCount(string $text): ?int
    {
        if (preg_match('/\b(\d{1,4})\b/', $text, $m)) {
            $n = (int)$m[1];
            if ($n >= 1 && $n <= 2000) return $n;
        }
        return null;
    }

    /**
     * Normaliza el presupuesto en una de las categorías definidas.
     * Acepta palabras comunes como "cien mil", "100k", "de 50 a 100", etc.
     */
    private function normalizeBudgetRange(string $text): ?string
    {
        $t = mb_strtolower($text);
        // Quitar símbolos de moneda y texto irrelevante
        $clean = str_replace(['$', 'mxn', 'pesos', 'peso', 'de', 'aprox', 'aproximado', 'aproximada', ','], ' ', $t);
        // Buscar números con opcionales sufijos k, mil, m (millones)
        preg_match_all('/(\d+(?:\.\d+)?)\s*(k|mil|m)?/u', $clean, $matches);
        $values = [];
        foreach ($matches[1] as $idx => $num) {
            $suf = $matches[2][$idx] ?? '';
            $val = (float)$num;
            $mult = 1;
            if ($suf === 'k' || $suf === 'mil') {
                $mult = 1000;
            } elseif ($suf === 'm') {
                $mult = 1000000;
            }
            $values[] = (int)round($val * $mult);
        }
        if (empty($values)) {
            return null;
        }
        // Si hay dos valores, tomar el promedio para estimar rango
        $avg = (int)round(array_sum($values) / count($values));
        // Si el valor es inferior a 1000, asumimos que está en miles (ej: "80" -> 80k)
        if ($avg < 1000) {
            $avg *= 1000;
        }
        // Clasificar en rangos
        if ($avg >= 30000 && $avg < 50000) return '30-50';
        if ($avg >= 50000 && $avg < 100000) return '50-100';
        if ($avg >= 100000 && $avg < 150000) return '100-150';
        if ($avg >= 150000 && $avg < 200000) return '150-200';
        if ($avg >= 200000) return '200+';
        return null;
    }

    /**
     * Normaliza el tipo de paquete.
     */
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

    /**
     * Parsea respuestas de sí/no en distintos modismos.
     */
    private function parseYesNo(string $text): ?bool
    {
        $t = mb_strtolower(trim($text));
        $yes = ['si', 'sí', 'simon', 'claro', 'ok', 'va', 'de acuerdo', 'afirmativo', 'claro que sí'];
        $no  = ['no', 'nel', 'nop', 'para nada', 'negativo', 'nope'];
        foreach ($yes as $w) if ($t === $w || str_contains($t, $w)) return true;
        foreach ($no as $w)  if ($t === $w || str_contains($t, $w)) return false;
        return null;
    }

    /**
     * Normaliza el tipo de cliente.
     */
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

    /**
     * Calcula la puntuación del lead en función de los datos capturados.
     */
    private function scoreLead(WaLead $lead): int
    {
        $score = 0;
        if ($lead->event_date) $score += 15;
        if ($lead->people_count) $score += 15;
        if ($lead->budget_range) $score += 25;
        if ($lead->package_type) $score += 10;
        // Más personas, mayor puntuación
        if ($lead->people_count >= 100) $score += 10;
        if ($lead->people_count >= 200) $score += 10;
        // Presupuestos altos
        if (in_array($lead->budget_range, ['150-200', '200+'], true)) $score += 10;
        // Eventos fuertes
        if (in_array($lead->event_type, ['boda', 'xv', 'empresarial'], true)) $score += 10;
        return min(100, $score);
    }

    /**
     * Registra un mensaje de conversación en el archivo de log de conversaciones.
     */
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
        // Escribe en storage/logs/conversation.log
        try {
            $path = storage_path('logs/conversation.log');
            file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
        } catch (\Throwable $e) {
            // Fallback a Log por si falla file_put_contents
            Log::info('Conversación: ' . $line);
        }
    }

    /**
     * Registra el resumen del lead en un archivo aparte.
     */
    private function logSummary(string $summary): void
    {
        try {
            $path = storage_path('logs/lead_summary.log');
            file_put_contents($path, $summary . PHP_EOL . str_repeat('-', 40) . PHP_EOL, FILE_APPEND);
        } catch (\Throwable $e) {
            Log::info('Resumen lead: ' . $summary);
        }
    }
}