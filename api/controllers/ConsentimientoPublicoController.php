<?php
/**
 * api/controllers/ConsentimientoPublicoController.php
 * -----------------------------------------------------------------------------
 * Endpoints PÚBLICOS del autoservicio de consentimiento.
 *
 * Atienden los tres enlaces abiertos —estudiantes, empleados y proveedores— que
 * la institución difunde. No exigen token: quien llega es el titular de los
 * datos (o el representante de un estudiante), que no tiene cuenta en el
 * sistema.
 *
 * El flujo tiene tres pasos:
 *
 *   1. inicio       → datos de la institución y disclaimer vigente del tipo.
 *   2. identificar  → se busca la cédula o el RUC. Si existe, se devuelven sus
 *                     datos; si no, se avisa que hay que registrarlos.
 *   3. registrar    → da de alta a quien no existía, registra la decisión en
 *                     `consentimiento` y `consentimientohistorial`, y envía el
 *                     correo de confirmación.
 *
 * Regla de revocatoria: quien ya tenía consentimiento otorgado no puede
 * revocarlo desde aquí; debe escribir a la institución. La pantalla lo muestra
 * deshabilitado y la API lo rechaza igualmente.
 * -----------------------------------------------------------------------------
 */

final class ConsentimientoPublicoController extends Controller
{
    public const TIPOS = ['ESTUDIANTE', 'EMPLEADO', 'PROVEEDOR'];

    /** Documento que se pide en el primer paso, según el tipo de persona. */
    public const DOCUMENTO = [
        'ESTUDIANTE' => 'CEDULA',
        'EMPLEADO'   => 'CEDULA',
        'PROVEEDOR'  => 'RUC',
    ];

    public const RELACIONES = [
        'MADRE', 'PADRE', 'ABUELO/A', 'HERMANO/A', 'TIO/A',
        'REPRESENTANTE LEGAL', 'TUTOR/A', 'OTRO',
    ];

    /** Versión de política que se sella cuando el disclaimer no trae una. */
    private const VERSION_POR_DEFECTO = 'WEB-1.0';

    /* ================================================================== */
    /* Paso 1: apertura del enlace                                         */
    /* ================================================================== */

    /** GET /api/consentimiento-publico/inicio?tipo=&inst= */
    public function inicio(array $ruta = []): void
    {
        [$tipo, $institucion] = $this->contexto($this->peticion->query);

        $disclaimer = DisclaimersController::vigente($this->db, (int)$institucion['id'], $tipo);

        Response::exito([
            'tipo'             => $tipo,
            'documento'        => self::DOCUMENTO[$tipo],
            'institucion_id'   => (int)$institucion['id'],
            'institucion'      => $institucion['nombre'],
            'hay_disclaimer'   => $disclaimer !== null,
            'disclaimer'       => $this->disclaimerPublico($disclaimer),
            // Relaciones que la base acepta hoy: la pantalla arma su desplegable
            // con esto, y así nunca ofrece una que no se pueda guardar.
            'relaciones'       => EstudiantesController::relacionesDisponibles($this->db),
        ]);
    }

    /* ================================================================== */
    /* Paso 2: identificación                                              */
    /* ================================================================== */

    /**
     * POST /api/consentimiento-publico/identificar
     * Cuerpo: { tipo, inst, identificacion }
     */
    public function identificar(array $ruta = []): void
    {
        [$tipo, $institucion] = $this->contexto($this->peticion->cuerpo + $this->peticion->query);
        $institucionId = (int)$institucion['id'];

        $identificacion = $this->normalizarIdentificacion($this->peticion->texto('identificacion'), $tipo);

        $registro = $this->localizar($tipo, $institucionId, $identificacion);
        $estado   = $registro !== null ? $this->estadoConsentimiento($institucionId, (int)$registro['PersonaId']) : null;

        Response::exito([
            'tipo'            => $tipo,
            'documento'       => self::DOCUMENTO[$tipo],
            'identificacion'  => $identificacion,
            'existe'          => $registro !== null,
            'datos'           => $registro,
            'estado_actual'   => $estado,
            // Quien ya consintió no puede revocar desde aquí
            'puede_revocar'   => !($estado !== null && $estado['Estado'] === 'ACTIVO'),
            'disclaimer'      => $this->disclaimerPublico(
                DisclaimersController::vigente($this->db, $institucionId, $tipo)
            ),
        ]);
    }

    /* ================================================================== */
    /* Paso 3: decisión                                                    */
    /* ================================================================== */

    /**
     * POST /api/consentimiento-publico/registrar
     * Cuerpo: { tipo, inst, identificacion, decision, datos: {...} }
     */
    public function registrar(array $ruta = []): void
    {
        [$tipo, $institucion] = $this->contexto($this->peticion->cuerpo + $this->peticion->query);
        $institucionId = (int)$institucion['id'];

        $identificacion = $this->normalizarIdentificacion($this->peticion->texto('identificacion'), $tipo);
        $decision       = strtoupper($this->peticion->texto('decision'));

        if (!in_array($decision, ['OTORGA', 'REVOCA'], true)) {
            Response::validacion(['Indique si otorga o revoca el consentimiento.']);
        }

        /* Si la decisión llega desde un enlace CON VERIFICACIÓN, trae el pase
           firmado que acredita que la identidad se comprobó con un código
           enviado al correo registrado. Queda anotado en el historial. */
        $pase       = $this->peticion->texto('pase');
        $verificado = $pase !== ''
            && VerificacionPublicaController::paseValido($pase, $tipo, $institucionId, $identificacion);

        if ($pase !== '' && !$verificado) {
            Response::error(
                'La verificación de su identidad caducó. Vuelva a abrir el enlace y solicite un código nuevo.',
                409
            );
        }

        $existente = $this->localizar($tipo, $institucionId, $identificacion);

        /* Con verificación no se dan altas: si la persona no está registrada,
           el enlace verificado no es el camino. */
        if ($verificado && $existente === null) {
            Response::error('No encontramos su registro en la institución.', 404);
        }

        // La regla de revocatoria se aplica también aquí, no solo en la pantalla
        if ($existente !== null && $decision === 'REVOCA') {
            $estado = $this->estadoConsentimiento($institucionId, (int)$existente['PersonaId']);
            if ($estado !== null && $estado['Estado'] === 'ACTIVO') {
                Response::error(
                    'Su consentimiento ya está registrado. Para revocarlo debe escribir a la '
                    . 'Fundación REA desde el correo que tiene registrado.',
                    409
                );
            }
        }

        $disclaimer  = DisclaimersController::vigente($this->db, $institucionId, $tipo);
        $finalidadId = $this->finalidadAplicable();

        if ($finalidadId === null) {
            Response::error(
                'La institución todavía no tiene configurada una finalidad del tratamiento. '
                . 'Comuníquese con nosotros para completar su registro.',
                409
            );
        }

        $ip      = $this->ipCliente();
        $estado  = $decision === 'OTORGA' ? 'ACTIVO' : 'INACTIVO';
        $persona = [];

        try {
            $this->db->beginTransaction();

            $persona = $existente !== null
                ? $existente
                : $this->darDeAlta($tipo, $institucionId, $identificacion);

            $personaId       = (int)$persona['PersonaId'];
            $representanteId = isset($persona['RepresentanteId']) && $persona['RepresentanteId'] !== null
                ? (int)$persona['RepresentanteId']
                : null;

            $consentimientoId = $this->guardarConsentimiento(
                $institucionId, $personaId, $representanteId, $finalidadId,
                $estado, $decision, $ip, $disclaimer, $verificado
            );

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('[API] Consentimiento público: ' . $ex->getMessage());
            Response::error(
                'No pudimos registrar su decisión en este momento. Intente nuevamente en unos minutos.',
                500
            );
        }

        // El correo va después de confirmar la transacción: si falla, la
        // decisión ya quedó registrada, que es lo que importa.
        $correo = $this->enviarConfirmacion(
            $tipo, $institucionId, (string)$institucion['nombre'], $persona, $decision, $disclaimer
        );

        Response::exito([
            'decision'         => $decision,
            'estado'           => $estado,
            'nuevo_registro'   => $existente === null,
            'consentimiento_id' => $consentimientoId,
            'fecha'            => date('Y-m-d H:i:s'),
            'correo'           => $correo,
            'mensaje'          => $decision === 'OTORGA'
                ? 'Su consentimiento fue registrado correctamente.'
                : 'Su revocación fue registrada correctamente.',
        ]);
    }

    /* ================================================================== */
    /* Contexto y validaciones                                             */
    /* ================================================================== */

    /**
     * Tipo de persona e institución del enlace.
     * @return array{0:string,1:array}
     */
    /* ------------------------------------------------------------------ */
    /* Puentes para el enlace CON VERIFICACIÓN                             */
    /* ------------------------------------------------------------------ */
    /* VerificacionPublicaController reutiliza estas tres operaciones para no  */
    /* duplicar la búsqueda ni las reglas del enlace. Son envoltorios de los   */
    /* métodos privados de más abajo: la lógica sigue viviendo en un solo      */
    /* lugar.                                                                 */

    /** @return array{0:string,1:array} tipo de persona e institución del enlace */
    public function contextoPublico(array $parametros): array
    {
        return $this->contexto($parametros);
    }

    /** Busca a la persona en la tabla de su tipo; null si no está registrada. */
    public function localizarPublico(string $tipo, int $institucionId, string $identificacion): ?array
    {
        return $this->localizar($tipo, $institucionId, $identificacion);
    }

    /** Último consentimiento de la persona en esa institución, o null. */
    public function estadoConsentimientoPublico(int $institucionId, int $personaId): ?array
    {
        return $this->estadoConsentimiento($institucionId, $personaId);
    }

    private function contexto(array $parametros): array
    {
        $tipo = strtoupper(trim((string)($parametros['tipo'] ?? '')));
        if (!in_array($tipo, self::TIPOS, true)) {
            Response::error('El enlace no es válido. Solicite el enlace correcto a la institución.', 404);
        }

        $institucionId = (int)($parametros['inst'] ?? 0);
        if ($institucionId <= 0) {
            Response::error('El enlace no indica la institución educativa.', 404);
        }

        $institucion = $this->consultarUna(
            "SELECT id, nombre FROM institucion_educativa WHERE id = ? AND estado = 'ACTIVO'",
            [$institucionId]
        );
        if (!$institucion) {
            Response::error('La institución educativa no está disponible en este momento.', 404);
        }

        return [$tipo, $institucion];
    }

    /** Comprueba el formato del documento según el tipo de persona. */
    private function normalizarIdentificacion(string $valor, string $tipo): string
    {
        $valor = preg_replace('/[^0-9A-Za-z]/', '', trim($valor)) ?? '';

        if ($valor === '') {
            Response::validacion([
                self::DOCUMENTO[$tipo] === 'RUC'
                    ? 'Ingrese el número de RUC.'
                    : 'Ingrese el número de cédula.',
            ]);
        }

        if (self::DOCUMENTO[$tipo] === 'RUC') {
            if (!preg_match('/^\d{10,13}$/', $valor)) {
                Response::validacion(['El RUC debe tener entre 10 y 13 dígitos.']);
            }
        } elseif (!preg_match('/^\d{10}$/', $valor)) {
            Response::validacion(['La cédula debe tener 10 dígitos.']);
        }

        return $valor;
    }

    /* ================================================================== */
    /* Búsqueda y alta                                                     */
    /* ================================================================== */

    /** Busca a la persona en la tabla que corresponde a su tipo. */
    private function localizar(string $tipo, int $institucionId, string $identificacion): ?array
    {
        if ($tipo === 'ESTUDIANTE') {
            $fila = $this->consultarUna(
                "SELECT p.PersonaId, p.Nombres, p.Apellidos, p.Identificacion, p.TipoIdentificacion,
                        p.Email, p.Telefono,
                        e.EstudianteId, e.CodigoEstudiante, e.RepresentanteRelacion,
                        e.RepresentanteId,
                        r.Nombres AS RepNombres, r.Apellidos AS RepApellidos,
                        r.Identificacion AS RepIdentificacion, r.Email AS RepEmail, r.Telefono AS RepTelefono
                   FROM estudiante e
             INNER JOIN persona p ON p.PersonaId = e.PersonaId
              LEFT JOIN persona r ON r.PersonaId = e.RepresentanteId
                  WHERE e.InstitucionEducativaId = ? AND p.Identificacion = ?
               ORDER BY e.EstudianteId DESC LIMIT 1",
                [$institucionId, $identificacion]
            );
        } elseif ($tipo === 'EMPLEADO') {
            $fila = $this->consultarUna(
                "SELECT p.PersonaId, p.Nombres, p.Apellidos, p.Identificacion, p.TipoIdentificacion,
                        p.Email, p.Telefono,
                        t.EmpleadoId
                   FROM empleado t
             INNER JOIN persona p ON p.PersonaId = t.PersonaId
                  WHERE t.InstitucionEducativaId = ? AND p.Identificacion = ?
               ORDER BY t.EmpleadoId DESC LIMIT 1",
                [$institucionId, $identificacion]
            );
        } else {
            $fila = $this->consultarUna(
                "SELECT p.PersonaId, p.Nombres, p.Apellidos, p.Identificacion, p.TipoIdentificacion,
                        p.Email, p.Telefono,
                        t.ProveedorId, t.Ruc, t.RazonSocial
                   FROM proveedor t
             INNER JOIN persona p ON p.PersonaId = t.PersonaId
                  WHERE t.InstitucionEducativaId = ? AND (p.Identificacion = ? OR t.Ruc = ?)
               ORDER BY t.ProveedorId DESC LIMIT 1",
                [$institucionId, $identificacion, $identificacion]
            );
        }

        if (!$fila) {
            return null;
        }

        $fila['NombreCompleto'] = trim((string)$fila['Apellidos'] . ' ' . (string)$fila['Nombres']);

        return $fila;
    }

    /**
     * Crea a la persona y su vínculo con la institución a partir de los datos
     * que llenó en la pantalla. En estudiantes crea también al representante.
     */
    private function darDeAlta(string $tipo, int $institucionId, string $identificacion): array
    {
        $datos = $this->peticion->dato('datos', []);
        if (!is_array($datos)) {
            $datos = [];
        }

        $campo = static fn(string $clave): string => trim((string)($datos[$clave] ?? ''));

        $nombres   = $campo('nombres');
        $apellidos = $campo('apellidos');
        $email     = $campo('email');
        $telefono  = $campo('telefono');

        $errores = [];
        if ($nombres === '')   { $errores[] = 'Ingrese los nombres.'; }
        if ($apellidos === '') { $errores[] = 'Ingrese los apellidos.'; }

        // El correo es obligatorio salvo en estudiantes, donde se usa el del representante
        if ($tipo !== 'ESTUDIANTE') {
            if ($email === '') {
                $errores[] = 'Ingrese el correo electrónico.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errores[] = 'El correo electrónico no es válido.';
            }
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El correo electrónico del estudiante no es válido.';
        }

        if ($tipo === 'PROVEEDOR' && $campo('razon_social') === '') {
            $errores[] = 'Ingrese la razón social.';
        }

        /* --- Representante, solo para estudiantes --- */
        $rep = [];
        if ($tipo === 'ESTUDIANTE') {
            $repCampo = static fn(string $clave): string => trim((string)($datos['representante'][$clave] ?? ''));

            $rep = [
                'identificacion' => preg_replace('/[^0-9A-Za-z]/', '', $repCampo('identificacion')) ?? '',
                'nombres'        => $repCampo('nombres'),
                'apellidos'      => $repCampo('apellidos'),
                'email'          => $repCampo('email'),
                'telefono'       => $repCampo('telefono'),
                'relacion'       => strtoupper($repCampo('relacion')),
            ];

            if ($rep['identificacion'] === '')                  { $errores[] = 'Ingrese la cédula del representante.'; }
            elseif (!preg_match('/^\d{10}$/', $rep['identificacion'])) { $errores[] = 'La cédula del representante debe tener 10 dígitos.'; }
            if ($rep['identificacion'] === $identificacion)     { $errores[] = 'El estudiante no puede ser su propio representante.'; }
            if ($rep['nombres'] === '' || $rep['apellidos'] === '') { $errores[] = 'Ingrese los nombres y apellidos del representante.'; }
            if ($rep['email'] === '') {
                $errores[] = 'Ingrese el correo del representante: allí se enviará la confirmación.';
            } elseif (!filter_var($rep['email'], FILTER_VALIDATE_EMAIL)) {
                $errores[] = 'El correo del representante no es válido.';
            }
            if (!in_array($rep['relacion'], EstudiantesController::relacionesDisponibles($this->db), true)) {
                $errores[] = 'Indique la relación del representante con el estudiante.';
            }
        }

        if ($errores) {
            Response::validacion($errores);
        }

        /* --- Persona titular --- */
        $personaId = Padron::crearOActualizar($this->db, $institucionId, [
            'identificacion' => $identificacion,
            'tipo'           => self::DOCUMENTO[$tipo],
            'nombres'        => mb_substr($nombres, 0, 100),
            'apellidos'      => mb_substr($apellidos, 0, 100),
            'email'          => $email,
            'telefono'       => $telefono,
            'estado'         => 'ACTIVO',
        ]);

        /* --- Vínculo con la institución --- */
        if ($tipo === 'ESTUDIANTE') {
            $representanteId = Padron::crearOActualizar($this->db, $institucionId, [
                'identificacion' => $rep['identificacion'],
                'tipo'           => 'CEDULA',
                'nombres'        => mb_substr($rep['nombres'], 0, 100),
                'apellidos'      => mb_substr($rep['apellidos'], 0, 100),
                'email'          => $rep['email'],
                'telefono'       => $rep['telefono'],
                'estado'         => 'ACTIVO',
            ]);

            $this->ejecutar(
                'INSERT INTO estudiante
                    (InstitucionEducativaId, PersonaId, CodigoEstudiante,
                     RepresentanteId, RepresentanteRelacion, Estado)
                 VALUES (?,?,?,?,?,\'ACTIVO\')',
                [
                    $institucionId, $personaId,
                    $campo('codigo_estudiante') ?: null,
                    $representanteId, $rep['relacion'],
                ]
            );
        } elseif ($tipo === 'EMPLEADO') {
            $representanteId = null;
            $this->ejecutar(
                'INSERT INTO empleado (InstitucionEducativaId, PersonaId, Estado)
                 VALUES (?,?,\'ACTIVO\')',
                [$institucionId, $personaId]
            );
        } else {
            $representanteId = null;
            $this->ejecutar(
                'INSERT INTO proveedor (InstitucionEducativaId, PersonaId, Ruc, RazonSocial, Estado)
                 VALUES (?,?,?,?,\'ACTIVO\')',
                [$institucionId, $personaId, $identificacion, mb_substr($campo('razon_social'), 0, 150)]
            );
        }

        $persona = $this->localizar($tipo, $institucionId, $identificacion);

        if ($persona === null) {
            // No debería ocurrir: se acaba de insertar
            throw new PDOException('No se pudo recuperar el registro recién creado.');
        }

        return $persona;
    }


    /* ================================================================== */
    /* Consentimiento                                                      */
    /* ================================================================== */

    /** Último consentimiento registrado para la persona en la institución. */
    private function estadoConsentimiento(int $institucionId, int $personaId): ?array
    {
        $fila = $this->consultarUna(
            'SELECT ConsentimientoId, Estado, FechaConsentimiento, FechaRevocacion, VersionPolitica
               FROM consentimiento
              WHERE InstitucionEducativaId = ? AND PersonaId = ?
           ORDER BY ConsentimientoId DESC LIMIT 1',
            [$institucionId, $personaId]
        );

        return $fila ?: null;
    }

    /** Crea o actualiza el consentimiento y deja constancia en el historial. */
    private function guardarConsentimiento(
        int $institucionId,
        int $personaId,
        ?int $representanteId,
        int $finalidadId,
        string $estado,
        string $decision,
        ?string $ip,
        ?array $disclaimer,
        bool $verificado = false
    ): int {
        $version = $disclaimer !== null && trim((string)$disclaimer['Version']) !== ''
            ? mb_substr((string)$disclaimer['Version'], 0, 50)
            : self::VERSION_POR_DEFECTO;

        $existente = $this->consultarUna(
            'SELECT * FROM consentimiento
              WHERE InstitucionEducativaId = ? AND PersonaId = ? AND FinalidadId = ?
           ORDER BY ConsentimientoId DESC LIMIT 1',
            [$institucionId, $personaId, $finalidadId]
        );

        if ($existente) {
            $consentimientoId = (int)$existente['ConsentimientoId'];
            $estadoAnterior   = (string)$existente['Estado'];

            $this->ejecutar(
                'UPDATE consentimiento
                    SET Estado = ?, FechaConsentimiento = ?, FechaRevocacion = ?,
                        RepresentanteId = ?, MedioConsentimiento = \'WEB\',
                        VersionPolitica = ?, IpOrigen = ?
                  WHERE ConsentimientoId = ? AND InstitucionEducativaId = ?',
                [
                    $estado,
                    $decision === 'OTORGA' ? date('Y-m-d H:i:s') : $existente['FechaConsentimiento'],
                    $decision === 'REVOCA' ? date('Y-m-d H:i:s') : null,
                    $representanteId, $version, $ip,
                    $consentimientoId, $institucionId,
                ]
            );
        } else {
            $estadoAnterior = null;

            $this->ejecutar(
                'INSERT INTO consentimiento
                    (InstitucionEducativaId, PersonaId, FinalidadId, FechaConsentimiento, FechaRevocacion,
                     RepresentanteId, MedioConsentimiento, VersionPolitica, IpOrigen, Estado)
                 VALUES (?,?,?,?,?,?,\'WEB\',?,?,?)',
                [
                    $institucionId, $personaId, $finalidadId,
                    date('Y-m-d H:i:s'),
                    $decision === 'REVOCA' ? date('Y-m-d H:i:s') : null,
                    $representanteId, $version, $ip, $estado,
                ]
            );
            $consentimientoId = (int)$this->db->lastInsertId();
        }

        $this->sincronizarTiposDato($institucionId, $consentimientoId, $decision === 'OTORGA');

        $this->ejecutar(
            'INSERT INTO consentimientohistorial
                (InstitucionEducativaId, ConsentimientoId, EstadoAnterior, EstadoNuevo, Accion,
                 FechaAccion, UsuarioId, IpOrigen, Observacion)
             VALUES (?,?,?,?,?,NOW(),NULL,?,?)',
            [
                $institucionId, $consentimientoId, $estadoAnterior, $estado,
                $decision === 'OTORGA' ? 'CONSENTIMIENTO_WEB' : 'REVOCACION_WEB',
                $ip,
                mb_substr(
                    ($decision === 'OTORGA' ? 'Consentimiento otorgado' : 'Consentimiento revocado')
                    . ' por el titular desde el enlace '
                    . ($verificado
                        ? 'público CON VERIFICACIÓN de identidad por código enviado al correo registrado'
                        : 'público de la institución')
                    . '. Política versión ' . $version . '.',
                    0, 500
                ),
            ]
        );

        return $consentimientoId;
    }

    /** Marca todos los tipos de dato del catálogo según la decisión. */
    private function sincronizarTiposDato(int $institucionId, int $consentimientoId, bool $autoriza): void
    {
        $this->ejecutar(
            'DELETE FROM consentimientodato WHERE ConsentimientoId = ? AND InstitucionEducativaId = ?',
            [$consentimientoId, $institucionId]
        );

        $tipos = $this->columna('SELECT TipoDatoId FROM tipodato');
        if (!$tipos) {
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO consentimientodato (InstitucionEducativaId, ConsentimientoId, TipoDatoId, Autorizado)
             VALUES (?,?,?,?)'
        );
        foreach ($tipos as $tipoDatoId) {
            $stmt->execute([$institucionId, $consentimientoId, $tipoDatoId, $autoriza ? 'SI' : 'NO']);
        }
    }

    private function finalidadAplicable(): ?int
    {
        $fila = $this->consultarUna(
            "SELECT FinalidadId FROM finalidad WHERE Activo = 'ACTIVO' ORDER BY FinalidadId LIMIT 1"
        );

        return $fila ? (int)$fila['FinalidadId'] : null;
    }

    /* ================================================================== */
    /* Correo de confirmación                                              */
    /* ================================================================== */

    /**
     * Envía la confirmación de la decisión. En estudiantes va al representante,
     * indicando que se trata de su representado.
     *
     * @return array{enviado:bool,destino:string,detalle:string}
     */
    private function enviarConfirmacion(
        string $tipo,
        int $institucionId,
        string $institucionNombre,
        array $persona,
        string $decision,
        ?array $disclaimer
    ): array {
        $esEstudiante  = $tipo === 'ESTUDIANTE';
        $representante = $esEstudiante
            ? trim((string)($persona['RepApellidos'] ?? '') . ' ' . (string)($persona['RepNombres'] ?? ''))
            : '';

        $destino = $esEstudiante
            ? trim((string)($persona['RepEmail'] ?? '')) ?: trim((string)($persona['Email'] ?? ''))
            : trim((string)($persona['Email'] ?? ''));

        if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            return [
                'enviado' => false,
                'destino' => '',
                'detalle' => 'No hay una dirección de correo registrada para enviar la confirmación.',
            ];
        }

        $correo = Correo::desdeConfiguracion(
            CorreoConfiguracionController::configuracionDe($this->db, $institucionId)
        );

        $html = PlantillaCorreo::confirmacion([
            'tipo'             => $tipo,
            'decision'         => $decision,
            'titular'          => (string)($persona['NombreCompleto'] ?? ''),
            'identificacion'   => (string)($persona['Identificacion'] ?? ''),
            'documento'        => self::DOCUMENTO[$tipo],
            'representante'    => $representante,
            'es_representante' => $esEstudiante && trim((string)($persona['RepEmail'] ?? '')) !== '',
            'institucion'      => $institucionNombre,
            'version'          => $disclaimer['Version'] ?? self::VERSION_POR_DEFECTO,
            'fecha'            => date('d/m/Y H:i'),
        ]);

        $asunto = $decision === 'OTORGA'
            ? 'Confirmación de su consentimiento de datos personales'
            : 'Confirmación de la revocación de su consentimiento';

        $enviado = $correo->enviar($destino, (string)($persona['NombreCompleto'] ?? ''), $asunto, $html);
        $detalle = $enviado ? '' : $correo->ultimoError();
        $correo->cerrar();

        return ['enviado' => $enviado, 'destino' => $destino, 'detalle' => $detalle];
    }

    /* ================================================================== */
    /* Utilidades                                                          */
    /* ================================================================== */

    /** Datos del disclaimer que se envían a la pantalla pública. */
    private function disclaimerPublico(?array $disclaimer): ?array
    {
        if ($disclaimer === null) {
            return null;
        }

        return [
            'DisclaimerId' => (int)$disclaimer['DisclaimerId'],
            'Version'      => $disclaimer['Version'],
            'Titulo'       => $disclaimer['Titulo'],
            // Ya viene saneado desde que se guardó; se vuelve a limpiar por si
            // el texto se editó directamente en la base de datos.
            'Texto'        => HtmlSeguro::limpiar((string)$disclaimer['Texto']),
        ];
    }

    private function ipCliente(): ?string
    {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $clave) {
            if (!empty($_SERVER[$clave])) {
                return mb_substr(trim(explode(',', (string)$_SERVER[$clave])[0]), 0, 20);
            }
        }
        return null;
    }
}
