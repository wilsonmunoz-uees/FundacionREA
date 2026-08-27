<?php
/**
 * api/controllers/VerificacionPublicaController.php
 * -----------------------------------------------------------------------------
 * Endpoints PÚBLICOS de los enlaces de consentimiento CON VERIFICACIÓN.
 *
 * Son la variante prudente de los enlaces abiertos: aquí la persona NO puede
 * darse de alta ni modificar nada. El enlace solo consulta si ya está
 * registrada y, antes de dejarla decidir sobre sus datos, comprueba que es
 * quien dice ser enviándole un código al correo que consta en el sistema.
 *
 * El recorrido tiene cuatro pasos:
 *
 *   1. consultar      → ¿existe esta cédula o RUC en la institución? Si no
 *                       existe, ahí termina: no se crea nada.
 *                       (método buscarRegistro)
 *   2. enviar-codigo  → se genera un código de 6 dígitos, se envía al correo
 *                       registrado y se responde con ese correo enmascarado.
 *   3. reenviar       → mismo paso 2, con un tiempo mínimo de espera entre
 *                       envíos; el número de reenvíos también está limitado.
 *   4. validar-codigo → si el código es correcto y no ha caducado, se entrega
 *                       un pase firmado con el que se abre la pantalla de
 *                       consentimiento.
 *
 * Del código nunca se guarda el valor: solo su SHA-256. Caduca a los
 * 10 minutos y se anula en cuanto se usa o se pide otro.
 * -----------------------------------------------------------------------------
 */

final class VerificacionPublicaController extends Controller
{
    /** Minutos de validez del código, contados desde su envío. */
    public const VIGENCIA_MINUTOS = 10;

    /** Segundos que deben pasar entre un envío y el siguiente. */
    private const ESPERA_REENVIO_SEGUNDOS = 60;

    /** Reenvíos permitidos para una misma identificación dentro de la vigencia. */
    private const MAX_ENVIOS = 5;

    /** Códigos equivocados admitidos antes de anular el vigente. */
    private const MAX_INTENTOS = 5;

    /** Consultas por identificación y hora, para dificultar el barrido de cédulas. */
    private const MAX_ENVIOS_POR_HORA = 10;

    /** Minutos de validez del pase que abre la pantalla de consentimiento. */
    private const VIGENCIA_PASE_MINUTOS = 20;

    /* ================================================================== */
    /* Paso 1: consulta (solo lectura)                                     */
    /* ================================================================== */

    /**
     * POST /api/verificacion-publica/consultar
     * Cuerpo: { tipo, inst, identificacion }
     *
     * No escribe absolutamente nada. Si la persona no está registrada, lo dice
     * y el recorrido termina ahí.
     *
     * Se llama buscarRegistro() y no consultar(): Controller ya define
     * consultar(string $sql, array $parametros) para las consultas SQL, y PHP
     * no admite dos firmas distintas con el mismo nombre.
     */
    public function buscarRegistro(array $ruta = []): void
    {
        [$tipo, $institucion] = $this->contexto();
        $institucionId = (int)$institucion['id'];

        $identificacion = $this->identificacion($tipo);
        $registro = $this->publico()->localizarPublico($tipo, $institucionId, $identificacion);

        if ($registro === null) {
            Response::exito([
                'tipo'           => $tipo,
                'documento'      => ConsentimientoPublicoController::DOCUMENTO[$tipo],
                'identificacion' => $identificacion,
                'existe'         => false,
                'mensaje'        => 'No encontramos ese número en los registros de ' . $institucion['nombre'] . '.',
            ]);
        }

        $destino = $this->destinatario($tipo, $registro);
        $estado  = $this->publico()->estadoConsentimientoPublico($institucionId, (int)$registro['PersonaId']);

        Response::exito([
            'tipo'            => $tipo,
            'documento'       => ConsentimientoPublicoController::DOCUMENTO[$tipo],
            'identificacion'  => $identificacion,
            'existe'          => true,
            'datos'           => $this->soloLectura($tipo, $registro),
            'estado_actual'   => $estado,
            'puede_revocar'   => !($estado !== null && $estado['Estado'] === 'ACTIVO'),
            'hay_correo'      => $destino !== '',
            'correo_oculto'   => $this->enmascarar($destino),
            'correo_de'       => $tipo === 'ESTUDIANTE' ? 'representante' : 'titular',
        ]);
    }

    /* ================================================================== */
    /* Paso 2 y 3: envío y reenvío del código                              */
    /* ================================================================== */

    /**
     * POST /api/verificacion-publica/enviar-codigo
     * Cuerpo: { tipo, inst, identificacion, reenvio? }
     */
    public function enviarCodigo(array $ruta = []): void
    {
        [$tipo, $institucion] = $this->contexto();
        $institucionId = (int)$institucion['id'];

        $identificacion = $this->identificacion($tipo);
        $registro = $this->publico()->localizarPublico($tipo, $institucionId, $identificacion);

        if ($registro === null) {
            Response::validacion(['No encontramos ese número en los registros de la institución.']);
        }

        $destino = $this->destinatario($tipo, $registro);
        if ($destino === '') {
            Response::validacion([
                $tipo === 'ESTUDIANTE'
                    ? 'El representante registrado no tiene un correo electrónico. Comuníquese con la institución para actualizarlo.'
                    : 'No hay un correo electrónico registrado para enviarle el código. Comuníquese con la institución para actualizarlo.',
            ]);
        }

        $this->limpiarCaducados($institucionId);

        $vigente = $this->vigenteDe($institucionId, $tipo, $identificacion);
        $envio   = 1;

        if ($vigente !== null) {
            // La resta la hace la base: PHP y MySQL pueden estar en zonas
            // horarias distintas, y restar una fecha de la otra daría un
            // resultado absurdo.
            $segundos = (int)($vigente['SegundosDesdeEnvio'] ?? 0);
            if ($segundos < self::ESPERA_REENVIO_SEGUNDOS) {
                Response::validacion([
                    'Espere ' . (self::ESPERA_REENVIO_SEGUNDOS - $segundos)
                    . ' segundos antes de solicitar otro código.',
                ]);
            }
            $envio = (int)$vigente['Envio'] + 1;
            if ($envio > self::MAX_ENVIOS) {
                Response::validacion([
                    'Se alcanzó el número máximo de envíos. Cierre la página y vuelva a abrir el enlace en unos minutos.',
                ]);
            }
        }

        if ($this->enviosUltimaHora($institucionId, $tipo, $identificacion) >= self::MAX_ENVIOS_POR_HORA) {
            Response::validacion([
                'Se solicitaron demasiados códigos para este número. Inténtelo de nuevo dentro de una hora.',
            ]);
        }

        // Un solo código vigente por identificación: los anteriores se anulan.
        $this->anularPendientes($institucionId, $tipo, $identificacion);

        $codigo = $this->generarCodigo();

        // Las dos fechas las pone la base con su propio reloj (NOW()), de modo
        // que emisión, caducidad y comparaciones usan siempre el mismo. En un
        // hospedaje compartido, PHP y MySQL rara vez comparten zona horaria.
        $this->ejecutar(
            'INSERT INTO verificacion_codigo
                (InstitucionEducativaId, TipoPersona, PersonaId, Identificacion, Destinatario,
                 CodigoHash, FechaEmision, FechaExpira, Intentos, Envio, IpOrigen, Estado)
             VALUES (?,?,?,?,?,?, NOW(), DATE_ADD(NOW(), INTERVAL ' . self::VIGENCIA_MINUTOS . ' MINUTE),
                     0,?,?,\'PENDIENTE\')',
            [
                $institucionId, $tipo, (int)$registro['PersonaId'], $identificacion, $destino,
                $this->hash($codigo), $envio, $this->ip(),
            ]
        );

        // Se relee lo que quedó grabado: así la pantalla muestra la hora del
        // mismo reloj con el que se comprobará la caducidad.
        $emitido = $this->vigenteDe($institucionId, $tipo, $identificacion) ?? [];
        $expira  = (string)($emitido['FechaExpira'] ?? '');
        $segundosRestantes = max(0, (int)($emitido['SegundosRestantes'] ?? self::VIGENCIA_MINUTOS * 60));

        $emision = (string)($emitido['FechaEmision'] ?? '');

        $resultado = $this->enviarPorCorreo(
            $institucionId, (string)$institucion['nombre'], $tipo, $registro, $destino, $codigo,
            $expira, $emision
        );

        if (!$resultado['enviado']) {
            // Si el correo no salió, el código no sirve para nada: se anula.
            $this->anularPendientes($institucionId, $tipo, $identificacion);
            error_log('[API] Verificación: no se pudo enviar el código. ' . $resultado['detalle']);
            Response::error(
                'No pudimos enviar el código a su correo en este momento. Inténtelo nuevamente en unos minutos.',
                502
            );
        }

        Response::exito([
            'enviado'        => true,
            'correo_oculto'  => $this->enmascarar($destino),
            'correo_de'      => $tipo === 'ESTUDIANTE' ? 'representante' : 'titular',
            'envio'          => $envio,
            'es_reenvio'     => $envio > 1,
            'envios_restantes' => max(0, self::MAX_ENVIOS - $envio),
            'expira'         => $expira,
            'expira_hora'    => $expira !== '' ? substr($expira, 11, 5) : '',
            'emision_hora'   => isset($emitido['FechaEmision']) ? substr((string)$emitido['FechaEmision'], 11, 5) : '',
            'segundos_restantes' => $segundosRestantes,
            'vigencia_minutos' => self::VIGENCIA_MINUTOS,
            'espera_segundos'  => self::ESPERA_REENVIO_SEGUNDOS,
            'mensaje'        => 'Le enviamos un código de ' . self::VIGENCIA_MINUTOS
                              . ' minutos de validez a ' . $this->enmascarar($destino) . '.',
        ]);
    }

    /* ================================================================== */
    /* Paso 4: validación del código                                       */
    /* ================================================================== */

    /**
     * POST /api/verificacion-publica/validar-codigo
     * Cuerpo: { tipo, inst, identificacion, codigo }
     */
    public function validarCodigo(array $ruta = []): void
    {
        [$tipo, $institucion] = $this->contexto();
        $institucionId = (int)$institucion['id'];

        $identificacion = $this->identificacion($tipo);
        $codigo = preg_replace('/\D/', '', $this->peticion->texto('codigo')) ?? '';

        if ($codigo === '') {
            Response::validacion(['Escriba el código que le enviamos por correo.']);
        }

        $this->limpiarCaducados($institucionId);
        $vigente = $this->vigenteDe($institucionId, $tipo, $identificacion);

        if ($vigente === null) {
            Response::validacion([
                'El código caducó o ya fue utilizado. Solicite uno nuevo con el botón de reenvío.',
            ]);
        }

        if (!hash_equals((string)$vigente['CodigoHash'], $this->hash($codigo))) {
            $intentos = (int)$vigente['Intentos'] + 1;

            if ($intentos >= self::MAX_INTENTOS) {
                $this->ejecutar(
                    'UPDATE verificacion_codigo SET Intentos = ?, Estado = \'ANULADO\'
                      WHERE VerificacionId = ?',
                    [$intentos, (int)$vigente['VerificacionId']]
                );
                Response::validacion([
                    'El código se ingresó mal demasiadas veces y quedó anulado. Solicite uno nuevo.',
                ]);
            }

            $this->ejecutar(
                'UPDATE verificacion_codigo SET Intentos = ? WHERE VerificacionId = ?',
                [$intentos, (int)$vigente['VerificacionId']]
            );

            Response::validacion([
                'El código no es correcto. Le quedan ' . (self::MAX_INTENTOS - $intentos) . ' intento(s).',
            ]);
        }

        // Correcto: se marca usado y se entrega el pase
        $this->ejecutar(
            'UPDATE verificacion_codigo SET Estado = \'USADO\', FechaUso = NOW() WHERE VerificacionId = ?',
            [(int)$vigente['VerificacionId']]
        );

        $expiraPase = time() + self::VIGENCIA_PASE_MINUTOS * 60;

        Response::exito([
            'verificado'      => true,
            'tipo'            => $tipo,
            'identificacion'  => $identificacion,
            'pase'            => $this->firmarPase($tipo, $institucionId, $identificacion, $expiraPase),
            'pase_expira'     => date('Y-m-d H:i:s', $expiraPase),
            'mensaje'         => 'Identidad verificada correctamente.',
        ]);
    }

    /* ================================================================== */
    /* Pase firmado                                                        */
    /* ================================================================== */

    /**
     * El pase acredita que esta identificación superó la verificación. Va
     * firmado con HMAC-SHA256 usando la misma clave que los tokens de la API,
     * de modo que la pantalla pública no puede fabricarlo por su cuenta.
     */
    private function firmarPase(string $tipo, int $institucionId, string $identificacion, int $expira): string
    {
        $cuerpo = $tipo . '|' . $institucionId . '|' . $identificacion . '|' . $expira;
        $firma  = hash_hmac('sha256', $cuerpo, Database::secreto());

        return rtrim(strtr(base64_encode($cuerpo . '|' . $firma), '+/', '-_'), '=');
    }

    /** Comprueba un pase emitido por validarCodigo(). Lo usa el registro final. */
    public static function paseValido(string $pase, string $tipo, int $institucionId, string $identificacion): bool
    {
        $crudo = base64_decode(strtr($pase, '-_', '+/'), true);
        if ($crudo === false) {
            return false;
        }

        $partes = explode('|', $crudo);
        if (count($partes) !== 5) {
            return false;
        }
        [$tipoPase, $institucionPase, $identificacionPase, $expira, $firma] = $partes;

        $cuerpo   = $tipoPase . '|' . $institucionPase . '|' . $identificacionPase . '|' . $expira;
        $esperada = hash_hmac('sha256', $cuerpo, Database::secreto());

        if (!hash_equals($esperada, $firma)) {
            return false;
        }
        if ((int)$expira < time()) {
            return false;
        }

        return $tipoPase === $tipo
            && (int)$institucionPase === $institucionId
            && $identificacionPase === $identificacion;
    }

    /* ================================================================== */
    /* Correo                                                              */
    /* ================================================================== */

    private function enviarPorCorreo(
        int $institucionId,
        string $institucionNombre,
        string $tipo,
        array $registro,
        string $destino,
        string $codigo,
        string $expira,
        string $emision = ''
    ): array {
        $esEstudiante = $tipo === 'ESTUDIANTE';

        $correo = Correo::desdeConfiguracion(
            CorreoConfiguracionController::configuracionDe($this->db, $institucionId)
        );

        $html = PlantillaCorreo::codigoVerificacion([
            'codigo'           => $codigo,
            'titular'          => (string)($registro['NombreCompleto'] ?? ''),
            'identificacion'   => (string)($registro['Identificacion'] ?? ''),
            'documento'        => ConsentimientoPublicoController::DOCUMENTO[$tipo],
            'tipo'             => $tipo,
            'es_representante' => $esEstudiante,
            'institucion'      => $institucionNombre,
            'minutos'          => self::VIGENCIA_MINUTOS,
            'expira'           => date('d/m/Y H:i', strtotime($expira)),
            // Ambas horas salen del reloj de la base de datos: si se mezclara con
            // el de PHP, y los dos servidores no estuvieran en la misma zona
            // horaria, el correo mostraría un código «solicitado» cinco horas
            // antes de caducar en diez minutos.
            'fecha'            => $emision !== ''
                ? date('d/m/Y H:i', strtotime($emision))
                : date('d/m/Y H:i'),
        ]);

        $enviado = $correo->enviar(
            $destino,
            (string)($registro['NombreCompleto'] ?? ''),
            'Su código de verificación: ' . $codigo,
            $html
        );
        $detalle = $enviado ? '' : $correo->ultimoError();
        $correo->cerrar();

        return ['enviado' => $enviado, 'detalle' => $detalle];
    }

    /* ================================================================== */
    /* Consultas de apoyo                                                  */
    /* ================================================================== */

    /** Código PENDIENTE y no caducado de esa identificación, si lo hay. */
    private function vigenteDe(int $institucionId, string $tipo, string $identificacion): ?array
    {
        return $this->consultarUna(
            'SELECT v.*,
                    TIMESTAMPDIFF(SECOND, v.FechaEmision, NOW()) AS SegundosDesdeEnvio,
                    TIMESTAMPDIFF(SECOND, NOW(), v.FechaExpira) AS SegundosRestantes
               FROM verificacion_codigo v
              WHERE v.InstitucionEducativaId = ? AND v.TipoPersona = ? AND v.Identificacion = ?
                AND v.Estado = \'PENDIENTE\' AND v.FechaExpira > NOW()
           ORDER BY v.VerificacionId DESC LIMIT 1',
            [$institucionId, $tipo, $identificacion]
        );
    }

    private function enviosUltimaHora(int $institucionId, string $tipo, string $identificacion): int
    {
        return $this->contar(
            'SELECT COUNT(*) total FROM verificacion_codigo
              WHERE InstitucionEducativaId = ? AND TipoPersona = ? AND Identificacion = ?
                AND FechaEmision > DATE_SUB(NOW(), INTERVAL 1 HOUR)',
            [$institucionId, $tipo, $identificacion]
        );
    }

    private function anularPendientes(int $institucionId, string $tipo, string $identificacion): void
    {
        $this->ejecutar(
            'UPDATE verificacion_codigo SET Estado = \'ANULADO\'
              WHERE InstitucionEducativaId = ? AND TipoPersona = ? AND Identificacion = ?
                AND Estado = \'PENDIENTE\'',
            [$institucionId, $tipo, $identificacion]
        );
    }

    /**
     * Marca como anulados los códigos que ya caducaron y borra los que llevan
     * más de un día: la tabla no debe crecer sin control.
     */
    private function limpiarCaducados(int $institucionId): void
    {
        $this->ejecutar(
            'UPDATE verificacion_codigo SET Estado = \'ANULADO\'
              WHERE InstitucionEducativaId = ? AND Estado = \'PENDIENTE\' AND FechaExpira <= NOW()',
            [$institucionId]
        );
        $this->ejecutar(
            'DELETE FROM verificacion_codigo
              WHERE InstitucionEducativaId = ? AND FechaEmision < DATE_SUB(NOW(), INTERVAL 1 DAY)',
            [$institucionId]
        );
    }

    /* ================================================================== */
    /* Utilidades                                                          */
    /* ================================================================== */

    /** Instancia del controlador público, para reutilizar su búsqueda. */
    private function publico(): ConsentimientoPublicoController
    {
        return new ConsentimientoPublicoController($this->peticion);
    }

    /** Tipo de persona e institución del enlace, validados. */
    private function contexto(): array
    {
        return $this->publico()->contextoPublico($this->peticion->cuerpo + $this->peticion->query);
    }

    private function identificacion(string $tipo): string
    {
        $valor = (string)preg_replace('/[^0-9A-Za-z]/', '', $this->peticion->texto('identificacion'));

        if ($valor === '') {
            Response::validacion([
                ConsentimientoPublicoController::DOCUMENTO[$tipo] === 'RUC'
                    ? 'Ingrese el número de RUC.'
                    : 'Ingrese el número de cédula.',
            ]);
        }

        return mb_substr($valor, 0, 50);
    }

    /** Correo al que se envía el código: el del representante en estudiantes. */
    private function destinatario(string $tipo, array $registro): string
    {
        $destino = $tipo === 'ESTUDIANTE'
            ? trim((string)($registro['RepEmail'] ?? ''))
            : trim((string)($registro['Email'] ?? ''));

        return filter_var($destino, FILTER_VALIDATE_EMAIL) ? $destino : '';
    }

    /**
     * Los datos que se muestran en pantalla van recortados: esta es una página
     * pública y basta con que la persona reconozca su registro.
     */
    private function soloLectura(string $tipo, array $registro): array
    {
        $vista = [
            'NombreCompleto'  => $registro['NombreCompleto'] ?? '',
            'Identificacion'  => $registro['Identificacion'] ?? '',
            'TipoIdentificacion' => $registro['TipoIdentificacion'] ?? '',
            'Email'           => $this->enmascarar((string)($registro['Email'] ?? '')),
            'Telefono'        => $this->enmascararTelefono((string)($registro['Telefono'] ?? '')),
        ];

        if ($tipo === 'ESTUDIANTE') {
            $vista['CodigoEstudiante']      = $registro['CodigoEstudiante'] ?? '';
            $vista['RepresentanteRelacion'] = $registro['RepresentanteRelacion'] ?? '';
            $vista['Representante'] = trim(
                (string)($registro['RepApellidos'] ?? '') . ' ' . (string)($registro['RepNombres'] ?? '')
            );
            $vista['RepEmail'] = $this->enmascarar((string)($registro['RepEmail'] ?? ''));
        } elseif ($tipo === 'PROVEEDOR') {
            $vista['RazonSocial'] = $registro['RazonSocial'] ?? '';
            $vista['Ruc']         = $registro['Ruc'] ?? '';
        }

        return $vista;
    }

    /** ana.perez@correo.com → an•••••z@correo.com */
    private function enmascarar(string $correo): string
    {
        if ($correo === '' || !str_contains($correo, '@')) {
            return '';
        }

        [$usuario, $dominio] = explode('@', $correo, 2);
        $largo = mb_strlen($usuario);

        if ($largo <= 2) {
            $visible = mb_substr($usuario, 0, 1) . '•';
        } else {
            $visible = mb_substr($usuario, 0, 2) . str_repeat('•', max(3, $largo - 3)) . mb_substr($usuario, -1);
        }

        return $visible . '@' . $dominio;
    }

    /** 0991234567 → ••••••4567 */
    private function enmascararTelefono(string $telefono): string
    {
        $limpio = (string)preg_replace('/\s+/', '', $telefono);
        if ($limpio === '') {
            return '';
        }
        if (mb_strlen($limpio) <= 4) {
            return str_repeat('•', mb_strlen($limpio));
        }
        return str_repeat('•', mb_strlen($limpio) - 4) . mb_substr($limpio, -4);
    }

    /** Código de 6 dígitos, generado con el generador criptográfico de PHP. */
    private function generarCodigo(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function hash(string $codigo): string
    {
        return hash('sha256', $codigo . '|' . Database::secreto());
    }

    private function ip(): ?string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $clave) {
            if (!empty($_SERVER[$clave])) {
                return mb_substr(trim(explode(',', (string)$_SERVER[$clave])[0]), 0, 45);
            }
        }
        return null;
    }
}
