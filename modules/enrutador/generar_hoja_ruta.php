<?php
// ============================================================
// ARCHIVO: modules/enrutador/generar_hoja_ruta.php
// DESCRIPCIÓN: Generación transaccional de hojas de ruta
//              (invoca el procedimiento sp_generar_hoja_ruta — TCL)
// ============================================================
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/helpers.php';

$auth = new Auth();
$auth->requierePerfil('enrutador', 'administrador');

$db      = Database::getInstance()->getConnection();
$usuario = $auth->getUsuarioActual();

// ---- Catálogos ----
$encuestadores = $db->query("
    SELECT u.id_usuario, CONCAT(u.nombre,' ',u.apellido) AS nombre
    FROM usuarios_sistema u
    INNER JOIN perfiles p ON u.id_perfil = p.id_perfil
    WHERE p.nombre_perfil = 'encuestador' AND u.activo = 1
    ORDER BY u.nombre
")->fetchAll();

$puntos = $db->query("SELECT id_punto, nombre_punto FROM puntos_atencion WHERE activo = 1 ORDER BY es_oficina_principal DESC, nombre_punto")->fetchAll();

// ---- Procesar formulario ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->verificarCSRF($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Token de seguridad inválido. Recargue la página e intente de nuevo.');
        header('Location: generar_hoja_ruta.php');
        exit;
    }

    $idEncuestador = (int)($_POST['id_encuestador'] ?? 0);
    $idPunto       = (int)($_POST['id_punto'] ?? 0);
    $fechaTrabajo  = $_POST['fecha_trabajo'] ?? '';
    $zona          = $_POST['zona_tipo'] ?? '';

    $errores = [];
    if (!in_array($idEncuestador, array_map('intval', array_column($encuestadores, 'id_usuario')), true)) {
        $errores[] = 'Seleccione un encuestador válido.';
    }
    if (!in_array($idPunto, array_map('intval', array_column($puntos, 'id_punto')), true)) {
        $errores[] = 'Seleccione un punto de entrega válido.';
    }
    $fechaObj = DateTime::createFromFormat('Y-m-d', $fechaTrabajo);
    if (!$fechaObj || $fechaObj->format('Y-m-d') !== $fechaTrabajo || $fechaTrabajo < date('Y-m-d')) {
        $errores[] = 'La fecha de trabajo debe ser hoy o una fecha futura.';
    }
    if (!in_array($zona, ['urbana', 'rural'], true)) {
        $errores[] = 'Seleccione el tipo de zona.';
    }

    if (empty($errores)) {
        // Regla: un encuestador no puede tener dos hojas para el mismo día
        $dup = $db->prepare("SELECT COUNT(*) FROM hojas_ruta WHERE id_encuestador = :e AND fecha_trabajo = :f");
        $dup->execute([':e' => $idEncuestador, ':f' => $fechaTrabajo]);
        if ((int)$dup->fetchColumn() > 0) {
            $errores[] = 'Ese encuestador ya tiene una hoja de ruta asignada para esa fecha.';
        }

        // Regla: debe haber solicitudes pendientes en la zona
        $pend = $db->prepare("
            SELECT COUNT(*) FROM solicitudes_encuesta s
            INNER JOIN ciudadanos c ON s.id_ciudadano = c.id_ciudadano
            INNER JOIN comunas com  ON c.id_comuna    = com.id_comuna
            WHERE s.estado = 'pendiente' AND com.tipo = :z
        ");
        $pend->execute([':z' => $zona]);
        if ((int)$pend->fetchColumn() === 0) {
            $errores[] = "No hay solicitudes pendientes en zona $zona para asignar.";
        }
    }

    if (!empty($errores)) {
        setFlash('danger', implode(' ', $errores));
        header('Location: generar_hoja_ruta.php');
        exit;
    }

    try {
        $call = $db->prepare("CALL sp_generar_hoja_ruta(:enc, :enr, :punto, :fecha, :zona, @resultado)");
        $call->execute([
            ':enc'   => $idEncuestador,
            ':enr'   => $usuario['id'],
            ':punto' => $idPunto,
            ':fecha' => $fechaTrabajo,
            ':zona'  => $zona,
        ]);
        $call->closeCursor();
        $resultado = (string)$db->query("SELECT @resultado")->fetchColumn();

        if (str_starts_with($resultado, 'OK')) {
            preg_match('/#(\d+)/', $resultado, $m);
            $auth->registrarAuditoria($usuario['id'], 'GENERAR_HOJA_RUTA', 'hojas_ruta', isset($m[1]) ? (int)$m[1] : null, [], [
                'encuestador' => $idEncuestador, 'fecha' => $fechaTrabajo, 'zona' => $zona,
            ]);
            setFlash('success', $resultado);
        } else {
            setFlash('danger', $resultado ?: 'El procedimiento no devolvió resultado.');
        }
    } catch (PDOException $e) {
        setFlash('danger', 'Error al ejecutar sp_generar_hoja_ruta: ' . $e->getMessage()
            . ' — Verifique que importó el script sql/04_TCL_transacciones.sql.');
    }
    header('Location: generar_hoja_ruta.php');
    exit;
}

// ---- Resumen de pendientes por zona ----
$resumen = ['urbana' => 0, 'rural' => 0];
foreach ($db->query("
    SELECT com.tipo, COUNT(*) AS total
    FROM solicitudes_encuesta s
    INNER JOIN ciudadanos c ON s.id_ciudadano = c.id_ciudadano
    INNER JOIN comunas com  ON c.id_comuna    = com.id_comuna
    WHERE s.estado = 'pendiente'
    GROUP BY com.tipo
")->fetchAll() as $r) {
    $resumen[$r['tipo']] = (int)$r['total'];
}

// ---- Cola de pendientes (orden de prioridad del SP) ----
$cola = $db->query("
    SELECT s.numero_radicado, s.fecha_limite, DATEDIFF(s.fecha_limite, CURDATE()) AS dias_restantes,
           CONCAT(c.nombres,' ',c.apellidos) AS ciudadano, c.direccion, b.nombre_barrio,
           com.numero_comuna, com.tipo
    FROM solicitudes_encuesta s
    INNER JOIN ciudadanos c ON s.id_ciudadano = c.id_ciudadano
    INNER JOIN barrios b    ON c.id_barrio    = b.id_barrio
    INNER JOIN comunas com  ON c.id_comuna    = com.id_comuna
    WHERE s.estado = 'pendiente'
    ORDER BY s.fecha_limite ASC, s.fecha_radicacion ASC
    LIMIT 30
")->fetchAll();

// ---- Hojas de ruta generadas ----
$hojas = $db->query("
    SELECT h.id_hoja_ruta, h.fecha_asignacion, h.fecha_trabajo, h.zona_tipo, h.total_encuestas, h.estado,
           CONCAT(e.nombre,' ',e.apellido) AS encuestador,
           CONCAT(r.nombre,' ',r.apellido) AS enrutador,
           p.nombre_punto,
           (SELECT COUNT(*) FROM detalle_hoja_ruta d WHERE d.id_hoja_ruta = h.id_hoja_ruta AND d.resultado = 'realizada') AS realizadas
    FROM hojas_ruta h
    INNER JOIN usuarios_sistema e ON h.id_encuestador   = e.id_usuario
    INNER JOIN usuarios_sistema r ON h.id_enrutador     = r.id_usuario
    INNER JOIN puntos_atencion p  ON h.id_punto_entrega = p.id_punto
    ORDER BY h.id_hoja_ruta DESC
    LIMIT 50
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrutador — Generar Hoja de Ruta</title>
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
        <h4 class="fw-bold mb-0" style="color:#1a3a5c;"><i class="bi bi-map-fill me-2"></i>Generar Hoja de Ruta</h4>
        <small class="text-muted">Asigna automáticamente las solicitudes pendientes más urgentes (máx. <?= MAX_ENCUESTAS_URBANA ?> urbanas / <?= MAX_ENCUESTAS_RURAL ?> rurales) mediante una transacción con COMMIT/ROLLBACK.</small>
    </div>

    <?php mostrarFlash(); ?>

    <div class="row g-3 mb-4">
        <!-- Formulario -->
        <div class="col-lg-5">
            <div class="card card-panel h-100">
                <div class="card-header bg-white fw-bold">Nueva hoja de ruta</div>
                <div class="card-body">
                    <?php if (empty($encuestadores)): ?>
                        <div class="alert alert-warning mb-0">No hay encuestadores activos registrados.</div>
                    <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <div class="mb-3">
                            <label class="form-label">Encuestador</label>
                            <select name="id_encuestador" class="form-select" required>
                                <option value="">— Seleccione —</option>
                                <?php foreach ($encuestadores as $e): ?>
                                    <option value="<?= (int)$e['id_usuario'] ?>"><?= htmlspecialchars($e['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Punto de entrega de la hoja</label>
                            <select name="id_punto" class="form-select" required>
                                <?php foreach ($puntos as $p): ?>
                                    <option value="<?= (int)$p['id_punto'] ?>"><?= htmlspecialchars($p['nombre_punto']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label">Fecha de trabajo</label>
                                <input type="date" name="fecha_trabajo" class="form-control" min="<?= date('Y-m-d') ?>"
                                       value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Zona</label>
                                <select name="zona_tipo" class="form-select" required>
                                    <option value="urbana">Urbana (<?= $resumen['urbana'] ?> pend.)</option>
                                    <option value="rural">Rural (<?= $resumen['rural'] ?> pend.)</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-success w-100"><i class="bi bi-lightning-charge-fill me-1"></i>Generar hoja de ruta</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Cola de prioridad -->
        <div class="col-lg-7">
            <div class="card card-panel h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span class="fw-bold">Cola de pendientes (orden de prioridad)</span>
                    <span>
                        <span class="badge bg-primary">Urbana: <?= $resumen['urbana'] ?></span>
                        <span class="badge bg-success">Rural: <?= $resumen['rural'] ?></span>
                    </span>
                </div>
                <div class="card-body p-0" style="max-height:340px; overflow:auto;">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light sticky-top"><tr><th>Radicado</th><th>Ciudadano</th><th>Barrio</th><th>Zona</th><th>Vence</th></tr></thead>
                        <tbody>
                        <?php if (empty($cola)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No hay solicitudes pendientes.</td></tr>
                        <?php else: foreach ($cola as $c): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($c['numero_radicado']) ?></code></td>
                                <td><?= htmlspecialchars($c['ciudadano']) ?></td>
                                <td><small><?= htmlspecialchars($c['nombre_barrio']) ?> (C<?= (int)$c['numero_comuna'] ?>)</small></td>
                                <td><span class="badge <?= $c['tipo'] === 'rural' ? 'bg-success' : 'bg-primary' ?>"><?= ucfirst($c['tipo']) ?></span></td>
                                <td>
                                    <?php $dr = (int)$c['dias_restantes']; ?>
                                    <span class="badge <?= $dr < 0 ? 'bg-danger' : ($dr <= 3 ? 'bg-warning text-dark' : 'bg-light text-dark border') ?>">
                                        <?= $dr < 0 ? 'Vencida' : $dr . ' días' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Hojas generadas -->
    <div class="card card-panel">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-bold">Hojas de ruta generadas</span>
            <span class="badge bg-secondary"><?= count($hojas) ?> registros</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>#</th><th>Fecha trabajo</th><th>Encuestador</th><th>Zona</th><th>Punto entrega</th><th>Avance</th><th>Estado</th><th>Generó</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($hojas)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">Aún no se han generado hojas de ruta.</td></tr>
                    <?php else: foreach ($hojas as $h):
                        $pct = $h['total_encuestas'] > 0 ? round($h['realizadas'] / $h['total_encuestas'] * 100) : 0; ?>
                        <tr>
                            <td class="fw-bold">#<?= (int)$h['id_hoja_ruta'] ?></td>
                            <td><?= date('d/m/Y', strtotime($h['fecha_trabajo'])) ?></td>
                            <td><?= htmlspecialchars($h['encuestador']) ?></td>
                            <td><span class="badge <?= $h['zona_tipo'] === 'rural' ? 'bg-success' : 'bg-primary' ?>"><?= ucfirst($h['zona_tipo']) ?></span></td>
                            <td><small><?= htmlspecialchars($h['nombre_punto']) ?></small></td>
                            <td style="min-width:130px;">
                                <div class="progress" style="height:8px;"><div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div></div>
                                <small class="text-muted"><?= (int)$h['realizadas'] ?>/<?= (int)$h['total_encuestas'] ?></small>
                            </td>
                            <td><?= badgeEstadoHoja($h['estado']) ?></td>
                            <td><small><?= htmlspecialchars($h['enrutador']) ?></small></td>
                            <td><a href="<?= BASE_URL ?>/modules/encuestador/hoja_ruta.php?id=<?= (int)$h['id_hoja_ruta'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a></td>
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
