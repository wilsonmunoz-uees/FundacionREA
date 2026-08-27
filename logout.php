<?php
// logout.php
session_start();

require_once __DIR__ . '/includes/api_client.php';

// Avisar a la API que la sesión termina (el token se descarta igualmente)
if (!empty($_SESSION['api_token'])) {
    apiPost('auth/logout');
}

// Vaciar todas las variables de sesión
$_SESSION = [];

// Destruir la cookie de sesión si existe
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destruir la sesión
session_destroy();

// Redirigir al login
header('Location: login.php');
exit;
