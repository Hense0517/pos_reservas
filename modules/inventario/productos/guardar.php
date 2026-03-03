<?php
/**
 * ============================================
 * ARCHIVO: guardar.php
 * UBICACIÓN: /modules/inventario/productos/guardar.php
 * PROPÓSITO: Procesar el formulario de creación/edición de productos
 * ============================================
 */

session_start();

require_once __DIR__ . '/../../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar permisos
if (!$auth->hasPermission('productos', 'crear')) {
    $_SESSION['error'] = "No tienes permisos para crear productos";
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$database = Database::getInstance();
$db = $database->getConnection();

// Obtener datos del formulario
$id = $_POST['id'] ?? null;
$codigo = trim($_POST['codigo'] ?? '');
$codigo_barras = trim($_POST['codigo_barras'] ?? '');
$nombre = trim($_POST['nombre'] ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');
$categoria_id = !empty($_POST['categoria_id']) ? intval($_POST['categoria_id']) : null;
$marca_id = !empty($_POST['marca_id']) ? intval($_POST['marca_id']) : null;
$precio_compra = floatval($_POST['precio_compra'] ?? 0);
$precio_venta = floatval($_POST['precio_venta'] ?? 0);
$stock = intval($_POST['stock'] ?? 0);
$stock_minimo = intval($_POST['stock_minimo'] ?? 5);
$es_servicio = isset($_POST['es_servicio']) ? 1 : 0;
$activo = isset($_POST['activo']) ? 1 : 0;

// Validaciones básicas
$errors = [];

if (empty($codigo)) {
    $errors[] = "El código es requerido";
}

if (empty($nombre)) {
    $errors[] = "El nombre es requerido";
}

if ($precio_compra <= 0) {
    $errors[] = "El precio de compra debe ser mayor a cero";
}

if ($precio_venta <= 0) {
    $errors[] = "El precio de venta debe ser mayor a cero";
}

if (!empty($errors)) {
    $_SESSION['error'] = implode("<br>", $errors);
    header("Location: " . ($id ? "editar.php?id=$id" : "crear.php"));
    exit();
}

try {
    $db->beginTransaction();

    // Verificar si el código ya existe (excluyendo el actual si es edición)
    if ($id) {
        $check_query = "SELECT id FROM productos WHERE codigo = ? AND id != ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$codigo, $id]);
    } else {
        $check_query = "SELECT id FROM productos WHERE codigo = ?";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$codigo]);
    }
    
    if ($check_stmt->fetch()) {
        throw new Exception("El código '$codigo' ya está en uso");
    }

    // Verificar código de barras si se proporcionó
    if (!empty($codigo_barras)) {
        if ($id) {
            $check_barras = "SELECT id FROM productos WHERE codigo_barras = ? AND id != ?";
            $check_stmt = $db->prepare($check_barras);
            $check_stmt->execute([$codigo_barras, $id]);
        } else {
            $check_barras = "SELECT id FROM productos WHERE codigo_barras = ?";
            $check_stmt = $db->prepare($check_barras);
            $check_stmt->execute([$codigo_barras]);
        }
        
        if ($check_stmt->fetch()) {
            throw new Exception("El código de barras '$codigo_barras' ya está en uso");
        }
    }

    if ($id) {
        // Actualizar producto existente
        $query = "UPDATE productos SET 
                  codigo = ?, codigo_barras = ?, nombre = ?, descripcion = ?, 
                  categoria_id = ?, marca_id = ?, precio_compra = ?, precio_venta = ?, 
                  stock = ?, stock_minimo = ?, es_servicio = ?, activo = ?, updated_at = NOW()
                  WHERE id = ?";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$codigo, $codigo_barras, $nombre, $descripcion, 
                       $categoria_id, $marca_id, $precio_compra, $precio_venta, 
                       $stock, $stock_minimo, $es_servicio, $activo, $id]);
        
        // Eliminar atributos anteriores
        $delete_atributos = "DELETE FROM producto_atributos WHERE producto_id = ?";
        $delete_stmt = $db->prepare($delete_atributos);
        $delete_stmt->execute([$id]);
        
        $producto_id = $id;
        
    } else {
        // Insertar nuevo producto
        $query = "INSERT INTO productos (
                  codigo, codigo_barras, nombre, descripcion, categoria_id, marca_id, 
                  precio_compra, precio_venta, stock, stock_minimo, es_servicio, activo, created_at
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$codigo, $codigo_barras, $nombre, $descripcion, 
                       $categoria_id, $marca_id, $precio_compra, $precio_venta, 
                       $stock, $stock_minimo, $es_servicio, $activo]);
        
        $producto_id = $db->lastInsertId();
    }

    // Guardar atributos seleccionados (valores predefinidos)
    if (isset($_POST['atributos']) && is_array($_POST['atributos'])) {
        foreach ($_POST['atributos'] as $tipo_id => $valor_id) {
            if (!empty($valor_id)) {
                $query_atributo = "INSERT INTO producto_atributos (producto_id, tipo_atributo_id, valor_atributo_id) 
                                  VALUES (?, ?, ?)";
                $stmt_atributo = $db->prepare($query_atributo);
                $stmt_atributo->execute([$producto_id, $tipo_id, $valor_id]);
            }
        }
    }

    // Guardar atributos de texto libre
    if (isset($_POST['atributos_texto']) && is_array($_POST['atributos_texto'])) {
        foreach ($_POST['atributos_texto'] as $tipo_id => $valor_texto) {
            if (!empty($valor_texto)) {
                $query_atributo = "INSERT INTO producto_atributos (producto_id, tipo_atributo_id, valor_texto) 
                                  VALUES (?, ?, ?)";
                $stmt_atributo = $db->prepare($query_atributo);
                $stmt_atributo->execute([$producto_id, $tipo_id, $valor_texto]);
            }
        }
    }

    // Si es nuevo producto y tiene stock inicial, registrar en auditoría
    if (!$id && $stock > 0 && !$es_servicio) {
        $query_audit = "INSERT INTO auditoria_stock 
                       (producto_id, tipo_movimiento, cantidad, stock_anterior, stock_nuevo, usuario_id, referencia, motivo) 
                       VALUES (?, 'compra', ?, 0, ?, ?, 'Inventario inicial', 'Stock inicial al crear producto')";
        $stmt_audit = $db->prepare($query_audit);
        $stmt_audit->execute([$producto_id, $stock, $stock, $_SESSION['usuario_id']]);
    }

    $db->commit();

    $_SESSION['success'] = $id ? "Producto actualizado correctamente" : "Producto creado correctamente";
    header("Location: ver.php?id=" . $producto_id);
    exit();

} catch (Exception $e) {
    $db->rollBack();
    error_log("Error al guardar producto: " . $e->getMessage());
    $_SESSION['error'] = "Error al guardar el producto: " . $e->getMessage();
    header("Location: " . ($id ? "editar.php?id=$id" : "crear.php"));
    exit();
}
?>