<?php
/**
 * includes/accesos.php
 * -----------------------------------------------------------------------------
 * Mapa único de control de acceso del sistema.
 *
 * Cada opción del menú declara aquí:
 *   roles       -> nombres de rol que la abren (compatibilidad con la instalación original)
 *   permisos    -> códigos de permiso que también la abren (tabla `permiso`)
 *   autenticado -> true si basta con tener sesión iniciada
 *
 * Las páginas (auth.php) y la API (api/core/Auth.php) leen este mismo archivo,
 * de modo que la pantalla y el endpoint nunca se contradicen.
 *
 * Un usuario entra si: es SuperAdmin, o tiene alguno de los roles listados,
 * o tiene alguno de los permisos listados.
 * -----------------------------------------------------------------------------
 */

function accesosSistema(): array
{
    return [

        /* ---------- Seguridades (administración de accesos) ---------- */
        'usuarios' => [
            'etiqueta' => 'Usuarios del Sistema',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['SEG_USUARIOS'],
        ],
        'roles' => [
            'etiqueta' => 'Roles',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['SEG_ROLES'],
        ],
        'permisos' => [
            'etiqueta' => 'Permisos',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['SEG_PERMISOS'],
        ],
        /**
         * Disclaimers: textos de política que ve cada persona al dar su
         * consentimiento desde los enlaces públicos.
         */
        'disclaimers' => [
            'etiqueta' => 'Disclaimers de Datos',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['ADM_DISCLAIMERS'],
        ],
        /**
         * Configuración del correo saliente y enlaces públicos de
         * consentimiento. Ambas pantallas comparten el mismo permiso.
         */
        'correo_configuracion' => [
            'etiqueta' => 'Configuración de Correo',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['ADM_CORREO'],
        ],

        /**
         * Links de consentimiento con verificación: los enlaces públicos de
         * solo consulta que confirman la identidad con un código enviado al
         * correo registrado. Es una opción administrativa: la abren el
         * SuperAdmin y quien tenga el permiso, que de fábrica lleva el rol
         * administrativo del sistema.
         */
        'enlaces_verificados' => [
            'etiqueta' => 'Links de Consentimiento con Verificación',
            'roles'    => ['SuperAdmin', 'Seguridades'],
            'permisos' => ['ADM_ENLACES_VERIF'],
        ],

        /**
         * Envío Masivo de invitaciones al consentimiento.
         *
         * Es la opción del rol **Registro de Datos**: envía a estudiantes,
         * empleados o proveedores el enlace de consentimiento con verificación,
         * con su documento precargado. Como toda opción del sistema, el
         * SuperAdmin también la ve.
         */
        'envio_masivo' => [
            'etiqueta' => 'Envío Masivo de Invitaciones',
            'roles'    => ['SuperAdmin', 'Registro de Datos'],
            'permisos' => [],
        ],

        /**
         * Carga de Información: incorpora al padrón de la institución activa lo
         * que trae la plantilla Excel, dando de alta lo que no está y
         * actualizando lo que sí. Es la única vía de alta de empleados,
         * estudiantes y proveedores, y por su alcance no tiene permiso
         * asignable: la abre únicamente SuperAdmin.
         */
        'carga_informacion' => [
            'etiqueta' => 'Carga de Información',
            'roles'    => ['SuperAdmin'],
            'permisos' => [],
        ],

        /* ---------- Registro de datos (mantenimientos) ---------- */
        'instituciones' => [
            'etiqueta' => 'Instituciones Educativas',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['REG_INSTITUCIONES'],
        ],
        'empleados' => [
            'etiqueta' => 'Empleados',
            'roles'    => ['SuperAdmin', 'RecursosHumanos'],
            'permisos' => ['REG_EMPLEADOS'],
        ],
        'estudiantes' => [
            'etiqueta' => 'Estudiantes',
            'roles'    => ['SuperAdmin', 'Secretaria'],
            'permisos' => ['REG_ESTUDIANTES'],
        ],
        'proveedores' => [
            'etiqueta' => 'Proveedores',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['REG_PROVEEDORES'],
        ],
        /*
         * Consentimientos: pantalla de CONSULTA.
         *
         * El consentimiento lo otorga el titular desde el enlace público, no lo
         * teclea nadie por él: registrarlo a mano dejaba constancia de una
         * voluntad que el sistema no puede acreditar. Aquí solo se mira.
         */
        'consentimientos' => [
            'etiqueta' => 'Consentimientos',
            'roles'    => ['SuperAdmin', 'RecursosHumanos', 'Secretaria'],
            // REGISTRO_DATOS se conserva por compatibilidad con el permiso original
            'permisos' => ['REG_CONSENTIMIENTOS', 'REGISTRO_DATOS'],
        ],

        /*
         * Revocar es la única acción que queda sobre un consentimiento, y la
         * reserva el SuperAdmin: deja sin efecto una autorización del titular y
         * afecta a todo tratamiento que dependiera de ella.
         */
        'consentimientos_revocar' => [
            'etiqueta' => 'Revocar consentimientos',
            'roles'    => ['SuperAdmin'],
            'permisos' => [],
        ],
        /*
         * Finalidades y tipos de dato son CATÁLOGOS COMPARTIDOS por las 21
         * instituciones: sus tablas no tienen columna de institución, y no la
         * tienen a propósito. Una finalidad como «gestión académica» o un tipo
         * de dato como «correo electrónico» significan lo mismo en toda la red,
         * y los consentimientos ya otorgados apuntan a ellos.
         *
         * Por eso solo los mantiene el SuperAdmin, que es quien tiene la vista
         * de toda la red: cada institución los usa al registrar consentimientos,
         * pero ninguna puede cambiar lo que las demás están usando. Editarlos
         * desde una institución sería editarlos para todas, sin saberlo.
         */
        'finalidades' => [
            'etiqueta' => 'Finalidades del Tratamiento',
            'roles'    => ['SuperAdmin'],
            'permisos' => [],
        ],
        'tipos_dato' => [
            'etiqueta' => 'Tipos de Dato Personal',
            'roles'    => ['SuperAdmin'],
            'permisos' => [],
        ],

        /**
         * Lectura del padrón de personas.
         *
         * `persona` es la entidad padre de empleados, estudiantes,
         * representantes y proveedores, y no tiene opción de menú propia: no se
         * mantiene por separado. Esta clave solo abre la LECTURA, que necesitan
         * los módulos que eligen a una persona ya registrada —consentimientos y
         * usuarios— y la ficha 360° de Consultas.
         */
        'personas_lectura' => [
            'etiqueta' => 'Consulta del directorio de personas',
            'roles'    => ['SuperAdmin', 'RecursosHumanos', 'Secretaria'],
            'permisos' => [
                'REG_PERSONAS', 'REG_EMPLEADOS', 'REG_ESTUDIANTES', 'REG_PROVEEDORES',
                'REG_CONSENTIMIENTOS', 'REGISTRO_DATOS', 'SEG_USUARIOS',
            ],
        ],

        /* ---------- Consultas ---------- */
        /* Cada consulta exige su permiso CON_*. Los roles originales del sistema
           se mantienen en la lista para no quitarles un acceso que ya tenían. */
        'consulta_buscar_persona' => [
            'etiqueta' => 'Buscar Persona',
            'roles'    => ['SuperAdmin', 'RecursosHumanos', 'Secretaria'],
            'permisos' => ['CON_BUSCAR_PERSONA'],
        ],
        'consulta_historial' => [
            'etiqueta' => 'Historial de Consentimientos',
            'roles'    => ['SuperAdmin', 'RecursosHumanos', 'Secretaria'],
            'permisos' => ['CON_HISTORIAL'],
        ],
        'consulta_vigentes' => [
            'etiqueta' => 'Consentimientos Vigentes / Revocados',
            'roles'    => ['SuperAdmin', 'RecursosHumanos', 'Secretaria'],
            'permisos' => ['CON_VIGENTES'],
        ],

        /* ---------- Reportes ---------- */
        /* REPORTES_EXPORTACION se conserva por compatibilidad. */
        'reporte_cobertura' => [
            'etiqueta' => 'Cobertura y Pendientes',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['REP_COBERTURA', 'REPORTES_EXPORTACION'],
        ],
        'reporte_consentimientos' => [
            'etiqueta' => 'Consentimientos por Finalidad',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['REP_CONSENTIMIENTOS', 'REPORTES_EXPORTACION'],
        ],
        'reporte_titulares' => [
            'etiqueta' => 'Consentimientos por Titular',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['REP_TITULARES', 'REPORTES_EXPORTACION'],
        ],
        'reporte_auditoria' => [
            'etiqueta' => 'Bitácora de Auditoría',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['REP_AUDITORIA'],
        ],
        'reporte_red_educativa' => [
            'etiqueta' => 'Red Educativa Multi-Sede',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['REP_RED_EDUCATIVA', 'REPORTES_EXPORTACION'],
        ],
        'reporte_envios_masivos' => [
            'etiqueta' => 'Efectividad de Envíos Masivos',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['REP_CONSENTIMIENTOS', 'REPORTES_EXPORTACION'],
        ],
        'exportar_csv' => [
            'etiqueta' => 'Exportar CSV',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['REP_EXPORTAR_CSV', 'REPORTES_EXPORTACION'],
        ],

        /**
         * Lectura de los nombres de usuario: la necesita la subpantalla de
         * búsqueda de usuarios de la bitácora de auditoría.
         */
        'usuarios_lectura' => [
            'etiqueta' => 'Consulta de nombres de usuario',
            'roles'    => ['SuperAdmin'],
            'permisos' => ['SEG_USUARIOS', 'REP_AUDITORIA'],
        ],
        /* Indicadores del panel principal: visibles para cualquier sesión. */
        'panel' => [
            'etiqueta'    => 'Panel Principal',
            'roles'       => [],
            'permisos'    => [],
            'autenticado' => true,
        ],
    ];
}

/** Definición de una opción concreta; arreglo vacío si la clave no existe. */
function accesoDe(string $clave): array
{
    static $mapa = null;
    if ($mapa === null) {
        $mapa = accesosSistema();
    }
    return $mapa[$clave] ?? ['roles' => [], 'permisos' => [], 'autenticado' => false];
}

/** Claves agrupadas por sección del menú (para mostrar u ocultar la sección completa). */
function accesosDeSeccion(string $seccion): array
{
    $secciones = [
        'registro'  => ['instituciones', 'empleados', 'estudiantes', 'proveedores',
                        'consentimientos', 'finalidades', 'tipos_dato', 'usuarios', 'roles', 'permisos',
                        'disclaimers', 'correo_configuracion', 'enlaces_verificados',
                        'envio_masivo', 'carga_informacion'],
        'consultas' => ['consulta_buscar_persona', 'consulta_historial', 'consulta_vigentes'],
        'reportes'  => ['reporte_cobertura', 'reporte_red_educativa', 'reporte_consentimientos', 'reporte_titulares',
                        'reporte_auditoria', 'reporte_envios_masivos', 'exportar_csv'],
    ];
    return $secciones[$seccion] ?? [];
}
