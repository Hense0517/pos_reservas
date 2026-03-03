<?php
/**
 * ============================================
 * ARCHIVO: sync_estados_ventas.php
 * UBICACIÓN: /modules/cuentas_por_cobrar/sync_estados_ventas.php
 * FECHA CORRECCIÓN: 2026-02-17
 * 
 * PROPÓSITO:
 * Sincronizar los estados de las ventas a crédito con sus cuentas por cobrar
 * 
 * PROBLEMA ORIGINAL:
 * 1. Usaba ruta incorrecta a database.php
 * 2. No verificaba autenticación correctamente
 * 3. No usaba BASE_URL para redirecciones
 * 4. Diseño sin estilos del sistema
 * 
 * SOLUCIÓN APLICADA:
 * - Usar __DIR__ para rutas absolutas
 * - Incluir header/footer del sistema
 * - Usar BASE_URL para redirecciones
 * - Mejorar interfaz de usuario
 * ============================================
 */

session_start();

// Incluir configuración principal (que ya incluye Database)
require_once __DIR__ . '/../../includes/config.php';

// Incluir header del sistema
include __DIR__ . '/../../includes/header.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    $_SESSION['error'] = "Debe iniciar sesión para acceder a esta función";
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar permisos (solo administradores)
if ($_SESSION['usuario_rol'] != 'admin') {
    $_SESSION['error'] = "Acceso denegado. Solo administradores.";
    header("Location: " . BASE_URL . "index.php");
    exit();
}

// Conexión a base de datos
try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

$resultados = [
    'total_ventas' => 0,
    'actualizadas' => 0,
    'sin_cambios' => 0,
    'detalles' => []
];

try {
    $db->beginTransaction();
    
    // 1. Obtener todas las ventas a crédito
    $sql_ventas = "SELECT v.id, v.numero_factura, v.estado as estado_venta,
                          v.cliente_id,
                          c.nombre as cliente_nombre,
                          cp.id as cuenta_id, cp.estado as estado_cuenta, 
                          cp.saldo_pendiente, cp.total_deuda
                   FROM ventas v
                   LEFT JOIN clientes c ON v.cliente_id = c.id
                   LEFT JOIN cuentas_por_cobrar cp ON v.id = cp.venta_id
                   WHERE v.tipo_venta = 'credito'
                   ORDER BY v.fecha DESC";
    
    $stmt_ventas = $db->prepare($sql_ventas);
    $stmt_ventas->execute();
    $ventas = $stmt_ventas->fetchAll(PDO::FETCH_ASSOC);
    
    $resultados['total_ventas'] = count($ventas);
    
    foreach ($ventas as $venta) {
        $nuevo_estado = 'completada'; // Por defecto
        $motivo = '';
        
        if ($venta['cuenta_id']) {
            // Hay cuenta por cobrar asociada
            if ($venta['saldo_pendiente'] == 0) {
                $nuevo_estado = 'pagada_credito';
                $motivo = 'Cuenta pagada completamente';
            } else if ($venta['saldo_pendiente'] > 0) {
                $nuevo_estado = 'pendiente_credito';
                $motivo = 'Saldo pendiente: $' . number_format($venta['saldo_pendiente'], 0, ',', '.');
            }
        } else {
            // No tiene cuenta por cobrar (error)
            $nuevo_estado = 'pendiente_credito';
            $motivo = 'Sin cuenta por cobrar asociada';
        }
        
        // Registrar detalle
        $detalle = [
            'factura' => $venta['numero_factura'],
            'cliente' => $venta['cliente_nombre'] ?? 'N/A',
            'estado_anterior' => $venta['estado_venta'],
            'estado_nuevo' => $nuevo_estado,
            'motivo' => $motivo
        ];
        
        // Actualizar solo si es diferente
        if ($venta['estado_venta'] != $nuevo_estado) {
            $sql_update = "UPDATE ventas SET estado = ?, updated_at = NOW() WHERE id = ?";
            $stmt_update = $db->prepare($sql_update);
            $stmt_update->execute([$nuevo_estado, $venta['id']]);
            
            $resultados['actualizadas']++;
            $detalle['actualizada'] = true;
        } else {
            $resultados['sin_cambios']++;
            $detalle['actualizada'] = false;
        }
        
        $resultados['detalles'][] = $detalle;
    }
    
    $db->commit();
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $_SESSION['error'] = "Error durante la sincronización: " . $e->getMessage();
    header("Location: index.php");
    exit();
}

$page_title = "Sincronización de Estados - " . ($config['nombre_negocio'] ?? 'Sistema POS');
?>

<div class="max-w-6xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-sync-alt text-blue-600 mr-2"></i>
                Sincronización de Estados
            </h1>
            <p class="text-gray-600 mt-1">Sincronizar estados de ventas a crédito con cuentas por cobrar</p>
        </div>
        <div class="flex space-x-3">
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver a Cuentas
            </a>
            <a href="../ventas/index.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-shopping-cart mr-2"></i>
                Ver Ventas
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- Resumen -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-blue-100 text-blue-600">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Total Ventas</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $resultados['total_ventas']; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-green-100 text-green-600">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Actualizadas</p>
                    <p class="text-2xl font-bold text-green-600"><?php echo $resultados['actualizadas']; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-gray-100 text-gray-600">
                    <i class="fas fa-minus-circle"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Sin Cambios</p>
                    <p class="text-2xl font-bold text-gray-600"><?php echo $resultados['sin_cambios']; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-purple-100 text-purple-600">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Fecha</p>
                    <p class="text-lg font-bold text-gray-900"><?php echo date('d/m/Y H:i'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Resultados detallados -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">
                <i class="fas fa-list-ul text-blue-600 mr-2"></i>
                Detalle de Ventas Procesadas
            </h2>
        </div>
        
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Factura</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cliente</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado Anterior</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado Nuevo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Motivo</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acción</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($resultados['detalles'] as $detalle): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span class="font-medium text-blue-600">
                                    <?php echo htmlspecialchars($detalle['factura']); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <?php echo htmlspecialchars($detalle['cliente']); ?>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 text-xs rounded-full 
                                    <?php 
                                    switch($detalle['estado_anterior']) {
                                        case 'completada':
                                            echo 'bg-green-100 text-green-800';
                                            break;
                                        case 'pendiente_credito':
                                            echo 'bg-yellow-100 text-yellow-800';
                                            break;
                                        case 'pagada_credito':
                                            echo 'bg-blue-100 text-blue-800';
                                            break;
                                        default:
                                            echo 'bg-gray-100 text-gray-800';
                                    }
                                    ?>">
                                    <?php 
                                    $estado_anterior = str_replace('_', ' ', $detalle['estado_anterior']);
                                    echo ucfirst($estado_anterior);
                                    ?>
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($detalle['actualizada']): ?>
                                    <span class="px-2 py-1 text-xs rounded-full 
                                        <?php 
                                        switch($detalle['estado_nuevo']) {
                                            case 'completada':
                                                echo 'bg-green-100 text-green-800';
                                                break;
                                            case 'pendiente_credito':
                                                echo 'bg-yellow-100 text-yellow-800';
                                                break;
                                            case 'pagada_credito':
                                                echo 'bg-blue-100 text-blue-800';
                                                break;
                                            default:
                                                echo 'bg-gray-100 text-gray-800';
                                        }
                                        ?>">
                                        <?php 
                                        $estado_nuevo = str_replace('_', ' ', $detalle['estado_nuevo']);
                                        echo ucfirst($estado_nuevo);
                                        ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400 italic">Sin cambios</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                <?php echo htmlspecialchars($detalle['motivo']); ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($detalle['actualizada']): ?>
                                    <span class="text-green-600">
                                        <i class="fas fa-check-circle mr-1"></i>
                                        Actualizada
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400">
                                        <i class="fas fa-minus-circle mr-1"></i>
                                        Sin cambios
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr>
                        <td colspan="6" class="px-4 py-3 text-sm text-gray-600">
                            <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                            Total: <?php echo $resultados['total_ventas']; ?> ventas | 
                            Actualizadas: <?php echo $resultados['actualizadas']; ?> | 
                            Sin cambios: <?php echo $resultados['sin_cambios']; ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Botones de acción adicionales -->
    <div class="flex justify-center space-x-4 mt-6">
        <button onclick="window.location.reload()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-sync-alt mr-2"></i>
            Ejecutar nuevamente
        </button>
        
        <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-arrow-left mr-2"></i>
            Volver a Cuentas por Cobrar
        </a>
    </div>
</div>

<script>
// Auto-refresh opcional (descomentar si se desea)
// setTimeout(function() {
//     window.location.reload();
// }, 5000);
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>