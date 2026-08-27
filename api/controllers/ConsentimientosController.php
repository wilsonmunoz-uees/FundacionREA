<?php
// api/controllers/ConsentimientosController.php
// Núcleo del sistema: consentimientos, detalle de tipos de dato autorizados
// (consentimientodato) y bitácora automática (consentimientohistorial).

final class ConsentimientosController extends Controller
{
    private const ROLES  = ['SuperAdmin', 'RecursosHumanos', 'Secretaria'];
    private const MEDIOS = ['WEB', 'EMAIL', 'WHATSAPP', 'APP'];

    /** Acceso: uno de los roles del módulo o el permiso REGISTRO_DATOS. */
    private function autorizar(): array
    {
        return $this->requiereAcceso('consentimientos');
    }

    /** GET /api/consentimientos?q=&estado=&pagina= */
    public function index(array $ruta = []): void
    {
        $this->autorizar();
        $institucionId = $this->institucion();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'WHERE c.InstitucionEducativaId = ? AND (p.Nombres LIKE ? OR p.Apellidos LIKE ? OR p.Identificacion LIKE ? OR p.Email LIKE ? OR f.Nombre LIKE ?)';
        $params = [$institucionId, $buscar, $buscar, $buscar, $buscar, $buscar];

        $estadoFiltro = strtoupper($this->peticion->paramTexto('estado'));
        if (in_array($estadoFiltro, ['ACTIVO', 'INACTIVO'], true)) {
            $where   .= ' AND c.Estado = ?';
            $params[] = $estadoFiltro;
        }

        $desde = 'FROM consentimiento c
             LEFT JOIN persona p   ON p.PersonaId   = c.PersonaId
             LEFT JOIN finalidad f ON f.FinalidadId = c.FinalidadId';

        $total = $this->contar("SELECT COUNT(*) total $desde $where", $params);
        [$pagina, $porPagina, $offset] = $this->paginacion(12);

        $datos = $this->consultar(
            "SELECT c.*, p.Nombres, p.Apellidos, f.Nombre AS FinalidadNombre
             $desde $where
             ORDER BY c.FechaConsentimiento DESC
             LIMIT $offset, $porPagina",
            $params
        );

        Response::lista($datos, $total, $pagina, $porPagina);
    }

    /**
     * GET /api/consentimientos/catalogos
     * Devuelve de una sola llamada lo que necesitan los formularios:
     * personas activas, finalidades activas y tipos de dato.
     */
    public function catalogos(array $ruta = []): void
    {
        $this->autorizar();

        Response::exito([
            'personas' => $this->consultar(
                "SELECT PersonaId, CONCAT(Apellidos, ' ', Nombres, ' - ', COALESCE(Identificacion,'S/I')) AS etiqueta
                   FROM persona
                  WHERE InstitucionEducativaId = ? AND Estado = 'ACTIVO'
               ORDER BY Apellidos, Nombres",
                [$this->institucion()]
            ),
            'finalidades' => $this->consultar(
                "SELECT FinalidadId, Nombre FROM finalidad WHERE Activo = 'ACTIVO' ORDER BY Nombre"
            ),
            'tipos_dato' => $this->consultar(
                'SELECT TipoDatoId, Nombre, EsSensible FROM tipodato ORDER BY Nombre'
            ),
        ]);
    }

    /** GET /api/consentimientos/{id} — incluye los tipos de dato autorizados. */
    public function show(array $ruta): void
    {
        $this->autorizar();
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $registro = $this->consultarUna(
            'SELECT c.*, p.Nombres, p.Apellidos, p.Identificacion, f.Nombre AS FinalidadNombre,
                    rp.Nombres AS RepNombres, rp.Apellidos AS RepApellidos, rp.Identificacion AS RepIdentificacion
               FROM consentimiento c
          LEFT JOIN persona p   ON p.PersonaId   = c.PersonaId
          LEFT JOIN persona rp  ON rp.PersonaId  = c.RepresentanteId
          LEFT JOIN finalidad f ON f.FinalidadId = c.FinalidadId
              WHERE c.ConsentimientoId = ? AND c.InstitucionEducativaId = ?',
            [$id, $institucionId]
        );
        if (!$registro) {
            Response::noEncontrado();
        }

        $registro['tipos_autorizados'] = array_map('intval', $this->columna(
            "SELECT TipoDatoId FROM consentimientodato
              WHERE ConsentimientoId = ? AND InstitucionEducativaId = ? AND Autorizado = 'SI'",
            [$id, $institucionId]
        ));

        Response::exito($registro);
    }

    /** POST /api/consentimientos */
    public function store(array $ruta = []): void
    {
        $usuario = $this->autorizar();
        $institucionId = $this->institucion();
        $datos = $this->validar();

        try {
            $this->db->beginTransaction();

            $this->ejecutar(
                "INSERT INTO consentimiento
                    (InstitucionEducativaId, PersonaId, FinalidadId, FechaConsentimiento, FechaRevocacion,
                     RepresentanteId, MedioConsentimiento, VersionPolitica, IpOrigen, Estado)
                 VALUES (?,?,?,?,NULL,?,?,?,?,'ACTIVO')",
                [
                    $institucionId, $datos['persona_id'], $datos['finalidad_id'], $datos['fecha'],
                    $datos['representante_id'], $datos['medio'], $datos['version_politica'], $datos['ip_origen'],
                ]
            );

            $consentimientoId = (int)$this->db->lastInsertId();

            $this->registrarHistorial(
                $institucionId, $consentimientoId, null, 'ACTIVO', 'CREACION',
                'Registro inicial del consentimiento.', $usuario, $datos['ip_origen']
            );

            $this->sincronizarTiposDato($institucionId, $consentimientoId, $datos['tipos_autorizados']);

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'El consentimiento ya existe.');
        }

        $this->auditarInsercion('consentimiento', 'ConsentimientoId', $consentimientoId, $institucionId);
        $this->auditarLista(
            'consentimiento', $consentimientoId, 'TiposDatoAutorizados',
            [], $datos['tipos_autorizados']
        );

        Response::exito(
            ['ConsentimientoId' => $consentimientoId, 'mensaje' => 'Consentimiento registrado correctamente.'],
            [],
            201
        );
    }

    /** PUT /api/consentimientos/{id} */
    public function update(array $ruta): void
    {
        $usuario = $this->autorizar();
        $institucionId = $this->institucion();
        $id    = (int)$ruta['id'];
        $datos = $this->validar();

        $actual = $this->filaAuditable('consentimiento', 'ConsentimientoId', $id, $institucionId);
        if (!$actual) {
            Response::noEncontrado();
        }
        $estadoAnterior = $actual['Estado'] ?? null;
        $tiposAntes     = array_map('intval', $this->columna(
            "SELECT TipoDatoId FROM consentimientodato
              WHERE ConsentimientoId = ? AND InstitucionEducativaId = ? AND Autorizado = 'SI'",
            [$id, $institucionId]
        ));

        try {
            $this->db->beginTransaction();

            $this->ejecutar(
                'UPDATE consentimiento
                    SET PersonaId = ?, FinalidadId = ?, FechaConsentimiento = ?, RepresentanteId = ?,
                        MedioConsentimiento = ?, VersionPolitica = ?, IpOrigen = ?
                  WHERE ConsentimientoId = ? AND InstitucionEducativaId = ?',
                [
                    $datos['persona_id'], $datos['finalidad_id'], $datos['fecha'], $datos['representante_id'],
                    $datos['medio'], $datos['version_politica'], $datos['ip_origen'], $id, $institucionId,
                ]
            );

            $this->registrarHistorial(
                $institucionId, $id, $estadoAnterior, $estadoAnterior, 'MODIFICACION',
                'Actualización de datos del consentimiento.', $usuario, $datos['ip_origen']
            );

            $this->sincronizarTiposDato($institucionId, $id, $datos['tipos_autorizados']);

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'No se pudo actualizar el consentimiento.');
        }

        $this->auditarActualizacion('consentimiento', 'ConsentimientoId', $id, $actual, $institucionId);
        $this->auditarLista(
            'consentimiento', $id, 'TiposDatoAutorizados',
            $tiposAntes, $datos['tipos_autorizados']
        );

        Response::exito(['ConsentimientoId' => $id, 'mensaje' => 'Consentimiento actualizado correctamente.']);
    }

    /** POST /api/consentimientos/{id}/revocar */
    public function revocar(array $ruta): void
    {
        $usuario = $this->autorizar();
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $actual = $this->filaAuditable('consentimiento', 'ConsentimientoId', $id, $institucionId);
        if (!$actual) {
            Response::noEncontrado();
        }

        $observacion = $this->peticion->texto('observacion') ?: 'Consentimiento revocado por el titular.';

        $this->ejecutar(
            "UPDATE consentimiento SET Estado = 'INACTIVO', FechaRevocacion = NOW()
              WHERE ConsentimientoId = ? AND InstitucionEducativaId = ?",
            [$id, $institucionId]
        );

        $this->registrarHistorial(
            $institucionId, $id, $actual['Estado'], 'INACTIVO', 'REVOCACION', $observacion, $usuario
        );

        $this->auditarActualizacion('consentimiento', 'ConsentimientoId', $id, $actual, $institucionId);

        Response::exito(['ConsentimientoId' => $id, 'estado' => 'INACTIVO', 'mensaje' => 'Consentimiento revocado correctamente.']);
    }

    /** POST /api/consentimientos/{id}/reactivar */
    public function reactivar(array $ruta): void
    {
        $usuario = $this->autorizar();
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $actual = $this->filaAuditable('consentimiento', 'ConsentimientoId', $id, $institucionId);
        if (!$actual) {
            Response::noEncontrado();
        }

        $this->ejecutar(
            "UPDATE consentimiento SET Estado = 'ACTIVO', FechaRevocacion = NULL
              WHERE ConsentimientoId = ? AND InstitucionEducativaId = ?",
            [$id, $institucionId]
        );

        $this->registrarHistorial(
            $institucionId, $id, $actual['Estado'], 'ACTIVO', 'REACTIVACION',
            $this->peticion->texto('observacion') ?: 'Consentimiento reactivado.', $usuario
        );

        $this->auditarActualizacion('consentimiento', 'ConsentimientoId', $id, $actual, $institucionId);

        Response::exito(['ConsentimientoId' => $id, 'estado' => 'ACTIVO', 'mensaje' => 'Consentimiento reactivado correctamente.']);
    }

    /* ------------------------------------------------------------------ */
    /* Apoyo                                                               */
    /* ------------------------------------------------------------------ */

    /** Reescribe el detalle consentimientodato marcando SI/NO por tipo de dato. */
    private function sincronizarTiposDato(int $institucionId, int $consentimientoId, array $autorizados): void
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
            $stmt->execute([
                $institucionId,
                $consentimientoId,
                $tipoDatoId,
                in_array((int)$tipoDatoId, $autorizados, true) ? 'SI' : 'NO',
            ]);
        }
    }

    /** Bitácora de auditoría (equivalente a registrarHistorialConsentimiento). */
    private function registrarHistorial(
        int $institucionId,
        int $consentimientoId,
        ?string $estadoAnterior,
        ?string $estadoNuevo,
        string $accion,
        string $observacion,
        array $usuario,
        ?string $ipOrigen = null
    ): void {
        $this->ejecutar(
            'INSERT INTO consentimientohistorial
                (InstitucionEducativaId, ConsentimientoId, EstadoAnterior, EstadoNuevo, Accion, FechaAccion, UsuarioId, IpOrigen, Observacion)
             VALUES (?,?,?,?,?,NOW(),?,?,?)',
            [
                $institucionId,
                $consentimientoId,
                $estadoAnterior,
                $estadoNuevo,
                $accion,
                $usuario['usuario_id'] ?? null,
                $ipOrigen ?: ($_SERVER['REMOTE_ADDR'] ?? null),
                $observacion,
            ]
        );
    }

    private function validar(): array
    {
        $errores     = [];
        $personaId   = $this->peticion->entero('persona_id');
        $finalidadId = $this->peticion->entero('finalidad_id');
        $medio       = $this->peticion->texto('medio');
        $fecha       = $this->peticion->texto('fecha_consentimiento');
        $ip          = $this->peticion->texto('ip_origen');

        $institucionId   = $this->institucion();
        $representanteId = $this->peticion->entero('representante_id') ?: null;

        if ($personaId <= 0) {
            $errores[] = 'Debe seleccionar la persona titular del dato.';
        } elseif (!Padron::perteneceA($this->db, $personaId, $institucionId)) {
            $errores[] = 'El titular seleccionado no pertenece a esta institución.';
        }

        if ($representanteId && !Padron::perteneceA($this->db, $representanteId, $institucionId)) {
            $errores[] = 'El representante seleccionado no pertenece a esta institución.';
        }

        if ($finalidadId <= 0) $errores[] = 'Debe seleccionar la finalidad del tratamiento.';

        if ($errores) {
            Response::validacion($errores);
        }

        return [
            'persona_id'        => $personaId,
            'finalidad_id'      => $finalidadId,
            'representante_id'  => $representanteId,
            'medio'             => in_array($medio, self::MEDIOS, true) ? $medio : null,
            'version_politica'  => $this->peticion->texto('version_politica'),
            'fecha'             => $fecha !== '' ? $fecha : date('Y-m-d H:i:s'),
            'ip_origen'         => $ip !== '' ? $ip : ($_SERVER['REMOTE_ADDR'] ?? ''),
            'tipos_autorizados' => $this->peticion->arregloEnteros('tipos_autorizados'),
        ];
    }
}
