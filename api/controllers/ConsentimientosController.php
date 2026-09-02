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

    /**
     * Revocar deja sin efecto una autorización del titular, así que la reserva
     * el SuperAdmin. Ver la clave `consentimientos_revocar` en accesos.php.
     */
    private function autorizarRevocacion(): array
    {
        return $this->requiereAcceso('consentimientos_revocar');
    }

    /** GET /api/consentimientos?q=&estado=&pagina= */
    public function index(array $ruta = []): void
    {
        $this->autorizar();
        $institucionId = $this->institucion();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'WHERE c.InstitucionEducativaId = ? AND (p.Nombres LIKE ? OR p.Apellidos LIKE ? OR f.Nombre LIKE ?)';
        $params = [$institucionId, $buscar, $buscar, $buscar];

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

    /**
     * POST /api/consentimientos
     *
     * **Retirado.** El consentimiento lo otorga el titular desde su enlace
     * público, con verificación de identidad y constancia de fecha, hora,
     * dirección de origen y versión de la política aceptada. Registrarlo a mano
     * dejaba en la base una autorización que el sistema no podía acreditar: si
     * mañana alguien pregunta quién consintió y cómo, no hay respuesta.
     *
     * La ruta se conserva para responder con el motivo en vez de un 404.
     */
    public function store(array $ruta = []): void
    {
        $this->autorizar();

        Response::error(
            'El consentimiento lo otorga el titular desde su enlace de consentimiento; '
            . 'no se registra a mano. Use «Envío Masivo de Invitaciones» para pedírselo.',
            403
        );
    }

    /**
     * PUT /api/consentimientos/{id}
     *
     * **Retirado.** Un consentimiento otorgado es un hecho con fecha: no se
     * edita. Si cambió lo que el titular autoriza, lo que corresponde es que
     * vuelva a pronunciarse, no que alguien reescriba lo que dijo. Lo único que
     * puede hacerse sobre él es revocarlo, y eso queda en el historial.
     */
    public function update(array $ruta): void
    {
        $this->autorizar();

        Response::error(
            'Un consentimiento otorgado no se modifica: es un hecho con fecha y constancia. '
            . 'Puede consultarlo o revocarlo.',
            403
        );
    }

    /** POST /api/consentimientos/{id}/revocar — solo SuperAdmin. */
    public function revocar(array $ruta): void
    {
        $usuario = $this->autorizarRevocacion();
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

    /**
     * POST /api/consentimientos/{id}/reactivar — solo SuperAdmin.
     *
     * Ya no se ofrece en la pantalla: devolver la vigencia a un consentimiento
     * revocado es una decisión del titular, y el camino para eso es que vuelva a
     * otorgarlo desde su enlace público, con su verificación y su constancia. La
     * ruta se conserva para deshacer una revocación hecha por error.
     */
    public function reactivar(array $ruta): void
    {
        $usuario = $this->autorizarRevocacion();
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
}
