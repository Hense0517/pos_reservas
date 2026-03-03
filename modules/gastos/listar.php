<?php 
include '../../includes/header.php';

// Verificar permisos
if (!$auth->hasPermission('gastos', 'lectura')) {
    header("Location: ../../index.php");
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Filtros
$filtro_categoria = $_GET['categoria'] ?? '';
$filtro_mes = $_GET['mes'] ?? '';
$filtro_anio = $_GET['anio'] ?? date('Y');

// Construir consulta con filtros
$where_conditions = [];
$params = [];

if ($filtro_categoria) {
    $where_conditions[] = "g.categoria = ?";
    $params[] = $filtro_categoria;
}

if ($filtro_mes) {
    $where_conditions[] = "MONTH(g.fecha) = ?";
    $params[] = $filtro_mes;
}

if ($filtro_anio) {
    $where_conditions[] = "YEAR(g.fecha) = ?";
    $params[] = $filtro_anio;
}

$where_sql = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Consulta principal
$query = "SELECT g.*, u.nombre as usuario_nombre 
          FROM gastos g 
          LEFT JOIN usuarios u ON g.usuario_id = u.id 
          $where_sql 
          ORDER BY g.fecha DESC, g.created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute($params);
$gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Categorías para el filtro
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
            <h1 class="text-3xl font-bold text-gray-900">Lista Completa de Gastos</h1>
            <p class="text-gray-600">Todos los gastos registrados en el sistema</p>
        </div>
        <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-arrow-left mr-2"></i>
            Volver al Dashboard
        </a>
    </div>

    <!-- Filtros -->
    <div class="bg-white shadow rounded-lg mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Filtros</h2>
        </div>
        <div class="p-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="categoria" class="block text-sm font-medium text-gray-700 mb-1">Categoría</label>
                    <select id="categoria" name="categoria" class="w-full border border-gray-300 rounded-md py-2 px-3">
                        <option value="">Todas las categorías</option>
                        <?php foreach ($categorias as $categoria): ?>
                        <option value="<?php echo $categoria; ?>" <?php echo $filtro_categoria == $categoria ? 'selected' : ''; ?>>
                            <?php echo $categoria; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="mes" class="block text-sm font-medium text-gray-700 mb-1">Mes</label>
                    <select id="mes" name="mes" class="w-full border border-gray-300 rounded-md py-2 px-3">
                        <option value="">Todos los meses</option>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $filtro_mes == $i ? 'selected' : ''; ?>>
                            <?php echo DateTime::createFromFormat('!m', $i)->format('F'); ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div>
                    <label for="anio" class="block text-sm font-medium text-gray-700 mb-1">Año</label>
                    <select id="anio" name="anio" class="w-full border border-gray-300 rounded-md py-2 px-3">
                        <?php for ($i = date('Y'); $i >= 2020; $i--): ?>
                        <option value="<?php echo $i; ?>" <?php echo $filtro_anio == $i ? 'selected' : ''; ?>>
                            <?php echo $i; ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                
                <div class="flex items-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded w-full">
                        <i class="fas fa-filter mr-2"></i>Filtrar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Lista de gastos -->
    <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-semibold text-gray-900">
                <?php echo count($gastos); ?> gastos encontrados
            </h2>
            <div class="flex space-x-2">
                <a href="crear.php" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm flex items-center">
                    <i class="fas fa-plus mr-1"></i> Nuevo Gasto
                </a>
            </div>
        </div>
        
        <div class="p-6">
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
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-receipt text-4xl text-gray-400 mb-3"></i>
                    <p class="text-gray-500">No se encontraron gastos con los filtros aplicados</p>
                    <a href="listar.php" class="inline-block mt-4 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                        <i class="fas fa-times mr-2"></i>Limpiar Filtros
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>