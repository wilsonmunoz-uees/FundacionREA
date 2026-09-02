<?php
// api/controllers/TiposDatoController.php
// CRUD del catálogo global de tipos de dato personal.

final class TiposDatoController extends Controller
{
    /**
     * Categorías admitidas, las mismas que declara el enum de la columna.
     *
     * PERSONAL  el dato identifica o describe a la persona
     * PUBLICO   consta ya en una fuente de acceso público
     * OPCIONAL  se recoge solo si el titular quiere entregarlo
     */
    public const CATEGORIAS = ['PERSONAL', 'PUBLICO', 'OPCIONAL'];

    /** Con la que se queda un valor que no reconoce: la más protectora. */
    public const CATEGORIA_POR_DEFECTO = 'PERSONAL';

    private const ROLES = ['SuperAdmin'];
    /** Clave en includes/accesos.php: define qué permisos abren este recurso. */
    private const MODULO = 'tipos_dato';

    /** GET /api/tipos-dato?q=&pagina=&solo_sensibles=1 */
    public function index(array $ruta = []): void
    {
        // Lectura para cualquier usuario autenticado (la usan consentimientos,
        // consultas y reportes); la escritura queda restringida a SuperAdmin.
        $this->requiereAutenticacion();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'FROM tipodato WHERE (Nombre LIKE ? OR Codigo LIKE ? OR Categoria LIKE ?)';
        $params = [$buscar, $buscar, $buscar];

        if ($this->peticion->paramEntero('solo_sensibles') === 1) {
            $where .= " AND EsSensible = 'SI'";
        }

        $total = $this->contar("SELECT COUNT(*) total $where", $params);
        [$pagina, $porPagina, $offset] = $this->paginacion(12);

        $datos = $this->consultar("SELECT * $where ORDER BY Nombre LIMIT $offset, $porPagina", $params);

        Response::lista($datos, $total, $pagina, $porPagina, ['categorias' => self::CATEGORIAS]);
    }

    /** GET /api/tipos-dato/{id} */
    public function show(array $ruta): void
    {
        $this->requiereAutenticacion();

        $registro = $this->consultarUna('SELECT * FROM tipodato WHERE TipoDatoId = ?', [(int)$ruta['id']]);
        if (!$registro) {
            Response::noEncontrado();
        }
        Response::exito($registro);
    }

    /** POST /api/tipos-dato */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $datos = $this->validar();

        try {
            $this->ejecutar(
                'INSERT INTO tipodato (Codigo, Nombre, Categoria, EsSensible) VALUES (?,?,?,?)',
                [$datos['codigo'], $datos['nombre'], $datos['categoria'], $datos['es_sensible']]
            );
        } catch (PDOException $ex) {
            $this->errorBaseDatos($ex, 'Ya existe un tipo de dato con ese código.');
        }

        $id = (int)$this->db->lastInsertId();
        $this->auditarInsercion('tipodato', 'TipoDatoId', $id);

        Response::exito(
            ['TipoDatoId' => $id, 'mensaje' => 'Tipo de dato registrado correctamente.'],
            [],
            201
        );
    }

    /** PUT /api/tipos-dato/{id} */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $id    = (int)$ruta['id'];
        $datos = $this->validar();

        $antes = $this->filaAuditable('tipodato', 'TipoDatoId', $id);
        if (!$antes) {
            Response::noEncontrado();
        }

        try {
            $this->ejecutar(
                'UPDATE tipodato SET Codigo = ?, Nombre = ?, Categoria = ?, EsSensible = ? WHERE TipoDatoId = ?',
                [$datos['codigo'], $datos['nombre'], $datos['categoria'], $datos['es_sensible'], $id]
            );
        } catch (PDOException $ex) {
            $this->errorBaseDatos($ex, 'Ya existe un tipo de dato con ese código.');
        }

        $this->auditarActualizacion('tipodato', 'TipoDatoId', $id, $antes);

        Response::exito(['TipoDatoId' => $id, 'mensaje' => 'Tipo de dato actualizado correctamente.']);
    }

    /** DELETE /api/tipos-dato/{id} */
    public function destroy(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $id = (int)$ruta['id'];

        $antes = $this->filaAuditable('tipodato', 'TipoDatoId', $id);
        if (!$antes) {
            Response::noEncontrado();
        }

        try {
            $this->ejecutar('DELETE FROM tipodato WHERE TipoDatoId = ?', [$id]);
        } catch (PDOException $ex) {
            Response::error('No se puede eliminar: el tipo de dato está en uso en consentimientos.', 409);
        }

        $this->auditarEliminacion('tipodato', $id, $antes);

        Response::exito(['TipoDatoId' => $id, 'mensaje' => 'Tipo de dato eliminado.']);
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
            'categoria'   => $this->categoria($this->peticion->texto('categoria')),
            'es_sensible' => strtoupper($this->peticion->texto('es_sensible', 'NO')) === 'SI' ? 'SI' : 'NO',
        ];
    }

    /**
     * Deja la categoría en uno de los tres valores que admite la columna.
     *
     * Antes era texto libre y cada quien escribía lo suyo —«Contacto»,
     * «contacto», «Datos de contacto»—, con lo que la clasificación no servía
     * para agrupar nada. Ante algo que no reconoce se queda con PERSONAL: si no
     * consta que un dato sea público, se trata como personal.
     */
    private function categoria(string $valor): string
    {
        $valor = mb_strtoupper(trim($valor));

        return in_array($valor, self::CATEGORIAS, true) ? $valor : self::CATEGORIA_POR_DEFECTO;
    }
}
