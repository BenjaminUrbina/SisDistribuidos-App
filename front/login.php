<?php
require_once 'includes/auth.php';

if (lm_esta_autenticado()) {
    $usuario = lm_usuario_actual();
    header('Location: ' . lm_ruta_por_rol($usuario['rol'] ?? ''));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $usuario = lm_autenticar_demo($email, $password);
    if ($usuario) {
        $_SESSION['lm_usuario'] = $usuario;
        header('Location: ' . lm_ruta_por_rol($usuario['rol']));
        exit;
    }

    $error = 'Credenciales invalidas. Usa una cuenta demo o conecta tu autenticacion real.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Libre Mercado &mdash; Ingreso</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="lm-auth-shell">
    <section class="lm-auth-hero">
        <div>
            <a class="lm-auth-brand text-decoration-none" href="login.php">
                <span class="lm-logo-icon"><i class="bi bi-shop-window"></i></span>
                <span>Libre<strong>Mercado</strong></span>
            </a>

            <div class="lm-auth-copy">
                <h1>Acceso por rol para clientes y vendedores.</h1>
                <p>
                    La pantalla queda lista para conectar autenticación real, control de permisos y nodos de base de datos
                    cuando el backend esté disponible.
                </p>
            </div>

            <ul class="lm-auth-list">
                <li><i class="bi bi-bag-check"></i><span>El cliente compra productos y sigue sus pedidos.</span></li>
                <li><i class="bi bi-shop"></i><span>El vendedor crea items y registra reposición a proveedores.</span></li>
                <li><i class="bi bi-diagram-3"></i><span>La estructura queda preparada para conectar los nodos del sistema distribuido.</span></li>
            </ul>
        </div>

        <div class="mt-4">
            <div class="lm-demo-box">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-info-circle text-warning"></i>
                    <strong>Credenciales demo</strong>
                </div>
                <div class="small text-muted mb-1">Cliente</div>
                <div class="mb-2"><code>cliente@demo.local</code> / <code>cliente123</code></div>
                <div class="small text-muted mb-1">Vendedor</div>
                <div><code>vendedor@demo.local</code> / <code>vendedor123</code></div>
            </div>
        </div>
    </section>

    <section class="lm-auth-panel">
        <div class="lm-auth-card">
            <h2>Ingresar al sistema</h2>
            <p>Usa una cuenta demo mientras conectas la base real.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger mt-4 mb-0">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="mt-4">
                <div class="mb-3">
                    <label class="lm-form-label">Correo electrónico</label>
                    <input type="email" name="email" class="lm-input form-control" placeholder="correo@ejemplo.cl" required>
                </div>
                <div class="mb-3">
                    <label class="lm-form-label">Contraseña</label>
                    <input type="password" name="password" class="lm-input form-control" placeholder="Tu contraseña" required>
                </div>
                <button class="btn-lm-primary btn w-100" type="submit">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Entrar
                </button>
            </form>

            <div class="mt-4 p-3 rounded-3" style="background: var(--lm-surface2); border: 1px solid var(--lm-border);">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="lm-pulse-dot online"></span>
                    <strong class="small">Listo para conectar autenticación real</strong>
                </div>
                <div class="small text-muted">
                    Reemplaza las credenciales demo por validación contra tu tabla <code>clientes</code> cuando conectes la base.
                </div>
            </div>
        </div>
    </section>
</div>
</body>
</html>
