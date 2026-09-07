<?php
/**
 * api/core/ClaveTemporal.php
 * -----------------------------------------------------------------------------
 * Envío de la contraseña temporal de una cuenta nueva o restablecida.
 *
 * Es el único camino por el que una contraseña sale del sistema, y va derecha al
 * titular de la cuenta: quien la crea nunca la ve, ni en la pantalla ni en la
 * respuesta de la API. Con eso, administrar cuentas deja de dar acceso a ellas.
 *
 * Como toda contraseña que alguien más generó, es de un solo uso: la cuenta
 * queda marcada con `usuario`.`DebeCambiarClave = 'SI'` y el ingreso no lleva a
 * ninguna parte hasta que su dueño fije la suya.
 *
 * El envío NUNCA hace fracasar el alta: la cuenta ya está creada cuando esto se
 * ejecuta. Si el correo no sale, se devuelve el motivo para que la pantalla lo
 * diga y se pueda restablecer la clave desde la edición del usuario.
 * -----------------------------------------------------------------------------
 */

final class ClaveTemporal
{
    public const ASUNTO = 'Sus credenciales de acceso al sistema de protección de datos';

    /**
     * Envía la contraseña temporal a su titular.
     *
     * @param array $datos destino, username, clave, es_nueva
     * @return array{enviado:bool, destino:string, motivo:string, mensaje:string}
     *         `mensaje` viene ya redactado para mostrarlo tal cual en pantalla.
     */
    public static function enviar(PDO $db, int $institucionId, array $datos): array
    {
        $destino = trim((string)($datos['destino'] ?? ''));

        if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            return self::conMensaje([
                'enviado' => false,
                'destino' => $destino,
                'motivo'  => 'la persona no tiene un correo válido registrado',
            ]);
        }

        try {
            $institucion = self::institucion($db, $institucionId);

            $html = PlantillaCorreo::claveTemporal([
                'institucion' => (string)($institucion['nombre'] ?? ''),
                'username'    => (string)($datos['username'] ?? ''),
                'clave'       => (string)($datos['clave'] ?? ''),
                'es_nueva'    => $datos['es_nueva'] ?? true,
                'enlace'      => self::enlaceLogin(),
            ]);

            $correo = Correo::desdeConfiguracion(
                CorreoConfiguracionController::configuracionDe($db, $institucionId)
            );

            $ok     = $correo->enviar($destino, (string)($datos['username'] ?? ''), self::ASUNTO, $html);
            $motivo = $ok ? '' : (string)$correo->ultimoError();
            $correo->cerrar();

            return self::conMensaje(['enviado' => $ok, 'destino' => $destino, 'motivo' => $motivo]);
        } catch (Throwable $e) {
            error_log('[API] ClaveTemporal: ' . $e->getMessage());

            return self::conMensaje([
                'enviado' => false,
                'destino' => $destino,
                'motivo'  => $e->getMessage(),
            ]);
        }
    }

    /** Dirección de la pantalla de ingreso, armada desde la petición en curso. */
    private static function enlaceLogin(): string
    {
        $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        $host    = $_SERVER['HTTP_HOST'] ?? '';
        $script  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/api/index.php');
        $carpeta = rtrim(preg_replace('#/api(/.*)?$#', '', $script) ?? '', '/');

        if ($host === '') {
            return '';
        }

        return ($esHttps ? 'https' : 'http') . '://' . $host . $carpeta . '/login.php';
    }

    private static function conMensaje(array $resultado): array
    {
        return $resultado + ['mensaje' => self::mensaje($resultado)];
    }

    /** Frase para la pantalla, ya redactada. */
    public static function mensaje(array $resultado): string
    {
        if ($resultado['enviado']) {
            return 'Se enviaron las credenciales de acceso a ' . $resultado['destino']
                 . '. La contraseña es temporal: el sistema le exigirá cambiarla al ingresar.';
        }

        return 'La cuenta quedó creada, pero no se pudo enviar la contraseña'
             . ($resultado['destino'] !== '' ? ' a ' . $resultado['destino'] : '')
             . ': ' . ($resultado['motivo'] !== '' ? $resultado['motivo'] : 'error desconocido')
             . '. Use «Restablecer contraseña» en la edición del usuario para volver a intentarlo.';
    }

    /** Datos de la institución, cacheados por petición. */
    private static function institucion(PDO $db, int $institucionId): array
    {
        static $cache = [];

        if (isset($cache[$institucionId])) {
            return $cache[$institucionId];
        }

        $stmt = $db->prepare('SELECT id, nombre FROM institucion_educativa WHERE id = ?');
        $stmt->execute([$institucionId]);
        $fila = $stmt->fetch();

        return $cache[$institucionId] = ($fila === false ? [] : $fila);
    }
}
