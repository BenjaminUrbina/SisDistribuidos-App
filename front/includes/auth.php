<?php

require_once __DIR__ . '/config.php';

function lm_usuarios_demo(): array {
    return [
        [
            'id_cli' => 1,
            'cliente' => 'Cliente Demo',
            'email' => 'cliente@demo.local',
            'password' => 'cliente123',
            'rol' => 'cliente',
        ],
        [
            'id_cli' => 2,
            'cliente' => 'Vendedor Demo',
            'email' => 'vendedor@demo.local',
            'password' => 'vendedor123',
            'rol' => 'vendedor',
        ],
        [
            'id_cli' => 0,
            'cliente' => 'Admin Sistema',
            'email' => 'admin@demo.local',
            'password' => 'admin123',
            'rol' => 'admin',
        ],
    ];
}

function lm_usuario_actual(): ?array {
    return $_SESSION['lm_usuario'] ?? null;
}

function lm_esta_autenticado(): bool {
    return lm_usuario_actual() !== null;
}

function lm_ruta_por_rol(string $rol): string {
    return match ($rol) {
        'cliente' => 'cliente.php',
        'vendedor' => 'vendedor.php',
        default => 'index.php',
    };
}

function lm_autenticar_demo(string $email, string $password): ?array {
    foreach (lm_usuarios_demo() as $usuario) {
        if (strcasecmp($usuario['email'], $email) === 0 && $usuario['password'] === $password) {
            return [
                'id_cli' => $usuario['id_cli'],
                'cliente' => $usuario['cliente'],
                'email' => $usuario['email'],
                'rol' => $usuario['rol'],
            ];
        }
    }

    return null;
}

function lm_autenticar_bd(string $email, string $password): ?array
{
    $usuario = lm_usuario_por_email($email);
    if (!$usuario || (int) ($usuario['activo'] ?? 0) !== 1) {
        return null;
    }

    $hash = (string) ($usuario['password'] ?? '');
    $valido = password_verify($password, $hash) || hash_equals($hash, $password);
    if (!$valido) {
        return null;
    }

    $cliente = lm_fetch_one('central', 'SELECT id_cli, cliente FROM clientes WHERE email = ? LIMIT 1', [$email]);

    return [
        'id_usuario' => (int) $usuario['id_usuario'],
        'id_cli' => (int) ($cliente['id_cli'] ?? 0),
        'cliente' => $cliente['cliente'] ?? $usuario['nombre'],
        'email' => $usuario['email'],
        'rol' => $usuario['rol'],
    ];
}

function lm_requiere_roles(array $roles): void {
    if (!lm_esta_autenticado()) {
        header('Location: ' . lm_url('login.php'));
        exit;
    }

    $usuario = lm_usuario_actual();
    if (!$usuario || !in_array($usuario['rol'], $roles, true)) {
        header('Location: ' . lm_url(lm_ruta_por_rol($usuario['rol'] ?? '')));
        exit;
    }
}

function lm_cerrar_sesion(): void {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function lm_seed_demo_state(): void {
    if (isset($_SESSION['lm_demo_state'])) {
        return;
    }

    $_SESSION['lm_demo_state'] = [
        'productos' => [
            ['id_prod' => 1, 'producto' => 'Notebook X13', 'precio' => 899990, 'descripcion' => 'Portatil liviana para ventas y oficina.'],
            ['id_prod' => 2, 'producto' => 'Mouse Pro', 'precio' => 14990, 'descripcion' => 'Mouse ergonomico de alta precision.'],
            ['id_prod' => 3, 'producto' => 'Teclado Mecánico', 'precio' => 39990, 'descripcion' => 'Switches tactiles y retroiluminacion.'],
            ['id_prod' => 4, 'producto' => 'Monitor 24"', 'precio' => 129990, 'descripcion' => 'Panel IPS para estaciones de trabajo.'],
            ['id_prod' => 5, 'producto' => 'Disco SSD 1TB', 'precio' => 79990, 'descripcion' => 'Almacenamiento rapido para reposicion.'],
        ],
        'sucursales' => [
            ['id_suc' => 1, 'sucursal' => 'Centro'],
            ['id_suc' => 2, 'sucursal' => 'Providencia'],
            ['id_suc' => 3, 'sucursal' => 'Maipu'],
        ],
        'stock' => [
            ['id_suc' => 1, 'id_prod' => 1, 'cantidad' => 8],
            ['id_suc' => 1, 'id_prod' => 2, 'cantidad' => 24],
            ['id_suc' => 1, 'id_prod' => 3, 'cantidad' => 11],
            ['id_suc' => 2, 'id_prod' => 1, 'cantidad' => 5],
            ['id_suc' => 2, 'id_prod' => 4, 'cantidad' => 7],
            ['id_suc' => 3, 'id_prod' => 5, 'cantidad' => 18],
        ],
        'proveedores' => [
            ['id_prov' => 1, 'proveedor' => 'TecnoSupply', 'contacto' => '+56 9 1111 2222'],
            ['id_prov' => 2, 'proveedor' => 'Distribuciones Andina', 'contacto' => '+56 9 3333 4444'],
            ['id_prov' => 3, 'proveedor' => 'CompuStock', 'contacto' => '+56 2 5555 6666'],
        ],
        'carrito' => [],
        'ventas' => [
            [
                'id_venta' => 1001,
                'cliente' => 'Cliente Demo',
                'sucursal' => 'Centro',
                'total' => 14990,
                'estado' => 'pagado',
                'fecha' => date('Y-m-d H:i', strtotime('-1 day')),
                'detalle' => [
                    ['producto' => 'Mouse Pro', 'cantidad' => 1, 'precio' => 14990],
                ],
            ],
        ],
        'compras' => [
            [
                'id_compra' => 5001,
                'proveedor' => 'TecnoSupply',
                'producto' => 'Notebook X13',
                'sucursal' => 'Centro',
                'cantidad' => 3,
                'costo' => 2550000,
                'fecha' => date('Y-m-d H:i', strtotime('-2 day')),
            ],
        ],
    ];
}

function &lm_demo_bucket(string $key): array {
    lm_seed_demo_state();

    if (!isset($_SESSION['lm_demo_state'][$key])) {
        $_SESSION['lm_demo_state'][$key] = [];
    }

    return $_SESSION['lm_demo_state'][$key];
}

function lm_indexar_por_id(array $items, string $campoId, int $id): ?array {
    foreach ($items as $item) {
        if ((int) ($item[$campoId] ?? 0) === $id) {
            return $item;
        }
    }

    return null;
}

function lm_siguiente_id(array $items, string $campoId): int {
    $max = 0;
    foreach ($items as $item) {
        $max = max($max, (int) ($item[$campoId] ?? 0));
    }

    return $max + 1;
}
