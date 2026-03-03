<?php
// Auto-fixed: 2026-02-17 01:57:21
require_once '../../../includes/config.php';
session_start();
unset($_SESSION['productos_etiquetas']);
header('Location: index.php');
exit();
?>