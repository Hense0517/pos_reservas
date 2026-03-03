<?php
// Archivo: crear_estructura.php

$base = "sistema_pos";

// Estructura de carpetas
$folders = [
    "$base/config",
    "$base/modules",
    "$base/modules/configuracion",
    "$base/modules/usuarios",
    "$base/modules/ventas",
    "$base/modules/compras",
    "$base/modules/gastos",
    "$base/modules/clientes",
    "$base/modules/proveedores",
    "$base/modules/inventario",
    "$base/assets",
    "$base/assets/css",
    "$base/assets/js",
    "$base/assets/images",
    "$base/includes"
];

// Archivos a crear
$files = [
    "$base/config/database.php" => "<?php\n// Configuración de la base de datos\n\$host='localhost';\n\$user='root';\n\$pass='';\n\$db='sistema_pos';\n?>",

    "$base/includes/header.php" => "<!-- HEADER -->\n<header>\n    <h1>Sistema POS</h1>\n</header>",

    "$base/includes/sidebar.php" => "<!-- SIDEBAR -->\n<aside>\n    <ul>\n        <li>Dashboard</li>\n        <li>Ventas</li>\n        <li>Compras</li>\n        <li>Inventario</li>\n    </ul>\n</aside>",

    "$base/includes/footer.php" => "<!-- FOOTER -->\n<footer>\n    <p>© " . date('Y') . " Sistema POS</p>\n</footer>",

    "$base/index.php" => "<?php\ninclude 'includes/header.php';\ninclude 'includes/sidebar.php';\n?>\n<h2>Bienvenido al Sistema POS</h2>\n<?php include 'includes/footer.php'; ?>"
];

// Crear carpetas
foreach ($folders as $folder) {
    if (!file_exists($folder)) {
        mkdir($folder, 0777, true);
        echo "Carpeta creada: $folder<br>";
    } else {
        echo "Carpeta ya existe: $folder<br>";
    }
}

// Crear archivos
foreach ($files as $file => $content) {
    if (!file_exists($file)) {
        file_put_contents($file, $content);
        echo "Archivo creado: $file<br>";
    } else {
        echo "Archivo ya existe: $file<br>";
    }
}

echo "<br><strong>Estructura creada correctamente.</strong>";
?>
