<?php
/**
 * api/core/InvitacionConsentimiento.php
 * -----------------------------------------------------------------------------
 * Envío del correo que invita a otorgar el consentimiento, a UNA sola persona.
 *
 * El mismo correo que reparte el Envío Masivo, pero de uno en uno. Lo usan las
 * pantallas de empleados, estudiantes y proveedores: cada vez que alguien
 * corrige los datos de contacto de un titular, ese titular recibe la invitación
 * con sus datos ya actualizados.
 *
 * Que el correo salga solo tiene un motivo que va más allá de la comodidad: la
 * LOPDP exige que el consentimiento se recoja sobre información exacta. Si la
 * dirección de contacto cambió, el consentimiento anterior se otorgó sobre un
 * dato que ya no es el vigente, y conviene volver a pedirlo.
 *
 * El envío NUNCA hace fracasar el guardado: los datos ya están grabados cuando
 * esto se ejecuta. Si el correo no sale, se devuelve el motivo para que la
 * pantalla lo diga con claridad, y se puede reintentar desde el Envío Masivo.
 * -----------------------------------------------------------------------------
 */

final class InvitacionConsentimiento
{
    /** Asunto del correo. El mismo que usa el envío masivo. */
    public const ASUNTO = 'Consentimiento para el tratamiento de sus datos personales';

    /**
     * Envía la invitación a una persona.
     *
     * @param string $tipo   EMPLEADO | ESTUDIANTE | PROVEEDOR
     * @param array  $datos  destino, titular, identificacion, representante
     * @return array{enviado:bool, destino:string, motivo:string, mensaje:string}
     *         `mensaje` viene ya redactado para mostrarlo tal cual en pantalla.
     */
    public static function enviarA(PDO $db, int $institucionId, string $tipo, array $datos): array
    {
        $destino = trim((string)($datos['destino'] ?? ''));

        if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            return self::conMensaje([
                'enviado' => false,
                'destino' => $destino,
                'motivo'  => $destino === ''
                    ? 'no tiene un correo registrado'
                    : 'el correo registrado no es una dirección válida',
            ]);
        }

        try {
            $institucion = self::institucion($db, $institucionId);
            $disclaimer  = DisclaimersController::vigente($db, $institucionId, $tipo);

            $html = PlantillaCorreo::invitacionConsentimiento([
                'tipo'             => $tipo,
                'titular'          => (string)($datos['titular'] ?? ''),
                'identificacion'   => (string)($datos['identificacion'] ?? ''),
                'documento'        => ConsentimientoPublicoController::DOCUMENTO[$tipo] ?? 'documento',
                'es_representante' => $tipo === 'ESTUDIANTE',
                'representante'    => (string)($datos['representante'] ?? ''),
                'institucion'      => (string)($institucion['nombre'] ?? ''),
                'enlace'           => self::enlace($tipo, $institucionId, (string)($datos['identificacion'] ?? '')),
                'version'          => $disclaimer['Version'] ?? '',
                'fecha'            => date('d/m/Y'),
            ]);

            $correo = Correo::desdeConfiguracion(
                CorreoConfiguracionController::configuracionDe($db, $institucionId)
            );

            /* A quién se saluda: en estudiantes el correo va al representante,
               así que el saludo es para él aunque el titular sea el alumno. */
            $nombreDestino = $tipo === 'ESTUDIANTE' && trim((string)($datos['representante'] ?? '')) !== ''
                ? trim((string)$datos['representante'])
                : (string)($datos['titular'] ?? '');

            $ok = $correo->enviar($destino, $nombreDestino, self::ASUNTO, $html);
            $motivo = $ok ? '' : (string)$correo->ultimoError();
            $correo->cerrar();

            return self::conMensaje(['enviado' => $ok, 'destino' => $destino, 'motivo' => $motivo]);
        } catch (Throwable $e) {
            /* Un fallo aquí no puede tumbar la petición: el registro ya se
               guardó. Se anota y se informa. */
            error_log('[API] InvitacionConsentimiento: ' . $e->getMessage());

            return self::conMensaje(['enviado' => false, 'destino' => $destino, 'motivo' => $e->getMessage()]);
        }
    }

    /**
     * Enlace público con verificación, el mismo que reparte el envío masivo.
     *
     * Se arma a partir de la petición en curso y no de una constante, para que
     * funcione en cualquier hospedaje sin tener que configurar la URL base.
     */
    public static function enlace(string $tipo, int $institucionId, string $identificacion): string
    {
        $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // /api/... -> raíz del sitio
        $script  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/api/index.php');
        $carpeta = rtrim(preg_replace('#/api(/.*)?$#', '', $script) ?? '', '/');

        return ($esHttps ? 'https' : 'http') . '://' . $host . $carpeta
            . '/consentimiento_verificado.php'
            . '?tipo=' . urlencode(mb_strtolower($tipo))
            . '&inst=' . $institucionId
            . '&doc='  . urlencode($identificacion);
    }

    /** Añade al resultado la frase ya redactada para la pantalla. */
    private static function conMensaje(array $resultado): array
    {
        return $resultado + ['mensaje' => self::mensaje($resultado)];
    }

    /** Frase para la pantalla, ya redactada. */
    public static function mensaje(array $resultado): string
    {
        if ($resultado['enviado']) {
            return 'Se envió a ' . $resultado['destino']
                 . ' la solicitud de consentimiento para el tratamiento de sus datos personales, '
                 . 'con la información recién actualizada.';
        }

        return 'Los datos se guardaron, pero no se pudo enviar la solicitud de consentimiento'
             . ($resultado['destino'] !== '' ? ' a ' . $resultado['destino'] : '')
             . ': ' . ($resultado['motivo'] !== '' ? $resultado['motivo'] : 'error desconocido')
             . '. Puede reintentarlo desde «Envío Masivo de Invitaciones».';
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
