<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Solicitud — Ventanilla</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css" rel="stylesheet">
    <style>
        body { background:#f0f4f8; }
        #mapaContainer { height: 400px; border-radius: 12px; border: 2px solid #1a3a5c; }
        .card-form { border: none; border-radius: 14px; box-shadow: 0 4px 20px rgba(0,0,0,.08); }
        .section-label {
            font-size:.72rem; font-weight:700; text-transform:uppercase;
            letter-spacing:.08em; color:#607d8b; border-bottom:1px solid #eceff1;
            padding-bottom:6px; margin-bottom:1rem;
        }
        .geo-coords { background:#e3f2fd; border:1px solid #90caf9; border-radius:8px; padding:.6rem 1rem; font-size:.82rem; }
        .radicado-preview { font-family:monospace; font-size:1.1rem; font-weight:700; color:#1a3a5c; background:#e8eaf6; border-radius:8px; padding:.4rem 1rem; display:inline-block; }
        .engine-toggle { border-radius: 20px; font-size: 0.8rem; }
    </style>
</head>
<body>

<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/helpers.php';

$auth = new Auth();
$auth->requierePerfil('ventanilla', 'administrador');

$db      = Database::getInstance()->getConnection();
$usuario = $auth->getUsuarioActual();

$comunas = $db->query("SELECT id_comuna, numero_comuna, nombre_comuna, tipo FROM comunas ORDER BY numero_comuna")->fetchAll();

$stmtUltimo = $db->query("SELECT COUNT(*) FROM solicitudes_encuesta WHERE DATE(fecha_radicacion) = CURDATE()");
$correlativo = (int)$stmtUltimo->fetchColumn() + 1;
$numeroRadicado = 'RAD-' . date('Ymd') . '-' . str_pad($correlativo, 4, '0', STR_PAD_LEFT);

$errores = [];
$exito = '';
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->verificarCSRF($_POST['csrf_token'] ?? '')) {
        $errores[] = 'Token de seguridad inválido.';
    } else {
        $formData = [
            'tipo_documento'   => $_POST['tipo_documento']   ?? '',
            'numero_documento' => trim($_POST['numero_documento'] ?? ''),
            'nombres'          => trim($_POST['nombres']          ?? ''),
            'apellidos'        => trim($_POST['apellidos']        ?? ''),
            'celular'          => trim($_POST['celular']          ?? ''),
            'email'            => trim($_POST['email']            ?? ''),
            'fecha_nacimiento' => $_POST['fecha_nacimiento']      ?? null,
            'direccion'        => trim($_POST['direccion']        ?? ''),
            'nombre_barrio'    => normalizarNombreBarrio($_POST['nombre_barrio'] ?? ''),
            'id_comuna'        => COMUNA_HABILITADA ? (int)($_POST['id_comuna'] ?? 0) : 0,
            'latitud'         => $_POST['latitud']               ?? null,
            'longitud'         => $_POST['longitud']              ?? null,
            'observaciones'    => trim($_POST['observaciones']    ?? ''),
        ];

        if (empty($formData['tipo_documento']))   $errores[] = 'Seleccione el tipo de documento.';
        if (empty($formData['numero_documento'])) $errores[] = 'Ingrese el número de documento.';
        if (empty($formData['nombres']))          $errores[] = 'Ingrese los nombres.';
        if (empty($formData['apellidos']))        $errores[] = 'Ingrese los apellidos.';
        if (!preg_match('/^3\d{9}$/', $formData['celular'])) $errores[] = 'Número celular inválido (10 dígitos iniciando en 3).';
        if (empty($formData['direccion']))        $errores[] = 'Ingrese la dirección domiciliaria.';
        if (COMUNA_HABILITADA && $formData['id_comuna'] <= 0) $errores[] = 'Seleccione la comuna o corregimiento.';
        $largoBarrio = mb_strlen($formData['nombre_barrio'], 'UTF-8');
        if ($largoBarrio < 2 || $largoBarrio > 100) $errores[] = 'Escriba o seleccione el barrio (entre 2 y 100 caracteres).';

        if (empty($errores)) {
            try {
                $db->beginTransaction();

                // Barrio: se usa el existente o se registra uno nuevo.
                // Si la comuna está deshabilitada, se toma la del barrio o queda "Por asignar".
                [$formData['id_barrio'], $formData['id_comuna']] =
                    resolverBarrio($db, $formData['nombre_barrio'], $formData['id_comuna'] ?: null);

                $stmtBuscar = $db->prepare("SELECT id_ciudadano FROM ciudadanos WHERE tipo_documento = :tipo AND numero_documento = :num LIMIT 1");
                $stmtBuscar->execute([':tipo' => $formData['tipo_documento'], ':num' => $formData['numero_documento']]);
                $idCiudadano = $stmtBuscar->fetchColumn();

                if ($idCiudadano) {
                    $stmtUpdate = $db->prepare("
                        UPDATE ciudadanos SET
                            nombres = :nombres, apellidos = :apellidos,
                            celular = :celular, email = :email,
                            direccion = :dir, id_barrio = :barrio, id_comuna = :comuna
                        WHERE id_ciudadano = :id
                    ");
                    $stmtUpdate->execute([
                        ':nombres'   => $formData['nombres'],
                        ':apellidos' => $formData['apellidos'],
                        ':celular'   => $formData['celular'],
                        ':email'     => $formData['email'] ?: null,
                        ':dir'       => $formData['direccion'],
                        ':barrio'    => $formData['id_barrio'],
                        ':comuna'    => $formData['id_comuna'],
                        ':id'        => $idCiudadano,
                    ]);
                } else {
                    $stmtIns = $db->prepare("
                        INSERT INTO ciudadanos
                            (tipo_documento, numero_documento, nombres, apellidos, celular, email, fecha_nacimiento, direccion, id_barrio, id_comuna)
                        VALUES
                            (:tipo, :num, :nombres, :apellidos, :celular, :email, :fnac, :dir, :barrio, :comuna)
                    ");
                    $stmtIns->execute([
                        ':tipo'      => $formData['tipo_documento'],
                        ':num'       => $formData['numero_documento'],
                        ':nombres'   => $formData['nombres'],
                        ':apellidos' => $formData['apellidos'],
                        ':celular'   => $formData['celular'],
                        ':email'     => $formData['email'] ?: null,
                        ':fnac'      => $formData['fecha_nacimiento'] ?: null,
                        ':dir'       => $formData['direccion'],
                        ':barrio'    => $formData['id_barrio'],
                        ':comuna'    => $formData['id_comuna'],
                    ]);
                    $idCiudadano = (int)$db->lastInsertId();
                }

                $stmtCount = $db->query("SELECT COUNT(*) FROM solicitudes_encuesta WHERE DATE(fecha_radicacion) = CURDATE()");
                $corrFinal = (int)$stmtCount->fetchColumn() + 1;
                $radFinal  = 'RAD-' . date('Ymd') . '-' . str_pad($corrFinal, 4, '0', STR_PAD_LEFT);

                $stmtSol = $db->prepare("
                    INSERT INTO solicitudes_encuesta
                        (numero_radicado, id_ciudadano, id_punto_atencion, id_usuario_ventanilla, fecha_radicacion, fecha_limite, latitud, longitud, observaciones)
                    VALUES
                        (:radicado, :ciudadano, :punto, :ventanilla, NOW(), DATE_ADD(CURDATE(), INTERVAL :dias DAY), :lat, :lng, :obs)
                ");
                $stmtSol->execute([
                    ':radicado'   => $radFinal,
                    ':ciudadano'  => $idCiudadano,
                    ':punto'      => $usuario['id_punto'] ?? 1,
                    ':ventanilla' => $usuario['id'],
                    ':dias'       => MAX_DIAS_PLAZO,
                    ':lat'        => !empty($formData['latitud'])  ? $formData['latitud']  : null,
                    ':lng'        => !empty($formData['longitud']) ? $formData['longitud'] : null,
                    ':obs'        => $formData['observaciones'] ?: null,
                ]);
                $idSolicitud = (int)$db->lastInsertId();

                $db->commit();
                $auth->registrarAuditoria($usuario['id'], 'RADICACION_SOLICITUD', 'solicitudes_encuesta', $idSolicitud, [], ['radicado' => $radFinal]);

                $exito = $radFinal;
                $formData = [];
            } catch (Throwable $e) {
                $db->rollBack();
                $errores[] = 'Error al procesar la solicitud: ' . $e->getMessage();
            }
        }
    }
}
?>

<?php include INCLUDES_PATH . '/header.php'; ?>

<div class="container-fluid py-4 px-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="<?= BASE_URL ?>/modules/ventanilla/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Volver
        </a>
        <div>
            <h4 class="fw-bold mb-0" style="color:#1a3a5c;">
                <i class="bi bi-plus-circle-fill me-2 text-primary"></i>Radicar Solicitud de Encuesta Domiciliaria
            </h4>
            <small class="text-muted">Requerimientos 2 y 3 — Dirección manual/editable y georreferenciación satelital</small>
        </div>
    </div>

    <?php if ($exito): ?>
    <div class="alert alert-success d-flex align-items-center gap-3 rounded-3 shadow-sm mb-4" role="alert">
        <i class="bi bi-check-circle-fill fs-3"></i>
        <div>
            <strong>¡Solicitud radicada con éxito!</strong><br>
            Radicado asignado: <span class="radicado-preview"><?= htmlspecialchars($exito) ?></span><br>
            <small>Plazo máximo de visita: <strong><?= date('d/m/Y', strtotime('+' . MAX_DIAS_PLAZO . ' days')) ?></strong> (15 días calendario según política institucional).</small>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($errores)): ?>
    <div class="alert alert-danger rounded-3 mb-4">
        <i class="bi bi-exclamation-octagon-fill me-2"></i><strong>Por favor corrija los siguientes campos:</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($errores as $e): ?>
                <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" id="formSolicitud">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card card-form h-100">
                    <div class="card-body p-4">
                        <div class="section-label"><i class="bi bi-person-badge me-1"></i>1. Datos del Ciudadano Solicitante</div>

                        <div class="row g-3 mb-3">
                            <div class="col-5">
                                <label class="form-label small fw-semibold">Tipo Documento *</label>
                                <select name="tipo_documento" class="form-select form-select-sm" required>
                                    <option value="CC" <?= (($formData['tipo_documento'] ?? 'CC') === 'CC') ? 'selected' : '' ?>>Cédula Ciudadanía (CC)</option>
                                    <option value="CE" <?= (($formData['tipo_documento'] ?? '') === 'CE') ? 'selected' : '' ?>>Cédula Extranjería (CE)</option>
                                    <option value="TI" <?= (($formData['tipo_documento'] ?? '') === 'TI') ? 'selected' : '' ?>>Tarjeta Identidad (TI)</option>
                                    <option value="PP" <?= (($formData['tipo_documento'] ?? '') === 'PP') ? 'selected' : '' ?>>Pasaporte (PP)</option>
                                </select>
                            </div>
                            <div class="col-7">
                                <label class="form-label small fw-semibold">Número de Documento *</label>
                                <input type="text" name="numero_documento" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($formData['numero_documento'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Nombres *</label>
                                <input type="text" name="nombres" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($formData['nombres'] ?? '') ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Apellidos *</label>
                                <input type="text" name="apellidos" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($formData['apellidos'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Celular de Contacto *</label>
                                <input type="tel" name="celular" class="form-control form-control-sm"
                                       placeholder="3001234567" value="<?= htmlspecialchars($formData['celular'] ?? '') ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Correo Electrónico</label>
                                <input type="email" name="email" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($formData['email'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="section-label mt-4"><i class="bi bi-geo-alt-fill me-1"></i>2. Dirección Manual y Editable</div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Dirección Domiciliaria (100% Editable) *</label>
                            <div class="input-group">
                                <input type="text" name="direccion" id="campoDir" class="form-control"
                                       placeholder="Ej: Calle 5 # 20-10 Casa 3" value="<?= htmlspecialchars($formData['direccion'] ?? '') ?>" required>
                                <button type="button" class="btn btn-primary" id="btnBuscarDir" title="Buscar dirección en el mapa">
                                    <i class="bi bi-search me-1"></i>Ubicar en Mapa
                                </button>
                            </div>
                            <small class="text-muted">El mapa busca en OpenStreetMap con la <b>dirección</b>. Si el punto no queda exacto, arrastra el marcador al predio.</small>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Comuna / Corregimiento <?= COMUNA_HABILITADA ? '*' : '' ?></label>
                                <select name="id_comuna" id="selectComuna" class="form-select form-select-sm" <?= COMUNA_HABILITADA ? 'required' : 'disabled' ?>>
                                    <option value="">— Seleccionar —</option>
                                    <?php foreach ($comunas as $com): ?>
                                        <option value="<?= $com['id_comuna'] ?>" <?= ($formData['id_comuna'] ?? 0) == $com['id_comuna'] ? 'selected' : '' ?>>
                                            Comuna <?= $com['numero_comuna'] ?> — <?= htmlspecialchars($com['nombre_comuna']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!COMUNA_HABILITADA): ?>
                                    <small class="text-muted"><i class="bi bi-lock me-1"></i>Deshabilitado por ahora: se asigna según el barrio.</small>
                                <?php endif; ?>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Barrio *</label>
                                <input type="text" name="nombre_barrio" id="inputBarrio" class="form-control form-control-sm"
                                       list="listaBarrios" maxlength="100" autocomplete="off" required
                                       placeholder="Escriba o elija el barrio"
                                       value="<?= htmlspecialchars($formData['nombre_barrio'] ?? '') ?>">
                                <datalist id="listaBarrios"></datalist>
                                <small class="text-muted" id="ayudaBarrio">Elija una sugerencia o escriba el barrio si no aparece.</small>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Latitud (Editable)</label>
                                <input type="number" step="0.0000001" name="latitud" id="latitud" class="form-control form-control-sm font-monospace"
                                       value="<?= htmlspecialchars($formData['latitud'] ?? '') ?>" placeholder="Ej: 3.4516">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-semibold">Longitud (Editable)</label>
                                <input type="number" step="0.0000001" name="longitud" id="longitud" class="form-control form-control-sm font-monospace"
                                       value="<?= htmlspecialchars($formData['longitud'] ?? '') ?>" placeholder="Ej: -76.5320">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Observaciones / Indicaciones Especiales</label>
                            <textarea name="observaciones" class="form-control form-control-sm" rows="2" placeholder="Ej: Frente a la tienda escolar, reja negra"><?= htmlspecialchars($formData['observaciones'] ?? '') ?></textarea>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm">
                                <i class="bi bi-save-fill me-2"></i>Radicar Solicitud Oficial
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card card-form h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <div class="section-label mb-0"><i class="bi bi-map-fill me-1"></i>3. Georreferenciación (OpenStreetMap)</div>
                            <div class="btn-group btn-group-sm d-none"><!-- Google Maps desactivado: la georreferenciación usa OpenStreetMap -->
                                <button type="button" class="btn btn-outline-primary engine-toggle" id="btnModoGoogle">
                                    <i class="bi bi-google me-1"></i>Google Maps
                                </button>
                                <button type="button" class="btn btn-outline-secondary active engine-toggle" id="btnModoOSM">
                                    <i class="bi bi-globe-americas me-1"></i>OpenStreetMap
                                </button>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-success" id="btnMiUbicacion">
                                <i class="bi bi-crosshair me-1"></i>GPS Mi Ubicación
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnCentrarCali">
                                <i class="bi bi-bullseye me-1"></i>Centrar en Cali
                            </button>
                        </div>

                        <div id="mapaContainer" class="mb-3"></div>

                        <div class="geo-coords d-flex justify-content-between align-items-center flex-wrap gap-2" id="coordsBox">
                            <div>
                                <small class="fw-bold d-block"><i class="bi bi-pin-map-fill text-danger me-1"></i>Punto Satelital:</small>
                                <span id="coordsTexto">Haz clic sobre el mapa para fijar el predio.</span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-success" id="btnCopiarDirDetectada" style="display:none;">
                                <i class="bi bi-arrow-left-circle me-1"></i>Usar dir. sugerida
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
const GOOGLE_API_KEY = "<?= GOOGLE_MAPS_API_KEY ?>";
let latActual = <?= !empty($formData['latitud']) ? (float)$formData['latitud'] : 3.4516 ?>;
let lngActual = <?= !empty($formData['longitud']) ? (float)$formData['longitud'] : -76.5320 ?>;

let modoActual = 'osm';
let leafletMap = null;
let leafletMarker = null;
let googleMap = null;
let googleMarker = null;
let geocoderGoogle = null;
let direccionSugerida = '';

function iniciarLeaflet() {
    if (leafletMap) { leafletMap.remove(); leafletMap = null; }
    document.getElementById('mapaContainer').innerHTML = "";
    leafletMap = L.map('mapaContainer').setView([latActual, lngActual], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(leafletMap);

    leafletMarker = L.marker([latActual, lngActual], { draggable: true }).addTo(leafletMap);

    leafletMarker.on('dragend', function (e) {
        const p = e.target.getLatLng();
        actualizarCoordenadas(p.lat, p.lng);
        consultarDireccionInversa(p.lat, p.lng);
    });

    leafletMap.on('click', function (e) {
        leafletMarker.setLatLng(e.latlng);
        actualizarCoordenadas(e.latlng.lat, e.latlng.lng);
        consultarDireccionInversa(e.latlng.lat, e.latlng.lng);
    });
}

function iniciarGoogleMaps() {
    if (!window.google || !window.google.maps) {
        cargarScriptGoogleMaps();
        return;
    }

    document.getElementById('mapaContainer').innerHTML = "";
    geocoderGoogle = new google.maps.Geocoder();
    const centro = { lat: latActual, lng: lngActual };

    googleMap = new google.maps.Map(document.getElementById('mapaContainer'), {
        zoom: 15,
        center: centro,
        mapTypeId: 'roadmap',
        mapTypeControl: true,
        streetViewControl: true
    });

    googleMarker = new google.maps.Marker({
        position: centro,
        map: googleMap,
        draggable: true,
        animation: google.maps.Animation.DROP
    });

    googleMarker.addListener('dragend', function () {
        const pos = googleMarker.getPosition();
        actualizarCoordenadas(pos.lat(), pos.lng());
        geocodificarGoogleInversa(pos.lat(), pos.lng());
    });

    googleMap.addListener('click', function (e) {
        googleMarker.setPosition(e.latLng);
        actualizarCoordenadas(e.latLng.lat(), e.latLng.lng());
        geocodificarGoogleInversa(e.latLng.lat(), e.latLng.lng());
    });
}

function cargarScriptGoogleMaps() {
    if (document.getElementById('gmaps-script')) return;
    const script = document.createElement('script');
    script.id = 'gmaps-script';
    const keyParam = GOOGLE_API_KEY ? `key=${GOOGLE_API_KEY}&` : '';
    script.src = `https://maps.googleapis.com/maps/api/js?${keyParam}libraries=places&callback=iniciarGoogleMaps`;
    script.async = true;
    script.defer = true;
    script.onerror = function() {
        alert("Google Maps no pudo cargarse. Se activará el mapa alternativo (OpenStreetMap).");
        pasarAOpenStreetMap();
    };
    document.head.appendChild(script);
}

// Cambia al mapa OpenStreetMap y marca el botón correspondiente
function pasarAOpenStreetMap() {
    modoActual = 'osm';
    document.getElementById('btnModoOSM').classList.add('active');
    document.getElementById('btnModoGoogle').classList.remove('active');
    iniciarLeaflet();
}
// Google llama a esta función si la API Key es inválida
window.gm_authFailure = pasarAOpenStreetMap;

function actualizarCoordenadas(lat, lng) {
    latActual = lat;
    lngActual = lng;
    document.getElementById('latitud').value = lat.toFixed(7);
    document.getElementById('longitud').value = lng.toFixed(7);
    document.getElementById('coordsTexto').innerHTML = `Lat: <b>${lat.toFixed(6)}</b>, Lng: <b>${lng.toFixed(6)}</b>`;
}

// Mueve el marcador del mapa activo (Google u OpenStreetMap) y guarda las coordenadas
function moverMarcador(lat, lng) {
    actualizarCoordenadas(lat, lng);
    if (modoActual === 'google' && googleMap && googleMarker) {
        googleMap.setCenter({ lat: lat, lng: lng });
        googleMap.setZoom(17);
        googleMarker.setPosition({ lat: lat, lng: lng });
    } else if (leafletMap && leafletMarker) {
        leafletMap.setView([lat, lng], 17);
        leafletMarker.setLatLng([lat, lng]);
    }
}

// Búsqueda en OpenStreetMap usando SOLO el campo Dirección
document.getElementById('btnBuscarDir').addEventListener('click', function () {
    const dir = document.getElementById('campoDir').value.trim();
    if (!dir) {
        alert('Por favor escribe la dirección a georreferenciar.');
        return;
    }
    if (modoActual !== 'osm') pasarAOpenStreetMap();
    buscarConNominatim(dir);
});

// Quita símbolos de la nomenclatura colombiana que confunden al buscador (#, No., N°)
function limpiarDireccion(texto) {
    return texto.replace(/#|N[°º]\.?|No\./gi, ' ').replace(/\s+/g, ' ').trim();
}

async function buscarConNominatim(dir) {
    const completa = limpiarDireccion(dir);
    const soloVia  = limpiarDireccion(dir.split(/#|N[°º]\.?|No\./i)[0]);   // ej: "Calle 5" de "Calle 5 # 20-10"
    const intentos = [completa];
    if (soloVia && soloVia !== completa) intentos.push(soloVia);

    try {
        for (let i = 0; i < intentos.length; i++) {
            const q = `${intentos[i]}, Cali, Valle del Cauca, Colombia`;
            // viewbox = área de Cali (zona urbana y corregimientos); bounded=1 descarta resultados de otras ciudades
            const url = `https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(q)}`
                      + `&format=json&limit=1&countrycodes=co&viewbox=-76.75,3.60,-76.40,3.25&bounded=1`;
            const data = await (await fetch(url)).json();
            if (data && data.length > 0) {
                moverMarcador(parseFloat(data[0].lat), parseFloat(data[0].lon));
                if (i > 0) {
                    alert('Ubicación aproximada: se encontró la vía, pero no el número exacto. Arrastra el marcador hasta el predio.');
                }
                return;
            }
        }
        alert('Dirección no encontrada automáticamente. Revisa la dirección o ubica el predio haciendo clic en el mapa.');
    } catch (err) {
        alert('Error conectando con OpenStreetMap. Revisa la conexión a internet.');
    }
}

function geocodificarGoogleInversa(lat, lng) {
    if (!geocoderGoogle) return;
    geocoderGoogle.geocode({ location: { lat: lat, lng: lng } }, function (results, status) {
        if (status === 'OK' && results[0]) {
            direccionSugerida = results[0].formatted_address;
            mostrarBotonSugerencia(direccionSugerida);
        }
    });
}

async function consultarDireccionInversa(lat, lng) {
    try {
        const resp = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`);
        const data = await resp.json();
        if (data && data.display_name) {
            direccionSugerida = data.display_name;
            mostrarBotonSugerencia(direccionSugerida);
        }
    } catch(e) {}
}

function mostrarBotonSugerencia(sug) {
    const btn = document.getElementById('btnCopiarDirDetectada');
    btn.style.display = 'inline-block';
    btn.title = `Dirección detectada: ${sug}`;
}

document.getElementById('btnCopiarDirDetectada').addEventListener('click', function () {
    if (direccionSugerida) {
        document.getElementById('campoDir').value = direccionSugerida;
        document.getElementById('campoDir').focus();
    }
});

// Modos
document.getElementById('btnModoGoogle').addEventListener('click', function () {
    this.classList.add('active');
    document.getElementById('btnModoOSM').classList.remove('active');
    modoActual = 'google';
    iniciarGoogleMaps();
});

document.getElementById('btnModoOSM').addEventListener('click', function () {
    this.classList.add('active');
    document.getElementById('btnModoGoogle').classList.remove('active');
    modoActual = 'osm';
    iniciarLeaflet();
});

document.getElementById('btnCentrarCali').addEventListener('click', function () {
    actualizarCoordenadas(3.4516, -76.5320);
    if (modoActual === 'google' && googleMap && googleMarker) {
        googleMap.setCenter({ lat: 3.4516, lng: -76.5320 });
        googleMarker.setPosition({ lat: 3.4516, lng: -76.5320 });
    } else if (leafletMap && leafletMarker) {
        leafletMap.setView([3.4516, -76.5320], 14);
        leafletMarker.setLatLng([3.4516, -76.5320]);
    }
});

// Sugerencias de barrios (el usuario también puede escribir uno nuevo)
const COMUNA_HABILITADA = <?= COMUNA_HABILITADA ? 'true' : 'false' ?>;
let barriosConocidos = [];

function cargarBarrios(cId) {
    const lista = document.getElementById('listaBarrios');
    lista.innerHTML = '';
    barriosConocidos = [];
    actualizarAyudaBarrio();
    if (COMUNA_HABILITADA && !cId) return;
    const url = COMUNA_HABILITADA
        ? `<?= BASE_URL ?>/api/solicitudes.php?accion=barrios&id_comuna=${cId}`
        : `<?= BASE_URL ?>/api/solicitudes.php?accion=barrios_todos`;
    fetch(url)
        .then(r => r.json())
        .then(d => {
            if (d.success && d.barrios) {
                d.barrios.forEach(b => {
                    const opt = document.createElement('option');
                    opt.value = b.nombre_barrio;
                    lista.appendChild(opt);
                    barriosConocidos.push(b.nombre_barrio.toLowerCase());
                });
            }
            actualizarAyudaBarrio();
        });
}

function actualizarAyudaBarrio() {
    const ayuda = document.getElementById('ayudaBarrio');
    const valor = document.getElementById('inputBarrio').value.trim().toLowerCase();
    if (valor.length >= 2 && !barriosConocidos.includes(valor)) {
        ayuda.className = 'text-primary';
        ayuda.innerHTML = '<i class="bi bi-plus-circle me-1"></i>Barrio nuevo: se registrará al radicar.';
    } else {
        ayuda.className = 'text-muted';
        ayuda.textContent = 'Elija una sugerencia o escriba el barrio si no aparece.';
    }
}

document.getElementById('selectComuna').addEventListener('change', function () {
    cargarBarrios(this.value);
});
document.getElementById('inputBarrio').addEventListener('input', actualizarAyudaBarrio);

// Cargar sugerencias al abrir: todos los barrios (comuna deshabilitada) o los de la comuna elegida
if (!COMUNA_HABILITADA || document.getElementById('selectComuna').value) {
    cargarBarrios(document.getElementById('selectComuna').value);
}

// Inicializar por defecto en OpenStreetMap
modoActual = 'osm';
iniciarLeaflet();
</script>
</body>
</html>
