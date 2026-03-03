<?php
require_once 'config/database.php';
require_once 'config/auth.php';

$database = Database::getInstance();
$auth = new Auth($database);
$auth->logout();
?>