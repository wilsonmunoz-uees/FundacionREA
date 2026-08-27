<?php
// api/controllers/EmpleadosController.php
// CRUD de empleados: siempre acotado a la institución educativa del token.
//
// `persona` es la entidad padre y no tiene mantenimiento propio: los datos
// personales del empleado se capturan aquí mismo. Al guardar, la ficha se crea
// o se reutiliza si ese documento ya consta en el padrón de la institución
// (ver api/core/Padron.php).

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

    /** POST /api/empleados */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $datos = $this->validar();

        // Un mismo documento no puede estar dos veces como empleado
        $yaEsEmpleado = $this->consultarUna(
            'SELECT e.EmpleadoId FROM empleado e
       INNER JOIN persona p ON p.PersonaId = e.PersonaId
            WHERE e.InstitucionEducativaId = ? AND p.Identificacion = ?',
            [$institucionId, $datos['persona']['identificacion']]
        );
        if ($yaEsEmpleado) {
            Response::error('Ya existe un empleado registrado con esa identificación.', 409);
        }

        try {
            $this->db->beginTransaction();

            // La ficha se crea, o se reutiliza si esa persona ya está en el padrón
            $personaId = Padron::crearOActualizar($this->db, $institucionId, $datos['persona']);

            $this->ejecutar(
                'INSERT INTO empleado (InstitucionEducativaId, PersonaId, Estado) VALUES (?,?,?)',
                [$institucionId, $personaId, $datos['estado']]
            );
            $id = (int)$this->db->lastInsertId();

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'Ya existe un empleado registrado para esa persona.');
            return;
        }

        $this->auditarInsercion('persona',  'PersonaId',  $personaId, $institucionId);
        $this->auditarInsercion('empleado', 'EmpleadoId', $id, $institucionId);

        Response::exito(
            ['EmpleadoId' => $id, 'mensaje' => 'Empleado registrado correctamente.'],
            [],
            201
        );
    }

    /** PUT /api/empleados/{id} */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id    = (int)$ruta['id'];
        $datos = $this->validar();

        $antes = $this->filaAuditable('empleado', 'EmpleadoId', $id, $institucionId);
        if (!$antes) {
            Response::noEncontrado();
        }

        $personaId    = (int)$antes['PersonaId'];
        $antesPersona = $this->filaAuditable('persona', 'PersonaId', $personaId, $institucionId);

        // Al editar se cambian los datos de la persona ya vinculada, así que el
        // documento nuevo no puede ser el de otra ficha de la institución.
        if (Padron::documentoOcupado($this->db, $institucionId, $datos['persona']['identificacion'], $personaId)) {
            Response::error('Otra persona de esta institución ya tiene esa identificación.', 409);
        }

        try {
            $this->db->beginTransaction();

            Padron::actualizar($this->db, $institucionId, $personaId, $datos['persona']);

            $this->ejecutar(
                'UPDATE empleado SET Estado = ?
                  WHERE EmpleadoId = ? AND InstitucionEducativaId = ?',
                [$datos['estado'], $id, $institucionId]
            );

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'Ya existe un empleado registrado para esa persona.');
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

    /* ------------------------------------------------------------------ */

    private function validar(): array
    {
        $persona = Padron::normalizar($this->peticion->cuerpo);
        $errores = Padron::validar($persona, 'el empleado');

        if ($errores) {
            Response::validacion($errores);
        }

        $estado = $this->estado($this->peticion->texto('estado', 'ACTIVO'));

        // El vínculo manda: si el empleado se inactiva, su ficha también
        $persona['estado'] = $estado;

        return [
            'persona' => $persona,
            'estado'  => $estado,
        ];
    }
}
