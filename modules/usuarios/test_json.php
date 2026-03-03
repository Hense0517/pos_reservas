<?php
// Auto-fixed: 2026-02-17 01:57:21
require_once '../../../includes/config.php';
// modules/usuarios/test_json.php
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'Test JSON funcionando',
    'timestamp' => date('Y-m-d H:i:s')
]);
?>