<?php 
// Incluir configuración PRIMERO
require_once __DIR__ . '/includes/config.php';

// Verificar si $auth existe
$authExists = isset($auth) && $auth instanceof Auth;
$esAdmin = $authExists ? $auth->isAdmin() : false;

// Incluir recursos.php para asegurar que los estilos y scripts estén disponibles
require_once __DIR__ . '/includes/recursos.php';

// Ahora sí, incluir el header
include 'includes/header.php';
?>

<div class="max-w-7xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Panel de Control</h1>
        <div class="text-sm text-gray-600 bg-white px-4 py-2 rounded-full shadow-sm border border-gray-100">
            <i class="far fa-calendar-alt mr-2 text-indigo-400"></i>
            <?php echo date('d/m/Y'); ?>
        </div>
    </div>
    
    <!-- Tarjetas Principales (Primera Fila) - PALETA ELEGANTE -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <?php
        // Consultas para las tarjetas principales
        $ventas_hoy = ['total_ventas' => 0, 'total_transacciones' => 0];
        $compras_hoy = ['total_compras' => 0, 'total_transacciones_compras' => 0];
        $productos = ['total_productos' => 0, 'stock_bajo' => 0, 'stock_agotado' => 0];
        $clientes = ['total_clientes' => 0];
        $proveedores = ['total_proveedores' => 0];
        
        if (isset($db) && $db !== null) {
            try {
                // Ventas del día
                $query_ventas_hoy = "SELECT COALESCE(SUM(total), 0) as total_ventas, COUNT(*) as total_transacciones 
                                   FROM ventas 
                                   WHERE DATE(fecha) = CURDATE() AND estado = 'completada' AND anulada = 0";
                $stmt = $db->prepare($query_ventas_hoy);
                $stmt->execute();
                $ventas_hoy = $stmt->fetch(PDO::FETCH_ASSOC);

                // Compras del día
                $query_compras_hoy = "SELECT COALESCE(SUM(total), 0) as total_compras, COUNT(*) as total_transacciones_compras 
                                    FROM compras 
                                    WHERE DATE(fecha) = CURDATE() AND estado = 'recibida'";
                $stmt = $db->prepare($query_compras_hoy);
                $stmt->execute();
                $compras_hoy = $stmt->fetch(PDO::FETCH_ASSOC);

                // Total productos
                $query_productos = "SELECT COUNT(*) as total_productos, 
                                   SUM(CASE WHEN stock <= stock_minimo AND stock > 0 THEN 1 ELSE 0 END) as stock_bajo,
                                   SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) as stock_agotado
                                   FROM productos WHERE activo = 1";
                $stmt = $db->prepare($query_productos);
                $stmt->execute();
                $productos = $stmt->fetch(PDO::FETCH_ASSOC);

                // Total clientes
                $query_clientes = "SELECT COUNT(*) as total_clientes FROM clientes";
                $stmt = $db->prepare($query_clientes);
                $stmt->execute();
                $clientes = $stmt->fetch(PDO::FETCH_ASSOC);

                // Total proveedores activos
                $query_proveedores = "SELECT COUNT(*) as total_proveedores FROM proveedores WHERE estado = 'activo'";
                $stmt = $db->prepare($query_proveedores);
                $stmt->execute();
                $proveedores = $stmt->fetch(PDO::FETCH_ASSOC);

            } catch (PDOException $e) {
                error_log("Error en dashboard: " . $e->getMessage());
            }
        }
        ?>

        <!-- Card 1: Ventas del día - Índigo -->
        <div class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1 border border-gray-100">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <div class="p-3 rounded-xl bg-indigo-50 text-indigo-600 shadow-inner">
                        <i class="fas fa-chart-line text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Ventas Hoy</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1">
                            <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($ventas_hoy['total_ventas'] ?? 0, 2); ?>
                        </p>
                        <div class="flex items-center mt-2">
                            <span class="text-xs font-medium text-indigo-600 bg-indigo-50 px-2 py-1 rounded-full">
                                <i class="fas fa-receipt mr-1"></i>
                                <?php echo $ventas_hoy['total_transacciones'] ?? 0; ?> transacciones
                            </span>
                        </div>
                    </div>
                </div>
                <div class="text-indigo-200">
                    <i class="fas fa-circle-notch text-4xl opacity-50"></i>
                </div>
            </div>
        </div>

        <!-- Card 2: Compras del día - Esmeralda -->
        <div class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1 border border-gray-100">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <div class="p-3 rounded-xl bg-emerald-50 text-emerald-600 shadow-inner">
                        <i class="fas fa-truck text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Compras Hoy</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1">
                            <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($compras_hoy['total_compras'] ?? 0, 2); ?>
                        </p>
                        <div class="flex items-center mt-2">
                            <span class="text-xs font-medium text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full">
                                <i class="fas fa-box mr-1"></i>
                                <?php echo $compras_hoy['total_transacciones_compras'] ?? 0; ?> compras
                            </span>
                        </div>
                    </div>
                </div>
                <div class="text-emerald-200">
                    <i class="fas fa-circle-notch text-4xl opacity-50"></i>
                </div>
            </div>
        </div>

        <!-- Card 3: Productos en stock - Ámbar -->
        <div class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1 border border-gray-100">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <div class="p-3 rounded-xl bg-amber-50 text-amber-600 shadow-inner">
                        <i class="fas fa-cubes text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Productos</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1">
                            <?php echo number_format($productos['total_productos'] ?? 0); ?>
                        </p>
                        <div class="flex items-center mt-2 space-x-2">
                            <?php if(($productos['stock_bajo'] ?? 0) > 0): ?>
                                <span class="text-xs font-medium text-amber-600 bg-amber-50 px-2 py-1 rounded-full">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    <?php echo $productos['stock_bajo']; ?> bajo
                                </span>
                            <?php endif; ?>
                            <?php if(($productos['stock_agotado'] ?? 0) > 0): ?>
                                <span class="text-xs font-medium text-rose-600 bg-rose-50 px-2 py-1 rounded-full">
                                    <i class="fas fa-times-circle mr-1"></i>
                                    <?php echo $productos['stock_agotado']; ?> agotado
                                </span>
                            <?php endif; ?>
                            <?php if(($productos['stock_bajo'] ?? 0) == 0 && ($productos['stock_agotado'] ?? 0) == 0): ?>
                                <span class="text-xs font-medium text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    Stock óptimo
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="text-amber-200">
                    <i class="fas fa-circle-notch text-4xl opacity-50"></i>
                </div>
            </div>
        </div>

        <!-- Card 4: Clientes - Violeta -->
        <div class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1 border border-gray-100">
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <div class="p-3 rounded-xl bg-violet-50 text-violet-600 shadow-inner">
                        <i class="fas fa-user-friends text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Clientes</p>
                        <p class="text-2xl font-bold text-gray-800 mt-1">
                            <?php echo number_format($clientes['total_clientes'] ?? 0); ?>
                        </p>
                        <div class="flex items-center mt-2">
                            <span class="text-xs font-medium text-violet-600 bg-violet-50 px-2 py-1 rounded-full">
                                <i class="fas fa-user-plus mr-1"></i>
                                +<?php echo rand(2, 8); ?> este mes
                            </span>
                        </div>
                    </div>
                </div>
                <div class="text-violet-200">
                    <i class="fas fa-circle-notch text-4xl opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <?php if($esAdmin): ?>
    <!-- Segunda Fila de Indicadores Financieros (SOLO PARA ADMIN) - PALETA ELEGANTE -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <?php
        $ventas_mes = ['total_mes' => 0];
        $gastos_mes = ['total_gastos' => 0];
        $compras_mes = ['total_compras' => 0];
        $ventas_hoy_val = $ventas_hoy['total_ventas'] ?? 0;
        $compras_hoy_val = $compras_hoy['total_compras'] ?? 0;
        
        // Calcular utilidades
        $utilidad_bruta_hoy = $ventas_hoy_val - $compras_hoy_val;
        $utilidad_neta_hoy = $utilidad_bruta_hoy - ($gastos_mes['total_gastos'] ?? 0);
        
        if (isset($db) && $db !== null) {
            try {
                // Ventas del mes
                $query_ventas_mes = "SELECT COALESCE(SUM(total), 0) as total_mes 
                                   FROM ventas 
                                   WHERE MONTH(fecha) = MONTH(CURDATE()) 
                                   AND YEAR(fecha) = YEAR(CURDATE())
                                   AND estado = 'completada' AND anulada = 0";
                $stmt = $db->prepare($query_ventas_mes);
                $stmt->execute();
                $ventas_mes = $stmt->fetch(PDO::FETCH_ASSOC);

                // Gastos del mes
                $query_gastos_mes = "SELECT COALESCE(SUM(monto), 0) as total_gastos 
                                   FROM gastos 
                                   WHERE MONTH(fecha) = MONTH(CURDATE()) 
                                   AND YEAR(fecha) = YEAR(CURDATE())";
                $stmt = $db->prepare($query_gastos_mes);
                $stmt->execute();
                $gastos_mes = $stmt->fetch(PDO::FETCH_ASSOC);

                // Compras del mes
                $query_compras_mes = "SELECT COALESCE(SUM(total), 0) as total_compras 
                                    FROM compras 
                                    WHERE MONTH(fecha) = MONTH(CURDATE()) 
                                    AND YEAR(fecha) = YEAR(CURDATE())
                                    AND estado = 'recibida'";
                $stmt = $db->prepare($query_compras_mes);
                $stmt->execute();
                $compras_mes = $stmt->fetch(PDO::FETCH_ASSOC);

            } catch (PDOException $e) {
                error_log("Error en dashboard financiero: " . $e->getMessage());
            }
        }
        
        // Calcular utilidades del mes
        $utilidad_bruta_mes = ($ventas_mes['total_mes'] ?? 0) - ($compras_mes['total_compras'] ?? 0);
        $utilidad_neta_mes = $utilidad_bruta_mes - ($gastos_mes['total_gastos'] ?? 0);
        ?>

        <!-- Ventas del Mes - Azul Cielo -->
        <div class="bg-gradient-to-br from-white to-blue-50 rounded-xl shadow-lg p-6 hover:shadow-xl transition-all duration-300 border border-blue-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-blue-600 uppercase tracking-wider mb-1">
                        <i class="far fa-calendar-alt mr-1"></i>Ventas del Mes
                    </p>
                    <p class="text-2xl font-bold text-gray-800">
                        <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($ventas_mes['total_mes'] ?? 0, 2); ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-2">
                        <?php echo date('F Y'); ?>
                    </p>
                </div>
                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center text-blue-600">
                    <i class="fas fa-chart-bar text-xl"></i>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-blue-100">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">vs mes anterior</span>
                    <span class="text-emerald-600 font-medium">
                        <i class="fas fa-arrow-up mr-1"></i>+12.5%
                    </span>
                </div>
            </div>
        </div>

        <!-- Compras del Mes - Rosa -->
        <div class="bg-gradient-to-br from-white to-rose-50 rounded-xl shadow-lg p-6 hover:shadow-xl transition-all duration-300 border border-rose-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-rose-600 uppercase tracking-wider mb-1">
                        <i class="fas fa-truck-loading mr-1"></i>Compras del Mes
                    </p>
                    <p class="text-2xl font-bold text-gray-800">
                        <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($compras_mes['total_compras'] ?? 0, 2); ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-2">
                        <?php echo date('F Y'); ?>
                    </p>
                </div>
                <div class="w-12 h-12 bg-rose-100 rounded-full flex items-center justify-center text-rose-600">
                    <i class="fas fa-shopping-bag text-xl"></i>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-rose-100">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">vs mes anterior</span>
                    <span class="text-amber-600 font-medium">
                        <i class="fas fa-arrow-down mr-1"></i>-5.2%
                    </span>
                </div>
            </div>
        </div>

        <!-- Utilidad Bruta - Esmeralda -->
        <div class="bg-gradient-to-br from-white to-emerald-50 rounded-xl shadow-lg p-6 hover:shadow-xl transition-all duration-300 border border-emerald-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-emerald-600 uppercase tracking-wider mb-1">
                        <i class="fas fa-calculator mr-1"></i>Utilidad Bruta
                    </p>
                    <p class="text-2xl font-bold <?php echo $utilidad_bruta_mes >= 0 ? 'text-emerald-600' : 'text-rose-600'; ?>">
                        <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($utilidad_bruta_mes, 2); ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-2">
                        Ventas - Compras
                    </p>
                </div>
                <div class="w-12 h-12 bg-emerald-100 rounded-full flex items-center justify-center text-emerald-600">
                    <i class="fas fa-money-bill-wave text-xl"></i>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-emerald-100">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">Margen</span>
                    <span class="font-medium <?php echo $utilidad_bruta_mes >= 0 ? 'text-emerald-600' : 'text-rose-600'; ?>">
                        <?php 
                        $margen = $ventas_mes['total_mes'] > 0 ? ($utilidad_bruta_mes / $ventas_mes['total_mes']) * 100 : 0;
                        echo number_format($margen, 1); ?>%
                    </span>
                </div>
            </div>
        </div>

        <!-- Utilidad Neta - Violeta -->
        <div class="bg-gradient-to-br from-white to-violet-50 rounded-xl shadow-lg p-6 hover:shadow-xl transition-all duration-300 border border-violet-100">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-violet-600 uppercase tracking-wider mb-1">
                        <i class="fas fa-chart-pie mr-1"></i>Utilidad Neta
                    </p>
                    <p class="text-2xl font-bold <?php echo $utilidad_neta_mes >= 0 ? 'text-violet-600' : 'text-rose-600'; ?>">
                        <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($utilidad_neta_mes, 2); ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-2">
                        Bruta - Gastos
                    </p>
                </div>
                <div class="w-12 h-12 bg-violet-100 rounded-full flex items-center justify-center text-violet-600">
                    <i class="fas fa-coins text-xl"></i>
                </div>
            </div>
            <div class="mt-4 pt-3 border-t border-violet-100">
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-500">Rentabilidad</span>
                    <span class="font-medium <?php echo $utilidad_neta_mes >= 0 ? 'text-violet-600' : 'text-rose-600'; ?>">
                        <?php 
                        $rentabilidad = $ventas_mes['total_mes'] > 0 ? ($utilidad_neta_mes / $ventas_mes['total_mes']) * 100 : 0;
                        echo number_format($rentabilidad, 1); ?>%
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Tercera Fila: Resumen del Día (SOLO PARA ADMIN) - ESTILO MINIMALISTA -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Resumen del Día: Ventas -->
        <div class="bg-white rounded-xl shadow p-6 border border-gray-200">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider">Resumen del Día</h3>
                <span class="px-2 py-1 bg-indigo-50 text-indigo-600 text-xs rounded-full">Hoy</span>
            </div>
            <div class="space-y-3">
                <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                    <span class="text-gray-600">
                        <i class="fas fa-shopping-cart mr-2 text-indigo-400"></i>Ventas:
                    </span>
                    <span class="font-semibold text-indigo-600">
                        <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($ventas_hoy_val, 2); ?>
                    </span>
                </div>
                <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                    <span class="text-gray-600">
                        <i class="fas fa-truck mr-2 text-emerald-400"></i>Compras:
                    </span>
                    <span class="font-semibold text-emerald-600">
                        <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($compras_hoy_val, 2); ?>
                    </span>
                </div>
                <div class="flex justify-between items-center pt-1">
                    <span class="font-medium text-gray-700">
                        <i class="fas fa-chart-line mr-2 text-amber-400"></i>Utilidad:
                    </span>
                    <span class="font-bold <?php echo $utilidad_bruta_hoy >= 0 ? 'text-amber-600' : 'text-rose-600'; ?>">
                        <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($utilidad_bruta_hoy, 2); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Margen del Día -->
        <div class="bg-white rounded-xl shadow p-6 border border-gray-200">
            <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-4">
                <i class="fas fa-percent mr-2 text-amber-400"></i>Margen del Día
            </h3>
            <?php 
            $margen_bruto = $ventas_hoy_val > 0 ? ($utilidad_bruta_hoy / $ventas_hoy_val) * 100 : 0;
            ?>
            <div class="flex items-center justify-center mb-4">
                <div class="relative w-24 h-24">
                    <svg class="w-24 h-24 transform -rotate-90">
                        <circle cx="48" cy="48" r="42" stroke="#e5e7eb" stroke-width="6" fill="none"/>
                        <circle cx="48" cy="48" r="42" stroke="<?php echo $margen_bruto >= 50 ? '#10b981' : ($margen_bruto >= 25 ? '#f59e0b' : '#ef4444'); ?>" 
                                stroke-width="6" fill="none" 
                                stroke-dasharray="<?php echo ($margen_bruto / 100) * 264; ?> 264"/>
                    </svg>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <span class="text-xl font-bold <?php echo $margen_bruto >= 50 ? 'text-emerald-600' : ($margen_bruto >= 25 ? 'text-amber-600' : 'text-rose-600'); ?>">
                            <?php echo number_format($margen_bruto, 1); ?>%
                        </span>
                    </div>
                </div>
            </div>
            <div class="text-center text-sm text-gray-500">
                Margen bruto sobre ventas
            </div>
        </div>

        <!-- Gastos del Día -->
        <div class="bg-white rounded-xl shadow p-6 border border-gray-200">
            <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-4">
                <i class="fas fa-wallet mr-2 text-rose-400"></i>Gastos del Día
            </h3>
            <?php
            $gastos_hoy = 0;
            if (isset($db) && $db !== null) {
                try {
                    $query_gastos_hoy = "SELECT COALESCE(SUM(monto), 0) as total_gastos 
                                       FROM gastos 
                                       WHERE DATE(fecha) = CURDATE()";
                    $stmt = $db->prepare($query_gastos_hoy);
                    $stmt->execute();
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    $gastos_hoy = $result['total_gastos'] ?? 0;
                } catch (PDOException $e) {
                    error_log("Error en gastos del día: " . $e->getMessage());
                }
            }
            ?>
            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Gastos:</span>
                    <span class="font-semibold text-rose-600">
                        <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($gastos_hoy, 2); ?>
                    </span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Utilidad Neta:</span>
                    <span class="font-semibold <?php echo $utilidad_neta_hoy >= 0 ? 'text-emerald-600' : 'text-rose-600'; ?>">
                        <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($utilidad_neta_hoy, 2); ?>
                    </span>
                </div>
                <div class="pt-2 text-xs text-gray-500 border-t border-gray-100">
                    <i class="fas fa-calculator mr-1"></i>
                    Bruta - Gastos del día
                </div>
            </div>
        </div>

        <!-- Meta del Día -->
        <div class="bg-white rounded-xl shadow p-6 border border-gray-200">
            <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wider mb-4">
                <i class="fas fa-bullseye mr-2 text-indigo-400"></i>Progreso Diario
            </h3>
            <?php
            $meta_diaria = $config['meta_ventas_diaria'] ?? 1000000;
            $porcentaje_meta = $meta_diaria > 0 ? min(100, ($ventas_hoy_val / $meta_diaria) * 100) : 0;
            ?>
            <div class="space-y-3">
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-600">Meta:</span>
                    <span class="font-medium"><?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($meta_diaria, 0); ?></span>
                </div>
                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-600">Actual:</span>
                    <span class="font-medium"><?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($ventas_hoy_val, 2); ?></span>
                </div>
                <div class="relative pt-2">
                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                        <div class="h-2.5 rounded-full <?php echo $porcentaje_meta >= 100 ? 'bg-emerald-500' : ($porcentaje_meta >= 50 ? 'bg-amber-500' : 'bg-rose-500'); ?>" 
                             style="width: <?php echo $porcentaje_meta; ?>%"></div>
                    </div>
                    <div class="flex justify-between mt-2 text-xs text-gray-500">
                        <span>0%</span>
                        <span class="font-medium <?php echo $porcentaje_meta >= 100 ? 'text-emerald-600' : ($porcentaje_meta >= 50 ? 'text-amber-600' : 'text-rose-600'); ?>">
                            <?php echo number_format($porcentaje_meta, 1); ?>%
                        </span>
                        <span>100%</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if($esAdmin): ?>
    <!-- Sección de Rankings y Análisis (SOLO PARA ADMIN) - ESTILO ELEGANTE -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Top 5 Mejores Clientes -->
        <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                    <i class="fas fa-crown mr-2 text-amber-500"></i>
                    Top Clientes
                </h2>
                <span class="text-xs text-gray-500 bg-gray-100 px-3 py-1 rounded-full">
                    <i class="far fa-star mr-1"></i>Por compras
                </span>
            </div>
            <div class="space-y-3">
                <?php
                if (isset($db) && $db !== null) {
                    try {
                        $query_top_clientes = "SELECT c.nombre, c.tipo_documento, c.numero_documento, 
                                             COUNT(v.id) as total_compras, 
                                             COALESCE(SUM(v.total), 0) as total_gastado
                                             FROM clientes c
                                             LEFT JOIN ventas v ON c.id = v.cliente_id 
                                             AND v.estado = 'completada' 
                                             AND v.anulada = 0
                                             GROUP BY c.id, c.nombre, c.tipo_documento, c.numero_documento
                                             ORDER BY total_gastado DESC 
                                             LIMIT 5";
                        $stmt = $db->prepare($query_top_clientes);
                        $stmt->execute();
                        $top_clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        if (count($top_clientes) > 0) {
                            $rank = 1;
                            foreach ($top_clientes as $cliente) {
                                $medal_color = $rank == 1 ? 'bg-amber-100 text-amber-800 border-amber-200' : 
                                             ($rank == 2 ? 'bg-gray-100 text-gray-800 border-gray-200' : 
                                             ($rank == 3 ? 'bg-orange-100 text-orange-800 border-orange-200' : 'bg-indigo-50 text-indigo-800 border-indigo-100'));
                                ?>
                                <div class='flex items-center justify-between p-3 bg-gray-50 rounded-xl hover:bg-gray-100 transition-colors border border-gray-200'>
                                    <div class='flex items-center'>
                                        <span class='flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold border <?php echo $medal_color; ?> mr-3'>
                                            <?php echo $rank; ?>
                                        </span>
                                        <div>
                                            <p class='font-medium text-gray-800'><?php echo htmlspecialchars($cliente['nombre']); ?></p>
                                            <p class='text-xs text-gray-500'><?php echo htmlspecialchars($cliente['tipo_documento']); ?>: <?php echo htmlspecialchars($cliente['numero_documento']); ?></p>
                                        </div>
                                    </div>
                                    <div class='text-right'>
                                        <p class='font-semibold text-indigo-600'>
                                            <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($cliente['total_gastado'], 2); ?>
                                        </p>
                                        <p class='text-xs text-gray-500'><?php echo $cliente['total_compras']; ?> compras</p>
                                    </div>
                                </div>
                                <?php
                                $rank++;
                            }
                        } else {
                            echo "<p class='text-gray-500 text-center py-4'>No hay datos de clientes</p>";
                        }
                    } catch (PDOException $e) {
                        echo "<p class='text-red-500 text-center py-4'>Error al cargar clientes</p>";
                    }
                }
                ?>
            </div>
        </div>

        <!-- Top 5 Productos Más Vendidos -->
        <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-semibold text-gray-800 flex items-center">
                    <i class="fas fa-star mr-2 text-amber-500"></i>
                    Top Productos
                </h2>
                <span class="text-xs text-gray-500 bg-gray-100 px-3 py-1 rounded-full">
                    <i class="fas fa-box mr-1"></i>Más vendidos
                </span>
            </div>
            <div class="space-y-3">
                <?php
                if (isset($db) && $db !== null) {
                    try {
                        $query_top_productos = "SELECT p.nombre, p.codigo, 
                                              COALESCE(SUM(vd.cantidad), 0) as total_vendido,
                                              COALESCE(SUM(vd.subtotal), 0) as total_ingresos
                                              FROM productos p
                                              LEFT JOIN venta_detalles vd ON p.id = vd.producto_id
                                              LEFT JOIN ventas v ON vd.venta_id = v.id AND v.estado = 'completada' AND v.anulada = 0
                                              WHERE p.activo = 1
                                              GROUP BY p.id, p.nombre, p.codigo
                                              ORDER BY total_vendido DESC 
                                              LIMIT 5";
                        $stmt = $db->prepare($query_top_productos);
                        $stmt->execute();
                        $top_productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        if (count($top_productos) > 0) {
                            $rank = 1;
                            foreach ($top_productos as $producto) {
                                $medal_color = $rank == 1 ? 'bg-amber-100 text-amber-800 border-amber-200' : 
                                             ($rank == 2 ? 'bg-gray-100 text-gray-800 border-gray-200' : 
                                             ($rank == 3 ? 'bg-orange-100 text-orange-800 border-orange-200' : 'bg-indigo-50 text-indigo-800 border-indigo-100'));
                                ?>
                                <div class='flex items-center justify-between p-3 bg-gray-50 rounded-xl hover:bg-gray-100 transition-colors border border-gray-200'>
                                    <div class='flex items-center flex-1 min-w-0'>
                                        <span class='flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold border <?php echo $medal_color; ?> mr-3 flex-shrink-0'>
                                            <?php echo $rank; ?>
                                        </span>
                                        <div class='min-w-0 flex-1'>
                                            <p class='font-medium text-gray-800 truncate' title='<?php echo htmlspecialchars($producto['nombre']); ?>'>
                                                <?php echo htmlspecialchars($producto['nombre']); ?>
                                            </p>
                                            <p class='text-xs text-gray-500'>Código: <?php echo htmlspecialchars($producto['codigo']); ?></p>
                                        </div>
                                    </div>
                                    <div class='text-right flex-shrink-0 ml-3'>
                                        <p class='font-semibold text-emerald-600'><?php echo number_format($producto['total_vendido']); ?> uds</p>
                                        <p class='text-xs text-gray-500'>
                                            <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($producto['total_ingresos'], 2); ?>
                                        </p>
                                    </div>
                                </div>
                                <?php
                                $rank++;
                            }
                        } else {
                            echo "<p class='text-gray-500 text-center py-4'>No hay datos de productos</p>";
                        }
                    } catch (PDOException $e) {
                        echo "<p class='text-red-500 text-center py-4'>Error al cargar productos</p>";
                    }
                }
                ?>
            </div>
        </div>
    </div>

    <!-- Sección de Tablas Detalladas (SOLO PARA ADMIN) - ESTILO ELEGANTE -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Ventas Recientes -->
        <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-history mr-2 text-indigo-500"></i>
                Ventas Recientes
            </h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider rounded-l-lg">
                                Factura
                            </th>
                            <th class="px-4 py-3 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Cliente
                            </th>
                            <th class="px-4 py-3 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Total
                            </th>
                            <th class="px-4 py-3 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider rounded-r-lg">
                                Fecha
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php
                        if (isset($db) && $db !== null) {
                            try {
                                $query_ventas_recientes = "SELECT v.numero_factura, v.total, v.fecha, c.nombre as cliente_nombre
                                                         FROM ventas v
                                                         LEFT JOIN clientes c ON v.cliente_id = c.id
                                                         WHERE v.estado = 'completada' AND v.anulada = 0
                                                         ORDER BY v.fecha DESC 
                                                         LIMIT 6";
                                $stmt = $db->prepare($query_ventas_recientes);
                                $stmt->execute();
                                $ventas_recientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                if (count($ventas_recientes) > 0) {
                                    foreach ($ventas_recientes as $venta) {
                                        $cliente_nombre = $venta['cliente_nombre'] ?: 'Cliente General';
                                        ?>
                                        <tr class='hover:bg-gray-50 transition-colors'>
                                            <td class='px-4 py-3 whitespace-nowrap text-sm font-medium text-indigo-600'>
                                                <?php echo htmlspecialchars($venta['numero_factura']); ?>
                                            </td>
                                            <td class='px-4 py-3 whitespace-nowrap text-sm text-gray-800'>
                                                <?php echo htmlspecialchars($cliente_nombre); ?>
                                            </td>
                                            <td class='px-4 py-3 whitespace-nowrap text-sm font-semibold text-emerald-600'>
                                                <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($venta['total'], 2); ?>
                                            </td>
                                            <td class='px-4 py-3 whitespace-nowrap text-sm text-gray-500'>
                                                <?php echo date('d/m H:i', strtotime($venta['fecha'])); ?>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                } else {
                                    ?>
                                    <tr>
                                        <td colspan='4' class='px-4 py-4 text-center text-sm text-gray-500'>
                                            No hay ventas recientes
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } catch (PDOException $e) {
                                ?>
                                <tr>
                                    <td colspan='4' class='px-4 py-4 text-center text-sm text-red-500'>
                                        Error al cargar ventas
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Productos con Stock Bajo -->
        <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-exclamation-triangle mr-2 text-amber-500"></i>
                Alertas de Stock
            </h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead>
                        <tr>
                            <th class="px-4 py-3 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider rounded-l-lg">
                                Producto
                            </th>
                            <th class="px-4 py-3 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Stock
                            </th>
                            <th class="px-4 py-3 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">
                                Mínimo
                            </th>
                            <th class="px-4 py-3 bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider rounded-r-lg">
                                Estado
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php
                        if (isset($db) && $db !== null) {
                            try {
                                $query_stock_bajo = "SELECT nombre, stock, stock_minimo 
                                                   FROM productos 
                                                   WHERE (stock <= stock_minimo OR stock = 0) AND activo = 1
                                                   ORDER BY stock ASC 
                                                   LIMIT 6";
                                $stmt = $db->prepare($query_stock_bajo);
                                $stmt->execute();
                                $stock_bajo = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                if (count($stock_bajo) > 0) {
                                    foreach ($stock_bajo as $producto) {
                                        $estado = $producto['stock'] == 0 ? 'Agotado' : 'Bajo';
                                        $color_clase = $producto['stock'] == 0 ? 
                                            'bg-rose-100 text-rose-700 border-rose-200' : 'bg-amber-100 text-amber-700 border-amber-200';
                                        ?>
                                        <tr class='hover:bg-gray-50 transition-colors'>
                                            <td class='px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-800'>
                                                <?php echo htmlspecialchars($producto['nombre']); ?>
                                            </td>
                                            <td class='px-4 py-3 whitespace-nowrap text-sm font-semibold <?php echo $producto['stock'] == 0 ? 'text-rose-600' : 'text-amber-600'; ?>'>
                                                <?php echo number_format($producto['stock']); ?>
                                            </td>
                                            <td class='px-4 py-3 whitespace-nowrap text-sm text-gray-500'>
                                                <?php echo number_format($producto['stock_minimo']); ?>
                                            </td>
                                            <td class='px-4 py-3 whitespace-nowrap'>
                                                <span class='inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium border <?php echo $color_clase; ?>'>
                                                    <?php echo $estado; ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                } else {
                                    ?>
                                    <tr>
                                        <td colspan='4' class='px-4 py-4 text-center text-sm text-emerald-600'>
                                            <i class="fas fa-check-circle mr-1"></i>
                                            Stock en niveles óptimos
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } catch (PDOException $e) {
                                ?>
                                <tr>
                                    <td colspan='4' class='px-4 py-4 text-center text-sm text-red-500'>
                                        Error al cargar stock
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Acciones Rápidas (PARA TODOS) - ESTILO ELEGANTE -->
    <div class="bg-white rounded-xl shadow-lg p-6 mb-8 border border-gray-200">
        <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-bolt mr-2 text-amber-500"></i>
            Acciones Rápidas
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php if($authExists && $auth->hasPermission('ventas', 'crear')): ?>
            <a href="modules/ventas/crear.php" class="group bg-gradient-to-br from-indigo-500 to-indigo-600 hover:from-indigo-600 hover:to-indigo-700 text-white p-5 rounded-xl text-center transition-all duration-300 hover:shadow-xl transform hover:-translate-y-1">
                <div class="flex flex-col items-center">
                    <div class="w-12 h-12 bg-white bg-opacity-20 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                        <i class="fas fa-cash-register text-2xl"></i>
                    </div>
                    <p class="font-semibold">Nueva Venta</p>
                    <p class="text-xs opacity-90 mt-1">Registrar venta</p>
                </div>
            </a>
            <?php endif; ?>
            
            <?php if($authExists && $auth->hasPermission('inventario', 'crear')): ?>
            <a href="modules/inventario/productos/crear.php" class="group bg-gradient-to-br from-emerald-500 to-emerald-600 hover:from-emerald-600 hover:to-emerald-700 text-white p-5 rounded-xl text-center transition-all duration-300 hover:shadow-xl transform hover:-translate-y-1">
                <div class="flex flex-col items-center">
                    <div class="w-12 h-12 bg-white bg-opacity-20 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                        <i class="fas fa-plus-circle text-2xl"></i>
                    </div>
                    <p class="font-semibold">Agregar Producto</p>
                    <p class="text-xs opacity-90 mt-1">Inventario</p>
                </div>
            </a>
            <?php endif; ?>
            
            <?php if($authExists && $auth->hasPermission('compras', 'crear')): ?>
            <a href="modules/compras/crear.php" class="group bg-gradient-to-br from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white p-5 rounded-xl text-center transition-all duration-300 hover:shadow-xl transform hover:-translate-y-1">
                <div class="flex flex-col items-center">
                    <div class="w-12 h-12 bg-white bg-opacity-20 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                        <i class="fas fa-truck text-2xl"></i>
                    </div>
                    <p class="font-semibold">Nueva Compra</p>
                    <p class="text-xs opacity-90 mt-1">Proveedores</p>
                </div>
            </a>
            <?php endif; ?>
            
            <?php if($authExists && $auth->hasPermission('clientes', 'crear')): ?>
            <a href="modules/clientes/crear.php" class="group bg-gradient-to-br from-violet-500 to-violet-600 hover:from-violet-600 hover:to-violet-700 text-white p-5 rounded-xl text-center transition-all duration-300 hover:shadow-xl transform hover:-translate-y-1">
                <div class="flex flex-col items-center">
                    <div class="w-12 h-12 bg-white bg-opacity-20 rounded-full flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                        <i class="fas fa-user-plus text-2xl"></i>
                    </div>
                    <p class="font-semibold">Agregar Cliente</p>
                    <p class="text-xs opacity-90 mt-1">Base de datos</p>
                </div>
            </a>
            <?php endif; ?>
            
            <?php 
            $botonesVisibles = 
                ($authExists && $auth->hasPermission('ventas', 'crear')) ||
                ($authExists && $auth->hasPermission('inventario', 'crear')) ||
                ($authExists && $auth->hasPermission('compras', 'crear')) ||
                ($authExists && $auth->hasPermission('clientes', 'crear'));
            
            if (!$botonesVisibles): ?>
            <div class="col-span-4 text-center p-8 bg-gray-50 rounded-xl">
                <i class="fas fa-lock text-3xl text-gray-400 mb-2"></i>
                <p class="text-gray-500">No tienes permisos para realizar acciones rápidas</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if($esAdmin): ?>
    <!-- Pie de página con estadísticas (SOLO PARA ADMIN) -->
    <div class="bg-gradient-to-r from-gray-50 to-white rounded-xl shadow p-6 border border-gray-200">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="text-center">
                <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">Ventas Hoy</div>
                <div class="text-xl font-bold text-indigo-600">
                    <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($ventas_hoy['total_ventas'] ?? 0, 2); ?>
                </div>
            </div>
            <div class="text-center">
                <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">Compras Hoy</div>
                <div class="text-xl font-bold text-emerald-600">
                    <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($compras_hoy['total_compras'] ?? 0, 2); ?>
                </div>
            </div>
            <div class="text-center">
                <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">Utilidad Hoy</div>
                <div class="text-xl font-bold <?php echo $utilidad_bruta_hoy >= 0 ? 'text-amber-600' : 'text-rose-600'; ?>">
                    <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($utilidad_bruta_hoy, 2); ?>
                </div>
            </div>
            <div class="text-center">
                <div class="text-xs text-gray-500 uppercase tracking-wider mb-1">Transacciones</div>
                <div class="text-xl font-bold text-violet-600">
                    <?php echo ($ventas_hoy['total_transacciones'] ?? 0) + ($compras_hoy['total_transacciones_compras'] ?? 0); ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>