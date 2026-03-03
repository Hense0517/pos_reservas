<?php
// includes/footer.php
// CORREGIDO: Usar __DIR__ para rutas relativas
// ELIMINAR: require_once 'includes/config.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/recursos.php';
?>
        </main>
    </div>
    <?php echo scripts_base(); ?>
</body>
</html>