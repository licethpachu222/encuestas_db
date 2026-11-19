<?php
// ============================================================
// ARCHIVO: modules/encuestador/hoja_ruta.php
// DESCRIPCIÓN: Hoja de ruta del encuestador (registro de visitas)
//              y entrega de hojas desde Ventanilla.
// ============================================================
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/helpers.php';

$auth = new Auth();
$auth->requierePerfil('encuestador', 'ventanilla', 'enrutador', 'administrador');

$db      = Database::getInstance()->getConnection();
$usuario = $auth->getUsuarioActual();
$perfil  = $usuario['perfil'];
$esEncuestador = ($perfil === 'encuestador');
$puedeEntregar = in_array($perfil, ['ventanilla', 'enrutador', 'administrador'], true);
$puedeRegistrar = in_array($perfil, ['encuestador', 'administrador'], true);

/** Obtiene una hoja verificando que el usuario tenga acceso a ella. */
function obtenerHoja(PDO $db, int $idHoja, array $usuario): ?array {
    $stmt = $db->prepare("
        SELECT h.*, CONCAT(e.nombre,' ',e.apellido) AS encuestador, e.telefono AS tel_encuestador,
               CONCAT(r.nombre,' ',r.apellido) AS enrutador, p.nombre_punto, p.direccion AS dir_punto
        FROM hojas_ruta h
        INNER JOIN usuarios_sistema e ON h.id_encuestador   = e.id_usuario
        INNER JOIN usuarios_sistema r ON h.id_enrutador     = r.id_usuario
        INNER JOIN puntos_atencion p  ON h.id_punto_entrega = p.id_punto
        WHERE h.id_hoja_ruta = :id
    ");
    $stmt->execute([':id' => $idHoja]);
    $hoja = $stmt->fetch();
    if (!$hoja) return null;
    if ($usuario['perfil'] === 'encuestador' && (int)$hoja['id_encuestador'] !== (int)$usuario['id']) return null;
    return $hoja;
}

// ============================================================
// ACCIONES POST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idHoja = (int)($_POST['id_hoja'] ?? 0);
    $volver = 'hoja_ruta.php?id=' . $idHoja;

    if (!$auth->verificarCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Token de seguridad inválido. Recargue la página.');
        header("Location: $volver"); exit;
    }

    $hoja = obtenerHoja($db, $idHoja, $usuario);
    if (!$hoja) {
        setFlash('danger', 'Hoja de ruta no encontrada o sin permisos.');
        header('Location: hoja_ruta.php'); exit;
    }

    $accion = $_POST['accion'] ?? '';

    try {
        // ---- 1. Ventanilla entrega la hoja física al encuestador ----
        if ($accion === 'entregar' && $puedeEntregar) {
            if ($hoja['estado'] !== 'generada') {
                throw new RuntimeException('Solo se pueden entregar hojas en estado "generada".');
            }
            $db->prepare("UPDATE hojas_ruta SET estado = 'entregada' WHERE id_hoja_ruta = :id")->execute([':id' => $idHoja]);
            $auth->registrarAuditoria($usuario['id'], 'ENTREGAR_HOJA_RUTA', 'hojas_ruta', $idHoja, ['estado' => 'generada'], ['estado' => 'entregada']);
            setFlash('success', "Hoja de ruta #$idHoja marcada como entregada al encuestador.");
        }

        // ---- 2. Encuestador confirma telefónicamente la visita ----
        elseif ($accion === 'confirmar_llamada' && $puedeRegistrar) {
            $idDetalle = (int)($_POST['id_detalle'] ?? 0);
            $stmt = $db->prepare("UPDATE detalle_hoja_ruta SET hora_confirmacion_llamada = NOW()
                                  WHERE id_detalle = :d AND id_hoja_ruta = :h");
            $stmt->execute([':d' => $idDetalle, ':h' => $idHoja]);
            setFlash('success', 'Llamada de confirmación registrada.');
        }

        // ---- 3. Encuestador registra el resultado de la visita (transacción) ----
        elseif ($accion === 'registrar' && $puedeRegistrar) {
            $idDetalle = (int)($_POST['id_detalle'] ?? 0);
            $resultado = $_POST['resultado'] ?? '';
            $notas     = trim($_POST['notas'] ?? '');

            if (!in_array($resultado, ['realizada', 'ausente', 'reagendada'], true)) {
                throw new RuntimeException('Resultado de visita no válido.');
            }
            if ($resultado !== 'realizada' && $notas === '') {
                throw new RuntimeException('Escriba una nota explicando por qué la visita quedó ' . $resultado . '.');
            }

            $det = $db->prepare("SELECT id_solicitud, resultado FROM detalle_hoja_ruta WHERE id_detalle = :d AND id_hoja_ruta = :h");
            $det->execute([':d' => $idDetalle, ':h' => $idHoja]);
            $detalle = $det->fetch();
            if (!$detalle) throw new RuntimeException('Visita no encontrada en esta hoja.');
            if ($detalle['resultado'] === 'realizada') throw new RuntimeException('Esta encuesta ya fue realizada y está en revisión de calidad.');

            $db->beginTransaction();

            $db->prepare("UPDATE detalle_hoja_ruta
                          SET resultado = :r, notas_encuestador = :n, fecha_visita = NOW()
                          WHERE id_detalle = :d")
               ->execute([':r' => $resultado, ':n' => $notas ?: null, ':d' => $idDetalle]);

            $nuevoEstadoSol = $resultado === 'realizada' ? 'realizada' : 'en_proceso';
            $db->prepare("UPDATE solicitudes_encuesta SET estado = :e WHERE id_solicitud = :s")
               ->execute([':e' => $nuevoEstadoSol, ':s' => $detalle['id_solicitud']]);

            // Estado de la hoja: completada si ya no quedan visitas pendientes/reagendadas
            $rest = $db->prepare("SELECT COUNT(*) FROM detalle_hoja_ruta WHERE id_hoja_ruta = :h AND resultado IN ('pendiente','reagendada')");
            $rest->execute([':h' => $idHoja]);
            $estadoHoja = ((int)$rest->fetchColumn() === 0) ? 'completada' : 'en_proceso';
            $db->prepare("UPDATE hojas_ruta SET estado = :e WHERE id_hoja_ruta = :h")->execute([':e' => $estadoHoja, ':h' => $idHoja]);

            $db->commit();

            $auth->registrarAuditoria($usuario['id'], 'REGISTRAR_VISITA', 'detalle_hoja_ruta', $idDetalle,
                ['resultado' => $detalle['resultado']], ['resultado' => $resultado, 'notas' => $notas]);
            setFlash('success', 'Visita registrada como "' . $resultado . '".' . ($estadoHoja === 'completada' ? ' ¡Hoja de ruta completada!' : ''));
        }

        else {
            throw new RuntimeException('Acción no permitida para su perfil.');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('danger', $e->getMessage());
    }
    header("Location: $volver"); exit;
}

// ============================================================
// CONSULTAS
// ============================================================
if ($esEncuestador) {
    $stmtHojas = $db->prepare("
        SELECT h.id_hoja_ruta, h.fecha_trabajo, h.zona_tipo, h.total_encuestas, h.estado,
               CONCAT(e.nombre,' ',e.apellido) AS encuestador
        FROM hojas_ruta h INNER JOIN usuarios_sistema e ON h.id_encuestador = e.id_usuario
        WHERE h.id_encuestador = :id
        ORDER BY (h.estado = 'completada'), h.fecha_trabajo DESC
    ");
    $stmtHojas->execute([':id' => $usuario['id']]);
} else {
    $stmtHojas = $db->query("
        SELECT h.id_hoja_ruta, h.fecha_trabajo, h.zona_tipo, h.total_encuestas, h.estado,
               CONCAT(e.nombre,' ',e.apellido) AS encuestador
        FROM hojas_ruta h INNER JOIN usuarios_sistema e ON h.id_encuestador = e.id_usuario
        ORDER BY FIELD(h.estado,'generada','entregada','en_proceso','completada'), h.fecha_trabajo DESC
        LIMIT 50
    ");
}
$hojas = $stmtHojas->fetchAll();

$idSel = (int)($_GET['id'] ?? 0);
if ($idSel === 0 && !empty($hojas)) $idSel = (int)$hojas[0]['id_hoja_ruta'];
$hoja = $idSel ? obtenerHoja($db, $idSel, $usuario) : null;

$visitas = [];
if ($hoja) {
    $stmtV = $db->prepare("
        SELECT d.*, s.numero_radicado, s.fecha_limite, s.estado AS estado_solicitud, s.latitud, s.longitud, s.observaciones,
               CONCAT(c.nombres,' ',c.apellidos) AS ciudadano, c.celular, c.direccion,
               b.nombre_barrio, com.numero_comuna
        FROM detalle_hoja_ruta d
        INNER JOIN solicitudes_encuesta s ON d.id_solicitud = s.id_solicitud
        INNER JOIN ciudadanos c           ON s.id_ciudadano = c.id_ciudadano
        INNER JOIN barrios b              ON c.id_barrio    = b.id_barrio
        INNER JOIN comunas com            ON c.id_comuna    = com.id_comuna
        WHERE d.id_hoja_ruta = :h
        ORDER BY d.orden_visita
    ");
    $stmtV->execute([':h' => $hoja['id_hoja_ruta']]);
    $visitas = $stmtV->fetchAll();
}

$puntosMapa = array_values(array_filter(array_map(fn($v) => ($v['latitud'] && $v['longitud']) ? [
    'lat' => (float)$v['latitud'], 'lng' => (float)$v['longitud'],
    'orden' => (int)$v['orden_visita'], 'txt' => $v['ciudadano'] . ' — ' . $v['direccion'], 'res' => $v['resultado'],
] : null, $visitas)));

$despuesDeCorte = date('H:i') >= HORA_CORTE_CAMPO;
$csrf = htmlspecialchars($_SESSION['csrf_token'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $esEncuestador ? 'Mi Hoja de Ruta' : 'Entrega de Hojas de Ruta' ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
    <style>
        body { background:#f0f4f8; }
        .card-panel { border:none; border-radius:14px; box-shadow:0 2px 16px rgba(0,0,0,.08); }
        .table td, .table th { font-size:.84rem; vertical-align:middle; }
        .hoja-item { border-left:4px solid transparent; }
        .hoja-item.active { border-left-color:#1a3a5c; background:#e8f0fe !important; color:inherit; }
        #mapa { height:300px; border-radius:10px; }
        .orden { width:28px; height:28px; border-radius:50%; background:#1a3a5c; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:.8rem; }
        @media print { nav, .no-print { display:none !important; } .col-lg-3 { display:none; } .col-lg-9 { width:100%; } }
    </style>
</head>
<body>
<?php include INCLUDES_PATH . '/header.php'; ?>

<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0" style="color:#1a3a5c;">
                <i class="bi bi-clipboard2-check-fill me-2"></i><?= $esEncuestador ? 'Mi Hoja de Ruta' : 'Hojas de Ruta — Entrega y Seguimiento' ?>
            </h4>
            <small class="text-muted">
                <?= $esEncuestador ? 'Confirme cada visita por teléfono y registre el resultado en campo.' : 'Entregue las hojas generadas a los encuestadores y siga su avance.' ?>
            </small>
        </div>
        <?php if ($hoja): ?>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm no-print"><i class="bi bi-printer me-1"></i>Imprimir hoja</button>
        <?php endif; ?>
    </div>

    <?php mostrarFlash(); ?>

    <?php if ($esEncuestador && $despuesDeCorte): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 no-print">
        <i class="bi bi-shield-exclamation fs-5"></i>
        <div>Son más de las <?= HORA_CORTE_CAMPO ?>: por seguridad, el protocolo indica repliegue del trabajo de campo. Registre los resultados pendientes y reprograme las visitas faltantes.</div>
    </div>
    <?php endif; ?>

    <div class="row g-3">
        <!-- Lista de hojas -->
        <div class="col-lg-3">
            <div class="card card-panel">
                <div class="card-header bg-white fw-bold"><?= $esEncuestador ? 'Mis hojas' : 'Hojas de ruta' ?></div>
                <div class="list-group list-group-flush" style="max-height:600px; overflow:auto;">
                    <?php if (empty($hojas)): ?>
                        <div class="p-3 text-muted small">
                            <?= $esEncuestador ? 'No tienes hojas de ruta asignadas todavía.' : 'Aún no se han generado hojas de ruta.' ?>
                        </div>
                    <?php else: foreach ($hojas as $h): ?>
                        <a href="?id=<?= (int)$h['id_hoja_ruta'] ?>" class="list-group-item list-group-item-action hoja-item <?= (int)$h['id_hoja_ruta'] === $idSel ? 'active' : '' ?>">
                            <div class="d-flex justify-content-between">
                                <strong>#<?= (int)$h['id_hoja_ruta'] ?> · <?= date('d/m/Y', strtotime($h['fecha_trabajo'])) ?></strong>
                                <?= badgeEstadoHoja($h['estado']) ?>
                            </div>
                            <small class="text-muted">
                                <?= $esEncuestador ? '' : htmlspecialchars($h['encuestador']) . ' · ' ?>
                                <?= ucfirst($h['zona_tipo']) ?> · <?= (int)$h['total_encuestas'] ?> encuestas
                            </small>
                        </a>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <!-- Detalle -->
        <div class="col-lg-9">
            <?php if (!$hoja): ?>
                <div class="card card-panel p-5 text-center text-muted">
                    <i class="bi bi-map display-4 mb-2"></i>
                    <p class="mb-0">Seleccione una hoja de ruta para ver su detalle.</p>
                </div>
            <?php else: ?>
            <div class="card card-panel mb-3">
                <div class="card-body">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-8">
                            <h5 class="fw-bold mb-1">Hoja de ruta #<?= (int)$hoja['id_hoja_ruta'] ?> <?= badgeEstadoHoja($hoja['estado']) ?></h5>
                            <div class="small text-muted">
                                <i class="bi bi-person me-1"></i>Encuestador: <strong><?= htmlspecialchars($hoja['encuestador']) ?></strong> ·
                                <i class="bi bi-calendar-event ms-1 me-1"></i>Trabajo: <strong><?= date('d/m/Y', strtotime($hoja['fecha_trabajo'])) ?></strong> ·
                                <i class="bi bi-geo ms-1 me-1"></i>Zona <?= htmlspecialchars($hoja['zona_tipo']) ?><br>
                                <i class="bi bi-building me-1"></i>Recoger en: <?= htmlspecialchars($hoja['nombre_punto']) ?> (<?= htmlspecialchars($hoja['dir_punto'] ?? '') ?>) ·
                                Generada por <?= htmlspecialchars($hoja['enrutador']) ?> el <?= date('d/m/Y H:i', strtotime($hoja['fecha_asignacion'])) ?>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-end no-print">
                            <?php if ($puedeEntregar && $hoja['estado'] === 'generada'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="id_hoja" value="<?= (int)$hoja['id_hoja_ruta'] ?>">
                                    <input type="hidden" name="accion" value="entregar">
                                    <button class="btn btn-primary"><i class="bi bi-box-arrow-in-right me-1"></i>Marcar como entregada</button>
                                </form>
                            <?php endif; ?>
                            <?php
                            $hechas = count(array_filter($visitas, fn($v) => $v['resultado'] === 'realizada'));
                            $pct = count($visitas) ? round($hechas / count($visitas) * 100) : 0;
                            ?>
                            <div class="mt-2">
                                <div class="progress" style="height:10px;"><div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div></div>
                                <small class="text-muted"><?= $hechas ?> de <?= count($visitas) ?> encuestas realizadas</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($puntosMapa)): ?>
            <div class="card card-panel mb-3 no-print">
                <div class="card-body p-2"><div id="mapa"></div></div>
            </div>
            <?php endif; ?>

            <div class="card card-panel">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>#</th><th>Ciudadano</th><th>Dirección</th><th>Radicado / Vence</th><th>Llamada</th><th>Resultado</th><?php if ($puedeRegistrar): ?><th class="no-print">Registrar</th><?php endif; ?></tr>
                            </thead>
                            <tbody>
                            <?php if (empty($visitas)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Esta hoja no tiene visitas asignadas.</td></tr>
                            <?php else: foreach ($visitas as $v): ?>
                                <tr>
                                    <td><span class="orden"><?= (int)$v['orden_visita'] ?></span></td>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($v['ciudadano']) ?></div>
                                        <a href="tel:<?= htmlspecialchars($v['celular']) ?>" class="small text-decoration-none"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($v['celular']) ?></a>
                                    </td>
                                    <td>
                                        <div><?= htmlspecialchars($v['direccion']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($v['nombre_barrio']) ?> · Comuna <?= (int)$v['numero_comuna'] ?></small>
                                        <?php if ($v['observaciones']): ?><div class="small fst-italic text-secondary"><?= htmlspecialchars($v['observaciones']) ?></div><?php endif; ?>
                                    </td>
                                    <td><code><?= htmlspecialchars($v['numero_radicado']) ?></code><br><small class="text-muted"><?= date('d/m/Y', strtotime($v['fecha_limite'])) ?></small></td>
                                    <td>
                                        <?php if ($v['hora_confirmacion_llamada']): ?>
                                            <span class="badge bg-success"><i class="bi bi-check2 me-1"></i><?= date('H:i', strtotime($v['hora_confirmacion_llamada'])) ?></span>
                                        <?php elseif ($puedeRegistrar && $v['resultado'] !== 'realizada'): ?>
                                            <form method="POST" class="no-print">
                                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                                <input type="hidden" name="id_hoja" value="<?= (int)$hoja['id_hoja_ruta'] ?>">
                                                <input type="hidden" name="id_detalle" value="<?= (int)$v['id_detalle'] ?>">
                                                <input type="hidden" name="accion" value="confirmar_llamada">
                                                <button class="btn btn-sm btn-outline-success"><i class="bi bi-telephone-outbound"></i> Confirmar</button>
                                            </form>
                                        <?php else: ?>
                                            <small class="text-muted">—</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= badgeResultadoVisita($v['resultado']) ?>
                                        <?php if ($v['fecha_visita']): ?><div class="small text-muted"><?= date('d/m H:i', strtotime($v['fecha_visita'])) ?></div><?php endif; ?>
                                        <?php if ($v['notas_encuestador']): ?><div class="small"><?= htmlspecialchars($v['notas_encuestador']) ?></div><?php endif; ?>
                                    </td>
                                    <?php if ($puedeRegistrar): ?>
                                    <td class="no-print" style="min-width:220px;">
                                        <?php if ($v['resultado'] === 'realizada'): ?>
                                            <small class="text-success"><i class="bi bi-lock-fill me-1"></i>Enviada a calidad</small>
                                        <?php elseif ($hoja['estado'] === 'generada'): ?>
                                            <small class="text-muted">Pendiente de entrega en ventanilla</small>
                                        <?php else: ?>
                                            <form method="POST" class="d-flex flex-column gap-1">
                                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                                <input type="hidden" name="id_hoja" value="<?= (int)$hoja['id_hoja_ruta'] ?>">
                                                <input type="hidden" name="id_detalle" value="<?= (int)$v['id_detalle'] ?>">
                                                <input type="hidden" name="accion" value="registrar">
                                                <select name="resultado" class="form-select form-select-sm" required>
                                                    <option value="realizada">✔ Realizada</option>
                                                    <option value="ausente">✖ Ausente</option>
                                                    <option value="reagendada">↻ Reagendada</option>
                                                </select>
                                                <input type="text" name="notas" class="form-control form-control-sm" placeholder="Notas de la visita" maxlength="500">
                                                <button class="btn btn-sm btn-primary">Guardar</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($puntosMapa)): ?>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const puntos = <?= json_encode($puntosMapa) ?>;
const mapa = L.map('mapa');
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(mapa);
const colores = { pendiente: '#1a3a5c', realizada: '#2e7d32', ausente: '#c62828', reagendada: '#f9a825' };
const capas = puntos.map(p => L.marker([p.lat, p.lng], {
    icon: L.divIcon({ className: '', html: `<span class="orden" style="background:${colores[p.res] || '#1a3a5c'}">${p.orden}</span>`, iconSize: [28, 28] })
}).bindPopup(`<b>${p.orden}.</b> ${p.txt.replace(/</g, '&lt;')}`).addTo(mapa));
mapa.fitBounds(L.featureGroup(capas).getBounds().pad(0.3), { maxZoom: 16 });
if (puntos.length > 1) L.polyline(puntos.map(p => [p.lat, p.lng]), { color: '#1a3a5c', dashArray: '6 6', weight: 2 }).addTo(mapa);
</script>
<?php endif; ?>
</body>
</html>
