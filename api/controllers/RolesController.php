<?php
// api/controllers/RolesController.php
// CRUD de roles y asignación de permisos (rolpermiso).

final class RolesController extends Controller
{
    private const ROLES = ['SuperAdmin'];
    /** Clave en includes/accesos.php: define qué permisos abren este recurso. */
    private const MODULO = 'roles';

    /** GET /api/roles?q=&pagina= */
    public function index(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'FROM rol WHERE InstitucionEducativaId = ? AND Nombre LIKE ?';
        $params = [$institucionId, $buscar];

        $total = $this->contar("SELECT COUNT(*) total $where", $params);
        [$pagina, $porPagina, $offset] = $this->paginacion(12);

        $datos = $this->consultar("SELECT * $where ORDER BY Nombre LIMIT $offset, $porPagina", $params);

        // Conteo de permisos por rol (para la columna del listado)
        $conteo = [];
        if ($datos) {
            $ids = array_column($datos, 'RolId');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $filas = $this->consultar(
                "SELECT RolId, COUNT(*) AS total FROM rolpermiso
                  WHERE InstitucionEducativaId = ? AND RolId IN ($in) GROUP BY RolId",
                array_merge([$institucionId], $ids)
            );
            foreach ($filas as $fila) {
                $conteo[(int)$fila['RolId']] = (int)$fila['total'];
            }
        }
        foreach ($datos as &$fila) {
            $fila['TotalPermisos'] = $conteo[(int)$fila['RolId']] ?? 0;
        }
        unset($fila);

        Response::lista($datos, $total, $pagina, $porPagina, [
            'permisos_disponibles' => $this->permisosActivos($institucionId),
        ]);
    }

    /** GET /api/roles/{id} — incluye los PermisoId asignados. */
    public function show(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $registro = $this->consultarUna(
            'SELECT * FROM rol WHERE RolId = ? AND InstitucionEducativaId = ?',
            [$id, $institucionId]
        );
        if (!$registro) {
            Response::noEncontrado();
        }

        $registro['permisos_asignados'] = array_map('intval', $this->columna(
            'SELECT PermisoId FROM rolpermiso WHERE RolId = ? AND InstitucionEducativaId = ?',
            [$id, $institucionId]
        ));
        $registro['permisos_disponibles'] = $this->permisosActivos($institucionId);

        Response::exito($registro);
    }

    /** POST /api/roles */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $datos = $this->validar();

        try {
            $this->db->beginTransaction();

            $this->ejecutar(
                'INSERT INTO rol (InstitucionEducativaId, Nombre, Descripcion, Estado) VALUES (?,?,?,?)',
                [$institucionId, $datos['nombre'], $datos['descripcion'], $datos['estado']]
            );
            $rolId = (int)$this->db->lastInsertId();

            $this->sincronizarPermisos($institucionId, $rolId, $datos['permisos']);

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'Ya existe un rol con ese nombre.');
        }

        $this->auditarInsercion('rol', 'RolId', $rolId, $institucionId);
        $this->auditarLista(
            'rol', $rolId, 'Permisos',
            [], $this->codigosPermisos($institucionId, $datos['permisos'])
        );

        Response::exito(['RolId' => $rolId, 'mensaje' => 'Rol registrado correctamente.'], [], 201);
    }

    /** PUT /api/roles/{id} */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id    = (int)$ruta['id'];
        $datos = $this->validar();

        $antes = $this->filaAuditable('rol', 'RolId', $id, $institucionId);
        if (!$antes) {
            Response::noEncontrado();
        }
        $permisosAntes = $this->codigosPermisos($institucionId, array_map('intval', $this->columna(
            'SELECT PermisoId FROM rolpermiso WHERE RolId = ? AND InstitucionEducativaId = ?',
            [$id, $institucionId]
        )));

        try {
            $this->db->beginTransaction();

            $this->ejecutar(
                'UPDATE rol SET Nombre = ?, Descripcion = ?, Estado = ? WHERE RolId = ? AND InstitucionEducativaId = ?',
                [$datos['nombre'], $datos['descripcion'], $datos['estado'], $id, $institucionId]
            );

            $this->sincronizarPermisos($institucionId, $id, $datos['permisos']);

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'Ya existe un rol con ese nombre.');
        }

        $this->auditarActualizacion('rol', 'RolId', $id, $antes, $institucionId);
        $this->auditarLista(
            'rol', $id, 'Permisos',
            $permisosAntes, $this->codigosPermisos($institucionId, $datos['permisos'])
        );

        Response::exito(['RolId' => $id, 'mensaje' => 'Rol actualizado correctamente.']);
    }

    /** PATCH /api/roles/{id}/estado */
    public function estadoCambiar(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $registro = $this->consultarUna(
            'SELECT Estado FROM rol WHERE RolId = ? AND InstitucionEducativaId = ?',
            [$id, $institucionId]
        );
        if (!$registro) {
            Response::noEncontrado();
        }

        $nuevo = $this->peticion->texto('estado') !== ''
            ? $this->estado($this->peticion->texto('estado'))
            : $this->estadoInvertido($registro['Estado']);

        $this->ejecutar(
            'UPDATE rol SET Estado = ? WHERE RolId = ? AND InstitucionEducativaId = ?',
            [$nuevo, $id, $institucionId]
        );
        $this->auditarActualizacion('rol', 'RolId', $id, $registro, $institucionId);

        Response::exito(['RolId' => $id, 'estado' => $nuevo, 'mensaje' => 'Estado actualizado.']);
    }

    /* ------------------------------------------------------------------ */

    private function permisosActivos(int $institucionId): array
    {
        return $this->consultar(
            "SELECT PermisoId, Codigo, Nombre, Modulo FROM permiso
              WHERE InstitucionEducativaId = ? AND Estado = 'ACTIVO' ORDER BY Modulo, Nombre",
            [$institucionId]
        );
    }

    /** Traduce identificadores de permiso a códigos, para que la auditoría se lea. */
    private function codigosPermisos(int $institucionId, array $permisoIds): array
    {
        $permisoIds = array_values(array_filter(array_map('intval', $permisoIds)));
        if (!$permisoIds) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($permisoIds), '?'));

        return $this->columna(
            "SELECT Codigo FROM permiso
              WHERE InstitucionEducativaId = ? AND PermisoId IN ($marcas) ORDER BY Codigo",
            array_merge([$institucionId], $permisoIds)
        );
    }

    private function sincronizarPermisos(int $institucionId, int $rolId, array $permisos): void
    {
        $this->ejecutar(
            'DELETE FROM rolpermiso WHERE RolId = ? AND InstitucionEducativaId = ?',
            [$rolId, $institucionId]
        );

        if (!$permisos) {
            return;
        }

        $stmt = $this->db->prepare('INSERT INTO rolpermiso (InstitucionEducativaId, RolId, PermisoId) VALUES (?,?,?)');
        foreach ($permisos as $permisoId) {
            $stmt->execute([$institucionId, $rolId, $permisoId]);
        }
    }

    private function validar(): array
    {
        $nombre = $this->peticion->texto('nombre');
        if ($nombre === '') {
            Response::validacion(['El nombre del rol es obligatorio.']);
        }

        return [
            'nombre'      => $nombre,
            'descripcion' => $this->peticion->texto('descripcion'),
            'estado'      => $this->estado($this->peticion->texto('estado', 'ACTIVO')),
            'permisos'    => $this->peticion->arregloEnteros('permisos'),
        ];
    }
}
