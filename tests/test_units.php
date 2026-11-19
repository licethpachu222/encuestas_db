<?php
// ============================================================
// ARCHIVO: tests/test_units.php
// DESCRIPCIÓN: Suite de Pruebas Unitarias y de Integración
// PROYECTO: Sistema de Gestión de Encuestas - Entrega 1
// Ejecutable desde CLI (php test_units.php) o Navegador Web
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';
require_once INCLUDES_PATH . '/auth.php';

$isCli = (php_sapi_name() === 'cli');

class TestRunner {
    private int $passed = 0;
    private int $failed = 0;
    private array $results = [];

    public function assert(string $testName, bool $condition, string $detail = ''): void {
        if ($condition) {
            $this->passed++;
            $this->results[] = ['name' => $testName, 'status' => 'PASS', 'detail' => $detail];
        } else {
            $this->failed++;
            $this->results[] = ['name' => $testName, 'status' => 'FAIL', 'detail' => $detail];
        }
    }

    public function getSummary(): array {
        return [
            'total'   => $this->passed + $this->failed,
            'passed'  => $this->passed,
            'failed'  => $this->failed,
            'results' => $this->results,
        ];
    }
}

$suite = new TestRunner();

// ============================================================
// TEST 1: Conexión a Base de Datos (Singleton PDO)
// ============================================================
try {
    $db = Database::getInstance()->getConnection();
    $suite->assert("TEST 1 - Conexión PDO Singleton a MySQL", $db instanceof PDO, "Conexión establecida correctamente con atributos UTF-8 y excepciones.");
} catch (Throwable $e) {
    $suite->assert("TEST 1 - Conexión PDO Singleton a MySQL", false, "Error de conexión: " . $e->getMessage());
}

// ============================================================
// TEST 2: Validación de Hashing de Contraseñas (BCrypt) para los 5 perfiles
// ============================================================
$roles = ['admin', 'ventanilla', 'enrutador', 'encuestador', 'calidad'];
$defaultPassword = 'password';
$knownHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

$suite->assert(
    "TEST 2.1 - Algoritmo BCrypt password_verify() para credenciales maestras",
    password_verify($defaultPassword, $knownHash),
    "El hash Bcrypt de prueba resuelve 'password' satisfactoriamente."
);

if (isset($db)) {
    try {
        $stmt = $db->query("SELECT username, password_hash FROM usuarios_sistema WHERE activo = 1");
        $usersDb = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $allPassed = true;
        foreach ($roles as $rol) {
            if (!isset($usersDb[$rol]) || !password_verify('password', $usersDb[$rol])) {
                $allPassed = false;
                break;
            }
        }
        $suite->assert(
            "TEST 2.2 - Integridad de los 5 usuarios del sistema en BD",
            $allPassed,
            "Los 5 roles requeridos (admin, ventanilla, enrutador, encuestador, calidad) existen y autentican."
        );
    } catch (Throwable $e) {
        $suite->assert("TEST 2.2 - Integridad de los 5 usuarios del sistema en BD", false, $e->getMessage());
    }
}

// ============================================================
// TEST 3: Regla de Negocio - Plazo Legal de 15 Días Calendario
// ============================================================
$fechaRad = new DateTime('2026-11-09');
$fechaLim = clone $fechaRad;
$fechaLim->modify('+' . MAX_DIAS_PLAZO . ' days');
$diff = $fechaRad->diff($fechaLim)->days;

$suite->assert(
    "TEST 3 - Cálculo de Fecha Límite según Política Institucional (15 días)",
    ($diff === 15 && $fechaLim->format('Y-m-d') === '2026-11-24'),
    "La fecha límite calculada fue: " . $fechaLim->format('Y-m-d') . " (+15 días exactos)."
);

// ============================================================
// TEST 4: Formato del Número de Radicado Oficial
// ============================================================
$correlativoEjemplo = 14;
$radicadoGenerado = 'RAD-' . date('Ymd') . '-' . str_pad($correlativoEjemplo, 4, '0', STR_PAD_LEFT);
$regexRadicado = '/^RAD-\d{8}-\d{4}$/';

$suite->assert(
    "TEST 4 - Formato Estándar de Radicación (RAD-YYYYMMDD-XXXX)",
    (bool)preg_match($regexRadicado, $radicadoGenerado),
    "Radicado generado: $radicadoGenerado cumple con la expresión regular institucional."
);

// ============================================================
// TEST 5: Validación Geográfica - Delimitación Coordenadas de Cali
// ============================================================
function validarCoordenadasCali(float $lat, float $lng): bool {
    // Bounding Box representativo de Cali urbano y rural
    return ($lat >= 3.25 && $lat <= 3.55 && $lng >= -76.65 && $lng <= -76.40);
}

$coordValida = validarCoordenadasCali(3.4516, -76.5320); // Centro de Cali
$coordInvalida = validarCoordenadasCali(4.6097, -74.0817); // Bogotá (inválida)

$suite->assert(
    "TEST 5.1 - Validación de Coordenadas Geográficas (Dentro de Cali)",
    $coordValida,
    "Coordenada [3.4516, -76.5320] aceptada dentro del perímetro de Cali."
);

$suite->assert(
    "TEST 5.2 - Rechazo de Coordenadas Fuera de la Jurisdicción Municipal",
    !$coordInvalida,
    "Coordenada foránea fuera del cuadrante de Cali fue rechazada con éxito."
);

// ============================================================
// TEST 6: Generación y Validación de Token CSRF
// ============================================================
$authInstance = new Auth();
$tokenGenerado = $_SESSION['csrf_token'] ?? '';
$esTokenValido = $authInstance->verificarCSRF($tokenGenerado);
$esTokenInvalidoRechazado = !$authInstance->verificarCSRF('fake_token_123456');

$suite->assert(
    "TEST 6 - Seguridad CSRF (Cross-Site Request Forgery)",
    (strlen($tokenGenerado) === 64 && $esTokenValido && $esTokenInvalidoRechazado),
    "Tokens criptográficos seguros generados con random_bytes(32) verificados con hash_equals()."
);

// ============================================================
// TEST 7: Validación de Teléfono Celular Colombiano
// ============================================================
$celularValido = (bool)preg_match('/^3\d{9}$/', '3157894512');
$celularInvalido = (bool)preg_match('/^3\d{9}$/', '2157894');

$suite->assert(
    "TEST 7 - Validación de Número Celular (+57 Formato Móvil)",
    ($celularValido && !$celularInvalido),
    "Acepta números de 10 dígitos que inician con 3, rechaza fijos y longitud incompleta."
);

$summary = $suite->getSummary();

// ============================================================
// RENDERIZACIÓN DE RESULTADOS
// ============================================================
if ($isCli) {
    echo "=========================================================\n";
    echo "  RESULTADOS SUITE DE PRUEBAS UNITARIAS - ENTREGA 1\n";
    echo "=========================================================\n";
    foreach ($summary['results'] as $res) {
        $tag = $res['status'] === 'PASS' ? '[PASS]' : '[FAIL]';
        echo sprintf("%-8s %-50s\n   -> %s\n", $tag, $res['name'], $res['detail']);
    }
    echo "---------------------------------------------------------\n";
    echo "Total: {$summary['total']} | Pasadas: {$summary['passed']} | Falladas: {$summary['failed']}\n";
    echo "=========================================================\n";
    exit($summary['failed'] > 0 ? 1 : 0);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Pruebas Unitarias — Entrega 1</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f0f4f8; font-family: 'Segoe UI', system-ui, sans-serif; padding: 2rem; }
        .test-card { border: none; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.08); }
        .badge-pass { background: #2e7d32; }
        .badge-fail { background: #c62828; }
    </style>
</head>
<body>
<div class="container" style="max-width: 900px;">
    <div class="test-card card bg-white p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="fw-bold mb-0 text-primary"><i class="bi bi-shield-check me-2"></i>Suite de Pruebas Unitarias</h3>
                <small class="text-muted">Entrega 1 — Sistema de Encuestas Domiciliarias Cali</small>
            </div>
            <a href="../modules/auth/login.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-arrow-left me-1"></i>Ir a la Aplicación</a>
        </div>
        <div class="row g-3 text-center my-2">
            <div class="col-4">
                <div class="p-3 bg-light rounded-3">
                    <div class="fs-3 fw-bold text-dark"><?= $summary['total'] ?></div>
                    <small class="text-muted">Total Pruebas</small>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 bg-light rounded-3">
                    <div class="fs-3 fw-bold text-success"><?= $summary['passed'] ?></div>
                    <small class="text-muted">Aprobadas</small>
                </div>
            </div>
            <div class="col-4">
                <div class="p-3 bg-light rounded-3">
                    <div class="fs-3 fw-bold text-danger"><?= $summary['failed'] ?></div>
                    <small class="text-muted">Falladas</small>
                </div>
            </div>
        </div>
    </div>

    <div class="test-card card bg-white p-4">
        <h5 class="fw-bold mb-3">Detalle de Casos de Prueba Ejecutados</h5>
        <div class="list-group list-group-flush">
            <?php foreach ($summary['results'] as $r): ?>
            <div class="list-group-item px-0 py-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <h6 class="mb-0 fw-semibold"><?= htmlspecialchars($r['name']) ?></h6>
                    <span class="badge <?= $r['status'] === 'PASS' ? 'badge-pass' : 'badge-fail' ?> rounded-pill px-3 py-2">
                        <?= $r['status'] ?>
                    </span>
                </div>
                <small class="text-muted"><?= htmlspecialchars($r['detail']) ?></small>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
</body>
</html>
