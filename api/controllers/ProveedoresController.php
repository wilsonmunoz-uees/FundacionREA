<?php
// api/controllers/ProveedoresController.php
// Mantenimiento de proveedores de bienes, servicios e infraestructura.
//
// Las ALTAS ya no ocurren aquí. Los proveedores entran por «Carga de
// Información» (CargaInformacionController). Este módulo solo consulta y permite
// corregir el correo, el teléfono y el estado; el RUC, la razón social y el
// nombre del contacto se leen de la base y no de la petición.

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

    /**
     * POST /api/proveedores
     *
     * El alta individual está retirada: los proveedores entran por Carga de
     * Información. La ruta se conserva para poder responder con un motivo en
     * vez de un 404.
     */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);

        Response::error(
            'El alta de proveedores se realiza desde la opción «Carga de Información». '
            . 'Desde esta pantalla solo puede actualizar el correo, el teléfono y el estado.',
            403
        );
    }

    /**
     * PUT /api/proveedores/{id}
     *
     * Edición restringida: solo el correo, el teléfono y el estado. El RUC, la
     * razón social y el nombre del contacto son los que trajo la carga y se leen
     * de la base, no de la petición.
     */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $antes = $this->filaAuditable('proveedor', 'ProveedorId', $id, $institucionId);
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
        $errores = Padron::validarContacto($persona, 'el contacto del proveedor');

        if ($errores) {
            Response::validacion($errores);
        }

        $estado = $this->estado($this->peticion->texto('estado', (string)$antes['Estado']));
        $persona['estado'] = $estado;

        try {
            $this->db->beginTransaction();

            Padron::actualizar($this->db, $institucionId, $personaId, $persona);

            $this->ejecutar(
                'UPDATE proveedor SET Estado = ?
                  WHERE ProveedorId = ? AND InstitucionEducativaId = ?',
                [$estado, $id, $institucionId]
            );

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'No se pudo actualizar el proveedor.');
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

}
