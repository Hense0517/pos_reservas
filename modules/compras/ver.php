<?php
/**
 * ============================================
 * ARCHIVO: ver.php
 * UBICACIÓN: /modules/compras/ver.php
 * PROPÓSITO: Ver detalles completos de una compra
 * ============================================
 */

session_start();
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/header.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    $_SESSION['error'] = "ID de compra no válido";
    header("Location: index.php");
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Obtener datos de la compra
    $stmt = $db->prepare("SELECT c.*, p.nombre as proveedor_nombre, p.telefono as proveedor_telefono, 
                                 p.email as proveedor_email, u.nombre as usuario_nombre 
                          FROM compras c 
                          LEFT JOIN proveedores p ON c.proveedor_id = p.id 
                          LEFT JOIN usuarios u ON c.usuario_id = u.id 
                          WHERE c.id = ?");
    $stmt->execute([$id]);
    $compra = $stmt->fetch();
    
    if (!$compra) {
        $_SESSION['error'] = "Compra no encontrada";
        header("Location: index.php");
        exit();
    }
    
    // Obtener detalles de la compra
    $stmt = $db->prepare("SELECT cd.*, pr.nombre as producto_nombre, pr.codigo 
                          FROM compra_detalles cd 
                          LEFT JOIN productos pr ON cd.producto_id = pr.id 
                          WHERE cd.compra_id = ?");
    $stmt->execute([$id]);
    $detalles = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $_SESSION['error'] = "Error al cargar datos";
    header("Location: index.php");
    exit();
}
?>

<div class="max-w-7xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-eye text-blue-600 mr-2"></i>
                Detalles de Compra
            </h1>
            <p class="text-gray-600 mt-1">Factura: <?php echo htmlspecialchars($compra['numero_factura']); ?></p>
        </div>
        <div class="flex space-x-3 mt-4 md:mt-0">
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver
            </a>
            <a href="imprimir.php?id=<?php echo $id; ?>" target="_blank" 
               class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-print mr-2"></i>
                Imprimir
            </a>
            <?php if ($compra['estado'] == 'pendiente'): ?>
                <a href="editar.php?id=<?php echo $id; ?>" 
                   class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-edit mr-2"></i>
                    Editar
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- Información principal -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Información de la compra -->
        <div class="lg:col-span-1 space-y-6">
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold mb-4 border-b pb-2">
                    <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                    Información de la Compra
                </h2>
                
                <dl class="space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Factura:</dt>
                        <dd class="font-medium"><?php echo htmlspecialchars($compra['numero_factura']); ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Fecha:</dt>
                        <dd class="font-medium"><?php echo date('d/m/Y H:i', strtotime($compra['fecha'])); ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Usuario:</dt>
                        <dd class="font-medium"><?php echo htmlspecialchars($compra['usuario_nombre']); ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Estado:</dt>
                        <dd>
                            <?php
                            $estado_colors = [
                                'pendiente' => 'bg-yellow-100 text-yellow-800',
                                'recibida' => 'bg-green-100 text-green-800',
                                'cancelada' => 'bg-red-100 text-red-800'
                            ];
                            $color = $estado_colors[$compra['estado']] ?? 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="px-2 py-1 text-xs rounded-full <?php echo $color; ?>">
                                <?php echo ucfirst($compra['estado']); ?>
                            </span>
                        </dd>
                    </div>
                </dl>
            </div>

            <!-- Información del proveedor -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold mb-4 border-b pb-2">
                    <i class="fas fa-truck text-green-600 mr-2"></i>
                    Proveedor
                </h2>
                
                <dl class="space-y-3">
                    <div>
                        <dt class="text-gray-600">Nombre:</dt>
                        <dd class="font-medium"><?php echo htmlspecialchars($compra['proveedor_nombre'] ?? 'N/A'); ?></dd>
                    </div>
                    <?php if (!empty($compra['proveedor_telefono'])): ?>
                    <div>
                        <dt class="text-gray-600">Teléfono:</dt>
                        <dd class="font-medium"><?php echo htmlspecialchars($compra['proveedor_telefono']); ?></dd>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($compra['proveedor_email'])): ?>
                    <div>
                        <dt class="text-gray-600">Email:</dt>
                        <dd class="font-medium"><?php echo htmlspecialchars($compra['proveedor_email']); ?></dd>
                    </div>
                    <?php endif; ?>
                </dl>
            </div>

            <!-- Resumen financiero -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold mb-4 border-b pb-2">
                    <i class="fas fa-calculator text-purple-600 mr-2"></i>
                    Resumen Financiero
                </h2>
                
                <dl class="space-y-3">
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Subtotal:</dt>
                        <dd class="font-medium">$<?php echo number_format($compra['subtotal'], 0, ',', '.'); ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-600">Impuesto:</dt>
                        <dd class="font-medium">$<?php echo number_format($compra['impuesto'], 0, ',', '.'); ?></dd>
                    </div>
                    <div class="flex justify-between text-lg font-bold border-t pt-2">
                        <dt class="text-gray-800">Total:</dt>
                        <dd class="text-green-600">$<?php echo number_format($compra['total'], 0, ',', '.'); ?></dd>
                    </div>
                </dl>
            </div>
        </div>

        <!-- Productos -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-semibold mb-4 border-b pb-2">
                    <i class="fas fa-boxes text-green-600 mr-2"></i>
                    Productos
                </h2>
                
                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">Producto</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500">Cantidad</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500">Precio Unit.</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($detalles as $d): ?>
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="font-medium"><?php echo htmlspecialchars($d['producto_nombre']); ?></div>
                                    <div class="text-xs text-gray-500">Código: <?php echo htmlspecialchars($d['codigo']); ?></div>
                                </td>
                                <td class="px-4 py-3 text-right"><?php echo $d['cantidad']; ?></td>
                                <td class="px-4 py-3 text-right">$<?php echo number_format($d['precio'], 0, ',', '.'); ?></td>
                                <td class="px-4 py-3 text-right font-medium">$<?php echo number_format($d['cantidad'] * $d['precio'], 0, ',', '.'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="3" class="px-4 py-3 text-right font-bold">Total Productos:</td>
                                <td class="px-4 py-3 text-right font-bold text-green-600">
                                    $<?php echo number_format($compra['total'], 0, ',', '.'); ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>