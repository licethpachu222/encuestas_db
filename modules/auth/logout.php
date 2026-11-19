<?php
// ============================================================
// ARCHIVO: modules/auth/logout.php
// DESCRIPCIÓN: Cierre seguro de sesión
// ============================================================
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once INCLUDES_PATH . '/auth.php';

$auth = new Auth();
$auth->logout();
header('Location: ' . BASE_URL . '/modules/auth/login.php?msg=logout_ok');
exit;
