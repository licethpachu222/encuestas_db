<?php
// ============================================================
// ARCHIVO: modules/calidad/revision.php
// DESCRIPCIÓN: Auditoría de calidad — dictamen Apto / No Apto
// ============================================================
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/helpers.php';

$auth = new Auth();
$auth->requierePerfil('calidad', 'administrador');

$db      = Database::getInstance()->getConnection();
$usuario = $auth->getUsuarioActual();

// ============================================================
// REGISTRAR DICTAMEN (transacción)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$auth->verificarCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Token de seguridad inválido. Recargue la página.');
        }
        $idSolicitud   = (int)($_POST['id_solicitud'] ?? 0);
        $resultado     = $_POST['resultado'] ?? '';
        $observaciones = trim($_POST['observaciones'] ?? '');

        if (!in_array($resultado, ['apto', 'no_apto'], true)) {
            throw new RuntimeException('Seleccione un dictamen válido (Apto / No Apto).');
        }
        if ($resultado === 'no_apto' && $observaciones === '') {
            throw new RuntimeException('Para un dictamen "No Apto" debe indicar el motivo en observaciones.');
        }

        $db->beginTransaction();

        // Bloquea la fila para evitar que dos revisores dictaminen la misma encuesta
        $chk = $db->prepare("SELECT estado, numero_radicado FROM solicitudes_encuesta WHERE id_solicitud = :id FOR UPDATE");
        $chk->execute([':id' => $idSolicitud]);
        $sol = $chk->fetch();
        if (!$sol) throw new RuntimeException('Solicitud no encontrada.');
        if ($sol['estado'] !== 'realizada') throw new RuntimeException('La solicitud ' . $sol['numero_radicado'] . ' ya fue revisada o no está lista para calidad.');

        $db->prepare("INSERT INTO revisiones_calidad (id_solicitud, id_revisor, resultado, observaciones)
                      VALUES (:s, :r, :res, :obs)")
           ->execute([':s' => $idSolicitud, ':r' => $usuario['id'], ':res' => $resultado, ':obs' => $observaciones ?: null]);

        $nuevoEstado = $resultado === 'apto' ? 'apta' : 'no_apta';
        $db->prepare("UPDATE solicitudes_encuesta SET estado = :e WHERE id_solicitud = :s")
           ->execute([':e' => $nuevoEstado, ':s' => $idSolicitud]);

        $db->commit();

        $auth->registrarAuditoria($usuario['id'], 'DICTAMEN_CALIDAD', 'revisiones_calidad', $idSolicitud,
            ['estado' => 'realizada'], ['estado' => $nuevoEstado, 'observaciones' => $observaciones]);
        setFlash('success', 'Encuesta ' . $sol['numero_radicado'] . ' dictaminada como ' . ($resultado === 'apto' ? 'APTA' : 'NO APTA') . '.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('danger', $e->getMessage());
    }
    header('Location: revision.php');
    exit;
}

// ============================================================
// CONSULTAS
// ============================================================
$porRevisar = $db->query("
    SELECT s.id_solicitud, s.numero_radicado, s.fecha_radicacion, s.fecha_limite, s.observaciones,
           DATEDIFF(s.fecha_limite, CURDATE()) AS dias_restantes,
           CONCAT(c.nombres,' ',c.apellidos) AS ciudadano, c.tipo_documento, c.numero_documento,
           c.celular, c.direccion, b.nombre_barrio, com.numero_comuna,
           d.fecha_visita, d.notas_encuestador, d.hora_confirmacion_llamada, d.id_hoja_ruta,
           CONCAT(e.nombre,' ',e.apellido) AS encuestador
    FROM solicitudes_encuesta s
    INNER JOIN ciudadanos c        ON s.id_ciudadano  = c.id_ciudadano
    INNER JOIN barrios b           ON c.id_barrio     = b.id_barrio
    INNER JOIN comunas com         ON c.id_comuna     = com.id_comuna
    LEFT JOIN detalle_hoja_ruta d  ON d.id_solicitud  = s.id_solicitud
    LEFT JOIN hojas_ruta h         ON d.id_hoja_ruta  = h.id_hoja_ruta
    LEFT JOIN usuarios_sistema e   ON h.id_encuestador = e.id_usuario
    WHERE s.estado = 'realizada'
    ORDER BY s.fecha_limite ASC
")->fetchAll();

$historial = $db->query("
    SELECT r.fecha_revision, r.resultado, r.observaciones, s.numero_radicado,
           CONCAT(c.nombres,' ',c.apellidos) AS ciudadano,
           CONCAT(u.nombre,' ',u.apellido)   AS revisor
    FROM revisiones_calidad r
    INNER JOIN solicitudes_encuesta s ON r.id_solicitud = s.id_solicitud
    INNER JOIN ciudadanos c           ON s.id_ciudadano = c.id_ciudadano
    INNER JOIN usuarios_sistema u     ON r.id_revisor   = u.id_usuario
    ORDER BY r.fecha_revision DESC
    LIMIT 30
")->fetchAll();

$totales = $db->query("SELECT SUM(resultado='apto') AS aptos, SUM(resultado='no_apto') AS no_aptos FROM revisiones_calidad")->fetch();
$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calidad — Revisión de Encuestas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background:#f0f4f8; }
        .card-panel { border:none; border-radius:14px; box-shadow:0 2px 16px rgba(0,0,0,.08); }
        .counter-pill { font-size:1.6rem; font-weight:800; }
        .table td, .table th { font-size:.84rem; vertical-align:middle; }
    </style>
</head>
<body>
<?php include INCLUDES_PATH . '/header.php'; ?>

<div class="container-fluid py-4 px-4">
    <div class="mb-4">
        <h4 class="fw-bold mb-0" style="color:#1a3a5c;"><i class="bi bi-patch-check-fill me-2"></i>Supervisión de Calidad</h4>
        <small class="text-muted">Revise las encuestas realizadas en campo y emita el dictamen Apto / No Apto.</small>
    </div>

    <?php mostrarFlash(); ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card card-panel text-center p-3"><div class="counter-pill text-secondary"><?= count($porRevisar) ?></div><div class="text-muted small">Encuestas por revisar</div></div></div>
        <div class="col-md-4"><div class="card card-panel text-center p-3"><div class="counter-pill text-success"><?= (int)($totales['aptos'] ?? 0) ?></div><div class="text-muted small">Dictaminadas Aptas</div></div></div>
        <div class="col-md-4"><div class="card card-panel text-center p-3"><div class="counter-pill text-danger"><?= (int)($totales['no_aptos'] ?? 0) ?></div><div class="text-muted small">Dictaminadas No Aptas</div></div></div>
    </div>

    <div class="card card-panel mb-4">
        <div class="card-header bg-white fw-bold">Bandeja de revisión</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Radicado</th><th>Ciudadano</th><th>Dirección</th><th>Visita en campo</th><th style="min-width:280px;">Dictamen</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($porRevisar)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-inbox me-1"></i>No hay encuestas pendientes de revisión. Aparecerán aquí cuando un encuestador marque una visita como "Realizada".</td></tr>
                    <?php else: foreach ($porRevisar as $s): ?>
                        <tr>
                            <td>
                                <code class="fw-bold"><?= htmlspecialchars($s['numero_radicado']) ?></code>
                                <div class="small text-muted">Vence <?= date('d/m/Y', strtotime($s['fecha_limite'])) ?>
                                    <?php if ((int)$s['dias_restantes'] < 0): ?><span class="badge bg-danger">Vencida</span><?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($s['ciudadano']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($s['tipo_documento'] . ' ' . $s['numero_documento']) ?> · <?= htmlspecialchars($s['celular']) ?></small>
                            </td>
                            <td>
                                <?= htmlspecialchars($s['direccion']) ?>
                                <div class="small text-muted"><?= htmlspecialchars($s['nombre_barrio']) ?> · Comuna <?= (int)$s['numero_comuna'] ?></div>
                            </td>
                            <td>
                                <div class="small"><i class="bi bi-person me-1"></i><?= htmlspecialchars($s['encuestador'] ?? '—') ?>
                                    <?php if ($s['id_hoja_ruta']): ?>· Hoja #<?= (int)$s['id_hoja_ruta'] ?><?php endif; ?></div>
                                <div class="small text-muted">
                                    <?= $s['fecha_visita'] ? 'Visitada ' . date('d/m/Y H:i', strtotime($s['fecha_visita'])) : '' ?>
                                    <?= $s['hora_confirmacion_llamada'] ? ' · <i class="bi bi-telephone-fill text-success"></i> confirmada' : ' · <i class="bi bi-telephone-x text-danger"></i> sin llamada' ?>
                                </div>
                                <?php if ($s['notas_encuestador']): ?><div class="small fst-italic">"<?= htmlspecialchars($s['notas_encuestador']) ?>"</div><?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" class="d-flex flex-column gap-1">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="id_solicitud" value="<?= (int)$s['id_solicitud'] ?>">
                                    <textarea name="observaciones" rows="2" class="form-control form-control-sm" placeholder="Observaciones (obligatorio si es No Apto)" maxlength="1000"></textarea>
                                    <div class="d-flex gap-1">
                                        <button name="resultado" value="apto" class="btn btn-sm btn-success flex-fill"><i class="bi bi-check-lg me-1"></i>Apto</button>
                                        <button name="resultado" value="no_apto" class="btn btn-sm btn-danger flex-fill"><i class="bi bi-x-lg me-1"></i>No apto</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card card-panel">
        <div class="card-header bg-white fw-bold">Historial de dictámenes</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Fecha</th><th>Radicado</th><th>Ciudadano</th><th>Dictamen</th><th>Observaciones</th><th>Revisor</th></tr></thead>
                    <tbody>
                    <?php if (empty($historial)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">Aún no hay dictámenes registrados.</td></tr>
                    <?php else: foreach ($historial as $h): ?>
                        <tr>
                            <td><small><?= date('d/m/Y H:i', strtotime($h['fecha_revision'])) ?></small></td>
                            <td><code><?= htmlspecialchars($h['numero_radicado']) ?></code></td>
                            <td><?= htmlspecialchars($h['ciudadano']) ?></td>
                            <td><?= $h['resultado'] === 'apto' ? '<span class="badge bg-success">Apto</span>' : '<span class="badge bg-danger">No apto</span>' ?></td>
                            <td><small><?= htmlspecialchars($h['observaciones'] ?? '') ?></small></td>
                            <td><small><?= htmlspecialchars($h['revisor']) ?></small></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
