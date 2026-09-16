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

        Response::lista($datos, $total, $pagina, $porPagina, [
            'siguiente_id' => $siguienteId,
            /* Los largos viajan a la pantalla en vez de escribirse allí: así el
               formulario no puede quedarse en desacuerdo con lo que acepta el
               servidor, que es quien decide al guardar. */
            'reglas' => [
                'nombre'    => ['maximo' => self::LARGO_NOMBRE],
                'direccion' => ['maximo' => self::LARGO_DIRECCION],
                'telefono'  => Telefono::reglasContacto(),
            ],
        ]);
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

    /** Topes de las columnas de `institucion_educativa`. */
    private const LARGO_NOMBRE    = 100;
    private const LARGO_DIRECCION = 100;

    private function validar(bool $esNuevo): array
    {
        $errores   = [];
        $nombre    = $this->peticion->texto('nombre');
        $direccion = $this->peticion->texto('direccion');
        $telefono  = $this->peticion->texto('telefono');

        if ($nombre === '') {
            $errores[] = 'El nombre es obligatorio.';
        } elseif (mb_strlen($nombre) > self::LARGO_NOMBRE) {
            $errores[] = 'El nombre no puede superar los ' . self::LARGO_NOMBRE . ' caracteres.';
        }

        if ($direccion === '') {
            $errores[] = 'La dirección es obligatoria.';
        } elseif (mb_strlen($direccion) > self::LARGO_DIRECCION) {
            $errores[] = 'La dirección no puede superar los ' . self::LARGO_DIRECCION . ' caracteres.';
        }

        /* El teléfono de la institución NO sigue la regla del teléfono de una
           persona: es la forma de contacto que publica la escuela, y viene con
           extensiones, dos números o la palabra «Central». Ver el comentario de
           api/core/Telefono.php. */
        $errores = array_merge($errores, Telefono::validarContacto($telefono, true));

        if ($errores) {
            Response::validacion($errores);
        }

        return [
            'nombre'    => mb_substr($nombre, 0, self::LARGO_NOMBRE),
            'direccion' => mb_substr($direccion, 0, self::LARGO_DIRECCION),
            'telefono'  => Telefono::normalizarContacto($telefono),
            'estado'    => $this->estado($this->peticion->texto('estado', 'ACTIVO')),
        ];
    }
}
