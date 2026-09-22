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
            'avisos'           => self::revisar($config),
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
        /* Con SMTP activo el remitente deja de ser opcional. Antes, si se
           dejaba vacío, el sistema inventaba no-responder@<dominio del sitio>,
           y casi ningún servidor acepta un MAIL FROM de un dominio que no es
           suyo: el correo no salía y el motivo —«sender verify failed»— no
           apuntaba a este campo por ninguna parte. */
        if ($activo === 'SI' && $remitente === null) {
            $errores[] = 'Indique el correo del remitente: es la dirección desde la que '
                       . 'saldrán los mensajes, y el servidor la comprueba.';
        }
        if ($remitente !== null && !CorreoElectronico::esValido($remitente)) {
            $errores[] = 'La dirección del remitente no es válida: '
                       . (CorreoElectronico::problema($remitente) ?? '');
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

        // La bitácora anota qué campos se tocaron, nunca sus valores: la clave
        // del servidor no llega a escribirse en ninguna parte legible.
        $this->auditarActualizacion('correo_configuracion', 'InstitucionEducativaId', $institucionId, $anterior);

        Response::exito(['mensaje' => 'Configuración de correo guardada correctamente.']);
    }

    /** POST /api/correo/probar */
    public function probar(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);

        $destino = CorreoElectronico::normalizar($this->peticion->texto('correo'));
        if (!CorreoElectronico::esValido($destino)) {
            Response::validacion([
                'Indique una dirección de correo válida para la prueba: '
                . (CorreoElectronico::problema($destino) ?? ''),
            ]);
        }

        $correo = Correo::desdeConfiguracion($config = self::configuracionDe($this->db, $this->institucion()));

        $html = '<p>Este es un mensaje de prueba del Sistema de Gestión de Protección de Datos '
              . 'de la Red Educativa Arquidiocesana.</p>'
              . '<p>Si lo está leyendo, la configuración de correo funciona correctamente.</p>';

        $ok = $correo->enviar($destino, '', 'Prueba de configuración de correo — REA', $html);

        /* La conversación se recoge antes de cerrar por si quedara algo
           abierto. Puede terminar igualmente en «QUIT / 221 closing
           connection» —cuando el fallo es de autenticación, la conexión se
           cierra sola ahí mismo—, y está bien que se vea: ese 221 es la
           despedida correcta del servidor, y verlo debajo del error real es lo
           que deja claro que no era él quien fallaba. */
        $dialogo = $correo->dialogo();
        $correo->cerrar();

        if (!$ok) {
            Response::error(
                'No se pudo enviar el mensaje de prueba. ' . $correo->ultimoError(),
                502,
                [],
                ['dialogo' => $dialogo, 'avisos' => self::revisar($config)]
            );
        }

        Response::exito([
            'mensaje' => 'Mensaje de prueba enviado a ' . $destino . '.',
            'via'     => $correo->usaSmtp() ? 'SMTP' : 'mail() de PHP',
            'dialogo' => $dialogo,
            'avisos'  => self::revisar($config),
        ]);
    }

    /**
     * Dominios de correo público. Con ellos la comparación contra el MX no
     * dice nada: el servidor de salida y el de entrada son distintos por
     * diseño —smtp.gmail.com envía, google.com recibe— y avisar sería ruido.
     */
    private const CORREO_PUBLICO = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'outlook.es', 'hotmail.com',
        'live.com', 'msn.com', 'yahoo.com', 'yahoo.es', 'icloud.com', 'me.com',
        'zoho.com', 'proton.me', 'protonmail.com',
    ];

    /**
     * Revisa la configuración y devuelve avisos en lenguaje llano.
     *
     * No son errores: hay montajes perfectamente válidos en los que el
     * servidor de salida no tiene nada que ver con el que recibe —cualquier
     * servicio de envío lo es—. El aviso apunta solo al caso que cuesta horas
     * descubrir: el servidor configurado es el del propio dominio, pero el
     * correo de ese dominio ya lo recibe otro proveedor. Eso significa que el
     * dominio se mudó y esta pantalla se quedó atrás, con lo que el buzón que
     * intenta autenticarse probablemente ya no exista donde se le busca.
     *
     * @return array<int, array{tipo: string, texto: string}>
     */
    public static function revisar(?array $config): array
    {
        $avisos = [];

        if (empty($config) || ($config['Activo'] ?? 'NO') !== 'SI') {
            return $avisos;
        }

        $servidor  = strtolower(trim((string)($config['Servidor'] ?? '')));
        $usuario   = trim((string)($config['Usuario'] ?? ''));
        $remitente = trim((string)($config['RemitenteCorreo'] ?? ''));

        if ($servidor === '') {
            return $avisos;
        }

        $dominioRemitente = self::dominioDe($remitente);
        $dominioUsuario   = self::dominioDe($usuario);

        if ($dominioRemitente !== '' && $dominioUsuario !== ''
            && $dominioRemitente !== $dominioUsuario) {
            $avisos[] = [
                'tipo'  => 'aviso',
                'texto' => 'El remitente es de «' . $dominioRemitente . '» pero el usuario SMTP es '
                         . 'de «' . $dominioUsuario . '». Muchos servidores rechazan un mensaje '
                         . 'cuyo remitente no corresponde a la cuenta con la que se autentica.',
            ];
        }

        $aviso = self::avisoPorMx($servidor, $dominioRemitente, $usuario,
                                  self::mxDe($dominioRemitente));
        if ($aviso !== null) {
            $avisos[] = $aviso;
        }

        return $avisos;
    }

    /**
     * El aviso de «su dominio ya no recibe el correo aquí», o null.
     *
     * Se separa de la consulta al DNS para poder probarlo sin depender de la
     * red, que es justo lo que no se puede reproducir cuando hace falta.
     *
     * @param string[] $mx Servidores de entrada del dominio, el primero el de
     *                     mayor prioridad. Vacío si no se pudo consultar.
     */
    public static function avisoPorMx(string $servidor, string $dominio, string $usuario, array $mx): ?array
    {
        if ($dominio === '' || $mx === []) {
            return null;
        }
        if (in_array($dominio, self::CORREO_PUBLICO, true)) {
            return null;
        }
        // Solo interesa cuando el servidor configurado es el del propio
        // dominio. Si es el de un servicio externo, que no coincida con el MX
        // es lo esperado y no hay nada que advertir.
        if ($servidor !== $dominio && !str_ends_with($servidor, '.' . $dominio)) {
            return null;
        }
        // Se mira el MX de mayor prioridad: es el que recibe de verdad. Un
        // dominio mudado suele conservar el servidor viejo como MX de reserva,
        // y compararse contra ese ocultaría precisamente el problema.
        $principal = $mx[0];
        if ($principal === $dominio || str_ends_with($principal, '.' . $dominio)) {
            return null;
        }

        return [
            'tipo'  => 'aviso',
            'texto' => 'El correo de «' . $dominio . '» lo recibe ' . $principal . ', no ' . $servidor
                     . '. Si los buzones del dominio se mudaron a ese proveedor, «' . $usuario
                     . '» probablemente ya no exista en ' . $servidor . ' y la autenticación '
                     . 'fallará. Para seguir enviando por ' . $servidor . ' hace falta una cuenta '
                     . 'de correo creada en ese servidor.',
        ];
    }

    /** Dominio de una dirección, en minúsculas y sin el punto final. */
    private static function dominioDe(string $correo): string
    {
        $arroba = strrpos($correo, '@');
        if ($arroba === false) {
            return '';
        }
        return rtrim(strtolower(substr($correo, $arroba + 1)), '.');
    }

    /**
     * Servidores que reciben el correo de un dominio, el primero el de mayor
     * prioridad. Devuelve [] si el hospedaje no permite consultar el DNS.
     */
    private static function mxDe(string $dominio): array
    {
        if ($dominio === '' || !function_exists('getmxrr')) {
            return [];
        }
        $hosts = [];
        $pesos = [];
        if (!@getmxrr($dominio, $hosts, $pesos) || !$hosts) {
            return [];
        }
        array_multisort($pesos, $hosts);

        return array_map(static fn($h) => rtrim(strtolower($h), '.'), $hosts);
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
