<?php
// api/controllers/EstudiantesController.php
// CRUD de estudiantes matriculados y su representante legal.
//
// `persona` es la entidad padre y no tiene mantenimiento propio: los datos del
// estudiante y los de su representante se capturan aquí mismo. Al guardar, cada
// ficha se crea o se reutiliza si ese documento ya consta en el padrón de la
// institución (ver api/core/Padron.php). Por eso un representante que ya sea
// empleado, o que represente a otro hermano, no se duplica.

final class EstudiantesController extends Controller
{
    private const ROLES = ['SuperAdmin', 'Secretaria'];
    /** Clave en includes/accesos.php: define qué permisos abren este recurso. */
    private const MODULO = 'estudiantes';
    /**
     * Relaciones que el sistema conoce. Es la lista completa, la que declara el
     * DDL vigente.
     *
     * OJO: no se usa directamente para validar. Una base que todavía no haya
     * corrido el script de actualización acepta solo cuatro de estas, y grabar
     * una que su enum no reconoce da un error de truncamiento —o, peor, en un
     * servidor sin modo estricto, guarda la relación en blanco sin avisar—.
     * Por eso la validación y el desplegable usan relacionesDisponibles(), que
     * pregunta a la propia base qué acepta.
     */
    public const RELACIONES = [
        'MADRE', 'PADRE', 'ABUELO/A', 'HERMANO/A', 'TIO/A',
        'REPRESENTANTE LEGAL', 'TUTOR/A', 'OTRO',
    ];

    /**
     * Parentescos que la lista nueva agrupó, con su reemplazo.
     *
     * Sirven para un solo fin: darse cuenta de que la base todavía está en la
     * lista anterior. Como el desplegable se arma con lo que la base acepta, sin
     * esto la pantalla mostraría `ABUELO` y `ABUELA` por separado sin decir por
     * qué, y parecería que el cambio no se aplicó al código.
     *
     * Se resuelve con `08_ALTER_relacion_representante.sql`.
     */
    public const RELACIONES_RETIRADAS = [
        'ABUELO' => 'ABUELO/A',
        'ABUELA' => 'ABUELO/A',
        'TIO'    => 'TIO/A',
        'TIA'    => 'TIO/A',
    ];

    /** @var string[]|null Se lee una sola vez por petición. */
    private static ?array $relacionesCache = null;

    /**
     * Relaciones que la base REALMENTE acepta, leídas del enum de la columna
     * `estudiante`.`RepresentanteRelacion`.
     *
     * Así la pantalla ofrece exactamente lo que se puede guardar, sin importar
     * si la base está al día o todavía en la versión anterior. Si por lo que
     * fuera no se puede leer el enum, se recurre a la lista completa.
     *
     * @return string[]
     */
    public static function relacionesDisponibles(PDO $db): array
    {
        if (self::$relacionesCache !== null) {
            return self::$relacionesCache;
        }

        try {
            $stmt = $db->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'estudiante'
                    AND COLUMN_NAME  = 'RepresentanteRelacion'"
            );
            $stmt->execute();
            $tipo = (string)($stmt->fetchColumn() ?: '');

            // enum('MADRE','PADRE',...)  ->  ['MADRE', 'PADRE', ...]
            if (preg_match('/^enum\((.*)\)$/i', trim($tipo), $m)
                && preg_match_all("/'((?:[^']|'')*)'/", $m[1], $valores)) {

                $lista = array_values(array_filter(array_map(
                    static fn(string $v): string => str_replace("''", "'", $v),
                    $valores[1]
                ), static fn(string $v): bool => $v !== ''));

                if ($lista) {
                    return self::$relacionesCache = $lista;
                }
            }
        } catch (PDOException $e) {
            error_log('[API] No se pudo leer el enum de RepresentanteRelacion: ' . $e->getMessage());
        }

        return self::$relacionesCache = self::RELACIONES;
    }

    /**
     * Parentescos retirados que la base TODAVÍA acepta, es decir, los que
     * delatan que falta ejecutar la migración.
     *
     * Devuelve un mapa `['ABUELO' => 'ABUELO/A', ...]` con solo los que siguen
     * en el enum. Vacío = la base está al día.
     *
     * @return array<string, string>
     */
    public static function relacionesRetiradas(PDO $db): array
    {
        $enLaBase = self::relacionesDisponibles($db);

        return array_filter(
            self::RELACIONES_RETIRADAS,
            static fn(string $viejo): bool => in_array($viejo, $enLaBase, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /** GET /api/estudiantes?q=&pagina= */
    public function index(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'WHERE es.InstitucionEducativaId = ?
                     AND (p.Nombres LIKE ? OR p.Apellidos LIKE ? OR es.CodigoEstudiante LIKE ? OR p.Identificacion LIKE ?)';
        $params = [$institucionId, $buscar, $buscar, $buscar, $buscar];

        $total = $this->contar(
            "SELECT COUNT(*) total FROM estudiante es INNER JOIN persona p ON p.PersonaId = es.PersonaId $where",
            $params
        );
        [$pagina, $porPagina, $offset] = $this->paginacion(12);

        $datos = $this->consultar(
            "SELECT es.*, p.Nombres, p.Apellidos, p.Identificacion,
                    rp.Nombres AS RepNombres, rp.Apellidos AS RepApellidos
               FROM estudiante es
         INNER JOIN persona p  ON p.PersonaId  = es.PersonaId
          LEFT JOIN persona rp ON rp.PersonaId = es.RepresentanteId
             $where
           ORDER BY p.Apellidos
              LIMIT $offset, $porPagina",
            $params
        );

        Response::lista($datos, $total, $pagina, $porPagina, [
            // La pantalla arma su desplegable con esto, no con una lista fija
            'relaciones'          => self::relacionesDisponibles($this->db),
            'relaciones_retiradas'=> self::relacionesRetiradas($this->db),
        ]);
    }

    /** GET /api/estudiantes/{id} */
    public function show(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);

        $registro = $this->consultarUna(
            'SELECT es.*, p.Nombres, p.Apellidos, p.Identificacion, p.TipoIdentificacion,
                    p.Email, p.Telefono,
                    rp.Nombres AS RepNombres, rp.Apellidos AS RepApellidos,
                    rp.Identificacion AS RepIdentificacion, rp.TipoIdentificacion AS RepTipoIdentificacion,
                    rp.Email AS RepEmail, rp.Telefono AS RepTelefono
               FROM estudiante es
          LEFT JOIN persona p  ON p.PersonaId  = es.PersonaId
          LEFT JOIN persona rp ON rp.PersonaId = es.RepresentanteId
              WHERE es.EstudianteId = ? AND es.InstitucionEducativaId = ?',
            [(int)$ruta['id'], $this->institucion()]
        );

        if (!$registro) {
            Response::noEncontrado();
        }
        Response::exito($registro);
    }

    /** POST /api/estudiantes */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $datos = $this->validar();

        $yaEsEstudiante = $this->consultarUna(
            'SELECT es.EstudianteId FROM estudiante es
       INNER JOIN persona p ON p.PersonaId = es.PersonaId
            WHERE es.InstitucionEducativaId = ? AND p.Identificacion = ?',
            [$institucionId, $datos['persona']['identificacion']]
        );
        if ($yaEsEstudiante) {
            Response::error('Ya existe un estudiante matriculado con esa identificación.', 409);
        }

        try {
            $this->db->beginTransaction();

            $personaId       = Padron::crearOActualizar($this->db, $institucionId, $datos['persona']);
            $representanteId = $datos['representante'] !== null
                ? Padron::crearOActualizar($this->db, $institucionId, $datos['representante'])
                : null;

            $this->ejecutar(
                'INSERT INTO estudiante
                    (InstitucionEducativaId, PersonaId, CodigoEstudiante, RepresentanteId, RepresentanteRelacion, Estado)
                 VALUES (?,?,?,?,?,?)',
                [
                    $institucionId, $personaId, $datos['codigo'],
                    $representanteId, $datos['relacion'], $datos['estado'],
                ]
            );
            $id = (int)$this->db->lastInsertId();

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'Ya existe un estudiante con ese código.');
            return;
        }

        $this->auditarInsercion('persona', 'PersonaId', $personaId, $institucionId);
        if ($representanteId !== null) {
            $this->auditarInsercion('persona', 'PersonaId', $representanteId, $institucionId);
        }
        $this->auditarInsercion('estudiante', 'EstudianteId', $id, $institucionId);

        Response::exito(
            ['EstudianteId' => $id, 'mensaje' => 'Estudiante matriculado correctamente.'],
            [],
            201
        );
    }

    /** PUT /api/estudiantes/{id} */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id    = (int)$ruta['id'];
        $datos = $this->validar();

        $antes = $this->filaAuditable('estudiante', 'EstudianteId', $id, $institucionId);
        if (!$antes) {
            Response::noEncontrado();
        }

        $personaId    = (int)$antes['PersonaId'];
        $antesPersona = $this->filaAuditable('persona', 'PersonaId', $personaId, $institucionId);

        if (Padron::documentoOcupado($this->db, $institucionId, $datos['persona']['identificacion'], $personaId)) {
            Response::error('Otra persona de esta institución ya tiene esa identificación.', 409);
        }

        try {
            $this->db->beginTransaction();

            // Datos del estudiante: se editan sobre su propia ficha
            Padron::actualizar($this->db, $institucionId, $personaId, $datos['persona']);

            /* El representante puede cambiar de persona: si el documento que
               llega es otro, se enlaza (o se crea) esa ficha en vez de pisar la
               del representante anterior, que puede representar a otro alumno. */
            $representanteId = null;
            if ($datos['representante'] !== null) {
                $representanteId = Padron::crearOActualizar($this->db, $institucionId, $datos['representante']);
            }

            $this->ejecutar(
                'UPDATE estudiante
                    SET CodigoEstudiante = ?,
                        RepresentanteId = ?, RepresentanteRelacion = ?, Estado = ?
                  WHERE EstudianteId = ? AND InstitucionEducativaId = ?',
                [
                    $datos['codigo'],
                    $representanteId, $datos['relacion'], $datos['estado'], $id, $institucionId,
                ]
            );

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'Ya existe un estudiante con ese código.');
            return;
        }

        $this->auditarActualizacion('persona',    'PersonaId',    $personaId, $antesPersona, $institucionId);
        $this->auditarActualizacion('estudiante', 'EstudianteId', $id, $antes, $institucionId);

        Response::exito(['EstudianteId' => $id, 'mensaje' => 'Estudiante actualizado correctamente.']);
    }

    /** PATCH /api/estudiantes/{id}/estado */
    public function estadoCambiar(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $registro = $this->consultarUna(
            'SELECT Estado FROM estudiante WHERE EstudianteId = ? AND InstitucionEducativaId = ?',
            [$id, $institucionId]
        );
        if (!$registro) {
            Response::noEncontrado();
        }

        $nuevo = $this->peticion->texto('estado') !== ''
            ? $this->estado($this->peticion->texto('estado'))
            : $this->estadoInvertido($registro['Estado']);

        $this->ejecutar(
            'UPDATE estudiante SET Estado = ? WHERE EstudianteId = ? AND InstitucionEducativaId = ?',
            [$nuevo, $id, $institucionId]
        );
        $this->auditarActualizacion('estudiante', 'EstudianteId', $id, $registro, $institucionId);

        Response::exito(['EstudianteId' => $id, 'estado' => $nuevo, 'mensaje' => 'Estado actualizado.']);
    }

    /* ------------------------------------------------------------------ */

    private function validar(): array
    {
        $codigo   = $this->peticion->texto('codigo_estudiante');
        $relacion = $this->peticion->texto('representante_relacion');
        $cuerpo   = $this->peticion->cuerpo;

        // Datos del estudiante y de su representante, en el mismo formulario
        $persona       = Padron::normalizar($cuerpo);
        $representante = Padron::normalizar($cuerpo, 'rep_');

        $errores = Padron::validar($persona, 'el estudiante');

        if ($codigo === '') $errores[] = 'El código de estudiante es obligatorio.';

        /* El representante es opcional: solo se valida si se escribió algo de
           él. Si viene, su correo SÍ es obligatorio, porque es la dirección a
           la que llegan los avisos de consentimiento del estudiante. */
        $hayRepresentante = $representante['identificacion'] !== ''
            || $representante['nombres'] !== ''
            || $representante['apellidos'] !== ''
            || $representante['email'] !== '';

        if ($hayRepresentante) {
            $errores = array_merge($errores, Padron::validar($representante, 'el representante', true));

            if ($representante['identificacion'] !== ''
                && $representante['identificacion'] === $persona['identificacion']) {
                $errores[] = 'El representante no puede ser la misma persona que el estudiante.';
            }
        } elseif ($relacion !== '') {
            $errores[] = 'Indicó una relación de representante, pero no sus datos.';
        }

        /* Se avisa con claridad en lugar de dejar que MySQL rechace el valor con
           un error de truncamiento, que no le dice nada a quien está usando el
           sistema. Ocurre cuando la base todavía no tiene las relaciones nuevas. */
        $disponibles = self::relacionesDisponibles($this->db);
        if ($relacion !== '' && !in_array($relacion, $disponibles, true)) {
            $errores[] = in_array($relacion, self::RELACIONES, true)
                ? 'La relación «' . $relacion . '» todavía no está habilitada en la base de datos. '
                  . 'Ejecute el script de actualización de estructura, o elija una de estas: '
                  . implode(', ', $disponibles) . '.'
                : 'La relación del representante no es válida. Elija una de estas: '
                  . implode(', ', $disponibles) . '.';
        }

        if ($errores) {
            Response::validacion($errores);
        }

        $estado = $this->estado($this->peticion->texto('estado', 'ACTIVO'));
        $persona['estado'] = $estado;

        return [
            'persona'       => $persona,
            // El representante conserva su propio estado: puede seguir activo
            // aunque el estudiante se inactive.
            'representante' => $hayRepresentante ? $representante : null,
            'codigo'        => $codigo,
            'relacion'      => $relacion !== '' ? $relacion : null,
            'estado'        => $estado,
        ];
    }
}

<?php
// api/controllers/EstudiantesController.php
// CRUD de estudiantes matriculados y su representante legal.
//
// `persona` es la entidad padre y no tiene mantenimiento propio: los datos del
// estudiante y los de su representante se capturan aquí mismo. Al guardar, cada
// ficha se crea o se reutiliza si ese documento ya consta en el padrón de la
// institución (ver api/core/Padron.php). Por eso un representante que ya sea
// empleado, o que represente a otro hermano, no se duplica.

final class EstudiantesController extends Controller
{
    private const ROLES = ['SuperAdmin', 'Secretaria'];
    /** Clave en includes/accesos.php: define qué permisos abren este recurso. */
    private const MODULO = 'estudiantes';
    /**
     * Relaciones que el sistema conoce. Es la lista completa, la que declara el
     * DDL vigente.
     *
     * OJO: no se usa directamente para validar. Una base que todavía no haya
     * corrido el script de actualización acepta solo cuatro de estas, y grabar
     * una que su enum no reconoce da un error de truncamiento —o, peor, en un
     * servidor sin modo estricto, guarda la relación en blanco sin avisar—.
     * Por eso la validación y el desplegable usan relacionesDisponibles(), que
     * pregunta a la propia base qué acepta.
     */
    public const RELACIONES = [
        'MADRE', 'PADRE', 'ABUELO/A', 'HERMANO/A', 'TIO/A',
        'REPRESENTANTE LEGAL', 'TUTOR/A', 'OTRO',
    ];

    /**
     * Parentescos que la lista nueva agrupó, con su reemplazo.
     *
     * Sirven para un solo fin: darse cuenta de que la base todavía está en la
     * lista anterior. Como el desplegable se arma con lo que la base acepta, sin
     * esto la pantalla mostraría `ABUELO` y `ABUELA` por separado sin decir por
     * qué, y parecería que el cambio no se aplicó al código.
     *
     * Se resuelve con `08_ALTER_relacion_representante.sql`.
     */
    public const RELACIONES_RETIRADAS = [
        'ABUELO' => 'ABUELO/A',
        'ABUELA' => 'ABUELO/A',
        'TIO'    => 'TIO/A',
        'TIA'    => 'TIO/A',
    ];

    /** @var string[]|null Se lee una sola vez por petición. */
    private static ?array $relacionesCache = null;

    /**
     * Relaciones que la base REALMENTE acepta, leídas del enum de la columna
     * `estudiante`.`RepresentanteRelacion`.
     *
     * Así la pantalla ofrece exactamente lo que se puede guardar, sin importar
     * si la base está al día o todavía en la versión anterior. Si por lo que
     * fuera no se puede leer el enum, se recurre a la lista completa.
     *
     * @return string[]
     */
    public static function relacionesDisponibles(PDO $db): array
    {
        if (self::$relacionesCache !== null) {
            return self::$relacionesCache;
        }

        try {
            $stmt = $db->prepare(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME   = 'estudiante'
                    AND COLUMN_NAME  = 'RepresentanteRelacion'"
            );
            $stmt->execute();
            $tipo = (string)($stmt->fetchColumn() ?: '');

            // enum('MADRE','PADRE',...)  ->  ['MADRE', 'PADRE', ...]
            if (preg_match('/^enum\((.*)\)$/i', trim($tipo), $m)
                && preg_match_all("/'((?:[^']|'')*)'/", $m[1], $valores)) {

                $lista = array_values(array_filter(array_map(
                    static fn(string $v): string => str_replace("''", "'", $v),
                    $valores[1]
                ), static fn(string $v): bool => $v !== ''));

                if ($lista) {
                    return self::$relacionesCache = $lista;
                }
            }
        } catch (PDOException $e) {
            error_log('[API] No se pudo leer el enum de RepresentanteRelacion: ' . $e->getMessage());
        }

        return self::$relacionesCache = self::RELACIONES;
    }

    /**
     * Parentescos retirados que la base TODAVÍA acepta, es decir, los que
     * delatan que falta ejecutar la migración.
     *
     * Devuelve un mapa `['ABUELO' => 'ABUELO/A', ...]` con solo los que siguen
     * en el enum. Vacío = la base está al día.
     *
     * @return array<string, string>
     */
    public static function relacionesRetiradas(PDO $db): array
    {
        $enLaBase = self::relacionesDisponibles($db);

        return array_filter(
            self::RELACIONES_RETIRADAS,
            static fn(string $viejo): bool => in_array($viejo, $enLaBase, true),
            ARRAY_FILTER_USE_KEY
        );
    }

    /** GET /api/estudiantes?q=&pagina= */
    public function index(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'WHERE es.InstitucionEducativaId = ?
                     AND (p.Nombres LIKE ? OR p.Apellidos LIKE ? OR es.CodigoEstudiante LIKE ? OR p.Identificacion LIKE ?)';
        $params = [$institucionId, $buscar, $buscar, $buscar, $buscar];

        $total = $this->contar(
            "SELECT COUNT(*) total FROM estudiante es INNER JOIN persona p ON p.PersonaId = es.PersonaId $where",
            $params
        );
        [$pagina, $porPagina, $offset] = $this->paginacion(12);

        $datos = $this->consultar(
            "SELECT es.*, p.Nombres, p.Apellidos, p.Identificacion,
                    rp.Nombres AS RepNombres, rp.Apellidos AS RepApellidos
               FROM estudiante es
         INNER JOIN persona p  ON p.PersonaId  = es.PersonaId
          LEFT JOIN persona rp ON rp.PersonaId = es.RepresentanteId
             $where
           ORDER BY p.Apellidos
              LIMIT $offset, $porPagina",
            $params
        );

        Response::lista($datos, $total, $pagina, $porPagina, [
            // La pantalla arma su desplegable con esto, no con una lista fija
            'relaciones'          => self::relacionesDisponibles($this->db),
            'relaciones_retiradas'=> self::relacionesRetiradas($this->db),
        ]);
    }

    /** GET /api/estudiantes/{id} */
    public function show(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);

        $registro = $this->consultarUna(
            'SELECT es.*, p.Nombres, p.Apellidos, p.Identificacion, p.TipoIdentificacion,
                    p.Email, p.Telefono,
                    rp.Nombres AS RepNombres, rp.Apellidos AS RepApellidos,
                    rp.Identificacion AS RepIdentificacion, rp.TipoIdentificacion AS RepTipoIdentificacion,
                    rp.Email AS RepEmail, rp.Telefono AS RepTelefono
               FROM estudiante es
          LEFT JOIN persona p  ON p.PersonaId  = es.PersonaId
          LEFT JOIN persona rp ON rp.PersonaId = es.RepresentanteId
              WHERE es.EstudianteId = ? AND es.InstitucionEducativaId = ?',
            [(int)$ruta['id'], $this->institucion()]
        );

        if (!$registro) {
            Response::noEncontrado();
        }
        Response::exito($registro);
    }

    /** POST /api/estudiantes */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $datos = $this->validar();

        $yaEsEstudiante = $this->consultarUna(
            'SELECT es.EstudianteId FROM estudiante es
       INNER JOIN persona p ON p.PersonaId = es.PersonaId
            WHERE es.InstitucionEducativaId = ? AND p.Identificacion = ?',
            [$institucionId, $datos['persona']['identificacion']]
        );
        if ($yaEsEstudiante) {
            Response::error('Ya existe un estudiante matriculado con esa identificación.', 409);
        }

        try {
            $this->db->beginTransaction();

            $personaId       = Padron::crearOActualizar($this->db, $institucionId, $datos['persona']);
            $representanteId = $datos['representante'] !== null
                ? Padron::crearOActualizar($this->db, $institucionId, $datos['representante'])
                : null;

            $this->ejecutar(
                'INSERT INTO estudiante
                    (InstitucionEducativaId, PersonaId, CodigoEstudiante, RepresentanteId, RepresentanteRelacion, Estado)
                 VALUES (?,?,?,?,?,?)',
                [
                    $institucionId, $personaId, $datos['codigo'],
                    $representanteId, $datos['relacion'], $datos['estado'],
                ]
            );
            $id = (int)$this->db->lastInsertId();

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'Ya existe un estudiante con ese código.');
            return;
        }

        $this->auditarInsercion('persona', 'PersonaId', $personaId, $institucionId);
        if ($representanteId !== null) {
            $this->auditarInsercion('persona', 'PersonaId', $representanteId, $institucionId);
        }
        $this->auditarInsercion('estudiante', 'EstudianteId', $id, $institucionId);

        Response::exito(
            ['EstudianteId' => $id, 'mensaje' => 'Estudiante matriculado correctamente.'],
            [],
            201
        );
    }

    /** PUT /api/estudiantes/{id} */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id    = (int)$ruta['id'];
        $datos = $this->validar();

        $antes = $this->filaAuditable('estudiante', 'EstudianteId', $id, $institucionId);
        if (!$antes) {
            Response::noEncontrado();
        }

        $personaId    = (int)$antes['PersonaId'];
        $antesPersona = $this->filaAuditable('persona', 'PersonaId', $personaId, $institucionId);

        if (Padron::documentoOcupado($this->db, $institucionId, $datos['persona']['identificacion'], $personaId)) {
            Response::error('Otra persona de esta institución ya tiene esa identificación.', 409);
        }

        try {
            $this->db->beginTransaction();

            // Datos del estudiante: se editan sobre su propia ficha
            Padron::actualizar($this->db, $institucionId, $personaId, $datos['persona']);

            /* El representante puede cambiar de persona: si el documento que
               llega es otro, se enlaza (o se crea) esa ficha en vez de pisar la
               del representante anterior, que puede representar a otro alumno. */
            $representanteId = null;
            if ($datos['representante'] !== null) {
                $representanteId = Padron::crearOActualizar($this->db, $institucionId, $datos['representante']);
            }

            $this->ejecutar(
                'UPDATE estudiante
                    SET CodigoEstudiante = ?,
                        RepresentanteId = ?, RepresentanteRelacion = ?, Estado = ?
                  WHERE EstudianteId = ? AND InstitucionEducativaId = ?',
                [
                    $datos['codigo'],
                    $representanteId, $datos['relacion'], $datos['estado'], $id, $institucionId,
                ]
            );

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'Ya existe un estudiante con ese código.');
            return;
        }

        $this->auditarActualizacion('persona',    'PersonaId',    $personaId, $antesPersona, $institucionId);
        $this->auditarActualizacion('estudiante', 'EstudianteId', $id, $antes, $institucionId);

        Response::exito(['EstudianteId' => $id, 'mensaje' => 'Estudiante actualizado correctamente.']);
    }

    /** PATCH /api/estudiantes/{id}/estado */
    public function estadoCambiar(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $registro = $this->consultarUna(
            'SELECT Estado FROM estudiante WHERE EstudianteId = ? AND InstitucionEducativaId = ?',
            [$id, $institucionId]
        );
        if (!$registro) {
            Response::noEncontrado();
        }

        $nuevo = $this->peticion->texto('estado') !== ''
            ? $this->estado($this->peticion->texto('estado'))
            : $this->estadoInvertido($registro['Estado']);

        $this->ejecutar(
            'UPDATE estudiante SET Estado = ? WHERE EstudianteId = ? AND InstitucionEducativaId = ?',
            [$nuevo, $id, $institucionId]
        );
        $this->auditarActualizacion('estudiante', 'EstudianteId', $id, $registro, $institucionId);

        Response::exito(['EstudianteId' => $id, 'estado' => $nuevo, 'mensaje' => 'Estado actualizado.']);
    }

    /* ------------------------------------------------------------------ */

    private function validar(): array
    {
        $codigo   = $this->peticion->texto('codigo_estudiante');
        $relacion = $this->peticion->texto('representante_relacion');
        $cuerpo   = $this->peticion->cuerpo;

        // Datos del estudiante y de su representante, en el mismo formulario
        $persona       = Padron::normalizar($cuerpo);
        $representante = Padron::normalizar($cuerpo, 'rep_');

        $errores = Padron::validar($persona, 'el estudiante');

        if ($codigo === '') $errores[] = 'El código de estudiante es obligatorio.';

        /* El representante es opcional: solo se valida si se escribió algo de
           él. Si viene, su correo SÍ es obligatorio, porque es la dirección a
           la que llegan los avisos de consentimiento del estudiante. */
        $hayRepresentante = $representante['identificacion'] !== ''
            || $representante['nombres'] !== ''
            || $representante['apellidos'] !== ''
            || $representante['email'] !== '';

        if ($hayRepresentante) {
            $errores = array_merge($errores, Padron::validar($representante, 'el representante', true));

            if ($representante['identificacion'] !== ''
                && $representante['identificacion'] === $persona['identificacion']) {
                $errores[] = 'El representante no puede ser la misma persona que el estudiante.';
            }
        } elseif ($relacion !== '') {
            $errores[] = 'Indicó una relación de representante, pero no sus datos.';
        }

        /* Se avisa con claridad en lugar de dejar que MySQL rechace el valor con
           un error de truncamiento, que no le dice nada a quien está usando el
           sistema. Ocurre cuando la base todavía no tiene las relaciones nuevas. */
        $disponibles = self::relacionesDisponibles($this->db);
        if ($relacion !== '' && !in_array($relacion, $disponibles, true)) {
            $errores[] = in_array($relacion, self::RELACIONES, true)
                ? 'La relación «' . $relacion . '» todavía no está habilitada en la base de datos. '
                  . 'Ejecute el script de actualización de estructura, o elija una de estas: '
                  . implode(', ', $disponibles) . '.'
                : 'La relación del representante no es válida. Elija una de estas: '
                  . implode(', ', $disponibles) . '.';
        }

        if ($errores) {
            Response::validacion($errores);
        }

        $estado = $this->estado($this->peticion->texto('estado', 'ACTIVO'));
        $persona['estado'] = $estado;

        return [
            'persona'       => $persona,
            // El representante conserva su propio estado: puede seguir activo
            // aunque el estudiante se inactive.
            'representante' => $hayRepresentante ? $representante : null,
            'codigo'        => $codigo,
            'relacion'      => $relacion !== '' ? $relacion : null,
            'estado'        => $estado,
        ];
    }
}
