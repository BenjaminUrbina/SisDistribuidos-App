<?php
require_once 'includes/auth.php';
require_once 'includes/lm_services.php';

if (lm_esta_autenticado()) {
    $usuario = lm_usuario_actual();
    header('Location: ' . lm_url(lm_ruta_por_rol($usuario['rol'] ?? '')));
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? 'login';

    if ($accion === 'login') {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        try {
            $usuario = lm_autenticar_bd($email, $password);
            
            // Si el login por BD falló (pero la BD está arriba), probamos con demo
            if (!$usuario) {
                $usuario = lm_autenticar_demo($email, $password);
            }

            if ($usuario) {
                $_SESSION['lm_usuario'] = $usuario;
                header('Location: ' . lm_url(lm_ruta_por_rol($usuario['rol'])));
                exit;
            }
            $error = 'Credenciales invalidas.';
        } catch (Throwable $e) {
            // En CP, si el nodo central está caído, no permitimos login
            $error = 'Error de conexión: ' . $e->getMessage();
        }
    } elseif ($accion === 'registro') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $rut = trim($_POST['rut'] ?? '');

        try {
            if ($nombre && $email && $password) {
                // Registrar como cliente por defecto
                lm_guardar_cliente([
                    'id_cli' => 0,
                    'cliente' => $nombre,
                    'email' => $email,
                    'rut' => $rut,
                    'password' => $password,
                    'rol' => 'cliente'
                ]);
                $success = 'Cuenta creada con éxito. Ahora puedes ingresar.';
            } else {
                $error = 'Completa todos los campos obligatorios.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
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
    <style>
        .auth-toggle-btn { cursor: pointer; color: var(--lm-accent); font-weight: 600; text-decoration: underline; }
    </style>
</head>
<body>
<div class="lm-auth-shell">
    <section class="lm-auth-hero">
        <div>
            <a class="lm-auth-brand text-decoration-none" href="index.php">
                <span class="lm-logo-icon"><i class="bi bi-shop-window"></i></span>
                <span>Libre<strong>Mercado</strong></span>
            </a>

            <div class="lm-auth-copy">
                <h1>Tu puerta al comercio distribuido.</h1>
                <p>
                    Únete a la red más robusta de comercio electrónico, con transacciones seguras (ACID) 
                    y alta consistencia de datos (CP).
                </p>
            </div>

            <ul class="lm-auth-list">
                <li><i class="bi bi-check-circle-fill"></i><span>Navega por el catálogo libremente.</span></li>
                <li><i class="bi bi-check-circle-fill"></i><span>Crea tu cuenta para comprar.</span></li>
                <li><i class="bi bi-check-circle-fill"></i><span>Gestiona tus pedidos y stock en tiempo real.</span></li>
            </ul>
        </div>

        <div class="mt-4">
            <div class="lm-demo-box">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-info-circle text-warning"></i>
                    <strong>Cuentas de prueba</strong>
                </div>
                <div class="mb-1 text-muted small">Administrador: <code>admin@demo.local</code> / <code>admin123</code></div>
                <div class="mb-1 text-muted small">Vendedor: <code>vendedor@demo.local</code> / <code>vendedor123</code></div>
                <div class="small text-muted">Usa el formulario de registro para crear tu propio usuario cliente.</div>
            </div>
        </div>
    </section>

    <section class="lm-auth-panel">
        <div class="lm-auth-card">
            
            <div id="loginForm">
                <h2>Ingresar al sistema</h2>
                <p>Bienvenido de vuelta. Ingresa tus datos.</p>

                <?php if ($error && !isset($_POST['accion']) || ($error && $_POST['accion'] === 'login')): ?>
                    <div class="alert alert-danger mt-3"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success mt-3"><?= htmlspecialchars($success) ?></div>
                <?php endif; ?>

                <form method="POST" class="mt-4">
                    <input type="hidden" name="accion" value="login">
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
                <div class="mt-4 text-center text-muted small">
                    ¿No tienes cuenta? <span class="auth-toggle-btn" onclick="toggleAuth()">Regístrate aquí</span>
                </div>
            </div>

            <div id="registerForm" style="display:none;">
                <h2>Crear cuenta</h2>
                <p>Regístrate para empezar a comprar.</p>

                <?php if ($error && $_POST['accion'] === 'registro'): ?>
                    <div class="alert alert-danger mt-3"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" class="mt-4">
                    <input type="hidden" name="accion" value="registro">
                    <div class="mb-3">
                        <label class="lm-form-label">Nombre Completo</label>
                        <input type="text" name="nombre" class="lm-input form-control" placeholder="Juan Pérez" required>
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">RUT</label>
                        <input type="text" name="rut" class="lm-input form-control" placeholder="12.345.678-9">
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Correo electrónico</label>
                        <input type="email" name="email" class="lm-input form-control" placeholder="correo@ejemplo.cl" required>
                    </div>
                    <div class="mb-3">
                        <label class="lm-form-label">Contraseña</label>
                        <input type="password" name="password" class="lm-input form-control" placeholder="Crea una clave" required>
                    </div>
                    <button class="btn-lm-primary btn w-100" type="submit">
                        <i class="bi bi-person-plus me-1"></i>Crear cuenta
                    </button>
                </form>
                <div class="mt-4 text-center text-muted small">
                    ¿Ya tienes cuenta? <span class="auth-toggle-btn" onclick="toggleAuth()">Inicia sesión</span>
                </div>
            </div>

        </div>
    </section>
</div>

<script>
function toggleAuth() {
    const login = document.getElementById('loginForm');
    const register = document.getElementById('registerForm');
    if (login.style.display === 'none') {
        login.style.display = 'block';
        register.style.display = 'none';
    } else {
        login.style.display = 'none';
        register.style.display = 'block';
    }
}
<?php if (isset($_POST['accion']) && $_POST['accion'] === 'registro' && $error): ?>
    toggleAuth();
<?php endif; ?>
</script>
</body>
</html>
