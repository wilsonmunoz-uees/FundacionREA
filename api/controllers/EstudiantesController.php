<?php
// api/controllers/EstudiantesController.php
// Mantenimiento de estudiantes matriculados y su representante legal.
//
// Las MATRÍCULAS ya no ocurren aquí. Los estudiantes entran por «Carga de
// Información» (CargaInformacionController), que valida el archivo completo
// —incluido que el representante conste en su hoja— antes de escribir nada.
//
// Este módulo solo consulta y permite corregir lo que cambia con el tiempo: del
// estudiante, correo, teléfono y estado; de su representante, correo, teléfono y
// parentesco. El documento, los nombres, el código de matrícula y la identidad
// del representante se leen de la base y no de la petición (Padron::contacto).

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

    /**
     * POST /api/estudiantes
     *
     * La matrícula individual está retirada: los estudiantes entran por Carga
     * de Información, que valida el archivo completo —incluida la existencia
     * del representante— antes de escribir nada. La ruta se conserva para poder
     * responder con un motivo en vez de un 404.
     */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);

        Response::error(
            'La matrícula de estudiantes se realiza desde la opción «Carga de Información». '
            . 'Desde esta pantalla solo puede actualizar los datos de contacto y el estado.',
            403
        );
    }

    /**
     * PUT /api/estudiantes/{id}
     *
     * Edición restringida. Del estudiante solo se aceptan correo, teléfono y
     * estado; de su representante, correo, teléfono y parentesco. Todo lo demás
     * —documento, nombres, código de matrícula y quién es el representante— es
     * lo que trajo la carga, y se lee de la base, no de la petición.
     */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $antes = $this->filaAuditable('estudiante', 'EstudianteId', $id, $institucionId);
        if (!$antes) {
            Response::noEncontrado();
        }

        $personaId    = (int)$antes['PersonaId'];
        $antesPersona = $this->filaAuditable('persona', 'PersonaId', $personaId, $institucionId);
        $ficha        = Padron::porId($this->db, $institucionId, $personaId);

        if (!$ficha) {
            Response::noEncontrado();
        }

        $cuerpo  = $this->peticion->cuerpo;
        $persona = Padron::contacto($ficha, $cuerpo);
        $errores = Padron::validarContacto($persona, 'el estudiante');

        /* El representante ya está asignado por la carga: aquí solo se corrigen
           sus datos de contacto y el parentesco. Si el estudiante todavía no
           tiene representante, no hay nada que editar desde esta pantalla. */
        $representanteId = $antes['RepresentanteId'] !== null ? (int)$antes['RepresentanteId'] : null;
        $fichaRep        = $representanteId !== null
            ? Padron::porId($this->db, $institucionId, $representanteId)
            : null;
        $representante   = $fichaRep !== null ? Padron::contacto($fichaRep, $cuerpo, 'rep_') : null;

        if ($representante !== null) {
            $errores = array_merge(
                $errores,
                Padron::validarContacto($representante, 'el representante', true)
            );
        }

        /* El parentesco sí se puede cambiar. Se avisa con claridad en lugar de
           dejar que MySQL rechace el valor con un error de truncamiento, que no
           le dice nada a quien está usando el sistema. */
        $relacion    = $this->peticion->texto('representante_relacion');
        $disponibles = self::relacionesDisponibles($this->db);

        if ($relacion !== '' && !in_array($relacion, $disponibles, true)) {
            $errores[] = in_array($relacion, self::RELACIONES, true)
                ? 'La relación «' . $relacion . '» todavía no está habilitada en la base de datos. '
                  . 'Ejecute el script de actualización de estructura, o elija una de estas: '
                  . implode(', ', $disponibles) . '.'
                : 'La relación del representante no es válida. Elija una de estas: '
                  . implode(', ', $disponibles) . '.';
        }
        if ($relacion !== '' && $representante === null) {
            $errores[] = 'Este estudiante no tiene representante asignado. '
                       . 'Asígnelo desde la opción «Carga de Información».';
        }

        if ($errores) {
            Response::validacion($errores);
        }

        $estado = $this->estado($this->peticion->texto('estado', (string)$antes['Estado']));
        $persona['estado'] = $estado;

        try {
            $this->db->beginTransaction();

            // Datos de contacto del estudiante, sobre su propia ficha
            Padron::actualizar($this->db, $institucionId, $personaId, $persona);

            /* El representante conserva su propio estado: puede seguir activo
               aunque el estudiante se inactive. */
            if ($representante !== null && $representanteId !== null) {
                Padron::actualizar($this->db, $institucionId, $representanteId, $representante);
            }

            $this->ejecutar(
                'UPDATE estudiante
                    SET RepresentanteRelacion = ?, Estado = ?
                  WHERE EstudianteId = ? AND InstitucionEducativaId = ?',
                [$relacion !== '' ? $relacion : null, $estado, $id, $institucionId]
            );

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'No se pudo actualizar el estudiante.');
            return;
        }

        $this->auditarActualizacion('persona',    'PersonaId',    $personaId, $antesPersona, $institucionId);
        $this->auditarActualizacion('estudiante', 'EstudianteId', $id, $antes, $institucionId);

        /* Datos de contacto nuevos, consentimiento nuevo. En estudiantes el
           correo va al representante: es él quien consiente por el menor, y es
           su dirección la que consta en la ficha. */
        $invitacion = InvitacionConsentimiento::enviarA($this->db, $institucionId, 'ESTUDIANTE', [
            'destino'        => $representante !== null ? $representante['email'] : $persona['email'],
            'titular'        => trim($persona['apellidos'] . ' ' . $persona['nombres']),
            'identificacion' => $persona['identificacion'],
            'representante'  => $representante !== null
                ? trim($representante['apellidos'] . ' ' . $representante['nombres'])
                : '',
        ]);

        Response::exito([
            'EstudianteId' => $id,
            'mensaje'      => 'Estudiante actualizado correctamente.',
            'invitacion'   => $invitacion,
        ]);
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

}
