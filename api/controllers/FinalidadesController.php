<?php
// api/controllers/FinalidadesController.php
// CRUD del catálogo global de finalidades del tratamiento de datos.
// Nota: la columna de estado en esta tabla se llama 'Activo'.

final class FinalidadesController extends Controller
{
    private const ROLES = ['SuperAdmin'];
    /** Clave en includes/accesos.php: define qué permisos abren este recurso. */
    private const MODULO = 'finalidades';

    /** GET /api/finalidades?q=&pagina=&solo_activas=1 */
    public function index(array $ruta = []): void
    {
        // El listado también lo consumen consultas y reportes, por eso basta
        // con estar autenticado para leer; escribir sigue siendo de SuperAdmin.
        $this->requiereAutenticacion();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'FROM finalidad WHERE (Nombre LIKE ? OR Codigo LIKE ?)';
        $params = [$buscar, $buscar];

        if ($this->peticion->paramEntero('solo_activas') === 1) {
            $where .= " AND Activo = 'ACTIVO'";
        }

        $total = $this->contar("SELECT COUNT(*) total $where", $params);
        [$pagina, $porPagina, $offset] = $this->paginacion(12);

        $datos = $this->consultar("SELECT * $where ORDER BY Nombre LIMIT $offset, $porPagina", $params);

        Response::lista($datos, $total, $pagina, $porPagina);
    }

    /** GET /api/finalidades/{id} */
    public function show(array $ruta): void
    {
        $this->requiereAutenticacion();

        $registro = $this->consultarUna('SELECT * FROM finalidad WHERE FinalidadId = ?', [(int)$ruta['id']]);
        if (!$registro) {
            Response::noEncontrado();
        }
        Response::exito($registro);
    }

    /** POST /api/finalidades */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $datos = $this->validar();

        try {
            $this->ejecutar(
                'INSERT INTO finalidad (Codigo, Nombre, Descripcion, Activo) VALUES (?,?,?,?)',
                [$datos['codigo'], $datos['nombre'], $datos['descripcion'], $datos['activo']]
            );
        } catch (PDOException $ex) {
            $this->errorBaseDatos($ex, 'Ya existe una finalidad con ese código.');
        }

        $id = (int)$this->db->lastInsertId();
        $this->auditarInsercion('finalidad', 'FinalidadId', $id);

        Response::exito(
            ['FinalidadId' => $id, 'mensaje' => 'Finalidad registrada correctamente.'],
            [],
            201
        );
    }

    /** PUT /api/finalidades/{id} */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $id    = (int)$ruta['id'];
        $datos = $this->validar();

        $antes = $this->filaAuditable('finalidad', 'FinalidadId', $id);
        if (!$antes) {
            Response::noEncontrado();
        }

        try {
            $this->ejecutar(
                'UPDATE finalidad SET Codigo = ?, Nombre = ?, Descripcion = ?, Activo = ? WHERE FinalidadId = ?',
                [$datos['codigo'], $datos['nombre'], $datos['descripcion'], $datos['activo'], $id]
            );
        } catch (PDOException $ex) {
            $this->errorBaseDatos($ex, 'Ya existe una finalidad con ese código.');
        }

        $this->auditarActualizacion('finalidad', 'FinalidadId', $id, $antes);

        Response::exito(['FinalidadId' => $id, 'mensaje' => 'Finalidad actualizada correctamente.']);
    }

    /** PATCH /api/finalidades/{id}/estado */
    public function estadoCambiar(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $id = (int)$ruta['id'];

        $registro = $this->consultarUna('SELECT Activo FROM finalidad WHERE FinalidadId = ?', [$id]);
        if (!$registro) {
            Response::noEncontrado();
        }

        $nuevo = $this->peticion->texto('estado') !== ''
            ? $this->estado($this->peticion->texto('estado'))
            : $this->estadoInvertido($registro['Activo']);

        $this->ejecutar('UPDATE finalidad SET Activo = ? WHERE FinalidadId = ?', [$nuevo, $id]);
        $this->auditarActualizacion('finalidad', 'FinalidadId', $id, $registro);

        Response::exito(['FinalidadId' => $id, 'estado' => $nuevo, 'mensaje' => 'Estado actualizado.']);
    }

    /* ------------------------------------------------------------------ */

    private function validar(): array
    {
        $errores = [];
        $codigo  = $this->peticion->texto('codigo');
        $nombre  = $this->peticion->texto('nombre');

        if ($codigo === '') $errores[] = 'El código es obligatorio.';
        if ($nombre === '') $errores[] = 'El nombre es obligatorio.';

        if ($errores) {
            Response::validacion($errores);
        }

        return [
            'codigo'      => $codigo,
            'nombre'      => $nombre,
            'descripcion' => $this->peticion->texto('descripcion'),
            'activo'      => $this->estado($this->peticion->texto('activo', 'ACTIVO')),
        ];
    }
}
