<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>403 - Acceso Denegado</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f0f4f8; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .card-403 { max-width: 480px; border-radius: 14px; border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
<div class="card card-403 bg-white p-4 text-center">
    <div class="text-danger mb-3"><i class="bi bi-shield-lock-fill" style="font-size: 4rem;"></i></div>
    <h3 class="fw-bold text-dark">403 - Acceso Restringido</h3>
    <p class="text-muted">No posees los privilegios ni el perfil requerido para acceder a este módulo.</p>
    <div class="mt-3">
        <a href="javascript:history.back()" class="btn btn-outline-secondary me-2">Regresar</a>
        <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">Ir a mi Panel</a>
    </div>
</div>
</body>
</html>
