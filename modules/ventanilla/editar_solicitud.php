<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Corregir Dirección y Georreferenciación — Ventanilla</title>
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
        .geo-badge { background:#e8f5e9; border:1px solid #81c784; color:#2e7d32; border-radius:8px; padding:.5rem 1rem; }
        .engine-toggle { border-radius: 20px; font-size: 0.8rem; }
    </style>
</head>
<body>

<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once INCLUDES_PATH . '/auth.php';
require_once INCLUDES_PATH . '/helpers.php';

$auth = new Auth();
$auth->requierePerfil('ventanilla', 'administrador', 'enrutador');

$db      = Database::getInstance()->getConnection();
$usuario = $auth->getUsuarioActual();

$idSolicitud = (int)($_GET['id'] ?? 0);
if ($idSolicitud <= 0) {
    header('Location: index.php');
    exit;
}

// Cargar la solicitud con sus datos actuales
$stmt = $db->prepare("
    SELECT s.*, c.id_ciudadano, c.tipo_documento, c.numero_documento,
           c.nombres, c.apellidos, c.celular, c.direccion AS dir_ciudadano,
           c.id_barrio, c.id_comuna,
           b.nombre_barrio, com.numero_comuna, com.nombre_comuna
    FROM solicitudes_encuesta s
    INNER JOIN ciudadanos c ON s.id_ciudadano = c.id_ciudadano
    LEFT  JOIN barrios b    ON c.id_barrio    = b.id_barrio
    LEFT  JOIN comunas com  ON c.id_comuna    = com.id_comuna
    WHERE s.id_solicitud = :id
    LIMIT 1
");
$stmt->execute([':id' => $idSolicitud]);
$solicitud = $stmt->fetch();

if (!$solicitud) {
    echo "<div class='container py-5'><div class='alert alert-danger'>Solicitud no encontrada. <a href='index.php'>Volver</a></div></div>";
    exit;
}

$comunas = $db->query("SELECT id_comuna, numero_comuna, nombre_comuna, tipo FROM comunas ORDER BY numero_comuna")->fetchAll();

$errores = [];
$exito = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->verificarCSRF($_POST['csrf_token'] ?? '')) {
        $errores[] = 'Token de seguridad inválido.';
    } else {
        $direccionNueva = trim($_POST['direccion'] ?? '');
        $idComunaNueva  = COMUNA_HABILITADA ? (int)($_POST['id_comuna'] ?? 0) : 0;
        $nombreBarrio   = normalizarNombreBarrio($_POST['nombre_barrio'] ?? '');
        $celularNuevo   = trim($_POST['celular'] ?? '');
        $latNueva       = !empty($_POST['latitud']) ? (float)$_POST['latitud'] : null;
        $lngNueva       = !empty($_POST['longitud']) ? (float)$_POST['longitud'] : null;
        $motivo         = trim($_POST['motivo_cambio'] ?? 'Corrección de dirección / ubicación');

        if (empty($direccionNueva)) $errores[] = 'La dirección domiciliaria no puede estar vacía.';
        if (COMUNA_HABILITADA && $idComunaNueva <= 0) $errores[] = 'Seleccione una comuna válida.';
        $largoBarrio = mb_strlen($nombreBarrio, 'UTF-8');
        if ($largoBarrio < 2 || $largoBarrio > 100) $errores[] = 'Escriba o seleccione el barrio (entre 2 y 100 caracteres).';

        if (empty($errores)) {
            try {
                $db->beginTransaction();

                // 0. Barrio: se usa el existente o se registra uno nuevo.
                //    Si la comuna está deshabilitada, se toma la del barrio o queda "Por asignar".
                [$idBarrioNuevo, $idComunaNueva] = resolverBarrio($db, $nombreBarrio, $idComunaNueva ?: null);

                // 1. Actualizar datos en ciudadanos
                $updCiu = $db->prepare("
                    UPDATE ciudadanos SET
                        direccion = :dir, id_comuna = :comuna, id_barrio = :barrio, celular = :cel
                    WHERE id_ciudadano = :id
                ");
                $updCiu->execute([
                    ':dir'    => $direccionNueva,
                    ':comuna' => $idComunaNueva,
                    ':barrio' => $idBarrioNuevo,
                    ':cel'    => $celularNuevo ?: $solicitud['celular'],
                    ':id'     => $solicitud['id_ciudadano'],
                ]);

                // 2. Actualizar coordenadas en la solicitud
                $updSol = $db->prepare("
                    UPDATE solicitudes_encuesta SET
                        latitud = :lat, longitud = :lng,
                        observaciones = CONCAT(IFNULL(observaciones, ''), '\n[', NOW(), ' - Dir actualizada: ', :dir, ' Motivo: ', :motivo, ']')
                    WHERE id_solicitud = :id
                ");
                $updSol->execute([
                    ':lat'    => $latNueva,
                    ':lng'    => $lngNueva,
                    ':dir'    => $direccionNueva,
                    ':motivo' => $motivo,
                    ':id'     => $idSolicitud,
                ]);

                $db->commit();

                $auth->registrarAuditoria(
                    $usuario['id'], 'CORRECCION_DIRECCION', 'solicitudes_encuesta',
                    $idSolicitud,
                    ['dir_anterior' => $solicitud['dir_ciudadano'], 'lat_ant' => $solicitud['latitud'], 'lng_ant' => $solicitud['longitud']],
                    ['dir_nueva' => $direccionNueva, 'lat_nueva' => $latNueva, 'lng_nueva' => $lngNueva]
                );

                $exito = '¡Dirección y georreferenciación corregidas y guardadas exitosamente!';

                // Refrescar datos en memoria
                $solicitud['dir_ciudadano'] = $direccionNueva;
                $solicitud['id_comuna']     = $idComunaNueva;
                $solicitud['id_barrio']     = $idBarrioNuevo;
                $solicitud['nombre_barrio'] = $nombreBarrio;
                $solicitud['latitud']       = $latNueva;
                $solicitud['longitud']      = $lngNueva;
                $solicitud['celular']       = $celularNuevo;

            } catch (Throwable $e) {
                $db->rollBack();
                $errores[] = 'Error al actualizar: ' . $e->getMessage();
            }
        }
    }
}
?>

<?php include INCLUDES_PATH . '/header.php'; ?>

<div class="container-fluid py-4 px-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
            <div>
                <h4 class="fw-bold mb-0 text-dark">
                    <i class="bi bi-pencil-square text-warning me-2"></i>Corregir Dirección / Georreferenciación
                </h4>
                <small class="text-muted">Radicado: <strong class="text-primary"><?= htmlspecialchars($solicitud['numero_radicado']) ?></strong> — Ciudadano: <?= htmlspecialchars($solicitud['nombres'] . ' ' . $solicitud['apellidos']) ?></small>
            </div>
        </div>
        <a href="index.php" class="btn btn-sm btn-outline-primary"><i class="bi bi-journal-text me-1"></i>Ir a Bitácora</a>
    </div>

    <?php if ($exito): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 rounded-3 shadow-sm mb-4">
            <i class="bi bi-check-circle-fill fs-4"></i>
            <div><?= htmlspecialchars($exito) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errores)): ?>
        <div class="alert alert-danger rounded-3 mb-4">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Errores al guardar:</strong>
            <ul class="mb-0 mt-1">
                <?php foreach ($errores as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" id="formEdicion">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="row g-4">
            <!-- Columna Izquierda: Formulario Manual y Editable -->
            <div class="col-lg-5">
                <div class="card card-form h-100">
                    <div class="card-body p-4">
                        <div class="section-label"><i class="bi bi-geo-alt-fill me-1"></i>Dirección Manual y Editable</div>

                        <div class="alert alert-warning py-2 small mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            <strong>Edición 100% Manual:</strong> Puedes modificar libremente el texto de la dirección (añadir número de casa, apto, manzana o corregir ortografía).
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Dirección de Residencia *</label>
                            <div class="input-group">
                                <input type="text" name="direccion" id="campoDir" class="form-control"
                                       value="<?= htmlspecialchars($solicitud['dir_ciudadano'] ?? '') ?>"
                                       placeholder="Ej: Calle 5 # 20-10 Casa 4" required>
                                <button type="button" class="btn btn-primary" id="btnBuscarEnMapa" title="Ubicar esta dirección escrita en el mapa">
                                    <i class="bi bi-search me-1"></i>Ubicar
                                </button>
                            </div>
                            <small class="text-muted">Escribe la dirección correcta y pulsa <strong>Ubicar</strong>: el mapa busca en OpenStreetMap con la dirección.</small>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Comuna / Corregimiento <?= COMUNA_HABILITADA ? '*' : '' ?></label>
                                <select name="id_comuna" id="selectComuna" class="form-select form-select-sm" <?= COMUNA_HABILITADA ? 'required' : 'disabled' ?>>
                                    <option value="">— Seleccionar —</option>
                                    <?php foreach ($comunas as $c): ?>
                                        <option value="<?= $c['id_comuna'] ?>" <?= ($solicitud['id_comuna'] == $c['id_comuna']) ? 'selected' : '' ?>>
                                            Comuna <?= $c['numero_comuna'] ?> (<?= htmlspecialchars($c['nombre_comuna']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (!COMUNA_HABILITADA): ?>
                                    <small class="text-muted"><i class="bi bi-lock me-1"></i>Deshabilitado por ahora: se asigna según el barrio.</small>
                                <?php endif; ?>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Barrio *</label>
                                <input type="text" name="nombre_barrio" id="inputBarrio" class="form-control form-control-sm"
                                       list="listaBarrios" maxlength="100" autocomplete="off" required
                                       placeholder="Escriba o elija el barrio"
                                       value="<?= htmlspecialchars($_POST['nombre_barrio'] ?? ($solicitud['nombre_barrio'] ?? '')) ?>">
                                <datalist id="listaBarrios"></datalist>
                                <small class="text-muted" id="ayudaBarrio">Elija una sugerencia o escriba el barrio si no aparece.</small>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">Latitud (Editable)</label>
                                <input type="number" step="0.0000001" name="latitud" id="inputLat" class="form-control form-control-sm font-monospace"
                                       value="<?= htmlspecialchars($solicitud['latitud'] ?? '') ?>" placeholder="Ej: 3.4516">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">Longitud (Editable)</label>
                                <input type="number" step="0.0000001" name="longitud" id="inputLng" class="form-control form-control-sm font-monospace"
                                       value="<?= htmlspecialchars($solicitud['longitud'] ?? '') ?>" placeholder="Ej: -76.5320">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">Celular de Contacto</label>
                            <input type="tel" name="celular" class="form-control form-control-sm"
                                   value="<?= htmlspecialchars($solicitud['celular'] ?? '') ?>">
                        </div>

                        <div class="mb-4">
                            <label class="form-label small fw-bold">Motivo de la Corrección</label>
                            <input type="text" name="motivo_cambio" class="form-control form-control-sm"
                                   placeholder="Ej: Error tipográfico en número de casa o barrio">
                        </div>

                        <button type="submit" class="btn btn-success w-100 fw-bold py-2 shadow-sm">
                            <i class="bi bi-check2-circle me-2"></i>Guardar Corrección de Dirección
                        </button>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha: Mapa Satelital Google Maps / Leaflet con Pin Arrastrable -->
            <div class="col-lg-7">
                <div class="card card-form h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                            <div class="section-label mb-0"><i class="bi bi-map-fill me-1"></i>Georreferenciación (OpenStreetMap)</div>
                            <div class="btn-group btn-group-sm d-none"><!-- Google Maps desactivado: la georreferenciación usa OpenStreetMap -->
                                <button type="button" class="btn btn-outline-primary engine-toggle" id="btnModoGoogle">
                                    <i class="bi bi-google me-1"></i>Google Maps
                                </button>
                                <button type="button" class="btn btn-outline-secondary active engine-toggle" id="btnModoOSM">
                                    <i class="bi bi-globe-americas me-1"></i>OpenStreetMap
                                </button>
                            </div>
                        </div>

                        <p class="small text-muted mb-2">
                            Haz clic sobre el predio o arrastra el marcador rojo. Al moverlo, se actualizarán las coordenadas y podrás sincronizar la dirección.
                        </p>

                        <!-- Contenedor del Mapa -->
                        <div id="mapaContainer" class="mb-3"></div>

                        <!-- Panel de sugerencia y coordenadas detectadas -->
                        <div class="geo-badge d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <small class="fw-bold d-block"><i class="bi bi-pin-map-fill text-danger me-1"></i>Punto Fijado:</small>
                                <span id="textoCoords" class="font-monospace small">
                                    Lat: <?= $solicitud['latitud'] ?: 'Sin fijar' ?>, Lng: <?= $solicitud['longitud'] ?: 'Sin fijar' ?>
                                </span>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-success" id="btnCopiarDirDetectada" style="display:none;">
                                <i class="bi bi-arrow-left-circle me-1"></i>Usar dirección sugerida
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Scripts de Mapas: Leaflet (base) + Google Maps (API dinámica) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
// ============================================================
// CONFIGURACIÓN DUAL: GOOGLE MAPS + LEAFLET FALLBACK
// ============================================================
const GOOGLE_API_KEY = "<?= GOOGLE_MAPS_API_KEY ?>";
let latActual = <?= $solicitud['latitud'] ? (float)$solicitud['latitud'] : 3.4516 ?>;
let lngActual = <?= $solicitud['longitud'] ? (float)$solicitud['longitud'] : -76.5320 ?>;

let modoActual = 'osm'; // 'google' o 'osm'
let leafletMap = null;
let leafletMarker = null;
let googleMap = null;
let googleMarker = null;
let geocoderGoogle = null;
let direccionSugerida = '';

// Inicializar primero con Leaflet para garantizar renderizado instantáneo sin pantallas blancas
function iniciarLeaflet() {
    if (leafletMap) { leafletMap.remove(); leafletMap = null; }
    document.getElementById('mapaContainer').innerHTML = "";
    leafletMap = L.map('mapaContainer').setView([latActual, lngActual], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '© OpenStreetMap'
    }).addTo(leafletMap);

    leafletMarker = L.marker([latActual, lngActual], { draggable: true }).addTo(leafletMap);
    leafletMarker.bindPopup("<b>Ubicación del Predio</b><br>Arrastra para corregir").openPopup();

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
        zoom: 16,
        center: centro,
        mapTypeId: 'roadmap',
        mapTypeControl: true,
        streetViewControl: true
    });

    googleMarker = new google.maps.Marker({
        position: centro,
        map: googleMap,
        draggable: true,
        animation: google.maps.Animation.DROP,
        title: "Arrastra para ajustar la dirección exacta"
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
        alert("No se pudo cargar Google Maps (clave ausente o sin conexión). Se activará OpenStreetMap.");
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
    document.getElementById('inputLat').value = lat.toFixed(7);
    document.getElementById('inputLng').value = lng.toFixed(7);
    document.getElementById('textoCoords').textContent = `Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`;
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
document.getElementById('btnBuscarEnMapa').addEventListener('click', function () {
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

// Geocodificación inversa con Google Maps (de coordenadas a dirección)
function geocodificarGoogleInversa(lat, lng) {
    if (!geocoderGoogle) return;
    geocoderGoogle.geocode({ location: { lat: lat, lng: lng } }, function (results, status) {
        if (status === 'OK' && results[0]) {
            direccionSugerida = results[0].formatted_address;
            mostrarBotonSugerencia(direccionSugerida);
        }
    });
}

// Geocodificación inversa con OpenStreetMap
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

function mostrarBotonSugerencia(sugerida) {
    const btn = document.getElementById('btnCopiarDirDetectada');
    btn.style.display = 'inline-block';
    btn.title = `Dirección detectada: ${sugerida}`;
}

document.getElementById('btnCopiarDirDetectada').addEventListener('click', function () {
    if (direccionSugerida) {
        // Rellenar pero permitir edición inmediata
        document.getElementById('campoDir').value = direccionSugerida;
        document.getElementById('campoDir').focus();
    }
});

// Modos de visualización
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
        ayuda.innerHTML = '<i class="bi bi-plus-circle me-1"></i>Barrio nuevo: se registrará al guardar.';
    } else {
        ayuda.className = 'text-muted';
        ayuda.textContent = 'Elija una sugerencia o escriba el barrio si no aparece.';
    }
}

document.getElementById('selectComuna').addEventListener('change', function () {
    document.getElementById('inputBarrio').value = '';
    cargarBarrios(this.value);
});
document.getElementById('inputBarrio').addEventListener('input', actualizarAyudaBarrio);

// Cargar sugerencias al abrir: todos los barrios (comuna deshabilitada) o los de la comuna actual
if (!COMUNA_HABILITADA || document.getElementById('selectComuna').value) {
    cargarBarrios(document.getElementById('selectComuna').value);
}

// Inicializar por defecto en OpenStreetMap
modoActual = 'osm';
iniciarLeaflet();
</script>
</body>
</html>
