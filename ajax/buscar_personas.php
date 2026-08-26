<?php
/**
 * ajax/buscar_personas.php
 * -----------------------------------------------------------------------------
 * Búsqueda de personas para la subpantalla de selección (modal).
 *
 * Lo consume por fetch() el componente includes/selector_persona.php.
 * No habla con MySQL: reenvía la consulta a la API REST (/api/personas) usando
 * el token que ya está guardado en la sesión PHP, de modo que el token nunca
 * viaja al navegador.
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

/* Mismo criterio que el directorio de personas (ver includes/accesos.php). */
if (!puedeAcceder('personas_lectura')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No cuenta con permisos para consultar el directorio de personas.']);
    exit;
}

$estado = strtoupper(trim($_GET['estado'] ?? 'ACTIVO'));
if (!in_array($estado, ['ACTIVO', 'INACTIVO', ''], true)) {
    $estado = 'ACTIVO';
}

/* Filtros opcionales que declaran los formularios:
     excluir      -> omite una persona concreta (el titular al elegir representante)
     sin_usuario  -> solo personas que aún no tienen cuenta en la institución    */
$excluir    = max(0, (int)($_GET['excluir'] ?? 0));
$sinUsuario = ((int)($_GET['sin_usuario'] ?? 0) === 1) ? 1 : 0;

$respuesta = apiGet('personas', [
    'q'           => trim($_GET['q'] ?? ''),
    'estado'      => $estado,
    'excluir'     => $excluir ?: '',
    'sin_usuario' => $sinUsuario ?: '',
    'pagina'      => max(1, (int)($_GET['pagina'] ?? 1)),
    'por_pagina'  => min(50, max(5, (int)($_GET['por_pagina'] ?? 8))),
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
