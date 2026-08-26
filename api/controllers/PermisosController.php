<?php
// api/controllers/PermisosController.php
// CRUD del catálogo de permisos del sistema (por institución).

final class PermisosController extends Controller
{
    private const ROLES   = ['SuperAdmin'];
    /** Clave en includes/accesos.php: define qué permisos abren este recurso. */
    private const MODULO = 'permisos';
    private const MODULOS = ['ADMINISTRACION', 'REGISTRO_DATOS', 'CONSULTA_BUSQUEDAS', 'REPORTES_EXPORTACION'];

    /** GET /api/permisos?q=&pagina= */
    public function index(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'FROM permiso WHERE InstitucionEducativaId = ? AND (Nombre LIKE ? OR Codigo LIKE ?)';
        $params = [$institucionId, $buscar, $buscar];

        $total = $this->contar("SELECT COUNT(*) total $where", $params);
        [$pagina, $porPagina, $offset] = $this->paginacion(12);

        $datos = $this->consultar("SELECT * $where ORDER BY Modulo, Nombre LIMIT $offset, $porPagina", $params);

        Response::lista($datos, $total, $pagina, $porPagina, ['modulos' => self::MODULOS]);
    }

    /** GET /api/permisos/{id} */
    public function show(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);

        $registro = $this->consultarUna(
            'SELECT * FROM permiso WHERE PermisoId = ? AND InstitucionEducativaId = ?',
            [(int)$ruta['id'], $this->institucion()]
        );
        if (!$registro) {
            Response::noEncontrado();
        }
        Response::exito($registro);
    }

    /** POST /api/permisos */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $datos = $this->validar();

        try {
            $this->ejecutar(
                'INSERT INTO permiso (InstitucionEducativaId, Codigo, Nombre, Modulo, Descripcion, Estado)
                 VALUES (?,?,?,?,?,?)',
                [$institucionId, $datos['codigo'], $datos['nombre'], $datos['modulo'], $datos['descripcion'], $datos['estado']]
            );
        } catch (PDOException $ex) {
            $this->errorBaseDatos($ex, 'Ya existe un permiso con ese código.');
        }

        $id = (int)$this->db->lastInsertId();
        $this->auditarInsercion('permiso', 'PermisoId', $id, $institucionId);

        Response::exito(
            ['PermisoId' => $id, 'mensaje' => 'Permiso registrado correctamente.'],
            [],
            201
        );
    }

    /** PUT /api/permisos/{id} */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id    = (int)$ruta['id'];
        $datos = $this->validar();

        $antes = $this->filaAuditable('permiso', 'PermisoId', $id, $institucionId);
        if (!$antes) {
            Response::noEncontrado();
        }

        try {
            $this->ejecutar(
                'UPDATE permiso SET Codigo = ?, Nombre = ?, Modulo = ?, Descripcion = ?, Estado = ?
                  WHERE PermisoId = ? AND InstitucionEducativaId = ?',
                [$datos['codigo'], $datos['nombre'], $datos['modulo'], $datos['descripcion'], $datos['estado'], $id, $institucionId]
            );
        } catch (PDOException $ex) {
            $this->errorBaseDatos($ex, 'Ya existe un permiso con ese código.');
        }

        $this->auditarActualizacion('permiso', 'PermisoId', $id, $antes, $institucionId);

        Response::exito(['PermisoId' => $id, 'mensaje' => 'Permiso actualizado correctamente.']);
    }

    /** PATCH /api/permisos/{id}/estado */
    public function estadoCambiar(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $registro = $this->consultarUna(
            'SELECT Estado FROM permiso WHERE PermisoId = ? AND InstitucionEducativaId = ?',
            [$id, $institucionId]
        );
        if (!$registro) {
            Response::noEncontrado();
        }

        $nuevo = $this->peticion->texto('estado') !== ''
            ? $this->estado($this->peticion->texto('estado'))
            : $this->estadoInvertido($registro['Estado']);

        $this->ejecutar(
            'UPDATE permiso SET Estado = ? WHERE PermisoId = ? AND InstitucionEducativaId = ?',
            [$nuevo, $id, $institucionId]
        );
        $this->auditarActualizacion('permiso', 'PermisoId', $id, $registro, $institucionId);

        Response::exito(['PermisoId' => $id, 'estado' => $nuevo, 'mensaje' => 'Estado actualizado.']);
    }

    /* ------------------------------------------------------------------ */

    private function validar(): array
    {
        $errores = [];
        $codigo  = $this->peticion->texto('codigo');
        $nombre  = $this->peticion->texto('nombre');
        $modulo  = $this->peticion->texto('modulo');

        if ($codigo === '') $errores[] = 'El código es obligatorio.';
        if ($nombre === '') $errores[] = 'El nombre es obligatorio.';

        if ($errores) {
            Response::validacion($errores);
        }

        return [
            'codigo'      => $codigo,
            'nombre'      => $nombre,
            'modulo'      => in_array($modulo, self::MODULOS, true) ? $modulo : null,
            'descripcion' => $this->peticion->texto('descripcion'),
            'estado'      => $this->estado($this->peticion->texto('estado', 'ACTIVO')),
        ];
    }
}
