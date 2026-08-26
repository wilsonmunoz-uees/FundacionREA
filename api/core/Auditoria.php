<?php
/**
 * api/core/Auditoria.php
 * -----------------------------------------------------------------------------
 * Bitácora general de la base de datos.
 *
 * Cada alta, cambio o baja realizada a través de la API deja constancia en la
 * tabla `auditoria`: institución, usuario, fecha y hora, IP de origen, tabla y
 * registro afectados, y el detalle campo por campo con su valor original y el
 * nuevo.
 *
 * Se registra una fila por cada campo modificado, de modo que el reporte puede
 * mostrar exactamente qué dato cambió.
 *
 * Si la tabla `auditoria` todavía no existe (script 01_DDL_estructura.sql), el
 * registro se omite en silencio para no interrumpir la operación del sistema.
 * -----------------------------------------------------------------------------
 */

final class Auditoria
{
    /** Campos que nunca deben quedar registrados con su valor real. */
    private const CAMPOS_SENSIBLES = ['PasswordHash', 'password', 'password_confirm', 'Clave', 'clave'];

    private const OCULTO = '********';

    /** Se desactiva sola si la tabla no existe, para no repetir el error. */
    private static bool $disponible = true;

    /* ------------------------------------------------------------------ */
    /* Registro de operaciones                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Alta de un registro: se anota el valor inicial de cada campo con dato.
     *
     * @param array $usuario Contexto devuelto por Auth::usuarioDe()
     * @param array $datos   Fila recién insertada (columna => valor)
     */
    public static function insercion(array $usuario, string $tabla, $registroId, array $datos): void
    {
        $filas = [];
        foreach ($datos as $campo => $valor) {
            if ($valor === null || $valor === '') {
                continue;   // no se anotan los campos que quedaron vacíos
            }
            $filas[] = [$campo, null, self::valor($campo, $valor)];
        }

        if (!$filas) {
            $filas[] = [null, null, null];   // deja constancia del alta aunque no haya datos
        }

        self::guardar($usuario, $tabla, $registroId, 'INSERT', $filas);
    }

    /**
     * Cambio: se comparan los valores antes y después, y solo se anotan
     * los campos que realmente cambiaron.
     */
    public static function actualizacion(array $usuario, string $tabla, $registroId, ?array $antes, ?array $despues): void
    {
        if (!$antes || !$despues) {
            return;
        }

        $filas = [];
        foreach ($despues as $campo => $valorNuevo) {
            if (!array_key_exists($campo, $antes)) {
                continue;
            }
            $valorAnterior = $antes[$campo];

            if (self::sonIguales($valorAnterior, $valorNuevo)) {
                continue;
            }

            $filas[] = [
                $campo,
                self::valor($campo, $valorAnterior),
                self::valor($campo, $valorNuevo),
            ];
        }

        if (!$filas) {
            return;   // se guardó, pero ningún dato cambió: no se registra ruido
        }

        self::guardar($usuario, $tabla, $registroId, 'UPDATE', $filas);
    }

    /** Baja: se conserva el valor que tenía cada campo antes de eliminarse. */
    public static function eliminacion(array $usuario, string $tabla, $registroId, ?array $antes): void
    {
        $filas = [];
        foreach (($antes ?? []) as $campo => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }
            $filas[] = [$campo, self::valor($campo, $valor), null];
        }

        if (!$filas) {
            $filas[] = [null, null, null];
        }

        self::guardar($usuario, $tabla, $registroId, 'DELETE', $filas);
    }

    /**
     * Cambio de una lista asociada (roles de un usuario, permisos de un rol,
     * tipos de dato de un consentimiento): una sola anotación con la lista
     * completa antes y después.
     */
    public static function cambioLista(array $usuario, string $tabla, $registroId, string $campo, string $antes, string $despues): void
    {
        if ($antes === $despues) {
            return;
        }

        self::guardar($usuario, $tabla, $registroId, 'UPDATE', [[
            $campo,
            self::valor($campo, $antes === '' ? null : $antes),
            self::valor($campo, $despues === '' ? null : $despues),
        ]]);
    }

    /* ------------------------------------------------------------------ */
    /* Utilidades                                                          */
    /* ------------------------------------------------------------------ */

    /** Compara valores tolerando diferencias de tipo entre PHP y MySQL. */
    private static function sonIguales($a, $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        return (string)$a === (string)$b;
    }

    /** Oculta contraseñas y recorta textos demasiado largos. */
    private static function valor(?string $campo, $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        if ($campo !== null && in_array($campo, self::CAMPOS_SENSIBLES, true)) {
            return self::OCULTO;
        }

        $texto = is_scalar($valor) ? (string)$valor : json_encode($valor, JSON_UNESCAPED_UNICODE);

        return mb_strlen($texto) > 1000 ? mb_substr($texto, 0, 1000) . '…' : $texto;
    }

    /** IP del cliente, considerando proxys del hosting. */
    private static function ip(): ?string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $clave) {
            if (!empty($_SERVER[$clave])) {
                $ip = trim(explode(',', (string)$_SERVER[$clave])[0]);
                return mb_substr($ip, 0, 45);
            }
        }
        return null;
    }

    /** @param array $filas [[campo, valorAnterior, valorNuevo], ...] */
    private static function guardar(array $usuario, string $tabla, $registroId, string $operacion, array $filas): void
    {
        if (!self::$disponible) {
            return;
        }

        try {
            $stmt = Database::conexion()->prepare(
                'INSERT INTO auditoria
                    (InstitucionEducativaId, FechaHora, UsuarioId, Username, IpOrigen,
                     Tabla, RegistroId, Operacion, Campo, ValorAnterior, ValorNuevo)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $institucion = (int)($usuario['institucion_id'] ?? 0);
            $usuarioId   = $usuario['usuario_id'] ?? null;
            $username    = $usuario['username'] ?? null;
            $ip          = self::ip();
            $registro    = $registroId === null ? null : mb_substr((string)$registroId, 0, 64);

            foreach ($filas as [$campo, $anterior, $nuevo]) {
                $stmt->execute([
                    $institucion, $usuarioId, $username, $ip,
                    $tabla, $registro, $operacion, $campo, $anterior, $nuevo,
                ]);
            }
        } catch (PDOException $e) {
            // 42S02 = la tabla no existe todavía: se desactiva para no insistir
            if ($e->getCode() === '42S02') {
                self::$disponible = false;
                error_log('[API] La tabla `auditoria` no existe. Ejecute BaseDatos/01_DDL_estructura.sql');
                return;
            }
            error_log('[API] No se pudo registrar la auditoría: ' . $e->getMessage());
        }
    }
}
