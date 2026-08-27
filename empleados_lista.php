<?php
// empleados_lista.php
// Listado mínimo de empleados. Los datos se obtienen de la API REST (/api/empleados);
// el módulo completo con búsqueda, alta y edición está en modules/empleados.php.
define('APP_ROOT', '');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

// Validar autorización estricta
if (!tieneRol(['SuperAdmin', 'RecursosHumanos'])) {
    http_response_code(403);
    die("Error 403: Acceso denegado. No tienes permisos para gestionar empleados.");
}

$listado   = apiGet('empleados', ['por_pagina' => 100]);
$empleados = apiDatos($listado, []);

echo "<h1>Listado de Empleados</h1>";

if (!$listado['ok']) {
    echo '<p style="color:#c8102e;">' . e(apiError($listado)) . '</p>';
} elseif (empty($empleados)) {
    echo "<p>No hay empleados registrados en la institución activa.</p>";
} else {
    echo '<ul>';
    foreach ($empleados as $empleado) {
        echo '<li>'
            . e(nombreCompleto($empleado['Nombres'] ?? null, $empleado['Apellidos'] ?? null))
            . ' &mdash; ' . e($empleado['Identificacion'] ?? '')
            . ' (' . e($empleado['Estado'] ?? '') . ')'
            . '</li>';
    }
    echo '</ul>';
}
