<?php
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();
include '../../../includes/header.php';

// Verificar permisos
if ($_SESSION['usuario_rol'] != 'admin') {
    header('Location: /sistema_pos/index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

$tipo_id = isset($_GET['tipo_id']) ? intval($_GET['tipo_id']) : 0;

if ($tipo_id <= 0) {
    header('Location: tipos.php');
    exit;
}

// Obtener información del tipo
$query = "SELECT * FROM tipos_atributo WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$tipo_id]);
$tipo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tipo) {
    header('Location: tipos.php');
    exit;
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'crear_valor') {
            $valor = trim($_POST['valor']);
            $valor_numerico = !empty($_POST['valor_numerico']) ? floatval($_POST['valor_numerico']) : null;
            $orden = intval($_POST['orden'] ?? 0);
            
            $query = "INSERT INTO valores_atributo (tipo_atributo_id, valor, valor_numerico, orden) VALUES (?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->execute([$tipo_id, $valor, $valor_numerico, $orden]);
            
            $_SESSION['success'] = "Valor agregado correctamente.";
            
        } elseif ($action === 'editar_valor') {
            $id = intval($_POST['id']);
            $valor = trim($_POST['valor']);
            $valor_numerico = !empty($_POST['valor_numerico']) ? floatval($_POST['valor_numerico']) : null;
            $orden = intval($_POST['orden'] ?? 0);
            $activo = isset($_POST['activo']) ? 1 : 0;
            
            $query = "UPDATE valores_atributo SET valor = ?, valor_numerico = ?, orden = ?, activo = ? WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$valor, $valor_numerico, $orden, $activo, $id]);
            
            $_SESSION['success'] = "Valor actualizado correctamente.";
            
        } elseif ($action === 'eliminar_valor') {
            $id = intval($_POST['id']);
            
            $query = "DELETE FROM valores_atributo WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$id]);
            
            $_SESSION['success'] = "Valor eliminado correctamente.";
        }
        
        header("Location: valores.php?tipo_id=" . $tipo_id);
        exit;
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
}

// Obtener valores del tipo
$query = "SELECT * FROM valores_atributo WHERE tipo_atributo_id = ? ORDER BY orden, valor_numerico, valor";
$stmt = $db->prepare($query);
$stmt->execute([$tipo_id]);
$valores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;
unset($_SESSION['error'], $_SESSION['success']);
?>

<div class="max-w-4xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Valores de <?php echo htmlspecialchars($tipo['nombre']); ?></h1>
                <div class="flex items-center mt-2">
                    <a href="tipos.php" class="text-blue-600 hover:text-blue-800 mr-2">
                        <i class="fas fa-arrow-left"></i> Volver a tipos
                    </a>
                    <span class="text-gray-400 mx-2">•</span>
                    <span class="text-gray-600">Gestiona los valores disponibles</span>
                </div>
            </div>
            <button onclick="abrirModalCrear()" class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-md inline-flex items-center">
                <i class="fas fa-plus mr-2"></i>
                Nuevo Valor
            </button>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if ($error): ?>
        <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <!-- Tabla de valores -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Valor</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Valor Numérico</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Orden</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($valores)): ?>
                <tr>
                    <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                        <i class="fas fa-database text-4xl mb-3 opacity-30"></i>
                        <p>No hay valores configurados</p>
                        <p class="text-sm mt-2">Agrega valores usando el botón "Nuevo Valor"</p>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($valores as $valor): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap font-medium"><?php echo htmlspecialchars($valor['valor']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap"><?php echo $valor['valor_numerico'] ?? '-'; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap"><?php echo $valor['orden']; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs rounded-full <?php echo $valor['activo'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                <?php echo $valor['activo'] ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                            <button onclick="editarValor(<?php echo htmlspecialchars(json_encode($valor)); ?>)" 
                                    class="text-blue-600 hover:text-blue-900 mr-3">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este valor?')">
                                <input type="hidden" name="action" value="eliminar_valor">
                                <input type="hidden" name="id" value="<?php echo $valor['id']; ?>">
                                <button type="submit" class="text-red-600 hover:text-red-900">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Crear/Editar Valor -->
<div id="modalValor" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-lg bg-white">
        <div class="flex justify-between items-center pb-3 mb-3 border-b">
            <h3 class="text-lg font-semibold text-gray-900" id="modalValorTitle">Nuevo Valor</h3>
            <button onclick="cerrarModalValor()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" id="formValor">
            <input type="hidden" name="action" id="valorAction" value="crear_valor">
            <input type="hidden" name="id" id="valorId" value="">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valor *</label>
                    <input type="text" name="valor" id="valorValor" required
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="Ej: S, M, L, 100gr, etc.">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valor Numérico (para ordenar)</label>
                    <input type="number" step="0.01" name="valor_numerico" id="valorNumerico"
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="Ej: 1, 2, 3, 100, etc.">
                    <p class="text-xs text-gray-500 mt-1">Usado para ordenar valores numéricamente</p>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Orden</label>
                    <input type="number" name="orden" id="valorOrden" value="0"
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="flex items-center">
                    <input type="checkbox" name="activo" id="valorActivo" value="1" class="mr-2" checked>
                    <label for="valorActivo" class="text-sm text-gray-700">Activo</label>
                </div>
            </div>
            
            <div class="flex justify-end gap-2 mt-6 pt-3 border-t">
                <button type="button" onclick="cerrarModalValor()" 
                        class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                    Cancelar
                </button>
                <button type="submit" 
                        class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function abrirModalCrear() {
    document.getElementById('modalValorTitle').innerText = 'Nuevo Valor';
    document.getElementById('valorAction').value = 'crear_valor';
    document.getElementById('valorId').value = '';
    document.getElementById('valorValor').value = '';
    document.getElementById('valorNumerico').value = '';
    document.getElementById('valorOrden').value = '0';
    document.getElementById('valorActivo').checked = true;
    
    document.getElementById('modalValor').classList.remove('hidden');
}

function editarValor(valor) {
    document.getElementById('modalValorTitle').innerText = 'Editar Valor';
    document.getElementById('valorAction').value = 'editar_valor';
    document.getElementById('valorId').value = valor.id;
    document.getElementById('valorValor').value = valor.valor;
    document.getElementById('valorNumerico').value = valor.valor_numerico || '';
    document.getElementById('valorOrden').value = valor.orden || 0;
    document.getElementById('valorActivo').checked = valor.activo == 1;
    
    document.getElementById('modalValor').classList.remove('hidden');
}

function cerrarModalValor() {
    document.getElementById('modalValor').classList.add('hidden');
}
</script>

<?php include '../../../includes/footer.php'; ?>