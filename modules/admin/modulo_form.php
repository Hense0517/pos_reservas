<!DOCTYPE html>
<html>
<head>
    <title>Gestión de Módulos</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container mx-auto p-6">
        <h1 class="text-2xl font-bold mb-6"><?php
// Auto-fixed: 2026-02-17 01:57:21
require_once '../../../includes/config.php'; echo isset($modulo) ? 'Editar Módulo' : 'Agregar Nuevo Módulo'; ?></h1>
        
        <form method="POST" class="max-w-2xl">
            <div class="space-y-4">
                <div>
                    <label class="block mb-1">Nombre (clave única)</label>
                    <input type="text" name="nombre" value="<?php echo $modulo['nombre'] ?? ''; ?>" 
                           class="w-full border rounded p-2" required>
                </div>
                
                <div>
                    <label class="block mb-1">Descripción</label>
                    <input type="text" name="descripcion" value="<?php echo $modulo['descripcion'] ?? ''; ?>" 
                           class="w-full border rounded p-2" required>
                </div>
                
                <div>
                    <label class="block mb-1">Icono (FontAwesome)</label>
                    <input type="text" name="icono" value="<?php echo $modulo['icono'] ?? 'fas fa-folder'; ?>" 
                           class="w-full border rounded p-2" placeholder="fas fa-folder">
                    <p class="text-sm text-gray-600 mt-1">Ej: fas fa-users, fas fa-box, fas fa-chart-bar</p>
                </div>
                
                <div>
                    <label class="block mb-1">Grupo</label>
                    <select name="grupo" class="w-full border rounded p-2">
                        <option value="principal" <?php echo ($modulo['grupo'] ?? '') == 'principal' ? 'selected' : ''; ?>>Principal</option>
                        <option value="ventas" <?php echo ($modulo['grupo'] ?? '') == 'ventas' ? 'selected' : ''; ?>>Ventas</option>
                        <option value="inventario" <?php echo ($modulo['grupo'] ?? '') == 'inventario' ? 'selected' : ''; ?>>Inventario</option>
                        <option value="compras" <?php echo ($modulo['grupo'] ?? '') == 'compras' ? 'selected' : ''; ?>>Compras</option>
                        <option value="finanzas" <?php echo ($modulo['grupo'] ?? '') == 'finanzas' ? 'selected' : ''; ?>>Finanzas</option>
                        <option value="reportes" <?php echo ($modulo['grupo'] ?? '') == 'reportes' ? 'selected' : ''; ?>>Reportes</option>
                        <option value="configuracion" <?php echo ($modulo['grupo'] ?? '') == 'configuracion' ? 'selected' : ''; ?>>Configuración</option>
                    </select>
                </div>
                
                <div>
                    <label class="block mb-1">Ruta</label>
                    <input type="text" name="ruta" value="<?php echo $modulo['ruta'] ?? ''; ?>" 
                           class="w-full border rounded p-2" placeholder="modulo/index.php">
                </div>
                
                <div>
                    <label class="block mb-1">Orden</label>
                    <input type="number" name="orden" value="<?php echo $modulo['orden'] ?? 99; ?>" 
                           class="w-full border rounded p-2">
                </div>
                
                <?php if (isset($modulo)): ?>
                <div>
                    <label class="inline-flex items-center">
                        <input type="checkbox" name="activo" value="1" 
                               <?php echo ($modulo['activo'] ?? 0) ? 'checked' : ''; ?> class="mr-2">
                        <span>Activo</span>
                    </label>
                </div>
                <?php endif; ?>
                
                <div class="pt-4">
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Guardar</button>
                    <a href="modulos.php" class="ml-2 text-gray-600">Cancelar</a>
                </div>
            </div>
        </form>
    </div>
</body>
</html>