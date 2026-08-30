<?php
/**
 * includes/menu.php
 * Menú lateral del sistema. Requiere que APP_ROOT y las funciones de
 * auth.php ya estén disponibles (incluido desde includes/layout_top.php).
 */
$paginaActual = basename($_SERVER['SCRIPT_NAME']);

/** Determina si el enlace corresponde a la página activa. */
function esActivo(string $archivo): string {
    global $paginaActual;
    return $archivo === $paginaActual ? ' activo' : '';
}

/** Determina si el grupo debe mostrarse expandido porque contiene la página activa. */
function grupoAbierto(array $archivos): string {
    global $paginaActual;
    return in_array($paginaActual, $archivos, true) ? ' open' : '';
}

$archivosRegistro = [
    'instituciones.php', 'empleados.php', 'estudiantes.php',
    'proveedores.php', 'finalidades.php', 'tipos_dato.php', 'consentimientos.php',
    'usuarios.php', 'roles.php', 'permisos.php',
    'disclaimers.php', 'correo_configuracion.php',
    'enlaces_verificados.php', 'envio_masivo.php', 'precarga_inicial.php',
];
$archivosConsultas = ['buscar_persona.php', 'historial_consentimientos.php', 'consentimientos_vigentes.php'];
$archivosReportes  = ['reporte_consentimientos.php', 'reporte_datos_sensibles.php', 'reporte_titulares.php',
                      'reporte_auditoria.php', 'exportar_csv.php'];
?>
<aside class="sidebar" id="sidebarApp">
    <div class="sidebar-logo">
        <img src="<?= e(APP_ROOT) ?>assets/logo_small.png" alt="REA">
        <div class="sidebar-lema">Educamos y evangelizamos con amor</div>
    </div>

    <nav>
        <div class="nav-seccion">
            <a class="nav-link<?= esActivo('dashboard.php') ?>" href="<?= e(APP_ROOT) ?>dashboard.php">
                <span class="icono">🏠</span> Panel Principal
            </a>
        </div>

        <?php if (puedeAccederSeccion('registro')): ?>
        <div class="nav-seccion">
            <div class="nav-titulo">Registro de Datos</div>
            <details class="nav-grupo"<?= grupoAbierto($archivosRegistro) ?> open>
                <summary class="nav-link">
                    <span class="icono">📝</span> Entidades <span class="flecha">▶</span>
                </summary>
                <div class="nav-sub">
                    <?php if (puedeAcceder('instituciones')): ?>
                    <a class="nav-link<?= esActivo('instituciones.php') ?>" href="<?= e(APP_ROOT) ?>modules/instituciones.php">
                        <span class="icono">🏫</span> Instituciones
                    </a>
                    <?php endif; ?>

                    <?php if (puedeAcceder('empleados')): ?>
                    <a class="nav-link<?= esActivo('empleados.php') ?>" href="<?= e(APP_ROOT) ?>modules/empleados.php">
                        <span class="icono">👥</span> Empleados
                    </a>
                    <?php endif; ?>

                    <?php if (puedeAcceder('estudiantes')): ?>
                    <a class="nav-link<?= esActivo('estudiantes.php') ?>" href="<?= e(APP_ROOT) ?>modules/estudiantes.php">
                        <span class="icono">🎓</span> Estudiantes
                    </a>
                    <?php endif; ?>

                    <?php if (puedeAcceder('proveedores')): ?>
                    <a class="nav-link<?= esActivo('proveedores.php') ?>" href="<?= e(APP_ROOT) ?>modules/proveedores.php">
                        <span class="icono">📦</span> Proveedores
                    </a>
                    <?php endif; ?>

                    <?php if (puedeAcceder('consentimientos')): ?>
                    <a class="nav-link<?= esActivo('consentimientos.php') ?>" href="<?= e(APP_ROOT) ?>modules/consentimientos.php">
                        <span class="icono">✅</span> Consentimientos
                    </a>
                    <?php endif; ?>

                    <?php if (puedeAcceder('finalidades')): ?>
                    <a class="nav-link<?= esActivo('finalidades.php') ?>" href="<?= e(APP_ROOT) ?>modules/finalidades.php">
                        <span class="icono">🎯</span> Finalidades del Tratamiento
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('tipos_dato')): ?>
                    <a class="nav-link<?= esActivo('tipos_dato.php') ?>" href="<?= e(APP_ROOT) ?>modules/tipos_dato.php">
                        <span class="icono">🗂️</span> Tipos de Dato Personal
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('usuarios')): ?>
                    <a class="nav-link<?= esActivo('usuarios.php') ?>" href="<?= e(APP_ROOT) ?>modules/usuarios.php">
                        <span class="icono">🔑</span> Usuarios del Sistema
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('roles')): ?>
                    <a class="nav-link<?= esActivo('roles.php') ?>" href="<?= e(APP_ROOT) ?>modules/roles.php">
                        <span class="icono">🛡️</span> Roles
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('permisos')): ?>
                    <a class="nav-link<?= esActivo('permisos.php') ?>" href="<?= e(APP_ROOT) ?>modules/permisos.php">
                        <span class="icono">🔐</span> Permisos
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('disclaimers')): ?>
                    <a class="nav-link<?= esActivo('disclaimers.php') ?>" href="<?= e(APP_ROOT) ?>modules/disclaimers.php">
                        <span class="icono">📜</span> Disclaimers de Datos
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('correo_configuracion')): ?>
                    <a class="nav-link<?= esActivo('correo_configuracion.php') ?>" href="<?= e(APP_ROOT) ?>modules/correo_configuracion.php">
                        <span class="icono">⚙️</span> Configuración de Correo
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('enlaces_verificados')): ?>
                    <a class="nav-link<?= esActivo('enlaces_verificados.php') ?>" href="<?= e(APP_ROOT) ?>modules/enlaces_verificados.php">
                        <span class="icono">🔗</span> Enlaces de Consentimiento
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('envio_masivo')): ?>
                    <a class="nav-link<?= esActivo('envio_masivo.php') ?>" href="<?= e(APP_ROOT) ?>modules/envio_masivo.php">
                        <span class="icono">📨</span> Envío Masivo
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('precarga')): ?>
                    <a class="nav-link<?= esActivo('precarga_inicial.php') ?>" href="<?= e(APP_ROOT) ?>modules/precarga_inicial.php">
                        <span class="icono">📥</span> PreCarga Inicial
                    </a>
                    <?php endif; ?>
                </div>
            </details>
        </div>
        <?php endif; ?>

        <?php if (puedeAccederSeccion('consultas')): ?>
        <div class="nav-seccion">
            <div class="nav-titulo">Consultas y Búsquedas</div>
            <details class="nav-grupo"<?= grupoAbierto($archivosConsultas) ?> open>
                <summary class="nav-link">
                    <span class="icono">🔍</span> Consultas <span class="flecha">▶</span>
                </summary>
                <div class="nav-sub">
                    <?php if (puedeAcceder('consulta_buscar_persona')): ?>
                    <a class="nav-link<?= esActivo('buscar_persona.php') ?>" href="<?= e(APP_ROOT) ?>consultas/buscar_persona.php">
                        <span class="icono">🔎</span> Buscar Persona
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('consulta_historial')): ?>
                    <a class="nav-link<?= esActivo('historial_consentimientos.php') ?>" href="<?= e(APP_ROOT) ?>consultas/historial_consentimientos.php">
                        <span class="icono">🕒</span> Historial de Consentimientos
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('consulta_vigentes')): ?>
                    <a class="nav-link<?= esActivo('consentimientos_vigentes.php') ?>" href="<?= e(APP_ROOT) ?>consultas/consentimientos_vigentes.php">
                        <span class="icono">📋</span> Vigentes / Revocados
                    </a>
                    <?php endif; ?>
                </div>
            </details>
        </div>
        <?php endif; ?>

        <?php if (puedeAccederSeccion('reportes')): ?>
        <div class="nav-seccion">
            <div class="nav-titulo">Reportes y Exportación</div>
            <details class="nav-grupo"<?= grupoAbierto($archivosReportes) ?> open>
                <summary class="nav-link">
                    <span class="icono">📊</span> Reportes <span class="flecha">▶</span>
                </summary>
                <div class="nav-sub">
                    <?php if (puedeAcceder('reporte_consentimientos')): ?>
                    <a class="nav-link<?= esActivo('reporte_consentimientos.php') ?>" href="<?= e(APP_ROOT) ?>reportes/reporte_consentimientos.php">
                        <span class="icono">📈</span> Consentimientos por Finalidad
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('reporte_datos_sensibles')): ?>
                    <a class="nav-link<?= esActivo('reporte_datos_sensibles.php') ?>" href="<?= e(APP_ROOT) ?>reportes/reporte_datos_sensibles.php">
                        <span class="icono">⚠️</span> Datos Sensibles
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('reporte_titulares')): ?>
                    <a class="nav-link<?= esActivo('reporte_titulares.php') ?>" href="<?= e(APP_ROOT) ?>reportes/reporte_titulares.php">
                        <span class="icono">🧾</span> Consentimientos por Titular
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('reporte_auditoria')): ?>
                    <a class="nav-link<?= esActivo('reporte_auditoria.php') ?>" href="<?= e(APP_ROOT) ?>reportes/reporte_auditoria.php">
                        <span class="icono">🗂️</span> Bitácora de Auditoría
                    </a>
                    <?php endif; ?>
                    <?php if (puedeAcceder('exportar_csv')): ?>
                    <a class="nav-link<?= esActivo('exportar_csv.php') ?>" href="<?= e(APP_ROOT) ?>reportes/exportar_csv.php">
                        <span class="icono">⬇️</span> Exportar CSV
                    </a>
                    <?php endif; ?>
                </div>
            </details>
        </div>
        <?php endif; ?>

        <?php if (esSuperAdmin()): ?>
        <div class="nav-seccion">
            <div class="nav-titulo">Desarrollo</div>
            <a class="nav-link" href="<?= e(APP_ROOT) ?>api/docs" target="_blank" rel="noopener">
                <span class="icono">🧩</span> Documentación de la API
            </a>
        </div>
        <?php endif; ?>
    </nav>
</aside>
