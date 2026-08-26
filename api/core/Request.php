<?php
// api/core/Request.php
// Representa la petición HTTP entrante: método, ruta, query string y cuerpo JSON.

final class Request
{
    public string $metodo;
    public string $ruta;
    public array  $query;
    public array  $cuerpo;
    private array $cabeceras;

    public function __construct()
    {
        $this->metodo    = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->query     = $_GET;
        $this->cabeceras = self::leerCabeceras();
        $this->ruta      = $this->resolverRuta();
        $this->cuerpo    = $this->leerCuerpo();

        // Soporte para clientes que no pueden enviar PUT/PATCH/DELETE:
        // POST + _method=PUT  (o cabecera X-HTTP-Method-Override)
        $override = $this->cuerpo['_method']
            ?? ($this->query['_method'] ?? $this->cabecera('X-HTTP-Method-Override'));
        if ($this->metodo === 'POST' && $override) {
            $this->metodo = strtoupper((string)$override);
        }
    }

    /**
     * Obtiene la ruta del recurso soportando dos modos:
     *  1. URLs limpias vía .htaccess  ->  /api/personas/5
     *  2. Sin mod_rewrite            ->  /api/index.php?ruta=personas/5
     */
    private function resolverRuta(): string
    {
        // Modo 2 (respaldo explícito)
        if (!empty($_GET['ruta'])) {
            return trim((string)$_GET['ruta'], '/');
        }

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $uri = rawurldecode($uri);

        // Recorta todo lo anterior (y lo incluido) al segmento /api
        if (preg_match('#/api(/index\.php)?(/.*)?$#', $uri, $m)) {
            $uri = $m[2] ?? '';
        }

        return trim($uri, '/');
    }

    /** Lee el cuerpo de la petición: JSON o formulario tradicional. */
    private function leerCuerpo(): array
    {
        $tipo = strtolower($this->cabecera('Content-Type') ?? '');

        if (str_contains($tipo, 'application/json')) {
            $crudo = file_get_contents('php://input') ?: '';
            if ($crudo === '') {
                return [];
            }
            $datos = json_decode($crudo, true);
            return is_array($datos) ? $datos : [];
        }

        if (!empty($_POST)) {
            return $_POST;
        }

        // PUT/PATCH/DELETE con cuerpo url-encoded
        $crudo = file_get_contents('php://input') ?: '';
        if ($crudo !== '') {
            $datos = json_decode($crudo, true);
            if (is_array($datos)) {
                return $datos;
            }
            parse_str($crudo, $datos);
            return is_array($datos) ? $datos : [];
        }

        return [];
    }

    private static function leerCabeceras(): array
    {
        $cabeceras = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $nombre => $valor) {
                $cabeceras[strtolower($nombre)] = $valor;
            }
        }
        foreach ($_SERVER as $clave => $valor) {
            if (str_starts_with($clave, 'HTTP_')) {
                $nombre = strtolower(str_replace('_', '-', substr($clave, 5)));
                $cabeceras[$nombre] = $valor;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $cabeceras['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        // Algunos Apache/CGI mueven la cabecera Authorization aquí
        if (empty($cabeceras['authorization'])) {
            foreach (['REDIRECT_HTTP_AUTHORIZATION', 'HTTP_AUTHORIZATION', 'PHP_AUTH_DIGEST'] as $alt) {
                if (!empty($_SERVER[$alt])) {
                    $cabeceras['authorization'] = $_SERVER[$alt];
                    break;
                }
            }
        }
        return $cabeceras;
    }

    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }

    /** Valor del cuerpo (POST/PUT). */
    public function dato(string $clave, $porDefecto = null)
    {
        return $this->cuerpo[$clave] ?? $porDefecto;
    }

    public function texto(string $clave, string $porDefecto = ''): string
    {
        $valor = $this->cuerpo[$clave] ?? $this->query[$clave] ?? $porDefecto;
        return is_scalar($valor) ? trim((string)$valor) : $porDefecto;
    }

    public function entero(string $clave, int $porDefecto = 0): int
    {
        $valor = $this->cuerpo[$clave] ?? $this->query[$clave] ?? $porDefecto;
        return is_scalar($valor) ? (int)$valor : $porDefecto;
    }

    /** Valor del query string (?clave=...). */
    public function param(string $clave, $porDefecto = null)
    {
        return $this->query[$clave] ?? $porDefecto;
    }

    public function paramTexto(string $clave, string $porDefecto = ''): string
    {
        $valor = $this->query[$clave] ?? $porDefecto;
        return is_scalar($valor) ? trim((string)$valor) : $porDefecto;
    }

    public function paramEntero(string $clave, int $porDefecto = 0): int
    {
        $valor = $this->query[$clave] ?? $porDefecto;
        return is_scalar($valor) ? (int)$valor : $porDefecto;
    }

    /** Devuelve un arreglo de enteros (ej. tipos_autorizados[]). */
    public function arregloEnteros(string $clave): array
    {
        $valor = $this->cuerpo[$clave] ?? [];
        if (!is_array($valor)) {
            $valor = ($valor === '' || $valor === null) ? [] : [$valor];
        }
        return array_values(array_map('intval', $valor));
    }
}
