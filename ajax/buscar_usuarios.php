<?php
/**
 * ajax/buscar_usuarios.php
 * -----------------------------------------------------------------------------
 * Búsqueda de usuarios del sistema para la subpantalla de selección (modal)
 * de la bitácora de auditoría.
 *
 * Lo consume por fetch() el componente includes/selector_entidad.php.
 * No habla con MySQL: reenvía la consulta a la API REST (/api/usuarios/buscar)
 * usando el token guardado en la sesión PHP, de modo que el token nunca viaja
 * al navegador.
 * -----------------------------------------------------------------------------
 */

define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/* Solo usuarios con sesión activa; aquí no se redirige, se responde JSON. */
if (!isset($_SESSION['usuario_id']) || empty($_SESSION['api_token'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Su sesión expiró. Vuelva a ingresar al sistema.']);
    exit;
}

/* Mismo criterio que la API (ver includes/accesos.php). */
if (!puedeAcceder('usuarios_lectura')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No cuenta con permisos para consultar los usuarios del sistema.']);
    exit;
}

$respuesta = apiGet('usuarios/buscar', [
    'q'          => trim($_GET['q'] ?? ''),
    'pagina'     => max(1, (int)($_GET['pagina'] ?? 1)),
    'por_pagina' => min(50, max(5, (int)($_GET['por_pagina'] ?? 8))),
]);

if (!$respuesta['ok']) {
    http_response_code($respuesta['estado'] >= 400 ? $respuesta['estado'] : 502);
    echo json_encode(['ok' => false, 'error' => apiError($respuesta)], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'    => true,
    'datos' => $respuesta['datos'] ?? [],
    'meta'  => $respuesta['meta'] ?? [],
], JSON_UNESCAPED_UNICODE);
