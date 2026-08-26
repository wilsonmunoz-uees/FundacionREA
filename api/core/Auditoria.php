<?php
/**
 * api/core/Auditoria.php
 * -----------------------------------------------------------------------------
 * Bitácora general de la base de datos.
 *
 * Cada alta, cambio o baja realizada a través de la API deja constancia en la
 * tabla `auditoria`: institución, usuario, fecha y hora, IP de origen, tabla y
 * registro afectados, y **qué campo** se tocó.
 *
 * La bitácora anota el QUÉ, no el DATO: registra que se modificó, por ejemplo,
 * el correo de una persona, pero no guarda ni el correo anterior ni el nuevo.
 * Así la propia bitácora de un sistema de protección de datos no se convierte en
 * una segunda copia —sin control de acceso propio y sin caducidad— de los datos
 * personales que custodia. Para saber qué dice hoy un registro está su pantalla;
 * para saber quién lo tocó y cuándo, está esta bitácora.
 *
 * Se registra una fila por cada campo afectado, de modo que el reporte puede
 * decir exactamente qué cambió en cada operación.
 *
 * Si la tabla `auditoria` todavía no existe (script 01_DDL_estructura.sql), el
 * registro se omite en silencio para no interrumpir la operación del sistema.
 * -----------------------------------------------------------------------------
 */

final class Auditoria
{
    /** Se desactiva sola si la tabla no existe, para no repetir el error. */
    private static bool $disponible = true;

    /* ------------------------------------------------------------------ */
    /* Registro de operaciones                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Alta de un registro: se anotan los campos que vinieron con dato.
     *
     * @param array $usuario Contexto devuelto por Auth::usuarioDe()
     * @param array $datos   Fila recién insertada (columna => valor)
     */
    public static function insercion(array $usuario, string $tabla, $registroId, array $datos): void
    {
        $campos = [];
        foreach ($datos as $campo => $valor) {
            if ($valor === null || $valor === '') {
                continue;   // no se anotan los campos que quedaron vacíos
            }
            $campos[] = $campo;
        }

        if (!$campos) {
            $campos[] = null;   // deja constancia del alta aunque no haya datos
        }

        self::guardar($usuario, $tabla, $registroId, 'INSERT', $campos);
    }

    /**
     * Cambio: se comparan los valores antes y después para saber qué campos
     * cambiaron realmente. La comparación ocurre solo en memoria; de ella queda
     * el nombre del campo, nunca el valor.
     */
    public static function actualizacion(array $usuario, string $tabla, $registroId, ?array $antes, ?array $despues): void
    {
        if (!$antes || !$despues) {
            return;
        }

        $campos = [];
        foreach ($despues as $campo => $valorNuevo) {
            if (!array_key_exists($campo, $antes)) {
                continue;
            }
            if (self::sonIguales($antes[$campo], $valorNuevo)) {
                continue;
            }
            $campos[] = $campo;
        }

        if (!$campos) {
            return;   // se guardó, pero ningún dato cambió: no se registra ruido
        }

        self::guardar($usuario, $tabla, $registroId, 'UPDATE', $campos);
    }

    /** Baja: se anota qué campos tenía el registro que se eliminó. */
    public static function eliminacion(array $usuario, string $tabla, $registroId, ?array $antes): void
    {
        $campos = [];
        foreach (($antes ?? []) as $campo => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }
            $campos[] = $campo;
        }

        if (!$campos) {
            $campos[] = null;
        }

        self::guardar($usuario, $tabla, $registroId, 'DELETE', $campos);
    }

    /**
     * Cambio de una lista asociada (roles de un usuario, permisos de un rol,
     * tipos de dato de un consentimiento): una sola anotación con el nombre de
     * la lista que cambió.
     *
     * Conserva los parámetros $antes y $despues porque es quien los compara para
     * decidir si hubo cambio; su contenido no se graba.
     */
    public static function cambioLista(array $usuario, string $tabla, $registroId, string $campo, string $antes, string $despues): void
    {
        if ($antes === $despues) {
            return;
        }

        self::guardar($usuario, $tabla, $registroId, 'UPDATE', [$campo]);
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
        if (is_bool($a) || is_bool($b)) {
            return (bool)$a === (bool)$b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (string)$a === (string)$b || abs((float)$a - (float)$b) < 0.000001;
        }
        return (string)$a === (string)$b;
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

    /** @param array<int, string|null> $campos Nombres de los campos afectados */
    private static function guardar(array $usuario, string $tabla, $registroId, string $operacion, array $campos): void
    {
        if (!self::$disponible) {
            return;
        }

        try {
            $stmt = Database::conexion()->prepare(
                'INSERT INTO auditoria
                    (InstitucionEducativaId, FechaHora, UsuarioId, Username, IpOrigen,
                     Tabla, RegistroId, Operacion, Campo)
                 VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, ?)'
            );

            $institucion = (int)($usuario['institucion_id'] ?? 0);
            $usuarioId   = $usuario['usuario_id'] ?? null;
            $username    = $usuario['username'] ?? null;
            $ip          = self::ip();
            $registro    = $registroId === null ? null : mb_substr((string)$registroId, 0, 64);

            foreach ($campos as $campo) {
                $stmt->execute([
                    $institucion, $usuarioId, $username, $ip,
                    $tabla, $registro, $operacion,
                    $campo === null ? null : mb_substr((string)$campo, 0, 64),
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
