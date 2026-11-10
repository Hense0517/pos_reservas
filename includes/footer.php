<?php
// includes/footer.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/recursos.php';
?>
        <footer class="app-footer">
            <i class="fas fa-scissors" aria-hidden="true"></i>
            <?php echo htmlspecialchars($config['nombre_negocio'] ?? 'Mi Negocio', ENT_QUOTES, 'UTF-8'); ?>
            &middot; Sistema de gestión para peluquerías &middot; <?php echo date('Y'); ?>
        </footer>
    </main>
    <?php echo scripts_base(); ?>
</body>
</html>
