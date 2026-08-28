<?php
// api/controllers/ProveedoresController.php
// CRUD de proveedores de bienes, servicios e infraestructura.

final class ProveedoresController extends Controller
{
    private const ROLES = ['SuperAdmin'];
    /** Clave en includes/accesos.php: define qué permisos abren este recurso. */
    private const MODULO = 'proveedores';

    /** GET /api/proveedores?q=&pagina= */
    public function index(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'WHERE pr.InstitucionEducativaId = ? AND (pr.RazonSocial LIKE ? OR pr.Ruc LIKE ?)';
        $params = [$institucionId, $buscar, $buscar];

        $total = $this->contar("SELECT COUNT(*) total FROM proveedor pr $where", $params);
        [$pagina, $porPagina, $offset] = $this->paginacion(12);

        $datos = $this->consultar(
            "SELECT pr.*, p.Nombres, p.Apellidos
               FROM proveedor pr
          LEFT JOIN persona p ON p.PersonaId = pr.PersonaId
             $where
           ORDER BY pr.RazonSocial
              LIMIT $offset, $porPagina",
            $params
        );

        Response::lista($datos, $total, $pagina, $porPagina);
    }

    /** GET /api/proveedores/{id} */
    public function show(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);

        $registro = $this->consultarUna(
            'SELECT pr.*, p.Nombres, p.Apellidos, p.Identificacion, p.TipoIdentificacion,
                    p.Email, p.Telefono
               FROM proveedor pr
          LEFT JOIN persona p ON p.PersonaId = pr.PersonaId
              WHERE pr.ProveedorId = ? AND pr.InstitucionEducativaId = ?',
            [(int)$ruta['id'], $this->institucion()]
        );

        if (!$registro) {
            Response::noEncontrado();
        }
        Response::exito($registro);
    }

    /** POST /api/proveedores */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $datos = $this->validar();

        $yaEsProveedor = $this->consultarUna(
            'SELECT pr.ProveedorId FROM proveedor pr
       INNER JOIN persona p ON p.PersonaId = pr.PersonaId
            WHERE pr.InstitucionEducativaId = ? AND p.Identificacion = ?',
            [$institucionId, $datos['persona']['identificacion']]
        );
        if ($yaEsProveedor) {
            Response::error('Ya existe un proveedor registrado con esa identificación.', 409);
        }

        try {
            $this->db->beginTransaction();

            $personaId = Padron::crearOActualizar($this->db, $institucionId, $datos['persona']);

            $this->ejecutar(
                'INSERT INTO proveedor (InstitucionEducativaId, PersonaId, Ruc, RazonSocial, Estado) VALUES (?,?,?,?,?)',
                [$institucionId, $personaId, $datos['ruc'], $datos['razon_social'], $datos['estado']]
            );
            $id = (int)$this->db->lastInsertId();

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'Ya existe un proveedor con esos datos.');
            return;
        }

        $this->auditarInsercion('persona',   'PersonaId',   $personaId, $institucionId);
        $this->auditarInsercion('proveedor', 'ProveedorId', $id, $institucionId);

        Response::exito(
            ['ProveedorId' => $id, 'mensaje' => 'Proveedor registrado correctamente.'],
            [],
            201
        );
    }

    /** PUT /api/proveedores/{id} */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id    = (int)$ruta['id'];
        $datos = $this->validar();

        $antes = $this->filaAuditable('proveedor', 'ProveedorId', $id, $institucionId);
        if (!$antes) {
            Response::noEncontrado();
        }

        $personaId    = (int)$antes['PersonaId'];
        $antesPersona = $this->filaAuditable('persona', 'PersonaId', $personaId, $institucionId);

        if (Padron::documentoOcupado($this->db, $institucionId, $datos['persona']['identificacion'], $personaId)) {
            Response::error('Otra persona de esta institución ya tiene esa identificación.', 409);
        }

        try {
            $this->db->beginTransaction();

            Padron::actualizar($this->db, $institucionId, $personaId, $datos['persona']);

            $this->ejecutar(
                'UPDATE proveedor SET Ruc = ?, RazonSocial = ?, Estado = ?
                  WHERE ProveedorId = ? AND InstitucionEducativaId = ?',
                [$datos['ruc'], $datos['razon_social'], $datos['estado'], $id, $institucionId]
            );

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'Ya existe un proveedor con esos datos.');
            return;
        }

        $this->auditarActualizacion('persona',   'PersonaId',   $personaId, $antesPersona, $institucionId);
        $this->auditarActualizacion('proveedor', 'ProveedorId', $id, $antes, $institucionId);

        Response::exito(['ProveedorId' => $id, 'mensaje' => 'Proveedor actualizado correctamente.']);
    }

    /** PATCH /api/proveedores/{id}/estado */
    public function estadoCambiar(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $registro = $this->consultarUna(
            'SELECT Estado FROM proveedor WHERE ProveedorId = ? AND InstitucionEducativaId = ?',
            [$id, $institucionId]
        );
        if (!$registro) {
            Response::noEncontrado();
        }

        $nuevo = $this->peticion->texto('estado') !== ''
            ? $this->estado($this->peticion->texto('estado'))
            : $this->estadoInvertido($registro['Estado']);

        $this->ejecutar(
            'UPDATE proveedor SET Estado = ? WHERE ProveedorId = ? AND InstitucionEducativaId = ?',
            [$nuevo, $id, $institucionId]
        );
        $this->auditarActualizacion('proveedor', 'ProveedorId', $id, $registro, $institucionId);

        Response::exito(['ProveedorId' => $id, 'estado' => $nuevo, 'mensaje' => 'Estado actualizado.']);
    }

    /* ------------------------------------------------------------------ */

    private function validar(): array
    {
        $razonSocial = $this->peticion->texto('razon_social');

        /* Datos de la persona de contacto del proveedor.
           El documento se valida contra el contexto 'proveedor' porque además de
           `persona`.`Identificacion` se copia a `proveedor`.`Ruc`, que es más
           corta: manda la columna que recortaría primero. */
        $persona = Padron::normalizar($this->peticion->cuerpo);
        $errores = Documento::validar(
            $persona['tipo'],
            (string)($persona['identificacion_cruda'] ?? $persona['identificacion']),
            'el contacto del proveedor',
            $this->db,
            'proveedor'
        );
        $errores = array_merge($errores, Padron::validar($persona, 'el contacto del proveedor', false, false));

        if ($razonSocial === '') $errores[] = 'La razón social es obligatoria.';

        if ($errores) {
            Response::validacion($errores);
        }

        $estado = $this->estado($this->peticion->texto('estado', 'ACTIVO'));
        $persona['estado'] = $estado;

        // Si no se indica un RUC aparte, se usa la identificación del contacto
        $ruc = $this->peticion->texto('ruc');

        return [
            'persona'      => $persona,
            'ruc'          => $ruc !== '' ? $ruc : $persona['identificacion'],
            'razon_social' => $razonSocial,
            'estado'       => $estado,
        ];
    }
}
