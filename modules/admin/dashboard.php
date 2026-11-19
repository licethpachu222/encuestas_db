<?php
// ============================================================
// ARCHIVO: modules/admin/dashboard.php
// DESCRIPCIÓN: Panel del Administrador — indicadores, gráficas y auditoría
// ============================================================
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/helpers.php';

$auth = new Auth();
$auth->requierePerfil('administrador');

$db      = Database::getInstance()->getConnection();
$usuario = $auth->getUsuarioActual();

// ---- Indicadores generales ----
$kpi = $db->query("
    SELECT
        COUNT(*)                                                          AS total,
        SUM(estado = 'pendiente')                                         AS pendientes,
        SUM(estado IN ('asignada','en_proceso'))                          AS en_campo,
        SUM(estado = 'realizada')                                         AS por_revisar,
        SUM(estado = 'apta')                                              AS aptas,
        SUM(estado = 'no_apta')                                           AS no_aptas,
        SUM(fecha_limite < CURDATE() AND estado IN ('pendiente','asignada','en_proceso')) AS vencidas,
        SUM(DATE(fecha_radicacion) = CURDATE())                           AS hoy
    FROM solicitudes_encuesta
")->fetch();
$kpi = array_map(fn($v) => (int)$v, $kpi ?: []);

// ---- Solicitudes por estado (gráfica dona) ----
$porEstado = $db->query("
    SELECT estado, COUNT(*) AS total
    FROM solicitudes_encuesta
    GROUP BY estado
    ORDER BY FIELD(estado,'pendiente','asignada','en_proceso','realizada','revisada','apta','no_apta')
")->fetchAll();

// ---- Solicitudes por comuna (gráfica barras) ----
$porComuna = $db->query("
    SELECT com.numero_comuna, com.nombre_comuna, com.tipo, COUNT(s.id_solicitud) AS total
    FROM solicitudes_encuesta s
    INNER JOIN ciudadanos c ON s.id_ciudadano = c.id_ciudadano
    INNER JOIN comunas com  ON c.id_comuna    = com.id_comuna
    GROUP BY com.id_comuna, com.numero_comuna, com.nombre_comuna, com.tipo
    ORDER BY com.numero_comuna
")->fetchAll();

// ---- Radicaciones últimos 15 días (gráfica línea) ----
$stmtDias = $db->query("
    SELECT DATE(fecha_radicacion) AS dia, COUNT(*) AS total
    FROM solicitudes_encuesta
    WHERE fecha_radicacion >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY DATE(fecha_radicacion)
");
$mapDias = [];
foreach ($stmtDias->fetchAll() as $r) { $mapDias[$r['dia']] = (int)$r['total']; }
$labelsDias = []; $valoresDias = [];
for ($i = 14; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $labelsDias[]  = date('d/m', strtotime($d));
    $valoresDias[] = $mapDias[$d] ?? 0;
}

// ---- Productividad por encuestador ----
$encuestadores = $db->query("
    SELECT CONCAT(u.nombre,' ',u.apellido) AS encuestador,
           COUNT(DISTINCT h.id_hoja_ruta)          AS hojas,
           COUNT(d.id_detalle)                     AS asignadas,
           SUM(d.resultado = 'realizada')          AS realizadas,
           SUM(d.resultado = 'ausente')            AS ausentes
    FROM usuarios_sistema u
    INNER JOIN perfiles p ON u.id_perfil = p.id_perfil AND p.nombre_perfil = 'encuestador'
    LEFT JOIN hojas_ruta h        ON h.id_encuestador = u.id_usuario
    LEFT JOIN detalle_hoja_ruta d ON d.id_hoja_ruta   = h.id_hoja_ruta
    GROUP BY u.id_usuario, u.nombre, u.apellido
    ORDER BY realizadas DESC
")->fetchAll();

// ---- Calidad ----
$calidad = $db->query("
    SELECT SUM(resultado = 'apto') AS aptos, SUM(resultado = 'no_apto') AS no_aptos, COUNT(*) AS total
    FROM revisiones_calidad
")->fetch();
$tasaAprobacion = ($calidad && (int)$calidad['total'] > 0)
    ? round(((int)$calidad['aptos'] / (int)$calidad['total']) * 100, 1)
    : null;

// ---- Usuarios del sistema ----
$usuarios = $db->query("
    SELECT u.username, CONCAT(u.nombre,' ',u.apellido) AS nombre, p.nombre_perfil, u.activo, u.ultimo_login
    FROM usuarios_sistema u
    INNER JOIN perfiles p ON u.id_perfil = p.id_perfil
    ORDER BY p.id_perfil, u.nombre
")->fetchAll();

// ---- Auditoría reciente ----
$auditoria = $db->query("
    SELECT a.created_at, a.accion, a.tabla_afectada, a.registro_id, a.ip_address,
           COALESCE(u.username, '—') AS username
    FROM auditoria_log a
    LEFT JOIN usuarios_sistema u ON a.id_usuario = u.id_usuario
    ORDER BY a.id_log DESC
    LIMIT 12
")->fetchAll();

$coloresEstado = [
    'pendiente' => '#f9a825', 'asignada' => '#29b6f6', 'en_proceso' => '#1e88e5',
    'realizada' => '#78909c', 'revisada' => '#37474f', 'apta' => '#43a047', 'no_apta' => '#e53935',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrador — Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background:#f0f4f8; }
        .card-panel { border:none; border-radius:14px; box-shadow:0 2px 16px rgba(0,0,0,.08); }
        .kpi { font-size:1.9rem; font-weight:800; line-height:1.1; }
        .kpi-label { font-size:.78rem; color:#6c757d; }
        .kpi-icon { font-size:1.6rem; opacity:.25; position:absolute; right:14px; top:12px; }
        .table td, .table th { font-size:.84rem; vertical-align:middle; }
        .chart-box { position:relative; height:260px; }
    </style>
</head>
<body>
<?php include INCLUDES_PATH . '/header.php'; ?>

<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0" style="color:#1a3a5c;">
                <i class="bi bi-speedometer2 me-2"></i>Dashboard del Administrador
            </h4>
            <small class="text-muted">Visión general del proceso: radicación → ruteo → campo → calidad</small>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>/modules/enrutador/generar_hoja_ruta.php" class="btn btn-success btn-sm"><i class="bi bi-map-fill me-1"></i>Hojas de ruta</a>
            <a href="<?= BASE_URL ?>/modules/calidad/revision.php" class="btn btn-info btn-sm"><i class="bi bi-patch-check-fill me-1"></i>Calidad</a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>
        </div>
    </div>

    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <?php
        $tarjetas = [
            ['Total solicitudes',        $kpi['total'] ?? 0,       'text-primary',   'bi-collection'],
            ['Radicadas hoy',            $kpi['hoy'] ?? 0,         'text-primary',   'bi-calendar-day'],
            ['Pendientes de ruta',       $kpi['pendientes'] ?? 0,  'text-warning',   'bi-hourglass-split'],
            ['En campo',                 $kpi['en_campo'] ?? 0,    'text-info',      'bi-geo-alt'],
            ['Por revisar (calidad)',    $kpi['por_revisar'] ?? 0, 'text-secondary', 'bi-search'],
            ['Aptas',                    $kpi['aptas'] ?? 0,       'text-success',   'bi-check2-circle'],
            ['No aptas',                 $kpi['no_aptas'] ?? 0,    'text-danger',    'bi-x-circle'],
            ['Vencidas (> 15 días)',     $kpi['vencidas'] ?? 0,    'text-danger',    'bi-exclamation-triangle'],
        ];
        foreach ($tarjetas as [$label, $valor, $color, $icono]): ?>
        <div class="col-6 col-md-3">
            <div class="card card-panel p-3 position-relative">
                <i class="bi <?= $icono ?> kpi-icon <?= $color ?>"></i>
                <div class="kpi <?= $color ?>"><?= (int)$valor ?></div>
                <div class="kpi-label"><?= $label ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Gráficas -->
    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card card-panel h-100">
                <div class="card-header bg-white fw-bold">Solicitudes por estado</div>
                <div class="card-body"><div class="chart-box"><canvas id="chEstado"></canvas></div></div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card card-panel h-100">
                <div class="card-header bg-white fw-bold">Radicaciones de los últimos 15 días</div>
                <div class="card-body"><div class="chart-box"><canvas id="chDias"></canvas></div></div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card card-panel h-100">
                <div class="card-header bg-white fw-bold">Demanda por comuna</div>
                <div class="card-body"><div class="chart-box"><canvas id="chComuna"></canvas></div></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card card-panel h-100">
                <div class="card-header bg-white fw-bold">Control de calidad</div>
                <div class="card-body d-flex flex-column justify-content-center text-center">
                    <?php if ($tasaAprobacion === null): ?>
                        <p class="text-muted mb-0">Aún no hay encuestas revisadas.</p>
                    <?php else: ?>
                        <div class="display-5 fw-bold text-success"><?= $tasaAprobacion ?>%</div>
                        <div class="text-muted small mb-3">Tasa de aprobación (Apto)</div>
                        <div class="progress" style="height:12px;">
                            <div class="progress-bar bg-success" style="width:<?= $tasaAprobacion ?>%"></div>
                            <div class="progress-bar bg-danger" style="width:<?= 100 - $tasaAprobacion ?>%"></div>
                        </div>
                        <div class="d-flex justify-content-between small mt-2">
                            <span class="text-success"><?= (int)$calidad['aptos'] ?> aptas</span>
                            <span class="text-danger"><?= (int)$calidad['no_aptos'] ?> no aptas</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Encuestadores -->
        <div class="col-lg-6">
            <div class="card card-panel h-100">
                <div class="card-header bg-white fw-bold"><i class="bi bi-people me-1"></i>Productividad de encuestadores</div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light"><tr><th>Encuestador</th><th class="text-center">Hojas</th><th class="text-center">Asignadas</th><th class="text-center">Realizadas</th><th class="text-center">Ausentes</th></tr></thead>
                        <tbody>
                        <?php if (empty($encuestadores)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">Sin encuestadores registrados.</td></tr>
                        <?php else: foreach ($encuestadores as $e): ?>
                            <tr>
                                <td><?= htmlspecialchars($e['encuestador']) ?></td>
                                <td class="text-center"><?= (int)$e['hojas'] ?></td>
                                <td class="text-center"><?= (int)$e['asignadas'] ?></td>
                                <td class="text-center text-success fw-bold"><?= (int)$e['realizadas'] ?></td>
                                <td class="text-center text-danger"><?= (int)$e['ausentes'] ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Usuarios -->
        <div class="col-lg-6">
            <div class="card card-panel h-100">
                <div class="card-header bg-white fw-bold"><i class="bi bi-person-badge me-1"></i>Usuarios del sistema</div>
                <div class="card-body p-0">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light"><tr><th>Usuario</th><th>Nombre</th><th>Perfil</th><th>Estado</th><th>Último ingreso</th></tr></thead>
                        <tbody>
                        <?php foreach ($usuarios as $u): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                                <td><?= htmlspecialchars($u['nombre']) ?></td>
                                <td><?= htmlspecialchars(ucfirst($u['nombre_perfil'])) ?></td>
                                <td><?= $u['activo'] ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
                                <td><small><?= $u['ultimo_login'] ? date('d/m/Y H:i', strtotime($u['ultimo_login'])) : '—' ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Auditoría -->
        <div class="col-12">
            <div class="card card-panel">
                <div class="card-header bg-white fw-bold"><i class="bi bi-shield-lock me-1"></i>Bitácora de auditoría (últimos 12 eventos)</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light"><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Tabla</th><th>Registro</th><th>IP</th></tr></thead>
                            <tbody>
                            <?php if (empty($auditoria)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">Sin eventos registrados.</td></tr>
                            <?php else: foreach ($auditoria as $a): ?>
                                <tr>
                                    <td><small><?= date('d/m/Y H:i:s', strtotime($a['created_at'])) ?></small></td>
                                    <td><?= htmlspecialchars($a['username']) ?></td>
                                    <td><span class="badge <?= str_contains($a['accion'], 'FALLIDO') ? 'bg-danger' : 'bg-light text-dark border' ?>"><?= htmlspecialchars($a['accion']) ?></span></td>
                                    <td><small><?= htmlspecialchars($a['tabla_afectada']) ?></small></td>
                                    <td><?= $a['registro_id'] !== null ? (int)$a['registro_id'] : '—' ?></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($a['ip_address'] ?? '') ?></small></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const coloresEstado = <?= json_encode($coloresEstado) ?>;
const porEstado = <?= json_encode($porEstado) ?>;
const porComuna = <?= json_encode($porComuna) ?>;

new Chart(document.getElementById('chEstado'), {
    type: 'doughnut',
    data: {
        labels: porEstado.map(r => r.estado.replace('_', ' ')),
        datasets: [{ data: porEstado.map(r => +r.total), backgroundColor: porEstado.map(r => coloresEstado[r.estado] || '#999'), borderWidth: 2 }]
    },
    options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
});

new Chart(document.getElementById('chDias'), {
    type: 'line',
    data: {
        labels: <?= json_encode($labelsDias) ?>,
        datasets: [{ label: 'Solicitudes radicadas', data: <?= json_encode($valoresDias) ?>, borderColor: '#1a3a5c', backgroundColor: 'rgba(26,58,92,.08)', fill: true, tension: .3, pointRadius: 3 }]
    },
    options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('chComuna'), {
    type: 'bar',
    data: {
        labels: porComuna.map(r => 'C' + r.numero_comuna + ' ' + r.nombre_comuna),
        datasets: [{ label: 'Solicitudes', data: porComuna.map(r => +r.total), backgroundColor: porComuna.map(r => r.tipo === 'rural' ? '#43a047' : '#1e88e5'), borderRadius: 6 }]
    },
    options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
</script>
</body>
</html>
