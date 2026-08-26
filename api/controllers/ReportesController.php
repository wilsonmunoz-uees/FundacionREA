<?php
// api/controllers/ReportesController.php
// KPIs del panel principal, reportes de cumplimiento y datos para exportación.

final class ReportesController extends Controller
{
    /**
     * Acceso a un reporte concreto (por rol o por su permiso).
     * Las claves están definidas en includes/accesos.php.
     */
    private function autorizarReporte(string $clave = 'reporte_consentimientos'): array
    {
        return $this->requiereAcceso($clave);
    }

    /**
     * GET /api/reportes/dashboard
     * KPIs + actividad reciente del panel principal.
     */
    public function dashboard(array $ruta = []): void
    {
        $this->requiereAutenticacion();
        $institucionId = $this->institucion();

        $kpi = static fn(string $tabla) =>
            "SELECT COUNT(*) total FROM $tabla WHERE InstitucionEducativaId = ? AND Estado = 'ACTIVO'";

        Response::exito([
            'kpis' => [
                'estudiantes'             => $this->contar($kpi('estudiante'), [$institucionId]),
                'empleados'               => $this->contar($kpi('empleado'), [$institucionId]),
                'consentimientos_activos' => $this->contar($kpi('consentimiento'), [$institucionId]),
                'consentimientos_revocados' => $this->contar(
                    "SELECT COUNT(*) total FROM consentimiento WHERE InstitucionEducativaId = ? AND Estado = 'INACTIVO'",
                    [$institucionId]
                ),
                'proveedores'             => $this->contar($kpi('proveedor'), [$institucionId]),
                'usuarios'                => $this->contar($kpi('usuario'), [$institucionId]),
            ],
            'ultimos_consentimientos' => $this->consultar(
                'SELECT c.ConsentimientoId, c.FechaConsentimiento, c.Estado, c.MedioConsentimiento,
                        p.Nombres, p.Apellidos, f.Nombre AS FinalidadNombre
                   FROM consentimiento c
              LEFT JOIN persona p   ON p.PersonaId   = c.PersonaId
              LEFT JOIN finalidad f ON f.FinalidadId = c.FinalidadId
                  WHERE c.InstitucionEducativaId = ?
               ORDER BY c.FechaConsentimiento DESC
                  LIMIT 6',
                [$institucionId]
            ),
            'ultimo_historial' => $this->consultar(
                'SELECT h.FechaAccion, h.Accion, h.EstadoAnterior, h.EstadoNuevo, h.Observacion, u.Username
                   FROM consentimientohistorial h
              LEFT JOIN usuario u ON u.UsuarioId = h.UsuarioId
                                 AND u.InstitucionEducativaId = h.InstitucionEducativaId
                  WHERE h.InstitucionEducativaId = ?
               ORDER BY h.FechaAccion DESC
                  LIMIT 6',
                [$institucionId]
            ),
        ]);
    }

    /**
     * GET /api/reportes/consentimientos
     * Consentimientos por finalidad, por medio y por mes + totales.
     */
    public function consentimientos(array $ruta = []): void
    {
        $this->autorizarReporte('reporte_consentimientos');
        $institucionId = $this->institucion();

        $porFinalidad = $this->consultar(
            "SELECT f.Nombre, COUNT(c.ConsentimientoId) AS total,
                    SUM(CASE WHEN c.Estado = 'ACTIVO'   THEN 1 ELSE 0 END) AS activos,
                    SUM(CASE WHEN c.Estado = 'INACTIVO' THEN 1 ELSE 0 END) AS revocados
               FROM finalidad f
          LEFT JOIN consentimiento c ON c.FinalidadId = f.FinalidadId AND c.InstitucionEducativaId = ?
           GROUP BY f.FinalidadId, f.Nombre
           ORDER BY total DESC",
            [$institucionId]
        );

        $porMedio = $this->consultar(
            "SELECT COALESCE(MedioConsentimiento,'No especificado') AS medio, COUNT(*) AS total
               FROM consentimiento
              WHERE InstitucionEducativaId = ?
           GROUP BY MedioConsentimiento
           ORDER BY total DESC",
            [$institucionId]
        );

        $porMes = $this->consultar(
            "SELECT DATE_FORMAT(FechaConsentimiento, '%Y-%m') AS periodo, COUNT(*) AS total
               FROM consentimiento
              WHERE InstitucionEducativaId = ?
                AND FechaConsentimiento >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
           GROUP BY periodo
           ORDER BY periodo",
            [$institucionId]
        );

        $totales = $this->consultarUna(
            "SELECT COUNT(*) t,
                    SUM(CASE WHEN Estado = 'ACTIVO'   THEN 1 ELSE 0 END) a,
                    SUM(CASE WHEN Estado = 'INACTIVO' THEN 1 ELSE 0 END) r
               FROM consentimiento WHERE InstitucionEducativaId = ?",
            [$institucionId]
        );

        Response::exito([
            'por_finalidad' => $porFinalidad,
            'por_medio'     => $porMedio,
            'por_mes'       => $porMes,
            'totales'       => $totales ?: ['t' => 0, 'a' => 0, 'r' => 0],
            'maximos'       => [
                'finalidad' => $this->maximo($porFinalidad),
                'medio'     => $this->maximo($porMedio),
                'mes'       => $this->maximo($porMes),
            ],
        ]);
    }

    /**
     * GET /api/reportes/datos-sensibles?tipo_dato_id=
     * Resumen y detalle del tratamiento de datos sensibles.
     */
    public function datosSensibles(array $ruta = []): void
    {
        $this->autorizarReporte('reporte_datos_sensibles');
        $institucionId = $this->institucion();

        $resumen = $this->consultar(
            "SELECT td.TipoDatoId, td.Nombre, td.Categoria,
                    SUM(CASE WHEN cd.Autorizado = 'SI' AND c.Estado = 'ACTIVO' THEN 1 ELSE 0 END) AS autorizados_vigentes,
                    SUM(CASE WHEN cd.Autorizado = 'SI' THEN 1 ELSE 0 END) AS autorizados_total
               FROM tipodato td
          LEFT JOIN consentimientodato cd ON cd.TipoDatoId = td.TipoDatoId AND cd.InstitucionEducativaId = ?
          LEFT JOIN consentimiento c      ON c.ConsentimientoId = cd.ConsentimientoId
                                         AND c.InstitucionEducativaId = cd.InstitucionEducativaId
              WHERE td.EsSensible = 'SI'
           GROUP BY td.TipoDatoId, td.Nombre, td.Categoria
           ORDER BY autorizados_vigentes DESC",
            [$institucionId]
        );

        $tipoDatoId = $this->peticion->paramEntero('tipo_dato_id');
        $params     = [$institucionId];
        $filtroTipo = '';
        if ($tipoDatoId > 0) {
            $filtroTipo = ' AND td.TipoDatoId = ?';
            $params[]   = $tipoDatoId;
        }

        $detalle = $this->consultar(
            "SELECT DISTINCT p.PersonaId, p.Nombres, p.Apellidos, td.Nombre AS TipoDatoNombre,
                    f.Nombre AS FinalidadNombre, c.FechaConsentimiento
               FROM consentimientodato cd
         INNER JOIN consentimiento c ON c.ConsentimientoId = cd.ConsentimientoId
                                    AND c.InstitucionEducativaId = cd.InstitucionEducativaId
         INNER JOIN tipodato td      ON td.TipoDatoId = cd.TipoDatoId
         INNER JOIN persona p        ON p.PersonaId = c.PersonaId
          LEFT JOIN finalidad f      ON f.FinalidadId = c.FinalidadId
              WHERE cd.InstitucionEducativaId = ?
                AND cd.Autorizado = 'SI'
                AND c.Estado = 'ACTIVO'
                AND td.EsSensible = 'SI'
                $filtroTipo
           ORDER BY p.Apellidos
              LIMIT 200",
            $params
        );

        Response::exito([
            'resumen'         => $resumen,
            'detalle'         => $detalle,
            'tipos_sensibles' => $this->consultar(
                "SELECT TipoDatoId, Nombre FROM tipodato WHERE EsSensible = 'SI' ORDER BY Nombre"
            ),
            'tipo_dato_id'    => $tipoDatoId,
        ]);
    }

    /**
     * GET /api/reportes/titulares
     * Detalle de titulares que otorgaron o revocaron su consentimiento.
     *
     * Filtros: estado (ACTIVO/INACTIVO), tipo (estudiantes/empleados/proveedores),
     * rango de fechas del consentimiento (desde/hasta) y texto libre.
     */
    public function titulares(array $ruta = []): void
    {
        $this->autorizarReporte('reporte_titulares');
        $institucionId = $this->institucion();

        /* --- Filtros --- */
        $estado = strtoupper($this->peticion->paramTexto('estado'));
        $tipo   = strtolower($this->peticion->paramTexto('tipo', 'todos'));
        $desde  = $this->peticion->paramTexto('desde');
        $hasta  = $this->peticion->paramTexto('hasta');
        $buscar = $this->peticion->paramTexto('q');

        $tiposValidos = ['todos', 'estudiantes', 'empleados', 'proveedores'];
        if (!in_array($tipo, $tiposValidos, true)) {
            $tipo = 'todos';
        }

        /* --- Columnas calculadas: a qué grupos pertenece cada titular --- */
        $seleccion = "SELECT c.ConsentimientoId, c.FechaConsentimiento, c.FechaRevocacion, c.Estado, c.IpOrigen,
                             c.MedioConsentimiento, p.PersonaId, p.Nombres, p.Apellidos, p.Identificacion,
                             f.Nombre AS FinalidadNombre,
                             (SELECT COUNT(*) FROM estudiante es
                               WHERE es.PersonaId = p.PersonaId AND es.InstitucionEducativaId = ?) AS EsEstudiante,
                             (SELECT COUNT(*) FROM empleado em
                               WHERE em.PersonaId = p.PersonaId AND em.InstitucionEducativaId = ?) AS EsEmpleado,
                             (SELECT pr.RazonSocial FROM proveedor pr
                               WHERE pr.PersonaId = p.PersonaId AND pr.InstitucionEducativaId = ? LIMIT 1) AS RazonSocial";

        $paramsSeleccion = [$institucionId, $institucionId, $institucionId];

        $desdeSql = "FROM consentimiento c
               INNER JOIN persona p   ON p.PersonaId   = c.PersonaId
                LEFT JOIN finalidad f ON f.FinalidadId = c.FinalidadId";

        $where  = 'WHERE c.InstitucionEducativaId = ?';
        $params = [$institucionId];

        if (in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
            $where   .= ' AND c.Estado = ?';
            $params[] = $estado;
        }

        if ($tipo !== 'todos') {
            $tabla = ['estudiantes' => 'estudiante', 'empleados' => 'empleado', 'proveedores' => 'proveedor'][$tipo];
            $where   .= " AND EXISTS (SELECT 1 FROM $tabla t
                                       WHERE t.PersonaId = p.PersonaId AND t.InstitucionEducativaId = ?)";
            $params[] = $institucionId;
        }

        if ($desde !== '') {
            $where   .= ' AND c.FechaConsentimiento >= ?';
            $params[] = $desde . ' 00:00:00';
        }
        if ($hasta !== '') {
            $where   .= ' AND c.FechaConsentimiento <= ?';
            $params[] = $hasta . ' 23:59:59';
        }

        if ($buscar !== '') {
            $like = $this->like($buscar);
            $where .= ' AND (p.Nombres LIKE ? OR p.Apellidos LIKE ? OR p.Identificacion LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        /* --- Totales del conjunto filtrado (para el resumen del reporte) --- */
        $totales = $this->consultarUna(
            "SELECT COUNT(*) total,
                    SUM(CASE WHEN c.Estado = 'ACTIVO'   THEN 1 ELSE 0 END) consentidos,
                    SUM(CASE WHEN c.Estado = 'INACTIVO' THEN 1 ELSE 0 END) revocados
             $desdeSql $where",
            $params
        ) ?: ['total' => 0, 'consentidos' => 0, 'revocados' => 0];

        $total = (int)$totales['total'];

        /* --- Página solicitada (el PDF pide todo con por_pagina alto) --- */
        [$pagina, $porPagina, $offset] = $this->paginacion(15);

        $filas = $this->consultar(
            "$seleccion $desdeSql $where ORDER BY c.FechaConsentimiento DESC, p.Apellidos LIMIT $offset, $porPagina",
            array_merge($paramsSeleccion, $params)
        );

        /* --- Etiquetas listas para mostrar --- */
        foreach ($filas as &$fila) {
            $grupos = [];
            if ((int)$fila['EsEstudiante'] > 0)   { $grupos[] = 'Estudiante'; }
            if ((int)$fila['EsEmpleado'] > 0)     { $grupos[] = 'Empleado'; }
            if (!empty($fila['RazonSocial']))     { $grupos[] = 'Proveedor'; }

            $esProveedor = !empty($fila['RazonSocial']);

            $fila['TipoPersona'] = $grupos ? implode(' / ', $grupos) : 'Sin vínculo';
            $fila['Titular']     = $esProveedor
                ? (string)$fila['RazonSocial']
                : trim(($fila['Apellidos'] ?? '') . ' ' . ($fila['Nombres'] ?? ''));
            $fila['EstadoTexto'] = $fila['Estado'] === 'ACTIVO' ? 'CONSENTIDO' : 'REVOCADO';
        }
        unset($fila);

        Response::lista($filas, $total, $pagina, $porPagina, [
            'totales' => [
                'registros'   => $total,
                'consentidos' => (int)$totales['consentidos'],
                'revocados'   => (int)$totales['revocados'],
            ],
            'filtros' => [
                'estado' => $estado,
                'tipo'   => $tipo,
                'desde'  => $desde,
                'hasta'  => $hasta,
                'q'      => $buscar,
            ],
        ]);
    }

    /**
     * GET /api/reportes/auditoria
     * Bitácora de la base de datos: todos los movimientos registrados en la
     * institución del usuario que consulta.
     *
     * Filtros: desde, hasta (rango de fechas), username, tabla, operacion, q.
     */
    /**
     * GET /api/reportes/cobertura-correo
     *
     * Cuántas personas de cada tipo tienen un correo al que enviarles el código
     * de los enlaces con verificación. En estudiantes cuenta el correo del
     * REPRESENTANTE, que es a quien se le escribe.
     *
     * La usa la pantalla de Enlaces de Consentimiento para
     * avisar a quién no alcanzaría ese enlace.
     */
    public function coberturaCorreo(array $ruta = []): void
    {
        $this->requiereAcceso('enlaces_verificados');
        $institucionId = $this->institucion();

        $conCorreo = "TRIM(COALESCE(%s, '')) <> '' AND %s LIKE '%%@%%'";

        $resumen = [];

        foreach ([
            'EMPLEADO'  => ['empleado',  'e', 'p.Email'],
            'PROVEEDOR' => ['proveedor', 'v', 'p.Email'],
        ] as $tipo => [$tabla, $alias, $campo]) {
            $filtro = sprintf($conCorreo, $campo, $campo);

            $total = $this->contar(
                "SELECT COUNT(*) total FROM `$tabla` $alias
            INNER JOIN persona p ON p.PersonaId = $alias.PersonaId
                 WHERE $alias.InstitucionEducativaId = ? AND $alias.Estado = 'ACTIVO'",
                [$institucionId]
            );
            $con = $this->contar(
                "SELECT COUNT(*) total FROM `$tabla` $alias
            INNER JOIN persona p ON p.PersonaId = $alias.PersonaId
                 WHERE $alias.InstitucionEducativaId = ? AND $alias.Estado = 'ACTIVO' AND $filtro",
                [$institucionId]
            );

            $resumen[$tipo] = ['total' => $total, 'con_correo' => $con, 'sin_correo' => $total - $con];
        }

        // Estudiantes: el correo que cuenta es el del representante
        $total = $this->contar(
            "SELECT COUNT(*) total FROM estudiante e
              WHERE e.InstitucionEducativaId = ? AND e.Estado = 'ACTIVO'",
            [$institucionId]
        );
        $con = $this->contar(
            "SELECT COUNT(*) total FROM estudiante e
         INNER JOIN persona r ON r.PersonaId = e.RepresentanteId
              WHERE e.InstitucionEducativaId = ? AND e.Estado = 'ACTIVO'
                AND TRIM(COALESCE(r.Email, '')) <> '' AND r.Email LIKE '%@%'",
            [$institucionId]
        );
        $resumen['ESTUDIANTE'] = ['total' => $total, 'con_correo' => $con, 'sin_correo' => $total - $con];

        Response::exito($resumen);
    }

    public function auditoria(array $ruta = []): void
    {
        $this->autorizarReporte('reporte_auditoria');
        $institucionId = $this->institucion();

        /* --- Filtros --- */
        $desde     = $this->peticion->paramTexto('desde');
        $hasta     = $this->peticion->paramTexto('hasta');
        $username  = $this->peticion->paramTexto('username');
        $tabla     = $this->peticion->paramTexto('tabla');
        $operacion = strtoupper($this->peticion->paramTexto('operacion'));
        $buscar    = $this->peticion->paramTexto('q');

        $desdeSql = 'FROM auditoria a';
        $where    = 'WHERE a.InstitucionEducativaId = ?';
        $params   = [$institucionId];

        if ($desde !== '') {
            $where   .= ' AND a.FechaHora >= ?';
            $params[] = $desde . ' 00:00:00';
        }
        if ($hasta !== '') {
            $where   .= ' AND a.FechaHora <= ?';
            $params[] = $hasta . ' 23:59:59';
        }
        if ($username !== '') {
            $where   .= ' AND a.Username = ?';
            $params[] = $username;
        }
        if ($tabla !== '') {
            $where   .= ' AND a.Tabla = ?';
            $params[] = $tabla;
        }
        if (in_array($operacion, ['INSERT', 'UPDATE', 'DELETE'], true)) {
            $where   .= ' AND a.Operacion = ?';
            $params[] = $operacion;
        }
        if ($buscar !== '') {
            $like   = $this->like($buscar);
            $where .= ' AND (a.Campo LIKE ? OR a.ValorAnterior LIKE ? OR a.ValorNuevo LIKE ? OR a.RegistroId LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        try {
            $totales = $this->consultarUna(
                "SELECT COUNT(*) total,
                        SUM(CASE WHEN a.Operacion = 'INSERT' THEN 1 ELSE 0 END) altas,
                        SUM(CASE WHEN a.Operacion = 'UPDATE' THEN 1 ELSE 0 END) cambios,
                        SUM(CASE WHEN a.Operacion = 'DELETE' THEN 1 ELSE 0 END) bajas,
                        COUNT(DISTINCT a.Username) usuarios
                 $desdeSql $where",
                $params
            ) ?: [];

            $total = (int)($totales['total'] ?? 0);

            [$pagina, $porPagina, $offset] = $this->paginacion(15);

            $filas = $this->consultar(
                "SELECT a.AuditoriaId, a.FechaHora, a.Username, a.IpOrigen, a.Tabla, a.RegistroId,
                        a.Operacion, a.Campo, a.ValorAnterior, a.ValorNuevo
                 $desdeSql $where
                 ORDER BY a.FechaHora DESC, a.AuditoriaId DESC
                 LIMIT $offset, $porPagina",
                $params
            );

            // Tablas presentes en la bitácora, para alimentar el filtro
            $tablas = $this->columna(
                'SELECT DISTINCT Tabla FROM auditoria WHERE InstitucionEducativaId = ? ORDER BY Tabla',
                [$institucionId]
            );
        } catch (PDOException $ex) {
            if ($ex->getCode() === '42S02') {
                Response::error(
                    'La bitácora de auditoría todavía no está instalada. '
                    . 'Ejecute el script BaseDatos/01_DDL_estructura.sql.',
                    503
                );
            }
            throw $ex;
        }

        foreach ($filas as &$fila) {
            $fila['OperacionTexto'] = [
                'INSERT' => 'ALTA',
                'UPDATE' => 'CAMBIO',
                'DELETE' => 'BAJA',
            ][$fila['Operacion']] ?? $fila['Operacion'];
        }
        unset($fila);

        Response::lista($filas, $total, $pagina, $porPagina, [
            'totales' => [
                'registros' => $total,
                'altas'     => (int)($totales['altas'] ?? 0),
                'cambios'   => (int)($totales['cambios'] ?? 0),
                'bajas'     => (int)($totales['bajas'] ?? 0),
                'usuarios'  => (int)($totales['usuarios'] ?? 0),
            ],
            'tablas'  => $tablas,
            'filtros' => [
                'desde'     => $desde,
                'hasta'     => $hasta,
                'username'  => $username,
                'tabla'     => $tabla,
                'operacion' => $operacion,
                'q'         => $buscar,
            ],
        ]);
    }

    /**
     * GET /api/reportes/exportar?entidad=personas
     * Devuelve encabezados y filas listas para construir el CSV en la vista.
     */
    public function exportar(array $ruta = []): void
    {
        $this->autorizarReporte('exportar_csv');
        $institucionId = $this->institucion();

        $entidades = [
            'personas'        => 'Personas',
            'empleados'       => 'Empleados',
            'estudiantes'     => 'Estudiantes',
            'proveedores'     => 'Proveedores',
            'consentimientos' => 'Consentimientos',
            'historial'       => 'Historial de Consentimientos',
        ];

        $entidad = $this->peticion->paramTexto('entidad');

        // Sin entidad: se devuelve el catálogo de exportaciones disponibles
        if ($entidad === '') {
            Response::exito(['entidades' => $entidades]);
        }

        if (!isset($entidades[$entidad])) {
            Response::error('La entidad solicitada no está permitida para exportación.', 422);
        }

        [$encabezados, $sql, $params] = $this->definicionExportacion($entidad, $institucionId);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $filas = $stmt->fetchAll(PDO::FETCH_NUM);

        Response::exito([
            'entidad'      => $entidad,
            'titulo'       => $entidades[$entidad],
            'encabezados'  => $encabezados,
            'filas'        => $filas,
            'total'        => count($filas),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /** @return array{0:array,1:string,2:array} */
    private function definicionExportacion(string $entidad, int $institucionId): array
    {
        switch ($entidad) {
            case 'personas':
                return [
                    ['PersonaId', 'TipoIdentificacion', 'Identificacion', 'Nombres', 'Apellidos', 'Email', 'Telefono', 'Estado'],
                    'SELECT PersonaId, TipoIdentificacion, Identificacion, Nombres, Apellidos, Email, Telefono, Estado
                       FROM persona
                      WHERE InstitucionEducativaId = ?
                   ORDER BY Apellidos',
                    [$institucionId],
                ];

            case 'empleados':
                return [
                    ['EmpleadoId', 'Nombres', 'Apellidos', 'Identificacion', 'Email', 'Telefono', 'Estado'],
                    'SELECT e.EmpleadoId, p.Nombres, p.Apellidos, p.Identificacion, p.Email, p.Telefono, e.Estado
                       FROM empleado e
                 INNER JOIN persona p ON p.PersonaId = e.PersonaId
                      WHERE e.InstitucionEducativaId = ?
                   ORDER BY p.Apellidos',
                    [$institucionId],
                ];

            case 'estudiantes':
                return [
                    ['EstudianteId', 'CodigoEstudiante', 'Nombres', 'Apellidos', 'Identificacion', 'Representante', 'Estado'],
                    'SELECT es.EstudianteId, es.CodigoEstudiante, p.Nombres, p.Apellidos, p.Identificacion,
                            TRIM(CONCAT(COALESCE(r.Apellidos, \'\'), \' \', COALESCE(r.Nombres, \'\'))) AS Representante,
                            es.Estado
                       FROM estudiante es
                 INNER JOIN persona p ON p.PersonaId = es.PersonaId
                  LEFT JOIN persona r ON r.PersonaId = es.RepresentanteId
                      WHERE es.InstitucionEducativaId = ?
                   ORDER BY p.Apellidos',
                    [$institucionId],
                ];

            case 'proveedores':
                return [
                    ['ProveedorId', 'RazonSocial', 'Ruc', 'Contacto', 'Estado'],
                    "SELECT pr.ProveedorId, pr.RazonSocial, pr.Ruc, CONCAT(p.Nombres,' ',p.Apellidos), pr.Estado
                       FROM proveedor pr
                  LEFT JOIN persona p ON p.PersonaId = pr.PersonaId
                      WHERE pr.InstitucionEducativaId = ?
                   ORDER BY pr.RazonSocial",
                    [$institucionId],
                ];

            case 'consentimientos':
                return [
                    ['ConsentimientoId', 'Titular', 'Finalidad', 'FechaConsentimiento', 'FechaRevocacion', 'Medio', 'VersionPolitica', 'Estado'],
                    "SELECT c.ConsentimientoId, CONCAT(p.Nombres,' ',p.Apellidos), f.Nombre, c.FechaConsentimiento,
                            c.FechaRevocacion, c.MedioConsentimiento, c.VersionPolitica, c.Estado
                       FROM consentimiento c
                  LEFT JOIN persona p   ON p.PersonaId   = c.PersonaId
                  LEFT JOIN finalidad f ON f.FinalidadId = c.FinalidadId
                      WHERE c.InstitucionEducativaId = ?
                   ORDER BY c.FechaConsentimiento DESC",
                    [$institucionId],
                ];

            case 'historial':
            default:
                return [
                    ['HistorialId', 'ConsentimientoId', 'Titular', 'Accion', 'EstadoAnterior', 'EstadoNuevo', 'FechaAccion', 'Usuario', 'Observacion'],
                    "SELECT h.HistorialId, h.ConsentimientoId, CONCAT(p.Nombres,' ',p.Apellidos), h.Accion,
                            h.EstadoAnterior, h.EstadoNuevo, h.FechaAccion, u.Username, h.Observacion
                       FROM consentimientohistorial h
                  LEFT JOIN consentimiento c ON c.ConsentimientoId = h.ConsentimientoId
                                            AND c.InstitucionEducativaId = h.InstitucionEducativaId
                  LEFT JOIN persona p        ON p.PersonaId = c.PersonaId
                  LEFT JOIN usuario u        ON u.UsuarioId = h.UsuarioId
                                            AND u.InstitucionEducativaId = h.InstitucionEducativaId
                      WHERE h.InstitucionEducativaId = ?
                   ORDER BY h.FechaAccion DESC",
                    [$institucionId],
                ];
        }
    }

    /** Valor máximo de la columna 'total' (para dimensionar las barras). */
    private function maximo(array $filas): int
    {
        $maximo = 1;
        foreach ($filas as $fila) {
            $maximo = max($maximo, (int)($fila['total'] ?? 0));
        }
        return $maximo;
    }
}
