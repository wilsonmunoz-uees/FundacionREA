<?php
// api/core/Response.php
// Salida uniforme de la API. Todas las respuestas son JSON con la forma:
//   { "ok": true,  "datos": ..., "meta": {...} }
//   { "ok": false, "error": "mensaje", "errores": [...] }

final class Response
{
    /** Envía una respuesta JSON y termina la ejecución. */
    public static function json(array $carga, int $estado = 200): void
    {
        http_response_code($estado);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($carga, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Respuesta correcta. $meta se usa para paginación y totales. */
    public static function exito($datos = null, array $meta = [], int $estado = 200): void
    {
        $carga = ['ok' => true, 'datos' => $datos];
        if (!empty($meta)) {
            $carga['meta'] = $meta;
        }
        self::json($carga, $estado);
    }

    /** Listado paginado. */
    public static function lista(array $datos, int $total, int $pagina, int $porPagina, array $extra = []): void
    {
        $meta = array_merge([
            'total'         => $total,
            'pagina'        => $pagina,
            'por_pagina'    => $porPagina,
            'total_paginas' => max(1, (int)ceil($total / max(1, $porPagina))),
        ], $extra);

        self::json(['ok' => true, 'datos' => $datos, 'meta' => $meta], 200);
    }

    /** Error genérico. $errores permite devolver validaciones múltiples. */
    public static function error(string $mensaje, int $estado = 400, array $errores = []): void
    {
        $carga = ['ok' => false, 'error' => $mensaje];
        if (!empty($errores)) {
            $carga['errores'] = array_values($errores);
        }
        self::json($carga, $estado);
    }

    public static function validacion(array $errores): void
    {
        self::error('Los datos enviados no son válidos.', 422, $errores);
    }

    public static function noAutenticado(string $mensaje = 'No autenticado o token expirado.'): void
    {
        self::error($mensaje, 401);
    }

    public static function prohibido(string $mensaje = 'No cuenta con permisos suficientes.'): void
    {
        self::error($mensaje, 403);
    }

    public static function noEncontrado(string $mensaje = 'Registro no encontrado.'): void
    {
        self::error($mensaje, 404);
    }
}
