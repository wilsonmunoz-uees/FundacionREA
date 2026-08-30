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
    }    /**
     * GET /api/reportes/cobertura
     * Reporte de cobertura y brecha de consentimientos (firmados vs pendientes).
     *
     * Filtros: tipo (todos/estudiantes/empleados/proveedores),
     *          estado_cobertura (todos/PENDIENTE/CONSENTIDO/REVOCADO),
     *          q (búsqueda libre).
     */
    public function cobertura(array $ruta = []): void
    {
        $this->autorizarReporte('reporte_cobertura');
        $institucionId = $this->institucion();

        /* --- Parámetros de filtro --- */
        $tipo            = strtolower($this->peticion->paramTexto('tipo', 'todos'));
        $estadoCobertura = strtoupper($this->peticion->paramTexto('estado_cobertura', 'PENDIENTE'));
        $versionFiltro   = trim((string)$this->peticion->paramTexto('version', 'todas'));
        $buscar          = $this->peticion->paramTexto('q');

        $tiposValidos = ['todos', 'estudiantes', 'empleados', 'proveedores'];
        if (!in_array($tipo, $tiposValidos, true)) {
            $tipo = 'todos';
        }

        $estadosValidos = ['TODOS', 'PENDIENTE', 'CONSENTIDO', 'REVOCADO'];
        if (!in_array($estadoCobertura, $estadosValidos, true)) {
            $estadoCobertura = 'PENDIENTE';
        }

        /* --- Obtener lista de versiones registradas para el filtro --- */
        $versionesDisponibles = $this->columna(
            "SELECT DISTINCT VersionPolitica
               FROM consentimiento
              WHERE InstitucionEducativaId = ?
                AND VersionPolitica IS NOT NULL
                AND VersionPolitica != ''
              ORDER BY VersionPolitica DESC",
            [$institucionId]
        );

        /* --- Filtro base de tipo de vínculo --- */
        $filtroVinculoSql = '';
        if ($tipo === 'estudiantes') {
            $filtroVinculoSql = ' AND es.EstudianteId IS NOT NULL';
        } elseif ($tipo === 'empleados') {
            $filtroVinculoSql = ' AND em.EmpleadoId IS NOT NULL';
        } elseif ($tipo === 'proveedores') {
            $filtroVinculoSql = ' AND pr.ProveedorId IS NOT NULL';
        } else {
            $filtroVinculoSql = ' AND (es.EstudianteId IS NOT NULL OR em.EmpleadoId IS NOT NULL OR pr.ProveedorId IS NOT NULL)';
        }

        /* --- Subconsulta agrupada de consentimientos por persona --- */
        $subConsSql = "LEFT JOIN (
            SELECT PersonaId,
                   COUNT(*) AS TotalConsentimientos,
                   SUM(CASE WHEN Estado = 'ACTIVO'   THEN 1 ELSE 0 END) AS ConsentimientosActivos,
                   SUM(CASE WHEN Estado = 'INACTIVO' THEN 1 ELSE 0 END) AS ConsentimientosRevocados,
                   MAX(CASE WHEN Estado = 'ACTIVO'   THEN FechaConsentimiento ELSE NULL END) AS UltimaFechaConsentimiento,
                   MAX(CASE WHEN Estado = 'INACTIVO' THEN FechaRevocacion     ELSE NULL END) AS UltimaFechaRevocacion,
                   MAX(MedioConsentimiento) AS MedioConsentimiento,
                   MAX(VersionPolitica) AS VersionPolitica
              FROM consentimiento
             WHERE InstitucionEducativaId = ?
          GROUP BY PersonaId
        ) cons ON cons.PersonaId = p.PersonaId";

        /* --- Cálculo de KPIs para la población del tipo seleccionado --- */
        $sqlKpis = "SELECT 
            COUNT(DISTINCT p.PersonaId) AS TotalPoblacion,
            SUM(CASE WHEN COALESCE(cons.ConsentimientosActivos, 0) > 0 THEN 1 ELSE 0 END) AS Consentidos,
            SUM(CASE WHEN COALESCE(cons.ConsentimientosActivos, 0) = 0 AND COALESCE(cons.ConsentimientosRevocados, 0) = 0 THEN 1 ELSE 0 END) AS Pendientes,
            SUM(CASE WHEN COALESCE(cons.ConsentimientosActivos, 0) = 0 AND COALESCE(cons.ConsentimientosRevocados, 0) > 0 THEN 1 ELSE 0 END) AS Revocados
          FROM persona p
          LEFT JOIN estudiante es ON es.PersonaId = p.PersonaId AND es.InstitucionEducativaId = p.InstitucionEducativaId AND es.Estado = 'ACTIVO'
          LEFT JOIN persona r     ON r.PersonaId = es.RepresentanteId AND r.InstitucionEducativaId = p.InstitucionEducativaId
          LEFT JOIN empleado em   ON em.PersonaId = p.PersonaId AND em.InstitucionEducativaId = p.InstitucionEducativaId AND em.Estado = 'ACTIVO'
          LEFT JOIN proveedor pr  ON pr.PersonaId = p.PersonaId AND pr.InstitucionEducativaId = p.InstitucionEducativaId AND pr.Estado = 'ACTIVO'
          $subConsSql
         WHERE p.InstitucionEducativaId = ?
           AND p.Estado = 'ACTIVO'
           $filtroVinculoSql";

        $resKpis = $this->consultarUna($sqlKpis, [$institucionId, $institucionId]) ?: [];
        $totalPob     = (int)($resKpis['TotalPoblacion'] ?? 0);
        $consentidos  = (int)($resKpis['Consentidos'] ?? 0);
        $pendientes   = (int)($resKpis['Pendientes'] ?? 0);
        $revocados    = (int)($resKpis['Revocados'] ?? 0);

        /* --- Construcción de la consulta filtrada para la lista --- */
        $whereSql = "WHERE p.InstitucionEducativaId = ?
                       AND p.Estado = 'ACTIVO'
                       $filtroVinculoSql";
        $paramsLista = [$institucionId, $institucionId];

        if ($estadoCobertura === 'CONSENTIDO') {
            $whereSql .= " AND COALESCE(cons.ConsentimientosActivos, 0) > 0";
        } elseif ($estadoCobertura === 'PENDIENTE') {
            $whereSql .= " AND COALESCE(cons.ConsentimientosActivos, 0) = 0 AND COALESCE(cons.ConsentimientosRevocados, 0) = 0";
        } elseif ($estadoCobertura === 'REVOCADO') {
            $whereSql .= " AND COALESCE(cons.ConsentimientosActivos, 0) = 0 AND COALESCE(cons.ConsentimientosRevocados, 0) > 0";
        }

        if ($versionFiltro !== '' && $versionFiltro !== 'todas') {
            $whereSql .= " AND cons.VersionPolitica = ?";
            $paramsLista[] = $versionFiltro;
        }

        if ($buscar !== '') {
            $like = $this->like($buscar);
            $whereSql .= " AND (
                p.Nombres LIKE ? OR p.Apellidos LIKE ? OR p.Identificacion LIKE ? OR
                es.CodigoEstudiante LIKE ? OR pr.RazonSocial LIKE ? OR pr.Ruc LIKE ? OR
                r.Nombres LIKE ? OR r.Apellidos LIKE ? OR r.Identificacion LIKE ?
            )";
            array_push($paramsLista, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $fromSql = "FROM persona p
          LEFT JOIN estudiante es ON es.PersonaId = p.PersonaId AND es.InstitucionEducativaId = p.InstitucionEducativaId AND es.Estado = 'ACTIVO'
          LEFT JOIN persona r     ON r.PersonaId = es.RepresentanteId AND r.InstitucionEducativaId = p.InstitucionEducativaId
          LEFT JOIN empleado em   ON em.PersonaId = p.PersonaId AND em.InstitucionEducativaId = p.InstitucionEducativaId AND em.Estado = 'ACTIVO'
          LEFT JOIN proveedor pr  ON pr.PersonaId = p.PersonaId AND pr.InstitucionEducativaId = p.InstitucionEducativaId AND pr.Estado = 'ACTIVO'
          $subConsSql";

        $sqlContar = "SELECT COUNT(DISTINCT p.PersonaId) AS total $fromSql $whereSql";
        $totalFiltrado = $this->contar($sqlContar, $paramsLista);

        [$pagina, $porPagina, $offset] = $this->paginacion(15);

        $sqlSelect = "SELECT 
            p.PersonaId, p.TipoIdentificacion, p.Identificacion, p.Nombres, p.Apellidos,
            p.Email AS PersonaEmail, p.Telefono AS PersonaTelefono,
            es.EstudianteId, es.CodigoEstudiante, es.RepresentanteId, es.RepresentanteRelacion,
            r.Nombres AS RepNombres, r.Apellidos AS RepApellidos, r.Email AS RepEmail,
            r.Telefono AS RepTelefono, r.Identificacion AS RepIdentificacion,
            em.EmpleadoId,
            pr.ProveedorId, pr.RazonSocial, pr.Ruc,
            COALESCE(cons.TotalConsentimientos, 0) AS TotalConsentimientos,
            COALESCE(cons.ConsentimientosActivos, 0) AS ConsentimientosActivos,
            COALESCE(cons.ConsentimientosRevocados, 0) AS ConsentimientosRevocados,
            cons.UltimaFechaConsentimiento,
            cons.UltimaFechaRevocacion,
            cons.MedioConsentimiento,
            cons.VersionPolitica,
            CASE 
                WHEN COALESCE(cons.ConsentimientosActivos, 0) > 0 THEN 'CONSENTIDO'
                WHEN COALESCE(cons.ConsentimientosRevocados, 0) > 0 THEN 'REVOCADO'
                ELSE 'PENDIENTE'
            END AS EstadoCobertura
          $fromSql
          $whereSql
          ORDER BY 
            (CASE WHEN COALESCE(cons.ConsentimientosActivos, 0) > 0 THEN 2 ELSE 1 END) ASC,
            p.Apellidos ASC, p.Nombres ASC
          LIMIT $offset, $porPagina";

        $filas = $this->consultar($sqlSelect, $paramsLista);

        foreach ($filas as &$f) {
            $esProveedor  = !empty($f['ProveedorId']);
            $esEstudiante = !empty($f['EstudianteId']);
            $esEmpleado   = !empty($f['EmpleadoId']);

            $roles = [];
            if ($esEstudiante) { $roles[] = 'Estudiante'; }
            if ($esEmpleado)   { $roles[] = 'Empleado'; }
            if ($esProveedor)  { $roles[] = 'Proveedor'; }
            $f['TipoPersona'] = $roles ? implode(' / ', $roles) : 'Persona';

            $f['Titular'] = $esProveedor && !empty($f['RazonSocial'])
                ? (string)$f['RazonSocial']
                : trim(($f['Apellidos'] ?? '') . ' ' . ($f['Nombres'] ?? ''));

            $f['Documento'] = $esProveedor && !empty($f['Ruc'])
                ? (string)$f['Ruc']
                : ($f['Identificacion'] ?: '—');

            // Representante / Contacto
            if ($esEstudiante && !empty($f['RepNombres'])) {
                $rel = !empty($f['RepresentanteRelacion']) ? ' (' . $f['RepresentanteRelacion'] . ')' : '';
                $f['Representante'] = trim(($f['RepApellidos'] ?? '') . ' ' . ($f['RepNombres'] ?? '')) . $rel;
            } elseif ($esProveedor) {
                $f['Representante'] = trim(($f['Apellidos'] ?? '') . ' ' . ($f['Nombres'] ?? '')) . ' (Contacto)';
            } else {
                $f['Representante'] = '—';
            }

            // Datos de contacto preferidos
            if ($esEstudiante) {
                $f['EmailContacto']    = $f['RepEmail'] ?: ($f['PersonaEmail'] ?: '—');
                $f['TelefonoContacto'] = $f['RepTelefono'] ?: ($f['PersonaTelefono'] ?: '—');
            } else {
                $f['EmailContacto']    = $f['PersonaEmail'] ?: '—';
                $f['TelefonoContacto'] = $f['PersonaTelefono'] ?: '—';
            }
        }
        unset($f);

        Response::lista($filas, $totalFiltrado, $pagina, $porPagina, [
            'kpis' => [
                'total'          => $totalPob,
                'consentidos'    => $consentidos,
                'pendientes'     => $pendientes,
                'revocados'      => $revocados,
                'pct_cobertura'  => $totalPob > 0 ? round(($consentidos / $totalPob) * 100, 1) : 0.0,
                'pct_pendientes' => $totalPob > 0 ? round(($pendientes / $totalPob) * 100, 1) : 0.0,
                'pct_revocados'  => $totalPob > 0 ? round(($revocados / $totalPob) * 100, 1) : 0.0,
            ],
            'versiones' => $versionesDisponibles,
            'filtros' => [
                'tipo'             => $tipo,
                'estado_cobertura' => $estadoCobertura,
                'version'          => $versionFiltro,
                'q'                => $buscar,
            ],
        ]);
    }

    /**
     * GET /api/reportes/consentimientos
     * Consentimientos por finalidad, por medio y por mes + totales.
     * Soportar filtros por fechas (desde/hasta), tipo de titular y medio.
     */
    public function consentimientos(array $ruta = []): void
    {
        $this->autorizarReporte('reporte_consentimientos');
        $institucionId = $this->institucion();

        // 1. Población activa y contactabilidad
        $sqlPob = "SELECT
            COUNT(DISTINCT p.PersonaId) AS total_poblacion,
            COUNT(DISTINCT CASE WHEN p.Email IS NOT NULL AND TRIM(p.Email) != '' THEN p.PersonaId ELSE NULL END) AS con_correo,
            COUNT(DISTINCT CASE WHEN c.Estado = 'ACTIVO' THEN p.PersonaId ELSE NULL END) AS titulares_consentidos,
            COUNT(DISTINCT CASE WHEN c.Estado = 'INACTIVO' THEN p.PersonaId ELSE NULL END) AS titulares_revocados
          FROM persona p
          LEFT JOIN consentimiento c ON c.PersonaId = p.PersonaId AND c.InstitucionEducativaId = p.InstitucionEducativaId
          WHERE p.InstitucionEducativaId = ?
            AND (
                EXISTS (SELECT 1 FROM estudiante es WHERE es.PersonaId = p.PersonaId AND es.InstitucionEducativaId = p.InstitucionEducativaId AND es.Estado = 'ACTIVO')
                OR EXISTS (SELECT 1 FROM empleado em WHERE em.PersonaId = p.PersonaId AND em.InstitucionEducativaId = p.InstitucionEducativaId AND em.Estado = 'ACTIVO')
                OR EXISTS (SELECT 1 FROM proveedor pr WHERE pr.PersonaId = p.PersonaId AND pr.InstitucionEducativaId = p.InstitucionEducativaId AND pr.Estado = 'ACTIVO')
            )";
        $pobData = $this->consultarUna($sqlPob, [$institucionId]) ?: [];
        $totalPob  = (int)($pobData['total_poblacion'] ?? 0);
        $conCorreo = (int)($pobData['con_correo'] ?? 0);
        $titCons   = (int)($pobData['titulares_consentidos'] ?? 0);
        $titRev    = (int)($pobData['titulares_revocados'] ?? 0);
        $titPend   = max(0, $totalPob - $titCons);
        $pctCob    = $totalPob > 0 ? round(($titCons / $totalPob) * 100, 1) : 0.0;
        $pctPend   = $totalPob > 0 ? round(($titPend / $totalPob) * 100, 1) : 0.0;
        $pctCorreo = $totalPob > 0 ? round(($conCorreo / $totalPob) * 100, 1) : 0.0;
        $pctRev    = $totalPob > 0 ? round(($titRev / $totalPob) * 100, 1) : 0.0;

        // 2. Desglose por tipo de titular
        $tiposDef = [
            'ESTUDIANTE' => ['tabla' => 'estudiante', 'etiqueta' => 'Estudiantes'],
            'EMPLEADO'   => ['tabla' => 'empleado',   'etiqueta' => 'Empleados / Docentes'],
            'PROVEEDOR'  => ['tabla' => 'proveedor',  'etiqueta' => 'Proveedores'],
        ];
        $porTipo = [];
        foreach ($tiposDef as $tipoKey => $cfg) {
            $tabla = $cfg['tabla'];
            $sqlTipo = "SELECT
                COUNT(DISTINCT p.PersonaId) AS poblacion,
                COUNT(DISTINCT CASE WHEN p.Email IS NOT NULL AND TRIM(p.Email) != '' THEN p.PersonaId ELSE NULL END) AS con_correo,
                COUNT(DISTINCT CASE WHEN c.Estado = 'ACTIVO' THEN p.PersonaId ELSE NULL END) AS consentidos,
                COUNT(DISTINCT CASE WHEN c.Estado = 'INACTIVO' THEN p.PersonaId ELSE NULL END) AS revocados
              FROM $tabla t
              JOIN persona p ON p.PersonaId = t.PersonaId AND p.InstitucionEducativaId = t.InstitucionEducativaId
              LEFT JOIN consentimiento c ON c.PersonaId = p.PersonaId AND c.InstitucionEducativaId = p.InstitucionEducativaId
              WHERE t.InstitucionEducativaId = ? AND t.Estado = 'ACTIVO'";
            $rowT = $this->consultarUna($sqlTipo, [$institucionId]) ?: [];
            $pobT = (int)($rowT['poblacion'] ?? 0);
            $ccT  = (int)($rowT['con_correo'] ?? 0);
            $conT = (int)($rowT['consentidos'] ?? 0);
            $revT = (int)($rowT['revocados'] ?? 0);
            $penT = max(0, $pobT - $conT);
            $porTipo[$tipoKey] = [
                'tipo'             => $tipoKey,
                'etiqueta'         => $cfg['etiqueta'],
                'poblacion'        => $pobT,
                'con_correo'       => $ccT,
                'pct_correo'       => $pobT > 0 ? round(($ccT / $pobT) * 100, 1) : 0.0,
                'consentidos'      => $conT,
                'pendientes'       => $penT,
                'revocados'        => $revT,
                'pct_cumplimiento' => $pobT > 0 ? round(($conT / $pobT) * 100, 1) : 0.0,
            ];
        }

        // 3. Totales de consentimientos y retención
        $totales = $this->consultarUna(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN c.Estado = 'ACTIVO'   THEN 1 ELSE 0 END) AS activos,
                    SUM(CASE WHEN c.Estado = 'INACTIVO' THEN 1 ELSE 0 END) AS revocados,
                    COUNT(DISTINCT CASE WHEN c.Estado = 'ACTIVO' THEN c.PersonaId END) AS personas_con_activos
               FROM consentimiento c
              WHERE c.InstitucionEducativaId = ?",
            [$institucionId]
        ) ?: [];

        $totCons  = (int)($totales['total'] ?? 0);
        $actCons  = (int)($totales['activos'] ?? 0);
        $revCons  = (int)($totales['revocados'] ?? 0);
        $personasConActivos = (int)($totales['personas_con_activos'] ?? 0);
        $pctRetencion = $totCons > 0 ? round(($actCons / $totCons) * 100, 1) : 0.0;
        $promedioFinalidades = $personasConActivos > 0 ? round($actCons / $personasConActivos, 1) : 0.0;

        // 4. Detalle por finalidad
        $porFinalidad = $this->consultar(
            "SELECT f.FinalidadId, f.Nombre,
                    COUNT(c.ConsentimientoId) AS total,
                    SUM(CASE WHEN c.Estado = 'ACTIVO'   THEN 1 ELSE 0 END) AS activos,
                    SUM(CASE WHEN c.Estado = 'INACTIVO' THEN 1 ELSE 0 END) AS revocados
               FROM finalidad f
          LEFT JOIN consentimiento c ON c.FinalidadId = f.FinalidadId AND c.InstitucionEducativaId = ?
              WHERE f.Activo = 'ACTIVO'
           GROUP BY f.FinalidadId, f.Nombre
           ORDER BY activos DESC, total DESC, f.Nombre ASC",
            [$institucionId]
        );

        $masAceptada = null;
        $masResistida = null;
        foreach ($porFinalidad as &$fin) {
            $t = (int)$fin['total'];
            $a = (int)$fin['activos'];
            $r = (int)$fin['revocados'];
            $tasa = $t > 0 ? round(($a / $t) * 100, 1) : 0.0;
            $fin['tasa_aceptacion'] = $tasa;

            if ($t > 0) {
                if ($masAceptada === null || $tasa > $masAceptada['tasa_aceptacion']) {
                    $masAceptada = ['Nombre' => $fin['Nombre'], 'tasa_aceptacion' => $tasa, 'activos' => $a];
                }
                if ($masResistida === null || $tasa < $masResistida['tasa_aceptacion']) {
                    $masResistida = ['Nombre' => $fin['Nombre'], 'tasa_aceptacion' => $tasa, 'revocados' => $r];
                }
            }
        }
        unset($fin);

        // 5. Calidad y eficiencia por canal
        $porMedio = $this->consultar(
            "SELECT COALESCE(c.MedioConsentimiento,'WEB') AS medio,
                    COUNT(*) AS total,
                    SUM(CASE WHEN c.Estado = 'ACTIVO'   THEN 1 ELSE 0 END) AS activos,
                    SUM(CASE WHEN c.Estado = 'INACTIVO' THEN 1 ELSE 0 END) AS revocados
               FROM consentimiento c
              WHERE c.InstitucionEducativaId = ?
           GROUP BY c.MedioConsentimiento
           ORDER BY total DESC",
            [$institucionId]
        );

        foreach ($porMedio as &$m) {
            $tM = (int)$m['total'];
            $aM = (int)$m['activos'];
            $rM = (int)$m['revocados'];
            $m['pct_del_total']   = $totCons > 0 ? round(($tM / $totCons) * 100, 1) : 0.0;
            $m['tasa_revocatoria'] = $tM > 0 ? round(($rM / $tM) * 100, 1) : 0.0;
        }
        unset($m);

        // 6. Evolución mensual (12 meses)
        $porMes = $this->consultar(
            "SELECT DATE_FORMAT(c.FechaConsentimiento, '%Y-%m') AS periodo,
                    COUNT(*) AS total,
                    SUM(CASE WHEN c.Estado = 'ACTIVO' THEN 1 ELSE 0 END) AS activos,
                    SUM(CASE WHEN c.Estado = 'INACTIVO' THEN 1 ELSE 0 END) AS revocados
               FROM consentimiento c
              WHERE c.InstitucionEducativaId = ?
                AND c.FechaConsentimiento >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
           GROUP BY periodo
           ORDER BY periodo ASC",
            [$institucionId]
        );

        Response::exito([
            'poblacion' => [
                'total'            => $totalPob,
                'con_correo'       => $conCorreo,
                'pct_con_correo'   => $pctCorreo,
                'consentidos'      => $titCons,
                'pendientes'       => $titPend,
                'revocados'        => $titRev,
                'pct_cobertura'    => $pctCob,
                'pct_pendientes'   => $pctPend,
                'pct_revocados'    => $pctRev,
            ],
            'totales' => [
                't' => $totCons,
                'a' => $actCons,
                'r' => $revCons,
                'pct_retencion'        => $pctRetencion,
                'promedio_finalidades' => $promedioFinalidades,
            ],
            'destacados' => [
                'mas_aceptada'  => $masAceptada,
                'mas_resistida' => $masResistida,
            ],
            'por_tipo'      => $porTipo,
            'por_finalidad' => $porFinalidad,
            'por_medio'     => $porMedio,
            'por_mes'       => $porMes,
            'maximos'       => [
                'finalidad' => $this->maximo($porFinalidad),
                'medio'     => $this->maximo($porMedio),
                'mes'       => $this->maximo($porMes),
            ],
        ]);
    }

    /**
     * GET /api/reportes/titulares
     * Detalle de titulares que otorgaron o revocaron su consentimiento.
     *
     * Filtros: estado, tipo, finalidad_id, medio, desde, hasta, q.
     */
    public function titulares(array $ruta = []): void
    {
        $this->autorizarReporte('reporte_titulares');
        $institucionId = $this->institucion();

        /* --- Filtros --- */
        $estado      = strtoupper($this->peticion->paramTexto('estado'));
        $tipo        = strtolower($this->peticion->paramTexto('tipo', 'todos'));
        $finalidadId = $this->peticion->paramEntero('finalidad_id');
        $medio       = strtoupper($this->peticion->paramTexto('medio', 'TODOS'));
        $desde       = $this->peticion->paramTexto('desde');
        $hasta       = $this->peticion->paramTexto('hasta');
        $buscar      = $this->peticion->paramTexto('q');

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
               INNER JOIN persona p   ON p.PersonaId   = c.PersonaId AND p.InstitucionEducativaId = c.InstitucionEducativaId
                LEFT JOIN finalidad f ON f.FinalidadId = c.FinalidadId";

        $where  = 'WHERE c.InstitucionEducativaId = ?';
        $params = [$institucionId];

        if (in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
            $where   .= ' AND c.Estado = ?';
            $params[] = $estado;
        }

        if ($finalidadId > 0) {
            $where   .= ' AND c.FinalidadId = ?';
            $params[] = $finalidadId;
        }

        if ($medio !== 'TODOS' && in_array($medio, ['WEB', 'EMAIL', 'WHATSAPP', 'APP'], true)) {
            $where   .= ' AND c.MedioConsentimiento = ?';
            $params[] = $medio;
        }

        if ($tipo !== 'todos') {
            $tabla = ['estudiantes' => 'estudiante', 'empleados' => 'empleado', 'proveedores' => 'proveedor'][$tipo];
            $where   .= " AND EXISTS (SELECT 1 FROM $tabla t
                                       WHERE t.PersonaId = p.PersonaId AND t.InstitucionEducativaId = ? AND t.Estado = 'ACTIVO')";
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
            'totales'     => [
                'registros'   => $total,
                'consentidos' => (int)$totales['consentidos'],
                'revocados'   => (int)$totales['revocados'],
            ],
            'finalidades' => $this->consultar("SELECT FinalidadId, Nombre FROM finalidad WHERE Activo = 'ACTIVO' ORDER BY Nombre"),
            'filtros'     => [
                'estado'       => $estado,
                'tipo'         => $tipo,
                'finalidad_id' => $finalidadId,
                'medio'        => $medio,
                'desde'        => $desde,
                'hasta'        => $hasta,
                'q'            => $buscar,
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
     * La usa la pantalla de Links de Consentimiento con Verificación para
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
            // La bitácora no guarda valores: se busca por campo y por registro.
            $where .= ' AND (a.Campo LIKE ? OR a.RegistroId LIKE ?)';
            array_push($params, $like, $like);
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
                        a.Operacion, a.Campo
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
     * GET /api/reportes/red-educativa
     * Tablero comparativo ejecutivo para SuperAdmin: todas las sedes de la red
     * con sus indicadores de cumplimiento LOPDP.
     * Sin filtros: vista global consolidada.
     */
    public function redEducativa(array $ruta = []): void
    {
        /* Solo SuperAdmin puede ver este reporte */
        $this->requiereRol(['SuperAdmin']);

        /* Obtener todas las instituciones activas */
        $instituciones = $this->consultar(
            "SELECT id, nombre, direccion, telefono, estado
               FROM institucion_educativa
              WHERE estado = 'ACTIVO'
              ORDER BY nombre ASC"
        );

        if (empty($instituciones)) {
            Response::exito([
                'kpis_globales' => [
                    'total_sedes' => 0, 'poblacion_total' => 0,
                    'consentimientos_total' => 0, 'cumplimiento_promedio' => 0,
                ],
                'sedes' => [],
            ]);
            return;
        }

        $sedes = [];
        $globalPoblacion      = 0;
        $globalConsentimientos = 0;
        $globalPendientes     = 0;
        $globalRevocados      = 0;

        foreach ($instituciones as $inst) {
            $ieId = (int)$inst['id'];

            /* Población activa por tipo */
            $estudiantes = $this->contar(
                "SELECT COUNT(*) total FROM estudiante WHERE InstitucionEducativaId = ? AND Estado = 'ACTIVO'",
                [$ieId]
            );
            $empleados = $this->contar(
                "SELECT COUNT(*) total FROM empleado WHERE InstitucionEducativaId = ? AND Estado = 'ACTIVO'",
                [$ieId]
            );
            $proveedores = $this->contar(
                "SELECT COUNT(*) total FROM proveedor WHERE InstitucionEducativaId = ? AND Estado = 'ACTIVO'",
                [$ieId]
            );

            /* Población objetivo total (personas activas con vínculo) */
            $poblacion = $this->contar(
                "SELECT COUNT(DISTINCT p.PersonaId) total
                   FROM persona p
                   LEFT JOIN estudiante es ON es.PersonaId = p.PersonaId AND es.InstitucionEducativaId = p.InstitucionEducativaId AND es.Estado = 'ACTIVO'
                   LEFT JOIN empleado em   ON em.PersonaId = p.PersonaId AND em.InstitucionEducativaId = p.InstitucionEducativaId AND em.Estado = 'ACTIVO'
                   LEFT JOIN proveedor pr  ON pr.PersonaId = p.PersonaId AND pr.InstitucionEducativaId = p.InstitucionEducativaId AND pr.Estado = 'ACTIVO'
                  WHERE p.InstitucionEducativaId = ? AND p.Estado = 'ACTIVO'
                    AND (es.EstudianteId IS NOT NULL OR em.EmpleadoId IS NOT NULL OR pr.ProveedorId IS NOT NULL)",
                [$ieId]
            );

            /* Consentimientos */
            $consMetrics = $this->consultarUna(
                "SELECT
                    COUNT(DISTINCT CASE WHEN c.Estado = 'ACTIVO' THEN c.PersonaId END) AS consentidos,
                    COUNT(DISTINCT CASE WHEN c.Estado = 'INACTIVO' THEN c.PersonaId END) AS revocados
                   FROM consentimiento c
                  WHERE c.InstitucionEducativaId = ?",
                [$ieId]
            ) ?: ['consentidos' => 0, 'revocados' => 0];

            $consentidos = (int)($consMetrics['consentidos'] ?? 0);
            $revocados   = (int)($consMetrics['revocados'] ?? 0);
            $pendientes  = max(0, $poblacion - $consentidos);
            $pctCumplimiento = $poblacion > 0 ? round(($consentidos / $poblacion) * 100, 1) : 0.0;

            /* Diagnóstico */
            if ($pctCumplimiento >= 80) {
                $diagnostico = 'AL_DIA';
            } elseif ($pctCumplimiento >= 50) {
                $diagnostico = 'EN_PROGRESO';
            } else {
                $diagnostico = 'REZAGADA';
            }

            $sedes[] = [
                'institucion_id'   => $ieId,
                'nombre'           => $inst['nombre'],
                'direccion'        => $inst['direccion'] ?: '—',
                'telefono'         => $inst['telefono'] ?: '—',
                'estudiantes'      => $estudiantes,
                'empleados'        => $empleados,
                'proveedores'      => $proveedores,
                'poblacion'        => $poblacion,
                'consentidos'      => $consentidos,
                'pendientes'       => $pendientes,
                'revocados'        => $revocados,
                'pct_cumplimiento' => $pctCumplimiento,
                'diagnostico'      => $diagnostico,
            ];

            $globalPoblacion      += $poblacion;
            $globalConsentimientos += $consentidos;
            $globalPendientes     += $pendientes;
            $globalRevocados      += $revocados;
        }

        /* Ordenar por cumplimiento descendente (ranking) */
        usort($sedes, fn($a, $b) => $b['pct_cumplimiento'] <=> $a['pct_cumplimiento']);

        /* Asignar ranking */
        foreach ($sedes as $i => &$s) {
            $s['ranking'] = $i + 1;
        }
        unset($s);

        $cumplimientoPromedio = $globalPoblacion > 0
            ? round(($globalConsentimientos / $globalPoblacion) * 100, 1)
            : 0.0;

        Response::exito([
            'kpis_globales' => [
                'total_sedes'           => count($sedes),
                'poblacion_total'       => $globalPoblacion,
                'consentimientos_total' => $globalConsentimientos,
                'pendientes_total'      => $globalPendientes,
                'revocados_total'       => $globalRevocados,
                'cumplimiento_promedio' => $cumplimientoPromedio,
            ],
            'sedes' => $sedes,
        ]);
    }

    /**
     * GET /api/reportes/envios-masivos
     * Métricas de efectividad de invitaciones enviadas y conversión a consentimientos.
     */
    public function enviosMasivos(array $ruta = []): void
    {
        $this->autorizarReporte('reporte_envios_masivos');
        $institucionId = $this->institucion();

        $tipo   = strtolower($this->peticion->paramTexto('tipo', 'todos'));
        $estado = strtoupper($this->peticion->paramTexto('estado', 'todos'));
        $desde  = $this->peticion->paramTexto('desde');
        $hasta  = $this->peticion->paramTexto('hasta');
        $buscar = $this->peticion->paramTexto('q');

        $tiposValidos = ['todos', 'estudiante', 'empleado', 'proveedor'];
        if (!in_array($tipo, $tiposValidos, true)) {
            $tipo = 'todos';
        }

        $estadosValidos = ['TODOS', 'USADO', 'PENDIENTE', 'EXPIRADO', 'ANULADO'];
        if (!in_array($estado, $estadosValidos, true)) {
            $estado = 'TODOS';
        }

        // Base where
        $where = "WHERE vc.InstitucionEducativaId = ?";
        $params = [$institucionId];

        if ($tipo !== 'todos') {
            $where .= " AND vc.TipoPersona = ?";
            $params[] = strtoupper($tipo);
        }

        if ($estado !== 'TODOS') {
            if ($estado === 'ANULADO') {
                $where .= " AND (vc.Estado = 'ANULADO' OR (vc.Estado = 'PENDIENTE' AND vc.FechaExpira < NOW()))";
            } elseif ($estado === 'PENDIENTE') {
                $where .= " AND (vc.Estado = 'PENDIENTE' AND vc.FechaExpira >= NOW())";
            } else {
                $where .= " AND vc.Estado = ?";
                $params[] = $estado;
            }
        }

        if ($desde !== '') {
            $where .= " AND vc.FechaEmision >= ?";
            $params[] = $desde . ' 00:00:00';
        }
        if ($hasta !== '') {
            $where .= " AND vc.FechaEmision <= ?";
            $params[] = $hasta . ' 23:59:59';
        }

        if ($buscar !== '') {
            $like = $this->like($buscar);
            $where .= " AND (p.Nombres LIKE ? OR p.Apellidos LIKE ? OR vc.Identificacion LIKE ? OR vc.Destinatario LIKE ?)";
            array_push($params, $like, $like, $like, $like);
        }

        // KPIs Globales
        $kpisWhere = "WHERE vc.InstitucionEducativaId = ?";
        $kpisParams = [$institucionId];
        if ($desde !== '') {
            $kpisWhere .= " AND vc.FechaEmision >= ?";
            $kpisParams[] = $desde . ' 00:00:00';
        }
        if ($hasta !== '') {
            $kpisWhere .= " AND vc.FechaEmision <= ?";
            $kpisParams[] = $hasta . ' 23:59:59';
        }

        $sqlKpis = "SELECT
            COUNT(*) AS total_invitaciones,
            SUM(CASE WHEN vc.Estado = 'USADO' THEN 1 ELSE 0 END) AS verificados_usados,
            SUM(CASE WHEN vc.Estado = 'PENDIENTE' AND vc.FechaExpira >= NOW() THEN 1 ELSE 0 END) AS pendientes_activos,
            SUM(CASE WHEN vc.Estado = 'ANULADO' OR (vc.Estado = 'PENDIENTE' AND vc.FechaExpira < NOW()) THEN 1 ELSE 0 END) AS anulados,
            COUNT(DISTINCT vc.PersonaId) AS personas_contactadas
          FROM verificacion_codigo vc
          $kpisWhere";

        $kpisData = $this->consultarUna($sqlKpis, $kpisParams) ?: [];
        $totInv = (int)($kpisData['total_invitaciones'] ?? 0);
        $totUsados = (int)($kpisData['verificados_usados'] ?? 0);
        $totPend = (int)($kpisData['pendientes_activos'] ?? 0);
        $totAnulados = (int)($kpisData['anulados'] ?? 0);
        $pctConversion = $totInv > 0 ? round(($totUsados / $totInv) * 100, 1) : 0.0;

        // Desglose por tipo
        $sqlPorTipo = "SELECT
            vc.TipoPersona,
            COUNT(*) AS total,
            SUM(CASE WHEN vc.Estado = 'USADO' THEN 1 ELSE 0 END) AS usados,
            SUM(CASE WHEN vc.Estado = 'PENDIENTE' AND vc.FechaExpira >= NOW() THEN 1 ELSE 0 END) AS pendientes,
            SUM(CASE WHEN vc.Estado = 'ANULADO' OR (vc.Estado = 'PENDIENTE' AND vc.FechaExpira < NOW()) THEN 1 ELSE 0 END) AS anulados
          FROM verificacion_codigo vc
          $kpisWhere
          GROUP BY vc.TipoPersona";
        $porTipo = $this->consultar($sqlPorTipo, $kpisParams);

        // Conteo total para paginación
        $sqlContar = "SELECT COUNT(*) total
                        FROM verificacion_codigo vc
                   LEFT JOIN persona p ON p.PersonaId = vc.PersonaId AND p.InstitucionEducativaId = vc.InstitucionEducativaId
                      $where";
        $totalFilas = $this->contar($sqlContar, $params);

        [$pagina, $porPagina, $offset] = $this->paginacion(15);

        // Detalle
        $sqlDetalle = "SELECT
            vc.VerificacionId,
            vc.TipoPersona,
            vc.PersonaId,
            vc.Identificacion,
            vc.Destinatario,
            vc.FechaEmision,
            vc.FechaExpira,
            vc.FechaUso,
            vc.Intentos,
            vc.Envio,
            vc.Estado AS EstadoCodigo,
            CASE
                WHEN vc.Estado = 'USADO' THEN 'USADO'
                WHEN vc.Estado = 'ANULADO' OR (vc.Estado = 'PENDIENTE' AND vc.FechaExpira < NOW()) THEN 'ANULADO'
                ELSE vc.Estado
            END AS EstadoCalculado,
            p.Nombres,
            p.Apellidos,
            (SELECT pr.RazonSocial FROM proveedor pr WHERE pr.PersonaId = p.PersonaId AND pr.InstitucionEducativaId = vc.InstitucionEducativaId LIMIT 1) AS RazonSocial,
            (SELECT CONCAT(r.Nombres, ' ', r.Apellidos) FROM estudiante e JOIN persona r ON r.PersonaId = e.RepresentanteId WHERE e.PersonaId = p.PersonaId AND e.InstitucionEducativaId = vc.InstitucionEducativaId LIMIT 1) AS Representante,
            (SELECT c.Estado FROM consentimiento c WHERE c.PersonaId = vc.PersonaId AND c.InstitucionEducativaId = vc.InstitucionEducativaId AND c.Estado = 'ACTIVO' LIMIT 1) AS ConsentimientoActivo
          FROM verificacion_codigo vc
     LEFT JOIN persona p ON p.PersonaId = vc.PersonaId AND p.InstitucionEducativaId = vc.InstitucionEducativaId
        $where
      ORDER BY vc.FechaEmision DESC, vc.VerificacionId DESC
         LIMIT $offset, $porPagina";

        $detalle = $this->consultar($sqlDetalle, $params);

        foreach ($detalle as &$d) {
            $d['Titular'] = !empty($d['RazonSocial'])
                ? (string)$d['RazonSocial']
                : trim(($d['Apellidos'] ?? '') . ' ' . ($d['Nombres'] ?? ''));
            if ($d['Titular'] === '') {
                $d['Titular'] = '—';
            }
        }
        unset($d);

        Response::lista($detalle, $totalFilas, $pagina, $porPagina, [
            'kpis' => [
                'total_invitaciones' => $totInv,
                'verificados_usados' => $totUsados,
                'pendientes_activos' => $totPend,
                'anulados'           => $totAnulados,
                'expirados_anulados' => $totAnulados,
                'pct_conversion'     => $pctConversion,
            ],
            'por_tipo' => $porTipo,
            'filtros'  => [
                'tipo'   => $tipo,
                'estado' => $estado,
                'desde'  => $desde,
                'hasta'  => $hasta,
                'q'      => $buscar,
            ],
        ]);
    }

    /**
     * GET /api/reportes/exportar?entidad=personas|empleados|estudiantes|proveedores|consentimientos|historials para construir el CSV en la vista.
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
