<?php
namespace App\Services;

class FaqMatcher
{
    public function match(string $text): ?string
    {
        $t = mb_strtolower($text);

        if (str_contains($t, 'capacidad') && str_contains($t, 'sal')) {
            return "Nuestro salón tiene capacidad para 100 personas y es refrigerado.";
        }
        if (str_contains($t, 'jard') || str_contains($t, 'exterior')) {
            return "Las áreas exteriores tienen capacidad hasta para 450 personas.";
        }
        if (str_contains($t, 'estacion')) {
            return "Sí, contamos con estacionamiento hasta para 120 carros.";
        }
        if (str_contains($t, 'silla') || str_contains($t, 'ruedas') || str_contains($t, 'acceso')) {
            return "Sí, contamos con acceso para personas con silla de ruedas dentro de todo el local y baños.";
        }
        if (str_contains($t, 'fum')) {
            return "Sí, contamos con área de fumadores en exterior.";
        }
        if (str_contains($t, 'apart') || str_contains($t, 'bloque') || str_contains($t, 'reserv')) {
            return "La fecha se bloquea con $5,000 pesos; posteriormente manejamos un sistema de pagos.";
        }
        if (str_contains($t, 'factura') || str_contains($t, 'iva')) {
            return "Sí emitimos factura; se agrega el IVA.";
        }
        if (str_contains($t, 'tarjeta')) {
            return "De momento no aceptamos tarjeta; únicamente efectivo o transferencia interbancaria.";
        }
        if (str_contains($t, 'ubic') || str_contains($t, 'donde') || str_contains($t, 'direc')) {
            return "Estamos en Camino al Tazajal S/N, Ejido La Victoria, Hermosillo, Sonora. A 10 minutos de la zona hotelera norte.";
        }
        if (str_contains($t, 'audio') || str_contains($t, 'audiovisual') || str_contains($t, 'pista')) {
            return "No manejamos pista ni equipo audiovisual; eso se ve con proveedores externos.";
        }
        if (str_contains($t, 'planner') || str_contains($t, 'coordin')) {
            return "No manejamos coordinación de eventos; eso se ve con su planner.";
        }

        return null;
    }
}