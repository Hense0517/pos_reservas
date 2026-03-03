<?php 
include '../../includes/header.php';

// Verificar permisos
if (!$auth->hasPermission('gastos', 'lectura')) {
    header("Location: ../../index.php");
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Categorías predefinidas
$categorias = [
    'Nómina y Salarios',
    'Alquiler y Arriendo', 
    'Servicios Públicos',
    'Suministros de Oficina',
    'Publicidad y Marketing',
    'Mantenimiento y Reparaciones',
    'Transporte y Logística',
    'Impuestos y Tributos',
    'Seguros',
    'Gastos Legales',
    'Capacitación',
    'Equipos y Tecnología',
    'Insumos y Materiales',
    'Gastos Bancarios',
    'Varios y Otros'
];
?>

<div class="max-w-7xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Gestión de Gastos</h1>
            <p class="text-gray-600">Control y análisis de gastos operativos</p>
        </div>
        <?php if ($auth->hasPermission('gastos', 'escritura')): ?>
        <a href="crear.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
            <i class="fas fa-plus mr-2"></i>
            Nuevo Gasto
        </a>
        <?php endif; ?>
    </div>

    <!-- KPIs y Métricas -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <?php
        // Consultas para KPIs
        $query_gastos_mes = "SELECT COALESCE(SUM(monto), 0) as total_mes 
                           FROM gastos 
                           WHERE MONTH(fecha) = MONTH(CURDATE()) 
                           AND YEAR(fecha) = YEAR(CURDATE())";
        $stmt = $db->prepare($query_gastos_mes);
        $stmt->execute();
        $gastos_mes = $stmt->fetch(PDO::FETCH_ASSOC);

        $query_gastos_anio = "SELECT COALESCE(SUM(monto), 0) as total_anio 
                            FROM gastos 
                            WHERE YEAR(fecha) = YEAR(CURDATE())";
        $stmt = $db->prepare($query_gastos_anio);
        $stmt->execute();
        $gastos_anio = $stmt->fetch(PDO::FETCH_ASSOC);

        $query_promedio_mensual = "SELECT COALESCE(AVG(monto_mensual), 0) as promedio 
                                 FROM (SELECT YEAR(fecha) as anio, MONTH(fecha) as mes, SUM(monto) as monto_mensual 
                                       FROM gastos 
                                       GROUP BY YEAR(fecha), MONTH(fecha)) as mensual";
        $stmt = $db->prepare($query_promedio_mensual);
        $stmt->execute();
        $promedio_mensual = $stmt->fetch(PDO::FETCH_ASSOC);

        $query_total_gastos = "SELECT COALESCE(SUM(monto), 0) as total FROM gastos";
        $stmt = $db->prepare($query_total_gastos);
        $stmt->execute();
        $total_gastos = $stmt->fetch(PDO::FETCH_ASSOC);

        $query_gastos_hoy = "SELECT COALESCE(SUM(monto), 0) as total_hoy 
                           FROM gastos 
                           WHERE DATE(fecha) = CURDATE()";
        $stmt = $db->prepare($query_gastos_hoy);
        $stmt->execute();
        $gastos_hoy = $stmt->fetch(PDO::FETCH_ASSOC);
        ?>

        <!-- Gastos del Mes -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Gastos del Mes</h3>
                    <p class="text-2xl font-bold text-gray-900">
                        <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($gastos_mes['total_mes'], 2); ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo date('F Y'); ?></p>
                </div>
                <div class="text-blue-500">
                    <i class="fas fa-calendar-alt text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Gastos del Año -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Gastos del Año</h3>
                    <p class="text-2xl font-bold text-gray-900">
                        <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($gastos_anio['total_anio'], 2); ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo date('Y'); ?></p>
                </div>
                <div class="text-green-500">
                    <i class="fas fa-chart-line text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Promedio Mensual -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-purple-500">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Promedio Mensual</h3>
                    <p class="text-2xl font-bold text-gray-900">
                        <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($promedio_mensual['promedio'], 2); ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-1">Histórico</p>
                </div>
                <div class="text-purple-500">
                    <i class="fas fa-chart-bar text-2xl"></i>
                </div>
            </div>
        </div>

        <!-- Gastos de Hoy -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-orange-500">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-medium text-gray-500">Gastos de Hoy</h3>
                    <p class="text-2xl font-bold text-gray-900">
                        <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($gastos_hoy['total_hoy'], 2); ?>
                    </p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo date('d/m/Y'); ?></p>
                </div>
                <div class="text-orange-500">
                    <i class="fas fa-money-bill-wave text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Distribución por Categorías -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Gastos por Categoría -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-chart-pie mr-2 text-blue-500"></i>
                    Gastos por Categoría (Mes Actual)
                </h2>
            </div>
            <div class="p-6">
                <?php
                $query_categorias_mes = "SELECT categoria, SUM(monto) as total 
                                       FROM gastos 
                                       WHERE MONTH(fecha) = MONTH(CURDATE()) 
                                       AND YEAR(fecha) = YEAR(CURDATE())
                                       GROUP BY categoria 
                                       ORDER BY total DESC";
                $stmt = $db->prepare($query_categorias_mes);
                $stmt->execute();
                $categorias_mes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <div class="space-y-4">
                    <?php foreach ($categorias_mes as $categoria): 
                        $porcentaje = $gastos_mes['total_mes'] > 0 ? ($categoria['total'] / $gastos_mes['total_mes']) * 100 : 0;
                    ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div class="flex-1">
                            <div class="flex justify-between mb-1">
                                <span class="text-sm font-medium text-gray-900"><?php echo $categoria['categoria']; ?></span>
                                <span class="text-sm text-gray-500"><?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($categoria['total'], 2); ?></span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <div class="bg-blue-600 h-2 rounded-full" 
                                     style="width: <?php echo $porcentaje; ?>%"></div>
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                <?php echo number_format($porcentaje, 1); ?>% del total
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if (count($categorias_mes) === 0): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-chart-pie text-4xl mb-3 opacity-50"></i>
                        <p>No hay gastos registrados este mes</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top 5 Gastos del Mes -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-list-ol mr-2 text-green-500"></i>
                    Top 5 Gastos del Mes
                </h2>
            </div>
            <div class="p-6">
                <?php
                $query_top_gastos = "SELECT descripcion, categoria, monto, fecha 
                                   FROM gastos 
                                   WHERE MONTH(fecha) = MONTH(CURDATE()) 
                                   AND YEAR(fecha) = YEAR(CURDATE())
                                   ORDER BY monto DESC 
                                   LIMIT 5";
                $stmt = $db->prepare($query_top_gastos);
                $stmt->execute();
                $top_gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <div class="space-y-3">
                    <?php if(count($top_gastos) > 0): ?>
                        <?php foreach ($top_gastos as $index => $gasto): 
                            $rank_color = $index == 0 ? 'bg-yellow-100 text-yellow-800' : 
                                        ($index == 1 ? 'bg-gray-100 text-gray-800' : 
                                        ($index == 2 ? 'bg-orange-100 text-orange-800' : 'bg-blue-100 text-blue-800'));
                        ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                            <div class="flex items-center flex-1">
                                <span class="flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold <?php echo $rank_color; ?> mr-3">
                                    <?php echo $index + 1; ?>
                                </span>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 truncate">
                                        <?php echo htmlspecialchars($gasto['descripcion']); ?>
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        <?php echo $gasto['categoria']; ?> • <?php echo date('d/m', strtotime($gasto['fecha'])); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold text-red-600">
                                    <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($gasto['monto'], 2); ?>
                                </p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-receipt text-4xl mb-3 opacity-50"></i>
                            <p>No hay gastos registrados este mes</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Lista de Gastos Recientes -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-semibold text-gray-900 flex items-center">
                <i class="fas fa-history mr-2 text-gray-500"></i>
                Gastos Recientes
            </h2>
            <div class="flex space-x-2">
                <a href="crear.php" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm flex items-center">
                    <i class="fas fa-plus mr-1"></i> Nuevo
                </a>
            </div>
        </div>
        <div class="p-6">
            <?php
            $query_gastos = "SELECT g.*, u.nombre as usuario_nombre 
                           FROM gastos g 
                           LEFT JOIN usuarios u ON g.usuario_id = u.id 
                           ORDER BY g.fecha DESC, g.created_at DESC 
                           LIMIT 10";
            $stmt = $db->prepare($query_gastos);
            $stmt->execute();
            $gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <?php if (count($gastos) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Descripción</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Categoría</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Monto</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Registrado por</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($gastos as $gasto): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo date('d/m/Y', strtotime($gasto['fecha'])); ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($gasto['descripcion']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        <?php echo $gasto['categoria']; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-red-600">
                                    <?php echo $config['moneda'] ?? 'USD'; ?> <?php echo number_format($gasto['monto'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo $gasto['usuario_nombre']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                    <?php if ($auth->hasPermission('gastos', 'escritura')): ?>
                                    <a href="editar.php?id=<?php echo $gasto['id']; ?>" class="text-blue-600 hover:text-blue-900">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($auth->hasPermission('gastos', 'completo')): ?>
                                    <a href="eliminar.php?id=<?php echo $gasto['id']; ?>" class="text-red-600 hover:text-red-900" onclick="return confirm('¿Eliminar este gasto?')">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="mt-4 text-center">
                    <a href="listar.php" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                        Ver todos los gastos →
                    </a>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-receipt text-4xl text-gray-400 mb-3"></i>
                    <p class="text-gray-500">No hay gastos registrados</p>
                    <p class="text-sm text-gray-400 mt-1">Comienza registrando tu primer gasto</p>
                    <a href="crear.php" class="inline-block mt-4 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                        <i class="fas fa-plus mr-2"></i>Registrar Primer Gasto
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>