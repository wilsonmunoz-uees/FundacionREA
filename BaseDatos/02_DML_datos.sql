-- Inserciones ordenadas por prioridad de dependencias

-- Nivel 1: Tablas Base
INSERT INTO `institucion_educativa` (`id`, `nombre`, `direccion`, `telefono`, `estado`) VALUES
(1, 'Unidad Educativa Particular Cardenal Richard Cushing', '30ava Gómez Rendón y Pedro Vicente Maldonado', '0981180177', 'ACTIVO'),
(2, 'Unidad Educativa Fiscomisional San Juan Bosco', 'Carchi 1213 y Clemente Ballén esquina', '0981174863', 'ACTIVO'),
(3, 'Escuela de Educación Básica Particular Santa Rosa de Lima', 'Perimetral-Isla Trinitaria Coop. 22 de Abril Mz. H Solar 1- 4', '0989866864', 'ACTIVO'),
(4, 'Escuela de Educación Básica Particular Nuestra Señora de Montebello', 'Ciudadela Montebello- Av. 41 Solar 1 calle Séptima', '09990710490', 'ACTIVO'),
(5, 'Unidad Educativa Particular Juan Diego Cuauhtlatoatzin', 'Bastión Popular Bloque 3, Mz. 688-Sl. 1', '0991195180', 'ACTIVO'),
(6, 'Unidad Educativa Particular Bartolomé Garelli', 'Bastión Popular, bloque 7, Mz. 1030 - Sol. 2', '0990184019', 'ACTIVO'),
(7, 'Escuela de Educación Básica Fiscomisional Monseñor Néstor Astudillo Bustamante', 'Coop. Santiago Roldós Mz. 1362 - Sl. 1', '0981171976', 'ACTIVO'),
(8, 'Unidad Educativa Particular “San Francisco Javier”', 'Mapasingue Este: Av. Novena #410, entre la calle Tercera y Cuarta', '0989512550', 'ACTIVO'),
(9, 'Unidad Educativa Particular Dolores Sopeña', 'Ciudadela Sopeña S/N junto a la Parroquia Nuestra Madre de Nazareth', '0981174928', 'ACTIVO'),
(10, 'Unidad Educativa Particular Monseñor Juan María Riera', 'Coop. 7 Lagos Calle Mira y Av. 4ta Sl. 1-7', '0967763305', 'ACTIVO'),
(11, 'Unidad Educativa Particular Santiago de las Praderas', 'Pradera 1 Calle 2 entre B3 Y C1', '0981168447', 'ACTIVO'),
(12, 'Unidad Educativa Particular San Joaquín y Santa Ana', '15ava entre Brasil y Gómez Rendón', '0981172642', 'ACTIVO'),
(13, 'Unidad Educativa Particular La Consolata', 'Fortín Lotización Atlanta, MZ. C Solar 9-21', '0988996287', 'ACTIVO'),
(14, 'Unidad Educativa Particular San Josemaría Escrivá', 'Av. Bombero S/N, vía a La Costa Km 5,5', '0967637036', 'ACTIVO'),
(15, 'Unidad Educativa Particular Cardenal Bernardino Echeverría', 'Av. Bombero S/N, vía a La Costa Km 5,5', '0991168710', 'ACTIVO'),
(16, 'Escuela de Educación Básica Particular Dr. Luis Arzube Arzube', 'Coop. Sergio Toral II etapa sector 58 Regalo de Dios Mz. 5590 Sl. 1', '0939837842', 'ACTIVO'),
(17, 'Unidad Educativa Particular Sagrada Familia de Nazareth', 'Nueva Prosperina Solar 1 Mz 2175', '0981167455', 'ACTIVO'),
(18, 'Escuela de Educación Básica Particular Santa María', 'La 26 y entre la Q y la R', '0988906936', 'ACTIVO'),
(19, 'Unidad Educativa Particular “San Esteban Diácono”', 'Cdla. Guangala Mz. 64 - Sl. 15, C. Calle Esmeraldas y Ernesto Albán', '0981177850', 'ACTIVO'),
(20, 'Escuela de Educación Básica Particular Santo Tomás de Aquino', 'Guasmo Norte Coop. Centro Cívico Mz. 112 - Sl. 1', '0981177850', 'ACTIVO'),
(21, 'Unidad Educativa Particular Las Cumbres', 'Valerio Estacio Etapa 3. Hcda. San Alejo-Monte Sinaí. Realidad de Dios', '0988996647', 'ACTIVO');

INSERT INTO `finalidad` (`FinalidadId`, `Codigo`, `Nombre`, `Descripcion`, `Activo`) VALUES
(1, '1', 'CONSENTIMIENTO DE USO DE DATOS', 'CONSENTIMIENTO DE USO DE DATOS', 'ACTIVO');

INSERT INTO `tipodato` (`TipoDatoId`, `Codigo`, `Nombre`, `Categoria`, `EsSensible`) VALUES
(1, '1', 'Telefono', 'PERSONAL', 'SI'),
(2, '2', 'Email', 'PERSONAL', 'NO'),
(3, '3', 'Direccion', 'PERSONAL', 'SI');

-- Nivel 2: Dependen de Institución
INSERT INTO `persona` (`InstitucionEducativaId`, `PersonaId`, `TipoIdentificacion`, `Identificacion`, `Nombres`, `Apellidos`, `Email`, `Telefono`, `Estado`) VALUES
(1, 1, 'CEDULA', '0999999999', 'Super', 'Administrador', 'wilson.munoz@uees.edu.ec', '09999999', 'ACTIVO');


INSERT INTO `rol` (`InstitucionEducativaId`, `RolId`, `Nombre`, `Descripcion`, `Estado`) VALUES
(1, 1, 'SuperAdmin', 'SuperAdmin', 'ACTIVO'),
(1, 2, 'Seguridades', 'Administración de accesos: usuarios, roles y permisos', 'ACTIVO'),
(1, 3, 'Registro de Datos', 'Mantenimiento de entidades, catálogos y consentimientos', 'ACTIVO'),
(1, 4, 'Consultas', 'Consulta de personas, consentimientos e historial de auditoría', 'ACTIVO'),
(1, 5, 'Reportes', 'Emisión de reportes de cumplimiento y exportación de datos', 'ACTIVO'),
(1, 6, 'Secretaria', 'Rol para secretarias', 'ACTIVO');

INSERT INTO `rol` (`InstitucionEducativaId`, `Nombre`, `Descripcion`, `Estado`)
SELECT i.id, r.Nombre, r.Descripcion, r.Estado
FROM `institucion_educativa` i
CROSS JOIN (
    -- Usamos la institución 2 como plantilla para los roles
    SELECT Nombre, Descripcion, Estado
    FROM `rol`
    WHERE InstitucionEducativaId = 1
) r
WHERE i.id >= 2;

INSERT INTO `permiso` (`InstitucionEducativaId`, `PermisoId`, `Codigo`, `Nombre`, `Modulo`, `Descripcion`, `Estado`) VALUES
(1, 1, 'a001', 'Todo', 'REGISTRO_DATOS', 'Permiso 1', 'ACTIVO'),
(1, 2, 'SEG_USUARIOS', 'Usuarios del Sistema', 'ADMINISTRACION', 'Crear, editar y activar/inactivar cuentas de usuario y sus roles', 'ACTIVO'),
(1, 3, 'SEG_ROLES', 'Roles', 'ADMINISTRACION', 'Administrar roles y los permisos asignados a cada uno', 'ACTIVO'),
(1, 4, 'SEG_PERMISOS', 'Permisos', 'ADMINISTRACION', 'Mantener el catálogo de permisos del sistema', 'ACTIVO'),
(1, 5, 'REG_INSTITUCIONES', 'Instituciones Educativas', 'REGISTRO_DATOS', 'Mantenimiento de instituciones educativas', 'ACTIVO'),
(1, 6, 'REG_PERSONAS', 'Personas', 'REGISTRO_DATOS', 'Directorio general de personas (entidad base del sistema)', 'ACTIVO'),
(1, 7, 'REG_EMPLEADOS', 'Empleados', 'REGISTRO_DATOS', 'Mantenimiento del personal, cargos y departamentos', 'ACTIVO'),
(1, 8, 'REG_ESTUDIANTES', 'Estudiantes', 'REGISTRO_DATOS', 'Matrícula de estudiantes y sus representantes legales', 'ACTIVO'),
(1, 9, 'REG_PROVEEDORES', 'Proveedores', 'REGISTRO_DATOS', 'Directorio de proveedores de bienes y servicios', 'ACTIVO'),
(1, 10, 'REG_CONSENTIMIENTOS', 'Consentimientos', 'REGISTRO_DATOS', 'Registro, modificación, revocación y reactivación de consentimientos', 'ACTIVO'),
(1, 11, 'REG_FINALIDADES', 'Finalidades del Tratamiento', 'REGISTRO_DATOS', 'Catálogo de finalidades del tratamiento de datos', 'ACTIVO'),
(1, 12, 'REG_TIPOS_DATO', 'Tipos de Dato Personal', 'CONSULTA_BUSQUEDAS', 'Catálogo de tipos de dato personal y su condición de sensible', 'ACTIVO'),
(1, 13, 'CON_BUSCAR_PERSONA', 'Buscar Persona', 'CONSULTA_BUSQUEDAS', 'Búsqueda de personas y ficha 360° del titular', 'ACTIVO'),
(1, 14, 'CON_HISTORIAL', 'Historial de Consentimientos', 'CONSULTA_BUSQUEDAS', 'Bitácora de auditoría: creación, modificación, revocación y reactivación', 'ACTIVO'),
(1, 15, 'CON_VIGENTES', 'Consentimientos Vigentes / Revocados', 'CONSULTA_BUSQUEDAS', 'Consulta de consentimientos por finalidad y tipo de dato', 'ACTIVO'),
(1, 16, 'REP_CONSENTIMIENTOS', 'Reporte de Consentimientos', 'REPORTES_EXPORTACION', 'Consentimientos por finalidad, medio y evolución mensual', 'ACTIVO'),
(1, 17, 'REP_DATOS_SENSIBLES', 'Reporte de Datos Sensibles', 'REPORTES_EXPORTACION', 'Tratamiento de categorías especiales de datos personales', 'ACTIVO'),
(1, 18, 'REP_TITULARES', 'Consentimientos por Titular', 'REPORTES_EXPORTACION', 'Detalle de titulares con consentimiento otorgado o revocado, con salida a PDF', 'ACTIVO'),
(1, 19, 'REP_EXPORTAR_CSV', 'Exportar Datos (CSV)', 'REPORTES_EXPORTACION', 'Descarga de la información de la institución en formato CSV', 'ACTIVO'),
(1, 20, 'REP_AUDITORIA', 'Bitácora de Auditoría', 'REPORTES_EXPORTACION', 'Consulta de todos los movimientos registrados en la base de datos', 'ACTIVO'),
(1, 21, 'ADM_DISCLAIMERS', 'Disclaimers de Datos', 'ADMINISTRACION', 'Redacción y vigencia de las políticas de protección de datos que se muestran al dar el consentimiento.', 'ACTIVO'),
(1, 22, 'ADM_CORREO', 'Configuración de Correo', 'ADMINISTRACION', 'Servidor de correo saliente de la institución: dirección, puerto, credenciales y remitente.', 'ACTIVO'),
(1, 23, 'ADM_ENLACES_VERIF', 'Enlaces con Verificación', 'ADMINISTRACION', 'Enlaces públicos de solo consulta que verifican la identidad con un código enviado por correo.', 'ACTIVO'),
(1, 24, 'REG_ENVIO_MASIVO', 'Envío Masivo de Invitaciones', 'REGISTRO_DATOS', 'Enviar a estudiantes, empleados o proveedores el enlace de consentimiento con su documento precargado.', 'ACTIVO');

INSERT INTO `permiso` (`InstitucionEducativaId`, `Codigo`, `Nombre`, `Modulo`, `Descripcion`, `Estado`)
SELECT i.id, p.Codigo, p.Nombre, p.Modulo, p.Descripcion, p.Estado
FROM `institucion_educativa` i
CROSS JOIN (
    SELECT Codigo, Nombre, Modulo, Descripcion, Estado
    FROM `permiso`
    WHERE InstitucionEducativaId = 1
) p
WHERE i.id >= 2;

INSERT INTO `rolpermiso` (`InstitucionEducativaId`, `RolId`, `PermisoId`) VALUES
(1, 1, 1),
(1, 2, 2),
(1, 2, 3),
(1, 2, 4),
(1, 2, 21),
(1, 2, 22),
(1, 2, 23),
(1, 3, 6),
(1, 3, 7),
(1, 3, 8),
(1, 3, 9),
(1, 3, 10),
(1, 3, 11),
(1, 3, 12),
(1, 3, 24),
(1, 4, 13),
(1, 4, 14),
(1, 4, 15),
(1, 5, 16),
(1, 5, 17),
(1, 5, 18),
(1, 5, 19),
(1, 5, 20),
(1, 6, 8);


INSERT INTO `rolpermiso` (`InstitucionEducativaId`, `RolId`, `PermisoId`)
SELECT r.InstitucionEducativaId, r.RolId, p.PermisoId
FROM `rol` r
JOIN `permiso` p ON r.InstitucionEducativaId = p.InstitucionEducativaId
WHERE r.InstitucionEducativaId >= 2
  AND (
    -- Mapeo para el rol SuperAdmin (en caso de que lo agregues a otras instituciones)
    (r.Nombre = 'SuperAdmin' AND p.Codigo = 'a001') OR
    
    -- Mapeo para el rol Seguridades
    (r.Nombre = 'Seguridades' AND p.Codigo IN ('SEG_USUARIOS', 'SEG_ROLES', 'SEG_PERMISOS', 'ADM_ENLACES_VERIF')) OR
    
    -- Mapeo para el rol Registro de Datos
    (r.Nombre = 'Registro de Datos' AND p.Codigo IN ('REG_PERSONAS', 'REG_EMPLEADOS', 'REG_ESTUDIANTES', 'REG_PROVEEDORES', 'REG_CONSENTIMIENTOS', 'REG_FINALIDADES', 'REG_TIPOS_DATO', 'REG_ENVIO_MASIVO')) OR
    
    -- Mapeo para el rol Consultas
    (r.Nombre = 'Consultas' AND p.Codigo IN ('CON_BUSCAR_PERSONA', 'CON_HISTORIAL', 'CON_VIGENTES')) OR
    
    -- Mapeo para el rol Reportes
    (r.Nombre = 'Reportes' AND p.Codigo IN ('REP_CONSENTIMIENTOS', 'REP_DATOS_SENSIBLES', 'REP_TITULARES', 'REP_EXPORTAR_CSV', 'REP_AUDITORIA')) OR
    
    (r.Nombre = 'Secretaria' AND p.Codigo IN ('REG_ESTUDIANTES'))  );


INSERT INTO `disclaimer` (
    `InstitucionEducativaId`, `TipoPersona`, `Version`, `Titulo`, `Texto`, 
    `Estado`, `FechaCreacion`, `FechaVigencia`, `UsuarioId`, `Username`
) VALUES
(1, 'ESTUDIANTE', '1.0', 'Consentimiento para el tratamiento de datos personales', '<p>De conformidad con la <strong>Ley Org&aacute;nica de Protecci&oacute;n de Datos Personales</strong> y su reglamento...</p>', 'ACTIVO', '2026-08-24 20:58:55', '2026-08-24 20:58:55', NULL, 'instalacion'),
(1, 'EMPLEADO', '1.0', 'Consentimiento para el tratamiento de datos personales', '<p>De conformidad con la <strong>Ley Org&aacute;nica de Protecci&oacute;n de Datos Personales</strong> y su reglamento...</p>', 'ACTIVO', '2026-08-24 20:58:55', '2026-08-24 20:58:55', NULL, 'instalacion'),
(1, 'PROVEEDOR', '1.0', 'Consentimiento para el tratamiento de datos personales', '<p>De conformidad con la <strong>Ley Org&aacute;nica de Protecci&oacute;n de Datos Personales</strong> y su reglamento...</p>', 'ACTIVO', '2026-08-24 20:58:55', '2026-08-24 20:58:55', NULL, 'instalacion');

-- 2. Inserción dinámica para las demás Instituciones (ID > 1) 
-- basadas en el contenido de la tabla `institucion_educativa`
INSERT INTO `disclaimer` (
    `InstitucionEducativaId`, `TipoPersona`, `Version`, `Titulo`, `Texto`, 
    `Estado`, `FechaCreacion`, `FechaVigencia`, `UsuarioId`, `Username`
)
SELECT 
    ie.id AS InstitucionEducativaId,
    roles.TipoPersona,
    '1.0' AS Version,
    'Consentimiento para el tratamiento de datos personales' AS Titulo,
    '<p>De conformidad con la <strong>Ley Org&aacute;nica de Protecci&oacute;n de Datos Personales</strong> y su reglamento...</p>' AS Texto,
    'ACTIVO' AS Estado,
    '2026-08-24 20:58:55' AS FechaCreacion,
    '2026-08-24 20:58:55' AS FechaVigencia,
    NULL AS UsuarioId,
    'instalacion' AS Username
FROM `institucion_educativa` ie
CROSS JOIN (
    SELECT 'ESTUDIANTE' AS TipoPersona UNION ALL
    SELECT 'EMPLEADO' AS TipoPersona UNION ALL
    SELECT 'PROVEEDOR' AS TipoPersona
) AS roles
WHERE ie.id > 1;

INSERT INTO `usuario` (`InstitucionEducativaId`, `PersonaId`, `UsuarioId`, `Username`, `PasswordHash`, `Email`, `UltimoAcceso`, `Estado`) VALUES
(1, 1, 1, 'admin', '$2y$10$7funCtrXbDSyub7gQ0knNe1N.AJ9vlo7htX3JVEUKEFE59dwprDqS', NULL, '2026-08-25 20:21:41', 'ACTIVO');


-- Nivel 4: Dependen de Usuario y otras de Nivel 3
INSERT INTO `usuariorol` (`InstitucionEducativaId`, `UsuarioId`, `RolId`) VALUES
(1, 1, 1);

-- Instituciones en las que puede entrar cada cuenta.
--
-- Todas arrancan con la suya y nada más: dar acceso a otra institución es dar
-- acceso a los datos personales de otra comunidad educativa, y eso se concede
-- una por una desde «Usuarios del Sistema», no de salida. El SuperAdmin no
-- necesita fila: entra en cualquier institución activa por su rol.
INSERT INTO `usuario_institucion` (`InstitucionEducativaId`, `UsuarioId`)
SELECT u.`InstitucionEducativaId`, u.`UsuarioId` FROM `usuario` u
ON DUPLICATE KEY UPDATE `UsuarioId` = `usuario_institucion`.`UsuarioId`;

COMMIT;