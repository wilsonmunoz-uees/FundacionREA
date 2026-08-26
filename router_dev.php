<?php
/**
 * router_dev.php
 * Router SOLO para pruebas locales con el servidor embebido de PHP.
 * Emula la reescritura de URLs que en producción hace api/.htaccess.
 *
 *   PHP_CLI_SERVER_WORKERS=6 php -S localhost:8080 -t . router_dev.php
 *
 * (Los workers son necesarios porque las páginas llaman a la API por HTTP;
 *  con un solo proceso, el servidor embebido se bloquearía a sí mismo.)
 * En Apache / nginx este archivo no se utiliza.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (preg_match('#^/api(/.*)?$#', $uri)) {
    $archivo = __DIR__ . $uri;
    if (is_file($archivo)) {
        return false; // archivos reales (por ejemplo /api/LEEME.md)
    }
    require __DIR__ . '/api/index.php';
    return true;
}

return false; // el resto lo sirve PHP normalmente
