<?php
// Auto-fixed: 2026-02-17 01:57:21
require_once 'includes/config.php';
/**
 * Mostrar estructura de carpetas y archivos del proyecto
 * Autor: ChatGPT
 */

function mostrarEstructura($ruta, $prefijo = "")
{
    // Obtener elementos dentro de la carpeta
    $elementos = scandir($ruta);
    $elementos = array_diff($elementos, ['.', '..']); // Limpiar

    foreach ($elementos as $i => $elemento) {

        $rutaCompleta = $ruta . DIRECTORY_SEPARATOR . $elemento;
        $esUltimo = ($i === array_key_last($elementos));

        // Dibujar líneas del árbol
        echo $prefijo . ($esUltimo ? "└── " : "├── ") . $elemento . "<br>";

        // Si es carpeta, entrar recursivamente
        if (is_dir($rutaCompleta)) {
            mostrarEstructura(
                $rutaCompleta,
                $prefijo . ($esUltimo ? "    " : "│   ")
            );
        }
    }
}

// Ruta base (carpeta del proyecto)
$rutaProyecto = __DIR__;

echo "<h2>Estructura del Proyecto</h2>";
echo "<pre>";
mostrarEstructura($rutaProyecto);
echo "</pre>";
