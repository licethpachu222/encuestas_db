<?php
// ============================================================
// ARCHIVO: index.php — Punto de entrada del sistema
// ============================================================
require_once __DIR__ . '/config/config.php';
require_once INCLUDES_PATH . '/auth.php';

$auth = new Auth();

if ($auth->estaAutenticado()) {
    $auth->redirigirPorPerfil();
} else {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit;
}
