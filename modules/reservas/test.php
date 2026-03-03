<?php
// modules/reservas/test.php
require_once __DIR__ . '/../../includes/config.php';
header('Content-Type: application/json');

echo json_encode(['success' => true, 'message' => 'Funciona']);