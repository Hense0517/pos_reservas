<?php
session_start();
require_once '../../includes/config.php';
require_once '../../includes/header.php';

// Verificar permisos
if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../../index.php");
    exit();
}

// Conexión a BD
require_once __DIR__ . '/../../config/database.php';
try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Obtener historial del usuario
$historial = [];
try {
    $sql = "SELECT pv.*, p.nombre as producto_nombre, p.precio_venta, p.imagen_principal
            FROM probador_virtual_historial pv
            LEFT JOIN productos p ON pv.producto_id = p.id
            WHERE pv.usuario_id = ?
            ORDER BY pv.fecha_creacion DESC
            LIMIT 50";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$_SESSION['usuario_id']]);
    $historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $historial = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial del Probador Virtual</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50">
    <?php include '../../includes/header.php'; ?>
    
    <div class="max-w-7xl mx-auto px-4 py-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-6">
            <i class="fas fa-history text-blue-600 mr-3"></i>Historial de Pruebas Virtuales
        </h1>
        
        <?php if (empty($historial)): ?>
            <div class="text-center py-12">
                <i class="fas fa-glasses text-gray-400 text-5xl mb-4"></i>
                <h3 class="text-xl font-medium text-gray-900 mb-2">No hay pruebas guardadas</h3>
                <p class="text-gray-600 mb-6">Usa el probador virtual para guardar tus pruebas favoritas</p>
                <a href="probador_virtual.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg inline-flex items-center">
                    <i class="fas fa-play mr-2"></i> Ir al Probador Virtual
                </a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($historial as $item): ?>
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="p-4">
                        <img src="<?php echo htmlspecialchars($item['imagen_data'] ?? ''); ?>" 
                             class="w-full h-48 object-cover rounded mb-3"
                             alt="Prueba virtual">
                        
                        <h4 class="font-bold text-gray-900 mb-1">
                            <?php echo htmlspecialchars($item['producto_nombre'] ?? 'Producto no encontrado'); ?>
                        </h4>
                        
                        <div class="flex justify-between items-center">
                            <span class="text-blue-600 font-bold">
                                $<?php echo number_format($item['precio_venta'] ?? 0, 2); ?>
                            </span>
                            <span class="text-sm text-gray-500">
                                <?php echo date('d/m/Y H:i', strtotime($item['fecha_creacion'])); ?>
                            </span>
                        </div>
                        
                        <div class="mt-4 flex space-x-2">
                            <button onclick="reuseTryOn(<?php echo $item['id']; ?>)" 
                                    class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 px-3 rounded text-sm">
                                <i class="fas fa-redo mr-1"></i> Reusar
                            </button>
                            <button onclick="shareTryOn(<?php echo $item['id']; ?>)" 
                                    class="flex-1 bg-green-600 hover:bg-green-700 text-white py-2 px-3 rounded text-sm">
                                <i class="fas fa-share-alt mr-1"></i> Compartir
                            </button>
                            <button onclick="deleteTryOn(<?php echo $item['id']; ?>)" 
                                    class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2 px-3 rounded text-sm">
                                <i class="fas fa-trash mr-1"></i> Eliminar
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
    function reuseTryOn(id) {
        window.location.href = `probador_virtual.php?load_tryon=${id}`;
    }
    
    function shareTryOn(id) {
        // Implementar lógica de compartir
        alert('Funcionalidad de compartir en desarrollo');
    }
    
    function deleteTryOn(id) {
        if (confirm('¿Estás seguro de eliminar esta prueba?')) {
            fetch('eliminar_probador.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error al eliminar: ' + data.error);
                }
            });
        }
    }
    </script>
</body>
</html>