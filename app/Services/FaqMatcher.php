<?php

namespace App\Services;

class FaqMatcher
{
    /**
     * Responde únicamente con información cerrada y validada.
     * Si no encuentra una respuesta confiable, devuelve null.
     */
    public function match(string $text): ?string
    {
        $t = $this->normalize($text);

        if ($this->containsAny($t, ['factura', 'facturacion', 'facturan', 'iva'])) {
            return "Sí, manejamos factura y los precios indicados son más IVA.";
        }

        if ($this->containsAny($t, [
            'a la medida',
            'personalizado',
            'personalizada',
            'personalizar',
            'cotizacion especial',
            'cotizacion personalizada',
            'paquete especial'
        ])) {
            return "También manejamos paquetes a la medida. Para eso lo ideal es compartir tus datos y un asesor te contacta para preparar una propuesta.";
        }

        if ($this->containsAny($t, ['adobes', 'los adobes'])) {
            if ($this->containsAny($t, ['precio', 'precios', 'cuesta', 'costo', 'vale', 'incluye', 'que incluye', 'incluye que', 'minimo', 'mínimo', 'personas'])) {
                return $this->losAdobesSummary();
            }

            if ($this->containsAny($t, ['lun', 'lunes', 'martes', 'miercoles', 'miércoles', 'jueves', 'entre semana'])) {
                return "El Paquete Los Adobes 2026 aplica de lunes a jueves.\n\n" . $this->losAdobesSummary();
            }

            return $this->losAdobesSummary();
        }

        if ($this->containsAny($t, ['primavera'])) {
            if ($this->containsAny($t, ['platillo', 'platillos', 'menu', 'menú', 'comida', 'banquete'])) {
                return "Opciones de platillos del Paquete Primavera:\n"
                    . "1. Pollo en Salsa Alfredos con champiñones y Ensalada del Chef, Pan de Ajo\n"
                    . "2. Pasta Fetuccini a la mantequilla y hierbas y Ensalada del Chef, Pan de Ajo\n"
                    . "3. Lasagna de res y Ensalada mediterránea, Pan de Ajo\n"
                    . "4. Paella Mixta (camarón, cerdo, pollo) y Pan\n"
                    . "5. Pastel de Elote bañado en crema de chile verde y frijoles\n\n"
                    . "Incluye loza y plaqué.\n"
                    . "Mínimo sugerido: 50 personas.";
            }

            return $this->primaveraSummary();
        }

        if ($this->containsAny($t, ['full inclusive', 'full', 'inclusive'])) {
            if ($this->containsAny($t, ['degustacion', 'degustación'])) {
                return "El Paquete Full Inclusive incluye degustación de menú para máximo 4 personas.\n\n" . $this->fullInclusiveSummary();
            }

            if ($this->containsAny($t, ['sesion', 'sesión', 'fotografica', 'fotográfica'])) {
                return "El Paquete Full Inclusive incluye uso de instalaciones para sesión fotográfica con máximo 20 personas.\n\n" . $this->fullInclusiveSummary();
            }

            return $this->fullInclusiveSummary();
        }

        if ($this->containsAny($t, ['paquetes', 'paquete']) && $this->containsAny($t, ['precio', 'precios', 'cuesta', 'costo', 'incluye', 'mínimo', 'minimo', 'personas'])) {
            return "Manejamos estas opciones:\n\n"
                . "Paquete Primavera\n"
                . "• 2026: $898 + IVA con banquete / $815 + IVA sin banquete\n"
                . "• 2027: $970 + IVA con banquete / $890 + IVA sin banquete\n"
                . "• Mínimo sugerido: 50 personas\n\n"
                . "Paquete Full Inclusive\n"
                . "• 2026: $998 + IVA por persona\n"
                . "• 2027: $1,070 + IVA por persona\n"
                . "• Mínimo sugerido: 100 personas\n\n"
                . "Paquete Los Adobes 2026 (Lun-Juev)\n"
                . "• $648 por persona con banquete\n"
                . "• $548 por persona sin banquete\n"
                . "• Mínimo sugerido: 50 personas\n\n"
                . "También manejamos paquetes a la medida con asesor.";
        }

        if ($this->containsAny($t, ['que paquetes manejan', 'qué paquetes manejan', 'opciones de paquetes', 'tipos de paquetes', 'paquetes'])) {
            return "Manejamos:\n"
                . "• Paquete Primavera — desde $815 + IVA por persona, mínimo sugerido 50 personas\n"
                . "• Paquete Full Inclusive — desde $998 + IVA por persona, mínimo sugerido 100 personas\n"
                . "• Paquete Los Adobes 2026 (Lun-Juev) — desde $548 por persona, mínimo sugerido 50 personas\n"
                . "• Paquetes a la medida";
        }

        return null;
    }

    public function canAnswer(string $text): bool
    {
        return $this->match($text) !== null;
    }

    private function primaveraSummary(): string
    {
        return "Paquete Primavera\n\n"
            . "Costo por persona:\n"
            . "2026\n"
            . "• Con banquete: $898 M.N. + IVA\n"
            . "• Sin banquete: $815 M.N. + IVA\n\n"
            . "2027\n"
            . "• Con banquete: $970 M.N. + IVA\n"
            . "• Sin banquete: $890 M.N. + IVA\n\n"
            . "Mínimo sugerido: 50 personas.\n\n"
            . "Incluye:\n"
            . "• Renta de Jardín y áreas exteriores por 5 horas\n"
            . "• Supervisor durante el evento\n"
            . "• Servicio de meseros y cantineros\n"
            . "• Personal en estacionamiento\n"
            . "• Baños refrigerados con personal incluido\n"
            . "• Personal de mantenimiento\n"
            . "• Área de cocina equipada con servicios incluidos (Gas, Agua, Luz)\n"
            . "• Área de barra para servicio de meseros\n"
            . "• Sala privada con baño (servibar, tv, internet)\n"
            . "• 2 hieleras para área de barras\n"
            . "• Bodega para resguardo de licor\n"
            . "• Mesas redondas y rectangulares de madera\n"
            . "• Sillas de madera Crossback\n"
            . "• Mantelería y servilletas de tela\n"
            . "• Descorche ilimitado por 5 horas (sodas, agua mineral, sal, hielos y vasos highball)\n"
            . "• Mesa para postres o snacks\n"
            . "• Iluminación arquitectónica y áreas de jardín\n"
            . "• Limpieza local antes y después del evento\n"
            . "• Banquete a elegir, incluye loza, plaqué y portaplatos";
    }

    private function fullInclusiveSummary(): string
    {
        return "Paquete Full Inclusive\n\n"
            . "Costo por persona:\n"
            . "• 2026: $998 M.N. + IVA\n"
            . "• 2027: $1,070 M.N. + IVA\n\n"
            . "Mínimo sugerido: 100 personas.\n\n"
            . "Incluye:\n"
            . "• Renta de Jardín y áreas exteriores por 5 horas\n"
            . "• Supervisor durante el evento\n"
            . "• Servicio de Meseros, Capitán y Cantineros\n"
            . "• Personal en estacionamiento\n"
            . "• Baños Refrigerados con personal incluido\n"
            . "• Personal de Mantenimiento\n"
            . "• 1 hora de cortesía durante el evento (no incluye servicio)\n"
            . "• Uso de instalaciones para sesión fotográfica (máx. 20 personas)\n"
            . "• Área de cocina equipada con servicios incluidos (Gas, Agua, Luz)\n"
            . "• Área de barra\n"
            . "• Sala privada con baño (servibar, tv, internet)\n"
            . "• 2 hieleras para área de barras\n"
            . "• Bodega para resguardo de licor\n"
            . "• Mesas redondas y cuadradas de madera\n"
            . "• Sillas madera Cross back\n"
            . "• Mantelería y Servilletas de Tela\n"
            . "• Mesa para postres o snacks\n"
            . "• Mesa para novios con Sillas de Lujo\n"
            . "• Descorche ilimitado por 5 horas (sodas, agua mineral, sal, hielos)\n"
            . "• Cristalería\n"
            . "• Servicio de Banquete 1 tiempo\n"
            . "• Degustación de menú (máx. 4 personas)\n"
            . "• Servicio de Loza y plaqué\n"
            . "• Iluminación arquitectónica y áreas de jardín\n"
            . "• Generador de 40 kva\n"
            . "• Limpieza antes/después";
    }

    private function losAdobesSummary(): string
    {
        return "Paquete Los Adobes 2026 (Lun-Juev)\n\n"
            . "Costo por persona:\n"
            . "• Con banquete: $648 M.N.\n"
            . "• Sin banquete: $548 M.N.\n\n"
            . "Mínimo sugerido: 50 personas.\n\n"
            . "Incluye:\n"
            . "• Renta salón Los Adobes por 5 horas\n"
            . "• Completamente refrigerado\n"
            . "• Supervisor durante el evento\n"
            . "• Servicio de meseros y cantineros\n"
            . "• Baños refrigerados\n"
            . "• Personal de mantenimiento\n"
            . "• 2 hieleras para barras\n"
            . "• Mesas redondas y rectangulares de madera\n"
            . "• Sillas madera Crossback\n"
            . "• Mantelería y servilletas de tela\n"
            . "• Descorche ilimitado por 5 horas (sodas, agua mineral, sal, hielos y vasos highball)\n"
            . "• Personal para descorche\n"
            . "• Mesa para postres/snacks\n"
            . "• Limpieza local antes/después\n"
            . "• Banquete a elegir, incluye loza, plaqué y portaplatos";
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $this->normalize($needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');

        $replacements = [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ü' => 'u',
            'ñ' => 'n',
        ];

        $text = strtr($text, $replacements);
        $text = preg_replace('/[^\p{L}\p{N}\s\$\+\-\/]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
