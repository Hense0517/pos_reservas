<?php 
// ACTIVAR TODOS LOS ERRORES PARA DEPURACIÓN
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Log de depuración
function debug_log($mensaje, $data = null) {
    $log = date('Y-m-d H:i:s') . " - " . $mensaje;
    if ($data !== null) {
        $log .= " - " . print_r($data, true);
    }
    $log .= "\n";
    file_put_contents('debug_devolucion.log', $log, FILE_APPEND);
}

// Iniciar sesión si no está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

debug_log("INICIO - Proceso de devolución iniciado");

ob_start();
include '../../includes/header.php';

// Verificar si se incluyó el header correctamente
debug_log("Header incluido");

// Verificar permisos
if (!isset($_SESSION['usuario_rol'])) {
    debug_log("ERROR - Sesión no tiene usuario_rol");
    header('Location: /sistema_pos/index.php');
    exit;
}

if ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'vendedor') {
    debug_log("ERROR - Usuario sin permisos: " . $_SESSION['usuario_rol']);
    header('Location: /sistema_pos/index.php');
    exit;
}

debug_log("Usuario autorizado: " . $_SESSION['usuario_id'] . " - Rol: " . $_SESSION['usuario_rol']);

$database = Database::getInstance();
$db = $database->getConnection();
debug_log("Conexión a BD establecida");

// Obtener venta
$venta_id = $_GET['id'] ?? 0;
debug_log("Venta ID solicitada: " . $venta_id);

$query_venta = "SELECT v.*, c.nombre as cliente_nombre 
                FROM ventas v 
                LEFT JOIN clientes c ON v.cliente_id = c.id 
                WHERE v.id = ? AND v.anulada = 0";
$stmt_venta = $db->prepare($query_venta);
debug_log("Preparando query venta: " . $query_venta);

if (!$stmt_venta) {
    debug_log("ERROR - Falló prepare() para venta: " . print_r($db->errorInfo(), true));
    die("Error en la consulta de venta");
}

$result_venta = $stmt_venta->execute([$venta_id]);
debug_log("Ejecutando query venta con ID: " . $venta_id . " - Resultado: " . ($result_venta ? "OK" : "FALLO"));

$venta = $stmt_venta->fetch(PDO::FETCH_ASSOC);
debug_log("Venta encontrada: " . ($venta ? "SÍ" : "NO"));

if (!$venta) {
    debug_log("ERROR - Venta no encontrada o anulada, ID: " . $venta_id);
    $_SESSION['error'] = "Venta no encontrada o ya fue anulada";
    header('Location: index.php');
    exit;
}

debug_log("Venta obtenida: " . print_r($venta, true));

// Obtener detalles de la venta
$query_detalles = "SELECT vd.*, p.nombre as producto_nombre, p.codigo as producto_codigo, p.stock,
                   (SELECT SUM(cantidad) FROM devoluciones WHERE venta_id = vd.venta_id AND producto_id = vd.producto_id) as cantidad_devuelta
                   FROM venta_detalles vd 
                   JOIN productos p ON vd.producto_id = p.id 
                   WHERE vd.venta_id = ?";
$stmt_detalles = $db->prepare($query_detalles);
debug_log("Preparando query detalles");

if (!$stmt_detalles) {
    debug_log("ERROR - Falló prepare() para detalles: " . print_r($db->errorInfo(), true));
    die("Error en la consulta de detalles");
}

$result_detalles = $stmt_detalles->execute([$venta_id]);
debug_log("Ejecutando query detalles - Resultado: " . ($result_detalles ? "OK" : "FALLO"));

$detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);
debug_log("Detalles encontrados: " . count($detalles) . " productos");

// Obtener devoluciones existentes
$query_devoluciones_existentes = "SELECT producto_id, SUM(cantidad) as total_devuelto 
                                  FROM devoluciones 
                                  WHERE venta_id = ? 
                                  GROUP BY producto_id";
$stmt_devoluciones = $db->prepare($query_devoluciones_existentes);
$stmt_devoluciones->execute([$venta_id]);
$devoluciones_existentes = $stmt_devoluciones->fetchAll(PDO::FETCH_KEY_PAIR);
debug_log("Devoluciones existentes: " . print_r($devoluciones_existentes, true));

// Obtener configuración del negocio
$query_config = "SELECT impuesto FROM configuracion_negocio LIMIT 1";
$stmt_config = $db->prepare($query_config);
$stmt_config->execute();
$config = $stmt_config->fetch(PDO::FETCH_ASSOC);
$impuesto_porcentaje = $config['impuesto'] ?? 0;
$impuesto_activo = $impuesto_porcentaje > 0;
debug_log("Configuración impuesto: " . ($impuesto_activo ? "ACTIVO " . ($impuesto_porcentaje * 100) . "%" : "INACTIVO"));

// Variables para mostrar mensajes
$error = '';
$success = '';

// Procesar devolución
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debug_log("=== INICIO PROCESAMIENTO POST ===");
    debug_log("POST recibido: ", $_POST);
    debug_log("SESSION usuario_id: ", $_SESSION['usuario_id']);
    
    try {
        $db->beginTransaction();
        debug_log("Transacción iniciada");
        
        $productos_devolucion = $_POST['productos'] ?? [];
        $motivo = trim($_POST['motivo'] ?? '');
        
        debug_log("Motivo recibido: " . $motivo);
        debug_log("Productos devolución: ", $productos_devolucion);
        
        // Validaciones básicas
        if (empty($motivo)) {
            throw new Exception("El motivo de la devolución es requerido");
        }
        
        $productos_seleccionados = false;
        foreach ($productos_devolucion as $cantidad) {
            if (intval($cantidad) > 0) {
                $productos_seleccionados = true;
                break;
            }
        }
        
        if (!$productos_seleccionados) {
            throw new Exception("No se seleccionaron productos para devolver");
        }
        
        $total_devolucion = 0;
        $productos_procesados = 0;
        $productos_detalle = [];
        
        foreach ($productos_devolucion as $producto_id => $cantidad) {
            $cantidad = intval($cantidad);
            debug_log("Procesando producto ID $producto_id, cantidad: $cantidad");
            
            if ($cantidad <= 0) {
                debug_log("Cantidad <= 0, saltando producto $producto_id");
                continue;
            }
            
            // Buscar detalle del producto
            $detalle_encontrado = null;
            foreach ($detalles as $detalle) {
                if ($detalle['producto_id'] == $producto_id) {
                    $detalle_encontrado = $detalle;
                    break;
                }
            }
            
            if (!$detalle_encontrado) {
                debug_log("ERROR - Producto $producto_id no encontrado en detalles de venta");
                continue;
            }
            
            debug_log("Detalle encontrado: " . print_r($detalle_encontrado, true));
            
            // Verificar cantidad disponible
            $cantidad_ya_devuelta = $devoluciones_existentes[$producto_id] ?? 0;
            $cantidad_disponible = $detalle_encontrado['cantidad'] - $cantidad_ya_devuelta;
            
            debug_log("Cantidad ya devuelta: $cantidad_ya_devuelta, Disponible: $cantidad_disponible");
            
            if ($cantidad > $cantidad_disponible) {
                throw new Exception("La cantidad a devolver ($cantidad) excede la disponible ($cantidad_disponible) para: " . $detalle_encontrado['producto_nombre']);
            }
            
            // Calcular montos
            $monto_devolucion = $detalle_encontrado['precio'] * $cantidad;
            $total_devolucion += $monto_devolucion;
            $productos_procesados++;
            
            debug_log("Monto devolución: $monto_devolucion, Total acumulado: $total_devolucion");
            
            // Registrar devolución
            $query_devolucion = "INSERT INTO devoluciones (venta_id, producto_id, cantidad, motivo, monto_devolucion, usuario_id, fecha) 
                                VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $stmt_devolucion = $db->prepare($query_devolucion);
            
            debug_log("Ejecutando INSERT devolucion: $venta_id, $producto_id, $cantidad, $motivo, $monto_devolucion, " . $_SESSION['usuario_id']);
            
            $resultado = $stmt_devolucion->execute([
                $venta_id, 
                $producto_id, 
                $cantidad, 
                $motivo, 
                $monto_devolucion, 
                $_SESSION['usuario_id']
            ]);
            
            if (!$resultado) {
                $error_info = $stmt_devolucion->errorInfo();
                debug_log("ERROR en INSERT devolución: " . print_r($error_info, true));
                throw new Exception("Error al registrar devolución para producto ID: $producto_id - " . $error_info[2]);
            }
            
            debug_log("Devolución registrada exitosamente");
            
            // Obtener stock actual antes de actualizar
            $query_stock_actual = "SELECT stock FROM productos WHERE id = ?";
            $stmt_stock = $db->prepare($query_stock_actual);
            $stmt_stock->execute([$producto_id]);
            $stock_actual = $stmt_stock->fetchColumn();
            
            debug_log("Stock actual producto $producto_id: $stock_actual");
            
            // Restaurar stock
            $query_update_stock = "UPDATE productos SET stock = stock + ? WHERE id = ?";
            $stmt_update = $db->prepare($query_update_stock);
            $result_update = $stmt_update->execute([$cantidad, $producto_id]);
            
            if (!$result_update) {
                debug_log("ERROR al actualizar stock producto $producto_id");
                throw new Exception("Error al actualizar stock del producto");
            }
            
            debug_log("Stock actualizado: +$cantidad unidades");
            
            // Registrar auditoría de stock
            $query_auditoria = "INSERT INTO auditoria_stock 
                               (producto_id, tipo_movimiento, cantidad, stock_anterior, stock_nuevo, usuario_id, referencia, motivo, fecha)
                               VALUES (?, 'devolucion', ?, ?, ?, ?, ?, ?, NOW())";
            $stmt_auditoria = $db->prepare($query_auditoria);
            
            $stock_nuevo = $stock_actual + $cantidad;
            debug_log("Stock anterior: $stock_actual, Stock nuevo: $stock_nuevo");
            
            $result_auditoria = $stmt_auditoria->execute([
                $producto_id,
                $cantidad,
                $stock_actual,
                $stock_nuevo,
                $_SESSION['usuario_id'],
                "Venta #" . $venta['numero_factura'],
                "Devolución: " . $motivo
            ]);
            
            if (!$result_auditoria) {
                debug_log("WARNING - No se pudo registrar auditoría, continuando...");
            } else {
                debug_log("Auditoría registrada exitosamente");
            }
        }
        
        debug_log("Total productos procesados: $productos_procesados");
        
        if ($productos_procesados === 0) {
            throw new Exception("No se procesaron productos para devolución");
        }
        
        // Actualizar total de la venta
        if ($total_devolucion > 0) {
            $nuevo_subtotal = $venta['subtotal'] - $total_devolucion;
            $nuevo_impuesto = $nuevo_subtotal * $impuesto_porcentaje;
            $nuevo_total = $nuevo_subtotal + $nuevo_impuesto;
            
            debug_log("Cálculos de actualización:");
            debug_log("Subtotal anterior: " . $venta['subtotal']);
            debug_log("Total devolución: $total_devolucion");
            debug_log("Nuevo subtotal: $nuevo_subtotal");
            debug_log("Nuevo impuesto: $nuevo_impuesto");
            debug_log("Nuevo total: $nuevo_total");
            
            $query_actualizar_venta = "UPDATE ventas 
                                      SET subtotal = ?, 
                                          impuesto = ?, 
                                          total = ?
                                      WHERE id = ?";
            $stmt_actualizar_venta = $db->prepare($query_actualizar_venta);
            $result_actualizar = $stmt_actualizar_venta->execute([
                $nuevo_subtotal,
                $nuevo_impuesto,
                $nuevo_total,
                $venta_id
            ]);
            
            if (!$result_actualizar) {
                debug_log("ERROR al actualizar venta: " . print_r($stmt_actualizar_venta->errorInfo(), true));
                throw new Exception("Error al actualizar el total de la venta");
            }
            
            debug_log("Venta actualizada exitosamente");
            
            // Registrar historial
            $query_historial = "INSERT INTO historial_ventas (venta_id, usuario_id, accion, detalles, fecha)
                               VALUES (?, ?, 'devolucion', ?, NOW())";
            $stmt_historial = $db->prepare($query_historial);
            $detalles_json = json_encode([
                'total_anterior' => $venta['total'],
                'total_nuevo' => $nuevo_total,
                'devolucion' => $total_devolucion,
                'productos_devueltos' => $productos_procesados,
                'motivo' => $motivo
            ]);
            
            $result_historial = $stmt_historial->execute([
                $venta_id, 
                $_SESSION['usuario_id'], 
                $detalles_json
            ]);
            
            if ($result_historial) {
                debug_log("Historial registrado");
            }
        }
        
        $db->commit();
        debug_log("=== TRANSACCIÓN COMPLETADA EXITOSAMENTE ===");
        
        // Mostrar éxito y redirigir con JavaScript
        echo "<script>
            console.log('Devolución exitosa procesada desde PHP');
            console.log('Total devuelto: $" . number_format($total_devolucion, 2) . "');
            console.log('Productos devueltos: " . $productos_procesados . "');
            
            Swal.fire({
                title: '¡Devolución Exitosa!',
                html: '<div style=\"text-align: left;\">' +
                      '<p>Devolución procesada correctamente.</p>' +
                      '<p><strong>Total devuelto:</strong> $" . number_format($total_devolucion, 2) . "</p>' +
                      '<p><strong>Productos devueltos:</strong> " . $productos_procesados . "</p>' +
                      '<p><strong>Motivo:</strong> " . addslashes($motivo) . "</p>' +
                      '</div>',
                icon: 'success',
                confirmButtonColor: '#3085d6',
                confirmButtonText: 'Ver Venta',
                showCancelButton: true,
                cancelButtonText: 'Cerrar'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'ver.php?id=" . $venta_id . "';
                } else {
                    // Recargar la página para limpiar el formulario
                    window.location.href = 'devolucion.php?id=" . $venta_id . "';
                }
            });
        </script>";
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
        debug_log("=== ERROR EN TRANSACCIÓN: " . $error . " ===");
        debug_log("Rollback ejecutado");
    }
}

debug_log("=== FIN PROCESAMIENTO, MOSTRANDO FORMULARIO ===");
?>

<!-- AGREGAR SWEETALERT2 ANTES DE CUALQUIER CÓDIGO JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Función para depuración en JavaScript
function debugJS(mensaje, data = null) {
    console.log('[JS DEBUG] ' + mensaje);
    if (data !== null) {
        console.log('[JS DEBUG DATA]', data);
    }
}

debugJS('Página cargada - Módulo de devoluciones');
debugJS('Venta ID: <?php echo $venta_id; ?>');
debugJS('Productos disponibles: <?php echo count($detalles); ?>');
</script>

<div class="max-w-4xl mx-auto">
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Procesar Devolución</h2>
            <p class="text-sm text-gray-600">Selecciona los productos a devolver</p>
            <?php if ($impuesto_activo): ?>
                <p class="text-xs text-blue-600 mt-1">
                    <i class="fas fa-info-circle"></i> 
                    Impuesto configurado: <?php echo number_format($impuesto_porcentaje * 100, 2); ?>%
                </p>
            <?php else: ?>
                <p class="text-xs text-gray-500 mt-1">
                    <i class="fas fa-info-circle"></i> 
                    Impuesto: 0%
                </p>
            <?php endif; ?>
        </div>
        
        <div class="p-6">
            <?php if (isset($error) && !empty($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4" id="errorMessage">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
                <script>
                    debugJS('Error PHP detectado: <?php echo addslashes($error); ?>');
                </script>
            <?php endif; ?>

            <!-- Información de la venta -->
            <div class="bg-blue-50 rounded-lg p-4 mb-6">
                <h3 class="text-lg font-medium text-blue-900 mb-2">Información de la Venta</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <span class="font-medium">Factura:</span>
                        <span><?php echo htmlspecialchars($venta['numero_factura']); ?></span>
                    </div>
                    <div>
                        <span class="font-medium">Cliente:</span>
                        <span><?php echo htmlspecialchars($venta['cliente_nombre'] ?? 'Cliente General'); ?></span>
                    </div>
                    <div>
                        <span class="font-medium">Fecha:</span>
                        <span><?php echo date('d/m/Y H:i:s', strtotime($venta['fecha'])); ?></span>
                    </div>
                    <div>
                        <span class="font-medium">Total:</span>
                        <span class="font-bold">$<?php echo number_format($venta['total'], 2); ?></span>
                    </div>
                </div>
            </div>

            <form method="POST" id="formDevolucion" action="devolucion.php?id=<?php echo $venta_id; ?>">
                <input type="hidden" name="debug" value="1">
                
                <!-- Productos para devolución -->
                <div class="mb-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Productos de la Venta</h3>
                    <?php if (empty($detalles)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-box-open text-gray-300 text-4xl mb-3"></i>
                            <p class="text-gray-500">No hay productos en esta venta</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Producto</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cantidad Vendida</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ya Devuelto</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Disponible</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Precio Unit.</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cantidad a Devolver</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($detalles as $detalle): 
                                        $cantidad_ya_devuelta = $devoluciones_existentes[$detalle['producto_id']] ?? 0;
                                        $cantidad_disponible = $detalle['cantidad'] - $cantidad_ya_devuelta;
                                        $disabled = $cantidad_disponible <= 0;
                                    ?>
                                    <tr class="hover:bg-gray-50 <?php echo $disabled ? 'opacity-50' : ''; ?>">
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($detalle['producto_nombre']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo htmlspecialchars($detalle['producto_codigo']); ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 text-center">
                                            <?php echo $detalle['cantidad']; ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 text-center">
                                            <span class="<?php echo $cantidad_ya_devuelta > 0 ? 'text-orange-600 font-medium' : 'text-gray-500'; ?>">
                                                <?php echo $cantidad_ya_devuelta; ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 text-center">
                                            <span class="<?php echo $cantidad_disponible > 0 ? 'text-green-600 font-medium' : 'text-red-600'; ?>">
                                                <?php echo $cantidad_disponible; ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 text-center">
                                            $<?php echo number_format($detalle['precio'], 2); ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <input type="number" 
                                                   name="productos[<?php echo $detalle['producto_id']; ?>]" 
                                                   id="cantidad_<?php echo $detalle['producto_id']; ?>"
                                                   min="0" 
                                                   max="<?php echo $cantidad_disponible; ?>" 
                                                   value="0"
                                                   class="w-24 border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 cantidad-devolucion text-center"
                                                   data-precio="<?php echo $detalle['precio']; ?>"
                                                   data-producto="<?php echo htmlspecialchars($detalle['producto_nombre']); ?>"
                                                   data-disponible="<?php echo $cantidad_disponible; ?>"
                                                   <?php echo $disabled ? 'disabled' : ''; ?>
                                                   onchange="debugJS('Cambio en producto <?php echo $detalle['producto_id']; ?>', this.value)">
                                            <?php if ($disabled): ?>
                                            <div class="text-xs text-red-500 mt-1">Ya devuelto completamente</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 text-center subtotal-devolucion" 
                                            id="subtotal_<?php echo $detalle['producto_id']; ?>">
                                            $0.00
                                        </td>
                                    </tr>
                                    <script>
                                        debugJS('Producto cargado: <?php echo $detalle['producto_id']; ?> - <?php echo addslashes($detalle['producto_nombre']); ?>');
                                        debugJS('Disponible: <?php echo $cantidad_disponible; ?>, Precio: <?php echo $detalle['precio']; ?>');
                                    </script>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Motivo y total -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label for="motivo" class="block text-sm font-medium text-gray-700 mb-2">
                            <span class="text-red-500">*</span> Motivo de la Devolución
                        </label>
                        <textarea id="motivo" name="motivo" rows="4" required
                                  class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Describa el motivo de la devolución (obligatorio)"
                                  oninput="debugJS('Motivo cambiado', this.value)"></textarea>
                        <div id="errorMotivo" class="text-red-500 text-xs mt-1 hidden">El motivo es requerido</div>
                    </div>
                    
                    <div class="bg-red-50 rounded-lg p-6 border border-red-200">
                        <h3 class="text-lg font-medium text-red-900 mb-4">Resumen de Devolución</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Total de productos a devolver:</span>
                                <span id="totalProductosDevolver" class="font-medium">0</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Subtotal devolución:</span>
                                <span id="subtotalDevolucion" class="font-medium">$0.00</span>
                            </div>
                            <?php if ($impuesto_activo): ?>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Impuesto (<?php echo number_format($impuesto_porcentaje * 100, 2); ?>%):</span>
                                <span id="impuestoDevolucion" class="font-medium">$0.00</span>
                            </div>
                            <?php endif; ?>
                            <div class="flex justify-between border-t border-red-200 pt-2">
                                <span class="text-lg font-medium text-red-900">Total a Devolver:</span>
                                <span id="totalDevolucion" class="text-xl font-bold text-red-600">$0.00</span>
                            </div>
                            <div class="mt-4 p-3 bg-red-100 rounded border border-red-300">
                                <div class="flex items-start">
                                    <i class="fas fa-exclamation-triangle text-red-500 mt-0.5 mr-2"></i>
                                    <div class="text-xs text-red-700">
                                        <strong>Nota:</strong> El stock será restaurado automáticamente y el total de la venta será actualizado.
                                        <?php if ($impuesto_activo): ?>
                                        <br>El total incluye el impuesto del <?php echo number_format($impuesto_porcentaje * 100, 2); ?>%.
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Botones -->
                <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200">
                    <a href="ver.php?id=<?php echo $venta_id; ?>" 
                       class="bg-white py-2 px-6 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        <i class="fas fa-times mr-2"></i>Cancelar
                    </a>
                    <button type="button" 
                            id="btnProcesarDevolucion"
                            onclick="procesarDevolucion()"
                            class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-md shadow-sm text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 disabled:opacity-50 disabled:cursor-not-allowed"
                            disabled>
                        <i class="fas fa-undo mr-2"></i>Procesar Devolución
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Variables globales para cálculos
let totalDevolucion = 0;
let subtotalDevolucion = 0;
let impuestoDevolucion = 0;
let totalProductosDevolver = 0;
const impuestoPorcentaje = <?php echo $impuesto_porcentaje; ?>;
const impuestoActivo = <?php echo $impuesto_activo ? 'true' : 'false'; ?>;

debugJS('Configuración impuesto', {
    porcentaje: impuestoPorcentaje,
    activo: impuestoActivo
});

// Calcular subtotal por producto
function calcularSubtotal(input) {
    const precio = parseFloat(input.getAttribute('data-precio'));
    let cantidad = parseInt(input.value) || 0;
    const disponible = parseInt(input.getAttribute('data-disponible'));
    const productoId = input.id.split('_')[1];
    const productoNombre = input.getAttribute('data-producto');
    
    debugJS(`Calculando subtotal producto ${productoId}: ${productoNombre}`, {
        precio: precio,
        cantidad: cantidad,
        disponible: disponible
    });
    
    // Validar que no exceda la cantidad disponible
    if (cantidad > disponible) {
        cantidad = disponible;
        input.value = disponible;
        debugJS(`Ajustando cantidad a máximo disponible: ${disponible}`);
        Swal.fire({
            title: 'Advertencia',
            text: `La cantidad máxima disponible para devolver es ${disponible}`,
            icon: 'warning',
            confirmButtonColor: '#3085d6'
        });
    }
    
    if (cantidad < 0) {
        cantidad = 0;
        input.value = 0;
        debugJS('Cantidad negativa ajustada a 0');
    }
    
    const subtotal = precio * cantidad;
    const subtotalElement = document.getElementById(`subtotal_${productoId}`);
    
    if (subtotalElement) {
        subtotalElement.textContent = `$${subtotal.toFixed(2)}`;
        debugJS(`Subtotal producto ${productoId}: $${subtotal.toFixed(2)}`);
    } else {
        debugJS(`ERROR: Elemento subtotal_${productoId} no encontrado`);
    }
    
    // Recalcular totales
    calcularTotalesDevolucion();
}

// Calcular totales generales de devolución
function calcularTotalesDevolucion() {
    debugJS('Calculando totales generales');
    
    subtotalDevolucion = 0;
    totalProductosDevolver = 0;
    const inputs = document.querySelectorAll('.cantidad-devolucion:not([disabled])');
    
    debugJS(`Encontrados ${inputs.length} inputs activos`);
    
    inputs.forEach(input => {
        const cantidad = parseInt(input.value) || 0;
        const precio = parseFloat(input.getAttribute('data-precio'));
        
        if (cantidad > 0) {
            subtotalDevolucion += precio * cantidad;
            totalProductosDevolver += cantidad;
            debugJS(`Input contribuye: cantidad=${cantidad}, precio=${precio}, subtotal=${precio * cantidad}`);
        }
    });
    
    // Calcular impuesto y total
    if (impuestoActivo) {
        impuestoDevolucion = subtotalDevolucion * impuestoPorcentaje;
        totalDevolucion = subtotalDevolucion + impuestoDevolucion;
    } else {
        impuestoDevolucion = 0;
        totalDevolucion = subtotalDevolucion;
    }
    
    // Actualizar displays
    document.getElementById('subtotalDevolucion').textContent = `$${subtotalDevolucion.toFixed(2)}`;
    
    // Solo actualizar impuesto si está activo
    if (impuestoActivo) {
        const impuestoElement = document.getElementById('impuestoDevolucion');
        if (impuestoElement) {
            impuestoElement.textContent = `$${impuestoDevolucion.toFixed(2)}`;
        }
    }
    
    document.getElementById('totalDevolucion').textContent = `$${totalDevolucion.toFixed(2)}`;
    document.getElementById('totalProductosDevolver').textContent = totalProductosDevolver;
    
    debugJS('Totales calculados', {
        subtotal: subtotalDevolucion,
        impuesto: impuestoDevolucion,
        total: totalDevolucion,
        productos: totalProductosDevolver
    });
    
    // Validar formulario
    validarFormulario();
}

// Validar formulario completo
function validarFormulario() {
    const motivo = document.getElementById('motivo').value.trim();
    const btnProcesar = document.getElementById('btnProcesarDevolucion');
    const errorMotivo = document.getElementById('errorMotivo');
    
    let formularioValido = true;
    
    debugJS('Validando formulario', {
        motivo: motivo,
        totalProductos: totalProductosDevolver
    });
    
    // Validar motivo
    if (motivo === '') {
        errorMotivo.classList.remove('hidden');
        formularioValido = false;
        debugJS('Motivo inválido: vacío');
    } else {
        errorMotivo.classList.add('hidden');
        debugJS('Motivo válido');
    }
    
    // Validar que haya al menos un producto para devolver
    if (totalProductosDevolver === 0) {
        debugJS('No hay productos seleccionados para devolver');
        formularioValido = false;
    } else {
        debugJS(`Productos seleccionados: ${totalProductosDevolver}`);
    }
    
    // Habilitar/deshabilitar botón
    btnProcesar.disabled = !formularioValido;
    debugJS(`Botón procesar ${formularioValido ? 'HABILITADO' : 'DESHABILITADO'}`);
    
    return formularioValido;
}

// Procesar devolución
function procesarDevolucion() {
    debugJS('Iniciando procesarDevolucion()');
    
    if (!validarFormulario()) {
        debugJS('Formulario inválido, deteniendo procesamiento');
        Swal.fire({
            title: 'Error',
            text: 'Complete todos los campos requeridos',
            icon: 'error',
            confirmButtonColor: '#3085d6'
        });
        return;
    }
    
    debugJS('Formulario válido, mostrando confirmación');
    
    // Mostrar confirmación con SweetAlert
    Swal.fire({
        title: '¿Confirmar devolución?',
        html: `
            <div class="text-left">
                <p class="mb-3">Va a procesar una devolución con los siguientes datos:</p>
                <div class="bg-gray-50 p-3 rounded text-sm">
                    <p><strong>Total a devolver:</strong> $${totalDevolucion.toFixed(2)}</p>
                    <p><strong>Productos:</strong> ${totalProductosDevolver}</p>
                    <p><strong>Motivo:</strong> ${document.getElementById('motivo').value}</p>
                </div>
                <p class="text-xs text-red-600 mt-3">Esta acción restaurará el stock y actualizará el total de la venta.</p>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, procesar devolución',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
        showLoaderOnConfirm: true,
        allowOutsideClick: () => !Swal.isLoading(),
        preConfirm: () => {
            debugJS('Usuario confirmó la devolución');
            
            // Mostrar datos que se enviarán
            const formData = new FormData(document.getElementById('formDevolucion'));
            const data = {};
            for (let [key, value] of formData.entries()) {
                if (key === 'productos') {
                    // Para el array de productos
                    if (!data[key]) data[key] = {};
                    // Los productos vienen como product[ID]=cantidad
                    // Pero FormData los convierte automáticamente
                } else {
                    data[key] = value;
                }
            }
            
            debugJS('Datos a enviar en POST:', data);
            
            // Enviar el formulario
            document.getElementById('formDevolucion').submit();
            
            // Retornar false para que SweetAlert no cierre automáticamente
            return false;
        }
    }).then((result) => {
        if (result.dismiss === Swal.DismissReason.cancel) {
            debugJS('Usuario canceló la devolución');
        }
    });
}

// Inicializar cálculos
document.addEventListener('DOMContentLoaded', function() {
    debugJS('DOM cargado - Inicializando módulo de devoluciones');
    
    // Calcular totales iniciales
    calcularTotalesDevolucion();
    
    // Agregar event listeners a todos los inputs de cantidad
    const inputsCantidad = document.querySelectorAll('.cantidad-devolucion');
    debugJS(`Agregando listeners a ${inputsCantidad.length} inputs de cantidad`);
    
    inputsCantidad.forEach(input => {
        input.addEventListener('input', function() {
            debugJS(`Input cambiado: ${this.id} = ${this.value}`);
            calcularSubtotal(this);
        });
        
        input.addEventListener('change', function() {
            debugJS(`Change event: ${this.id} = ${this.value}`);
            calcularSubtotal(this);
        });
    });
    
    // Validar formulario al escribir en motivo
    document.getElementById('motivo').addEventListener('input', function() {
        debugJS('Motivo input event');
        validarFormulario();
    });
    
    // Debug: verificar que el formulario existe
    const form = document.getElementById('formDevolucion');
    if (form) {
        debugJS('Formulario encontrado, action:', form.action);
        
        // Agregar event listener para submit
        form.addEventListener('submit', function(e) {
            debugJS('Formulario enviado (submit event)');
            console.log('Formulario enviado manualmente');
        });
    } else {
        debugJS('ERROR: Formulario no encontrado');
    }
    
    // Debug: verificar botón
    const btnProcesar = document.getElementById('btnProcesarDevolucion');
    if (btnProcesar) {
        debugJS('Botón procesar encontrado');
        btnProcesar.addEventListener('click', function() {
            debugJS('Botón procesar clickeado manualmente');
        });
    }
    
    debugJS('Inicialización completada');
});
</script>

<?php 
include '../../includes/footer.php'; 
ob_end_flush();
?>