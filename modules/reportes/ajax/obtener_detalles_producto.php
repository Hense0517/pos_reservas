<?php
// Auto-fixed: 2026-02-17 01:57:21
require_once '../../../../includes/config.php';
// modules/reportes/ajax/obtener_detalles_producto.php

// DEPURACIÓN EXTENSA
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Iniciar sesión SIEMPRE al principio
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// HEADER JSON PRIMERO
header('Content-Type: application/json; charset=UTF-8');

// Buffer para capturar cualquier output no deseado
ob_start();

try {
    // Verificar si tenemos ID
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        throw new Exception('ID de producto no válido o no proporcionado');
    }
    
    $producto_id = intval($_GET['id']);
    
    // ============================================
    // SIMULACIÓN DE DATOS (para prueba inicial)
    // ============================================
    $datos_simulados = [
        'id' => $producto_id,
        'codigo' => 'TEST-' . $producto_id,
        'nombre' => 'Producto de Prueba ' . $producto_id,
        'descripcion' => 'Este es un producto de prueba para verificar el funcionamiento del sistema.',
        'precio_compra' => 5000.00,
        'precio_venta' => 8500.00,
        'stock' => 25,
        'stock_minimo' => 5,
        'talla' => 'M',
        'color' => 'Negro',
        'categoria_nombre' => 'Pruebas',
        'marca_nombre' => 'Test Brand',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    // Calcular valores derivados
    $datos_simulados['valor_inventario'] = $datos_simulados['stock'] * $datos_simulados['precio_venta'];
    $datos_simulados['costo_inventario'] = $datos_simulados['stock'] * $datos_simulados['precio_compra'];
    $datos_simulados['margen_utilidad'] = $datos_simulados['precio_compra'] > 0 ? 
        round((($datos_simulados['precio_venta'] - $datos_simulados['precio_compra']) / 
              $datos_simulados['precio_compra']) * 100, 2) : 0;
    
    // Determinar estado del stock
    if ($datos_simulados['stock'] == 0) {
        $datos_simulados['estado_stock'] = 'agotado';
        $datos_simulados['estado_stock_texto'] = 'Agotado';
        $datos_simulados['estado_stock_color'] = 'text-red-600';
        $datos_simulados['estado_stock_bg'] = 'bg-red-100';
    } elseif ($datos_simulados['stock'] <= $datos_simulados['stock_minimo']) {
        $datos_simulados['estado_stock'] = 'bajo';
        $datos_simulados['estado_stock_texto'] = 'Bajo';
        $datos_simulados['estado_stock_color'] = 'text-yellow-600';
        $datos_simulados['estado_stock_bg'] = 'bg-yellow-100';
    } else {
        $datos_simulados['estado_stock'] = 'normal';
        $datos_simulados['estado_stock_texto'] = 'Normal';
        $datos_simulados['estado_stock_color'] = 'text-green-600';
        $datos_simulados['estado_stock_bg'] = 'bg-green-100';
    }
    
    // Preparar respuesta
    $response = [
        'success' => true,
        'data' => $datos_simulados,
        'debug' => [
            'producto_id_solicitado' => $producto_id,
            'timestamp' => date('Y-m-d H:i:s'),
            'session_active' => isset($_SESSION) ? 'Sí' : 'No',
            'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB'
        ]
    ];
    
} catch (Exception $e) {
    // Manejo de errores
    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'debug' => [
            'timestamp' => date('Y-m-d H:i:s'),
            'php_version' => PHP_VERSION,
            'session_status' => session_status(),
            'get_params' => $_GET
        ]
    ];
}

// Limpiar cualquier output no deseado
ob_clean();

// Enviar respuesta JSON
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Terminar script
exit;
?>