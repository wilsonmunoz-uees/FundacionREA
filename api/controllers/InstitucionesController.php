<?php
// api/controllers/InstitucionesController.php
// CRUD de instituciones educativas (solo SuperAdmin), más el listado público
// de instituciones activas que alimenta el combo de la pantalla de login.

final class InstitucionesController extends Controller
{
    /** Clave en includes/accesos.php: define qué roles y permisos abren este recurso. */
    private const MODULO = 'instituciones';

    /** GET /api/instituciones/activas — pública: la usa el formulario de login. */
    public function activas(array $ruta = []): void
    {
        $datos = $this->consultar(
            "SELECT id, nombre FROM institucion_educativa WHERE estado = 'ACTIVO' ORDER BY nombre"
        );
        Response::exito($datos);
    }

    /** GET /api/instituciones?q=&pagina= */
    public function index(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'FROM institucion_educativa WHERE (nombre LIKE ? OR direccion LIKE ?)';
        $params = [$buscar, $buscar];

        $total = $this->contar("SELECT COUNT(*) total $where", $params);
        [$pagina, $porPagina, $offset] = $this->paginacion(10);

        $datos = $this->consultar(
            "SELECT * $where ORDER BY id LIMIT $offset, $porPagina",
            $params
        );

        /* Identificador que le tocaría a la próxima institución. Es solo para
           mostrarlo en el formulario de alta: quien lo asigna de verdad es la
           base, con AUTO_INCREMENT, en el momento de insertar. */
        $siguienteId = (int)$this->contar(
            'SELECT COALESCE(MAX(id),0)+1 AS total FROM institucion_educativa'
        );

        Response::lista($datos, $total, $pagina, $porPagina, ['siguiente_id' => $siguienteId]);
    }

    /** GET /api/instituciones/{id} */
    public function show(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);

        $registro = $this->consultarUna(
            'SELECT * FROM institucion_educativa WHERE id = ?',
            [(int)$ruta['id']]
        );
        if (!$registro) {
            Response::noEncontrado();
        }
        Response::exito($registro);
    }

    /** POST /api/instituciones */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $datos = $this->validar(true);

        /* El identificador NO se recibe: lo genera la base. Antes se escribía a
           mano en el formulario, con lo que dos altas simultáneas podían pelear
           por el mismo número y cualquiera podía inventarse uno. */
        try {
            $this->ejecutar(
                'INSERT INTO institucion_educativa (nombre, direccion, telefono, estado)
                 VALUES (?,?,?,?)',
                [$datos['nombre'], $datos['direccion'], $datos['telefono'], $datos['estado']]
            );
            $id = (int)$this->db->lastInsertId();
        } catch (PDOException $ex) {
            $this->errorBaseDatos($ex, 'Ya existe una institución con ese nombre.');
            return;
        }

        $this->auditarInsercion('institucion_educativa', 'id', $id);

        Response::exito(
            ['id' => $id, 'mensaje' => 'Institución educativa registrada correctamente.'],
            [],
            201
        );
    }

    /** PUT /api/instituciones/{id} */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $id    = (int)$ruta['id'];
        $datos = $this->validar(false);

        $antes = $this->filaAuditable('institucion_educativa', 'id', $id);
        if (!$antes) {
            Response::noEncontrado();
        }

        try {
            $this->ejecutar(
                'UPDATE institucion_educativa
                    SET nombre = ?, direccion = ?, telefono = ?, estado = ?
                  WHERE id = ?',
                [$datos['nombre'], $datos['direccion'], $datos['telefono'], $datos['estado'], $id]
            );
        } catch (PDOException $ex) {
            $this->errorBaseDatos($ex, 'Ya existe una institución con ese identificador o nombre.');
        }

        $this->auditarActualizacion('institucion_educativa', 'id', $id, $antes);

        Response::exito(['id' => $id, 'mensaje' => 'Institución educativa actualizada correctamente.']);
    }

    /** PATCH /api/instituciones/{id}/estado */
    public function estadoCambiar(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $id = (int)$ruta['id'];

        $registro = $this->consultarUna('SELECT estado FROM institucion_educativa WHERE id = ?', [$id]);
        if (!$registro) {
            Response::noEncontrado();
        }

        $nuevo = $this->peticion->texto('estado') !== ''
            ? $this->estado($this->peticion->texto('estado'))
            : $this->estadoInvertido($registro['estado']);

        $this->ejecutar('UPDATE institucion_educativa SET estado = ? WHERE id = ?', [$nuevo, $id]);
        $this->auditarActualizacion('institucion_educativa', 'id', $id, $registro);

        Response::exito(['id' => $id, 'estado' => $nuevo, 'mensaje' => 'Estado actualizado.']);
    }

    /* ------------------------------------------------------------------ */

    private function validar(bool $esNuevo): array
    {
        $errores   = [];
        $nombre    = $this->peticion->texto('nombre');
        $direccion = $this->peticion->texto('direccion');
        $telefono  = $this->peticion->texto('telefono');

        if ($nombre === '')    $errores[] = 'El nombre es obligatorio.';
        if ($direccion === '') $errores[] = 'La dirección es obligatoria.';

        // El teléfono sigue la misma regla que en el resto del sistema
        $errores = array_merge($errores, Telefono::validar($telefono, 'la institución', true));

        if ($errores) {
            Response::validacion($errores);
        }

        return [
            'nombre'    => $nombre,
            'direccion' => $direccion,
            'telefono'  => Telefono::normalizar($telefono),
            'estado'    => $this->estado($this->peticion->texto('estado', 'ACTIVO')),
        ];
    }
}
