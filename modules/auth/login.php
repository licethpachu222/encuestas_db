<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión — Sistema de Encuestas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root {
            --color-primario: #1a3a5c;
        }
        body {
            background: linear-gradient(135deg, var(--color-primario) 0%, #2c5f8a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, sans-serif;
            padding: 1rem;
        }
        .login-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            width: 100%;
            max-width: 440px;
        }
        .login-header {
            background: var(--color-primario);
            color: #fff;
            padding: 2rem;
            text-align: center;
        }
        .login-header .logo-icon {
            font-size: 3rem;
            color: #64b5f6;
            margin-bottom: 0.5rem;
        }
        .login-body {
            padding: 2rem;
        }
        .btn-login {
            background: var(--color-primario);
            border: none;
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 1rem;
            font-weight: 600;
        }
        .btn-login:hover { background: #2c5f8a; }
    </style>
</head>
<body>

<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once INCLUDES_PATH . '/auth.php';

$auth = new Auth();

if ($auth->estaAutenticado()) {
    $auth->redirigirPorPerfil();
}

$error   = '';
$success = '';

$msg = $_GET['msg'] ?? '';
if ($msg === 'session_expired') $error   = 'Tu sesión ha expirado por inactividad. Inicia sesión nuevamente.';
if ($msg === 'logout_ok')       $success = 'Sesión cerrada exitosamente.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Por favor complete todos los campos.';
    } else {
        $resultado = $auth->login($username, $password);
        if ($resultado['success']) {
            $auth->redirigirPorPerfil();
        } else {
            $error = $resultado['message'];
        }
    }
}
?>

<div class="login-card">
    <div class="login-header">
        <div class="logo-icon"><i class="bi bi-clipboard-data-fill"></i></div>
        <h4 class="fw-bold mb-1">Sistema de Encuestas</h4>
        <small class="text-white-50">Empresa Encuestadora de Cali — Ingreso al Sistema</small>
    </div>

    <div class="login-body">
        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 py-2" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <small><?= htmlspecialchars($error) ?></small>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success d-flex align-items-center gap-2 py-2" role="alert">
                <i class="bi bi-check-circle-fill"></i>
                <small><?= htmlspecialchars($success) ?></small>
            </div>
        <?php endif; ?>

        <form method="POST" action="" id="formLogin">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <div class="form-floating mb-3">
                <input type="text" class="form-control" id="username" name="username"
                       placeholder="Usuario" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                <label for="username"><i class="bi bi-person-fill me-1"></i>Nombre de Usuario</label>
            </div>

            <div class="form-floating mb-4 position-relative">
                <input type="password" class="form-control" id="password" name="password"
                       placeholder="Contraseña" required>
                <label for="password"><i class="bi bi-lock-fill me-1"></i>Contraseña</label>
                <button type="button" class="btn btn-link position-absolute top-50 end-0 translate-middle-y pe-3 text-secondary"
                        id="togglePassword" tabindex="-1">
                    <i class="bi bi-eye-fill" id="eyeIcon"></i>
                </button>
            </div>

            <button type="submit" class="btn btn-login btn-primary w-100 text-white" id="btnLogin">
                <i class="bi bi-box-arrow-in-right me-2"></i>Iniciar Sesión
            </button>
        </form>
        <div class="cred-box mt-4">
            <div class="fw-bold mb-1 text-dark"><i class="bi bi-key-fill text-warning me-1"></i>Credenciales de Demostración:</div>
            <div class="mt-1 d-flex flex-wrap gap-1">
                <span class="badge bg-danger">admin</span>
                <span class="badge bg-primary">ventanilla</span>
                <span class="badge bg-success">enrutador</span>
                <span class="badge bg-warning text-dark">encuestador</span>
                <span class="badge bg-info text-dark">calidad</span>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    const pwd  = document.getElementById('password');
    const icon = document.getElementById('eyeIcon');
    if (pwd.type === 'password') {
        pwd.type = 'text';
        icon.classList.replace('bi-eye-fill', 'bi-eye-slash-fill');
    } else {
        pwd.type = 'password';
        icon.classList.replace('bi-eye-slash-fill', 'bi-eye-fill');
    }
});
</script>
</body>
</html>
