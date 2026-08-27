<?php
// api/controllers/ConsultasController.php
// Consultas transversales: búsqueda de personas, bitácora de auditoría y
// consentimientos vigentes / revocados.

final class ConsultasController extends Controller
{
    /**
     * GET /api/consultas/buscar-persona?q=&id=
     * Devuelve los resultados de la búsqueda y, si corresponde, la ficha 360°
     * de la persona seleccionada (o de la única coincidencia encontrada).
     */
    public function buscarPersona(array $ruta = []): void
    {
        $this->requiereAcceso('consulta_buscar_persona');
        $institucionId = $this->institucion();

        $buscar    = $this->peticion->paramTexto('q');
        $personaId = $this->peticion->paramEntero('id');
        $resultados = [];

        if ($buscar !== '') {
            $like = $this->like($buscar);
            $resultados = $this->consultar(
                'SELECT * FROM persona
                  WHERE InstitucionEducativaId = ?
                    AND (Nombres LIKE ? OR Apellidos LIKE ? OR Identificacion LIKE ? OR Email LIKE ?)
               ORDER BY Apellidos, Nombres
                  LIMIT 30',
                [$institucionId, $like, $like, $like, $like]
            );

            if (count($resultados) === 1 && $personaId === 0) {
                $personaId = (int)$resultados[0]['PersonaId'];
            }
        }

        $ficha = null;
        if ($personaId > 0) {
            $persona = $this->consultarUna(
                'SELECT * FROM persona WHERE PersonaId = ? AND InstitucionEducativaId = ?',
                [$personaId, $institucionId]
            );
            if ($persona) {
                $ficha = [
                    'persona'    => $persona,
                    'empleado'   => $this->consultarUna(
                        'SELECT * FROM empleado WHERE PersonaId = ? AND InstitucionEducativaId = ?',
                        [$personaId, $institucionId]
                    ),
                    'estudiante' => $this->consultarUna(
                        'SELECT * FROM estudiante WHERE PersonaId = ? AND InstitucionEducativaId = ?',
                        [$personaId, $institucionId]
                    ),
                    'proveedor'  => $this->consultarUna(
                        'SELECT * FROM proveedor WHERE PersonaId = ? AND InstitucionEducativaId = ?',
                        [$personaId, $institucionId]
                    ),
                    'usuario'    => $this->consultarUna(
                        'SELECT UsuarioId, Username, Email, UltimoAcceso, Estado
                           FROM usuario WHERE PersonaId = ? AND InstitucionEducativaId = ?',
                        [$personaId, $institucionId]
                    ),
                    'consentimientos' => $this->consultar(
                        'SELECT c.*, f.Nombre AS FinalidadNombre
                           FROM consentimiento c
                      LEFT JOIN finalidad f ON f.FinalidadId = c.FinalidadId
                          WHERE c.PersonaId = ? AND c.InstitucionEducativaId = ?
                       ORDER BY c.FechaConsentimiento DESC',
                        [$personaId, $institucionId]
                    ),
                ];
            }
        }

        Response::exito([
            'resultados'         => $resultados,
            'persona_id'         => $personaId,
            'ficha'              => $ficha,
        ]);
    }

    /**
     * GET /api/consultas/historial?consentimiento_id=&desde=&hasta=&q=&pagina=
     * Bitácora de auditoría de consentimientos.
     */
    public function historial(array $ruta = []): void
    {
        $this->requiereAcceso('consulta_historial');
        $institucionId = $this->institucion();

        $where  = 'WHERE h.InstitucionEducativaId = ?';
        $params = [$institucionId];

        $consentimientoId = $this->peticion->paramEntero('consentimiento_id');
        if ($consentimientoId > 0) {
            $where   .= ' AND h.ConsentimientoId = ?';
            $params[] = $consentimientoId;
        }

        $desde = $this->peticion->paramTexto('desde');
        if ($desde !== '') {
            $where   .= ' AND h.FechaAccion >= ?';
            $params[] = $desde . ' 00:00:00';
        }

        $hasta = $this->peticion->paramTexto('hasta');
        if ($hasta !== '') {
            $where   .= ' AND h.FechaAccion <= ?';
            $params[] = $hasta . ' 23:59:59';
        }

        $buscar = $this->peticion->paramTexto('q');
        if ($buscar !== '') {
            $like = $this->like($buscar);
            $where .= ' AND (p.Nombres LIKE ? OR p.Apellidos LIKE ? OR p.Identificacion LIKE ? OR p.Email LIKE ? OR u.Username LIKE ? OR u.Email LIKE ? OR CAST(h.ConsentimientoId AS CHAR) LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }

        $desdeSql = 'FROM consentimientohistorial h
                LEFT JOIN consentimiento c ON c.ConsentimientoId = h.ConsentimientoId
                                          AND c.InstitucionEducativaId = h.InstitucionEducativaId
                LEFT JOIN persona p        ON p.PersonaId = c.PersonaId
                LEFT JOIN usuario u        ON u.UsuarioId = h.UsuarioId
                                          AND u.InstitucionEducativaId = h.InstitucionEducativaId';

        $total = $this->contar("SELECT COUNT(*) total $desdeSql $where", $params);
        [$pagina, $porPagina, $offset] = $this->paginacion(20);

        $datos = $this->consultar(
            "SELECT h.*, p.Nombres, p.Apellidos, u.Username, f.Nombre AS FinalidadNombre
             $desdeSql
             LEFT JOIN finalidad f ON f.FinalidadId = c.FinalidadId
             $where
             ORDER BY h.FechaAccion DESC
             LIMIT $offset, $porPagina",
            $params
        );

        Response::lista($datos, $total, $pagina, $porPagina);
    }

    /**
     * GET /api/consultas/consentimientos-vigentes?estado=&finalidad_id=&tipo_dato_id=&q=&pagina=
     */
    public function consentimientosVigentes(array $ruta = []): void
    {
        $this->requiereAcceso('consulta_vigentes');
        $institucionId = $this->institucion();

        $where  = 'WHERE c.InstitucionEducativaId = ?';
        $params = [$institucionId];

        $estadoFiltro = strtoupper($this->peticion->paramTexto('estado', 'ACTIVO'));
        if (in_array($estadoFiltro, ['ACTIVO', 'INACTIVO'], true)) {
            $where   .= ' AND c.Estado = ?';
            $params[] = $estadoFiltro;
        }

        $finalidadId = $this->peticion->paramEntero('finalidad_id');
        if ($finalidadId > 0) {
            $where   .= ' AND c.FinalidadId = ?';
            $params[] = $finalidadId;
        }

        $buscar = $this->peticion->paramTexto('q');
        if ($buscar !== '') {
            $like = $this->like($buscar);
            $where .= ' AND (p.Nombres LIKE ? OR p.Apellidos LIKE ? OR p.Identificacion LIKE ? OR p.Email LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        // El filtro por tipo de dato agrega un JOIN cuyo parámetro va primero
        $joinTipoDato = '';
        $tipoDatoId   = $this->peticion->paramEntero('tipo_dato_id');
        if ($tipoDatoId > 0) {
            $joinTipoDato = "INNER JOIN consentimientodato cd
                                     ON cd.ConsentimientoId = c.ConsentimientoId
                                    AND cd.InstitucionEducativaId = c.InstitucionEducativaId
                                    AND cd.TipoDatoId = ?
                                    AND cd.Autorizado = 'SI'";
            array_unshift($params, $tipoDatoId);
        }

        $desdeSql = "FROM consentimiento c
                LEFT JOIN persona p   ON p.PersonaId   = c.PersonaId
                LEFT JOIN finalidad f ON f.FinalidadId = c.FinalidadId
                $joinTipoDato
                $where";

        $total = $this->contar("SELECT COUNT(*) total $desdeSql", $params);
        [$pagina, $porPagina, $offset] = $this->paginacion(15);

        $datos = $this->consultar(
            "SELECT c.*, p.Nombres, p.Apellidos, f.Nombre AS FinalidadNombre
             $desdeSql
             ORDER BY c.FechaConsentimiento DESC
             LIMIT $offset, $porPagina",
            $params
        );

        Response::lista($datos, $total, $pagina, $porPagina, [
            'finalidades' => $this->consultar('SELECT FinalidadId, Nombre FROM finalidad ORDER BY Nombre'),
            'tipos_dato'  => $this->consultar('SELECT TipoDatoId, Nombre FROM tipodato ORDER BY Nombre'),
        ]);
    }
}
