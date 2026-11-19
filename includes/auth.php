<?php
// ============================================================
// ARCHIVO: includes/auth.php
// DESCRIPCIÓN: Clase Auth — Autenticación, sesión y control de acceso
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

class Auth {
    private ?PDO $db = null;

    public function __construct() {
        try {
            $this->db = Database::getInstance()->getConnection();
        } catch (Throwable $e) {
            $this->db = null;
        }
        $this->iniciarSesion();
    }

    private function iniciarSesion(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path'     => '/',
                'secure'   => false,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public function login(string $username, string $password): array {
        if ($this->db === null) {
            return ['success' => false, 'message' => 'No hay conexión activa con la base de datos MySQL.'];
        }

        $stmt = $this->db->prepare("
            SELECT u.id_usuario, u.nombre, u.apellido, u.email,
                   u.username, u.password_hash, u.activo,
                   u.id_punto_atencion,
                   p.id_perfil, p.nombre_perfil
            FROM usuarios_sistema u
            INNER JOIN perfiles p ON u.id_perfil = p.id_perfil
            WHERE u.username = :username
            LIMIT 1
        ");
        $stmt->execute([':username' => $username]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            return ['success' => false, 'message' => 'Usuario o contraseña incorrectos.'];
        }

        if (!$usuario['activo']) {
            return ['success' => false, 'message' => 'Tu cuenta está inactiva. Contacta al administrador.'];
        }

        if (!password_verify($password, $usuario['password_hash'])) {
            $this->registrarAuditoria(null, 'LOGIN_FALLIDO', 'usuarios_sistema', null);
            return ['success' => false, 'message' => 'Usuario o contraseña incorrectos.'];
        }

        $_SESSION['id_usuario']       = $usuario['id_usuario'];
        $_SESSION['nombre']           = $usuario['nombre'];
        $_SESSION['apellido']         = $usuario['apellido'];
        $_SESSION['username']         = $usuario['username'];
        $_SESSION['perfil']           = $usuario['nombre_perfil'];
        $_SESSION['id_perfil']        = $usuario['id_perfil'];
        $_SESSION['id_punto']         = $usuario['id_punto_atencion'];
        $_SESSION['login_time']       = time();
        $_SESSION['csrf_token']       = bin2hex(random_bytes(32));

        session_regenerate_id(true);

        $upd = $this->db->prepare("UPDATE usuarios_sistema SET ultimo_login = NOW() WHERE id_usuario = :id");
        $upd->execute([':id' => $usuario['id_usuario']]);

        $this->registrarAuditoria($usuario['id_usuario'], 'LOGIN_EXITOSO', 'usuarios_sistema', $usuario['id_usuario']);

        return ['success' => true, 'perfil' => $usuario['nombre_perfil']];
    }

    public function logout(): void {
        $id = $_SESSION['id_usuario'] ?? null;
        if ($id) {
            $this->registrarAuditoria($id, 'LOGOUT', 'usuarios_sistema', $id);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        session_destroy();
    }

    public function estaAutenticado(): bool {
        if (!isset($_SESSION['id_usuario'], $_SESSION['login_time'])) {
            return false;
        }
        if ((time() - $_SESSION['login_time']) > SESSION_LIFETIME) {
            $this->logout();
            return false;
        }
        return true;
    }

    public function requierePerfil(string ...$perfiles): void {
        if (!$this->estaAutenticado()) {
            header('Location: ' . BASE_URL . '/modules/auth/login.php?msg=session_expired');
            exit;
        }
        if (!empty($perfiles) && !in_array($_SESSION['perfil'], $perfiles, true)) {
            http_response_code(403);
            include INCLUDES_PATH . '/403.php';
            exit;
        }
    }

    public function getUsuarioActual(): array {
        return [
            'id'       => $_SESSION['id_usuario'] ?? null,
            'nombre'   => $_SESSION['nombre'] ?? '',
            'apellido' => $_SESSION['apellido'] ?? '',
            'username' => $_SESSION['username'] ?? '',
            'perfil'   => $_SESSION['perfil'] ?? '',
            'id_punto' => $_SESSION['id_punto'] ?? null,
        ];
    }

    public function verificarCSRF(string $token): bool {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    public function redirigirPorPerfil(): void {
        $rutas = [
            'administrador' => BASE_URL . '/modules/admin/dashboard.php',
            'ventanilla'    => BASE_URL . '/modules/ventanilla/index.php',
            'enrutador'     => BASE_URL . '/modules/enrutador/index.php',
            'encuestador'   => BASE_URL . '/modules/encuestador/hoja_ruta.php',
            'calidad'       => BASE_URL . '/modules/calidad/revision.php',
        ];
        $perfil = $_SESSION['perfil'] ?? '';
        $url    = $rutas[$perfil] ?? BASE_URL . '/index.php';
        header("Location: $url");
        exit;
    }

    public function registrarAuditoria(?int $idUsuario, string $accion, string $tabla, ?int $registroId, array $anterior = [], array $nuevo = []): void {
        if ($this->db === null) return;
        try {
            $stmt = $this->db->prepare("
                INSERT INTO auditoria_log (id_usuario, accion, tabla_afectada, registro_id, datos_anteriores, datos_nuevos, ip_address, user_agent)
                VALUES (:id_usuario, :accion, :tabla, :registro_id, :anterior, :nuevo, :ip, :ua)
            ");
            $stmt->execute([
                ':id_usuario'  => $idUsuario,
                ':accion'      => $accion,
                ':tabla'       => $tabla,
                ':registro_id' => $registroId,
                ':anterior'    => $anterior ? json_encode($anterior) : null,
                ':nuevo'       => $nuevo    ? json_encode($nuevo)    : null,
                ':ip'          => $_SERVER['REMOTE_ADDR'] ?? 'CLI',
                ':ua'          => $_SERVER['HTTP_USER_AGENT'] ?? 'CLI',
            ]);
        } catch (Throwable $e) {
            error_log('[AUDIT ERROR] ' . $e->getMessage());
        }
    }
}
