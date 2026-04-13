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

        // =========================
        // FACTURACIÓN / IVA
        // =========================
        if ($this->containsAny($t, ['factura', 'facturacion', 'facturan', 'iva'])) {
            return "Sí, manejamos factura y los precios indicados son más IVA.";
        }

        // =========================
        // PAQUETES A LA MEDIDA
        // =========================
        if (
            $this->containsAny($t, [
                'a la medida',
                'personalizado',
                'personalizada',
                'personalizar',
                'cotizacion especial',
                'cotizacion personalizada',
                'paquete especial'
            ])
        ) {
            return "También manejamos paquetes a la medida. Para eso lo ideal es compartir tus datos y un asesor te contacta para preparar una propuesta.";
        }

        // =========================
        // LOS ADOBES
        // =========================
        if ($this->containsAny($t, ['adobes', 'los adobes'])) {
            if ($this->containsAny($t, ['precio', 'cuesta', 'costo', 'vale'])) {
                return "Paquete Los Adobes 2026 (Lun-Juev):\n"
                    . "• Con banquete: \$648 M.N. por persona\n"
                    . "• Sin banquete: \$548 M.N. por persona\n"
                    . "Mínimo: 60 personas.";
            }

            if ($this->containsAny($t, ['incluye', 'que incluye', 'incluye que'])) {
                return "Paquete Los Adobes 2026 (Lun-Juev), ideal para reuniones empresariales, showers, etc. Incluye:\n"
                    . "• Renta del salón Los Adobes por 5 horas\n"
                    . "• Completamente refrigerado\n"
                    . "• Supervisor durante el evento\n"
                    . "• Servicio de meseros y cantineros\n"
                    . "• Baños refrigerados\n"
                    . "• Personal de mantenimiento\n"
                    . "• 2 hieleras para área de barras\n"
                    . "• Mesas redondas y rectangulares de madera\n"
                    . "• Sillas de madera Crossback\n"
                    . "• Mantelería y servilletas de tela\n"
                    . "• Descorche ilimitado por 5 horas (sodas, agua mineral, sal, hielos y vasos highball)\n"
                    . "• Personal para descorche\n"
                    . "• Mesa para postres o snacks\n"
                    . "• Limpieza local antes y después del evento\n"
                    . "• Banquete a elegir (Pasta, Lasagna, Paella o Pastel de Elote), incluye loza, plaqué y portaplatos\n"
                    . "• Mínimo 60 personas";
            }

            if ($this->containsAny($t, ['lun', 'lunes', 'martes', 'miercoles', 'miércoles', 'jueves', 'entre semana'])) {
                return "El paquete Los Adobes 2026 aplica de lunes a jueves.";
            }

            return "Manejamos el Paquete Los Adobes 2026 para eventos de lunes a jueves, con mínimo de 60 personas. Si quieres, te comparto qué incluye o su precio por persona.";
        }

        // =========================
        // PAQUETE PRIMAVERA
        // =========================
        if ($this->containsAny($t, ['primavera'])) {
            if ($this->containsAny($t, ['precio 2026', '2026', 'cuesta 2026', 'costo 2026'])) {
                return "Paquete Primavera 2026:\n"
                    . "• Con banquete: \$898 M.N. + IVA por persona\n"
                    . "• Sin banquete: \$815 M.N. + IVA por persona";
            }

            if ($this->containsAny($t, ['precio 2027', '2027', 'cuesta 2027', 'costo 2027'])) {
                return "Paquete Primavera 2027:\n"
                    . "• Con banquete: \$970 M.N. + IVA por persona\n"
                    . "• Sin banquete: \$890 M.N. + IVA por persona";
            }

            if ($this->containsAny($t, ['precio', 'cuesta', 'costo', 'vale'])) {
                return "Paquete Primavera:\n"
                    . "2026\n"
                    . "• Con banquete: \$898 M.N. + IVA por persona\n"
                    . "• Sin banquete: \$815 M.N. + IVA por persona\n\n"
                    . "2027\n"
                    . "• Con banquete: \$970 M.N. + IVA por persona\n"
                    . "• Sin banquete: \$890 M.N. + IVA por persona";
            }

            if ($this->containsAny($t, ['platillo', 'platillos', 'menu', 'menú', 'comida', 'banquete'])) {
                return "Opciones de platillos del Paquete Primavera:\n"
                    . "1. Pollo en Salsa Alfredos con champiñones y Ensalada del Chef, Pan de Ajo\n"
                    . "2. Pasta Fetuccini a la mantequilla y hierbas y Ensalada del Chef, Pan de Ajo\n"
                    . "3. Lasagna de res y Ensalada mediterránea, Pan de Ajo\n"
                    . "4. Paella Mixta (camarón, cerdo, pollo) y Pan\n"
                    . "5. Pastel de Elote bañado en crema de chile verde y frijoles\n\n"
                    . "Incluye loza y plaqué.\n"
                    . "Mínimo: 60 personas.";
            }

            if ($this->containsAny($t, ['minimo', 'mínimo', 'personas'])) {
                return "El Paquete Primavera maneja un mínimo de 60 personas.";
            }

            if ($this->containsAny($t, ['incluye', 'que incluye', 'incluye que'])) {
                return "Paquete Primavera incluye:\n"
                    . "• Renta de nuestro Jardín y áreas exteriores por 5 horas\n"
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
                    . "• Iluminación arquitectónica y de áreas de jardín\n"
                    . "• Limpieza local antes y después del evento\n"
                    . "• Banquete a elegir\n"
                    . "• Mínimo 60 personas";
            }

            return "Manejamos el Paquete Primavera con opción con banquete o sin banquete, para 2026 y 2027. Si quieres, te comparto precio, inclusiones o platillos.";
        }

        // =========================
        // FULL INCLUSIVE
        // =========================
        if (
            $this->containsAny($t, [
                'full inclusive',
                'full',
                'inclusive'
            ])
        ) {
            if ($this->containsAny($t, ['precio 2026', '2026', 'cuesta 2026', 'costo 2026'])) {
                return "Paquete Full Inclusive 2026: \$998 M.N. + IVA por persona.";
            }

            if ($this->containsAny($t, ['precio 2027', '2027', 'cuesta 2027', 'costo 2027'])) {
                return "Paquete Full Inclusive 2027: \$1,070 M.N. + IVA por persona.";
            }

            if ($this->containsAny($t, ['precio', 'cuesta', 'costo', 'vale'])) {
                return "Paquete Full Inclusive:\n"
                    . "• 2026: \$998 M.N. + IVA por persona\n"
                    . "• 2027: \$1,070 M.N. + IVA por persona";
            }

            if ($this->containsAny($t, ['incluye', 'que incluye', 'incluye que'])) {
                return "Paquete Full Inclusive incluye:\n"
                    . "• Renta de nuestro Jardín y áreas exteriores por 5 horas\n"
                    . "• Supervisor durante el evento\n"
                    . "• Servicio de Meseros, Capitán y Cantineros\n"
                    . "• Personal en estacionamiento\n"
                    . "• Baños Refrigerados con personal incluido\n"
                    . "• Personal de Mantenimiento\n"
                    . "• 1 hora de cortesía durante el evento (no incluye servicio)\n"
                    . "• Uso de instalaciones para sesión fotográfica (máx. 20 personas)\n"
                    . "• Área de cocina equipada con servicios incluidos (Gas, Agua, Luz)\n"
                    . "• Área de barra para servicio de meseros\n"
                    . "• Sala privada con baño (servibar, tv, internet)\n"
                    . "• 2 hieleras para área de barras\n"
                    . "• Bodega para resguardo de licor\n"
                    . "• Mesas redondas y cuadradas de madera\n"
                    . "• Sillas madera Cross back\n"
                    . "• Mantelería y Servilletas de Tela\n"
                    . "• Mesa para postres o snacks\n"
                    . "• Mesa para novios con sillas de lujo\n"
                    . "• Descorche ilimitado por 5 horas (sodas, agua mineral, sal, hielos)\n"
                    . "• Cristalería (vasos highball, copas vino, tequileros, copas champagne, paneras, ceniceros)\n"
                    . "• Servicio de Banquete 1 tiempo (Medallón de Cerdo ó Filete de Pechuga + 2 guarniciones)\n"
                    . "• Degustación de menú (máx. 4 personas)\n"
                    . "• Servicio de loza y plaqué\n"
                    . "• Iluminación arquitectónica y de áreas de jardín\n"
                    . "• Generador de 40 kva\n"
                    . "• Limpieza local antes y después del evento";
            }

            if ($this->containsAny($t, ['degustacion', 'degustación'])) {
                return "El Paquete Full Inclusive incluye degustación de menú para máximo 4 personas.";
            }

            if ($this->containsAny($t, ['sesion', 'sesión', 'fotografica', 'fotográfica'])) {
                return "El Paquete Full Inclusive incluye uso de instalaciones para sesión fotográfica con máximo 20 personas.";
            }

            return "Manejamos el Paquete Full Inclusive para 2026 y 2027. Si quieres, te comparto precio o todo lo que incluye.";
        }

        // =========================
        // PREGUNTAS GENERALES SOBRE PRECIOS DE PAQUETES
        // =========================
        if ($this->containsAny($t, ['paquetes', 'paquete']) && $this->containsAny($t, ['precio', 'precios', 'cuesta', 'costo'])) {
            return "Manejamos estas opciones:\n\n"
                . "Paquete Primavera\n"
                . "• 2026: \$898 + IVA con banquete / \$815 + IVA sin banquete\n"
                . "• 2027: \$970 + IVA con banquete / \$890 + IVA sin banquete\n\n"
                . "Paquete Full Inclusive\n"
                . "• 2026: \$998 + IVA por persona\n"
                . "• 2027: \$1,070 + IVA por persona\n\n"
                . "Paquete Los Adobes 2026 (Lun-Juev)\n"
                . "• \$648 por persona con banquete\n"
                . "• \$548 por persona sin banquete\n\n"
                . "También manejamos paquetes a la medida con asesor.";
        }

        // =========================
        // PREGUNTAS GENERALES SOBRE OPCIONES
        // =========================
        if ($this->containsAny($t, ['que paquetes manejan', 'qué paquetes manejan', 'opciones de paquetes', 'tipos de paquetes'])) {
            return "Manejamos:\n"
                . "• Paquete Primavera\n"
                . "• Paquete Full Inclusive\n"
                . "• Paquete Los Adobes 2026 (Lun-Juev)\n"
                . "• Paquetes a la medida";
        }

        // =========================
        // SI NO HAY RESPUESTA CONFIABLE
        // =========================
        return null;
    }

    /**
     * Intenta detectar intención usando coincidencias flexibles,
     * pero no genera datos nuevos.
     */
    public function canAnswer(string $text): bool
    {
        return $this->match($text) !== null;
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