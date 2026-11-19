<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventanilla — Inicio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background:#f0f4f8; }
        .card-stat { border:none; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.08); }
        .semaforo-vencida  { background:#ffebee; color:#c62828; border-radius:20px; padding:3px 10px; font-size:.75rem; font-weight:600; }
        .semaforo-critica  { background:#fff3e0; color:#e65100; border-radius:20px; padding:3px 10px; font-size:.75rem; font-weight:600; }
        .semaforo-alerta   { background:#fffde7; color:#f57f17; border-radius:20px; padding:3px 10px; font-size:.75rem; font-weight:600; }
        .semaforo-normal   { background:#e8f5e9; color:#2e7d32; border-radius:20px; padding:3px 10px; font-size:.75rem; font-weight:600; }
        .table th { font-size:.82rem; }
        .table td { font-size:.85rem; vertical-align:middle; }
    </style>
</head>
<body>

<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once INCLUDES_PATH . '/auth.php';

$auth = new Auth();
$auth->requierePerfil('ventanilla', 'administrador');

$db      = Database::getInstance()->getConnection();
$usuario = $auth->getUsuarioActual();

$stmtHoy = $db->prepare("
    SELECT
        s.id_solicitud,
        s.numero_radicado,
        s.fecha_radicacion,
        s.fecha_limite,
        s.estado,
        s.latitud,
        s.longitud,
        DATEDIFF(s.fecha_limite, CURDATE())     AS dias_restantes,
        CASE
            WHEN DATEDIFF(s.fecha_limite, CURDATE()) < 0  THEN 'vencida'
            WHEN DATEDIFF(s.fecha_limite, CURDATE()) <= 3 THEN 'critica'
            WHEN DATEDIFF(s.fecha_limite, CURDATE()) <= 7 THEN 'alerta'
            ELSE 'normal'
        END                                     AS semaforo,
        CONCAT(c.nombres,' ',c.apellidos)        AS ciudadano,
        c.celular,
        c.direccion,
        b.nombre_barrio,
        com.numero_comuna,
        p.nombre_punto                           AS punto_atencion,
        CONCAT(u.nombre,' ',u.apellido)          AS ventanilla_resp
    FROM solicitudes_encuesta s
    INNER JOIN ciudadanos c       ON s.id_ciudadano          = c.id_ciudadano
    INNER JOIN barrios b          ON c.id_barrio             = b.id_barrio
    INNER JOIN comunas com        ON c.id_comuna             = com.id_comuna
    INNER JOIN puntos_atencion p  ON s.id_punto_atencion     = p.id_punto
    INNER JOIN usuarios_sistema u ON s.id_usuario_ventanilla = u.id_usuario
    WHERE DATE(s.fecha_radicacion) = CURDATE()
    ORDER BY s.fecha_radicacion DESC
");
$stmtHoy->execute();
$solicitudesHoy = $stmtHoy->fetchAll();

$stmtContadores = $db->prepare("
    SELECT
        COUNT(*)                                                AS total_hoy,
        SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END)  AS pendientes,
        SUM(CASE WHEN fecha_limite < CURDATE()
             AND estado NOT IN ('apta','no_apta','realizada','revisada')
             THEN 1 ELSE 0 END)                                AS vencidas
    FROM solicitudes_encuesta
    WHERE id_punto_atencion = :id_punto
      AND DATE(fecha_radicacion) = CURDATE()
");
$stmtContadores->execute([':id_punto' => $usuario['id_punto'] ?? 1]);
$contadores = $stmtContadores->fetch();
?>

<?php include INCLUDES_PATH . '/header.php'; ?>

<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0" style="color:#1a3a5c;">
                <i class="bi bi-person-workspace me-2"></i>Módulo de Ventanilla
            </h4>
            <small class="text-muted">
                <?= date('l, d \d\e F \d\e Y') ?> — Atiende: <?= htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']) ?>
            </small>
        </div>
        <a href="<?= BASE_URL ?>/modules/ventanilla/nueva_solicitud.php" class="btn btn-primary shadow-sm">
            <i class="bi bi-plus-circle-fill me-2"></i>Atender / Radicar Solicitud
        </a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4">
            <div class="card card-stat text-center p-3">
                <div class="fs-1 fw-bold text-primary"><?= count($solicitudesHoy) ?></div>
                <div class="text-muted small">Radicadas hoy en la ciudad</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card card-stat text-center p-3">
                <div class="fs-1 fw-bold text-warning"><?= (int)($contadores['pendientes'] ?? 0) ?></div>
                <div class="text-muted small">Pendientes en este punto</div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card card-stat text-center p-3">
                <div class="fs-1 fw-bold text-danger"><?= (int)($contadores['vencidas'] ?? 0) ?></div>
                <div class="text-muted small">Vencidas (Alerta de Demanda)</div>
            </div>
        </div>
    </div>

    <div class="card card-stat">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-bold" style="color:#1a3a5c;">
                <i class="bi bi-journal-text me-2"></i>Solicitudes Ingresadas Hoy
                <span class="badge bg-secondary ms-2"><?= count($solicitudesHoy) ?></span>
            </span>
            <input type="text" class="form-control form-control-sm w-auto" id="filtroTabla" placeholder="🔍 Buscar...">
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle" id="tablaBitacora">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Radicado</th>
                            <th>Ciudadano</th>
                            <th>Dirección / Barrio</th>
                            <th>Comuna</th>
                            <th>Punto Atención</th>
                            <th>Estado</th>
                            <th>Plazo Restante</th>
                            <th>Georreferencia</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($solicitudesHoy)): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">No se han registrado solicitudes el día de hoy.</td></tr>
                    <?php else: ?>
                        <?php foreach ($solicitudesHoy as $i => $s): ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td><code class="text-primary fw-bold"><?= htmlspecialchars($s['numero_radicado']) ?></code></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($s['ciudadano']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($s['celular']) ?></small>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($s['direccion']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($s['nombre_barrio']) ?></small>
                            </td>
                            <td class="text-center"><span class="badge bg-light text-dark border">Comuna <?= $s['numero_comuna'] ?></span></td>
                            <td><small><?= htmlspecialchars($s['punto_atencion']) ?></small></td>
                            <td><span class="badge bg-warning text-dark"><?= ucfirst($s['estado']) ?></span></td>
                            <td>
                                <span class="semaforo-<?= $s['semaforo'] ?>">
                                    <?= $s['dias_restantes'] >= 0 ? $s['dias_restantes'].' días' : 'VENCIDA' ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <?php if ($s['latitud'] && $s['longitud']): ?>
                                    <span class="badge bg-success" title="Lat: <?= $s['latitud'] ?> Lng: <?= $s['longitud'] ?>">
                                        <i class="bi bi-geo-alt-fill me-1"></i>Ubicado
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted small">Sin mapa</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="editar_solicitud.php?id=<?= $s['id_solicitud'] ?>" class="btn btn-sm btn-outline-warning py-0 px-2" title="Editar / Corregir Dirección">
                                    <i class="bi bi-pencil-square me-1"></i>Corregir
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('filtroTabla').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#tablaBitacora tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
</body>
</html>
