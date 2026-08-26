<?php
/**
 * api/controllers/CorreoConfiguracionController.php
 * -----------------------------------------------------------------------------
 * Servidor de correo saliente de la institución.
 *
 * El sistema envía un correo de confirmación cuando alguien otorga o revoca su
 * consentimiento desde los enlaces públicos. Aquí se define por dónde sale ese
 * correo: un servidor SMTP propio o, si no se configura ninguno, la función
 * mail() de PHP.
 *
 * La contraseña se guarda pero nunca se devuelve a la pantalla; tampoco queda
 * legible en la bitácora de auditoría.
 * -----------------------------------------------------------------------------
 */

final class CorreoConfiguracionController extends Controller
{
    /** Clave en includes/accesos.php */
    private const MODULO = 'correo_configuracion';

    /** GET /api/correo/configuracion */
    public function ver(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);

        $config = self::configuracionDe($this->db, $this->institucion());

        Response::exito([
            'servidor'         => $config['Servidor'] ?? '',
            'puerto'           => (int)($config['Puerto'] ?? 587),
            'seguridad'        => $config['Seguridad'] ?? 'TLS',
            'usuario'          => $config['Usuario'] ?? '',
            'clave_definida'   => !empty($config['Clave']),
            'remitente_correo' => $config['RemitenteCorreo'] ?? '',
            'remitente_nombre' => $config['RemitenteNombre'] ?? '',
            'activo'           => ($config['Activo'] ?? 'NO') === 'SI',
            'actualizado'      => $config['Actualizado'] ?? null,
        ]);
    }

    /** PUT /api/correo/configuracion */
    public function guardar(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        $activo    = $this->peticion->dato('activo') ? 'SI' : 'NO';
        $servidor  = $this->oNulo($this->peticion->texto('servidor'));
        $puerto    = $this->peticion->entero('puerto') ?: 587;
        $seguridad = strtoupper($this->peticion->texto('seguridad', 'TLS'));
        $remitente = $this->oNulo($this->peticion->texto('remitente_correo'));

        if (!in_array($seguridad, ['NINGUNA', 'TLS', 'SSL'], true)) {
            $seguridad = 'TLS';
        }

        $errores = [];
        if ($activo === 'SI' && $servidor === null) {
            $errores[] = 'Indique el servidor SMTP o desactive el envío por SMTP.';
        }
        if ($remitente !== null && !filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'La dirección del remitente no es válida.';
        }
        if ($puerto < 1 || $puerto > 65535) {
            $errores[] = 'El puerto debe estar entre 1 y 65535.';
        }
        if ($errores) {
            Response::validacion($errores);
        }

        $anterior = self::configuracionDe($this->db, $institucionId);

        // Sin clave nueva se conserva la guardada
        $claveNueva = (string)$this->peticion->dato('clave', '');
        $clave = $claveNueva !== '' ? $claveNueva : ($anterior['Clave'] ?? null);

        $this->ejecutar(
            'INSERT INTO correo_configuracion
                (InstitucionEducativaId, Servidor, Puerto, Seguridad, Usuario, Clave,
                 RemitenteCorreo, RemitenteNombre, Activo, Actualizado)
             VALUES (?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE
                Servidor = VALUES(Servidor), Puerto = VALUES(Puerto), Seguridad = VALUES(Seguridad),
                Usuario = VALUES(Usuario), Clave = VALUES(Clave), RemitenteCorreo = VALUES(RemitenteCorreo),
                RemitenteNombre = VALUES(RemitenteNombre), Activo = VALUES(Activo), Actualizado = NOW()',
            [
                $institucionId, $servidor, $puerto, $seguridad,
                $this->oNulo($this->peticion->texto('usuario')), $clave,
                $remitente, $this->oNulo($this->peticion->texto('remitente_nombre')), $activo,
            ]
        );

        // La clave queda enmascarada en la bitácora (ver Auditoria::CAMPOS_SENSIBLES)
        $this->auditarActualizacion('correo_configuracion', 'InstitucionEducativaId', $institucionId, $anterior);

        Response::exito(['mensaje' => 'Configuración de correo guardada correctamente.']);
    }

    /** POST /api/correo/probar */
    public function probar(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);

        $destino = $this->peticion->texto('correo');
        if (!filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            Response::validacion(['Indique una dirección de correo válida para la prueba.']);
        }

        $correo = Correo::desdeConfiguracion(self::configuracionDe($this->db, $this->institucion()));

        $html = '<p>Este es un mensaje de prueba del Sistema de Gestión de Protección de Datos '
              . 'de la Red Educativa Arquidiocesana.</p>'
              . '<p>Si lo está leyendo, la configuración de correo funciona correctamente.</p>';

        $ok = $correo->enviar($destino, '', 'Prueba de configuración de correo — REA', $html);
        $correo->cerrar();

        if (!$ok) {
            Response::error('No se pudo enviar el mensaje de prueba. ' . $correo->ultimoError(), 502);
        }

        Response::exito([
            'mensaje' => 'Mensaje de prueba enviado a ' . $destino . '.',
            'via'     => $correo->usaSmtp() ? 'SMTP' : 'mail() de PHP',
        ]);
    }

    /**
     * Configuración de una institución. Es estática y recibe la conexión para
     * que también pueda usarla el flujo público de consentimiento, que no
     * pertenece a este controlador.
     */
    public static function configuracionDe(PDO $db, int $institucionId): ?array
    {
        $stmt = $db->prepare('SELECT * FROM correo_configuracion WHERE InstitucionEducativaId = ?');
        $stmt->execute([$institucionId]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }
}
