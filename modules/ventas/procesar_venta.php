<?php
// Auto-fixed: 2026-02-17 01:57:21

ob_start();
session_start();

// Incluir configuración para usar el sistema de Auth
require_once __DIR__ . '/../../includes/config.php';

// Verificar permisos usando el sistema de Auth
if (!isset($auth) || !$auth->hasPermission('ventas', 'crear')) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'error' => 'No tienes permisos para crear ventas'
        ]);
        exit;
    } else {
        $_SESSION['error'] = "No tienes permisos para crear ventas";
        header('Location: /sistema_pos/index.php');
        exit;
    }
}

// Conexión a la base de datos
require_once __DIR__ . '/../../config/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => "Error de conexión a la base de datos: " . $e->getMessage()]);
        exit;
    } else {
        $_SESSION['error'] = "Error de conexión a la base de datos: " . $e->getMessage();
        header('Location: crear.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => "Método no permitido."]);
        exit;
    } else {
        $_SESSION['error'] = "Método no permitido.";
        header('Location: crear.php');
        exit;
    }
}

// Verificar si es petición AJAX
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

try {
    $db->beginTransaction();
    
    // ============================================
    // 1. OBTENER CONFIGURACIÓN DEL SISTEMA
    // ============================================
    $query_config = "SELECT impuesto, moneda FROM configuracion_negocio LIMIT 1";
    $stmt_config = $db->prepare($query_config);
    $stmt_config->execute();
    $configuracion = $stmt_config->fetch(PDO::FETCH_ASSOC);
    
    $impuesto_porcentaje = $configuracion['impuesto'] ?? 19.00;
    $impuesto_decimal = $impuesto_porcentaje / 100;
    $moneda = $configuracion['moneda'] ?? 'USD';

    // ============================================
    // 2. VALIDAR DATOS BÁSICOS
    // ============================================
    $required_fields = ['numero_factura', 'metodo_pago', 'tipo_venta'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            throw new Exception("El campo '$field' es requerido.");
        }
    }
    
    // Validar productos
    if (!isset($_POST['productos']) || empty($_POST['productos'])) {
        throw new Exception("Debe agregar al menos un producto a la venta.");
    }

    // ============================================
    // 3. OBTENER Y VALIDAR DATOS DE LA VENTA
    // ============================================
    $numero_factura = trim($_POST['numero_factura']);
    $cliente_id = !empty($_POST['cliente_id']) ? intval($_POST['cliente_id']) : null;
    $usuario_id = $auth->getUserId(); // Usar el ID del sistema de Auth
    $usuario_nombre = $auth->getUserName(); // Usar el nombre del sistema de Auth
    $metodo_pago = $_POST['metodo_pago'];
    $tipo_venta_post = $_POST['tipo_venta']; // 'contado' o 'credito' - GUARDAR ORIGINAL
    $observaciones = $_POST['observaciones'] ?? '';
    
    // Datos para crédito
    $abono_inicial = isset($_POST['abono_inicial']) ? floatval(str_replace(',', '.', $_POST['abono_inicial'])) : 0;
    $fecha_limite = !empty($_POST['fecha_limite']) ? $_POST['fecha_limite'] : null;
    $usar_fecha_limite = isset($_POST['usar_fecha_limite']) ? intval($_POST['usar_fecha_limite']) : 0;
    
    // Datos para contado
    $monto_recibido = isset($_POST['monto_recibido']) ? floatval(str_replace(',', '.', $_POST['monto_recibido'])) : 0;
    
    // Datos de descuento
    $descuento = isset($_POST['descuento']) ? floatval(str_replace(',', '.', $_POST['descuento'])) : 0;
    $tipo_descuento = $_POST['tipo_descuento'] ?? 'monto';
    
    // Datos para pago mixto (nuevos campos)
    $es_pago_mixto = $metodo_pago === 'mixto';
    $monto_efectivo_mixto = isset($_POST['monto_efectivo_mixto']) ? floatval(str_replace(',', '.', $_POST['monto_efectivo_mixto'])) : 0;
    $monto_tarjeta_mixto = isset($_POST['monto_tarjeta_mixto']) ? floatval(str_replace(',', '.', $_POST['monto_tarjeta_mixto'])) : 0;
    $monto_transferencia_mixto = isset($_POST['monto_transferencia_mixto']) ? floatval(str_replace(',', '.', $_POST['monto_transferencia_mixto'])) : 0;
    $monto_otro_mixto = isset($_POST['monto_otro_mixto']) ? floatval(str_replace(',', '.', $_POST['monto_otro_mixto'])) : 0;

    // ============================================
    // 4. VALIDACIONES ESPECÍFICAS POR TIPO DE VENTA
    // ============================================
    
    // Validar cliente para crédito
    if ($tipo_venta_post === 'credito' && empty($cliente_id)) {
        throw new Exception("Para venta a crédito debe seleccionar un cliente.");
    }
    
    // Validar abono inicial para crédito
    if ($tipo_venta_post === 'credito' && $abono_inicial < 0) {
        throw new Exception("El abono inicial no puede ser negativo.");
    }
    
    // Validar fecha límite para crédito
    if ($tipo_venta_post === 'credito' && $usar_fecha_limite == 1 && empty($fecha_limite)) {
        throw new Exception("Debe especificar una fecha límite para el crédito.");
    }
    
    if ($tipo_venta_post === 'credito' && $usar_fecha_limite == 0) {
        $fecha_limite = null;
    }
    
    // Validar monto recibido para contado
    if ($tipo_venta_post === 'contado' && $monto_recibido < 0) {
        throw new Exception("El monto recibido no puede ser negativo.");
    }
    
    // Validaciones específicas para pago mixto
    if ($es_pago_mixto && $tipo_venta_post === 'contado') {
        // Validar que todos los montos sean positivos o cero
        if ($monto_efectivo_mixto < 0 || $monto_tarjeta_mixto < 0 || 
            $monto_transferencia_mixto < 0 || $monto_otro_mixto < 0) {
            throw new Exception("Los montos del pago mixto no pueden ser negativos.");
        }
        
        // Validar que al menos un monto sea mayor a 0
        $suma_mixto = $monto_efectivo_mixto + $monto_tarjeta_mixto + 
                      $monto_transferencia_mixto + $monto_otro_mixto;
        
        if ($suma_mixto <= 0) {
            throw new Exception("Debe ingresar al menos un monto en el pago mixto.");
        }
    }

    // ============================================
    // 5. CALCULAR TOTALES
    // ============================================
    $subtotal = 0;
    
    // Obtener productos de la manera correcta
    if (is_string($_POST['productos'])) {
        // Si viene como string JSON
        $productos = json_decode($_POST['productos'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Intentar parsear como array serializado
            parse_str($_POST['productos'], $productos);
        }
    } else {
        $productos = $_POST['productos'];
    }
    
    if (!is_array($productos)) {
        throw new Exception("Formato de productos no válido.");
    }
    
    // Validar y calcular subtotal
    foreach ($productos as $index => $productoData) {
        if (!isset($productoData['id']) || !isset($productoData['cantidad']) || !isset($productoData['precio'])) {
            throw new Exception("Datos del producto #" . ($index + 1) . " incompletos.");
        }
        
        $producto_id = intval($productoData['id']);
        $cantidad = intval($productoData['cantidad']);
        $precio = floatval($productoData['precio']);
        
        if ($cantidad <= 0) {
            throw new Exception("La cantidad del producto #" . ($index + 1) . " debe ser mayor a 0.");
        }
        
        if ($precio < 0) {
            throw new Exception("El precio del producto #" . ($index + 1) . " no puede ser negativo.");
        }
        
        $subtotal += $precio * $cantidad;
    }
    
    // Aplicar descuento
    $descuentoAplicado = 0;
    if ($tipo_descuento === 'porcentaje') {
        $descuentoAplicado = $subtotal * ($descuento / 100);
    } else {
        $descuentoAplicado = min($descuento, $subtotal);
    }
    
    $subtotalConDescuento = $subtotal - $descuentoAplicado;
    $impuesto_total = $subtotalConDescuento * $impuesto_decimal;
    $total = $subtotalConDescuento + $impuesto_total;
    
    // ============================================
    // 6. CÁLCULOS ESPECÍFICOS POR TIPO DE VENTA Y MÉTODO DE PAGO
    // ============================================
    $cambio = 0;
    $saldo_pendiente = 0;
    $monto_recibido_final = 0;
    
    // Variable para determinar si se debe registrar como crédito en la BD
    $tipo_venta_bd = $tipo_venta_post; // Por defecto usar el tipo original
    
    if ($tipo_venta_post === 'contado') {
        if ($es_pago_mixto) {
            // PAGO MIXTO: Calcular y validar suma de pagos
            $suma_pagos_mixtos = $monto_efectivo_mixto + $monto_tarjeta_mixto + 
                                 $monto_transferencia_mixto + $monto_otro_mixto;
            
            // Validar que la suma coincida con el total (con tolerancia de 0.01)
            if (abs($suma_pagos_mixtos - $total) > 0.01) {
                throw new Exception("La suma de los pagos mixtos ($moneda " . 
                    number_format($suma_pagos_mixtos, 2) . 
                    ") no coincide con el total ($moneda " . number_format($total, 2) . ").");
            }
            
            $monto_recibido_final = $suma_pagos_mixtos;
            $cambio = 0; // En pago mixto no hay cambio
            $saldo_pendiente = 0;
            
        } else {
            // CONTADO NORMAL: calcular cambio
            $cambio = $monto_recibido - $total;
            
            if ($cambio < 0) {
                throw new Exception("El monto recibido ($moneda " . 
                    number_format($monto_recibido, 2) . 
                    ") es insuficiente. Total: $moneda " . number_format($total, 2));
            }
            
            $monto_recibido_final = $monto_recibido;
            $saldo_pendiente = 0;
        }
        
    } else {
        // CRÉDITO: validar y calcular saldo pendiente
        if ($abono_inicial > $total) {
            throw new Exception("El abono inicial ($moneda " . 
                number_format($abono_inicial, 2) . 
                ") no puede ser mayor al total ($moneda " . number_format($total, 2) . ").");
        }
        
        $saldo_pendiente = $total - $abono_inicial;
        $cambio = 0; // Para crédito no hay cambio
        $monto_recibido_final = $abono_inicial;
        
        // Si el abono es igual al total, convertir a contado para la BD
        if ($abono_inicial == $total) {
            $tipo_venta_bd = 'contado'; // Para la BD será contado
            $saldo_pendiente = 0;
        }
    }

    // ============================================
    // 7. VALIDAR NÚMERO DE FACTURA ÚNICO
    // ============================================
    $query_check_factura = "SELECT id FROM ventas WHERE numero_factura = ?";
    $stmt_check_factura = $db->prepare($query_check_factura);
    $stmt_check_factura->execute([$numero_factura]);
    
    if ($stmt_check_factura->fetch()) {
        throw new Exception("El número de factura '$numero_factura' ya existe.");
    }

    // ============================================
    // 8. INSERTAR VENTA EN LA BASE DE DATOS
    // ============================================
    $query_venta = "INSERT INTO ventas (
        numero_factura, cliente_id, usuario_id, subtotal, descuento, 
        tipo_descuento, impuesto, total, metodo_pago, monto_recibido, 
        cambio, observaciones, estado, tipo_venta, abono_inicial, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completada', ?, ?, NOW())";
    
    $stmt_venta = $db->prepare($query_venta);
    
    $result_venta = $stmt_venta->execute([
        $numero_factura, 
        $cliente_id, 
        $usuario_id, 
        $subtotal, 
        $descuentoAplicado,
        $tipo_descuento, 
        $impuesto_total, 
        $total, 
        $metodo_pago,
        $monto_recibido_final,
        $cambio, 
        $observaciones,
        $tipo_venta_bd, // Usar tipo_venta_bd que puede ser diferente de tipo_venta_post
        $abono_inicial
    ]);
    
    if (!$result_venta) {
        $errorInfo = $stmt_venta->errorInfo();
        throw new Exception("Error al insertar la venta: " . ($errorInfo[2] ?? 'Error desconocido'));
    }
    
    $venta_id = $db->lastInsertId();

    // ============================================
    // 9. PROCESAR PRODUCTOS
    // ============================================
    foreach ($productos as $index => $productoData) {
        $producto_id = intval($productoData['id']);
        $cantidad = intval($productoData['cantidad']);
        $precio = floatval($productoData['precio']);
        $subtotal_producto = $precio * $cantidad;
        
        // Verificar stock y producto
        $query_stock = "SELECT stock, nombre, activo FROM productos WHERE id = ?";
        $stmt_stock = $db->prepare($query_stock);
        $stmt_stock->execute([$producto_id]);
        $producto = $stmt_stock->fetch(PDO::FETCH_ASSOC);
        
        if (!$producto) {
            throw new Exception("Producto #" . ($index + 1) . " no encontrado.");
        }
        
        if (!$producto['activo']) {
            throw new Exception("El producto '" . $producto['nombre'] . "' está inactivo.");
        }
        
        if ($producto['stock'] < $cantidad) {
            throw new Exception("Stock insuficiente para '" . $producto['nombre'] . "'. Disponible: " . $producto['stock'] . ", Solicitado: $cantidad");
        }
        
        // Insertar detalle de venta
        $query_detalle = "INSERT INTO venta_detalles (venta_id, producto_id, cantidad, precio, subtotal) 
                         VALUES (?, ?, ?, ?, ?)";
        $stmt_detalle = $db->prepare($query_detalle);
        $result_detalle = $stmt_detalle->execute([
            $venta_id, 
            $producto_id, 
            $cantidad, 
            $precio, 
            $subtotal_producto
        ]);
        
        if (!$result_detalle) {
            $errorInfo = $stmt_detalle->errorInfo();
            throw new Exception("Error al insertar detalle de venta: " . ($errorInfo[2] ?? 'Error desconocido'));
        }
        
        // Actualizar stock
        $query_update_stock = "UPDATE productos SET stock = stock - ?, updated_at = NOW() WHERE id = ?";
        $stmt_update_stock = $db->prepare($query_update_stock);
        $result_update = $stmt_update_stock->execute([$cantidad, $producto_id]);
        
        if (!$result_update) {
            throw new Exception("Error al actualizar stock del producto '" . $producto['nombre'] . "'.");
        }
    }

    // ============================================
    // 10. PROCESAR PAGOS MIXTOS (SI APLICA)
    // ============================================
    if ($es_pago_mixto && $tipo_venta_post === 'contado') {
        // Verificar si la tabla pagos_mixtos_detalles existe
        $query_check_table = "SHOW TABLES LIKE 'pagos_mixtos_detalles'";
        $stmt_check_table = $db->query($query_check_table);
        
        if ($stmt_check_table->fetch()) {
            // Insertar pagos mixtos
            $pagos_mixtos = [
                ['metodo' => 'efectivo', 'monto' => $monto_efectivo_mixto],
                ['metodo' => 'tarjeta', 'monto' => $monto_tarjeta_mixto],
                ['metodo' => 'transferencia', 'monto' => $monto_transferencia_mixto],
                ['metodo' => 'otro', 'monto' => $monto_otro_mixto]
            ];
            
            foreach ($pagos_mixtos as $pago) {
                if ($pago['monto'] > 0) {
                    $query_pago_mixto = "INSERT INTO pagos_mixtos_detalles (venta_id, metodo, monto) 
                                        VALUES (?, ?, ?)";
                    $stmt_pago_mixto = $db->prepare($query_pago_mixto);
                    $result_pago_mixto = $stmt_pago_mixto->execute([
                        $venta_id,
                        $pago['metodo'],
                        $pago['monto']
                    ]);
                    
                    if (!$result_pago_mixto) {
                        error_log("⚠️ Error al insertar pago mixto: " . print_r($pago, true));
                        // Continuar aunque falle un pago mixto
                    }
                }
            }
            
            // Log para depuración
            error_log("✅ Pagos mixtos registrados para venta ID: $venta_id");
            error_log("   - Efectivo: $monto_efectivo_mixto");
            error_log("   - Tarjeta: $monto_tarjeta_mixto");
            error_log("   - Transferencia: $monto_transferencia_mixto");
            error_log("   - Otro: $monto_otro_mixto");
            error_log("   - Total: " . ($monto_efectivo_mixto + $monto_tarjeta_mixto + $monto_transferencia_mixto + $monto_otro_mixto));
            
        } else {
            error_log("⚠️ Advertencia: Tabla pagos_mixtos_detalles no existe. Crearla con el SQL proporcionado.");
        }
    }

    // ============================================
    // 11. PROCESAR CUENTAS POR COBRAR (SI ES CRÉDITO)
    // ============================================
    // IMPORTANTE: Solo crear cuenta por cobrar si ORIGINALMENTE era crédito y hay saldo pendiente
    if ($tipo_venta_post === 'credito' && $saldo_pendiente > 0) {
        try {
            // Insertar cuenta por cobrar
            $observaciones_cuenta = "Venta a crédito - Factura: $numero_factura - Vendedor: $usuario_nombre";
            $estado_cuenta = ($abono_inicial > 0) ? 'parcial' : 'pendiente';
            
            $query_cuenta = "INSERT INTO cuentas_por_cobrar (
                venta_id, cliente_id, total_deuda, saldo_pendiente,
                fecha_limite, estado, observaciones, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt_cuenta = $db->prepare($query_cuenta);
            $result_cuenta = $stmt_cuenta->execute([
                $venta_id,
                $cliente_id,
                $total,
                $saldo_pendiente,
                $fecha_limite,
                $estado_cuenta,
                $observaciones_cuenta
            ]);
            
            if (!$result_cuenta) {
                $errorInfo = $stmt_cuenta->errorInfo();
                error_log("⚠️ Error al crear cuenta por cobrar: " . ($errorInfo[2] ?? 'Error desconocido'));
            } else {
                $cuenta_id = $db->lastInsertId();
                error_log("✅ Cuenta por cobrar creada - ID: $cuenta_id");
                
                // Si hay abono inicial, registrar pago
                if ($abono_inicial > 0) {
                    $query_pago = "INSERT INTO pagos_cuentas_por_cobrar (
                        cuenta_id, monto, metodo_pago, tipo_pago, referencia,
                        usuario_id, observaciones, created_at
                    ) VALUES (?, ?, ?, 'abono_inicial', ?, ?, ?, NOW())";
                    
                    $stmt_pago = $db->prepare($query_pago);
                    $result_pago = $stmt_pago->execute([
                        $cuenta_id,
                        $abono_inicial,
                        $metodo_pago,
                        "Abono inicial - Factura: $numero_factura",
                        $usuario_id,
                        "Abono inicial de venta a crédito"
                    ]);
                    
                    if (!$result_pago) {
                        error_log("⚠️ Error al registrar abono inicial");
                    } else {
                        error_log("✅ Abono inicial registrado: $abono_inicial");
                    }
                }
            }
        } catch (Exception $e) {
            error_log("⚠️ Advertencia en venta a crédito: " . $e->getMessage());
            // No detenemos el proceso
        }
    }

    // ============================================
    // 12. CONFIRMAR TRANSACCIÓN
    // ============================================
    $db->commit();
    
    // ============================================
    // 13. RESPONDER AL CLIENTE CON JSON VÁLIDO
    // ============================================
    if ($isAjax) {
        // Asegurar que el contenido sea JSON válido
        header('Content-Type: application/json; charset=utf-8');
        
        $respuesta = [
            'success' => true,
            'venta_id' => (int)$venta_id,
            'numero_factura' => $numero_factura,
            'total' => (float)$total,
            'tipo_venta' => $tipo_venta_bd,
            'tipo_venta_original' => $tipo_venta_post,
            'metodo_pago' => $metodo_pago,
            'message' => 'Venta procesada exitosamente'
        ];
        
        // Agregar datos específicos según el tipo de venta
        if ($tipo_venta_post === 'credito') {
            $respuesta['abono_inicial'] = (float)$abono_inicial;
            $respuesta['saldo_pendiente'] = (float)$saldo_pendiente;
        } else {
            $respuesta['monto_recibido'] = (float)$monto_recibido_final;
            $respuesta['cambio'] = (float)$cambio;
        }
        
        // Agregar datos de pago mixto si aplica
        if ($es_pago_mixto && $tipo_venta_post === 'contado') {
            $respuesta['pago_mixto'] = true;
            $respuesta['desglose_pago_mixto'] = [
                'efectivo' => (float)$monto_efectivo_mixto,
                'tarjeta' => (float)$monto_tarjeta_mixto,
                'transferencia' => (float)$monto_transferencia_mixto,
                'otro' => (float)$monto_otro_mixto
            ];
        }
        
        // Limpiar el buffer de salida
        ob_clean();
        
        // Enviar respuesta JSON
        $json_response = json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        
        if ($json_response === false) {
            // Si hay error en la codificación JSON, enviar error
            $error_response = json_encode([
                'success' => false,
                'error' => 'Error al generar respuesta JSON: ' . json_last_error_msg(),
                'debug' => $respuesta
            ], JSON_UNESCAPED_UNICODE);
            
            echo $error_response;
            error_log("❌ Error JSON encoding: " . json_last_error_msg() . " - Data: " . print_r($respuesta, true));
        } else {
            echo $json_response;
        }
        
        exit;
    } else {
        // Redirección normal (para formularios tradicionales)
        $mensaje_success = "✅ VENTA PROCESADA EXITOSAMENTE<br>" .
                          "N° Factura: <strong>$numero_factura</strong><br>" .
                          "Total: <strong>$moneda " . number_format($total, 2) . "</strong><br>";
        
        if ($tipo_venta_post === 'credito') {
            $mensaje_success .= "Abono inicial: <strong>$moneda " . number_format($abono_inicial, 2) . "</strong><br>" .
                               "Saldo pendiente: <strong>$moneda " . number_format($saldo_pendiente, 2) . "</strong>";
        } elseif ($es_pago_mixto) {
            $mensaje_success .= "Método de pago: <strong>MIXTO</strong><br>" .
                               "Desglose: Efectivo: $moneda " . number_format($monto_efectivo_mixto, 2) .
                               ", Tarjeta: $moneda " . number_format($monto_tarjeta_mixto, 2) .
                               ", Transferencia: $moneda " . number_format($monto_transferencia_mixto, 2) .
                               ", Otro: $moneda " . number_format($monto_otro_mixto, 2);
        } else {
            $mensaje_success .= "Monto recibido: <strong>$moneda " . number_format($monto_recibido, 2) . "</strong><br>" .
                               "Cambio: <strong>$moneda " . number_format($cambio, 2) . "</strong>";
        }
        
        $_SESSION['success'] = $mensaje_success;
        
        // Redirigir según el tipo de venta
        if ($tipo_venta_post === 'credito') {
            header('Location: ver.php?id=' . $venta_id);
        } else {
            header('Location: imprimir_ticket.php?id=' . $venta_id);
        }
        exit;
    }

} catch (Exception $e) {
    // Rollback en caso de error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    
    // Registrar error en log
    error_log("❌ ERROR en procesar_venta.php: " . $e->getMessage() . " - Usuario ID: " . ($auth->getUserId() ?? 'N/A'));
    error_log("📋 Datos recibidos: " . print_r($_POST, true));
    
    // Responder al cliente
    if ($isAjax) {
        // Limpiar buffer
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        
        $error_response = json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'debug' => [
                'tipo_venta_post' => $tipo_venta_post ?? 'N/A',
                'metodo_pago' => $metodo_pago ?? 'N/A',
                'es_pago_mixto' => $es_pago_mixto ?? false
            ]
        ], JSON_UNESCAPED_UNICODE);
        
        echo $error_response;
        exit;
    } else {
        // Mostrar mensaje de error al usuario
        $_SESSION['error'] = "❌ ERROR: " . $e->getMessage();
        
        // Redirigir de vuelta al formulario
        header('Location: crear.php');
        exit;
    }
}

ob_end_flush();
?>