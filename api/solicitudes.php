<?php
// ============================================================
// ARCHIVO: api/solicitudes.php
// DESCRIPCIÓN: Endpoints AJAX para Ventanilla y Enrutador
// ============================================================
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/config.php';
require_once INCLUDES_PATH . '/auth.php';

$auth = new Auth();
if (!$auth->estaAutenticado()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autenticado']);
    exit;
}

$db     = Database::getInstance()->getConnection();
$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

switch ($accion) {
    case 'barrios':
        $idComuna = (int)($_GET['id_comuna'] ?? 0);
        $stmt = $db->prepare("SELECT id_barrio, nombre_barrio FROM barrios WHERE id_comuna = :id ORDER BY nombre_barrio ASC");
        $stmt->execute([':id' => $idComuna]);
        echo json_encode(['success' => true, 'barrios' => $stmt->fetchAll()]);
        break;

    case 'barrios_todos':
        // Sugerencias de todos los barrios registrados (se usa cuando el campo Comuna está deshabilitado)
        $stmt = $db->query("SELECT MIN(id_barrio) AS id_barrio, nombre_barrio FROM barrios GROUP BY nombre_barrio ORDER BY nombre_barrio ASC");
        echo json_encode(['success' => true, 'barrios' => $stmt->fetchAll()]);
        break;

    case 'ciudadano':
        $tipo = $_GET['tipo'] ?? '';
        $doc  = trim($_GET['doc']  ?? '');
        $stmt = $db->prepare("SELECT * FROM ciudadanos WHERE numero_documento = :doc AND tipo_documento = :tipo LIMIT 1");
        $stmt->execute([':doc' => $doc, ':tipo' => $tipo]);
        $c = $stmt->fetch();
        echo json_encode(['success' => (bool)$c, 'ciudadano' => $c]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);
}
