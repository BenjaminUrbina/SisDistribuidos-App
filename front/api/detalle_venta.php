<?php
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Venta invalida.']);
    exit;
}

try {
    $venta = lm_venta_por_id($id);
    if (!$venta) {
        echo json_encode(['ok' => false, 'error' => 'Venta no encontrada.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'venta' => $venta,
        'detalle' => lm_venta_detalle($id),
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
