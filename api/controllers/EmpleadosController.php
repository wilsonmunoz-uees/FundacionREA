<?php
// api/controllers/EmpleadosController.php
// Mantenimiento de empleados: siempre acotado a la institución educativa del token.
//
// Las ALTAS ya no ocurren aquí. Los empleados entran por «Carga de Información»
// (CargaInformacionController), que valida el archivo completo contra el padrón
// antes de escribir nada. Este módulo solo consulta y permite corregir los datos
// de contacto —correo y teléfono— y el estado del vínculo.
//
// El documento, el tipo y el nombre no se editan desde ninguna pantalla: son los
// que trajo la carga. Al actualizar se leen de la ficha grabada y no de lo que
// llegue en la petición (ver Padron::contacto).

final class EmpleadosController extends Controller
{
    private const ROLES = ['SuperAdmin', 'RecursosHumanos'];
    /** Clave en includes/accesos.php: define qué permisos abren este recurso. */
    private const MODULO = 'empleados';

    /** GET /api/empleados?q=&pagina= */
    public function index(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'FROM empleado e
             INNER JOIN persona p ON p.PersonaId = e.PersonaId
                  WHERE e.InstitucionEducativaId = ?
                    AND (p.Nombres LIKE ? OR p.Apellidos LIKE ? OR p.Identificacion LIKE ?)';
        $params = [$institucionId, $buscar, $buscar, $buscar];

        $total = $this->contar("SELECT COUNT(*) total $where", $params);
        [$pagina, $porPagina, $offset] = $this->paginacion(12);

        $datos = $this->consultar(
            "SELECT e.*, p.Nombres, p.Apellidos, p.Identificacion, p.Email
             $where ORDER BY p.Apellidos LIMIT $offset, $porPagina",
            $params
        );

        Response::lista($datos, $total, $pagina, $porPagina);
    }

    /** GET /api/empleados/{id} */
    public function show(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);

        $registro = $this->consultarUna(
            'SELECT e.*, p.Nombres, p.Apellidos, p.Identificacion, p.TipoIdentificacion,
                    p.Email, p.Telefono, p.Estado AS EstadoPersona
               FROM empleado e
          LEFT JOIN persona p ON p.PersonaId = e.PersonaId
              WHERE e.EmpleadoId = ? AND e.InstitucionEducativaId = ?',
            [(int)$ruta['id'], $this->institucion()]
        );

        if (!$registro) {
            Response::noEncontrado();
        }
        Response::exito($registro);
    }

    /**
     * POST /api/empleados
     *
     * El alta individual está retirada: los empleados entran por Carga de
     * Información, que es donde se valida el archivo completo contra el padrón.
     * La ruta se conserva para poder responder con un motivo en vez de un 404.
     */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);

        Response::error(
            'El alta de empleados se realiza desde la opción «Carga de Información». '
            . 'Desde esta pantalla solo puede actualizar el correo, el teléfono y el estado.',
            403
        );
    }

    /**
     * PUT /api/empleados/{id}
     *
     * Edición restringida: solo el correo, el teléfono y el estado. El
     * documento y el nombre son los que trajo la carga y no se tocan aquí; se
     * leen de la ficha grabada, no de lo que llegue en la petición.
     */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $antes = $this->filaAuditable('empleado', 'EmpleadoId', $id, $institucionId);
        if (!$antes) {
            Response::noEncontrado();
        }

        $personaId    = (int)$antes['PersonaId'];
        $antesPersona = $this->filaAuditable('persona', 'PersonaId', $personaId, $institucionId);
        $ficha        = Padron::porId($this->db, $institucionId, $personaId);

        if (!$ficha) {
            Response::noEncontrado();
        }

        $persona = Padron::contacto($ficha, $this->peticion->cuerpo);
        $errores = Padron::validarContacto($persona, 'el empleado');

        if ($errores) {
            Response::validacion($errores);
        }

        // El vínculo manda: si el empleado se inactiva, su ficha también
        $estado = $this->estado($this->peticion->texto('estado', (string)$antes['Estado']));
        $persona['estado'] = $estado;

        try {
            $this->db->beginTransaction();

            Padron::actualizar($this->db, $institucionId, $personaId, $persona);

            $this->ejecutar(
                'UPDATE empleado SET Estado = ?
                  WHERE EmpleadoId = ? AND InstitucionEducativaId = ?',
                [$estado, $id, $institucionId]
            );

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'No se pudo actualizar el empleado.');
            return;
        }

        $this->auditarActualizacion('persona',  'PersonaId',  $personaId, $antesPersona, $institucionId);
        $this->auditarActualizacion('empleado', 'EmpleadoId', $id, $antes, $institucionId);

        Response::exito(['EmpleadoId' => $id, 'mensaje' => 'Empleado actualizado correctamente.']);
    }

    /** PATCH /api/empleados/{id}/estado */
    public function estadoCambiar(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $registro = $this->consultarUna(
            'SELECT Estado FROM empleado WHERE EmpleadoId = ? AND InstitucionEducativaId = ?',
            [$id, $institucionId]
        );
        if (!$registro) {
            Response::noEncontrado();
        }

        $nuevo = $this->peticion->texto('estado') !== ''
            ? $this->estado($this->peticion->texto('estado'))
            : $this->estadoInvertido($registro['Estado']);

        $this->ejecutar(
            'UPDATE empleado SET Estado = ? WHERE EmpleadoId = ? AND InstitucionEducativaId = ?',
            [$nuevo, $id, $institucionId]
        );
        $this->auditarActualizacion('empleado', 'EmpleadoId', $id, $registro, $institucionId);

        Response::exito(['EmpleadoId' => $id, 'estado' => $nuevo, 'mensaje' => 'Estado actualizado.']);
    }

}
