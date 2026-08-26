<?php
/**
 * ajax/buscar_destinatarios.php
 * -----------------------------------------------------------------------------
 * Alimenta la subventana de selección individual del Envío Masivo.
 *
 * La pantalla no habla directamente con la API: el token vive en la sesión de
 * PHP y nunca llega al navegador. Este archivo hace de intermediario, igual que
 * el resto de los proxys de ajax/.
 *
 * Devuelve JSON:
 *   { ok, datos: [ { PersonaId, Identificacion, Nombres, Apellidos,
 *                    Destinatario, Representante, TieneCorreo } ],
 *     meta: { total, pagina, por_pagina, total_paginas, tipo, documento } }
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

/* Mismo criterio que la pantalla de envío (ver includes/accesos.php). */
if (!puedeAcceder('envio_masivo')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No cuenta con permisos para esta consulta.']);
    exit;
}

$respuesta = apiGet('envio-masivo/destinatarios', [
    'tipo'            => $_GET['tipo'] ?? '',
    'q'               => trim($_GET['q'] ?? ''),
    'solo_con_correo' => (int)($_GET['solo_con_correo'] ?? 0),
    'pagina'          => max(1, (int)($_GET['pagina'] ?? 1)),
    'por_pagina'      => 10,
]);

if (!$respuesta['ok']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => apiError($respuesta)]);
    exit;
}

echo json_encode([
    'ok'    => true,
    'datos' => apiDatos($respuesta, []),
    'meta'  => apiMeta($respuesta),
], JSON_UNESCAPED_UNICODE);
