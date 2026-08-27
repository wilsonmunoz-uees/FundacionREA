-- =============================================================================
--  02_DML_datos.sql
--  Sistema de Gestión de Protección de Datos
--  Red Educativa Arquidiocesana (REA)
-- -----------------------------------------------------------------------------
--  DATOS DE ARRANQUE DE LA BASE DE DATOS (DML)
--
--  Motor: MySQL 5.7 o superior / MariaDB 10.3 o superior.
--
--  Este archivo reúne TODOS los datos que el sistema necesita para funcionar:
--  la institución, los catálogos, los permisos, los roles con sus asignaciones,
--  los textos de política y la cuenta de administrador. Sustituye a los scripts
--  sueltos que se fueron generando durante el desarrollo.
--
--  Está ordenado por propósito:
--
--     1. Preparación
--     2. Institución educativa
--     3. Catálogos del tratamiento de datos
--     4. Permisos del sistema, por módulo
--     5. Roles
--     6. Asignación de permisos a cada rol
--     7. Disclaimers de política de datos
--     8. Cuenta de administrador
--     9. Usuarios de prueba (OPCIONAL — elimínelos en producción)
--    10. Verificación
--
--  TODO EL SCRIPT ES IDEMPOTENTE: puede ejecutarlo las veces que quiera sin
--  duplicar nada. Cada inserción comprueba antes si el registro ya existe, y
--  las referencias se resuelven por código o por nombre, nunca por un número
--  fijo, de modo que no depende de los valores del AUTO_INCREMENT.
--
--  Ejecución (después de 01_DDL_estructura.sql):
--     mysql -u USUARIO -p < 02_DML_datos.sql
-- =============================================================================


-- =============================================================================
--  1. PREPARACIÓN
-- =============================================================================

USE `ezyro_42650191_protecciondatos`;

-- Institución sobre la que se cargan los datos. Para preparar una segunda
-- institución, cambie este número y vuelva a ejecutar el script.
SET @institucion := 1;


-- =============================================================================
--  2. INSTITUCIÓN EDUCATIVA
-- -----------------------------------------------------------------------------
--  Ajuste el nombre, la dirección y el teléfono a los datos reales antes de
--  poner el sistema en producción.
-- =============================================================================

INSERT INTO `institucion_educativa` (`id`, `nombre`, `direccion`, `telefono`, `nombre_logotipo`, `estado`)
SELECT * FROM (
    SELECT @institucion AS id, 'Escuela 1' AS nombre, 'Direccion 1' AS direccion,
           '09999999' AS telefono, NULL AS logo, 'ACTIVO' AS estado
) AS n
WHERE NOT EXISTS (SELECT 1 FROM `institucion_educativa` i WHERE i.id = @institucion);


-- =============================================================================
--  3. CATÁLOGOS DEL TRATAMIENTO DE DATOS
-- -----------------------------------------------------------------------------
--  Son comunes a toda la red, no dependen de la institución.
-- =============================================================================

-- --- Finalidades del tratamiento ---------------------------------------------
-- Debe existir al menos una ACTIVA: es la que se registra cuando alguien da su
-- consentimiento desde los enlaces públicos.
INSERT INTO `finalidad` (`Codigo`, `Nombre`, `Descripcion`, `Activo`)
SELECT * FROM (
    SELECT '1' AS c, 'CONSENTIMIENTO DE USO DE DATOS' AS n,
           'Tratamiento de datos personales con fines educativos, administrativos y de comunicación institucional.' AS d,
           'ACTIVO' AS a
) AS x
WHERE NOT EXISTS (SELECT 1 FROM `finalidad` f WHERE f.Codigo = x.c);

-- --- Tipos de dato personal ---------------------------------------------------
-- EsSensible = SI marca los datos que la ley protege de forma reforzada; el
-- sistema los resalta en la pantalla pública y en los reportes.
INSERT INTO `tipodato` (`Codigo`, `Nombre`, `Categoria`, `EsSensible`)
SELECT * FROM (          SELECT '1' AS c, 'Telefono'  AS n, 'PERSONAL' AS cat, 'SI' AS s
    UNION ALL SELECT '2',       'Email',              'PERSONAL',       'NO'
    UNION ALL SELECT '3',       'Direccion',          'PERSONAL',       'SI'
) AS x
WHERE NOT EXISTS (SELECT 1 FROM `tipodato` t WHERE t.Codigo = x.c);


-- =============================================================================
--  4. PERMISOS DEL SISTEMA
-- -----------------------------------------------------------------------------
--  Cada permiso corresponde a una opción del menú. La aplicación los consulta
--  por su Código; el mapa de qué permiso abre cada opción está en
--  includes/accesos.php.
--
--  Un usuario entra a una opción si es SuperAdmin, o tiene alguno de los roles
--  que la abren, o alguno de estos permisos.
-- =============================================================================

INSERT INTO `permiso` (`InstitucionEducativaId`, `Codigo`, `Nombre`, `Modulo`, `Descripcion`, `Estado`)
SELECT * FROM (

    -- --- Módulo ADMINISTRACIÓN: accesos, parámetros y correo ------------------
              SELECT @institucion AS i, 'SEG_USUARIOS'        AS c, 'Usuarios del Sistema'          AS n, 'ADMINISTRACION'       AS m, 'Alta, cambio y baja de las cuentas de acceso.'                                   AS d, 'ACTIVO' AS e
    UNION ALL SELECT @institucion,      'SEG_ROLES',               'Roles',                              'ADMINISTRACION',            'Definición de roles y de los permisos que otorgan.'
                                                                                                                                                                                                                            , 'ACTIVO'
    UNION ALL SELECT @institucion,      'SEG_PERMISOS',            'Permisos',                           'ADMINISTRACION',            'Catálogo de permisos del sistema.',                                                    'ACTIVO'
    UNION ALL SELECT @institucion,      'ADM_DISCLAIMERS',         'Disclaimers de Datos',               'ADMINISTRACION',            'Redacción y vigencia de las políticas de protección de datos.',                        'ACTIVO'
    UNION ALL SELECT @institucion,      'ADM_CORREO',              'Configuración de Correo',            'ADMINISTRACION',            'Servidor de correo saliente de la institución.',                                       'ACTIVO'
    UNION ALL SELECT @institucion,      'ADM_ENLACES_VERIF',       'Enlaces de Consentimiento',          'ADMINISTRACION',            'Enlaces públicos de solo consulta que verifican la identidad con un código enviado por correo.', 'ACTIVO'

    -- --- Módulo REGISTRO DE DATOS: mantenimientos -----------------------------
    UNION ALL SELECT @institucion,      'REG_INSTITUCIONES',       'Instituciones Educativas',           'REGISTRO_DATOS',            'Mantenimiento de las instituciones de la red.',                                        'ACTIVO'
    UNION ALL SELECT @institucion,      'REG_PERSONAS',            'Personas',                           'REGISTRO_DATOS',            'Directorio general de personas.',                                                      'ACTIVO'
    UNION ALL SELECT @institucion,      'REG_EMPLEADOS',           'Empleados',                          'REGISTRO_DATOS',            'Personal de la institución.',                                                          'ACTIVO'
    UNION ALL SELECT @institucion,      'REG_ESTUDIANTES',         'Estudiantes',                        'REGISTRO_DATOS',            'Alumnos matriculados y sus representantes.',                                           'ACTIVO'
    UNION ALL SELECT @institucion,      'REG_PROVEEDORES',         'Proveedores',                        'REGISTRO_DATOS',            'Proveedores de bienes y servicios.',                                                   'ACTIVO'
    UNION ALL SELECT @institucion,      'REG_CONSENTIMIENTOS',     'Consentimientos',                    'REGISTRO_DATOS',            'Registro y control de consentimientos.',                                               'ACTIVO'
    UNION ALL SELECT @institucion,      'REG_FINALIDADES',         'Finalidades del Tratamiento',        'REGISTRO_DATOS',            'Catálogo de finalidades.',                                                             'ACTIVO'
    UNION ALL SELECT @institucion,      'REG_TIPOS_DATO',          'Tipos de Dato Personal',             'REGISTRO_DATOS',            'Catálogo de tipos de dato personal.',                                                  'ACTIVO'

    -- --- Módulo CONSULTAS ------------------------------------------------------
    UNION ALL SELECT @institucion,      'CON_BUSCAR_PERSONA',      'Buscar Persona',                     'CONSULTA_BUSQUEDAS',        'Búsqueda de personas y su ficha completa.',                                            'ACTIVO'
    UNION ALL SELECT @institucion,      'CON_HISTORIAL',           'Historial de Consentimientos',       'CONSULTA_BUSQUEDAS',        'Traza de cambios de los consentimientos.',                                             'ACTIVO'
    UNION ALL SELECT @institucion,      'CON_VIGENTES',            'Consentimientos Vigentes',           'CONSULTA_BUSQUEDAS',        'Consentimientos vigentes y revocados.',                                                'ACTIVO'

    -- --- Módulo REPORTES -------------------------------------------------------
    UNION ALL SELECT @institucion,      'REP_CONSENTIMIENTOS',     'Reporte de Consentimientos',         'REPORTES_EXPORTACION',      'Consentimientos por finalidad.',                                                       'ACTIVO'
    UNION ALL SELECT @institucion,      'REP_DATOS_SENSIBLES',     'Reporte de Datos Sensibles',         'REPORTES_EXPORTACION',      'Autorizaciones sobre datos sensibles.',                                                'ACTIVO'
    UNION ALL SELECT @institucion,      'REP_TITULARES',           'Consentimientos por Titular',        'REPORTES_EXPORTACION',      'Detalle por titular, con exportación a PDF.',                                          'ACTIVO'
    UNION ALL SELECT @institucion,      'REP_AUDITORIA',           'Bitácora de Auditoría',              'REPORTES_EXPORTACION',      'Todos los movimientos registrados en la base de datos.',                               'ACTIVO'
    UNION ALL SELECT @institucion,      'REP_EXPORTAR_CSV',        'Exportar Datos (CSV)',               'REPORTES_EXPORTACION',      'Descarga de la información en formato CSV.',                                           'ACTIVO'
) AS n
WHERE NOT EXISTS (
    SELECT 1 FROM `permiso` p
     WHERE p.Codigo = n.c AND p.InstitucionEducativaId = @institucion
);

-- Nombres que cambiaron después de la versión inicial. La inserción de arriba
-- respeta lo que ya existe —es idempotente—, de modo que una base instalada
-- conservaría el nombre viejo en la pantalla de Permisos y en Roles. Se corrige
-- por código, que es lo que nunca cambia; las asignaciones quedan intactas.
UPDATE `permiso`
   SET `Nombre`      = 'Enlaces de Consentimiento',
       `Descripcion` = 'Enlaces públicos de solo consulta que verifican la identidad con un código enviado por correo.'
 WHERE `Codigo` = 'ADM_ENLACES_VERIF'
   AND `InstitucionEducativaId` = @institucion;

UPDATE `permiso`
   SET `Descripcion` = 'Servidor de correo saliente de la institución.'
 WHERE `Codigo` = 'ADM_CORREO'
   AND `InstitucionEducativaId` = @institucion;


-- =============================================================================
--  5. ROLES
-- -----------------------------------------------------------------------------
--  SuperAdmin es especial: no depende de permisos, abre todas las opciones y
--  puede iniciar sesión en cualquier institución. Los otros cuatro se apoyan
--  en los permisos que se asignan en la sección siguiente.
-- =============================================================================

INSERT INTO `rol` (`InstitucionEducativaId`, `Nombre`, `Descripcion`, `Estado`)
SELECT * FROM (
              SELECT @institucion AS i, 'SuperAdmin'        AS n, 'Acceso total al sistema y a todas las instituciones'      AS d, 'ACTIVO' AS e
    UNION ALL SELECT @institucion,      'Seguridades',           'Administración de accesos: usuarios, roles y permisos',         'ACTIVO'
    UNION ALL SELECT @institucion,      'Registro de Datos',     'Mantenimiento de entidades, catálogos y consentimientos',       'ACTIVO'
    UNION ALL SELECT @institucion,      'Consultas',             'Consulta de personas, consentimientos e historial',             'ACTIVO'
    UNION ALL SELECT @institucion,      'Reportes',              'Emisión de reportes de cumplimiento y exportación de datos',    'ACTIVO'
) AS n
WHERE NOT EXISTS (
    SELECT 1 FROM `rol` r
     WHERE r.Nombre = n.n AND r.InstitucionEducativaId = @institucion
);


-- =============================================================================
--  6. ASIGNACIÓN DE PERMISOS A CADA ROL
-- -----------------------------------------------------------------------------
--  Los permisos se resuelven por su Código y los roles por su Nombre, de modo
--  que la asignación funciona sea cual sea el identificador que les tocó.
-- =============================================================================

-- --- Rol Seguridades: administración de accesos ------------------------------
SET @rol := (SELECT RolId FROM `rol` WHERE Nombre = 'Seguridades' AND InstitucionEducativaId = @institucion LIMIT 1);

INSERT INTO `rolpermiso` (`InstitucionEducativaId`, `RolId`, `PermisoId`)
SELECT @institucion, @rol, p.PermisoId
  FROM `permiso` p
 WHERE @rol IS NOT NULL
   AND p.InstitucionEducativaId = @institucion
   AND p.Codigo IN ('SEG_USUARIOS', 'SEG_ROLES', 'SEG_PERMISOS')
   AND NOT EXISTS (
       SELECT 1 FROM `rolpermiso` rp
        WHERE rp.InstitucionEducativaId = @institucion AND rp.RolId = @rol AND rp.PermisoId = p.PermisoId
   );

-- --- Rol Registro de Datos: mantenimientos -----------------------------------
SET @rol := (SELECT RolId FROM `rol` WHERE Nombre = 'Registro de Datos' AND InstitucionEducativaId = @institucion LIMIT 1);

INSERT INTO `rolpermiso` (`InstitucionEducativaId`, `RolId`, `PermisoId`)
SELECT @institucion, @rol, p.PermisoId
  FROM `permiso` p
 WHERE @rol IS NOT NULL
   AND p.InstitucionEducativaId = @institucion
   AND p.Codigo IN ('REG_INSTITUCIONES', 'REG_PERSONAS', 'REG_EMPLEADOS', 'REG_ESTUDIANTES',
                    'REG_PROVEEDORES', 'REG_CONSENTIMIENTOS', 'REG_FINALIDADES', 'REG_TIPOS_DATO')
   AND NOT EXISTS (
       SELECT 1 FROM `rolpermiso` rp
        WHERE rp.InstitucionEducativaId = @institucion AND rp.RolId = @rol AND rp.PermisoId = p.PermisoId
   );

-- --- Rol Consultas: solo el menú Consultas ------------------------------------
SET @rol := (SELECT RolId FROM `rol` WHERE Nombre = 'Consultas' AND InstitucionEducativaId = @institucion LIMIT 1);

INSERT INTO `rolpermiso` (`InstitucionEducativaId`, `RolId`, `PermisoId`)
SELECT @institucion, @rol, p.PermisoId
  FROM `permiso` p
 WHERE @rol IS NOT NULL
   AND p.InstitucionEducativaId = @institucion
   AND p.Codigo IN ('CON_BUSCAR_PERSONA', 'CON_HISTORIAL', 'CON_VIGENTES')
   AND NOT EXISTS (
       SELECT 1 FROM `rolpermiso` rp
        WHERE rp.InstitucionEducativaId = @institucion AND rp.RolId = @rol AND rp.PermisoId = p.PermisoId
   );

-- --- Rol Reportes: solo el menú Reportes --------------------------------------
SET @rol := (SELECT RolId FROM `rol` WHERE Nombre = 'Reportes' AND InstitucionEducativaId = @institucion LIMIT 1);

INSERT INTO `rolpermiso` (`InstitucionEducativaId`, `RolId`, `PermisoId`)
SELECT @institucion, @rol, p.PermisoId
  FROM `permiso` p
 WHERE @rol IS NOT NULL
   AND p.InstitucionEducativaId = @institucion
   AND p.Codigo IN ('REP_CONSENTIMIENTOS', 'REP_DATOS_SENSIBLES', 'REP_TITULARES',
                    'REP_AUDITORIA', 'REP_EXPORTAR_CSV')
   AND NOT EXISTS (
       SELECT 1 FROM `rolpermiso` rp
        WHERE rp.InstitucionEducativaId = @institucion AND rp.RolId = @rol AND rp.PermisoId = p.PermisoId
   );

-- Los permisos de administración se entregan al rol administrativo del sistema:
-- así un usuario administrativo —sin ser SuperAdmin— puede publicar los enlaces
-- de consentimiento y los enlaces con verificación.
SELECT @rol := RolId FROM `rol`
 WHERE InstitucionEducativaId = @institucion AND Nombre = 'Seguridades' LIMIT 1;

INSERT INTO `rolpermiso` (`InstitucionEducativaId`, `RolId`, `PermisoId`)
SELECT @institucion, @rol, p.PermisoId
  FROM `permiso` p
 WHERE p.InstitucionEducativaId = @institucion
   AND p.Codigo IN ('ADM_ENLACES_VERIF')
   AND NOT EXISTS (
       SELECT 1 FROM `rolpermiso` rp
        WHERE rp.InstitucionEducativaId = @institucion AND rp.RolId = @rol AND rp.PermisoId = p.PermisoId
   );

-- Nota: los permisos ADM_DISCLAIMERS y ADM_CORREO no se asignan a ningún rol a
-- propósito. De fábrica esas dos opciones solo las ve el SuperAdmin; para
-- entregárselas a otro rol, asígneselos desde el módulo Roles.


-- =============================================================================
--  7. DISCLAIMERS DE POLÍTICA DE DATOS
-- -----------------------------------------------------------------------------
--  Un texto por tipo de persona, ya vigente. Son los que ve quien abre los
--  enlaces públicos de consentimiento.
--
--  Edítelos desde Administración › Disclaimers de Datos. Para cambiar un texto
--  conviene crear una versión nueva y activarla, en lugar de modificar la
--  vigente: el consentimiento guarda qué versión aceptó cada persona.
-- =============================================================================

-- --- Estudiantes ---------------------------------------------------------------
INSERT INTO `disclaimer`
    (`InstitucionEducativaId`, `TipoPersona`, `Version`, `Titulo`, `Texto`, `Estado`, `FechaCreacion`, `FechaVigencia`, `Username`)
SELECT * FROM (
    SELECT @institucion AS i, 'ESTUDIANTE' AS t, '1.0' AS v,
           'Consentimiento para el tratamiento de datos personales' AS ti,
           CONCAT(
             '<p>De conformidad con la <strong>Ley Org&aacute;nica de Protecci&oacute;n de Datos Personales</strong> ',
             'y su reglamento, la instituci&oacute;n le informa que los datos personales del estudiante son tratados ',
             'con fines educativos, administrativos, acad&eacute;micos, de comunicaci&oacute;n institucional y de ',
             'cumplimiento de obligaciones legales.</p>',
             '<p>El tratamiento se realiza bajo los principios de licitud, lealtad, transparencia, minimizaci&oacute;n, ',
             'exactitud, seguridad y confidencialidad. Los datos no ser&aacute;n cedidos a terceros ajenos a la ',
             'instituci&oacute;n, salvo obligaci&oacute;n legal o requerimiento de autoridad competente, y se ',
             'conservar&aacute;n &uacute;nicamente durante el tiempo necesario para cumplir la finalidad que los justifica.</p>',
             '<p>Usted puede ejercer en cualquier momento los derechos de <strong>acceso, rectificaci&oacute;n, ',
             'actualizaci&oacute;n, eliminaci&oacute;n, oposici&oacute;n y portabilidad</strong>, y a no ser objeto de ',
             'decisiones automatizadas, dirigi&eacute;ndose a la instituci&oacute;n por los canales habituales de contacto.</p>',
             '<p><strong>Su decisi&oacute;n es libre.</strong> Otorgar el consentimiento no condiciona la prestaci&oacute;n ',
             'del servicio educativo, y su revocaci&oacute;n no tiene efectos retroactivos sobre los tratamientos ya ',
             'realizados de forma l&iacute;cita.</p>'
           ) AS x, 'ACTIVO' AS e, NOW() AS f1, NOW() AS f2, 'instalacion' AS u
) AS n
WHERE NOT EXISTS (
    SELECT 1 FROM `disclaimer` d
     WHERE d.InstitucionEducativaId = @institucion AND d.TipoPersona = 'ESTUDIANTE'
);

-- --- Empleados -----------------------------------------------------------------
INSERT INTO `disclaimer`
    (`InstitucionEducativaId`, `TipoPersona`, `Version`, `Titulo`, `Texto`, `Estado`, `FechaCreacion`, `FechaVigencia`, `Username`)
SELECT * FROM (
    SELECT @institucion AS i, 'EMPLEADO' AS t, '1.0' AS v,
           'Consentimiento para el tratamiento de datos personales' AS ti,
           CONCAT(
             '<p>De conformidad con la <strong>Ley Org&aacute;nica de Protecci&oacute;n de Datos Personales</strong> ',
             'y su reglamento, la instituci&oacute;n le informa que sus datos personales son tratados con fines ',
             'administrativos, laborales, de comunicaci&oacute;n institucional y de cumplimiento de obligaciones legales.</p>',
             '<p>Comprende los datos recabados con ocasi&oacute;n de la relaci&oacute;n laboral: identificaci&oacute;n, ',
             'contacto, formaci&oacute;n acad&eacute;mica, cargo y los que resulten necesarios para la gesti&oacute;n del personal.</p>',
             '<p>El tratamiento se realiza bajo los principios de licitud, lealtad, transparencia, minimizaci&oacute;n, ',
             'exactitud, seguridad y confidencialidad. Los datos no ser&aacute;n cedidos a terceros ajenos a la ',
             'instituci&oacute;n, salvo obligaci&oacute;n legal o requerimiento de autoridad competente.</p>',
             '<p>Usted puede ejercer en cualquier momento los derechos de <strong>acceso, rectificaci&oacute;n, ',
             'actualizaci&oacute;n, eliminaci&oacute;n, oposici&oacute;n y portabilidad</strong>, dirigi&eacute;ndose a ',
             'la instituci&oacute;n por los canales habituales de contacto.</p>'
           ) AS x, 'ACTIVO' AS e, NOW() AS f1, NOW() AS f2, 'instalacion' AS u
) AS n
WHERE NOT EXISTS (
    SELECT 1 FROM `disclaimer` d
     WHERE d.InstitucionEducativaId = @institucion AND d.TipoPersona = 'EMPLEADO'
);

-- --- Proveedores ---------------------------------------------------------------
INSERT INTO `disclaimer`
    (`InstitucionEducativaId`, `TipoPersona`, `Version`, `Titulo`, `Texto`, `Estado`, `FechaCreacion`, `FechaVigencia`, `Username`)
SELECT * FROM (
    SELECT @institucion AS i, 'PROVEEDOR' AS t, '1.0' AS v,
           'Consentimiento para el tratamiento de datos personales' AS ti,
           CONCAT(
             '<p>De conformidad con la <strong>Ley Org&aacute;nica de Protecci&oacute;n de Datos Personales</strong> ',
             'y su reglamento, la instituci&oacute;n le informa que los datos personales asociados al proveedor son ',
             'tratados con fines administrativos, contractuales, contables y de cumplimiento de obligaciones legales.</p>',
             '<p>Comprende los datos de la persona natural de contacto y los recabados con ocasi&oacute;n de la ',
             'relaci&oacute;n comercial: identificaci&oacute;n, contacto, facturaci&oacute;n y cumplimiento contractual.</p>',
             '<p>El tratamiento se realiza bajo los principios de licitud, lealtad, transparencia, minimizaci&oacute;n, ',
             'exactitud, seguridad y confidencialidad. Los datos no ser&aacute;n cedidos a terceros ajenos a la ',
             'instituci&oacute;n, salvo obligaci&oacute;n legal o requerimiento de autoridad competente.</p>',
             '<p>Usted puede ejercer en cualquier momento los derechos de <strong>acceso, rectificaci&oacute;n, ',
             'actualizaci&oacute;n, eliminaci&oacute;n, oposici&oacute;n y portabilidad</strong>, dirigi&eacute;ndose a ',
             'la instituci&oacute;n por los canales habituales de contacto.</p>'
           ) AS x, 'ACTIVO' AS e, NOW() AS f1, NOW() AS f2, 'instalacion' AS u
) AS n
WHERE NOT EXISTS (
    SELECT 1 FROM `disclaimer` d
     WHERE d.InstitucionEducativaId = @institucion AND d.TipoPersona = 'PROVEEDOR'
);


-- =============================================================================
--  8. CUENTA DE ADMINISTRADOR
-- -----------------------------------------------------------------------------
--  Crea la persona, la cuenta `admin` y le asigna el rol SuperAdmin.
--
--  Contraseña inicial:  Clave2026*
--  CÁMBIELA en cuanto entre por primera vez, desde Usuarios del Sistema.
--
--  El hash está generado con password_hash() de PHP (bcrypt, coste 12). Para
--  usar otra contraseña, genere su hash con:
--      php -r "echo password_hash('SU CLAVE', PASSWORD_DEFAULT);"
-- =============================================================================

SET @hash := '$2y$12$ES0g6M./m/reuY406ikOWeIfKYAvasak/Jfqw2Yv/mx00n/dB22Xa';

-- --- Persona del administrador ------------------------------------------------
INSERT INTO `persona` (`InstitucionEducativaId`, `TipoIdentificacion`, `Identificacion`, `Nombres`, `Apellidos`, `Email`, `Telefono`, `Estado`)
SELECT * FROM (
    SELECT @institucion AS ins, 'CEDULA' AS t, '0999999999' AS i, 'Super' AS n, 'Administrador' AS a,
           'admin@rea.edu.ec' AS e, '09999999' AS tel, 'ACTIVO' AS es
) AS x
WHERE NOT EXISTS (
    SELECT 1 FROM `persona` p
     WHERE p.InstitucionEducativaId = @institucion AND p.Identificacion = '0999999999'
);

-- --- Cuenta de acceso ----------------------------------------------------------
INSERT INTO `usuario` (`InstitucionEducativaId`, `PersonaId`, `Username`, `PasswordHash`, `Email`, `Estado`)
SELECT @institucion, p.PersonaId, 'admin', @hash, p.Email, 'ACTIVO'
  FROM `persona` p
 WHERE p.InstitucionEducativaId = @institucion
   AND p.Identificacion = '0999999999'
   AND NOT EXISTS (SELECT 1 FROM `usuario` u WHERE u.Username = 'admin');

-- --- Rol SuperAdmin ------------------------------------------------------------
INSERT INTO `usuariorol` (`InstitucionEducativaId`, `UsuarioId`, `RolId`)
SELECT @institucion, u.UsuarioId, r.RolId
  FROM `usuario` u
  JOIN `rol` r ON r.Nombre = 'SuperAdmin' AND r.InstitucionEducativaId = @institucion
 WHERE u.Username = 'admin'
   AND NOT EXISTS (
       SELECT 1 FROM `usuariorol` ur
        WHERE ur.InstitucionEducativaId = @institucion AND ur.UsuarioId = u.UsuarioId AND ur.RolId = r.RolId
   );


-- =============================================================================
--  9. USUARIOS DE PRUEBA  (OPCIONAL)
-- -----------------------------------------------------------------------------
--  Una cuenta por rol, para comprobar que cada uno ve exactamente lo que debe.
--  Todas usan la contraseña  Clave2026*
--
--      seguridades  → Usuarios, Roles y Permisos
--      registro     → Mantenimientos de entidades y catálogos
--      consultas    → Solo el menú Consultas
--      reportes     → Solo el menú Reportes
--
--  ELIMÍNELAS EN PRODUCCIÓN:
--      DELETE FROM usuario WHERE Username IN ('seguridades','registro','consultas','reportes');
--
--  Si no las necesita, no ejecute esta sección.
-- =============================================================================

-- --- Personas de las cuentas de prueba ----------------------------------------
INSERT INTO `persona` (`InstitucionEducativaId`, `TipoIdentificacion`, `Identificacion`, `Nombres`, `Apellidos`, `Email`, `Estado`)
SELECT * FROM (
              SELECT @institucion AS ins, 'CEDULA' AS t, 'USR-SEG-001' AS i, 'Usuario' AS n, 'Seguridades'       AS a, 'seguridades@rea.edu.ec' AS e, 'ACTIVO' AS es
    UNION ALL SELECT @institucion,        'CEDULA',      'USR-REG-001',      'Usuario',      'Registro de Datos',      'registro@rea.edu.ec',         'ACTIVO'
    UNION ALL SELECT @institucion,        'CEDULA',      'USR-CON-001',      'Usuario',      'Consultas',              'consultas@rea.edu.ec',        'ACTIVO'
    UNION ALL SELECT @institucion,        'CEDULA',      'USR-REP-001',      'Usuario',      'Reportes',               'reportes@rea.edu.ec',         'ACTIVO'
) AS x
WHERE NOT EXISTS (
    SELECT 1 FROM `persona` p
     WHERE p.InstitucionEducativaId = @institucion AND p.Identificacion = x.i
);

-- --- Cuentas de acceso ---------------------------------------------------------
INSERT INTO `usuario` (`InstitucionEducativaId`, `PersonaId`, `Username`, `PasswordHash`, `Email`, `Estado`)
SELECT @institucion, p.PersonaId, x.usuario, @hash, p.Email, 'ACTIVO'
  FROM (
              SELECT 'USR-SEG-001' AS ident, 'seguridades' AS usuario
    UNION ALL SELECT 'USR-REG-001',           'registro'
    UNION ALL SELECT 'USR-CON-001',           'consultas'
    UNION ALL SELECT 'USR-REP-001',           'reportes'
  ) AS x
  JOIN `persona` p ON p.Identificacion = x.ident
                  AND p.InstitucionEducativaId = @institucion
 WHERE NOT EXISTS (SELECT 1 FROM `usuario` u WHERE u.Username = x.usuario);

-- --- Asignación de su rol ------------------------------------------------------
INSERT INTO `usuariorol` (`InstitucionEducativaId`, `UsuarioId`, `RolId`)
SELECT @institucion, u.UsuarioId, r.RolId
  FROM (
              SELECT 'seguridades' AS usuario, 'Seguridades'       AS rol
    UNION ALL SELECT 'registro',               'Registro de Datos'
    UNION ALL SELECT 'consultas',              'Consultas'
    UNION ALL SELECT 'reportes',               'Reportes'
  ) AS x
  JOIN `usuario` u ON u.Username = x.usuario
  JOIN `rol` r     ON r.Nombre = x.rol AND r.InstitucionEducativaId = @institucion
 WHERE NOT EXISTS (
     SELECT 1 FROM `usuariorol` ur
      WHERE ur.InstitucionEducativaId = @institucion AND ur.UsuarioId = u.UsuarioId AND ur.RolId = r.RolId
 );


-- =============================================================================
--  10. VERIFICACIÓN
-- =============================================================================

SELECT 'Institución'  AS Concepto, COUNT(*) AS Registros FROM `institucion_educativa` WHERE id = @institucion
UNION ALL SELECT 'Finalidades',    COUNT(*) FROM `finalidad`
UNION ALL SELECT 'Tipos de dato',  COUNT(*) FROM `tipodato`
UNION ALL SELECT 'Permisos',       COUNT(*) FROM `permiso`     WHERE InstitucionEducativaId = @institucion
UNION ALL SELECT 'Roles',          COUNT(*) FROM `rol`         WHERE InstitucionEducativaId = @institucion
UNION ALL SELECT 'Disclaimers',    COUNT(*) FROM `disclaimer`  WHERE InstitucionEducativaId = @institucion
UNION ALL SELECT 'Usuarios',       COUNT(*) FROM `usuario`     WHERE InstitucionEducativaId = @institucion;

-- Permisos que otorga cada rol
SELECT r.Nombre AS Rol, COUNT(rp.PermisoId) AS Permisos,
       GROUP_CONCAT(p.Codigo ORDER BY p.Codigo SEPARATOR ', ') AS Detalle
  FROM `rol` r
  LEFT JOIN `rolpermiso` rp ON rp.RolId = r.RolId AND rp.InstitucionEducativaId = r.InstitucionEducativaId
  LEFT JOIN `permiso` p     ON p.PermisoId = rp.PermisoId
 WHERE r.InstitucionEducativaId = @institucion
 GROUP BY r.RolId, r.Nombre
 ORDER BY r.Nombre;

-- Disclaimer vigente por tipo de persona
SELECT TipoPersona, Version, Estado, FechaVigencia
  FROM `disclaimer`
 WHERE InstitucionEducativaId = @institucion AND Estado = 'ACTIVO'
 ORDER BY TipoPersona;

-- Cuentas creadas y su rol
SELECT u.Username, p.Apellidos, r.Nombre AS Rol, u.Estado
  FROM `usuario` u
  JOIN `persona` p    ON p.PersonaId = u.PersonaId
  LEFT JOIN `usuariorol` ur ON ur.UsuarioId = u.UsuarioId
  LEFT JOIN `rol` r         ON r.RolId = ur.RolId
 WHERE u.InstitucionEducativaId = @institucion
 ORDER BY u.Username;
