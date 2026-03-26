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
        if (str_contains($t, 'mobiliario') || str_contains($t, 'mueble') || str_contains($t, 'mesa')) {
            return "Sí, la renta incluye mobiliario (a partir de 60 personas).";
        }
        if (str_contains($t, 'mínimo') || str_contains($t, 'minimo')) {
            return "El mínimo sugerido para eventos en nuestro local es de 50 personas.";
        }
        if (str_contains($t, 'banquete')) {
            return "Manejamos un menú para bodas y otros tipos de eventos (desde taquizas hasta paella mixta).";
        }
        if (str_contains($t, 'generador') || str_contains($t, 'planta')) {
            return "Sí, contamos con generador eléctrico en la mayoría de los paquetes de 100 personas en adelante.";
        }
        if (str_contains($t, 'mascota')) {
            return "Dependiendo del caso se permiten mascotas; nos reservamos el derecho.";
        }
        if (str_contains($t, 'caballo')) {
            return "No permitimos caballos en el local.";
        }
        if (str_contains($t, 'cocina')) {
            return "Sí, contamos con una cocina equipada con equipos de gas y refrigeración.";
        }
        if ((str_contains($t, 'llevar') && str_contains($t, 'banquete')) || str_contains($t, 'chef')) {
            return "En algunos casos podemos descontar el banquete y usted puede llevar su propio chef y utilizar nuestra cocina.";
        }
        if ((str_contains($t, 'qué incluye') || str_contains($t, 'incluye')) && str_contains($t, 'local')) {
            return "La renta del local incluye nuestro personal de staff y supervisor.";
        }
        if (str_contains($t, 'hora extra')) {
            return "La hora extra incluye la renta del local y nuestro personal de staff; no incluye meseros ni cantineros.";
        }
        if (str_contains($t, 'pago') && (str_contains($t, 'plazo') || str_contains($t, 'pagos'))) {
            return "Manejamos un sistema de pagos cómodos; el evento debe quedar liquidado 30 días antes.";
        }

        // Preguntas sobre visitas o disponibilidad de fechas
        if (str_contains($t, 'visita') || str_contains($t, 'cita') || str_contains($t, 'disponib') || str_contains($t, 'agenda')) {
            return "Las visitas y agenda de fechas se coordinan directamente con un asesor. Con gusto puedo responder tus dudas generales y recabar la información de tu evento para canalizarte.";
        }

        return null;
    }
}