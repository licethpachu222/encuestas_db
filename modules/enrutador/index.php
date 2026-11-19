<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrutador — Bitácora Diaria</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background:#f0f4f8; }
        .card-panel { border:none; border-radius:14px; box-shadow:0 2px 16px rgba(0,0,0,.08); }
        .semaforo { font-size:.72rem; font-weight:700; border-radius:20px; padding:2px 10px; }
        .s-vencida { background:#ffebee; color:#c62828; }
        .s-critica { background:#fff3e0; color:#e65100; }
        .s-alerta  { background:#fffde7; color:#f57f17; }
        .s-normal  { background:#e8f5e9; color:#2e7d32; }
        .counter-pill { font-size:1.6rem; font-weight:800; }
    </style>
</head>
<body>

<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once INCLUDES_PATH . '/auth.php';

$auth = new Auth();
$auth->requierePerfil('enrutador', 'administrador', 'calidad');

$db      = Database::getInstance()->getConnection();
$usuario = $auth->getUsuarioActual();

$fechaFiltro = $_GET['fecha'] ?? date('Y-m-d');
$filtroEstado = $_GET['estado'] ?? '';

$where = ['DATE(s.fecha_radicacion) = :fecha'];
$params = [':fecha' => $fechaFiltro];

if (!empty($filtroEstado)) {
    $where[] = 's.estado = :estado';
    $params[':estado'] = $filtroEstado;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$stmtBitacora = $db->prepare("
    SELECT
        s.id_solicitud, s.numero_radicado, s.fecha_radicacion, s.fecha_limite,
        s.estado, s.latitud, s.longitud,
        DATEDIFF(s.fecha_limite, CURDATE()) AS dias_restantes,
        CASE
            WHEN DATEDIFF(s.fecha_limite, CURDATE()) < 0  THEN 'vencida'
            WHEN DATEDIFF(s.fecha_limite, CURDATE()) <= 3 THEN 'critica'
            WHEN DATEDIFF(s.fecha_limite, CURDATE()) <= 7 THEN 'alerta'
            ELSE 'normal'
        END AS semaforo,
        CONCAT(c.nombres,' ',c.apellidos) AS ciudadano,
        c.celular, c.direccion,
        b.nombre_barrio, com.numero_comuna, com.tipo AS zona_tipo,
        p.nombre_punto,
        CONCAT(u.nombre,' ',u.apellido) AS ventanilla_resp
    FROM solicitudes_encuesta s
    INNER JOIN ciudadanos c       ON s.id_ciudadano = c.id_ciudadano
    INNER JOIN barrios b          ON c.id_barrio = b.id_barrio
    INNER JOIN comunas com        ON c.id_comuna = com.id_comuna
    INNER JOIN puntos_atencion p  ON s.id_punto_atencion = p.id_punto
    INNER JOIN usuarios_sistema u ON s.id_usuario_ventanilla = u.id_usuario
    $whereSQL
    ORDER BY s.fecha_radicacion DESC
");
$stmtBitacora->execute($params);
$solicitudes = $stmtBitacora->fetchAll();

$stmtPendientes = $db->query("SELECT COUNT(*) FROM solicitudes_encuesta WHERE estado = 'pendiente'");
$totalPendientes = (int)$stmtPendientes->fetchColumn();
?>

<?php include INCLUDES_PATH . '/header.php'; ?>

<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0" style="color:#1a3a5c;">
                <i class="bi bi-journal-text me-2"></i>Requerimiento 4 — Bitácora General de Solicitudes
            </h4>
            <small class="text-muted">Control centralizado de todas las solicitudes radicadas en los puntos de atención</small>
        </div>
        <a href="<?= BASE_URL ?>/modules/enrutador/generar_hoja_ruta.php" class="btn btn-success">
            <i class="bi bi-map-fill me-1"></i>Generar Hoja de Ruta
        </a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card card-panel text-center p-3">
                <div class="counter-pill text-primary"><?= count($solicitudes) ?></div>
                <div class="text-muted small">Solicitudes del día seleccionado</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-panel text-center p-3">
                <div class="counter-pill text-warning"><?= $totalPendientes ?></div>
                <div class="text-muted small">Total acumulado pendientes de ruta</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-panel text-center p-3">
                <div class="counter-pill text-danger">
                    <?php
                    $venc = $db->query("SELECT COUNT(*) FROM solicitudes_encuesta WHERE fecha_limite < CURDATE() AND estado = 'pendiente'")->fetchColumn();
                    echo (int)$venc;
                    ?>
                </div>
                <div class="text-muted small">Vencidas fuera del límite legal (15 días)</div>
            </div>
        </div>
    </div>

    <div class="card card-panel mb-4">
        <div class="card-body py-2 px-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-1">Fecha</label>
                    <input type="date" name="fecha" class="form-control form-control-sm" value="<?= htmlspecialchars($fechaFiltro) ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-1">Estado</label>
                    <select name="estado" class="form-select form-select-sm">
                        <option value="">— Todos —</option>
                        <option value="pendiente" <?= $filtroEstado === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                        <option value="asignada" <?= $filtroEstado === 'asignada' ? 'selected' : '' ?>>Asignada</option>
                        <option value="realizada" <?= $filtroEstado === 'realizada' ? 'selected' : '' ?>>Realizada</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Filtrar</button>
                    <a href="index.php" class="btn btn-sm btn-outline-secondary">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card card-panel">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-bold text-dark">Bitácora Detallada de Ingreso</span>
            <span class="badge bg-secondary"><?= count($solicitudes) ?> registros</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Radicado</th>
                            <th>Fecha/Hora</th>
                            <th>Ciudadano</th>
                            <th>Dirección y Barrio</th>
                            <th>Comuna / Zona</th>
                            <th>Punto Radicación</th>
                            <th>Estado</th>
                            <th>SLA 15 Días</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($solicitudes)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">No se encontraron solicitudes registradas para la fecha seleccionada.</td></tr>
                    <?php else: ?>
                        <?php foreach ($solicitudes as $i => $s): ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td><code class="fw-bold text-primary"><?= htmlspecialchars($s['numero_radicado']) ?></code></td>
                            <td><small><?= date('d/m/Y H:i', strtotime($s['fecha_radicacion'])) ?></small></td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($s['ciudadano']) ?></div>
                                <small class="text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($s['celular']) ?></small>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($s['direccion']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($s['nombre_barrio']) ?></small>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">Comuna <?= $s['numero_comuna'] ?></span>
                                <span class="badge <?= $s['zona_tipo'] === 'rural' ? 'bg-success' : 'bg-primary' ?>"><?= ucfirst($s['zona_tipo']) ?></span>
                            </td>
                            <td><small><?= htmlspecialchars($s['nombre_punto']) ?></small></td>
                            <td><span class="badge bg-warning text-dark"><?= ucfirst($s['estado']) ?></span></td>
                            <td>
                                <span class="semaforo s-<?= $s['semaforo'] ?>">
                                    <?= $s['dias_restantes'] >= 0 ? $s['dias_restantes'].' días' : 'VENCIDA' ?>
                                </span>
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
</body>
</html>
