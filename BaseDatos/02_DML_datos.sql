-- phpMyAdmin SQL Dump - DML (Datos)
-- Inserciones ordenadas por prioridad de dependencias

USE `ezyro_42650191_protecciondatos`;

-- ==========================================
-- INSERCIÓN DE DATOS (ORDEN DE DEPENDENCIAS)
-- ==========================================

-- Nivel 1: Tablas Base
INSERT INTO `institucion_educativa` (`id`, `nombre`, `direccion`, `telefono`, `nombre_logotipo`, `estado`) VALUES
(1, 'Escuela Don Bosco', 'Direccion 1', '09999999', NULL, 'ACTIVO'),
(2, 'Escuela Juan Pablo Segundo', 'Direccion 2', '09999999', NULL, 'ACTIVO');

INSERT INTO `finalidad` (`FinalidadId`, `Codigo`, `Nombre`, `Descripcion`, `Activo`) VALUES
(1, '1', 'CONSENTIMIENTO DE USO DE DATOS', 'CONSENTIMIENTO DE USO DE DATOS', 'ACTIVO');

INSERT INTO `tipodato` (`TipoDatoId`, `Codigo`, `Nombre`, `Categoria`, `EsSensible`) VALUES
(1, '1', 'Telefono', 'PERSONAL', 'SI'),
(2, '2', 'Email', 'PERSONAL', 'NO'),
(3, '3', 'Direccion', 'PERSONAL', 'SI');

-- Nivel 2: Dependen de Institución
INSERT INTO `persona` (`InstitucionEducativaId`, `PersonaId`, `TipoIdentificacion`, `Identificacion`, `Nombres`, `Apellidos`, `Email`, `Telefono`, `Estado`) VALUES
(1, 1, 'CEDULA', '0999999999', 'Super', 'Administrador', 'admin@uees.edu.ec', '09999999', 'ACTIVO'),
(1, 9, 'CEDULA', '0916686009', 'Wilson Fidel', 'Muñoz Recalde', 'wmunozr@gmail.com', '0939071545', 'ACTIVO'),
(1, 10, 'CEDULA', '0700316284', 'Wilson Emiliano', 'Muñoz Davila', 'wilson.munoz@uees.edu.ec', '0980930119', 'ACTIVO'),
(1, 11, 'RUC', '0916686009001', 'COMPUMUNDOHYPERMEGARED', 'S.A.', 'wmunozr@yahoo.com', '0917181177', 'ACTIVO'),
(1, 12, 'CEDULA', 'USR-SEG-001', 'Usuario', 'Seguridades', 'seguridades@rea.local', NULL, 'ACTIVO'),
(1, 13, 'CEDULA', 'USR-REG-001', 'Usuario', 'Registro de Datos', 'registro@rea.local', NULL, 'ACTIVO'),
(1, 14, 'CEDULA', 'USR-CON-001', 'Usuario', 'Consultas', 'consultas@rea.local', NULL, 'ACTIVO'),
(1, 15, 'CEDULA', 'USR-REP-001', 'Usuario', 'Reportes', 'reportes@rea.local', NULL, 'ACTIVO'),
(1, 16, 'CEDULA', '0923112887', 'Luis Arturo', 'Celleri Carabajo', 'luis.celleri@uees.edu.ec', '0981427719', 'ACTIVO'),
(1, 17, 'CEDULA', '0955499645', 'Santiago Andrés', 'Muñoz Ringelman', 'santiago.munoz.ringelman@gmail.com', '0992694788', 'ACTIVO'),
(1, 18, 'RUC', '096328988001', 'Cristhian Ricardo', 'Cela Figueroa', 'cristhian.cela@uees.edu.ec', '096328988', 'ACTIVO'),
(1, 19, 'RUC', '0700316284001', 'Carlos', 'Freire Icaza', 'wilsonmunoz@hotmail.com', '0945454541', 'ACTIVO'),
(1, 20, 'CEDULA', '0921074860', 'Gabriel', 'Herrera', 'herrera.gabriel@gmail.com', '0984770197', 'ACTIVO'),
(1, 21, 'CEDULA', '0921708406', 'Gabriel', 'Herrera', 'herrera.gabriel@gmail.com', NULL, 'ACTIVO'),
(1, 22, 'CEDULA', '0919127480', 'Danny', 'Bajar', 'danie.f.bejar@gmail.com', '999437982', 'ACTIVO'),
(1, 23, 'CEDULA', '0919127481', 'Daniel', 'Béjar', 'daniel.f.bejar@gmail.com', '999437985', 'ACTIVO'),
(1, 24, 'CEDULA', '09188724402', 'Marco Vinicio', 'Muñoz Recalde', 'mmunozrecalde@gmail.com', '0992158118', 'ACTIVO');

INSERT INTO `rol` (`InstitucionEducativaId`, `RolId`, `Nombre`, `Descripcion`, `Estado`) VALUES
(1, 1, 'SuperAdmin', 'SuperAdmin', 'ACTIVO'),
(1, 2, 'Seguridades', 'Administración de accesos: usuarios, roles y permisos', 'ACTIVO'),
(1, 3, 'Registro de Datos', 'Mantenimiento de entidades, catálogos y consentimientos', 'ACTIVO'),
(1, 4, 'Consultas', 'Consulta de personas, consentimientos e historial de auditoría', 'ACTIVO'),
(1, 5, 'Reportes', 'Emisión de reportes de cumplimiento y exportación de datos', 'ACTIVO');

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
(1, 20, 'CON_BUSCAR_PERSONA', 'Buscar Persona', 'CONSULTA_BUSQUEDAS', 'Búsqueda de personas y ficha 360° del titular', 'ACTIVO'),
(1, 21, 'CON_HISTORIAL', 'Historial de Consentimientos', 'CONSULTA_BUSQUEDAS', 'Bitácora de auditoría: creación, modificación, revocación y reactivación', 'ACTIVO'),
(1, 22, 'CON_VIGENTES', 'Consentimientos Vigentes / Revocados', 'CONSULTA_BUSQUEDAS', 'Consulta de consentimientos por finalidad y tipo de dato', 'ACTIVO'),
(1, 23, 'REP_CONSENTIMIENTOS', 'Reporte de Consentimientos', 'REPORTES_EXPORTACION', 'Consentimientos por finalidad, medio y evolución mensual', 'ACTIVO'),
(1, 24, 'REP_DATOS_SENSIBLES', 'Reporte de Datos Sensibles', 'REPORTES_EXPORTACION', 'Tratamiento de categorías especiales de datos personales', 'ACTIVO'),
(1, 25, 'REP_TITULARES', 'Consentimientos por Titular', 'REPORTES_EXPORTACION', 'Detalle de titulares con consentimiento otorgado o revocado, con salida a PDF', 'ACTIVO'),
(1, 26, 'REP_EXPORTAR_CSV', 'Exportar Datos (CSV)', 'REPORTES_EXPORTACION', 'Descarga de la información de la institución en formato CSV', 'ACTIVO'),
(1, 30, 'REP_AUDITORIA', 'Bitácora de Auditoría', 'REPORTES_EXPORTACION', 'Consulta de todos los movimientos registrados en la base de datos', 'ACTIVO'),
(1, 32, 'ADM_DISCLAIMERS', 'Disclaimers de Datos', 'ADMINISTRACION', 'Redacción y vigencia de las políticas de protección de datos que se muestran al dar el consentimiento.', 'ACTIVO'),
(1, 33, 'ADM_CORREO', 'Configuración de Correo', 'ADMINISTRACION', 'Servidor de correo saliente y enlaces públicos de consentimiento.', 'ACTIVO'),
(1, 34, 'ADM_ENLACES_VERIF', 'Links con VerificaciÃ³n', 'ADMINISTRACION', 'Enlaces pÃºblicos de solo consulta que verifican la identidad con un cÃ³digo enviado por correo.', 'ACTIVO'),
(2, 35, 'ADM_ENLACES_VERIF', 'Links con VerificaciÃ³n', 'ADMINISTRACION', 'Enlaces pÃºblicos de solo consulta que verifican la identidad con un cÃ³digo enviado por correo.', 'ACTIVO');

INSERT INTO `correo_configuracion` (`InstitucionEducativaId`, `Servidor`, `Puerto`, `Seguridad`, `Usuario`, `Clave`, `RemitenteCorreo`, `RemitenteNombre`, `Activo`, `Actualizado`) VALUES
(1, 'smtp.hostinger.com', 587, 'TLS', 'wmunoz@saberempresarial.com', 'sNrO18855935+*', 'wmunoz@saberempresarial.com', 'REA', 'SI', '2026-08-24 12:35:08');

INSERT INTO `disclaimer` (`InstitucionEducativaId`, `DisclaimerId`, `TipoPersona`, `Version`, `Titulo`, `Texto`, `Estado`, `FechaCreacion`, `FechaVigencia`, `UsuarioId`, `Username`) VALUES
(1, 1, 'ESTUDIANTE', '1.0', 'Consentimiento para el tratamiento de datos personales', '<p>De conformidad con la <strong>Ley Org&aacute;nica de Protecci&oacute;n de Datos Personales</strong> y su reglamento...</p>', 'ACTIVO', '2026-08-24 20:58:55', '2026-08-24 20:58:55', NULL, 'instalacion'),
(1, 2, 'EMPLEADO', '1.0', 'Consentimiento para el tratamiento de datos personales', '<p>De conformidad con la <strong>Ley Org&aacute;nica de Protecci&oacute;n de Datos Personales</strong> y su reglamento...</p>', 'ACTIVO', '2026-08-24 20:58:55', '2026-08-24 20:58:55', NULL, 'instalacion'),
(1, 3, 'PROVEEDOR', '1.0', 'Consentimiento para el tratamiento de datos personales', '<p>De conformidad con la <strong>Ley Org&aacute;nica de Protecci&oacute;n de Datos Personales</strong> y su reglamento...</p>', 'ACTIVO', '2026-08-24 20:58:55', '2026-08-24 20:58:55', NULL, 'instalacion');

-- Nivel 3: Dependen de Institución y/o Persona
INSERT INTO `empleado` (`InstitucionEducativaId`, `EmpleadoId`, `PersonaId`, `Estado`) VALUES
(1, 1, 10, 'ACTIVO'),
(2, 2, 10, 'ACTIVO'),
(2, 3, 16, 'ACTIVO');

INSERT INTO `proveedor` (`InstitucionEducativaId`, `ProveedorId`, `PersonaId`, `Ruc`, `RazonSocial`, `Estado`) VALUES
(1, 1, 11, '0916686009001', 'COMPUMUNDO HYPER MEGA RED S.A.', 'ACTIVO'),
(1, 3, 19, '0700316284001', 'PAPELERIA E INSUMOS FREIRE S.A.', 'ACTIVO'),
(2, 2, 18, '096328988001', 'Papelería Cela S.A.', 'ACTIVO');

INSERT INTO `estudiante` (`InstitucionEducativaId`, `EstudianteId`, `PersonaId`, `CodigoEstudiante`, `RepresentanteId`, `RepresentanteRelacion`, `Estado`) VALUES
(1, 1, 9, '202598289', 10, 'PADRE', 'ACTIVO'),
(1, 3, 20, '0921074860', 21, 'REPRESENTANTE LEGAL', 'ACTIVO'),
(1, 4, 22, '0919127481', 23, 'PADRE', 'ACTIVO'),
(1, 5, 17, '20260545', 24, 'TIO', 'ACTIVO'),
(2, 2, 17, 'EST-2012-001', 9, 'PADRE', 'ACTIVO');

INSERT INTO `usuario` (`InstitucionEducativaId`, `PersonaId`, `UsuarioId`, `Username`, `PasswordHash`, `Email`, `UltimoAcceso`, `Estado`) VALUES
(1, 1, 1, 'admin', '$2y$10$20AKQPyukiEyAmR8C0zCLOQWgX.PdG67d.JR.N/oAK6Sfs/K6qzc.', NULL, '2026-08-25 20:21:41', 'ACTIVO'),
(1, 12, 3, 'seguridades', '$2y$10$QSwttESTN1z3cqtPICbA1./uimDjmO2F.FT6rBAH3hGaw.f22x.Qe', 'seguridades@rea.local', '2026-08-22 09:13:59', 'ACTIVO'),
(1, 13, 4, 'registro', '$2y$10$FIzC51hDG3X62GRNHASfiuVXtSG98up.b4UEK77M9Spe5fPAAkd5.', 'registro@rea.local', '2026-08-25 13:56:19', 'ACTIVO'),
(1, 14, 5, 'consultas', '$2y$10$My8rBc/kkY4JSTIfAaOEPOP5eP2gsHZo161BYficXk2KytZWkbk2u', 'consultas@rea.local', '2026-08-21 22:08:27', 'ACTIVO'),
(1, 15, 6, 'reportes', '$2y$10$KCAX69oCX25AtVSHvBzvPOF7uQTZmmLSiaJu9CnWD1shF35xWmoRq', 'reportes@rea.local', '2026-08-22 09:12:38', 'ACTIVO');

INSERT INTO `rolpermiso` (`InstitucionEducativaId`, `RolId`, `PermisoId`) VALUES
(1, 1, 1),
(1, 2, 2),
(1, 2, 3),
(1, 2, 4),
(1, 3, 6),
(1, 3, 7),
(1, 3, 8),
(1, 3, 9),
(1, 3, 10),
(1, 3, 11),
(1, 3, 12),
(1, 4, 20),
(1, 4, 21),
(1, 4, 22),
(1, 5, 23),
(1, 5, 24),
(1, 5, 25),
(1, 5, 26),
(1, 5, 30),
(1, 2, 34);

INSERT INTO `verificacion_codigo` (`InstitucionEducativaId`, `VerificacionId`, `TipoPersona`, `PersonaId`, `Identificacion`, `Destinatario`, `CodigoHash`, `FechaEmision`, `FechaExpira`, `FechaUso`, `Intentos`, `Envio`, `IpOrigen`, `Estado`) VALUES
(1, 1, 'ESTUDIANTE', 9, '0916686009', 'wilson.munoz@uees.edu.ec', 'b3bfe4beaa360b96e11dff247e1a6c4386312cf1f7eed3177cc2bde5d2a869fb', '2026-08-25 12:48:26', '2026-08-25 12:58:26', '2026-08-25 12:49:14', 0, 1, '185.27.134.197', 'USADO'),
(1, 2, 'ESTUDIANTE', 9, '0916686009', 'wilson.munoz@uees.edu.ec', '50b559c7db86277f126d6115a67a95f5aa2c4196646c1efff3b7ea543115a7d5', '2026-08-25 18:16:42', '2026-08-25 18:26:42', '2026-08-25 18:17:23', 0, 1, '185.27.134.197', 'USADO'),
(1, 3, 'ESTUDIANTE', 9, '0916686009', 'wilson.munoz@uees.edu.ec', 'c3c1dba564b06df93294aaed30beae4718792e74ba21b0c0e2cd8489aace551f', '2026-08-25 18:56:33', '2026-08-25 19:06:33', '2026-08-25 18:57:46', 1, 1, '185.27.134.197', 'USADO');

-- Nivel 4: Dependen de Usuario y otras de Nivel 3
INSERT INTO `usuariorol` (`InstitucionEducativaId`, `UsuarioId`, `RolId`) VALUES
(1, 1, 1),
(1, 3, 2),
(1, 4, 3),
(1, 5, 4),
(1, 6, 5);

INSERT INTO `auditoria` (`InstitucionEducativaId`, `AuditoriaId`, `FechaHora`, `UsuarioId`, `Username`, `IpOrigen`, `Tabla`, `RegistroId`, `Operacion`, `Campo`, `ValorAnterior`, `ValorNuevo`) VALUES
(1, 3, '2026-08-24 12:32:15', 1, 'admin', '185.27.134.197', 'correo_configuracion', '1', 'UPDATE', 'Usuario', 'wmunozr@gmail.com', 'wilson.munoz@uees.edu.ec'),
(1, 4, '2026-08-24 12:32:15', 1, 'admin', '185.27.134.197', 'correo_configuracion', '1', 'UPDATE', 'RemitenteCorreo', 'wmunozr@gmail.com', 'wilson.munoz@uees.edu.ec'),
(1, 10, '2026-08-24 21:08:40', 1, 'admin', '185.27.134.197', 'institucion_educativa', '1', 'UPDATE', 'nombre', 'Escuela 1', 'Escuela Don Bosco'),
(1, 18, '2026-08-25 20:25:40', 1, 'admin', '185.27.134.197', 'persona', '17', 'INSERT', 'InstitucionEducativaId', NULL, '1'),
(1, 19, '2026-08-25 20:25:40', 1, 'admin', '185.27.134.197', 'persona', '17', 'INSERT', 'PersonaId', NULL, '17'),
(2, 1, '2026-08-24 09:57:09', 1, 'admin', '185.27.134.197', 'carga_inicial', '20260824115709', 'UPDATE', 'Carga inicial desde archivo Excel', 'Borrado — historial: 0', 'Cargado — personas nuevas: 3');

INSERT INTO `consentimiento` (`InstitucionEducativaId`, `ConsentimientoId`, `PersonaId`, `FinalidadId`, `FechaConsentimiento`, `FechaRevocacion`, `RepresentanteId`, `MedioConsentimiento`, `VersionPolitica`, `IpOrigen`, `Estado`) VALUES
(1, 1, 10, 1, '2026-08-20 12:02:00', '2026-08-24 15:06:18', NULL, 'WEB', '', '185.27.134.197', 'INACTIVO'),
(1, 2, 9, 1, '2026-08-25 20:58:29', NULL, 10, 'WEB', '1.0', '185.27.134.197', 'ACTIVO'),
(1, 3, 19, 1, '2026-08-24 23:07:20', NULL, NULL, 'WEB', '1.0', '185.27.134.197', 'ACTIVO'),
(1, 4, 20, 1, '2026-08-25 07:29:19', NULL, 21, 'WEB', '1.0', '185.27.134.197', 'ACTIVO'),
(1, 5, 22, 1, '2026-08-25 07:40:51', NULL, 23, 'WEB', '1.0', '185.27.134.197', 'ACTIVO');

-- Nivel 5: Dependen de Consentimiento
INSERT INTO `consentimientodato` (`InstitucionEducativaId`, `ConsentimientoId`, `TipoDatoId`, `Autorizado`) VALUES
(1, 1, 1, 'NO'),
(1, 1, 2, 'NO'),
(1, 1, 3, 'NO'),
(1, 2, 1, 'SI'),
(1, 2, 2, 'SI'),
(1, 2, 3, 'SI'),
(1, 3, 1, 'SI'),
(1, 3, 2, 'SI'),
(1, 3, 3, 'SI'),
(1, 4, 1, 'SI'),
(1, 4, 2, 'SI'),
(1, 4, 3, 'SI'),
(1, 5, 1, 'SI'),
(1, 5, 2, 'SI'),
(1, 5, 3, 'SI');

INSERT INTO `consentimientohistorial` (`InstitucionEducativaId`, `HistorialId`, `ConsentimientoId`, `EstadoAnterior`, `EstadoNuevo`, `Accion`, `FechaAccion`, `UsuarioId`, `IpOrigen`, `Observacion`) VALUES
(1, 1, 1, NULL, 'ACTIVO', 'CREACION', '2026-08-20 09:02:29', 1, '187.251.172.1', 'Registro inicial del consentimiento.'),
(1, 2, 1, 'ACTIVO', 'INACTIVO', 'REVOCACION_WEB', '2026-08-24 13:06:18', NULL, '185.27.134.197', 'Consentimiento revocado por el titular desde la pantalla pública (CEDULA 0700316284, EMPLEADO).'),
(1, 3, 2, NULL, 'INACTIVO', 'REVOCACION_WEB', '2026-08-24 21:00:20', NULL, '185.27.134.197', 'Consentimiento revocado por el titular desde el enlace público de la institución. Política versión 1.0.'),
(1, 4, 3, NULL, 'ACTIVO', 'CONSENTIMIENTO_WEB', '2026-08-24 21:07:20', NULL, '185.27.134.197', 'Consentimiento otorgado por el titular desde el enlace público de la institución. Política versión 1.0.'),
(1, 5, 2, 'INACTIVO', 'ACTIVO', 'CONSENTIMIENTO_WEB', '2026-08-24 21:13:31', NULL, '185.27.134.197', 'Consentimiento otorgado por el titular desde el enlace público de la institución. Política versión 1.0.');