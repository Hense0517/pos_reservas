<?php
// Habilitar errores para depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Iniciar sesión
session_start();

// Conexión a la base de datos usando tu archivo config/database.php
require_once '../../../config/database.php';

try {
    // Crear instancia de Database
    $database = Database::getInstance();
    $conn = $database->getConnection();
    
    // Verificar si la conexión se estableció
    if (!$conn) {
        throw new Exception("No se pudo establecer conexión con la base de datos");
    }
    
} catch (Exception $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Configuración
$mensaje = '';
$accion = isset($_GET['accion']) ? $_GET['accion'] : 'listar';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $activo = isset($_POST['activo']) ? 1 : 0;
    
    if (empty($nombre)) {
        $_SESSION['mensaje'] = '<div class="alert alert-danger">El nombre es obligatorio</div>';
    } else {
        try {
            if ($accion === 'crear') {
                // Verificar si ya existe una marca con ese nombre
                $sql_check = "SELECT id FROM marcas WHERE nombre = ?";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([$nombre]);
                
                if ($stmt_check->fetch()) {
                    $_SESSION['mensaje'] = '<div class="alert alert-warning">Ya existe una marca con ese nombre</div>';
                } else {
                    // Crear nueva marca
                    $sql = "INSERT INTO marcas (nombre, descripcion, activo) VALUES (?, ?, ?)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$nombre, $descripcion, $activo]);
                    
                    $_SESSION['mensaje'] = '<div class="alert alert-success">Marca creada exitosamente</div>';
                }
            } elseif ($accion === 'editar' && $id > 0) {
                // Verificar si ya existe otra marca con ese nombre (excluyendo la actual)
                $sql_check = "SELECT id FROM marcas WHERE nombre = ? AND id != ?";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([$nombre, $id]);
                
                if ($stmt_check->fetch()) {
                    $_SESSION['mensaje'] = '<div class="alert alert-warning">Ya existe otra marca con ese nombre</div>';
                } else {
                    // Actualizar marca existente
                    $sql = "UPDATE marcas SET nombre = ?, descripcion = ?, activo = ?, updated_at = NOW() WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$nombre, $descripcion, $activo, $id]);
                    
                    $_SESSION['mensaje'] = '<div class="alert alert-success">Marca actualizada exitosamente</div>';
                }
            }
        } catch (PDOException $e) {
            $_SESSION['mensaje'] = '<div class="alert alert-danger">Error en la base de datos: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
    
    // Redireccionar para evitar reenvío del formulario
    header('Location: index.php?accion=listar');
    exit;
}

// Eliminar marca
if (isset($_GET['eliminar']) && $_GET['eliminar'] == 1 && $id > 0) {
    try {
        // Verificar si hay productos asociados
        $sql_check = "SELECT COUNT(*) as total FROM productos WHERE marca_id = ? AND activo = 1";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([$id]);
        $result = $stmt_check->fetch();
        $productos_asociados = $result['total'];
        
        if ($productos_asociados > 0) {
            $_SESSION['mensaje'] = '<div class="alert alert-warning">No se puede eliminar. Tiene ' . $productos_asociados . ' producto(s) asociado(s)</div>';
        } else {
            // Eliminar marca
            $sql = "DELETE FROM marcas WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() > 0) {
                $_SESSION['mensaje'] = '<div class="alert alert-success">Marca eliminada exitosamente</div>';
            } else {
                $_SESSION['mensaje'] = '<div class="alert alert-danger">La marca no existe</div>';
            }
        }
    } catch (PDOException $e) {
        $_SESSION['mensaje'] = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    
    header('Location: index.php?accion=listar');
    exit;
}

// Recuperar mensaje de sesión
if (isset($_SESSION['mensaje'])) {
    $mensaje = $_SESSION['mensaje'];
    unset($_SESSION['mensaje']);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Marcas</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            padding-top: 20px;
            padding-bottom: 20px;
        }
        .card {
            border: none;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #eee;
            font-weight: 600;
        }
        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85em;
        }
        .status-active {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-inactive {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .btn-action {
            width: 36px;
            height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .form-label {
            font-weight: 500;
            color: #495057;
        }
        .required:after {
            content: " *";
            color: #dc3545;
        }
        .main-container {
            max-width: 1200px;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Encabezado -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h1 class="h3 mb-0">
                    <i class="fas fa-tags text-primary me-2"></i>Gestión de Marcas
                </h1>
                <p class="text-muted mb-0">Administra las marcas de tus productos</p>
            </div>
            <div>
                <a href="../productos/index.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Volver a Productos
                </a>
            </div>
        </div>

        <!-- Mensajes -->
        <?php if ($mensaje): ?>
            <div class="mb-4"><?php echo $mensaje; ?></div>
        <?php endif; ?>

        <?php if ($accion === 'listar'): ?>
            <!-- Listado de Marcas -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Lista de Marcas</h5>
                    <a href="?accion=crear" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i> Nueva Marca
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th width="60">ID</th>
                                    <th>Nombre</th>
                                    <th>Descripción</th>
                                    <th width="100">Estado</th>
                                    <th width="120">Creado</th>
                                    <th width="100" class="text-center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                try {
                                    $sql = "SELECT m.*, 
                                            (SELECT COUNT(*) FROM productos p WHERE p.marca_id = m.id AND p.activo = 1) as productos_count
                                            FROM marcas m 
                                            ORDER BY m.nombre";
                                    
                                    $stmt = $conn->query($sql);
                                    $marcas = $stmt->fetchAll();
                                    
                                    if (empty($marcas)) { ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-5 text-muted">
                                                <i class="fas fa-inbox fa-2x mb-3 d-block"></i>
                                                No hay marcas registradas
                                            </td>
                                        </tr>
                                    <?php } else { 
                                        foreach ($marcas as $marca) { ?>
                                        <tr>
                                            <td class="text-muted">#<?php echo $marca['id']; ?></td>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($marca['nombre']); ?></div>
                                                <?php if ($marca['productos_count'] > 0): ?>
                                                    <small class="text-muted">
                                                        <i class="fas fa-box me-1"></i><?php echo $marca['productos_count']; ?> producto(s)
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $descripcion = $marca['descripcion'] ?? '';
                                                if (empty($descripcion)) {
                                                    echo '<span class="text-muted fst-italic">Sin descripción</span>';
                                                } else {
                                                    echo htmlspecialchars(mb_strlen($descripcion) > 60 ? mb_substr($descripcion, 0, 60) . '...' : $descripcion);
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $marca['activo'] ? 'status-active' : 'status-inactive'; ?>">
                                                    <?php echo $marca['activo'] ? 'Activo' : 'Inactivo'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                if ($marca['created_at']) {
                                                    echo date('d/m/Y', strtotime($marca['created_at']));
                                                } else {
                                                    echo '-';
                                                }
                                                ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="?accion=editar&id=<?php echo $marca['id']; ?>" 
                                                       class="btn btn-outline-warning btn-action" 
                                                       title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <?php if ($marca['productos_count'] == 0): ?>
                                                        <a href="?accion=listar&eliminar=1&id=<?php echo $marca['id']; ?>" 
                                                           class="btn btn-outline-danger btn-action" 
                                                           title="Eliminar"
                                                           onclick="return confirm('¿Eliminar la marca \'<?php echo addslashes($marca['nombre']); ?>\'?')">
                                                            <i class="fas fa-trash"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <button class="btn btn-outline-secondary btn-action" 
                                                                title="No se puede eliminar (tiene productos)"
                                                                disabled>
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php }
                                    }
                                } catch (PDOException $e) { ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-danger py-4">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            Error al cargar las marcas: <?php echo htmlspecialchars($e->getMessage()); ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer text-muted">
                    <small>
                        <i class="fas fa-info-circle me-1"></i>
                        Total: <?php echo isset($marcas) ? count($marcas) : 0; ?> marca(s)
                    </small>
                </div>
            </div>

        <?php elseif ($accion === 'crear' || $accion === 'editar'): ?>
            <!-- Formulario -->
            <?php
            $marca = ['nombre' => '', 'descripcion' => '', 'activo' => 1];
            $titulo = 'Crear Nueva Marca';
            $boton = 'Crear Marca';
            
            if ($accion === 'editar' && $id > 0) {
                try {
                    $sql = "SELECT * FROM marcas WHERE id = ?";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$id]);
                    $marca = $stmt->fetch();
                    
                    if (!$marca) {
                        echo '<div class="alert alert-danger">Marca no encontrada</div>';
                        echo '<a href="index.php" class="btn btn-secondary mt-3">Volver al listado</a>';
                        exit;
                    }
                    
                    $titulo = 'Editar Marca';
                    $boton = 'Actualizar Marca';
                    
                } catch (PDOException $e) {
                    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
                    echo '<a href="index.php" class="btn btn-secondary mt-3">Volver al listado</a>';
                    exit;
                }
            }
            ?>
            
            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><?php echo $titulo; ?></h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <?php if ($accion === 'editar'): ?>
                                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label for="nombre" class="form-label required">Nombre</label>
                                    <input type="text" class="form-control" id="nombre" name="nombre" 
                                           value="<?php echo htmlspecialchars($marca['nombre']); ?>" 
                                           required maxlength="100" 
                                           placeholder="Ej: Nike, Adidas, Sony">
                                    <div class="form-text">Nombre único de la marca</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="descripcion" class="form-label">Descripción</label>
                                    <textarea class="form-control" id="descripcion" name="descripcion" 
                                              rows="4" 
                                              placeholder="Descripción opcional de la marca"><?php echo htmlspecialchars($marca['descripcion']); ?></textarea>
                                    <div class="form-text">Máximo 500 caracteres</div>
                                </div>
                                
                                <div class="mb-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="activo" name="activo" 
                                               value="1" <?php echo $marca['activo'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="activo">
                                            <strong>Marca Activa</strong>
                                        </label>
                                    </div>
                                    <div class="form-text">
                                        Las marcas inactivas no aparecerán en los listados de productos
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <a href="?" class="btn btn-secondary">
                                        <i class="fas fa-times me-1"></i> Cancelar
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> <?php echo $boton; ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <?php if ($accion === 'editar'): ?>
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0">Información</h6>
                        </div>
                        <div class="card-body">
                            <dl class="mb-0">
                                <dt class="small text-muted">ID:</dt>
                                <dd class="mb-2">#<?php echo $marca['id']; ?></dd>
                                
                                <dt class="small text-muted">Creado:</dt>
                                <dd class="mb-2">
                                    <?php 
                                    if ($marca['created_at']) {
                                        echo date('d/m/Y H:i', strtotime($marca['created_at']));
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </dd>
                                
                                <?php if ($marca['updated_at'] && $marca['updated_at'] != $marca['created_at']): ?>
                                <dt class="small text-muted">Actualizado:</dt>
                                <dd class="mb-2">
                                    <?php echo date('d/m/Y H:i', strtotime($marca['updated_at'])); ?>
                                </dd>
                                <?php endif; ?>
                            </dl>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="card mt-3">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-lightbulb me-1"></i> Nota</h6>
                        </div>
                        <div class="card-body">
                            <p class="small mb-0">
                                Las marcas solo pueden eliminarse si no tienen productos asociados. 
                                Para desactivar una marca con productos, cambia su estado a "Inactivo".
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-focus en el campo nombre
        document.addEventListener('DOMContentLoaded', function() {
            const nombreField = document.getElementById('nombre');
            if (nombreField) {
                nombreField.focus();
            }
            
            // Limitar descripción a 500 caracteres
            const descField = document.getElementById('descripcion');
            if (descField) {
                descField.addEventListener('input', function() {
                    if (this.value.length > 500) {
                        alert('La descripción no puede tener más de 500 caracteres');
                        this.value = this.value.substring(0, 500);
                    }
                });
            }
        });
    </script>
</body>
</html>