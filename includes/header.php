<?php
// ============================================================
// ARCHIVO: includes/header.php
// DESCRIPCIÓN: Navbar con detección de perfil activo
// ============================================================
$usuario = $auth->getUsuarioActual();
$perfil  = $usuario['perfil'];

$menus = [
    'administrador' => [
        ['url' => BASE_URL.'/modules/admin/dashboard.php',      'icon' => 'bi-speedometer2',      'label' => 'Dashboard'],
        ['url' => BASE_URL.'/modules/enrutador/index.php',       'icon' => 'bi-journal-text',       'label' => 'Bitácora'],
        ['url' => BASE_URL.'/modules/enrutador/generar_hoja_ruta.php', 'icon' => 'bi-map-fill',    'label' => 'Hoja de Ruta'],
        ['url' => BASE_URL.'/modules/ventanilla/nueva_solicitud.php',  'icon' => 'bi-plus-circle',  'label' => 'Nueva Solicitud'],
        ['url' => BASE_URL.'/modules/encuestador/hoja_ruta.php',       'icon' => 'bi-clipboard-check', 'label' => 'Seguimiento Hojas'],
        ['url' => BASE_URL.'/modules/calidad/revision.php',            'icon' => 'bi-patch-check-fill', 'label' => 'Calidad'],
        ['url' => BASE_URL.'/tests/test_units.php',             'icon' => 'bi-shield-check',      'label' => 'Pruebas Unitarias'],
    ],
    'ventanilla' => [
        ['url' => BASE_URL.'/modules/ventanilla/index.php',          'icon' => 'bi-house-fill',       'label' => 'Inicio'],
        ['url' => BASE_URL.'/modules/ventanilla/nueva_solicitud.php', 'icon' => 'bi-plus-circle-fill','label' => 'Nueva Solicitud'],
        ['url' => BASE_URL.'/modules/encuestador/hoja_ruta.php',     'icon' => 'bi-clipboard-check',  'label' => 'Entrega Hojas'],
    ],
    'enrutador' => [
        ['url' => BASE_URL.'/modules/enrutador/index.php',              'icon' => 'bi-list-check',       'label' => 'Bitácora General'],
        ['url' => BASE_URL.'/modules/enrutador/generar_hoja_ruta.php',  'icon' => 'bi-map-fill',         'label' => 'Generar Hoja de Ruta'],
    ],
    'encuestador' => [
        ['url' => BASE_URL.'/modules/encuestador/hoja_ruta.php', 'icon' => 'bi-clipboard2-check-fill', 'label' => 'Mi Hoja de Ruta'],
    ],
    'calidad' => [
        ['url' => BASE_URL.'/modules/calidad/revision.php', 'icon' => 'bi-patch-check-fill', 'label' => 'Supervisión de Calidad'],
        ['url' => BASE_URL.'/modules/enrutador/index.php',  'icon' => 'bi-journal-text',     'label' => 'Bitácora'],
    ],
];

$badgeColores = [
    'administrador' => 'bg-danger',
    'ventanilla'    => 'bg-primary',
    'enrutador'     => 'bg-success',
    'encuestador'   => 'bg-warning text-dark',
    'calidad'       => 'bg-info text-dark',
];
?>
<nav class="navbar navbar-expand-lg navbar-dark" style="background:#1a3a5c;">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>">
            <i class="bi bi-clipboard-data-fill me-2 text-info"></i>SistemaEncuestas
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMenu">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php foreach (($menus[$perfil] ?? []) as $item): ?>
                <li class="nav-item">
                    <a class="nav-link <?= (strpos($_SERVER['PHP_SELF'], $item['url']) !== false ? 'active' : '') ?>"
                       href="<?= $item['url'] ?>">
                        <i class="bi <?= $item['icon'] ?> me-1"></i><?= $item['label'] ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>

            <ul class="navbar-nav ms-auto align-items-center gap-2">
                <li class="nav-item">
                    <span class="badge <?= $badgeColores[$perfil] ?? 'bg-secondary' ?> rounded-pill px-3 py-2">
                        <?= ucfirst(htmlspecialchars($perfil)) ?>
                    </span>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i>
                        <?= htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><h6 class="dropdown-header">Usuario: <?= htmlspecialchars($usuario['username']) ?></h6></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="<?= BASE_URL ?>/modules/auth/logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>Cerrar Sesión
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>
