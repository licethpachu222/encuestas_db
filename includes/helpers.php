<?php
// ============================================================
// ARCHIVO: includes/helpers.php
// DESCRIPCIÓN: Funciones de apoyo compartidas (mensajes flash, badges)
// ============================================================

/** Guarda un mensaje para mostrarlo después de una redirección (patrón PRG). */
function setFlash(string $tipo, string $mensaje): void {
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

/** Imprime y elimina el mensaje flash pendiente, si existe. */
function mostrarFlash(): void {
    if (empty($_SESSION['flash'])) return;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $icono = $f['tipo'] === 'success' ? 'bi-check-circle-fill' : ($f['tipo'] === 'danger' ? 'bi-x-octagon-fill' : 'bi-info-circle-fill');
    echo '<div class="alert alert-' . htmlspecialchars($f['tipo']) . ' alert-dismissible fade show d-flex align-items-center gap-2 rounded-3 shadow-sm">'
       . '<i class="bi ' . $icono . '"></i><div>' . htmlspecialchars($f['mensaje']) . '</div>'
       . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
}

/** Limpia el nombre de un barrio escrito a mano: sin espacios dobles y con mayúscula inicial. */
function normalizarNombreBarrio(string $nombre): string {
    $nombre = trim(preg_replace('/\s+/u', ' ', $nombre));
    return mb_convert_case(mb_strtolower($nombre, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

/**
 * Devuelve el id del barrio con ese nombre en la comuna indicada.
 * Si no existe, lo registra (INSERT) y devuelve el id nuevo.
 * Debe llamarse dentro de la transacción de la radicación o corrección.
 * La comparación ignora mayúsculas y tildes gracias a la intercalación utf8mb4_unicode_ci.
 */
function obtenerOCrearBarrio(PDO $db, int $idComuna, string $nombreBarrio): int {
    $nombreBarrio = normalizarNombreBarrio($nombreBarrio);

    $buscar = $db->prepare("SELECT id_barrio FROM barrios WHERE id_comuna = :c AND nombre_barrio = :n LIMIT 1");
    $buscar->execute([':c' => $idComuna, ':n' => $nombreBarrio]);
    $id = $buscar->fetchColumn();
    if ($id) return (int)$id;

    $insertar = $db->prepare("INSERT INTO barrios (nombre_barrio, id_comuna) VALUES (:n, :c)");
    $insertar->execute([':n' => $nombreBarrio, ':c' => $idComuna]);
    return (int)$db->lastInsertId();
}

/**
 * Devuelve el id de la comuna comodín "Por asignar" (numero_comuna = 0).
 * La crea la primera vez que se necesita.
 */
function idComunaPorAsignar(PDO $db): int {
    $id = $db->query("SELECT id_comuna FROM comunas WHERE numero_comuna = 0 LIMIT 1")->fetchColumn();
    if ($id) return (int)$id;
    $db->exec("INSERT INTO comunas (numero_comuna, nombre_comuna, tipo) VALUES (0, 'Por asignar', 'urbana')");
    return (int)$db->lastInsertId();
}

/**
 * Resuelve el barrio escrito por ventanilla y devuelve [id_barrio, id_comuna].
 * - Con comuna elegida: busca o crea el barrio en esa comuna.
 * - Sin comuna (campo deshabilitado): si el barrio ya existe en alguna comuna, usa esa;
 *   si no existe, lo crea en la comuna "Por asignar".
 */
function resolverBarrio(PDO $db, string $nombreBarrio, ?int $idComuna = null): array {
    if ($idComuna && $idComuna > 0) {
        return [obtenerOCrearBarrio($db, $idComuna, $nombreBarrio), $idComuna];
    }
    $nombreBarrio = normalizarNombreBarrio($nombreBarrio);
    $stmt = $db->prepare("SELECT id_barrio, id_comuna FROM barrios WHERE nombre_barrio = :n ORDER BY id_barrio LIMIT 1");
    $stmt->execute([':n' => $nombreBarrio]);
    if ($fila = $stmt->fetch()) {
        return [(int)$fila['id_barrio'], (int)$fila['id_comuna']];
    }
    $idPorAsignar = idComunaPorAsignar($db);
    return [obtenerOCrearBarrio($db, $idPorAsignar, $nombreBarrio), $idPorAsignar];
}

/** Badge Bootstrap según el estado de una solicitud. */
function badgeEstadoSolicitud(string $estado): string {
    $clases = [
        'pendiente'  => 'bg-warning text-dark',
        'asignada'   => 'bg-info text-dark',
        'en_proceso' => 'bg-primary',
        'realizada'  => 'bg-secondary',
        'revisada'   => 'bg-dark',
        'apta'       => 'bg-success',
        'no_apta'    => 'bg-danger',
    ];
    $txt = ucfirst(str_replace('_', ' ', $estado));
    return '<span class="badge ' . ($clases[$estado] ?? 'bg-light text-dark') . '">' . htmlspecialchars($txt) . '</span>';
}

/** Badge Bootstrap según el estado de una hoja de ruta. */
function badgeEstadoHoja(string $estado): string {
    $clases = [
        'generada'   => 'bg-secondary',
        'entregada'  => 'bg-info text-dark',
        'en_proceso' => 'bg-primary',
        'completada' => 'bg-success',
    ];
    $txt = ucfirst(str_replace('_', ' ', $estado));
    return '<span class="badge ' . ($clases[$estado] ?? 'bg-light text-dark') . '">' . htmlspecialchars($txt) . '</span>';
}

/** Badge según el resultado de una visita. */
function badgeResultadoVisita(string $resultado): string {
    $clases = [
        'pendiente'  => 'bg-light text-dark border',
        'realizada'  => 'bg-success',
        'ausente'    => 'bg-danger',
        'reagendada' => 'bg-warning text-dark',
    ];
    return '<span class="badge ' . ($clases[$resultado] ?? 'bg-light text-dark') . '">' . htmlspecialchars(ucfirst($resultado)) . '</span>';
}
