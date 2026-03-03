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

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'crear_tipo') {
            $nombre = trim($_POST['nombre']);
            $descripcion = trim($_POST['descripcion']);
            $tipo_dato = $_POST['tipo_dato'];
            $unidad = trim($_POST['unidad']);
            $icono = $_POST['icono'];
            
            $query = "INSERT INTO tipos_atributo (nombre, descripcion, tipo_dato, unidad, icono) VALUES (?, ?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->execute([$nombre, $descripcion, $tipo_dato, $unidad, $icono]);
            
            $_SESSION['success'] = "Tipo de atributo creado correctamente.";
            
        } elseif ($action === 'editar_tipo') {
            $id = intval($_POST['id']);
            $nombre = trim($_POST['nombre']);
            $descripcion = trim($_POST['descripcion']);
            $tipo_dato = $_POST['tipo_dato'];
            $unidad = trim($_POST['unidad']);
            $icono = $_POST['icono'];
            $activo = isset($_POST['activo']) ? 1 : 0;
            
            $query = "UPDATE tipos_atributo SET nombre = ?, descripcion = ?, tipo_dato = ?, unidad = ?, icono = ?, activo = ? WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$nombre, $descripcion, $tipo_dato, $unidad, $icono, $activo, $id]);
            
            $_SESSION['success'] = "Tipo de atributo actualizado correctamente.";
            
        } elseif ($action === 'eliminar_tipo') {
            $id = intval($_POST['id']);
            
            // Verificar si hay valores asociados
            $check = "SELECT COUNT(*) as total FROM valores_atributo WHERE tipo_atributo_id = ?";
            $stmt = $db->prepare($check);
            $stmt->execute([$id]);
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($total > 0) {
                $_SESSION['error'] = "No se puede eliminar porque tiene $total valores asociados.";
            } else {
                $query = "DELETE FROM tipos_atributo WHERE id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$id]);
                $_SESSION['success'] = "Tipo de atributo eliminado correctamente.";
            }
        }
        
        header("Location: tipos.php");
        exit;
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
}

// Obtener todos los tipos de atributos
$query = "SELECT * FROM tipos_atributo ORDER BY nombre";
$stmt = $db->prepare($query);
$stmt->execute();
$tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;
unset($_SESSION['error'], $_SESSION['success']);
?>

<div class="max-w-6xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Tipos de Atributos</h1>
                <div class="flex items-center mt-2">
                    <a href="../productos/" class="text-blue-600 hover:text-blue-800 mr-2">
                        <i class="fas fa-arrow-left"></i> Volver a productos
                    </a>
                    <span class="text-gray-400 mx-2">•</span>
                    <span class="text-gray-600">Gestiona los tipos de atributos del sistema</span>
                </div>
            </div>
            <button onclick="abrirModalCrear()" class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-md inline-flex items-center">
                <i class="fas fa-plus mr-2"></i>
                Nuevo Tipo de Atributo
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

    <!-- Grid de tipos de atributos -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($tipos as $tipo): ?>
        <div class="bg-white rounded-lg shadow overflow-hidden border border-gray-200 hover:shadow-lg transition-shadow">
            <div class="p-5">
                <div class="flex items-start justify-between">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center text-indigo-600 mr-4">
                            <i class="<?php echo $tipo['icono']; ?> text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($tipo['nombre']); ?></h3>
                            <?php if ($tipo['unidad']): ?>
                            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-full">Unidad: <?php echo $tipo['unidad']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="px-2 py-1 text-xs rounded-full <?php echo $tipo['activo'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                        <?php echo $tipo['activo'] ? 'Activo' : 'Inactivo'; ?>
                    </span>
                </div>
                
                <p class="text-sm text-gray-600 mt-3"><?php echo htmlspecialchars($tipo['descripcion'] ?: 'Sin descripción'); ?></p>
                
                <div class="mt-4 flex items-center justify-between text-sm">
                    <span class="text-gray-500">
                        <i class="fas fa-database mr-1"></i> Tipo: <?php echo $tipo['tipo_dato']; ?>
                    </span>
                    
                    <div class="flex space-x-2">
                        <a href="valores.php?tipo_id=<?php echo $tipo['id']; ?>" 
                           class="text-indigo-600 hover:text-indigo-900" title="Gestionar valores">
                            <i class="fas fa-list"></i>
                        </a>
                        <button onclick="editarTipo(<?php echo htmlspecialchars(json_encode($tipo)); ?>)" 
                                class="text-blue-600 hover:text-blue-900" title="Editar">
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php if ($tipo['nombre'] != 'Talla' && $tipo['nombre'] != 'Color'): ?>
                        <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este tipo de atributo?')">
                            <input type="hidden" name="action" value="eliminar_tipo">
                            <input type="hidden" name="id" value="<?php echo $tipo['id']; ?>">
                            <button type="submit" class="text-red-600 hover:text-red-900" title="Eliminar">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal Crear/Editar Tipo -->
<div id="modalTipo" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-lg bg-white">
        <div class="flex justify-between items-center pb-3 mb-3 border-b">
            <h3 class="text-lg font-semibold text-gray-900" id="modalTipoTitle">Nuevo Tipo de Atributo</h3>
            <button onclick="cerrarModalTipo()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form method="POST" id="formTipo">
            <input type="hidden" name="action" id="tipoAction" value="crear_tipo">
            <input type="hidden" name="id" id="tipoId" value="">
            
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre *</label>
                    <input type="text" name="nombre" id="tipoNombre" required
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                    <textarea name="descripcion" id="tipoDescripcion" rows="2"
                              class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de dato</label>
                    <select name="tipo_dato" id="tipoDato" required
                            class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="select">Select (lista desplegable)</option>
                        <option value="radio">Radio (opción única)</option>
                        <option value="texto">Texto libre</option>
                        <option value="numero">Número</option>
                        <option value="decimal">Decimal</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Unidad de medida</label>
                    <input type="text" name="unidad" id="tipoUnidad"
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                           placeholder="Ej: gr, ml, cm, kg">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Icono (FontAwesome)</label>
                    <select name="icono" id="tipoIcono"
                            class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="fas fa-tag">Etiqueta</option>
                        <option value="fas fa-ruler">Regla</option>
                        <option value="fas fa-weight-hanging">Peso</option>
                        <option value="fas fa-flask">Volumen</option>
                        <option value="fas fa-palette">Paleta</option>
                        <option value="fas fa-tshirt">Camiseta</option>
                        <option value="fas fa-shoe-prints">Zapato</option>
                        <option value="fas fa-expand">Expandir</option>
                        <option value="fas fa-cube">Cubo</option>
                        <option value="fas fa-venus-mars">Género</option>
                        <option value="fas fa-sun">Sol</option>
                    </select>
                </div>
                
                <div class="flex items-center">
                    <input type="checkbox" name="activo" id="tipoActivo" value="1" class="mr-2" checked>
                    <label for="tipoActivo" class="text-sm text-gray-700">Activo</label>
                </div>
            </div>
            
            <div class="flex justify-end gap-2 mt-6 pt-3 border-t">
                <button type="button" onclick="cerrarModalTipo()" 
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
    document.getElementById('modalTipoTitle').innerText = 'Nuevo Tipo de Atributo';
    document.getElementById('tipoAction').value = 'crear_tipo';
    document.getElementById('tipoId').value = '';
    document.getElementById('tipoNombre').value = '';
    document.getElementById('tipoDescripcion').value = '';
    document.getElementById('tipoDato').value = 'select';
    document.getElementById('tipoUnidad').value = '';
    document.getElementById('tipoIcono').value = 'fas fa-tag';
    document.getElementById('tipoActivo').checked = true;
    
    document.getElementById('modalTipo').classList.remove('hidden');
}

function editarTipo(tipo) {
    document.getElementById('modalTipoTitle').innerText = 'Editar Tipo de Atributo';
    document.getElementById('tipoAction').value = 'editar_tipo';
    document.getElementById('tipoId').value = tipo.id;
    document.getElementById('tipoNombre').value = tipo.nombre;
    document.getElementById('tipoDescripcion').value = tipo.descripcion || '';
    document.getElementById('tipoDato').value = tipo.tipo_dato;
    document.getElementById('tipoUnidad').value = tipo.unidad || '';
    document.getElementById('tipoIcono').value = tipo.icono || 'fas fa-tag';
    document.getElementById('tipoActivo').checked = tipo.activo == 1;
    
    document.getElementById('modalTipo').classList.remove('hidden');
}

function cerrarModalTipo() {
    document.getElementById('modalTipo').classList.add('hidden');
}
</script>

<?php include '../../../includes/footer.php'; ?>