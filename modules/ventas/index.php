<?php
if (session_status() === PHP_SESSION_NONE) session_start(); 
ob_start();

// Incluir config para tener acceso a $auth
$config_path = '../../includes/config.php';
if (!file_exists($config_path)) {
    die("Error: No se encuentra config.php en $config_path");
}
include $config_path;

// Incluir header
$header_path = '../../includes/header.php';
if (!file_exists($header_path)) {
    die("Error: No se encuentra header.php en $config_path");
}
include $header_path;

// Configurar zona horaria de Bogotá
date_default_timezone_set('America/Bogota');

// Verificar permisos usando la clase Auth
if (!$auth->hasPermission('ventas', 'lectura')) {
    $_SESSION['error'] = "No tienes permisos para acceder a ventas";
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Parámetros de filtro
$fecha_hoy = date('Y-m-d');
$fecha_inicio = $_GET['fecha_inicio'] ?? $fecha_hoy;
$fecha_fin = $_GET['fecha_fin'] ?? $fecha_hoy;
$estado = $_GET['estado'] ?? '';
$tipo_venta = $_GET['tipo_venta'] ?? '';
$metodo_pago = $_GET['metodo_pago'] ?? '';
$busqueda = $_GET['busqueda'] ?? '';

// CONSULTA PRINCIPAL CON BÚSQUEDA INDEPENDIENTE
$query = "SELECT v.*, c.nombre as cliente_nombre, c.numero_documento as cliente_documento, 
                 u.nombre as usuario_nombre,
                 (SELECT COUNT(*) FROM devoluciones d WHERE d.venta_id = v.id) as tiene_devoluciones
          FROM ventas v 
          LEFT JOIN clientes c ON v.cliente_id = c.id 
          LEFT JOIN usuarios u ON v.usuario_id = u.id 
          WHERE 1=1";

$params = [];
$es_busqueda_activa = !empty($busqueda);

// Si hay búsqueda, IGNORAMOS las fechas y filtros normales
if ($es_busqueda_activa) {
    $query .= " AND (v.numero_factura LIKE ? OR c.nombre LIKE ? OR c.numero_documento LIKE ?)";
    $param_busqueda = "%$busqueda%";
    $params[] = $param_busqueda;
    $params[] = $param_busqueda;
    $params[] = $param_busqueda;
} else {
    // Solo aplicamos filtros de fecha cuando NO hay búsqueda
    $query .= " AND DATE(v.fecha) BETWEEN ? AND ?";
    $params[] = $fecha_inicio;
    $params[] = $fecha_fin;
    
    // Filtro por estado (solo sin búsqueda)
    if ($estado === 'anuladas') {
        $query .= " AND v.anulada = 1";
    } elseif ($estado === 'completadas') {
        $query .= " AND v.anulada = 0 AND v.estado = 'completada'";
    } elseif ($estado === 'pendientes') {
        $query .= " AND v.anulada = 0 AND v.estado = 'pendiente'";
    } elseif ($estado === 'pendiente_credito') {
        $query .= " AND v.anulada = 0 AND v.estado = 'pendiente_credito'";
    } elseif ($estado === 'pagada_credito') {
        $query .= " AND v.anulada = 0 AND v.estado = 'pagada_credito'";
    } else {
        $query .= " AND v.anulada = 0";
    }

    // Filtro por tipo de venta (solo sin búsqueda)
    if ($tipo_venta === 'contado') {
        $query .= " AND (v.tipo_venta = 'contado' OR v.tipo_venta IS NULL OR v.tipo_venta = '')";
    } elseif ($tipo_venta === 'credito') {
        $query .= " AND v.tipo_venta = 'credito'";
    }

    // Filtro por método de pago (solo sin búsqueda)
    if ($metodo_pago && $metodo_pago !== 'todos') {
        $query .= " AND v.metodo_pago = ?";
        $params[] = $metodo_pago;
    }
}

$query .= " ORDER BY v.fecha DESC LIMIT 100"; // Limitamos a 100 resultados para búsquedas

$stmt = $db->prepare($query);
$stmt->execute($params);
$ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------------------
// CONSULTAS PARA ESTADÍSTICAS (SIEMPRE RESPETA FECHAS, IGNORA BÚSQUEDA)
// ---------------------------------------------------------------------------

// 1. TOTALES GENERALES (siempre con fechas)
$query_general = "SELECT 
    COUNT(*) as total_ventas,
    SUM(CASE WHEN anulada = 0 THEN total ELSE 0 END) as total_facturado,
    SUM(CASE WHEN anulada = 0 THEN descuento ELSE 0 END) as total_descuentos
    FROM ventas 
    WHERE DATE(fecha) BETWEEN ? AND ?";
    
$params_general = [$fecha_inicio, $fecha_fin];
$stmt_general = $db->prepare($query_general);
$stmt_general->execute($params_general);
$general = $stmt_general->fetch(PDO::FETCH_ASSOC);

// 2. VENTAS AL CONTADO (siempre con fechas)
$query_contado = "SELECT 
    SUM(CASE WHEN anulada = 0 THEN total ELSE 0 END) as total_contado,
    COUNT(CASE WHEN anulada = 0 THEN 1 END) as cantidad_contado
    FROM ventas 
    WHERE DATE(fecha) BETWEEN ? AND ? 
    AND (tipo_venta = 'contado' OR tipo_venta IS NULL OR tipo_venta = '')";
    
$stmt_contado = $db->prepare($query_contado);
$stmt_contado->execute([$fecha_inicio, $fecha_fin]);
$contado = $stmt_contado->fetch(PDO::FETCH_ASSOC);

// 3. VENTAS A CRÉDITO Y ABONOS (siempre con fechas)
$query_credito = "SELECT 
    SUM(CASE WHEN anulada = 0 THEN total ELSE 0 END) as total_credito,
    COUNT(CASE WHEN anulada = 0 THEN 1 END) as cantidad_credito,
    SUM(CASE WHEN anulada = 0 THEN abono_inicial ELSE 0 END) as total_abonos_iniciales
    FROM ventas 
    WHERE DATE(fecha) BETWEEN ? AND ? 
    AND tipo_venta = 'credito'";
    
$stmt_credito = $db->prepare($query_credito);
$stmt_credito->execute([$fecha_inicio, $fecha_fin]);
$credito = $stmt_credito->fetch(PDO::FETCH_ASSOC);

// 4. MÉTODOS DE PAGO (siempre con fechas)
$query_metodos_contado = "SELECT 
    v.id as venta_id,
    v.metodo_pago,
    v.total,
    v.abono_inicial,
    v.tipo_venta
    FROM ventas v
    WHERE DATE(v.fecha) BETWEEN ? AND ? 
    AND v.anulada = 0
    AND (v.tipo_venta = 'contado' OR v.tipo_venta IS NULL OR v.tipo_venta = '')";
    
$stmt_metodos_contado = $db->prepare($query_metodos_contado);
$stmt_metodos_contado->execute([$fecha_inicio, $fecha_fin]);
$ventas_contado = $stmt_metodos_contado->fetchAll(PDO::FETCH_ASSOC);

// 5. ABONOS DE CRÉDITO (siempre con fechas)
$query_abonos_credito = "SELECT 
    v.id as venta_id,
    v.metodo_pago,
    v.abono_inicial
    FROM ventas v
    WHERE DATE(v.fecha) BETWEEN ? AND ? 
    AND v.anulada = 0
    AND v.tipo_venta = 'credito'
    AND v.abono_inicial > 0";
    
$stmt_abonos_credito = $db->prepare($query_abonos_credito);
$stmt_abonos_credito->execute([$fecha_inicio, $fecha_fin]);
$abonos_credito = $stmt_abonos_credito->fetchAll(PDO::FETCH_ASSOC);

// 6. VENTAS CON DEVOLUCIONES (siempre con fechas)
$query_ventas_con_devoluciones = "SELECT 
    COUNT(DISTINCT v.id) as ventas_con_devoluciones,
    SUM(d.monto_devolucion) as total_devoluciones
    FROM ventas v
    LEFT JOIN devoluciones d ON v.id = d.venta_id
    WHERE DATE(v.fecha) BETWEEN ? AND ? 
    AND v.anulada = 0
    AND d.id IS NOT NULL";
    
$stmt_devoluciones = $db->prepare($query_ventas_con_devoluciones);
$stmt_devoluciones->execute([$fecha_inicio, $fecha_fin]);
$devoluciones_stats = $stmt_devoluciones->fetch(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------------------
// FUNCIÓN PARA OBTENER DESGLOSE DE PAGOS MIXTOS
// ---------------------------------------------------------------------------
function obtenerDesgloseMixto($db, $venta_id) {
    $query_desglose = "SELECT 
        SUM(CASE WHEN metodo = 'efectivo' THEN monto ELSE 0 END) as total_efectivo,
        SUM(CASE WHEN metodo = 'tarjeta' THEN monto ELSE 0 END) as total_tarjeta,
        SUM(CASE WHEN metodo = 'transferencia' THEN monto ELSE 0 END) as total_transferencia,
        SUM(CASE WHEN metodo = 'otro' THEN monto ELSE 0 END) as total_otro
        FROM pagos_mixtos_detalles 
        WHERE venta_id = ?";
    
    $stmt_desglose = $db->prepare($query_desglose);
    $stmt_desglose->execute([$venta_id]);
    $desglose = $stmt_desglose->fetch(PDO::FETCH_ASSOC);
    
    return $desglose ?: [
        'total_efectivo' => 0,
        'total_tarjeta' => 0,
        'total_transferencia' => 0,
        'total_otro' => 0
    ];
}

// ---------------------------------------------------------------------------
// CÁLCULOS PRECISOS CON DESGLOSE DE PAGOS MIXTOS (SIEMPRE CON FECHAS)
// ---------------------------------------------------------------------------

// Inicializar variables para métodos de pago
$total_efectivo = 0;
$total_tarjeta = 0;
$total_transferencia = 0;
$total_mixto_efectivo = 0;
$total_mixto_tarjeta = 0;
$total_mixto_transferencia = 0;
$total_mixto_otro = 0;
$total_ventas_mixto = 0;

// Procesar ventas al contado
foreach ($ventas_contado as $venta) {
    if ($venta['metodo_pago'] === 'mixto') {
        // Obtener desglose del pago mixto
        $desglose = obtenerDesgloseMixto($db, $venta['venta_id']);
        
        // Sumar a los totales específicos
        $total_mixto_efectivo += $desglose['total_efectivo'];
        $total_mixto_tarjeta += $desglose['total_tarjeta'];
        $total_mixto_transferencia += $desglose['total_transferencia'];
        $total_mixto_otro += $desglose['total_otro'];
        $total_ventas_mixto++;
        
        // Sumar también a los totales generales de cada método
        $total_efectivo += $desglose['total_efectivo'];
        $total_tarjeta += $desglose['total_tarjeta'];
        $total_transferencia += $desglose['total_transferencia'];
        
    } else {
        // Métodos de pago normales
        switch(strtolower($venta['metodo_pago'])) {
            case 'efectivo':
                $total_efectivo += $venta['total'];
                break;
            case 'tarjeta':
                $total_tarjeta += $venta['total'];
                break;
            case 'transferencia':
                $total_transferencia += $venta['total'];
                break;
        }
    }
}

// Procesar abonos de crédito
foreach ($abonos_credito as $abono) {
    if ($abono['metodo_pago'] === 'mixto') {
        // Obtener desglose del pago mixto (pero solo del abono)
        $desglose = obtenerDesgloseMixto($db, $abono['venta_id']);
        
        // Sumar a los totales específicos (solo el abono proporcional)
        $abono_total = $abono['abono_inicial'];
        $venta_total = 0;
        $query_total_venta = "SELECT total FROM ventas WHERE id = ?";
        $stmt_total = $db->prepare($query_total_venta);
        $stmt_total->execute([$abono['venta_id']]);
        $venta_data = $stmt_total->fetch(PDO::FETCH_ASSOC);
        
        if ($venta_data) {
            $venta_total = $venta_data['total'];
            if ($venta_total > 0) {
                $proporcion = $abono_total / $venta_total;
                
                $total_mixto_efectivo += $desglose['total_efectivo'] * $proporcion;
                $total_mixto_tarjeta += $desglose['total_tarjeta'] * $proporcion;
                $total_mixto_transferencia += $desglose['total_transferencia'] * $proporcion;
                $total_mixto_otro += $desglose['total_otro'] * $proporcion;
                
                $total_efectivo += $desglose['total_efectivo'] * $proporcion;
                $total_tarjeta += $desglose['total_tarjeta'] * $proporcion;
                $total_transferencia += $desglose['total_transferencia'] * $proporcion;
            }
        }
    } else {
        // Métodos de pago normales para abonos
        switch(strtolower($abono['metodo_pago'])) {
            case 'efectivo':
                $total_efectivo += $abono['abono_inicial'];
                break;
            case 'tarjeta':
                $total_tarjeta += $abono['abono_inicial'];
                break;
            case 'transferencia':
                $total_transferencia += $abono['abono_inicial'];
                break;
        }
    }
}

// Totales principales (siempre del período de fechas)
$total_facturado = $general['total_facturado'] ?? 0;
$total_ventas = $general['total_ventas'] ?? 0;
$total_descuentos = $general['total_descuentos'] ?? 0;

// Ventas al contado
$total_contado = $contado['total_contado'] ?? 0;
$cantidad_contado = $contado['cantidad_contado'] ?? 0;

// Ventas a crédito
$total_credito = $credito['total_credito'] ?? 0;
$cantidad_credito = $credito['cantidad_credito'] ?? 0;
$total_abonos_iniciales = $credito['total_abonos_iniciales'] ?? 0;

// Ventas con devoluciones
$ventas_con_devoluciones = $devoluciones_stats['ventas_con_devoluciones'] ?? 0;
$total_devoluciones = $devoluciones_stats['total_devoluciones'] ?? 0;

// Deuda pendiente y ingresos reales
$deuda_pendiente_creditos = $total_credito - $total_abonos_iniciales;
if ($deuda_pendiente_creditos < 0) $deuda_pendiente_creditos = 0;
$ingresos_reales = $total_contado + $total_abonos_iniciales;

// Totales métodos de pago combinados (dinero que realmente entró)
$total_metodos_combinados = $total_efectivo + $total_tarjeta + $total_transferencia + $total_mixto_otro;

// Porcentajes métodos de pago
$porcentaje_efectivo = $total_metodos_combinados > 0 ? ($total_efectivo / $total_metodos_combinados) * 100 : 0;
$porcentaje_tarjeta = $total_metodos_combinados > 0 ? ($total_tarjeta / $total_metodos_combinados) * 100 : 0;
$porcentaje_transferencia = $total_metodos_combinados > 0 ? ($total_transferencia / $total_metodos_combinados) * 100 : 0;

// Porcentajes generales
$porcentaje_contado = $total_facturado > 0 ? ($total_contado / $total_facturado) * 100 : 0;
$porcentaje_credito = $total_facturado > 0 ? ($total_credito / $total_facturado) * 100 : 0;
$porcentaje_abonado = $total_credito > 0 ? ($total_abonos_iniciales / $total_credito) * 100 : 0;
$porcentaje_deuda = $total_credito > 0 ? ($deuda_pendiente_creditos / $total_credito) * 100 : 0;
?>

<div class="max-w-7xl mx-auto px-2 sm:px-4">
    <!-- Encabezado con botones alineados -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-4 space-y-3 sm:space-y-0">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Ventas</h1>
            <p class="text-sm sm:text-base text-gray-600">Gestiona las ventas del sistema</p>
        </div>
        
        <div class="flex flex-wrap gap-2">
            <!-- Botón de Nueva Venta (SOLO si tiene permiso CREAR) -->
            <?php if ($auth->hasPermission('ventas', 'crear') || $auth->hasPermission('ventas', 'escritura')): ?>
            <a href="crear.php" class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 sm:px-4 sm:py-2 rounded-lg flex items-center shadow-md hover:shadow-lg transition-shadow text-sm sm:text-base">
                <i class="fas fa-plus-circle mr-1 sm:mr-2"></i>
                <span class="hidden sm:inline">Nueva Venta</span>
                <span class="inline sm:hidden">Nueva</span>
            </a>
            <?php endif; ?>
            
            <!-- Botón de Resumen del Día -->
            <a href="resumen_dia.php" 
               class="bg-gradient-to-r from-blue-500 to-blue-700 hover:from-blue-600 hover:to-blue-800 text-white px-3 py-2 sm:px-4 sm:py-2 rounded-lg flex items-center shadow-md hover:shadow-lg transition-shadow text-sm sm:text-base">
                <i class="fas fa-chart-pie mr-1 sm:mr-2"></i>
                <span class="hidden sm:inline">Resumen Día</span>
                <span class="inline sm:hidden">Resumen</span>
            </a>
            
            <!-- Botón de Cierre de Caja (solo si tiene permiso) -->
            <?php if ($auth->hasPermission('cierre_caja', 'lectura')): ?>
            <a href="cierre_caja.php?fecha=<?php echo $fecha_inicio; ?>" 
               class="bg-gradient-to-r from-purple-600 to-purple-800 hover:from-purple-700 hover:to-purple-900 text-white px-3 py-2 sm:px-4 sm:py-2 rounded-lg flex items-center shadow-md hover:shadow-lg transition-shadow text-sm sm:text-base">
                <i class="fas fa-calculator mr-1 sm:mr-2"></i>
                <span class="hidden sm:inline">Cierre Caja</span>
                <span class="inline sm:hidden">Cierre</span>
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Información de fecha y hora -->
    <div class="mb-4">
        <p class="text-xs sm:text-sm text-gray-500 flex flex-wrap items-center gap-2">
            <?php if ($es_busqueda_activa): ?>
                <span class="flex items-center text-orange-600">
                    <i class="fas fa-search mr-1"></i>
                    Búsqueda activa: <strong class="ml-1">"<?php echo htmlspecialchars($busqueda); ?>"</strong>
                    <span class="ml-2 text-gray-500">(Ignorando filtros de fecha)</span>
                </span>
            <?php else: ?>
                <span class="flex items-center">
                    <i class="fas fa-calendar-day mr-1"></i>
                    Período: <strong class="ml-1"><?php echo date('d/m/Y', strtotime($fecha_inicio)); ?></strong>
                    <?php if ($fecha_inicio !== $fecha_fin): ?>
                     al <strong><?php echo date('d/m/Y', strtotime($fecha_fin)); ?></strong>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
            <span class="hidden sm:inline">|</span>
            <span class="flex items-center">
                <i class="fas fa-clock mr-1"></i>Bogotá <?php echo date('H:i'); ?>
            </span>
        </p>
        <?php if ($es_busqueda_activa): ?>
        <p class="text-xs text-gray-500 mt-1">
            <i class="fas fa-info-circle mr-1"></i>
            Se muestran <strong>todas las ventas</strong> que coinciden con la búsqueda, sin importar la fecha.
        </p>
        <?php endif; ?>
    </div>
    
    <!-- BARRA DE BÚSQUEDA Y FILTROS MEJORADA -->
    <div class="bg-white rounded-lg shadow mb-4">
        <div class="p-3 sm:p-4">
            <!-- BÚSQUEDA RÁPIDA (INDEPENDIENTE) -->
            <div class="mb-4">
                <form method="GET" class="flex gap-2" id="formBusqueda">
                    <!-- Mantenemos los filtros actuales como hidden -->
                    <input type="hidden" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>">
                    <input type="hidden" name="fecha_fin" value="<?php echo $fecha_fin; ?>">
                    <input type="hidden" name="estado" value="<?php echo $estado; ?>">
                    <input type="hidden" name="tipo_venta" value="<?php echo $tipo_venta; ?>">
                    <input type="hidden" name="metodo_pago" value="<?php echo $metodo_pago; ?>">
                    
                    <div class="flex-1 relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" 
                               name="busqueda" 
                               value="<?php echo htmlspecialchars($busqueda); ?>"
                               class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                               placeholder="Buscar por factura, cliente o cédula (ignora fechas)..."
                               id="inputBusqueda">
                    </div>
                    <button type="submit" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md flex items-center text-sm">
                        <i class="fas fa-search mr-1 sm:mr-2"></i>
                        <span class="hidden sm:inline">Buscar</span>
                    </button>
                    <?php if ($busqueda): ?>
                    <button type="button"
                            onclick="limpiarBusqueda()"
                            class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-md flex items-center text-sm">
                        <i class="fas fa-times"></i>
                    </button>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- FILTROS AVANZADOS (SOLO VISIBLES SIN BÚSQUEDA) -->
            <?php if (!$es_busqueda_activa): ?>
            <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-7 gap-2">
                <input type="hidden" name="busqueda" value="">
                
                <div class="col-span-1">
                    <label for="fecha_inicio" class="block text-xs font-medium text-gray-700 mb-1">Fecha Inicio</label>
                    <input type="date" id="fecha_inicio" name="fecha_inicio" 
                           value="<?php echo $fecha_inicio; ?>"
                           class="block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 text-xs focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div class="col-span-1">
                    <label for="fecha_fin" class="block text-xs font-medium text-gray-700 mb-1">Fecha Fin</label>
                    <input type="date" id="fecha_fin" name="fecha_fin" 
                           value="<?php echo $fecha_fin; ?>"
                           class="block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 text-xs focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div class="col-span-1">
                    <label for="estado" class="block text-xs font-medium text-gray-700 mb-1">Estado</label>
                    <select id="estado" name="estado" class="block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 text-xs focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="todos">Todos</option>
                        <option value="completadas" <?php echo $estado == 'completadas' ? 'selected' : ''; ?>>Completadas</option>
                        <option value="pendientes" <?php echo $estado == 'pendientes' ? 'selected' : ''; ?>>Pendientes</option>
                        <option value="pendiente_credito" <?php echo $estado == 'pendiente_credito' ? 'selected' : ''; ?>>Crédito Pend.</option>
                        <option value="pagada_credito" <?php echo $estado == 'pagada_credito' ? 'selected' : ''; ?>>Crédito Pag.</option>
                        <option value="anuladas" <?php echo $estado == 'anuladas' ? 'selected' : ''; ?>>Anuladas</option>
                    </select>
                </div>
                
                <div class="col-span-1">
                    <label for="tipo_venta" class="block text-xs font-medium text-gray-700 mb-1">Tipo Venta</label>
                    <select id="tipo_venta" name="tipo_venta" class="block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 text-xs focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="todos">Todos</option>
                        <option value="contado" <?php echo $tipo_venta == 'contado' ? 'selected' : ''; ?>>Contado</option>
                        <option value="credito" <?php echo $tipo_venta == 'credito' ? 'selected' : ''; ?>>Crédito</option>
                    </select>
                </div>
                
                <div class="col-span-1">
                    <label for="metodo_pago" class="block text-xs font-medium text-gray-700 mb-1">Método Pago</label>
                    <select id="metodo_pago" name="metodo_pago" class="block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 text-xs focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="todos">Todos</option>
                        <option value="efectivo" <?php echo $metodo_pago == 'efectivo' ? 'selected' : ''; ?>>Efectivo</option>
                        <option value="tarjeta" <?php echo $metodo_pago == 'tarjeta' ? 'selected' : ''; ?>>Tarjeta</option>
                        <option value="transferencia" <?php echo $metodo_pago == 'transferencia' ? 'selected' : ''; ?>>Transferencia</option>
                        <option value="mixto" <?php echo $metodo_pago == 'mixto' ? 'selected' : ''; ?>>Mixto</option>
                    </select>
                </div>
                
                <div class="col-span-1 flex items-end space-x-1 sm:space-x-2">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-md text-xs w-full flex items-center justify-center">
                        <i class="fas fa-filter mr-1"></i> <span class="hidden sm:inline">Filtrar</span><span class="inline sm:hidden">Filtrar</span>
                    </button>
                    <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-3 py-2 rounded-md text-xs flex items-center justify-center">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </form>
            <?php else: ?>
            <!-- Información cuando hay búsqueda activa -->
            <div class="bg-blue-50 border border-blue-200 rounded p-3">
                <div class="flex items-start">
                    <i class="fas fa-info-circle text-blue-500 mt-0.5 mr-2"></i>
                    <div class="text-xs text-blue-700">
                        <strong>Modo búsqueda activo:</strong> Los resultados no están limitados por fechas ni filtros. 
                        Para volver a los filtros normales, <a href="index.php?fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>" class="text-blue-800 font-medium hover:underline">haz clic aquí</a>.
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mostrar mensajes -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <div class="flex items-center">
                <i class="fas fa-check-circle mr-2"></i>
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <div class="flex items-center">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- TARJETAS ESTADÍSTICAS (SIEMPRE MUESTRAN DATOS DEL PERÍODO, NO DE BÚSQUEDA) -->
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2 sm:gap-3 mb-4">
        <!-- Total Facturado -->
        <div class="bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-lg shadow p-2 sm:p-3 text-white">
            <div class="flex items-center justify-between mb-1 sm:mb-2">
                <i class="fas fa-chart-line text-xs sm:text-sm"></i>
                <span class="text-xs font-medium bg-white/20 px-1 sm:px-2 py-0.5 rounded">Ventas</span>
            </div>
            <div class="text-sm sm:text-lg font-bold truncate" title="$<?php echo number_format($total_facturado, 0, ',', '.'); ?>">
                $<?php echo number_format($total_facturado, 0, ',', '.'); ?>
            </div>
            <div class="text-xs opacity-80 mt-1"><?php echo $total_ventas; ?> ventas</div>
        </div>
        
        <!-- Ingresos Reales -->
        <div class="bg-gradient-to-br from-green-500 to-green-600 rounded-lg shadow p-2 sm:p-3 text-white">
            <div class="flex items-center justify-between mb-1 sm:mb-2">
                <i class="fas fa-cash-register text-xs sm:text-sm"></i>
                <span class="text-xs font-medium bg-white/20 px-1 sm:px-2 py-0.5 rounded">Ingresos</span>
            </div>
            <div class="text-sm sm:text-lg font-bold truncate" title="$<?php echo number_format($ingresos_reales, 0, ',', '.'); ?>">
                $<?php echo number_format($ingresos_reales, 0, ',', '.'); ?>
            </div>
            <div class="text-xs opacity-80 mt-1">Dinero recibido</div>
        </div>
        
        <!-- Ventas Contado -->
        <div class="bg-gradient-to-br from-emerald-500 to-emerald-600 rounded-lg shadow p-2 sm:p-3 text-white">
            <div class="flex items-center justify-between mb-1 sm:mb-2">
                <i class="fas fa-money-bill-wave text-xs sm:text-sm"></i>
                <span class="text-xs font-medium bg-white/20 px-1 sm:px-2 py-0.5 rounded">Contado</span>
            </div>
            <div class="text-sm sm:text-lg font-bold truncate" title="$<?php echo number_format($total_contado, 0, ',', '.'); ?>">
                $<?php echo number_format($total_contado, 0, ',', '.'); ?>
            </div>
            <div class="text-xs opacity-80 mt-1"><?php echo number_format($porcentaje_contado, 1); ?>%</div>
        </div>
        
        <!-- Ventas Crédito -->
        <div class="bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg shadow p-2 sm:p-3 text-white">
            <div class="flex items-center justify-between mb-1 sm:mb-2">
                <i class="fas fa-file-invoice-dollar text-xs sm:text-sm"></i>
                <span class="text-xs font-medium bg-white/20 px-1 sm:px-2 py-0.5 rounded">Crédito</span>
            </div>
            <div class="text-sm sm:text-lg font-bold truncate" title="$<?php echo number_format($total_credito, 0, ',', '.'); ?>">
                $<?php echo number_format($total_credito, 0, ',', '.'); ?>
            </div>
            <div class="text-xs opacity-80 mt-1"><?php echo number_format($porcentaje_credito, 1); ?>%</div>
        </div>
        
        <!-- Deuda Pendiente -->
        <div class="bg-gradient-to-br from-red-500 to-red-600 rounded-lg shadow p-2 sm:p-3 text-white">
            <div class="flex items-center justify-between mb-1 sm:mb-2">
                <i class="fas fa-clock text-xs sm:text-sm"></i>
                <span class="text-xs font-medium bg-white/20 px-1 sm:px-2 py-0.5 rounded">Deuda</span>
            </div>
            <div class="text-sm sm:text-lg font-bold truncate" title="$<?php echo number_format($deuda_pendiente_creditos, 0, ',', '.'); ?>">
                $<?php echo number_format($deuda_pendiente_creditos, 0, ',', '.'); ?>
            </div>
            <div class="text-xs opacity-80 mt-1">Por cobrar</div>
        </div>
        
        <!-- Ventas con Devoluciones -->
        <div class="bg-gradient-to-br from-orange-500 to-orange-600 rounded-lg shadow p-2 sm:p-3 text-white">
            <div class="flex items-center justify-between mb-1 sm:mb-2">
                <i class="fas fa-undo text-xs sm:text-sm"></i>
                <span class="text-xs font-medium bg-white/20 px-1 sm:px-2 py-0.5 rounded">Devoluciones</span>
            </div>
            <div class="text-sm sm:text-lg font-bold truncate" title="<?php echo $ventas_con_devoluciones; ?> ventas">
                <?php echo $ventas_con_devoluciones; ?> ventas
            </div>
            <div class="text-xs opacity-80 mt-1">$<?php echo number_format($total_devoluciones, 0, ',', '.'); ?></div>
        </div>
    </div>

    <!-- Lista de Ventas - TABLA MEJORADA PARA RESPONSIVE -->
    <div class="bg-white shadow overflow-hidden sm:rounded-lg mb-6">
        <div class="px-4 py-3 sm:px-6 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-base sm:text-lg font-medium text-gray-900">
                <i class="fas fa-list mr-2"></i>
                <?php if ($es_busqueda_activa): ?>
                    Resultados de búsqueda
                <?php else: ?>
                    Lista de Ventas
                <?php endif; ?>
                <span class="text-sm font-normal text-gray-500 ml-2">
                    (<?php echo count($ventas); ?> <?php echo $es_busqueda_activa ? 'resultados' : 'ventas'; ?>)
                </span>
            </h3>
            
            <div class="flex items-center space-x-1 sm:space-x-2">
                <!-- Indicador de modo búsqueda -->
                <?php if ($es_busqueda_activa): ?>
                <span class="bg-orange-100 text-orange-800 px-2 sm:px-3 py-1 rounded-md text-xs flex items-center">
                    <i class="fas fa-search mr-1"></i>
                    <span class="hidden sm:inline">Búsqueda activa</span>
                    <span class="inline sm:hidden">Búsqueda</span>
                </span>
                <?php endif; ?>
                
                <!-- Filtro rápido de devoluciones -->
                <?php if ($ventas_con_devoluciones > 0 && !$es_busqueda_activa): ?>
                <button onclick="filtrarDevoluciones()" 
                        class="bg-orange-100 hover:bg-orange-200 text-orange-800 px-2 sm:px-3 py-1 rounded-md text-xs flex items-center" id="btnDevoluciones">
                    <i class="fas fa-undo mr-1"></i>
                    <span class="hidden sm:inline">Ver devoluciones</span>
                    <span class="inline sm:hidden">Devol.</span>
                    <span class="ml-1 bg-orange-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">
                        <?php echo $ventas_con_devoluciones; ?>
                    </span>
                </button>
                <?php endif; ?>
                
                <!-- Botón de imprimir -->
                <button onclick="window.print()" 
                        class="bg-gray-100 hover:bg-gray-200 text-gray-800 px-2 sm:px-3 py-1 rounded-md text-xs flex items-center">
                    <i class="fas fa-print mr-1"></i>
                    <span class="hidden sm:inline">Imprimir</span>
                </button>
            </div>
        </div>
        
        <?php if (count($ventas) > 0): ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-xs sm:text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 sm:px-6 sm:py-3 text-left font-medium text-gray-500 uppercase tracking-wider w-20 sm:w-auto">Factura</th>
                            <th class="px-3 py-2 sm:px-6 sm:py-3 text-left font-medium text-gray-500 uppercase tracking-wider hidden sm:table-cell">Cliente</th>
                            <th class="px-3 py-2 sm:px-6 sm:py-3 text-left font-medium text-gray-500 uppercase tracking-wider w-28 sm:w-32">Fecha</th>
                            <th class="px-3 py-2 sm:px-6 sm:py-3 text-left font-medium text-gray-500 uppercase tracking-wider hidden md:table-cell">Tipo / Pago</th>
                            <th class="px-3 py-2 sm:px-6 sm:py-3 text-left font-medium text-gray-500 uppercase tracking-wider">Total</th>
                            <th class="px-3 py-2 sm:px-6 sm:py-3 text-left font-medium text-gray-500 uppercase tracking-wider w-24 sm:w-28">Estado</th>
                            <th class="px-3 py-2 sm:px-6 sm:py-3 text-left font-medium text-gray-500 uppercase tracking-wider w-16 sm:w-20">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200" id="tablaVentas">
                        <?php foreach ($ventas as $venta): 
                            // Determinar clase del estado
                            $estado_venta = $venta['estado'];
                            if ($venta['anulada']) {
                                $estado_class = 'bg-red-100 text-red-800';
                                $estado_text = 'Anulada';
                                $estado_icon = 'fas fa-ban';
                            } elseif ($estado_venta == 'completada') {
                                $estado_class = 'bg-green-100 text-green-800';
                                $estado_text = 'Completada';
                                $estado_icon = 'fas fa-check-circle';
                            } elseif ($estado_venta == 'pendiente') {
                                $estado_class = 'bg-yellow-100 text-yellow-800';
                                $estado_text = 'Pendiente';
                                $estado_icon = 'fas fa-clock';
                            } elseif ($estado_venta == 'pendiente_credito') {
                                $estado_class = 'bg-orange-100 text-orange-800';
                                $estado_text = 'Crédito Pendiente';
                                $estado_icon = 'fas fa-clock';
                            } elseif ($estado_venta == 'pagada_credito') {
                                $estado_class = 'bg-blue-100 text-blue-800';
                                $estado_text = 'Crédito Pagado';
                                $estado_icon = 'fas fa-check-double';
                            } else {
                                $estado_class = 'bg-gray-100 text-gray-800';
                                $estado_text = $estado_venta ? ucfirst(str_replace('_', ' ', $estado_venta)) : 'Completada';
                                $estado_icon = 'fas fa-question-circle';
                            }
                            
                            // Determinar tipo de venta y estilo
                            $tipo_venta_valor = $venta['tipo_venta'] ?? 'contado';
                            if ($tipo_venta_valor == 'credito') {
                                $tipo_class = 'bg-purple-100 text-purple-800';
                                $tipo_text = 'Crédito';
                                $tipo_icon = 'fas fa-hand-holding-usd';
                                
                                // Obtener información de cuenta por cobrar
                                $query_cuenta = "SELECT cp.id as cuenta_id, cp.saldo_pendiente, cp.estado as estado_cuenta 
                                                FROM cuentas_por_cobrar cp 
                                                WHERE cp.venta_id = ?";
                                $stmt_cuenta = $db->prepare($query_cuenta);
                                $stmt_cuenta->execute([$venta['id']]);
                                $cuenta = $stmt_cuenta->fetch(PDO::FETCH_ASSOC);
                                
                                $cuenta_id = $cuenta['cuenta_id'] ?? null;
                                $saldo_pendiente = $cuenta['saldo_pendiente'] ?? 0;
                                $estado_cuenta = $cuenta['estado_cuenta'] ?? 'pendiente';
                            } else {
                                $tipo_class = 'bg-green-100 text-green-800';
                                $tipo_text = 'Contado';
                                $tipo_icon = 'fas fa-money-bill-wave';
                                $cuenta_id = null;
                                $saldo_pendiente = 0;
                                $estado_cuenta = '';
                            }
                            
                            // Método de pago
                            $metodo_pago_venta = $venta['metodo_pago'] ?? 'efectivo';
                            $metodo_text = ucfirst($metodo_pago_venta);
                            $metodo_icon = '';
                            $metodo_color = '';
                            
                            switch(strtolower($metodo_pago_venta)) {
                                case 'efectivo':
                                    $metodo_icon = 'money-bill-wave';
                                    $metodo_color = 'text-green-600';
                                    break;
                                case 'tarjeta':
                                    $metodo_icon = 'credit-card';
                                    $metodo_color = 'text-blue-600';
                                    break;
                                case 'transferencia':
                                    $metodo_icon = 'university';
                                    $metodo_color = 'text-purple-600';
                                    break;
                                case 'mixto':
                                    $metodo_icon = 'random';
                                    $metodo_color = 'text-orange-600';
                                    break;
                                default:
                                    $metodo_icon = 'money-bill-wave';
                                    $metodo_color = 'text-gray-600';
                            }
                            
                            // OBTENER DESGLOSE DE PAGO MIXTO SI APLICA
                            $desglose_mixto = null;
                            if ($metodo_pago_venta === 'mixto') {
                                $desglose_mixto = obtenerDesgloseMixto($db, $venta['id']);
                            }
                            
                            // Indicador de devoluciones
                            $tiene_devoluciones = $venta['tiene_devoluciones'] > 0;
                            $devolucion_class = $tiene_devoluciones ? 'devolucion-row bg-orange-50 hover:bg-orange-100' : '';
                        ?>
                        <tr class="hover:bg-gray-50 <?php echo $devolucion_class; ?>" data-devolucion="<?php echo $tiene_devoluciones ? 'si' : 'no'; ?>">
                            <!-- Factura - MÓVIL: Mostrar más información -->
                            <td class="px-3 py-3 sm:px-6 sm:py-4 whitespace-nowrap">
                                <div class="flex flex-col">
                                    <div class="text-xs sm:text-sm font-medium text-gray-900 flex items-center">
                                        <?php echo $venta['numero_factura']; ?>
                                        <?php if ($tiene_devoluciones): ?>
                                        <span class="ml-1 sm:ml-2" title="Esta venta tiene devoluciones">
                                            <i class="fas fa-undo text-orange-500 text-xs"></i>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-xs text-gray-500 truncate sm:hidden">
                                        <?php echo $venta['cliente_nombre'] ?? 'Cliente General'; ?>
                                        <?php if ($venta['cliente_documento']): ?>
                                        <span class="text-gray-400 ml-1">(<?php echo $venta['cliente_documento']; ?>)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-xs text-gray-500 sm:hidden">
                                        <?php echo date('d/m H:i', strtotime($venta['fecha'])); ?>
                                    </div>
                                </div>
                            </td>
                            
                            <!-- Cliente - SOLO ESCRITORIO -->
                            <td class="px-3 py-3 sm:px-6 sm:py-4 whitespace-nowrap hidden sm:table-cell">
                                <div class="text-sm text-gray-900">
                                    <?php echo $venta['cliente_nombre'] ?? 'Cliente General'; ?>
                                </div>
                                <?php if ($venta['cliente_documento']): ?>
                                <div class="text-xs text-gray-500">
                                    <?php echo $venta['cliente_documento']; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Fecha - SOLO ESCRITORIO -->
                            <td class="px-3 py-3 sm:px-6 sm:py-4 whitespace-nowrap text-sm text-gray-500 hidden sm:table-cell">
                                <?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?>
                            </td>
                            
                            <!-- Tipo y Método de Pago - SOLO ESCRITORIO -->
                            <td class="px-3 py-3 sm:px-6 sm:py-4 whitespace-nowrap hidden md:table-cell">
                                <div class="flex flex-col space-y-1">
                                    <!-- Tipo de venta -->
                                    <div class="flex items-center">
                                        <i class="<?php echo $tipo_icon; ?> mr-1 text-xs"></i>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $tipo_class; ?>">
                                            <?php echo $tipo_text; ?>
                                        </span>
                                        <?php if ($tipo_venta_valor == 'credito' && isset($venta['abono_inicial']) && $venta['abono_inicial'] > 0): ?>
                                            <span class="ml-1 text-xs text-blue-600 font-medium">
                                                Abono: $<?php echo number_format($venta['abono_inicial'], 0, ',', '.'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Método de pago -->
                                    <div class="flex items-center text-xs text-gray-700">
                                        <i class="fas fa-<?php echo $metodo_icon; ?> mr-1 <?php echo $metodo_color; ?>"></i>
                                        <span class="font-medium"><?php echo $metodo_text; ?></span>
                                    </div>
                                    
                                    <!-- DESGLOSE DE PAGO MIXTO (si aplica) -->
                                    <?php if ($desglose_mixto): ?>
                                    <div class="ml-2 text-xs text-gray-600 space-y-0.5 mt-1 border-l-2 border-orange-300 pl-2">
                                        <?php if ($desglose_mixto['total_efectivo'] > 0): ?>
                                        <div class="flex justify-between">
                                            <span class="flex items-center">
                                                <i class="fas fa-money-bill-wave text-green-500 mr-1 text-xs"></i>
                                                <span>Efectivo:</span>
                                            </span>
                                            <span class="font-medium text-green-600">
                                                $<?php echo number_format($desglose_mixto['total_efectivo'], 0, ',', '.'); ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($desglose_mixto['total_tarjeta'] > 0): ?>
                                        <div class="flex justify-between">
                                            <span class="flex items-center">
                                                <i class="fas fa-credit-card text-blue-500 mr-1 text-xs"></i>
                                                <span>Tarjeta:</span>
                                            </span>
                                            <span class="font-medium text-blue-600">
                                                $<?php echo number_format($desglose_mixto['total_tarjeta'], 0, ',', '.'); ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($desglose_mixto['total_transferencia'] > 0): ?>
                                        <div class="flex justify-between">
                                            <span class="flex items-center">
                                                <i class="fas fa-university text-purple-500 mr-1 text-xs"></i>
                                                <span>Transferencia:</span>
                                            </span>
                                            <span class="font-medium text-purple-600">
                                                $<?php echo number_format($desglose_mixto['total_transferencia'], 0, ',', '.'); ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($desglose_mixto['total_otro'] > 0): ?>
                                        <div class="flex justify-between">
                                            <span class="flex items-center">
                                                <i class="fas fa-ellipsis-h text-gray-500 mr-1 text-xs"></i>
                                                <span>Otro:</span>
                                            </span>
                                            <span class="font-medium text-gray-600">
                                                $<?php echo number_format($desglose_mixto['total_otro'], 0, ',', '.'); ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <!-- Suma total del desglose (para verificación) -->
                                        <div class="flex justify-between pt-1 border-t border-gray-200 mt-1">
                                            <span class="font-medium">Total:</span>
                                            <span class="font-bold text-orange-600">
                                                $<?php echo number_format(
                                                    $desglose_mixto['total_efectivo'] + 
                                                    $desglose_mixto['total_tarjeta'] + 
                                                    $desglose_mixto['total_transferencia'] + 
                                                    $desglose_mixto['total_otro'], 
                                                    0, ',', '.'
                                                ); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Total - PARA TODOS -->
                            <td class="px-3 py-3 sm:px-6 sm:py-4 whitespace-nowrap">
                                <div class="text-sm font-semibold text-gray-900">
                                    $<?php echo number_format($venta['total'], 0, ',', '.'); ?>
                                </div>
                                <?php if ($venta['descuento'] > 0): ?>
                                <div class="text-xs text-yellow-600 mt-1 flex items-center">
                                    <i class="fas fa-tag mr-1 text-xs"></i>
                                    Desc: $<?php echo number_format($venta['descuento'], 0, ',', '.'); ?>
                                </div>
                                <?php endif; ?>
                                <!-- INFO PARA MÓVIL -->
                                <div class="text-xs text-gray-500 mt-1 sm:hidden">
                                    <div class="flex items-center">
                                        <i class="<?php echo $tipo_icon; ?> mr-1"></i>
                                        <span class="mr-2"><?php echo $tipo_text; ?></span>
                                        <i class="fas fa-<?php echo $metodo_icon; ?> mr-1 <?php echo $metodo_color; ?>"></i>
                                        <span><?php echo $metodo_text; ?></span>
                                    </div>
                                    <!-- DESGLOSE MÓVIL PARA PAGO MIXTO -->
                                    <?php if ($desglose_mixto): ?>
                                    <div class="mt-1 text-xs text-gray-600 space-y-0.5">
                                        <?php if ($desglose_mixto['total_efectivo'] > 0): ?>
                                        <div class="flex justify-between">
                                            <span class="flex items-center">
                                                <i class="fas fa-money-bill-wave text-green-500 mr-1 text-xs"></i>
                                                <span>Efec:</span>
                                            </span>
                                            <span>$<?php echo number_format($desglose_mixto['total_efectivo'], 0, ',', '.'); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($desglose_mixto['total_tarjeta'] > 0): ?>
                                        <div class="flex justify-between">
                                            <span class="flex items-center">
                                                <i class="fas fa-credit-card text-blue-500 mr-1 text-xs"></i>
                                                <span>Tarj:</span>
                                            </span>
                                            <span>$<?php echo number_format($desglose_mixto['total_tarjeta'], 0, ',', '.'); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($desglose_mixto['total_transferencia'] > 0): ?>
                                        <div class="flex justify-between">
                                            <span class="flex items-center">
                                                <i class="fas fa-university text-purple-500 mr-1 text-xs"></i>
                                                <span>Transf:</span>
                                            </span>
                                            <span>$<?php echo number_format($desglose_mixto['total_transferencia'], 0, ',', '.'); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($desglose_mixto['total_otro'] > 0): ?>
                                        <div class="flex justify-between">
                                            <span class="flex items-center">
                                                <i class="fas fa-ellipsis-h text-gray-500 mr-1 text-xs"></i>
                                                <span>Otro:</span>
                                            </span>
                                            <span>$<?php echo number_format($desglose_mixto['total_otro'], 0, ',', '.'); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Estado - PARA TODOS -->
                            <td class="px-3 py-3 sm:px-6 sm:py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $estado_class; ?>">
                                        <i class="<?php echo $estado_icon; ?> mr-1"></i>
                                        <span class="truncate"><?php echo $estado_text; ?></span>
                                    </span>
                                    <?php if ($tiene_devoluciones): ?>
                                    <span class="ml-1" title="Con devoluciones">
                                        <i class="fas fa-undo text-orange-500 text-xs"></i>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Acciones - PARA TODOS -->
                            <td class="px-3 py-3 sm:px-6 sm:py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex items-center space-x-1 sm:space-x-2">
                                    <!-- Botón VER -->
                                    <a href="ver.php?id=<?php echo $venta['id']; ?>" 
                                       class="text-blue-600 hover:text-blue-900 p-1 rounded hover:bg-blue-50"
                                       title="Ver detalles">
                                        <i class="fas fa-eye text-xs sm:text-sm"></i>
                                    </a>
                                    
                                    <!-- Botón IMPRIMIR -->
                                    <a href="imprimir_ticket.php?id=<?php echo $venta['id']; ?>" 
                                       target="_blank"
                                       class="text-gray-600 hover:text-gray-900 p-1 rounded hover:bg-gray-50"
                                       title="Imprimir ticket">
                                        <i class="fas fa-print text-xs sm:text-sm"></i>
                                    </a>
                                    
                                    <!-- Botón DEVOLUCIÓN (si aplica) -->
                                    <?php if (!$venta['anulada'] && $auth->hasPermission('ventas', 'editar')): ?>
                                        <?php if ($tiene_devoluciones): ?>
                                        <a href="ver.php?id=<?php echo $venta['id']; ?>#devoluciones"
                                           class="text-orange-600 hover:text-orange-900 p-1 rounded hover:bg-orange-50"
                                           title="Ver devoluciones">
                                            <i class="fas fa-undo text-xs sm:text-sm"></i>
                                        </a>
                                        <?php else: ?>
                                        <a href="devolucion.php?id=<?php echo $venta['id']; ?>"
                                           class="text-gray-400 hover:text-orange-600 p-1 rounded hover:bg-orange-50"
                                           title="Registrar devolución">
                                            <i class="fas fa-undo text-xs sm:text-sm"></i>
                                        </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <!-- Botón ANULAR (solo admin) -->
                                    <?php if (!$venta['anulada'] && $auth->hasPermission('ventas', 'eliminar')): ?>
                                    <button onclick="confirmarAnulacionVenta(<?php echo $venta['id']; ?>, '<?php echo addslashes($venta['numero_factura']); ?>')" 
                                            class="text-gray-400 hover:text-red-600 p-1 rounded hover:bg-red-50"
                                            title="Anular venta">
                                        <i class="fas fa-ban text-xs sm:text-sm"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Resumen de ventas con devoluciones -->
            <?php if ($ventas_con_devoluciones > 0 && !$es_busqueda_activa): ?>
            <div class="bg-orange-50 border-t border-orange-200 px-4 py-3 sm:px-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <i class="fas fa-undo text-orange-500 mr-2"></i>
                        <span class="text-sm text-orange-800">
                            <?php echo $ventas_con_devoluciones; ?> venta(s) tienen devoluciones registradas
                        </span>
                    </div>
                    <button onclick="mostrarSoloDevoluciones()" 
                            class="text-xs text-orange-600 hover:text-orange-800 font-medium">
                        <i class="fas fa-filter mr-1"></i> Filtrar devoluciones
                    </button>
                </div>
            </div>
            <?php endif; ?>
            
        <?php else: ?>
            <div class="text-center py-12 px-4">
                <?php if ($es_busqueda_activa): ?>
                    <i class="fas fa-search text-gray-400 text-4xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No se encontraron resultados</h3>
                    <p class="text-gray-500 mb-4">
                        No hay ventas que coincidan con "<strong><?php echo htmlspecialchars($busqueda); ?></strong>"
                    </p>
                    <button onclick="limpiarBusqueda()" 
                            class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg inline-flex items-center">
                        <i class="fas fa-times mr-2"></i>
                        Limpiar búsqueda
                    </button>
                <?php else: ?>
                    <i class="fas fa-shopping-cart text-gray-400 text-4xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No hay ventas registradas</h3>
                    <p class="text-gray-500 mb-4">
                        No se encontraron ventas en el período seleccionado.
                    </p>
                    <?php if ($auth->hasPermission('ventas', 'crear')): ?>
                    <a href="crear.php" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg inline-flex items-center">
                        <i class="fas fa-plus-circle mr-2"></i>
                        Realizar Primera Venta
                    </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de confirmación para anular -->
<div id="modalAnular" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900">Confirmar Anulación</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500">
                    ¿Estás seguro de que quieres anular la venta <span id="ventaNumero" class="font-bold"></span>?
                </p>
                <p class="text-sm text-red-500 mt-2">Esta acción no se puede deshacer.</p>
                <div class="mt-4">
                    <label for="motivo_anulacion" class="block text-sm font-medium text-gray-700 text-left">Motivo:</label>
                    <textarea id="motivo_anulacion" name="motivo_anulacion" rows="3" 
                              class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500" 
                              placeholder="Ingresa el motivo de la anulación..." required></textarea>
                </div>
            </div>
            <div class="flex justify-center space-x-3 mt-4">
                <button onclick="cerrarModalAnular()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded">
                    Cancelar
                </button>
                <button id="confirmarAnularBtn" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">
                    Anular Venta
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Variable global para almacenar el ID de la venta a anular
let ventaIdAnularGlobal = null;

// Función para confirmar anulación
function confirmarAnulacionVenta(id, numero) {
    ventaIdAnularGlobal = id;
    document.getElementById('ventaNumero').textContent = numero;
    document.getElementById('modalAnular').classList.remove('hidden');
    
    // Enfocar el textarea del motivo
    setTimeout(() => {
        document.getElementById('motivo_anulacion').focus();
    }, 100);
}

// Función para cerrar el modal
function cerrarModalAnular() {
    document.getElementById('modalAnular').classList.add('hidden');
    ventaIdAnularGlobal = null;
    document.getElementById('motivo_anulacion').value = '';
}

// Configurar el evento de confirmación
document.getElementById('confirmarAnularBtn').addEventListener('click', function() {
    const motivo = document.getElementById('motivo_anulacion').value;
    if (!motivo.trim()) {
        alert('Por favor ingresa el motivo de la anulación.');
        document.getElementById('motivo_anulacion').focus();
        return;
    }
    
    // Crear formulario para enviar la anulación
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'anular.php';
    
    const inputId = document.createElement('input');
    inputId.type = 'hidden';
    inputId.name = 'venta_id';
    inputId.value = ventaIdAnularGlobal;
    
    const inputMotivo = document.createElement('input');
    inputMotivo.type = 'hidden';
    inputMotivo.name = 'motivo_anulacion';
    inputMotivo.value = motivo;
    
    // Añadir token CSRF si existe
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    if (csrfToken) {
        const inputCsrf = document.createElement('input');
        inputCsrf.type = 'hidden';
        inputCsrf.name = 'csrf_token';
        inputCsrf.value = csrfToken;
        form.appendChild(inputCsrf);
    }
    
    form.appendChild(inputId);
    form.appendChild(inputMotivo);
    document.body.appendChild(form);
    
    // Mostrar mensaje de procesamiento
    mostrarCargandoAnulacion('Anulando venta...');
    
    // Enviar formulario
    form.submit();
});

// Función para mostrar carga durante anulación
function mostrarCargandoAnulacion(mensaje) {
    const loadingDiv = document.createElement('div');
    loadingDiv.id = 'loadingAnulacion';
    loadingDiv.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50';
    loadingDiv.innerHTML = `
        <div class="bg-white p-6 rounded-lg shadow-lg text-center">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mx-auto mb-4"></div>
            <p class="text-gray-700">${mensaje}</p>
        </div>
    `;
    document.body.appendChild(loadingDiv);
}

// Variables para controlar el filtro de devoluciones
let filtroDevolucionesActivo = false;

// Función para filtrar solo ventas con devoluciones
function filtrarDevoluciones() {
    filtroDevolucionesActivo = !filtroDevolucionesActivo;
    const filas = document.querySelectorAll('#tablaVentas tr');
    const boton = document.getElementById('btnDevoluciones');
    
    let contador = 0;
    filas.forEach(fila => {
        const tieneDevolucion = fila.getAttribute('data-devolucion') === 'si';
        
        if (filtroDevolucionesActivo) {
            // Mostrar solo ventas con devoluciones
            if (tieneDevolucion) {
                fila.style.display = '';
                contador++;
            } else {
                fila.style.display = 'none';
            }
        } else {
            // Mostrar todas las ventas
            fila.style.display = '';
            contador = filas.length;
        }
    });
    
    // Actualizar texto del botón
    if (boton) {
        if (filtroDevolucionesActivo) {
            boton.innerHTML = '<i class="fas fa-times mr-1"></i><span class="hidden sm:inline">Ver todas</span><span class="inline sm:hidden">Todas</span>';
            boton.classList.remove('bg-orange-100', 'text-orange-800');
            boton.classList.add('bg-blue-100', 'text-blue-800');
            
            // Actualizar contador
            const contadorSpan = boton.querySelector('.bg-orange-500');
            if (contadorSpan) {
                contadorSpan.className = 'ml-1 bg-blue-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center';
                contadorSpan.textContent = contador;
            }
        } else {
            boton.innerHTML = '<i class="fas fa-undo mr-1"></i><span class="hidden sm:inline">Ver devoluciones</span><span class="inline sm:hidden">Devol.</span>';
            boton.classList.remove('bg-blue-100', 'text-blue-800');
            boton.classList.add('bg-orange-100', 'text-orange-800');
            
            // Restaurar contador original
            const contadorSpan = boton.querySelector('.bg-blue-500');
            if (contadorSpan) {
                contadorSpan.className = 'ml-1 bg-orange-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center';
                contadorSpan.textContent = <?php echo $ventas_con_devoluciones; ?>;
            }
        }
    }
}

// Función para mostrar solo devoluciones
function mostrarSoloDevoluciones() {
    if (!filtroDevolucionesActivo) {
        filtrarDevoluciones();
    }
}

// Limpiar búsqueda y mantener filtros de fecha
function limpiarBusqueda() {
    const url = new URL(window.location.href);
    url.searchParams.delete('busqueda');
    window.location.href = url.toString();
}

// Inicializar filtros de fecha
document.addEventListener('DOMContentLoaded', function() {
    // Establecer fecha fin como hoy si no está definida
    const fechaFinInput = document.getElementById('fecha_fin');
    if (fechaFinInput && !fechaFinInput.value) {
        fechaFinInput.value = new Date().toISOString().split('T')[0];
    }
    
    // Establecer fecha inicio como hoy por defecto
    const fechaInicioInput = document.getElementById('fecha_inicio');
    if (fechaInicioInput && !fechaInicioInput.value) {
        fechaInicioInput.value = new Date().toISOString().split('T')[0];
    }
    
    // Enfocar campo de búsqueda si hay búsqueda activa
    const busquedaInput = document.getElementById('inputBusqueda');
    if (busquedaInput && busquedaInput.value) {
        busquedaInput.focus();
        busquedaInput.select();
    }
    
    // Auto-submit al escribir en búsqueda (con debounce)
    let debounceTimer;
    if (busquedaInput) {
        busquedaInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            if (this.value.length >= 2 || this.value.length === 0) {
                debounceTimer = setTimeout(() => {
                    document.getElementById('formBusqueda').submit();
                }, 500);
            }
        });
    }
    
    // Validar que la fecha inicio no sea mayor a fecha fin
    const formFiltros = document.querySelector('form[method="GET"]:not(#formBusqueda)');
    if (formFiltros) {
        formFiltros.addEventListener('submit', function(event) {
            const inicio = document.getElementById('fecha_inicio').value;
            const fin = document.getElementById('fecha_fin').value;
            
            if (inicio && fin && new Date(inicio) > new Date(fin)) {
                event.preventDefault();
                alert('La fecha de inicio no puede ser mayor a la fecha de fin.');
                document.getElementById('fecha_inicio').focus();
            }
        });
    }
    
    // Cerrar modal con Escape
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && !document.getElementById('modalAnular').classList.contains('hidden')) {
            cerrarModalAnular();
        }
    });
    
    // Cerrar modal al hacer clic fuera
    document.getElementById('modalAnular').addEventListener('click', function(event) {
        if (event.target === this) {
            cerrarModalAnular();
        }
    });
});

// Prevenir envío doble del formulario
let isSubmitting = false;
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(event) {
        if (isSubmitting) {
            event.preventDefault();
            return false;
        }
        isSubmitting = true;
        
        // Restablecer después de 3 segundos por si hay error
        setTimeout(() => {
            isSubmitting = false;
        }, 3000);
        
        return true;
    });
});
</script>

<style>
/* Estilos adicionales para mejor responsividad */
@media (max-width: 640px) {
    .devolucion-row {
        border-left: 3px solid #f97316;
    }
    
    table {
        font-size: 11px;
    }
    
    .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    
    /* Ajustar el modal para móviles */
    #modalAnular > div {
        margin: 1rem;
        width: calc(100% - 2rem);
    }
}

@media (max-width: 768px) {
    .hide-on-mobile {
        display: none !important;
    }
}

.truncate {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* Scroll suave para la tabla */
.overflow-x-auto {
    scrollbar-width: thin;
    scrollbar-color: #cbd5e0 #f7fafc;
}

.overflow-x-auto::-webkit-scrollbar {
    height: 8px;
}

.overflow-x-auto::-webkit-scrollbar-track {
    background: #f7fafc;
}

.overflow-x-auto::-webkit-scrollbar-thumb {
    background-color: #cbd5e0;
    border-radius: 4px;
}

/* Estilos para el modal */
#modalAnular {
    backdrop-filter: blur(2px);
}

#modalAnular > div {
    animation: modalSlideIn 0.3s ease-out;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Botón de anulación con efecto hover */
button[onclick*="confirmarAnulacionVenta"]:hover {
    transform: scale(1.1);
    transition: transform 0.2s;
}

/* Indicador de búsqueda activa */
.search-active {
    background-color: #fef3c7;
}
</style>

<?php include '../../includes/footer.php'; ?>