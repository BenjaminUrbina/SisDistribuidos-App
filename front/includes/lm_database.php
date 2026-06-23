<?php

final class LmDatabase
{
    private static bool $schemaBootstrapped = false;

    public static function nodes(): array
    {
        return [
            'central' => [
                'label' => 'Nodo Central',
                'host' => LM_DB_HOST_CENTRAL,
                'port' => LM_DB_PORT_CENTRAL,
                'db' => LM_DB_NAME_CENTRAL,
            ],
            'sucursal1' => [
                'label' => 'Sucursal La Serena',
                'host' => LM_DB_HOST_SUCURSAL1,
                'port' => LM_DB_PORT_SUCURSAL1,
                'db' => LM_DB_NAME_SUCURSAL1,
            ],
            'sucursal2' => [
                'label' => 'Sucursal Coquimbo',
                'host' => LM_DB_HOST_SUCURSAL2,
                'port' => LM_DB_PORT_SUCURSAL2,
                'db' => LM_DB_NAME_SUCURSAL2,
            ],
        ];
    }

    public static function nodeAliases(): array
    {
        return [
            'principal' => 'central',
            'central' => 'central',
            'nodo_central' => 'central',
            'sucursal_centro' => 'central',
            'centro' => 'central',
            'sucursales' => 'sucursal1',
            'sucursal1' => 'sucursal1',
            'la_serena' => 'sucursal1',
            'sucursal_la_serena' => 'sucursal1',
            'proveedores' => 'sucursal2',
            'sucursal2' => 'sucursal2',
            'coquimbo' => 'sucursal2',
            'sucursal_coquimbo' => 'sucursal2',
        ];
    }

    public static function stockNodeForSucursal(int $idSuc): string
    {
        return match ($idSuc) {
            2 => 'sucursal1',
            3 => 'sucursal2',
            default => 'central',
        };
    }

    public static function sucursalIdForNode(string $node): int
    {
        return match (self::canonicalNode($node)) {
            'sucursal1' => 2,
            'sucursal2' => 3,
            default => 0,
        };
    }

    public static function isSimulatedDown(string $node): bool
    {
        $key = self::canonicalNode($node);
        $file = sys_get_temp_dir() . "/lm_node_down_{$key}";
        return file_exists($file);
    }

    public static function simulateNodeDown(string $node, bool $down = true): void
    {
        $key = self::canonicalNode($node);
        $file = sys_get_temp_dir() . "/lm_node_down_{$key}";
        if ($down) {
            file_put_contents($file, '1');
        } else {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    public static function clearNodeSimulation(?string $node = null): void
    {
        if ($node === null) {
            foreach (self::nodes() as $key => $cfg) {
                $file = sys_get_temp_dir() . "/lm_node_down_{$key}";
                if (file_exists($file)) {
                    unlink($file);
                }
            }
            return;
        }

        $key = self::canonicalNode($node);
        $file = sys_get_temp_dir() . "/lm_node_down_{$key}";
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public static function connection(string $node): ?PDO
    {
        $key = self::canonicalNode($node);
        if ($key === null || self::isSimulatedDown($key)) {
            return null;
        }

        $nodes = self::nodes();
        if (!isset($nodes[$key])) {
            return null;
        }

        $cfg = $nodes[$key];

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $cfg['host'],
                $cfg['port'],
                $cfg['db']
            );

            $pdo = new PDO($dsn, LM_DB_USER, LM_DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            if ($key === 'central') {
                self::bootstrapCentralSchema($pdo);
            }

            return $pdo;
        } catch (Throwable $e) {
            error_log(sprintf('Error conectando nodo [%s]: %s', $key, $e->getMessage()));
            return null;
        }
    }

    public static function ping(string $node): bool
    {
        $pdo = self::connection($node);
        if (!$pdo instanceof PDO) {
            return false;
        }

        try {
            $pdo->query('SELECT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function canonicalNode(string $node): ?string
    {
        $key = strtolower(trim($node));
        $aliases = self::nodeAliases();
        return $aliases[$key] ?? (isset(self::nodes()[$key]) ? $key : null);
    }

    private static function bootstrapCentralSchema(PDO $pdo): void
    {
        if (self::$schemaBootstrapped) {
            return;
        }

        self::$schemaBootstrapped = true;

        // Solo intentar bootstrap si la tabla productos ya existe (si no, el seeder lo hará)
        if (self::tableExists($pdo, 'productos')) {
            if (!self::columnExists($pdo, 'productos', 'activo')) {
                $pdo->exec('ALTER TABLE productos ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1');
            }
        }

        if (!self::tableExists($pdo, 'stock')) {
            $pdo->exec(
                'CREATE TABLE stock (
                    id_stock INT NOT NULL AUTO_INCREMENT,
                    id_prod INT NOT NULL,
                    sucursal VARCHAR(100),
                    id_suc INT NOT NULL,
                    producto VARCHAR(120) NOT NULL,
                    cantidad INT NOT NULL DEFAULT 0,
                    stock_minimo INT DEFAULT 5,
                    actualizado_en DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id_stock)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }

        if (!self::tableExists($pdo, 'movimientos_stock')) {
            $pdo->exec(
                'CREATE TABLE movimientos_stock (
                    id_movimiento INT NOT NULL AUTO_INCREMENT,
                    id_prod INT NOT NULL,
                    id_suc INT NOT NULL,
                    tipo ENUM("entrada","salida","ajuste") NOT NULL,
                    cantidad INT NOT NULL,
                    motivo VARCHAR(200) DEFAULT NULL,
                    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id_movimiento)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
}

function lm_pdo(string $node): PDO
{
    $pdo = LmDatabase::connection($node);
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('No fue posible conectar al nodo ' . $node . '.');
    }

    return $pdo;
}

function conectarNodo(string $nodo = 'central'): ?PDO
{
    return LmDatabase::connection($nodo);
}

function estadoNodos(): array
{
    $estado = [];
    foreach (LmDatabase::nodes() as $key => $cfg) {
        $estado[$key] = LmDatabase::ping($key) ? 'online' : 'offline';
    }

    return $estado;
}

function lm_simular_caida_nodo(string $nodo, bool $caida = true): void
{
    LmDatabase::simulateNodeDown($nodo, $caida);
}

/**
 * Instala stored procedures y funciones desde un archivo SQL con DELIMITER
 * usando PDO (no requiere mysql CLI ni Docker).
 */
function lm_install_routines_from_file(PDO $pdo, string $filePath): array
{
    if (!file_exists($filePath)) {
        throw new InvalidArgumentException("Archivo SQL no encontrado: {$filePath}");
    }
    
    $sql = file_get_contents($filePath);
    $lines = explode("\n", $sql);
    $results = [];
    $currentStmt = '';
    $inRoutine = false;
    $delimiter = ';';
    
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Saltar comentarios y líneas vacías
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            if ($inRoutine) {
                $currentStmt .= $line . "\n";
            }
            continue;
        }
        
        // Detectar cambio de DELIMITER
        if (str_starts_with(strtoupper($trimmed), 'DELIMITER ')) {
            $parts = preg_split('/\s+/', $trimmed);
            $newDelim = $parts[1] ?? ';';
            
            if ($inRoutine && $newDelim === ';') {
                // Fin del bloque con DELIMITER ;
                $stmt = trim($currentStmt);
                if ($stmt !== '') {
                    try {
                        $pdo->exec($stmt);
                        $results[] = ['type' => 'success', 'statement' => substr($stmt, 0, 80) . '...'];
                    } catch (Throwable $e) {
                        $results[] = ['type' => 'error', 'statement' => substr($stmt, 0, 80) . '...', 'error' => $e->getMessage()];
                    }
                }
                $currentStmt = '';
                $inRoutine = false;
            }
            $delimiter = $newDelim;
            continue;
        }
        
        // Detectar CREATE de routine
        if (preg_match('/^\s*CREATE\s+(PROCEDURE|FUNCTION)\s/i', $trimmed)) {
            $inRoutine = true;
            $currentStmt = $line . "\n";
            continue;
        }
        
        if ($inRoutine) {
            $currentStmt .= $line . "\n";
            
            // Detectar fin de la routine con el delimitador actual
            if ($delimiter !== ';') {
                $endMarker = 'END ' . $delimiter;
                if (rtrim($trimmed) === $endMarker || str_ends_with(rtrim($trimmed), $endMarker)) {
                    $stmt = trim($currentStmt);
                    // Reemplazar el END con delimitador por END
                    $stmt = preg_replace('/END\s+' . preg_quote($delimiter, '/') . '\s*$/', 'END', $stmt);
                    if ($stmt !== '') {
                        try {
                            $pdo->exec($stmt);
                            $results[] = ['type' => 'success', 'statement' => substr($stmt, 0, 80) . '...'];
                        } catch (Throwable $e) {
                            $results[] = ['type' => 'error', 'statement' => substr($stmt, 0, 80) . '...', 'error' => $e->getMessage()];
                        }
                    }
                    $currentStmt = '';
                    $inRoutine = false;
                }
            }
        } elseif ($delimiter === ';') {
            // Sentencia regular (CREATE TABLE, etc.)
            $currentStmt .= $line . "\n";
            if (str_ends_with(rtrim($trimmed), ';')) {
                $stmt = trim($currentStmt);
                if ($stmt !== '') {
                    try {
                        $pdo->exec($stmt);
                        $results[] = ['type' => 'success', 'statement' => substr($stmt, 0, 80) . '...'];
                    } catch (Throwable $e) {
                        $results[] = ['type' => 'error', 'statement' => substr($stmt, 0, 80) . '...', 'error' => $e->getMessage()];
                    }
                }
                $currentStmt = '';
            }
        }
    }
    
    // Procesar cualquier statement restante
    $stmt = trim($currentStmt);
    if ($stmt !== '') {
        try {
            $pdo->exec($stmt);
            $results[] = ['type' => 'success', 'statement' => substr($stmt, 0, 80) . '...'];
        } catch (Throwable $e) {
            $results[] = ['type' => 'error', 'statement' => substr($stmt, 0, 80) . '...', 'error' => $e->getMessage()];
        }
    }
    
    return $results;
}

function lm_restaurar_nodo(string $nodo): void
{
    LmDatabase::clearNodeSimulation($nodo);
}

function lm_restaurar_todos_los_nodos(): void
{
    LmDatabase::clearNodeSimulation();
}
