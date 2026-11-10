<?php
// includes/recursos.php
// Estilos y scripts comunes del sistema (peluquería / reservas).
// Los colores dependen de la paleta elegida en Configuración (ver includes/tema.php).

require_once __DIR__ . '/tema.php';

/** Versión de un asset propio para que el navegador recargue el archivo cuando cambia. */
function asset_version(string $rutaRelativa): string
{
    $archivo = __DIR__ . '/../' . ltrim($rutaRelativa, '/');
    return is_file($archivo) ? (string) filemtime($archivo) : '1';
}

function estilos_base() {
    $css = 'assets/css/layout.css';
    $js  = 'assets/js/layout.js';

    return '
    <!-- Tipografías: Playfair Display (marca) + Montserrat (interfaz) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome para iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <!-- Tailwind CSS v4 - Play CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>

    <!-- Colores de los módulos (compatibilidad con clases existentes) -->
    <style type="text/tailwindcss">
        @theme {
            --color-success: #059669;
            --color-danger: #dc2626;
            --color-warning: #d97706;
            --color-info: #0891b2;
            --color-ventas: #f97316;
            --color-compras: #ec4899;
            --color-productos: #14b8a6;
            --color-clientes: #8b5cf6;
        }
    </style>

    <!-- Paleta de colores activa (se aplica a todas las clases blue/indigo/violet/purple) -->
    ' . tema_head() . '
    <!-- Estilos y comportamiento del layout -->
    <link rel="stylesheet" href="' . BASE_URL . $css . '?v=' . asset_version($css) . '">
    <script src="' . BASE_URL . $js . '?v=' . asset_version($js) . '" defer></script>
    ';
}

// El comportamiento del menú vive ahora en assets/js/layout.js (se carga desde estilos_base()).
// Se conserva la función para no romper los footers que la llaman.
function scripts_base() {
    return '';
}

// Función para colores personalizados del negocio
function colores_negocio($color = 'primary') {
    $colores = [
        'primary' => 'blue-600',
        'secondary' => 'violet-600',
        'success' => 'emerald-600',
        'danger' => 'red-600',
        'warning' => 'amber-600',
        'info' => 'cyan-600',
        'ventas' => 'orange-600',
        'compras' => 'pink-600',
        'productos' => 'teal-600',
        'clientes' => 'violet-600',
    ];
    
    return $colores[$color] ?? 'blue-600';
}

// Función para gradientes predefinidos
function gradiente($tipo = 'primary') {
    $gradientes = [
        'primary' => 'from-blue-600 to-violet-600',
        'ventas' => 'from-orange-600 to-amber-600',
        'compras' => 'from-pink-600 to-rose-600',
        'productos' => 'from-teal-600 to-emerald-600',
        'clientes' => 'from-violet-600 to-purple-600',
        'exito' => 'from-emerald-600 to-teal-600',
        'peligro' => 'from-red-600 to-rose-600',
    ];
    
    return $gradientes[$tipo] ?? 'from-blue-600 to-violet-600';
}

// Función para animaciones CSS
function animacion($tipo = 'fade') {
    $animaciones = [
        'fade' => 'transition-opacity duration-300',
        'slide' => 'transition-transform duration-300',
        'scale' => 'transition-transform duration-300 hover:scale-105',
        'shadow' => 'transition-shadow duration-300 hover:shadow-lg',
        'all' => 'transition-all duration-300 hover:shadow-lg hover:-translate-y-1',
    ];
    
    return $animaciones[$tipo] ?? 'transition-all duration-300';
}
?>