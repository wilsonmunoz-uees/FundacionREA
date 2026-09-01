<?php
// dashboard.php
define('APP_ROOT', '');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$institucionId = institucionActual();

// ---- Todo el panel se arma con una sola llamada a la API ----
$respuesta = apiGet('reportes/dashboard');
$panel     = apiDatos($respuesta, []);

if (!$respuesta['ok']) {
    flashSet('error', 'No se pudieron cargar los indicadores: ' . apiError($respuesta));
}

$kpis = $panel['kpis'] ?? [];
$kpiEstudiantes      = (int)($kpis['estudiantes'] ?? 0);
$kpiEmpleados        = (int)($kpis['empleados'] ?? 0);
$kpiConsentActivos   = (int)($kpis['consentimientos_activos'] ?? 0);
$kpiConsentRevocados = (int)($kpis['consentimientos_revocados'] ?? 0);
$kpiProveedores      = (int)($kpis['proveedores'] ?? 0);
$kpiUsuarios         = (int)($kpis['usuarios'] ?? 0);

$ultimosConsentimientos = $panel['ultimos_consentimientos'] ?? [];
$ultimoHistorial        = $panel['ultimo_historial'] ?? [];

$pageTitle = 'Panel Principal';
$pageDesc  = 'Resumen general del sistema de protección de datos';
$breadcrumb = [['label' => 'Panel Principal', 'url' => null]];
include __DIR__ . '/includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>Panel de Control</h1>
        <p>Bienvenido/a, <strong><?= e($_SESSION['username']) ?></strong> &middot; Roles: <em><?= e(implode(', ', $_SESSION['roles'])) ?></em></p>
    </div>
</div>

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-valor"><?= $kpiConsentActivos ?></div>
        <div class="kpi-label">Consentimientos Activos</div>
    </div>
    <div class="kpi-card kpi-alt-3">
        <div class="kpi-valor"><?= $kpiConsentRevocados ?></div>
        <div class="kpi-label">Consentimientos Revocados</div>
    </div>
    <div class="kpi-card kpi-alt-2">
        <div class="kpi-valor"><?= $kpiEstudiantes ?></div>
        <div class="kpi-label">Estudiantes Activos</div>
    </div>
    <div class="kpi-card kpi-alt-1">
        <div class="kpi-valor"><?= $kpiEmpleados ?></div>
        <div class="kpi-label">Empleados Activos</div>
    </div>
    <div class="kpi-card kpi-alt-2">
        <div class="kpi-valor"><?= $kpiProveedores ?></div>
        <div class="kpi-label">Proveedores Activos</div>
    </div>
    <div class="kpi-card kpi-alt-1">
        <div class="kpi-valor"><?= $kpiUsuarios ?></div>
        <div class="kpi-label">Usuarios del Sistema</div>
    </div>
</div>

<h2>Menú Principal</h2>
<div class="menu-grid">

    <?php if (puedeAcceder('empleados')): ?>
    <div class="card-modulo">
        <div class="icono-modulo">👥</div>
        <h3>Gestión de Empleados</h3>
        <p>Mantenimiento del personal vinculado a la institución.</p>
        <ul>
            <li><a href="modules/empleados.php">Ver Empleados</a></li>
            <li><a href="modules/empleados.php?accion=crear">Registrar Empleado</a></li>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (puedeAcceder('estudiantes')): ?>
    <div class="card-modulo">
        <div class="icono-modulo">🎓</div>
        <h3>Gestión de Estudiantes</h3>
        <p>Administración de alumnos matriculados y sus representantes.</p>
        <ul>
            <li><a href="modules/estudiantes.php">Ver Estudiantes</a></li>
            <li><a href="modules/estudiantes.php?accion=crear">Matricular Estudiante</a></li>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (puedeAcceder('proveedores')): ?>
    <div class="card-modulo">
        <div class="icono-modulo">📦</div>
        <h3>Gestión de Proveedores</h3>
        <p>Control de proveedores de bienes, servicios e infraestructura.</p>
        <ul>
            <li><a href="modules/proveedores.php">Directorio de Proveedores</a></li>
            <li><a href="modules/proveedores.php?accion=crear">Alta de Proveedor</a></li>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (puedeAcceder('consentimientos')): ?>
    <div class="card-modulo">
        <div class="icono-modulo">✅</div>
        <h3>Consentimientos</h3>
        <p>Consulta de los consentimientos otorgados por los titulares.</p>
        <ul>
            <li><a href="modules/consentimientos.php">Ver Consentimientos</a></li>
            <?php if (puedeAcceder('envio_masivo')): ?>
                <li><a href="modules/envio_masivo.php">Pedir consentimiento (envío masivo)</a></li>
            <?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (puedeAcceder('finalidades') || puedeAcceder('tipos_dato')): ?>
    <div class="card-modulo">
        <div class="icono-modulo">🎯</div>
        <h3>Finalidades y Tipos de Dato</h3>
        <p>Catálogos base para la gestión de consentimientos.</p>
        <ul>
            <li><a href="modules/finalidades.php">Finalidades del Tratamiento</a></li>
            <li><a href="modules/tipos_dato.php">Tipos de Dato Personal</a></li>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (puedeAcceder('usuarios') || puedeAcceder('roles') || puedeAcceder('permisos') || puedeAcceder('instituciones') || puedeAcceder('disclaimers') || puedeAcceder('correo_configuracion') || puedeAcceder('enlaces_verificados') || puedeAcceder('envio_masivo') || puedeAcceder('carga_informacion')): ?>
    <div class="card-modulo">
        <div class="icono-modulo">🔑</div>
        <h3>Administración</h3>
        <p>Usuarios, roles, permisos, instituciones, disclaimers, correo, envíos y carga de información.</p>
        <ul>
            <?php if (puedeAcceder('usuarios')): ?><li><a href="modules/usuarios.php">Usuarios del Sistema</a></li><?php endif; ?>
            <?php if (puedeAcceder('roles')): ?><li><a href="modules/roles.php">Roles</a></li><?php endif; ?>
            <?php if (puedeAcceder('permisos')): ?><li><a href="modules/permisos.php">Permisos</a></li><?php endif; ?>
            <?php if (puedeAcceder('instituciones')): ?><li><a href="modules/instituciones.php">Instituciones Educativas</a></li><?php endif; ?>
            <?php if (puedeAcceder('disclaimers')): ?><li><a href="modules/disclaimers.php">Disclaimers de Datos</a></li><?php endif; ?>
            <?php if (puedeAcceder('correo_configuracion')): ?><li><a href="modules/enlaces_consentimiento.php">Enlaces de Consentimiento</a></li><?php endif; ?>
            <?php if (puedeAcceder('enlaces_verificados')): ?><li><a href="modules/enlaces_verificados.php">Links de Consentimiento con Verificación</a></li><?php endif; ?>
            <?php if (puedeAcceder('envio_masivo')): ?><li><a href="modules/envio_masivo.php">Envío Masivo de Invitaciones</a></li><?php endif; ?>
            <?php if (puedeAcceder('carga_informacion')): ?><li><a href="modules/carga_informacion.php">Carga de Información</a></li><?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (puedeAccederSeccion('consultas') || puedeAccederSeccion('reportes')): ?>
    <div class="card-modulo">
        <div class="icono-modulo">🔍</div>
        <h3>Consultas y Reportes</h3>
        <p>Búsqueda de personas, historial y reportes de cumplimiento.</p>
        <ul>
            <?php if (puedeAcceder('reporte_cobertura')): ?><li><a href="reportes/reporte_cobertura.php">Cobertura y Pendientes</a></li><?php endif; ?>
            <?php if (esSuperAdmin()): ?><li><a href="reportes/reporte_red_educativa.php">Red Educativa Multi-Sede</a></li><?php endif; ?>
            <?php if (puedeAcceder('consulta_buscar_persona')): ?><li><a href="consultas/buscar_persona.php">Buscar Persona</a></li><?php endif; ?>
            <?php if (puedeAcceder('reporte_consentimientos')): ?><li><a href="reportes/reporte_consentimientos.php">Reportes de Consentimientos</a></li><?php endif; ?>
            <?php if (puedeAcceder('reporte_titulares')): ?><li><a href="reportes/reporte_titulares.php">Consentimientos por Titular</a></li><?php endif; ?>
            <?php if (puedeAcceder('reporte_envios_masivos')): ?><li><a href="reportes/reporte_envios_masivos.php">Efectividad de Envíos</a></li><?php endif; ?>
            <?php if (puedeAcceder('reporte_auditoria')): ?><li><a href="reportes/reporte_auditoria.php">Bitácora de Auditoría</a></li><?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

</div>

<div class="form-row" style="align-items:stretch;">
    <div class="card" style="flex:1 1 380px;">
        <h3>✅ Últimos Consentimientos Registrados</h3>
        <?php if (empty($ultimosConsentimientos)): ?>
            <p class="texto-mutado">Aún no hay consentimientos registrados para esta institución.</p>
        <?php else: ?>
            <ul class="timeline">
                <?php foreach ($ultimosConsentimientos as $c): ?>
                <li>
                    <div class="t-fecha"><?= f_fecha($c['FechaConsentimiento']) ?></div>
                    <div class="t-titulo"><?= e(nombreCompleto($c['Nombres'], $c['Apellidos'])) ?></div>
                    <div class="t-detalle">
                        Finalidad: <?= e($c['FinalidadNombre'] ?? '—') ?> &middot;
                        Medio: <?= e($c['MedioConsentimiento'] ?? '—') ?> &middot;
                        <?= badgeEstado($c['Estado']) ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card" style="flex:1 1 380px;">
        <h3>🕒 Movimientos Recientes de Consentimientos</h3>
        <?php if (empty($ultimoHistorial)): ?>
            <p class="texto-mutado">Sin movimientos registrados todavía.</p>
        <?php else: ?>
            <ul class="timeline">
                <?php foreach ($ultimoHistorial as $h): ?>
                <li>
                    <div class="t-fecha"><?= f_fecha($h['FechaAccion']) ?> &middot; <?= e($h['Username'] ?? 'sistema') ?></div>
                    <div class="t-titulo"><?= e($h['Accion']) ?></div>
                    <div class="t-detalle">
                        <?= e($h['EstadoAnterior'] ?? '—') ?> &rarr; <?= e($h['EstadoNuevo'] ?? '—') ?>
                        <?php if (!empty($h['Observacion'])): ?> &middot; <?= truncar($h['Observacion'], 70) ?><?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/layout_bottom.php'; ?>
