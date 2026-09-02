<?php
// auth.php
// Núcleo de autenticación, sesión y control de acceso (roles/permisos)
// del Sistema de Gestión de Protección de Datos - Red Educativa Arquidiocesana (REA).
//
// IMPORTANTE: este archivo ya NO abre conexiones a la base de datos.
// Toda la conectividad se realiza contra la API REST de /api (ver includes/api_client.php).

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/api_client.php';
require_once __DIR__ . '/includes/accesos.php';

/**
 * Verifica si el usuario actual tiene al menos uno de los roles requeridos.
 */
function tieneRol(array $rolesPermitidos): bool {
    if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['roles'])) {
        return false;
    }
    $interseccion = array_intersect($_SESSION['roles'], $rolesPermitidos);
    return count($interseccion) > 0;
}

/**
 * SuperAdmin: acceso total, sin restricción de módulo.
 */
function esSuperAdmin(): bool {
    return tieneRol(['SuperAdmin']);
}

/**
 * Verifica si el usuario tiene un permiso concreto (por Código).
 * Los permisos se reciben de la API al iniciar sesión; si no estuvieran en la
 * sesión, se consultan al endpoint /api/auth/permiso.
 */
function tienePermiso(string $codigoPermiso): bool {
    if (esSuperAdmin()) {
        return true;
    }
    if (!isset($_SESSION['usuario_id'], $_SESSION['institucion_id'])) {
        return false;
    }

    if (isset($_SESSION['permisos']) && is_array($_SESSION['permisos'])) {
        return in_array($codigoPermiso, $_SESSION['permisos'], true);
    }

    static $cache = [];
    if (isset($cache[$codigoPermiso])) {
        return $cache[$codigoPermiso];
    }

    $respuesta = apiGet('auth/permiso', ['codigo' => $codigoPermiso]);
    $datos     = apiDatos($respuesta, []);

    return $cache[$codigoPermiso] = (bool)($datos['tiene'] ?? false);
}

/**
 * Verifica si el usuario tiene al menos uno de los permisos indicados.
 */
function tieneAlgunPermiso(array $codigos): bool {
    foreach ($codigos as $codigo) {
        if (tienePermiso($codigo)) {
            return true;
        }
    }
    return false;
}

/**
 * ¿Puede el usuario abrir esta opción del sistema?
 * La definición de cada opción está en includes/accesos.php.
 */
function puedeAcceder(string $clave): bool {
    if (!isset($_SESSION['usuario_id'])) {
        return false;
    }
    if (esSuperAdmin()) {
        return true;
    }

    $acceso = accesoDe($clave);

    if (!empty($acceso['autenticado'])) {
        return true;
    }
    if (!empty($acceso['roles']) && tieneRol($acceso['roles'])) {
        return true;
    }
    return !empty($acceso['permisos']) && tieneAlgunPermiso($acceso['permisos']);
}

/** ¿Puede abrir alguna de las opciones de una sección del menú? */
function puedeAccederSeccion(string $seccion): bool {
    foreach (accesosDeSeccion($seccion) as $clave) {
        if (puedeAcceder($clave)) {
            return true;
        }
    }
    return false;
}

/**
 * Obliga a tener acceso a una opción concreta; si no, corta con 403.
 * Reemplaza a requireRol() en los módulos, permitiendo entrar tanto por rol
 * como por permiso asignado.
 */
function requireAcceso(string $clave): void {
    requireLogin();
    if (!puedeAcceder($clave)) {
        http_response_code(403);
        $prefijo = defined('APP_ROOT') ? APP_ROOT : '';
        die('<div style="font-family:sans-serif;padding:40px;text-align:center;color:#c8102e;">
                <h2>Acceso denegado</h2>
                <p>No cuenta con permisos suficientes para acceder a este módulo.</p>
                <p><a href="' . $prefijo . 'dashboard.php">Volver al panel principal</a></p>
             </div>');
    }
}

/**
 * Obliga a tener sesión activa; de lo contrario redirige al login.
 */
function requireLogin(): void {
    if (!isset($_SESSION['usuario_id']) || empty($_SESSION['api_token'])) {
        $prefijo = defined('APP_ROOT') ? APP_ROOT : '';
        header('Location: ' . $prefijo . 'login.php');
        exit;
    }

    /* Contraseña temporal todavía puesta: la sesión existe, pero no sirve para
       nada más que cambiarla. Se comprueba aquí, en el único sitio por el que
       pasan todas las pantallas, para que no haya ninguna puerta lateral. */
    if (!empty($_SESSION['debe_cambiar_clave'])
        && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'cambiar_clave.php') {
        $prefijo = defined('APP_ROOT') ? APP_ROOT : '';
        header('Location: ' . $prefijo . 'cambiar_clave.php');
        exit;
    }
}

/**
 * Obliga a tener uno de los roles indicados. Si no cumple, corta con 403.
 */
function requireRol(array $rolesPermitidos): void {
    requireLogin();
    if (!tieneRol($rolesPermitidos)) {
        http_response_code(403);
        $prefijo = defined('APP_ROOT') ? APP_ROOT : '';
        die('<div style="font-family:sans-serif;padding:40px;text-align:center;color:#c8102e;">
                <h2>Acceso denegado</h2>
                <p>No cuenta con permisos suficientes para acceder a este módulo.</p>
                <p><a href="' . $prefijo . 'dashboard.php">Volver al panel principal</a></p>
             </div>');
    }
}

/**
 * Procesa el Login contra la API REST y guarda en sesión el token, los roles
 * y los permisos del usuario para la institución seleccionada.
 *
 * @return true si las credenciales fueron aceptadas; en caso contrario, string con el error.
 */
function procesarLogin(string $username, string $password, string $institucion) {
    $respuesta = apiPost('auth/login', [
        'username'       => $username,
        'password'       => $password,
        'institucion_id' => (int)$institucion,
    ]);

    if (!$respuesta['ok']) {
        return apiError($respuesta);
    }

    $datos   = $respuesta['datos'];
    $usuario = $datos['usuario'];

    // Regenerar el ID de la sesión para prevenir ataques de Session Fixation
    session_regenerate_id(true);

    $_SESSION['api_token']          = $datos['token'];
    $_SESSION['api_token_expira']   = $datos['expira'];
    $_SESSION['usuario_id']         = $usuario['usuario_id'];
    $_SESSION['persona_id']         = $usuario['persona_id'] ?? null;
    $_SESSION['username']           = $usuario['username'];
    $_SESSION['institucion_id']     = $usuario['institucion_id'];
    $_SESSION['institucion_nombre'] = $usuario['institucion_nombre'] ?? '';
    $_SESSION['roles']              = $usuario['roles'] ?? [];
    $_SESSION['permisos']           = $usuario['permisos'] ?? [];
    // Institución a la que pertenece la cuenta y si está trabajando en otra
    // (solo ocurre con el SuperAdmin, que entra en todas).
    $_SESSION['institucion_propia'] = $usuario['institucion_propia'] ?? $usuario['institucion_id'];
    $_SESSION['institucion_visita'] = !empty($usuario['visita']);
    /* Entró con la contraseña temporal que le envió el sistema: hasta que fije
       la suya, requireLogin() lo devuelve a la pantalla de cambio. */
    $_SESSION['debe_cambiar_clave'] = !empty($usuario['debe_cambiar_clave']);

    return true;
}

/* =========================================================================
   Utilidades comunes (CSRF, mensajes flash, saneamiento)
   ========================================================================= */

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfCampo(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function csrfValido(): bool {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

function flashSet(string $tipo, string $mensaje): void {
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

function flashGet(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function e(?string $valor): string {
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}

function institucionActual(): ?int {
    return isset($_SESSION['institucion_id']) ? (int)$_SESSION['institucion_id'] : null;
}
