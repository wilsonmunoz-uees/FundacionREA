<?php
/**
 * api/core/PlantillaCorreo.php
 * -----------------------------------------------------------------------------
 * Carga la plantilla del correo de confirmación del consentimiento.
 *
 * El contenido vive en `plantillas/correo_confirmacion.php`, fuera de la API,
 * para que la institución pueda editar el texto sin tocar el código. Si el
 * archivo se borra o queda con un error, se usa un texto de respaldo y el
 * problema se anota en el log: el correo sale igual.
 * -----------------------------------------------------------------------------
 */

final class PlantillaCorreo
{
    /**
     * @param array $datos tipo, decision, titular, identificacion, documento,
     *                     representante, es_representante, institucion, version, fecha
     */
    public static function confirmacion(array $datos): string
    {
        $ruta = dirname(__DIR__, 2) . '/plantillas/correo_confirmacion.php';

        if (is_file($ruta)) {
            $html = self::renderizar($ruta, $datos);
            if (trim($html) !== '') {
                return $html;
            }
        }

        return self::respaldo($datos);
    }

    /**
     * Correo con el código de verificación de los enlaces con verificación.
     *
     * El contenido vive en `plantillas/correo_codigo_verificacion.php`, para
     * que la institución pueda editar el texto sin tocar el código.
     *
     * @param array $datos codigo, titular, identificacion, documento, tipo,
     *                     es_representante, institucion, minutos, expira, fecha
     */
    public static function codigoVerificacion(array $datos): string
    {
        $ruta = dirname(__DIR__, 2) . '/plantillas/correo_codigo_verificacion.php';

        if (is_file($ruta)) {
            $html = self::renderizar($ruta, $datos);
            if (trim($html) !== '') {
                return $html;
            }
        }

        return self::respaldoCodigo($datos);
    }

    /** Texto mínimo por si la plantilla del código no está disponible. */
    private static function respaldoCodigo(array $datos): string
    {
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $saludo = !empty($datos['es_representante'])
            ? 'Estimado/a representante de <strong>' . $e($datos['titular']) . '</strong>:'
            : 'Estimado/a <strong>' . $e($datos['titular']) . '</strong>:';

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#243447;">'
            . '<p>' . $saludo . '</p>'
            . '<p>Este es su código para continuar con el consentimiento de datos personales en '
            . $e($datos['institucion']) . ':</p>'
            . '<p style="font-size:30px;font-weight:bold;letter-spacing:8px;color:#1F4E79;">'
            . $e($datos['codigo']) . '</p>'
            . '<p>El código es válido durante ' . (int)($datos['minutos'] ?? 10)
            . ' minutos, hasta las ' . $e($datos['expira']) . '.</p>'
            . '<p style="color:#6b7a8d;font-size:13px;">Si usted no solicitó este código, ignore este '
            . 'mensaje: sin el código nadie puede continuar. Nunca lo comparta con terceros.</p>'
            . '</div>';
    }

    /**
     * Correo de invitación al consentimiento, el del Envío Masivo.
     *
     * El contenido vive en `plantillas/correo_invitacion_consentimiento.php`,
     * para que la institución pueda editar el texto sin tocar el código.
     *
     * @param array $datos tipo, titular, identificacion, documento,
     *                     es_representante, representante, institucion, enlace,
     *                     version, fecha
     */
    public static function invitacionConsentimiento(array $datos): string
    {
        $ruta = dirname(__DIR__, 2) . '/plantillas/correo_invitacion_consentimiento.php';

        if (is_file($ruta)) {
            $html = self::renderizar($ruta, $datos);
            if (trim($html) !== '') {
                return $html;
            }
        }

        return self::respaldoInvitacion($datos);
    }

    /** Texto mínimo por si la plantilla de invitación no está disponible. */
    private static function respaldoInvitacion(array $datos): string
    {
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $saludo = !empty($datos['es_representante'])
            ? 'Estimado/a representante de <strong>' . $e($datos['titular']) . '</strong>:'
            : 'Estimado/a <strong>' . $e($datos['titular']) . '</strong>:';

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#243447;">'
            . '<p>' . $saludo . '</p>'
            . '<p>' . $e($datos['institucion'] ?? '') . ' le solicita registrar su decisión sobre el '
            . 'tratamiento de los datos personales. Abra el siguiente enlace, que ya lleva su '
            . 'documento cargado:</p>'
            . '<p><a href="' . $e($datos['enlace'] ?? '#') . '">' . $e($datos['enlace'] ?? '') . '</a></p>'
            . '<p style="color:#6b7a8d;font-size:13px;">La pantalla le enviará un código de '
            . 'verificación a este mismo correo. No se le pedirán contraseñas ni datos de pago.</p>'
            . '</div>';
    }

    /** Ejecuta la plantilla en un ámbito aislado y devuelve lo que imprime. */
    private static function renderizar(string $ruta, array $datos): string
    {
        $render = static function (string $__ruta, array $datos): string {
            ob_start();
            try {
                include $__ruta;
            } catch (Throwable $e) {
                ob_end_clean();
                error_log('[API] Plantilla de correo con error: ' . $e->getMessage());
                return '';
            }
            return (string)ob_get_clean();
        };

        return $render($ruta, $datos);
    }

    /** Texto mínimo por si la plantilla no está disponible. */
    private static function respaldo(array $datos): string
    {
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        $otorga = ($datos['decision'] ?? '') === 'OTORGA';

        $saludo = !empty($datos['es_representante'])
            ? 'Estimado/a representante de <strong>' . $e($datos['titular']) . '</strong>:'
            : 'Estimado/a <strong>' . $e($datos['titular']) . '</strong>:';

        return '<p>' . $saludo . '</p>'
            . '<p>' . $e($datos['institucion']) . ' confirma que el '
            . $e($datos['fecha'] ?? '') . ' quedó registrada su decisión: '
            . '<strong>' . ($otorga ? 'consentimiento otorgado' : 'consentimiento revocado') . '</strong> '
            . 'para el tratamiento de datos personales (política versión ' . $e($datos['version'] ?? '') . ').</p>'
            . '<p>Si usted no realizó esta acción, comuníquese con la institución.</p>';
    }
}
