<?php
// crear_admin.php
// Instalación inicial: crea la persona, el usuario SuperAdmin y su rol.
// Ya no toca la base de datos directamente: llama al endpoint /api/setup/admin,
// que además impide volver a ejecutarse cuando la institución ya tiene usuarios.
define('APP_ROOT', '');
require_once __DIR__ . '/includes/api_client.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$institucionId = (int)($_GET['institucion_id'] ?? 1);
$username      = trim($_GET['username'] ?? 'admin');
$passwordPlana = $_GET['password'] ?? 'admin123';

$respuesta = apiPost('setup/admin', [
    'institucion_id' => $institucionId,
    'username'       => $username,
    'password'       => $passwordPlana,
    'rol_id'         => (int)($_GET['rol_id'] ?? 1),
]);

header('Content-Type: text/html; charset=utf-8');

if ($respuesta['ok']) {
    $datos = $respuesta['datos'];
    echo "Usuario SuperAdmin creado exitosamente. Puedes ir a <a href='login.php'>login.php</a> e ingresar con <strong>"
        . htmlspecialchars($username) . "</strong> / <strong>" . htmlspecialchars($passwordPlana) . "</strong>.";
    if (empty($datos['rol_asignado'])) {
        echo "<br><br><b>Atención:</b> el rol indicado no existe en esta institución; asígnelo manualmente desde el módulo de Usuarios.";
    }
    echo "<br><br><b>¡Por favor elimina este archivo ahora!</b>";
} else {
    echo "Error al crear el usuario: " . htmlspecialchars(implode(' ', apiErrores($respuesta)));
}
