-- =============================================================================
--  01_DDL_estructura.sql
--  Sistema de Gestión de Protección de Datos
--  Red Educativa Arquidiocesana (REA)
-- -----------------------------------------------------------------------------
--  ESTRUCTURA COMPLETA DE LA BASE DE DATOS (DDL)
--
--  Motor: MySQL 5.7 o superior / MariaDB 10.3 o superior.
--
--  Este archivo reúne TODA la definición de estructura del sistema: base de
--  datos, tablas, claves, índices y relaciones. Sustituye a los scripts sueltos
--  que se fueron generando durante el desarrollo.
--
--  Está ordenado por propósito, siguiendo las dependencias entre tablas:
--
--     1. Preparación de la base de datos
--     2. Núcleo multi-institución
--     3. Catálogos del tratamiento de datos
--     4. Directorio de personas
--     5. Vínculos con la institución (empleados, estudiantes y proveedores)
--     6. Consentimientos y su historial
--     7. Seguridad y control de accesos
--     8. Parámetros del sistema
--     9. Bitácora de auditoría
--    10. Integridad referencial (claves foráneas)
--    11. Verificación
--    12. Notas para bases de datos ya existentes
--
--  Los datos (catálogos, permisos, roles y usuarios) van aparte, en
--  02_DML_datos.sql. Ejecute primero este archivo y después aquel.
--
--  Ejecución:
--     mysql -u USUARIO -p < 01_DDL_estructura.sql
-- =============================================================================


-- =============================================================================
--  1. PREPARACIÓN DE LA BASE DE DATOS
-- =============================================================================

-- Ajustes de sesión: se restauran al final del script.
SET @OLD_UNIQUE_CHECKS      = @@UNIQUE_CHECKS,      UNIQUE_CHECKS = 0;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS = 0;
SET @OLD_SQL_MODE           = @@SQL_MODE,           SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- La base de datos conserva el nombre y la codificación del hospedaje original.
-- Si su servidor usa otro nombre, cámbielo aquí y en config.php.
CREATE DATABASE IF NOT EXISTS `ezyro_42650191_protecciondatos`
    DEFAULT CHARACTER SET latin1
    COLLATE latin1_swedish_ci;

USE `ezyro_42650191_protecciondatos`;


-- =============================================================================
--  2. NÚCLEO MULTI-INSTITUCIÓN
-- -----------------------------------------------------------------------------
--  Todo el sistema se organiza alrededor de la institución educativa: cada
--  registro operativo pertenece a una, y los usuarios solo ven la suya. La
--  excepción es el rol SuperAdmin, que puede entrar en cualquiera.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `institucion_educativa` (
  `id`              int(11)      NOT NULL COMMENT 'Identificador asignado manualmente',
  `nombre`          varchar(50)  NOT NULL,
  `direccion`       varchar(100) NOT NULL,
  `telefono`        varchar(20)  NOT NULL,
  `nombre_logotipo` varchar(100) DEFAULT NULL COMMENT 'Archivo del logotipo, si difiere del institucional',
  `estado`          enum('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Instituciones educativas de la red. Es la entidad raíz del sistema.';


-- =============================================================================
--  3. CATÁLOGOS DEL TRATAMIENTO DE DATOS
-- -----------------------------------------------------------------------------
--  Son comunes a toda la red: definen POR QUÉ se tratan los datos (finalidad)
--  y QUÉ datos se tratan (tipo de dato personal).
-- =============================================================================

-- Finalidades del tratamiento: el motivo declarado por el que se piden los datos.
CREATE TABLE IF NOT EXISTS `finalidad` (
  `FinalidadId`  int(11)      NOT NULL AUTO_INCREMENT,
  `Codigo`       varchar(50)  NOT NULL,
  `Nombre`       varchar(150) NOT NULL,
  `Descripcion`  text         DEFAULT NULL,
  `Activo`       enum('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
  PRIMARY KEY (`FinalidadId`),
  UNIQUE KEY `uk_finalidad_codigo` (`Codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Finalidades declaradas para el tratamiento de datos personales.';

-- Tipos de dato personal. EsSensible marca los que la ley protege de forma
-- reforzada y que el sistema resalta en los reportes.
CREATE TABLE IF NOT EXISTS `tipodato` (
  `TipoDatoId`  int(11)      NOT NULL AUTO_INCREMENT,
  `Codigo`      varchar(50)  NOT NULL,
  `Nombre`      varchar(150) NOT NULL,
  `Categoria`   varchar(100) DEFAULT NULL,
  `EsSensible`  enum('SI','NO') NOT NULL DEFAULT 'NO' COMMENT 'SI = dato sensible según la LOPDP',
  PRIMARY KEY (`TipoDatoId`),
  UNIQUE KEY `uk_tipodato_codigo` (`Codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Catálogo de tipos de dato personal sujetos a consentimiento.';


-- =============================================================================
--  4. DIRECTORIO DE PERSONAS
-- -----------------------------------------------------------------------------
--  El padrón de personas de cada institución, identificadas por su documento.
--  Sobre él se apoyan los vínculos institucionales: dentro de una institución,
--  la misma persona puede ser empleado y representante de un estudiante sin
--  duplicarse.
--
--  Cada persona PERTENECE A UNA INSTITUCIÓN: la identificación es única dentro
--  de ella, no en toda la red. Si la misma persona se relaciona con dos
--  instituciones, cada una tiene su propia ficha, y ninguna ve la de la otra.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `persona` (
  `InstitucionEducativaId` int(11)      NOT NULL,
  `PersonaId`              int(11)      NOT NULL AUTO_INCREMENT,
  `TipoIdentificacion`     enum('CEDULA','RUC','PASAPORTE','') DEFAULT NULL,
  `Identificacion`         varchar(50)  DEFAULT NULL,
  `Nombres`                varchar(100) DEFAULT NULL,
  `Apellidos`              varchar(100) DEFAULT NULL,
  `Email`                  varchar(150) DEFAULT NULL,
  `Telefono`               varchar(20)  DEFAULT NULL,
  `Estado`                 enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO',
  PRIMARY KEY (`InstitucionEducativaId`,`PersonaId`),
  -- PersonaId sigue siendo único en toda la base: es lo que permite que las
  -- demás tablas lo referencien con una sola columna, igual que EmpleadoId o
  -- EstudianteId en sus propias tablas.
  UNIQUE KEY `uk_persona_id` (`PersonaId`),
  -- La identificación es única DENTRO de cada institución, no en toda la red:
  -- la misma cédula puede constar en dos instituciones como fichas separadas.
  UNIQUE KEY `uk_persona_identificacion` (`InstitucionEducativaId`,`Identificacion`),
  KEY `ix_persona_nombre` (`InstitucionEducativaId`,`Apellidos`,`Nombres`),
  KEY `ix_persona_estado` (`InstitucionEducativaId`,`Estado`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Personas por institución. La identificación no se repite dentro de una misma institución.';


-- =============================================================================
--  5. VÍNCULOS CON LA INSTITUCIÓN
-- -----------------------------------------------------------------------------
--  Relacionan una persona del directorio con una institución bajo una calidad
--  concreta. Una misma persona puede tener varios vínculos a la vez.
-- =============================================================================

-- Personal de la institución.
CREATE TABLE IF NOT EXISTS `empleado` (
  `InstitucionEducativaId` int(11)      NOT NULL,
  `EmpleadoId`             int(11)      NOT NULL AUTO_INCREMENT,
  `PersonaId`              int(11)      DEFAULT NULL,
  `Estado`                 enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO',
  PRIMARY KEY (`InstitucionEducativaId`,`EmpleadoId`),
  UNIQUE KEY `uk_empleado_id` (`EmpleadoId`),
  KEY `fk_empleado_persona` (`PersonaId`),
  KEY `ix_empleado_estado` (`InstitucionEducativaId`,`Estado`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Empleados por institución.';

-- Alumnos matriculados. RepresentanteId apunta a otra persona del directorio:
-- es quien recibe los correos y quien puede consentir en su nombre.
CREATE TABLE IF NOT EXISTS `estudiante` (
  `InstitucionEducativaId` int(11)      NOT NULL,
  `EstudianteId`           int(11)      NOT NULL AUTO_INCREMENT,
  `PersonaId`              int(11)      DEFAULT NULL,
  `CodigoEstudiante`       varchar(20)  DEFAULT NULL,
  `RepresentanteId`        int(11)      DEFAULT NULL COMMENT 'Persona que representa al estudiante',
  `RepresentanteRelacion`  enum('MADRE','PADRE','ABUELO','ABUELA','TIO','TIA','REPRESENTANTE LEGAL','TUTOR/A','OTRO') DEFAULT NULL,
  `Estado`                 enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO',
  PRIMARY KEY (`InstitucionEducativaId`,`EstudianteId`),
  UNIQUE KEY `uk_estudiante_id` (`EstudianteId`),
  UNIQUE KEY `uk_estudiante_codigo` (`CodigoEstudiante`),
  KEY `fk_estudiante_persona` (`PersonaId`),
  KEY `fk_estudiante_representante` (`RepresentanteId`),
  KEY `ix_estudiante_estado` (`InstitucionEducativaId`,`Estado`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Estudiantes matriculados y su representante.';

-- Proveedores de bienes y servicios. PersonaId es el contacto natural;
-- RazonSocial y Ruc corresponden a la empresa.
CREATE TABLE IF NOT EXISTS `proveedor` (
  `InstitucionEducativaId` int(11)      NOT NULL,
  `ProveedorId`            int(11)      NOT NULL AUTO_INCREMENT,
  `PersonaId`              int(11)      NOT NULL,
  `Ruc`                    varchar(20)  DEFAULT NULL,
  `RazonSocial`            varchar(150) DEFAULT NULL,
  `Estado`                 enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO',
  PRIMARY KEY (`InstitucionEducativaId`,`ProveedorId`),
  UNIQUE KEY `uk_proveedor_id` (`ProveedorId`),
  KEY `fk_proveedor_persona` (`PersonaId`),
  KEY `ix_proveedor_estado` (`InstitucionEducativaId`,`Estado`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Proveedores por institución.';


-- =============================================================================
--  6. CONSENTIMIENTOS Y SU HISTORIAL
-- -----------------------------------------------------------------------------
--  Es el núcleo del sistema: qué persona autorizó qué finalidad, cuándo, por
--  qué medio y sobre qué datos; y toda la traza de cambios de esa decisión.
-- =============================================================================

-- Decisión vigente de una persona sobre una finalidad.
-- Estado ACTIVO = consentimiento otorgado; INACTIVO = revocado.
CREATE TABLE IF NOT EXISTS `consentimiento` (
  `InstitucionEducativaId` int(11)     NOT NULL,
  `ConsentimientoId`       int(11)     NOT NULL AUTO_INCREMENT,
  `PersonaId`              int(11)     DEFAULT NULL COMMENT 'Titular de los datos',
  `FinalidadId`            int(11)     DEFAULT NULL,
  `FechaConsentimiento`    datetime    DEFAULT NULL,
  `FechaRevocacion`        datetime    DEFAULT NULL COMMENT 'Se llena solo al revocar',
  `RepresentanteId`        int(11)     DEFAULT NULL COMMENT 'Quien consintió, si el titular es un estudiante',
  `MedioConsentimiento`    enum('WEB','EMAIL','WHATSAPP','APP') DEFAULT NULL,
  `VersionPolitica`        varchar(50) DEFAULT NULL COMMENT 'Versión del disclaimer aceptado',
  `IpOrigen`               varchar(20) DEFAULT NULL,
  `Estado`                 enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO',
  PRIMARY KEY (`InstitucionEducativaId`,`ConsentimientoId`),
  UNIQUE KEY `uk_consentimiento_id` (`ConsentimientoId`),
  KEY `fk_consentimiento_persona` (`PersonaId`),
  KEY `fk_consentimiento_finalidad` (`FinalidadId`),
  KEY `fk_consentimiento_representante` (`RepresentanteId`),
  KEY `ix_consentimiento_estado` (`InstitucionEducativaId`,`Estado`),
  KEY `ix_consentimiento_fecha` (`InstitucionEducativaId`,`FechaConsentimiento`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Consentimientos otorgados o revocados por finalidad.';

-- Detalle: qué tipos de dato quedaron autorizados dentro de ese consentimiento.
CREATE TABLE IF NOT EXISTS `consentimientodato` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `ConsentimientoId`       int(11) NOT NULL,
  `TipoDatoId`             int(11) NOT NULL,
  `Autorizado`             enum('SI','NO') NOT NULL DEFAULT 'NO',
  PRIMARY KEY (`InstitucionEducativaId`,`ConsentimientoId`,`TipoDatoId`),
  KEY `fk_consentimientodato_tipodato` (`TipoDatoId`),
  KEY `fk_consentimientodato_consentimiento` (`ConsentimientoId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Tipos de dato autorizados en cada consentimiento.';

-- Traza de cada cambio de estado. UsuarioId queda nulo cuando la acción la
-- realizó el propio titular desde el enlace público, no un usuario del sistema.
CREATE TABLE IF NOT EXISTS `consentimientohistorial` (
  `InstitucionEducativaId` int(11)      NOT NULL,
  `HistorialId`            int(11)      NOT NULL AUTO_INCREMENT,
  `ConsentimientoId`       int(11)      DEFAULT NULL,
  `EstadoAnterior`         enum('ACTIVO','INACTIVO') DEFAULT NULL,
  `EstadoNuevo`            varchar(50)  DEFAULT NULL,
  `Accion`                 varchar(100) DEFAULT NULL COMMENT 'CREACION, MODIFICACION, REVOCACION, CONSENTIMIENTO_WEB...',
  `FechaAccion`            datetime     DEFAULT NULL,
  `UsuarioId`              int(11)      DEFAULT NULL COMMENT 'Nulo si la hizo el titular desde el enlace público',
  `IpOrigen`               varchar(45)  DEFAULT NULL,
  `Observacion`            text         DEFAULT NULL,
  PRIMARY KEY (`InstitucionEducativaId`,`HistorialId`),
  UNIQUE KEY `uk_historial_id` (`HistorialId`),
  KEY `fk_consentimientohistorial_consentimiento` (`ConsentimientoId`),
  KEY `fk_consentimientohistorial_usuario` (`UsuarioId`),
  KEY `ix_historial_fecha` (`InstitucionEducativaId`,`FechaAccion`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Historial de cambios de cada consentimiento.';


-- =============================================================================
--  7. SEGURIDAD Y CONTROL DE ACCESOS
-- -----------------------------------------------------------------------------
--  Un usuario entra a una opción si es SuperAdmin, o tiene alguno de los roles
--  que la abren, o alguno de sus permisos. El mapa de qué abre cada opción está
--  en includes/accesos.php, del lado de la aplicación.
-- =============================================================================

-- Roles por institución.
CREATE TABLE IF NOT EXISTS `rol` (
  `InstitucionEducativaId` int(11)      NOT NULL,
  `RolId`                  int(11)      NOT NULL AUTO_INCREMENT,
  `Nombre`                 varchar(50)  NOT NULL,
  `Descripcion`            varchar(255) DEFAULT NULL,
  `Estado`                 enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO' COMMENT 'Un rol inactivo deja de otorgar sus accesos',
  PRIMARY KEY (`InstitucionEducativaId`,`RolId`),
  UNIQUE KEY `uk_rol_id` (`RolId`),
  -- Único POR INSTITUCIÓN: dos instituciones pueden tener un rol con el mismo
  -- nombre sin estorbarse.
  UNIQUE KEY `uk_rol_institucion_nombre` (`InstitucionEducativaId`,`Nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Roles del sistema, definidos por institución.';

-- Permisos por institución, agrupados por módulo.
CREATE TABLE IF NOT EXISTS `permiso` (
  `InstitucionEducativaId` int(11)      NOT NULL,
  `PermisoId`              int(11)      NOT NULL AUTO_INCREMENT,
  `Codigo`                 varchar(50)  NOT NULL COMMENT 'Código que consulta la aplicación',
  `Nombre`                 varchar(100) NOT NULL,
  `Modulo`                 enum('ADMINISTRACION','REGISTRO_DATOS','CONSULTA_BUSQUEDAS','REPORTES_EXPORTACION') DEFAULT NULL,
  `Descripcion`            varchar(255) DEFAULT NULL,
  `Estado`                 enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO',
  PRIMARY KEY (`InstitucionEducativaId`,`PermisoId`),
  UNIQUE KEY `uk_permiso_id` (`PermisoId`),
  -- Único POR INSTITUCIÓN, por el mismo motivo que en `rol`.
  UNIQUE KEY `uk_permiso_institucion_codigo` (`InstitucionEducativaId`,`Codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Permisos asignables a los roles.';

-- Qué permisos tiene cada rol.
CREATE TABLE IF NOT EXISTS `rolpermiso` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `RolId`                  int(11) NOT NULL,
  `PermisoId`              int(11) NOT NULL,
  PRIMARY KEY (`InstitucionEducativaId`,`RolId`,`PermisoId`),
  KEY `fk_rolpermiso_permiso` (`PermisoId`),
  KEY `fk_rolpermiso_rol` (`RolId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Asignación de permisos a roles.';

-- Cuentas de acceso. Username es único en todo el sistema: por eso el
-- SuperAdmin puede iniciar sesión en cualquier institución sin ambigüedad.
CREATE TABLE IF NOT EXISTS `usuario` (
  `InstitucionEducativaId` int(11)      NOT NULL,
  `PersonaId`              int(11)      NOT NULL,
  `UsuarioId`              int(11)      NOT NULL AUTO_INCREMENT,
  `Username`               varchar(50)  NOT NULL,
  `PasswordHash`           varchar(255) NOT NULL COMMENT 'Generado con password_hash() de PHP',
  `Email`                  varchar(150) DEFAULT NULL,
  `UltimoAcceso`           datetime     DEFAULT NULL,
  `Estado`                 enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO',
  PRIMARY KEY (`InstitucionEducativaId`,`PersonaId`),
  UNIQUE KEY `uk_usuario_id` (`UsuarioId`),
  UNIQUE KEY `uk_usuario_username` (`Username`),
  KEY `fk_usuario_persona` (`PersonaId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Cuentas de acceso al sistema.';

-- Qué roles tiene cada usuario.
CREATE TABLE IF NOT EXISTS `usuariorol` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `UsuarioId`              int(11) NOT NULL,
  `RolId`                  int(11) NOT NULL,
  PRIMARY KEY (`InstitucionEducativaId`,`UsuarioId`,`RolId`),
  KEY `fk_usuariorol_rol` (`RolId`),
  KEY `fk_usuariorol_usuario` (`UsuarioId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Asignación de roles a usuarios.';


-- =============================================================================
--  8. PARÁMETROS DEL SISTEMA
-- -----------------------------------------------------------------------------
--  Configuración que la institución administra desde la aplicación, sin tocar
--  código: los textos legales y el servidor de correo saliente.
-- =============================================================================

-- Disclaimers de política de datos. Solo uno ACTIVO por tipo de persona: es el
-- que ve quien abre el enlace público de ese tipo. Las versiones anteriores se
-- conservan porque el consentimiento guarda cuál aceptó cada persona.
CREATE TABLE IF NOT EXISTS `disclaimer` (
  `InstitucionEducativaId` int(11)      NOT NULL,
  `DisclaimerId`           int(11)      NOT NULL AUTO_INCREMENT,
  `TipoPersona`            enum('ESTUDIANTE','EMPLEADO','PROVEEDOR') NOT NULL,
  `Version`                varchar(20)  NOT NULL COMMENT 'Versión de la política, p. ej. 1.0',
  `Titulo`                 varchar(150) DEFAULT NULL,
  `Texto`                  mediumtext   NOT NULL COMMENT 'Texto enriquecido (HTML depurado al guardar)',
  `Estado`                 enum('ACTIVO','INACTIVO') NOT NULL DEFAULT 'INACTIVO' COMMENT 'ACTIVO = vigente para su tipo',
  `FechaCreacion`          datetime     DEFAULT NULL,
  `FechaVigencia`          datetime     DEFAULT NULL COMMENT 'Momento en que se activó',
  `UsuarioId`              int(11)      DEFAULT NULL,
  `Username`               varchar(50)  DEFAULT NULL,
  PRIMARY KEY (`InstitucionEducativaId`,`DisclaimerId`),
  UNIQUE KEY `uk_disclaimer_id` (`DisclaimerId`),
  UNIQUE KEY `uk_disclaimer_version` (`InstitucionEducativaId`,`TipoPersona`,`Version`),
  KEY `ix_disclaimer_vigente` (`InstitucionEducativaId`,`TipoPersona`,`Estado`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Textos de política de datos por tipo de persona y versión.';

-- Servidor de correo saliente, uno por institución. Sin configuración activa,
-- el sistema envía con la función mail() de PHP.
CREATE TABLE IF NOT EXISTS `correo_configuracion` (
  `InstitucionEducativaId` int(11)      NOT NULL,
  `Servidor`               varchar(150) DEFAULT NULL COMMENT 'Servidor SMTP, p. ej. smtp.gmail.com',
  `Puerto`                 int(11)      DEFAULT 587,
  `Seguridad`              enum('NINGUNA','TLS','SSL') NOT NULL DEFAULT 'TLS',
  `Usuario`                varchar(150) DEFAULT NULL,
  `Clave`                  varchar(255) DEFAULT NULL COMMENT 'Nunca se devuelve a la pantalla ni se registra en la auditoría',
  `RemitenteCorreo`        varchar(150) DEFAULT NULL,
  `RemitenteNombre`        varchar(150) DEFAULT NULL,
  `Activo`                 enum('SI','NO') NOT NULL DEFAULT 'NO' COMMENT 'NO = usar mail() de PHP',
  `Actualizado`            datetime     DEFAULT NULL,
  PRIMARY KEY (`InstitucionEducativaId`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Configuración del correo saliente por institución.';


-- Códigos de un solo uso de los enlaces públicos CON VERIFICACIÓN.
-- Antes de mostrar el disclaimer, esos enlaces envían un código al correo que
-- la persona tiene registrado y exigen escribirlo. El código se guarda cifrado
-- (SHA-256), nunca en claro, y caduca a los 10 minutos.
CREATE TABLE IF NOT EXISTS `verificacion_codigo` (
  `InstitucionEducativaId` int(11)      NOT NULL,
  `VerificacionId`         int(11)      NOT NULL AUTO_INCREMENT,
  `TipoPersona`            enum('ESTUDIANTE','EMPLEADO','PROVEEDOR') NOT NULL,
  `PersonaId`              int(11)      DEFAULT NULL COMMENT 'Titular consultado',
  `Identificacion`         varchar(50)  NOT NULL COMMENT 'Cédula o RUC con el que se consultó',
  `Destinatario`           varchar(150) NOT NULL COMMENT 'Correo al que se envió el código',
  `CodigoHash`             char(64)     NOT NULL COMMENT 'SHA-256 del código: nunca se guarda en claro',
  `FechaEmision`           datetime     NOT NULL,
  `FechaExpira`            datetime     NOT NULL COMMENT 'Emisión + 10 minutos',
  `FechaUso`               datetime     DEFAULT NULL,
  `Intentos`               int(11)      NOT NULL DEFAULT 0 COMMENT 'Códigos escritos sin acertar',
  `Envio`                  int(11)      NOT NULL DEFAULT 1 COMMENT 'Número de envío: 1 el original, 2+ reenvíos',
  `IpOrigen`               varchar(45)  DEFAULT NULL,
  `Estado`                 enum('PENDIENTE','USADO','ANULADO') NOT NULL DEFAULT 'PENDIENTE',
  PRIMARY KEY (`InstitucionEducativaId`,`VerificacionId`),
  UNIQUE KEY `uk_verificacion_id` (`VerificacionId`),
  KEY `fk_verificacion_persona` (`PersonaId`),
  KEY `ix_verificacion_busqueda` (`InstitucionEducativaId`,`TipoPersona`,`Identificacion`,`Estado`),
  KEY `ix_verificacion_expira` (`FechaExpira`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Códigos de verificación por correo de los enlaces públicos con verificación.';


-- =============================================================================
--  9. BITÁCORA DE AUDITORÍA
-- -----------------------------------------------------------------------------
--  Cada alta, cambio o baja hecha a través de la aplicación deja constancia
--  aquí, con UNA FILA POR CAMPO AFECTADO, de modo que el reporte pueda decir
--  exactamente qué dato se tocó.
--
--  La bitácora anota el QUÉ, no el DATO: guarda que se modificó, por ejemplo, el
--  correo de una persona, pero no el correo anterior ni el nuevo. Así la propia
--  bitácora de un sistema de protección de datos no se convierte en una segunda
--  copia —sin control de acceso propio y sin caducidad— de los datos personales
--  que custodia.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `auditoria` (
  `InstitucionEducativaId` int(11)     NOT NULL,
  `AuditoriaId`            bigint(20)  NOT NULL AUTO_INCREMENT,
  `FechaHora`              datetime    NOT NULL,
  `UsuarioId`              int(11)     DEFAULT NULL,
  `Username`               varchar(50) DEFAULT NULL COMMENT 'Se conserva aunque la cuenta se elimine',
  `IpOrigen`               varchar(45) DEFAULT NULL,
  `Tabla`                  varchar(64) NOT NULL,
  `RegistroId`             varchar(64) DEFAULT NULL,
  `Operacion`              enum('INSERT','UPDATE','DELETE') NOT NULL,
  `Campo`                  varchar(64) DEFAULT NULL COMMENT 'Qué dato se tocó. Nunca su contenido',
  PRIMARY KEY (`InstitucionEducativaId`,`AuditoriaId`),
  UNIQUE KEY `uk_auditoria_id` (`AuditoriaId`),
  KEY `ix_auditoria_fecha`   (`InstitucionEducativaId`,`FechaHora`),
  KEY `ix_auditoria_usuario` (`InstitucionEducativaId`,`Username`),
  KEY `ix_auditoria_tabla`   (`InstitucionEducativaId`,`Tabla`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
  COMMENT='Bitácora general: quién tocó qué dato, cuándo y desde dónde. No guarda valores.';


-- =============================================================================
--  10. INTEGRIDAD REFERENCIAL
-- -----------------------------------------------------------------------------
--  Las claves foráneas van al final para que el orden de creación de las tablas
--  no importe y para poder leerlas juntas.
--
--  Criterio de borrado:
--     RESTRICT  → protege lo que no debe perderse (la institución)
--     SET NULL  → conserva el registro histórico aunque desaparezca el referido
--     CASCADE   → el detalle no tiene sentido sin su cabecera
-- =============================================================================

-- --- Vínculos institucionales -------------------------------------------------
-- --- Directorio de personas -----------------------------------------------------
-- Cada persona pertenece a una institución. RESTRICT protege: no se puede borrar
-- una institución que todavía tiene personas registradas.
ALTER TABLE `persona`
  ADD CONSTRAINT `fk_persona_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `empleado`
  ADD CONSTRAINT `fk_empleado_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_empleado_persona` FOREIGN KEY (`PersonaId`)
      REFERENCES `persona` (`PersonaId`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `estudiante`
  ADD CONSTRAINT `fk_estudiante_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_estudiante_persona` FOREIGN KEY (`PersonaId`)
      REFERENCES `persona` (`PersonaId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_estudiante_representante` FOREIGN KEY (`RepresentanteId`)
      REFERENCES `persona` (`PersonaId`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `proveedor`
  ADD CONSTRAINT `fk_proveedor_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_proveedor_persona` FOREIGN KEY (`PersonaId`)
      REFERENCES `persona` (`PersonaId`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- --- Consentimientos ----------------------------------------------------------
ALTER TABLE `consentimiento`
  ADD CONSTRAINT `fk_consentimiento_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimiento_persona` FOREIGN KEY (`PersonaId`)
      REFERENCES `persona` (`PersonaId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimiento_representante` FOREIGN KEY (`RepresentanteId`)
      REFERENCES `persona` (`PersonaId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimiento_finalidad` FOREIGN KEY (`FinalidadId`)
      REFERENCES `finalidad` (`FinalidadId`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `consentimientodato`
  ADD CONSTRAINT `fk_consentimientodato_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimientodato_consentimiento` FOREIGN KEY (`ConsentimientoId`)
      REFERENCES `consentimiento` (`ConsentimientoId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimientodato_tipodato` FOREIGN KEY (`TipoDatoId`)
      REFERENCES `tipodato` (`TipoDatoId`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `consentimientohistorial`
  ADD CONSTRAINT `fk_consentimientohistorial_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimientohistorial_consentimiento` FOREIGN KEY (`ConsentimientoId`)
      REFERENCES `consentimiento` (`ConsentimientoId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimientohistorial_usuario` FOREIGN KEY (`UsuarioId`)
      REFERENCES `usuario` (`UsuarioId`) ON DELETE SET NULL ON UPDATE CASCADE;

-- --- Seguridad ----------------------------------------------------------------
ALTER TABLE `rol`
  ADD CONSTRAINT `fk_rol_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `permiso`
  ADD CONSTRAINT `fk_permiso_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `rolpermiso`
  ADD CONSTRAINT `fk_rolpermiso_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rolpermiso_rol` FOREIGN KEY (`RolId`)
      REFERENCES `rol` (`RolId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rolpermiso_permiso` FOREIGN KEY (`PermisoId`)
      REFERENCES `permiso` (`PermisoId`) ON DELETE CASCADE ON UPDATE CASCADE;

-- Ojo: borrar una persona arrastra su cuenta de usuario. Por eso ninguna
-- operación masiva del sistema elimina personas que tengan usuario.
ALTER TABLE `usuario`
  ADD CONSTRAINT `fk_usuario_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_usuario_persona` FOREIGN KEY (`PersonaId`)
      REFERENCES `persona` (`PersonaId`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `usuariorol`
  ADD CONSTRAINT `fk_usuariorol_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_usuariorol_usuario` FOREIGN KEY (`UsuarioId`)
      REFERENCES `usuario` (`UsuarioId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_usuariorol_rol` FOREIGN KEY (`RolId`)
      REFERENCES `rol` (`RolId`) ON DELETE CASCADE ON UPDATE CASCADE;

-- --- Parámetros y auditoría ---------------------------------------------------
ALTER TABLE `disclaimer`
  ADD CONSTRAINT `fk_disclaimer_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `correo_configuracion`
  ADD CONSTRAINT `fk_correoconfig_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `auditoria`
  ADD CONSTRAINT `fk_auditoria_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

-- Los códigos caducan solos; si se borra la institución o la persona, se van con
-- ella porque no tienen valor histórico.
ALTER TABLE `verificacion_codigo`
  ADD CONSTRAINT `fk_verificacion_institucion` FOREIGN KEY (`InstitucionEducativaId`)
      REFERENCES `institucion_educativa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_verificacion_persona` FOREIGN KEY (`PersonaId`)
      REFERENCES `persona` (`PersonaId`) ON DELETE CASCADE ON UPDATE CASCADE;


-- =============================================================================
--  11. VERIFICACIÓN
-- =============================================================================

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS      = @OLD_UNIQUE_CHECKS;
SET SQL_MODE           = @OLD_SQL_MODE;

SELECT TABLE_NAME AS Tabla, TABLE_COMMENT AS Proposito
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE()
 ORDER BY TABLE_NAME;

SELECT COUNT(*) AS TablasCreadas
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE();          -- deben ser 19

SELECT COUNT(*) AS ClavesForaneas
  FROM information_schema.TABLE_CONSTRAINTS
 WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY';   -- deben ser 33


-- =============================================================================
--  12. NOTAS PARA BASES DE DATOS YA EXISTENTES
-- -----------------------------------------------------------------------------
--  Este script crea todo desde cero y no altera lo que ya exista: los
--  CREATE TABLE llevan IF NOT EXISTS y los ALTER de claves foráneas fallarían
--  si la restricción ya estuviera creada.
--
--  Si va a ACTUALIZAR una base anterior en lugar de crear una nueva:
--
--  a) Ejecute solo los CREATE TABLE de las tablas que le falten, junto con sus
--     ALTER TABLE de la sección 10. Las tablas incorporadas después de la
--     versión inicial son:
--         auditoria             (bitácora)
--         disclaimer            (textos de política)
--         correo_configuracion  (servidor de correo)
--         verificacion_codigo   (códigos de los enlaces con verificación)
--
--  b) La versión inicial declaraba `rol`.`Nombre` y `permiso`.`Codigo` como
--     únicos en TODA la base, no por institución. Con esos índices, la segunda
--     institución no puede tener un rol o permiso con un nombre ya usado por la
--     primera. Para corregirlo, ejecute:
--
--         ALTER TABLE `rol`
--             DROP INDEX `Nombre`,
--             ADD UNIQUE KEY `uk_rol_institucion_nombre` (`InstitucionEducativaId`,`Nombre`);
--
--         ALTER TABLE `permiso`
--             DROP INDEX `Codigo`,
--             ADD UNIQUE KEY `uk_permiso_institucion_codigo` (`InstitucionEducativaId`,`Codigo`);
--
--     Con una sola institución no hace falta.
--
--  c) Si la base trae las tablas `correo_envio` y `correo_destinatario`, son de
--     una función retirada y pueden eliminarse:
--
--         DROP TABLE IF EXISTS `correo_destinatario`;
--         DROP TABLE IF EXISTS `correo_envio`;
-- =============================================================================
