<?php
// Auto-fixed: 2026-02-17 01:57:21
require_once '../../../../includes/config.php';
// limpiar_seleccion.php
session_start();

// Limpiar los productos seleccionados
if (isset($_SESSION['productos_etiquetas'])) {
    unset($_SESSION['productos_etiquetas']);
}

// También limpiar las cantidades si existen
if (isset($_SESSION['cantidades_productos'])) {
    unset($_SESSION['cantidades_productos']);
}

// Redirigir de vuelta a la página principal
header('Location: ' . (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php'));
exit();
?>