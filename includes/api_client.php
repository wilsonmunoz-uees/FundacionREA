<?php
/**
 * includes/api_client.php
 * -----------------------------------------------------------------------------
 * Cliente HTTP que usan TODAS las páginas del sitio para hablar con la API REST.
 *
 * Ninguna vista abre conexiones a MySQL: piden los datos por aquí.
 *
 *   $respuesta = apiGet('personas', ['q' => 'perez', 'pagina' => 2]);
 *   $registros = apiDatos($respuesta);
 *   $meta      = apiMeta($respuesta);
 *
 * El token Bearer se guarda en la sesión PHP al iniciar sesión y se adjunta
 * automáticamente en cada llamada.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/api_config.php';

final class ApiClient
{
    private static ?ApiClient $instancia = null;

    private string $baseUrl;
    /** true cuando hay que usar el modo de respaldo index.php?ruta=... */
    private bool $modoRespaldo = false;

    private function __construct()
    {
        $this->baseUrl      = $this->detectarBaseUrl();
        $this->modoRespaldo = !empty($_SESSION['api_modo_respaldo']);
    }

    public static function instancia(): ApiClient
    {
        return self::$instancia ??= new ApiClient();
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /* ------------------------------------------------------------------ */
    /* Verbos HTTP                                                         */
    /* ------------------------------------------------------------------ */

    public function get(string $ruta, array $params = []): array
    {
        return $this->solicitar('GET', $ruta, [], $params);
    }

    public function post(string $ruta, array $datos = [], array $params = []): array
    {
        return $this->solicitar('POST', $ruta, $datos, $params);
    }

    public function put(string $ruta, array $datos = [], array $params = []): array
    {
        return $this->solicitar('PUT', $ruta, $datos, $params);
    }

    public function patch(string $ruta, array $datos = [], array $params = []): array
    {
        return $this->solicitar('PATCH', $ruta, $datos, $params);
    }

    public function delete(string $ruta, array $datos = [], array $params = []): array
    {
        return $this->solicitar('DELETE', $ruta, $datos, $params);
    }

    /* ------------------------------------------------------------------ */
    /* Motor                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Realiza la llamada y normaliza siempre la respuesta a:
     *   ['ok'=>bool, 'estado'=>int, 'datos'=>mixed, 'meta'=>array, 'error'=>string, 'errores'=>array]
     */
    private function solicitar(string $metodo, string $ruta, array $datos = [], array $params = []): array
    {
        $respuesta = $this->enviar($metodo, $ruta, $datos, $params);

        // Si el hosting no tiene mod_rewrite, la primera llamada devuelve un 404
        // del servidor web (no un JSON de la API): se reintenta con el modo de
        // respaldo /api/index.php?ruta=...
        if ($respuesta['estado'] === 404 && !$respuesta['json_valido']) {
            $this->modoRespaldo = !$this->modoRespaldo;
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['api_modo_respaldo'] = $this->modoRespaldo;
            }
            $segundoIntento = $this->enviar($metodo, $ruta, $datos, $params);

            // Si el reintento tampoco encuentra la API, se conserva el modo previo
            if ($segundoIntento['json_valido'] || $segundoIntento['estado'] !== 404) {
                $respuesta = $segundoIntento;
            } else {
                $this->modoRespaldo = !$this->modoRespaldo;
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['api_modo_respaldo'] = $this->modoRespaldo;
                }
            }
        }

        // Token vencido o inválido: se cierra la sesión y se vuelve al login.
        if ($respuesta['estado'] === 401 && !empty($_SESSION['api_token'])) {
            $this->cerrarSesionExpirada();
        }

        unset($respuesta['json_valido']);
        return $respuesta;
    }

    private function enviar(string $metodo, string $ruta, array $datos, array $params): array
    {
        $url  = $this->construirUrl($ruta, $params);
        $body = $datos ? json_encode($datos, JSON_UNESCAPED_UNICODE) : null;

        $cabeceras = ['Accept: application/json'];
        if ($body !== null) {
            $cabeceras[] = 'Content-Type: application/json; charset=utf-8';
        }
        if (!empty($_SESSION['api_token'])) {
            $cabeceras[] = 'Authorization: Bearer ' . $_SESSION['api_token'];
        }

        [$cuerpo, $estado, $errorRed] = function_exists('curl_init')
            ? $this->viaCurl($metodo, $url, $body, $cabeceras)
            : $this->viaStream($metodo, $url, $body, $cabeceras);

        if ($errorRed !== null) {
            return [
                'ok'          => false,
                'estado'      => 0,
                'datos'       => null,
                'meta'        => [],
                'error'       => 'No se pudo contactar con la API: ' . $errorRed,
                'errores'     => [],
                'json_valido' => false,
            ];
        }

        $json = json_decode((string)$cuerpo, true);
        $jsonValido = is_array($json) && array_key_exists('ok', $json);

        if (!$jsonValido) {
            return [
                'ok'          => false,
                'estado'      => $estado,
                'datos'       => null,
                'meta'        => [],
                'error'       => $estado === 404
                    ? 'Ruta de la API no encontrada: ' . $ruta
                    : 'Respuesta inesperada de la API (HTTP ' . $estado . ').',
                'errores'     => [],
                'json_valido' => false,
            ];
        }

        return [
            'ok'          => (bool)$json['ok'],
            'estado'      => $estado,
            'datos'       => $json['datos'] ?? null,
            'meta'        => $json['meta'] ?? [],
            'error'       => $json['error'] ?? '',
            'errores'     => $json['errores'] ?? [],
            'json_valido' => true,
        ];
    }

    /** @return array{0:?string,1:int,2:?string} */
    private function viaCurl(string $metodo, string $url, ?string $body, array $cabeceras): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $cabeceras,
            CURLOPT_TIMEOUT        => API_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => API_VERIFICAR_SSL,
            CURLOPT_SSL_VERIFYHOST => API_VERIFICAR_SSL ? 2 : 0,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $cuerpo = curl_exec($ch);
        $estado = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        return [$cuerpo === false ? null : (string)$cuerpo, $estado, $error];
    }

    /** Respaldo si cURL no está disponible en el hosting. */
    private function viaStream(string $metodo, string $url, ?string $body, array $cabeceras): array
    {
        $contexto = stream_context_create([
            'http' => [
                'method'        => $metodo,
                'header'        => implode("\r\n", $cabeceras),
                'content'       => $body ?? '',
                'timeout'       => API_TIMEOUT,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => API_VERIFICAR_SSL,
                'verify_peer_name' => API_VERIFICAR_SSL,
            ],
        ]);

        $cuerpo = @file_get_contents($url, false, $contexto);
        $estado = 0;
        foreach ($http_response_header ?? [] as $linea) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linea, $m)) {
                $estado = (int)$m[1];
            }
        }

        if ($cuerpo === false && $estado === 0) {
            return [null, 0, 'sin respuesta del servidor'];
        }

        return [(string)$cuerpo, $estado, null];
    }

    private function construirUrl(string $ruta, array $params): string
    {
        $ruta = ltrim($ruta, '/');

        if ($this->modoRespaldo) {
            $params = array_merge(['ruta' => $ruta], $params);
            $url    = $this->baseUrl . '/index.php';
        } else {
            $url = $this->baseUrl . '/' . $ruta;
        }

        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        return $url;
    }

    /** Deduce https://host/ruta-del-proyecto/api sin configuración manual. */
    private function detectarBaseUrl(): string
    {
        if (defined('API_BASE_URL') && API_BASE_URL !== '') {
            return rtrim(API_BASE_URL, '/');
        }

        $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
            || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);

        $esquema = $esHttps ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

        $raizProyecto = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
        $rutaWeb      = null;

        // 1) Método principal: se compara la ruta física del script en ejecución
        //    con su ruta web; la diferencia es la carpeta web del proyecto.
        //    Funciona igual en la raíz del dominio o en un subdirectorio.
        $scriptFisico = str_replace('\\', '/', realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '');
        $scriptWeb    = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

        if ($scriptFisico !== '' && $scriptWeb !== '' && str_starts_with($scriptFisico, $raizProyecto)) {
            $relativa = substr($scriptFisico, strlen($raizProyecto)); // p.ej. /modules/personas.php
            if ($relativa !== '' && str_ends_with($scriptWeb, $relativa)) {
                $rutaWeb = substr($scriptWeb, 0, strlen($scriptWeb) - strlen($relativa));
            }
        }

        // 2) Respaldo: ruta física del proyecto menos el DOCUMENT_ROOT
        if ($rutaWeb === null) {
            $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
            if ($docRoot !== '' && str_starts_with($raizProyecto, $docRoot)) {
                $rutaWeb = substr($raizProyecto, strlen($docRoot));
            }
        }

        // 3) Último recurso: la carpeta del script actual
        if ($rutaWeb === null) {
            $rutaWeb = str_replace('\\', '/', dirname($scriptWeb ?: '/'));
        }

        $rutaWeb = rtrim($rutaWeb, '/');
        if ($rutaWeb !== '' && $rutaWeb[0] !== '/') {
            $rutaWeb = '/' . $rutaWeb;
        }

        return $esquema . '://' . $host . $rutaWeb . '/api';
    }

    private function cerrarSesionExpirada(): void
    {
        $prefijo = defined('APP_ROOT') ? APP_ROOT : '';

        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
            session_start();
        }
        $_SESSION['flash'] = ['tipo' => 'error', 'mensaje' => 'Su sesión expiró. Por favor, ingrese nuevamente.'];

        header('Location: ' . $prefijo . 'login.php');
        exit;
    }
}

/* ==========================================================================
   Funciones de atajo que usan las vistas
   ========================================================================== */

function api(): ApiClient
{
    return ApiClient::instancia();
}

function apiGet(string $ruta, array $params = []): array
{
    return api()->get($ruta, $params);
}

function apiPost(string $ruta, array $datos = [], array $params = []): array
{
    return api()->post($ruta, $datos, $params);
}

function apiPut(string $ruta, array $datos = [], array $params = []): array
{
    return api()->put($ruta, $datos, $params);
}

function apiPatch(string $ruta, array $datos = [], array $params = []): array
{
    return api()->patch($ruta, $datos, $params);
}

function apiDelete(string $ruta, array $datos = [], array $params = []): array
{
    return api()->delete($ruta, $datos, $params);
}

/** Extrae el contenido de 'datos' de una respuesta. */
function apiDatos(array $respuesta, $porDefecto = [])
{
    if (!$respuesta['ok']) {
        return $porDefecto;
    }
    return $respuesta['datos'] ?? $porDefecto;
}

/** Extrae la metadata (paginación, catálogos auxiliares). */
function apiMeta(array $respuesta, string $clave = null, $porDefecto = null)
{
    $meta = $respuesta['meta'] ?? [];
    if ($clave === null) {
        return $meta;
    }
    return $meta[$clave] ?? $porDefecto;
}

/** Lista de mensajes de error lista para pintar en la vista. */
function apiErrores(array $respuesta): array
{
    if ($respuesta['ok']) {
        return [];
    }
    if (!empty($respuesta['errores'])) {
        return $respuesta['errores'];
    }
    return [$respuesta['error'] ?: 'Ocurrió un error al comunicarse con la API.'];
}

/** Primer mensaje de error (para flashSet). */
function apiError(array $respuesta): string
{
    $errores = apiErrores($respuesta);
    return $errores[0] ?? '';
}

/** Total de registros de un listado paginado. */
function apiTotal(array $respuesta): int
{
    return (int)($respuesta['meta']['total'] ?? 0);
}

/* =========================================================================
   Llamadas públicas (sin sesión)
   -------------------------------------------------------------------------
   Las usa consentimiento.php, la pantalla que abre el titular desde el enlace
   del correo. No hay sesión ni token: la API valida esas rutas con la firma
   que viaja en el propio enlace. Se mantienen aparte de apiGet/apiPost para
   dejar explícito que esa pantalla no depende de ninguna sesión.
   ========================================================================= */

function apiGetPublico(string $ruta, array $params = []): array
{
    return api()->get($ruta, $params);
}

function apiPostPublico(string $ruta, array $datos = [], array $params = []): array
{
    return api()->post($ruta, $datos, $params);
}
