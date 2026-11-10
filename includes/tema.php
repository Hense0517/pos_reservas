<?php
/**
 * includes/tema.php — Paletas de colores del sistema
 *
 * Una sola fuente de verdad para los colores. La paleta activa se guarda en
 * configuracion_negocio.paleta_colores (ver migrations/2026-11-paleta-colores.sql) y se
 * aplica a todo el proyecto de dos formas:
 *
 *  1. Variables CSS (--tema-*) para los componentes nuevos (header, sidebar, login).
 *  2. Se redefinen las variables de color de Tailwind v4 (--color-blue-*, --color-indigo-*,
 *     --color-violet-*, --color-purple-*), de modo que TODAS las clases bg-blue-600,
 *     text-indigo-700, focus:ring-blue-500, etc. que ya existen en los ~70 archivos del
 *     proyecto cambian de color sin tocarlos uno por uno.
 *     Los colores semánticos (verde = éxito, rojo = error, amarillo = aviso) NO se tocan.
 *
 * Para páginas que NO usan Tailwind v4 (Tailwind 2/3 con colores fijos) se genera además una
 * capa de compatibilidad: tema_head(true).
 */

const TEMA_PALETA_DEFECTO = 'rosa_glamour';

/** Catálogo de paletas: id => [nombre, descripcion, primario, acento, oscuro] */
function tema_paletas(): array
{
    return [
        'rosa_glamour'   => ['nombre' => 'Rosa Glamour',   'descripcion' => 'Femenino y vibrante, ideal para salones de belleza', 'primario' => '#db2777', 'acento' => '#f59e0b', 'oscuro' => '#3b0a24'],
        'oro_negro'      => ['nombre' => 'Negro & Oro',    'descripcion' => 'Elegante y premium, estilo barbería de lujo',        'primario' => '#a8730a', 'acento' => '#1f2937', 'oscuro' => '#12100d'],
        'lavanda_spa'    => ['nombre' => 'Lavanda Spa',    'descripcion' => 'Relajante y moderno, aire de spa',                   'primario' => '#7c3aed', 'acento' => '#ec4899', 'oscuro' => '#26104f'],
        'verde_botanico' => ['nombre' => 'Verde Botánico', 'descripcion' => 'Natural y fresco, productos orgánicos',              'primario' => '#059669', 'acento' => '#c2955a', 'oscuro' => '#052e26'],
        'azul_oceano'    => ['nombre' => 'Azul Océano',    'descripcion' => 'Clásico y confiable (el tono original del sistema)', 'primario' => '#2563eb', 'acento' => '#06b6d4', 'oscuro' => '#0b1b3a'],
        'coral_atardecer'=> ['nombre' => 'Coral Atardecer','descripcion' => 'Cálido y energético',                                'primario' => '#ea580c', 'acento' => '#db2777', 'oscuro' => '#3a1204'],
        'vino_borgona'   => ['nombre' => 'Vino Borgoña',   'descripcion' => 'Sofisticado, para color y tratamientos premium',     'primario' => '#9f1239', 'acento' => '#d97706', 'oscuro' => '#34091a'],
        'grafito_moderno'=> ['nombre' => 'Grafito Moderno','descripcion' => 'Sobrio y minimalista',                               'primario' => '#475569', 'acento' => '#14b8a6', 'oscuro' => '#0f172a'],
    ];
}

/** Mezcla dos colores hex. $p = proporción (0..1) del color $b dentro del resultado. */
function tema_mezclar(string $a, string $b, float $p): string
{
    $a = ltrim($a, '#');
    $b = ltrim($b, '#');
    $out = '#';
    for ($i = 0; $i < 3; $i++) {
        $ca = hexdec(substr($a, $i * 2, 2));
        $cb = hexdec(substr($b, $i * 2, 2));
        $out .= str_pad(dechex((int) round($ca + ($cb - $ca) * $p)), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

/** Escala 50..950 a partir de un color base (que queda como el tono 600). */
function tema_escala(string $base): array
{
    return [
        50  => tema_mezclar($base, '#ffffff', 0.93),
        100 => tema_mezclar($base, '#ffffff', 0.85),
        200 => tema_mezclar($base, '#ffffff', 0.70),
        300 => tema_mezclar($base, '#ffffff', 0.52),
        400 => tema_mezclar($base, '#ffffff', 0.30),
        500 => tema_mezclar($base, '#ffffff', 0.12),
        600 => $base,
        700 => tema_mezclar($base, '#000000', 0.16),
        800 => tema_mezclar($base, '#000000', 0.30),
        900 => tema_mezclar($base, '#000000', 0.44),
        950 => tema_mezclar($base, '#000000', 0.60),
    ];
}

/**
 * Id de la paleta activa.
 * Usa $config global si ya trae paleta_colores; si no (config.php carga columnas fijas, o es una
 * página independiente como el login), hace una consulta mínima. Si la columna aún no existe
 * (falta ejecutar migrations/2026-11-paleta-colores.sql) se usa la paleta por defecto.
 */
function tema_paleta_id(): string
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }

    $candidato = null;
    if (isset($GLOBALS['config']) && is_array($GLOBALS['config']) && !empty($GLOBALS['config']['paleta_colores'])) {
        $candidato = $GLOBALS['config']['paleta_colores'];
    } else {
        try {
            if (!class_exists('Database')) {
                require_once __DIR__ . '/../config/database.php';
            }
            $pdo = Database::getInstance()->getConnection();
            $candidato = $pdo->query("SELECT paleta_colores FROM configuracion_negocio ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null;
        } catch (Throwable $e) {
            $candidato = null; // columna inexistente o BD no disponible: paleta por defecto
        }
    }

    $paletas = tema_paletas();
    $id = ($candidato && isset($paletas[$candidato])) ? $candidato : TEMA_PALETA_DEFECTO;
    return $id;
}

/** Variables CSS de una paleta como arreglo nombre => valor (útil para la vista previa en JS). */
function tema_variables(string $id): array
{
    $paletas = tema_paletas();
    $p = $paletas[$id] ?? $paletas[TEMA_PALETA_DEFECTO];
    $prim = tema_escala($p['primario']);
    $acen = tema_escala($p['acento']);

    $vars = [];
    foreach ($prim as $tono => $hex) {
        $vars["--tema-$tono"] = $hex;
        // Tailwind v4: todo lo "blue" e "indigo" pasa a ser el color primario
        $vars["--color-blue-$tono"]   = $hex;
        $vars["--color-indigo-$tono"] = $hex;
    }
    foreach ($acen as $tono => $hex) {
        $vars["--tema-acento-$tono"] = $hex;
        // "violet", "purple" y "fuchsia" pasan a ser el color de acento
        $vars["--color-violet-$tono"]  = $hex;
        $vars["--color-purple-$tono"]  = $hex;
        $vars["--color-fuchsia-$tono"] = $hex;
    }
    $vars['--tema-oscuro']   = $p['oscuro'];
    $vars['--tema-oscuro-2'] = tema_mezclar($p['oscuro'], '#ffffff', 0.10);
    $vars['--tema-oscuro-3'] = tema_mezclar($p['oscuro'], '#ffffff', 0.18);
    $vars['--tema-primario'] = $p['primario'];
    $vars['--tema-acento']   = $p['acento'];
    $vars['--color-primary']   = $p['primario'];
    $vars['--color-secondary'] = $p['acento'];
    return $vars;
}

/** Capa de compatibilidad para páginas con Tailwind 2/3 (colores fijos, sin variables). */
function tema_css_compat(): string
{
    $tonos = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];
    $familias = ['blue' => 'tema', 'indigo' => 'tema', 'violet' => 'tema-acento', 'purple' => 'tema-acento'];
    $css = '';
    foreach ($familias as $fam => $prefijo) {
        foreach ($tonos as $t) {
            $v = "var(--$prefijo-$t)";
            $css .= ".bg-$fam-$t{background-color:$v!important}"
                 . ".text-$fam-$t{color:$v!important}"
                 . ".border-$fam-$t{border-color:$v!important}"
                 . ".hover\\:bg-$fam-$t:hover{background-color:$v!important}"
                 . ".hover\\:text-$fam-$t:hover{color:$v!important}"
                 . ".hover\\:border-$fam-$t:hover{border-color:$v!important}"
                 . ".focus\\:border-$fam-$t:focus{border-color:$v!important}"
                 . ".focus\\:ring-$fam-$t:focus{--tw-ring-color:$v!important}"
                 . ".ring-$fam-$t{--tw-ring-color:$v!important}"
                 . ".from-$fam-$t{--tw-gradient-from:$v!important}"
                 . ".to-$fam-$t{--tw-gradient-to:$v!important}";
        }
    }
    return $css;
}

/** Capa de compatibilidad para páginas con Bootstrap 5 (botones, enlaces y foco "primary"). */
function tema_css_bootstrap(): string
{
    return '.btn-primary{background-color:var(--tema-600)!important;border-color:var(--tema-600)!important;color:#fff!important}'
        . '.btn-primary:hover,.btn-primary:focus,.btn-primary:active{background-color:var(--tema-700)!important;border-color:var(--tema-700)!important}'
        . '.btn-outline-primary{color:var(--tema-600)!important;border-color:var(--tema-600)!important;background:transparent!important}'
        . '.btn-outline-primary:hover{background-color:var(--tema-600)!important;color:#fff!important}'
        . '.text-primary{color:var(--tema-600)!important}.bg-primary{background-color:var(--tema-600)!important}.border-primary{border-color:var(--tema-600)!important}'
        . 'a{color:var(--tema-600)}a:hover{color:var(--tema-800)}'
        . '.form-control:focus,.form-select:focus{border-color:var(--tema-400)!important;box-shadow:0 0 0 .25rem var(--tema-100)!important}'
        . '.page-item.active .page-link{background-color:var(--tema-600)!important;border-color:var(--tema-600)!important}'
        . '.form-check-input:checked{background-color:var(--tema-600)!important;border-color:var(--tema-600)!important}';
}

/**
 * Bloque <style> con la paleta activa. Colócalo en el <head> DESPUÉS de cargar Tailwind/Bootstrap.
 * $compat    = true para páginas con Tailwind 2/3 (colores fijos).
 * $bootstrap = true para páginas con Bootstrap 5.
 */
function tema_head(bool $compat = false, bool $bootstrap = false): string
{
    $vars = tema_variables(tema_paleta_id());
    $css = ':root{';
    foreach ($vars as $nombre => $valor) {
        $css .= $nombre . ':' . $valor . ';';
    }
    $css .= '}';
    if ($compat) {
        $css .= tema_css_compat();
    }
    if ($bootstrap) {
        $css .= tema_css_bootstrap();
    }
    return '<style id="tema-css">' . $css . '</style>' . "\n";
}

/** ¿Existe ya la columna paleta_colores en la BD? (solo lectura, no modifica nada) */
function tema_columna_existe(PDO $pdo): bool
{
    try {
        return (bool) $pdo->query("SHOW COLUMNS FROM configuracion_negocio LIKE 'paleta_colores'")->fetch();
    } catch (Throwable $e) {
        return false;
    }
}
