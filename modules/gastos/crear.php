<?php
if (session_status() === PHP_SESSION_NONE) session_start(); 
// Iniciar buffer de salida
ob_start();

include '../../includes/config.php';

// Verificar permisos
if (!$auth->hasPermission('gastos', 'escritura')) {
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

// Procesar formulario
if ($_POST) {
    try {
        $descripcion = trim($_POST['descripcion']);
        $categoria = $_POST['categoria'];
        $monto = floatval($_POST['monto']);
        $fecha = $_POST['fecha'];
        $usuario_id = $user_info['id'];

        $query = "INSERT INTO gastos (descripcion, categoria, monto, fecha, usuario_id) 
                 VALUES (?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        $stmt->execute([$descripcion, $categoria, $monto, $fecha, $usuario_id]);

        $_SESSION['success'] = "Gasto registrado correctamente";
        
        // Limpiar buffer antes de redireccionar
        ob_end_clean();
        header("Location: index.php");
        exit;
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error al registrar el gasto: " . $e->getMessage();
    }
}

// Si no hay redirección, incluir el header después de procesar el POST
include '../../includes/header.php';
?>

<div class="max-w-2xl mx-auto">
    <div class="bg-white shadow rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Registrar Nuevo Gasto</h2>
            <p class="text-sm text-gray-600">Complete la información del gasto</p>
        </div>
        
        <form method="POST" class="p-6 space-y-6">
            <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
            <?php endif; ?>

            <div>
                <label for="descripcion" class="block text-sm font-medium text-gray-700 mb-1">
                    Descripción del Gasto *
                </label>
                <input type="text" id="descripcion" name="descripcion" required
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Ej: Pago de servicios de luz, Compra de material de oficina">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="categoria" class="block text-sm font-medium text-gray-700 mb-1">
                        Categoría *
                    </label>
                    <select id="categoria" name="categoria" required
                            class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Seleccione una categoría</option>
                        <?php foreach ($categorias as $categoria): ?>
                        <option value="<?php echo $categoria; ?>"><?php echo $categoria; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="monto" class="block text-sm font-medium text-gray-700 mb-1">
                        Monto *
                    </label>
                    <input type="number" id="monto" name="monto" step="0.01" min="0" required
                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                           placeholder="0.00">
                </div>
            </div>

            <div>
                <label for="fecha" class="block text-sm font-medium text-gray-700 mb-1">
                    Fecha del Gasto *
                </label>
                <input type="date" id="fecha" name="fecha" required
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                       value="<?php echo date('Y-m-d'); ?>">
            </div>

            <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200">
                <a href="index.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Cancelar
                </a>
                <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-save mr-2"></i>
                    Registrar Gasto
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>