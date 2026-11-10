<?php 
// modules/configuracion/index.php

session_start();
require_once '../../config/database.php';

// Verificar si el usuario está autenticado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Obtener la configuración actual
$config = [];
try {
    $database = Database::getInstance();
    $conn = $database->getConnection();
    
    if ($conn) {
        $stmt = $conn->query("SELECT * FROM configuracion_negocio LIMIT 1");
        $config = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } else {
        $error = "Error de conexión a la base de datos";
    }
} catch (Exception $e) {
    $error = "Error al cargar la configuración: " . $e->getMessage();
}

include '../../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <!-- Mostrar mensajes de éxito/error -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Configuración del Negocio</h2>
            <p class="text-sm text-gray-600">Actualiza la información de tu negocio</p>
        </div>
        
        <form action="guardar_configuracion.php" method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="nombre_negocio" class="block text-sm font-medium text-gray-700">Nombre del Negocio *</label>
                    <input type="text" id="nombre_negocio" name="nombre_negocio" 
                           value="<?php echo htmlspecialchars($config['nombre_negocio'] ?? ''); ?>"
                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                           required>
                </div>
                
                <div>
                    <label for="ruc" class="block text-sm font-medium text-gray-700">RUC</label>
                    <input type="text" id="ruc" name="ruc" 
                           value="<?php echo htmlspecialchars($config['ruc'] ?? ''); ?>"
                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            
            <div>
                <label for="direccion" class="block text-sm font-medium text-gray-700">Dirección</label>
                <textarea id="direccion" name="direccion" rows="3"
                          class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($config['direccion'] ?? ''); ?></textarea>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="telefono" class="block text-sm font-medium text-gray-700">Teléfono</label>
                    <input type="text" id="telefono" name="telefono" 
                           value="<?php echo htmlspecialchars($config['telefono'] ?? ''); ?>"
                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                    <input type="email" id="email" name="email" 
                           value="<?php echo htmlspecialchars($config['email'] ?? ''); ?>"
                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="moneda" class="block text-sm font-medium text-gray-700">Moneda</label>
                    <select id="moneda" name="moneda" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="USD" <?php echo ($config['moneda'] ?? 'USD') == 'USD' ? 'selected' : ''; ?>>Dólares (USD)</option>
                        <option value="EUR" <?php echo ($config['moneda'] ?? 'USD') == 'EUR' ? 'selected' : ''; ?>>Euros (EUR)</option>
                        <option value="MXN" <?php echo ($config['moneda'] ?? 'USD') == 'MXN' ? 'selected' : ''; ?>>Pesos Mexicanos (MXN)</option>
                        <option value="COP" <?php echo ($config['moneda'] ?? 'USD') == 'COP' ? 'selected' : ''; ?>>Pesos Colombianos (COP)</option>
                    </select>
                </div>
                
                <div>
                    <label for="impuesto" class="block text-sm font-medium text-gray-700">Impuesto (%)</label>
                    <input type="number" id="impuesto" name="impuesto" step="0.01" min="0" max="100"
                           value="<?php echo htmlspecialchars($config['impuesto'] ?? 0); ?>"
                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            
            <!-- ===================== PALETA DE COLORES ===================== -->
            <?php
            $es_admin_cfg   = (($_SESSION['usuario_rol'] ?? '') === 'admin');
            $paleta_activa  = tema_paleta_id();
            $columna_lista  = isset($conn) && $conn instanceof PDO && tema_columna_existe($conn);
            ?>
            <fieldset class="border border-gray-200 rounded-xl p-5" id="paletaColores" <?php echo $es_admin_cfg ? '' : 'disabled'; ?>>
                <legend class="px-2 text-sm font-semibold text-gray-800">
                    <i class="fas fa-palette mr-1 text-blue-600" aria-hidden="true"></i> Paleta de colores del sistema
                </legend>
                <p class="text-sm text-gray-600 mb-4">
                    Elige los colores de todo el sistema (menú, botones, enlaces, reportes y login).
                    Al tocar una paleta la ves al instante; se guarda al pulsar <strong>Guardar Cambios</strong>.
                </p>

                <?php if (!$es_admin_cfg): ?>
                    <p class="mb-3 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                        <i class="fas fa-lock mr-1" aria-hidden="true"></i> Solo un administrador puede cambiar la paleta.
                    </p>
                <?php elseif (!$columna_lista): ?>
                    <div class="mb-4 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-3">
                        <p class="font-semibold mb-1"><i class="fas fa-triangle-exclamation mr-1" aria-hidden="true"></i> Falta un paso en la base de datos</p>
                        <p>Para que la paleta se guarde para todos los usuarios, ejecuta una sola vez este SQL (phpMyAdmin &rarr; SQL).
                           Mientras tanto verás la vista previa, pero al recargar volverá la paleta por defecto.</p>
                        <pre class="mt-2 p-2 rounded bg-white border border-amber-200 text-xs overflow-x-auto select-all">ALTER TABLE configuracion_negocio
    ADD COLUMN paleta_colores VARCHAR(30) NOT NULL DEFAULT 'rosa_glamour';</pre>
                        <p class="mt-1 text-xs">El mismo script está en <code>migrations/2026-11-paleta-colores.sql</code>.</p>
                    </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3" role="radiogroup" aria-label="Paletas de colores">
                    <?php foreach (tema_paletas() as $pid => $pal):
                        $esc = tema_escala($pal['primario']);
                        $vars_json = htmlspecialchars(json_encode(tema_variables($pid), JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
                    ?>
                    <label class="relative block cursor-pointer">
                        <input type="radio" name="paleta_colores" value="<?php echo htmlspecialchars($pid, ENT_QUOTES, 'UTF-8'); ?>"
                               class="peer sr-only paleta-radio" data-vars="<?php echo $vars_json; ?>"
                               <?php echo $pid === $paleta_activa ? 'checked' : ''; ?>>
                        <span class="block h-full rounded-xl border-2 border-gray-200 bg-white p-3 transition peer-checked:border-blue-600 peer-checked:ring-4 peer-checked:ring-blue-100 peer-focus-visible:ring-4 peer-focus-visible:ring-blue-300 hover:border-gray-300">
                            <span class="flex h-10 overflow-hidden rounded-lg shadow-inner" aria-hidden="true">
                                <span class="flex-1" style="background:<?php echo $esc[300]; ?>"></span>
                                <span class="flex-1" style="background:<?php echo $pal['primario']; ?>"></span>
                                <span class="flex-1" style="background:<?php echo $esc[800]; ?>"></span>
                                <span class="flex-1" style="background:<?php echo $pal['acento']; ?>"></span>
                                <span class="flex-1" style="background:<?php echo $pal['oscuro']; ?>"></span>
                            </span>
                            <span class="mt-2 flex items-center justify-between">
                                <span class="text-sm font-semibold text-gray-800"><?php echo htmlspecialchars($pal['nombre'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <i class="fas fa-circle-check check text-blue-600" aria-hidden="true"></i>
                            </span>
                            <span class="block text-xs text-gray-500 mt-0.5"><?php echo htmlspecialchars($pal['descripcion'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <!-- Vista previa: estas clases usan los colores de la paleta elegida -->
                <div class="mt-4 flex flex-wrap items-center gap-3 rounded-lg bg-gray-50 border border-gray-200 px-4 py-3">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-500">Vista previa</span>
                    <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">Botón principal</button>
                    <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium border border-blue-600 text-blue-700 hover:bg-blue-50">Secundario</button>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">Etiqueta</span>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-700">Acento</span>
                    <a href="#paletaColores" class="text-sm font-medium text-blue-600 hover:text-blue-800 underline">Enlace</a>
                </div>
            </fieldset>

            <style>
            #paletaColores .check { opacity: 0; transition: opacity .2s; }
            #paletaColores .paleta-radio:checked + span .check { opacity: 1; }
            </style>
            <script>
            // Vista previa en vivo: aplica las variables de la paleta elegida a toda la página
            (function () {
                var radios = document.querySelectorAll('.paleta-radio');
                var raiz = document.documentElement;
                function aplicar(radio) {
                    try {
                        var vars = JSON.parse(radio.getAttribute('data-vars'));
                        Object.keys(vars).forEach(function (nombre) { raiz.style.setProperty(nombre, vars[nombre]); });
                    } catch (e) { /* JSON inválido: no se cambia nada */ }
                }
                radios.forEach(function (radio) {
                    radio.addEventListener('change', function () { if (radio.checked) { aplicar(radio); } });
                });
            })();
            </script>

            <div>
                <label for="logo" class="block text-sm font-medium text-gray-700">Logo del Negocio</label>
                <input type="file" id="logo" name="logo" 
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                       accept="image/jpeg,image/png,image/gif,image/webp">
                <p class="mt-1 text-sm text-gray-500">Formatos permitidos: JPEG, PNG, GIF, WebP. Tamaño máximo: 2MB</p>
                
                <?php 
                // MOSTRAR LOGO CON URL DIRECTA - Usando la URL absoluta corregida
                if(isset($config['logo']) && !empty($config['logo'])):
                    $logo_system_path = '../../' . $config['logo'];
                    
                    // URL absoluta corregida
                    $logo_display_url = 'https://www.valentinarojastienda.com.co/pos/' . $config['logo'];
                    
                    if(file_exists($logo_system_path)): 
                ?>
                    <div class="mt-2">
                        <p class="text-sm text-gray-500">Logo actual:</p>
                        <img src="<?php echo $logo_display_url; ?>" 
                             alt="Logo <?php echo htmlspecialchars($config['nombre_negocio'] ?? ''); ?>" 
                             class="h-20 w-20 mt-1 rounded border object-cover bg-gray-100"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                        <div class="text-sm text-red-600 mt-1" style="display: none;">
                            ❌ Error al cargar la imagen. 
                            <br>URL: <?php echo $logo_display_url; ?>
                            <br>Verifica que el archivo .htaccess permita el acceso a imágenes.
                        </div>
                        <p class="text-xs text-gray-500 mt-1">
                            ✅ Archivo encontrado: <?php echo htmlspecialchars($config['logo']); ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="mt-2">
                        <p class="text-sm text-yellow-600">
                            ❌ El archivo del logo no se encuentra en el servidor.
                        </p>
                        <p class="text-xs text-gray-500">
                            Ruta en servidor: <?php echo htmlspecialchars($logo_system_path); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <div class="flex justify-end space-x-3 pt-6">
                <button type="button" onclick="window.history.back()" 
                        class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Cancelar
                </button>
                <button type="submit" 
                        class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>