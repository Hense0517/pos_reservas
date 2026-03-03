<?php
// modules/ventas/buscar_producto.php - BUSCADOR INTELIGENTE CON FILTRO POR CATEGORÍA
session_start();
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$categoria_id = isset($_GET['categoria_id']) ? intval($_GET['categoria_id']) : 0;

if (strlen($q) < 2 && $categoria_id == 0) {
    echo json_encode([]);
    exit;
}

try {
    $categorias = [];
    $productos = [];
    
    // SOLO buscar categorías si NO estamos filtrando por una categoría específica
    if ($categoria_id == 0 && strlen($q) >= 2) {
        // Buscar categorías que coincidan con el término
        $queryCategorias = "SELECT id, nombre FROM categorias 
                            WHERE activo = 1 AND nombre LIKE ? 
                            ORDER BY nombre LIMIT 5";
        
        $searchTerm = "%$q%";
        $stmtCategorias = $db->prepare($queryCategorias);
        $stmtCategorias->execute([$searchTerm]);
        $categorias = $stmtCategorias->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Construir consulta de productos
    $queryProductos = "SELECT p.id, p.nombre, p.descripcion, p.codigo, p.codigo_barras, 
                              p.precio_venta, p.stock, p.talla, p.color,
                              m.nombre as marca_nombre, m.id as marca_id,
                              c.nombre as categoria_nombre, c.id as categoria_id
                       FROM productos p 
                       LEFT JOIN marcas m ON p.marca_id = m.id 
                       LEFT JOIN categorias c ON p.categoria_id = c.id
                       WHERE p.activo = 1";
    
    $params = [];
    
    // Si hay filtro por categoría específica
    if ($categoria_id > 0) {
        $queryProductos .= " AND p.categoria_id = ?";
        $params[] = $categoria_id;
    }
    
    // Si hay término de búsqueda
    if (strlen($q) >= 2) {
        $queryProductos .= " AND (p.nombre LIKE ? 
                              OR p.codigo LIKE ? 
                              OR p.codigo_barras LIKE ? 
                              OR p.descripcion LIKE ?
                              OR c.nombre LIKE ?)";
        
        $searchTerm = "%$q%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        
        // Si hay filtro por categoría y término, ordenar por relevancia
        $queryProductos .= " ORDER BY 
                           CASE 
                               WHEN p.nombre LIKE ? THEN 1
                               WHEN p.codigo LIKE ? THEN 2
                               WHEN p.codigo_barras LIKE ? THEN 3
                               WHEN p.descripcion LIKE ? THEN 4
                               WHEN c.nombre LIKE ? THEN 5
                               ELSE 6
                           END,
                           p.nombre 
                           LIMIT 50";
        
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    } else if ($categoria_id > 0) {
        // Solo filtro por categoría, sin búsqueda
        $queryProductos .= " ORDER BY p.nombre LIMIT 100";
    } else {
        // Sin filtros, no devolver productos
        $queryProductos .= " AND 1=0"; // No devolver nada
    }
    
    if (count($params) > 0) {
        $stmtProductos = $db->prepare($queryProductos);
        $stmtProductos->execute($params);
        $productos = $stmtProductos->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Formatear los productos
    foreach ($productos as &$p) {
        $p['precio_venta'] = floatval($p['precio_venta']);
        $p['stock'] = intval($p['stock']);
        $p['descripcion'] = $p['descripcion'] ?? '';
        $p['talla'] = $p['talla'] ?? '';
        $p['color'] = $p['color'] ?? '';
        $p['marca_nombre'] = $p['marca_nombre'] ?? '';
        $p['categoria_nombre'] = $p['categoria_nombre'] ?? '';
    }
    
    // Preparar respuesta con categorías y productos
    $respuesta = [
        'categorias' => $categorias,
        'productos' => $productos,
        'filtro_categoria' => $categoria_id
    ];
    
    echo json_encode($respuesta);
    
} catch (Exception $e) {
    error_log("Error en buscar_producto.php: " . $e->getMessage());
    echo json_encode(['error' => 'Error en la búsqueda']);
}
?>