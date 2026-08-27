<?php
// index.php
// Punto de entrada del sitio: redirige según el estado de la sesión.
define('APP_ROOT', '');
require_once __DIR__ . '/auth.php';

if (isset($_SESSION['usuario_id']) && !empty($_SESSION['api_token'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
