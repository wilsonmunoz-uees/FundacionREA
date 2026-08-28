-- phpMyAdmin SQL Dump - DDL (Estructura)
-- Generado con prioridad de dependencias

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- Base de datos: `ezyro_42650191_protecciondatos`
CREATE DATABASE IF NOT EXISTS `ezyro_42650191_protecciondatos` DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci;
USE `ezyro_42650191_protecciondatos`;

-- ==========================================
-- CREACIÓN DE TABLAS (ORDEN DE DEPENDENCIAS)
-- ==========================================

-- Nivel 1: Tablas Base
DROP TABLE IF EXISTS `institucion_educativa`;
CREATE TABLE `institucion_educativa` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) NOT NULL,
  `direccion` varchar(100) NOT NULL,
  `telefono` varchar(20) NOT NULL,
  `nombre_logotipo` varchar(100) DEFAULT NULL,
  `estado` enum('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `finalidad`;
CREATE TABLE `finalidad` (
  `FinalidadId` int(11) NOT NULL,
  `Codigo` varchar(50) NOT NULL,
  `Nombre` varchar(150) NOT NULL,
  `Descripcion` text DEFAULT NULL,
  `Activo` enum('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `tipodato`;
CREATE TABLE `tipodato` (
  `TipoDatoId` int(11) NOT NULL,
  `Codigo` varchar(50) NOT NULL,
  `Nombre` varchar(150) NOT NULL,
  `Categoria` varchar(100) DEFAULT NULL,
  `EsSensible` enum('SI','NO') DEFAULT 'NO'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Nivel 2: Dependen de Institución
DROP TABLE IF EXISTS `persona`;
CREATE TABLE `persona` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `PersonaId` int(11) NOT NULL,
  `TipoIdentificacion` enum('CEDULA','RUC','PASAPORTE','') DEFAULT NULL,
  `Identificacion` varchar(50) DEFAULT NULL,
  `Nombres` varchar(100) DEFAULT NULL,
  `Apellidos` varchar(100) DEFAULT NULL,
  `Email` varchar(150) DEFAULT NULL,
  `Telefono` varchar(20) DEFAULT NULL,
  `Estado` enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='Personas por instituciÃ³n. La identificaciÃ³n no se repite dentro de una misma instituciÃ³n.';

DROP TABLE IF EXISTS `rol`;
CREATE TABLE `rol` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `RolId` int(11) NOT NULL,
  `Nombre` varchar(50) NOT NULL,
  `Descripcion` varchar(255) DEFAULT NULL,
  `Estado` enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `permiso`;
CREATE TABLE `permiso` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `PermisoId` int(11) NOT NULL,
  `Codigo` varchar(50) NOT NULL,
  `Nombre` varchar(100) NOT NULL,
  `Modulo` enum('ADMINISTRACION','REGISTRO_DATOS','CONSULTA_BUSQUEDAS','REPORTES_EXPORTACION') DEFAULT NULL,
  `Descripcion` varchar(255) DEFAULT NULL,
  `Estado` enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `correo_configuracion`;
CREATE TABLE `correo_configuracion` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `Servidor` varchar(150) DEFAULT NULL COMMENT 'Servidor SMTP, p. ej. smtp.gmail.com',
  `Puerto` int(11) DEFAULT 587,
  `Seguridad` enum('NINGUNA','TLS','SSL') NOT NULL DEFAULT 'TLS',
  `Usuario` varchar(150) DEFAULT NULL COMMENT 'Usuario de autenticación SMTP',
  `Clave` varchar(255) DEFAULT NULL COMMENT 'Contraseña SMTP (nunca se devuelve a la pantalla)',
  `RemitenteCorreo` varchar(150) DEFAULT NULL COMMENT 'Dirección que aparece como remitente',
  `RemitenteNombre` varchar(150) DEFAULT NULL COMMENT 'Nombre visible del remitente',
  `Activo` enum('SI','NO') NOT NULL DEFAULT 'NO' COMMENT 'NO = usar mail() de PHP',
  `Actualizado` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `disclaimer`;
CREATE TABLE `disclaimer` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `DisclaimerId` int(11) NOT NULL,
  `TipoPersona` enum('ESTUDIANTE','EMPLEADO','PROVEEDOR') NOT NULL COMMENT 'A qué tipo de persona aplica',
  `Version` varchar(20) NOT NULL COMMENT 'Versión de la política, p. ej. 1.0',
  `Titulo` varchar(150) DEFAULT NULL COMMENT 'Encabezado que se muestra sobre el texto',
  `Texto` mediumtext NOT NULL COMMENT 'Texto enriquecido (HTML saneado)',
  `Estado` enum('ACTIVO','INACTIVO') NOT NULL DEFAULT 'INACTIVO' COMMENT 'ACTIVO = es el vigente para su tipo',
  `FechaCreacion` datetime DEFAULT NULL,
  `FechaVigencia` datetime DEFAULT NULL COMMENT 'Momento en que se activó',
  `UsuarioId` int(11) DEFAULT NULL,
  `Username` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Nivel 3: Dependen de Institución y/o Persona
DROP TABLE IF EXISTS `empleado`;
CREATE TABLE `empleado` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `EmpleadoId` int(11) NOT NULL,
  `PersonaId` int(11) DEFAULT NULL,
  `Estado` enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `proveedor`;
CREATE TABLE `proveedor` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `ProveedorId` int(11) NOT NULL,
  `PersonaId` int(11) NOT NULL,
  `Ruc` varchar(20) DEFAULT NULL,
  `RazonSocial` varchar(150) DEFAULT NULL,
  `Estado` enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `estudiante`;
CREATE TABLE `estudiante` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `EstudianteId` int(11) NOT NULL,
  `PersonaId` int(11) DEFAULT NULL,
  `CodigoEstudiante` varchar(20) DEFAULT NULL,
  `RepresentanteId` int(11) DEFAULT NULL,
  `RepresentanteRelacion`  enum('MADRE','PADRE','ABUELO/A','HERMANO/A','TIO/A','REPRESENTANTE LEGAL','TUTOR/A','OTRO') DEFAULT NULL,
  `Estado` enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `usuario`;
CREATE TABLE `usuario` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `PersonaId` int(11) NOT NULL,
  `UsuarioId` int(11) NOT NULL,
  `Username` varchar(50) NOT NULL,
  `PasswordHash` varchar(255) NOT NULL,
  `Email` varchar(150) DEFAULT NULL,
  `UltimoAcceso` datetime DEFAULT NULL,
  `Estado` enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `rolpermiso`;
CREATE TABLE `rolpermiso` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `RolId` int(11) NOT NULL,
  `PermisoId` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `verificacion_codigo`;
CREATE TABLE `verificacion_codigo` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `VerificacionId` int(11) NOT NULL,
  `TipoPersona` enum('ESTUDIANTE','EMPLEADO','PROVEEDOR') NOT NULL,
  `PersonaId` int(11) DEFAULT NULL COMMENT 'Titular consultado',
  `Identificacion` varchar(50) NOT NULL COMMENT 'CÃ©dula o RUC con el que se consultÃ³',
  `Destinatario` varchar(150) NOT NULL COMMENT 'Correo al que se enviÃ³ el cÃ³digo',
  `CodigoHash` char(64) NOT NULL COMMENT 'SHA-256 del cÃ³digo: nunca se guarda en claro',
  `FechaEmision` datetime NOT NULL,
  `FechaExpira` datetime NOT NULL COMMENT 'EmisiÃ³n + 10 minutos',
  `FechaUso` datetime DEFAULT NULL,
  `Intentos` int(11) NOT NULL DEFAULT 0 COMMENT 'CÃ³digos escritos sin acertar',
  `Envio` int(11) NOT NULL DEFAULT 1 COMMENT 'NÃºmero de envÃ­o: 1 el original, 2+ reenvÃ­os',
  `IpOrigen` varchar(45) DEFAULT NULL,
  `Estado` enum('PENDIENTE','USADO','ANULADO') NOT NULL DEFAULT 'PENDIENTE'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci COMMENT='CÃ³digos de verificaciÃ³n por correo de los enlaces pÃºblicos con verificaciÃ³n.';

-- Nivel 4: Dependen de Usuario y otras de Nivel 3
DROP TABLE IF EXISTS `usuariorol`;
CREATE TABLE `usuariorol` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `UsuarioId` int(11) NOT NULL,
  `RolId` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `auditoria`;
CREATE TABLE `auditoria` (
  `InstitucionEducativaId` int(11) NOT NULL COMMENT 'Institución educativa en la que ocurrió el movimiento',
  `AuditoriaId` bigint(20) NOT NULL,
  `FechaHora` datetime NOT NULL COMMENT 'Fecha y hora del movimiento',
  `UsuarioId` int(11) DEFAULT NULL COMMENT 'Usuario que ejecutó la acción',
  `Username` varchar(50) DEFAULT NULL COMMENT 'Nombre de usuario (se conserva aunque la cuenta se elimine)',
  `IpOrigen` varchar(45) DEFAULT NULL COMMENT 'Dirección IP desde la que se realizó la acción',
  `Tabla` varchar(64) NOT NULL COMMENT 'Tabla afectada',
  `RegistroId` varchar(64) DEFAULT NULL COMMENT 'Identificador del registro afectado',
  `Operacion` enum('INSERT','UPDATE','DELETE') NOT NULL,
  `Campo` varchar(64) DEFAULT NULL COMMENT 'Columna modificada',
  `ValorAnterior` text DEFAULT NULL COMMENT 'Valor antes del cambio',
  `ValorNuevo` text DEFAULT NULL COMMENT 'Valor después del cambio'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `consentimiento`;
CREATE TABLE `consentimiento` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `ConsentimientoId` int(11) NOT NULL,
  `PersonaId` int(11) DEFAULT NULL,
  `FinalidadId` int(11) DEFAULT NULL,
  `FechaConsentimiento` datetime DEFAULT NULL,
  `FechaRevocacion` datetime DEFAULT NULL,
  `RepresentanteId` int(11) DEFAULT NULL,
  `MedioConsentimiento` enum('WEB','EMAIL','WHATSAPP','APP') DEFAULT NULL,
  `VersionPolitica` varchar(50) DEFAULT NULL,
  `IpOrigen` varchar(20) DEFAULT NULL,
  `Estado` enum('ACTIVO','INACTIVO') DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Nivel 5: Dependen de Consentimiento
DROP TABLE IF EXISTS `consentimientodato`;
CREATE TABLE `consentimientodato` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `ConsentimientoId` int(11) NOT NULL,
  `TipoDatoId` int(11) NOT NULL,
  `Autorizado` enum('SI','NO') DEFAULT 'NO'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `consentimientohistorial`;
CREATE TABLE `consentimientohistorial` (
  `InstitucionEducativaId` int(11) NOT NULL,
  `HistorialId` int(11) NOT NULL,
  `ConsentimientoId` int(11) DEFAULT NULL,
  `EstadoAnterior` enum('ACTIVO','INACTIVO') DEFAULT NULL,
  `EstadoNuevo` varchar(50) DEFAULT NULL,
  `Accion` varchar(100) DEFAULT NULL,
  `FechaAccion` datetime DEFAULT NULL,
  `UsuarioId` int(11) DEFAULT NULL,
  `IpOrigen` varchar(45) DEFAULT NULL,
  `Observacion` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- ==========================================
-- ÍNDICES
-- ==========================================

ALTER TABLE `auditoria`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`AuditoriaId`),
  ADD UNIQUE KEY `AuditoriaId` (`AuditoriaId`),
  ADD KEY `ix_auditoria_fecha` (`InstitucionEducativaId`,`FechaHora`),
  ADD KEY `ix_auditoria_tabla` (`InstitucionEducativaId`,`Tabla`),
  ADD KEY `ix_auditoria_usuario` (`UsuarioId`);

ALTER TABLE `consentimiento`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`ConsentimientoId`),
  ADD UNIQUE KEY `ConsentimientoId` (`ConsentimientoId`),
  ADD KEY `fk_consentimiento_persona` (`PersonaId`),
  ADD KEY `fk_consentimiento_finalidad` (`FinalidadId`),
  ADD KEY `fk_consentimiento_institucion` (`InstitucionEducativaId`),
  ADD KEY `fk_consentimiento_representante` (`RepresentanteId`);

ALTER TABLE `consentimientodato`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`ConsentimientoId`,`TipoDatoId`),
  ADD KEY `fk_consentimientodato_consentimiento` (`ConsentimientoId`),
  ADD KEY `fk_consentimientodato_tipodato` (`TipoDatoId`);

ALTER TABLE `consentimientohistorial`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`HistorialId`),
  ADD UNIQUE KEY `HistorialId` (`HistorialId`),
  ADD KEY `fk_consentimientohistorial_consentimiento` (`ConsentimientoId`),
  ADD KEY `fk_consentimientohistorial_usuario` (`UsuarioId`);

ALTER TABLE `correo_configuracion`
  ADD PRIMARY KEY (`InstitucionEducativaId`);

ALTER TABLE `disclaimer`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`DisclaimerId`),
  ADD UNIQUE KEY `DisclaimerId` (`DisclaimerId`),
  ADD UNIQUE KEY `uk_disclaimer_version` (`InstitucionEducativaId`,`TipoPersona`,`Version`),
  ADD KEY `ix_disclaimer_vigente` (`InstitucionEducativaId`,`TipoPersona`,`Estado`);

ALTER TABLE `empleado`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`EmpleadoId`),
  ADD UNIQUE KEY `EmpleadoId` (`EmpleadoId`),
  ADD KEY `PersonaId` (`PersonaId`),
  ADD KEY `fk_empleado_institucion` (`InstitucionEducativaId`);

ALTER TABLE `estudiante`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`EstudianteId`),
  ADD UNIQUE KEY `EstudianteId` (`EstudianteId`),
  ADD UNIQUE KEY `CodigoEstudiante` (`CodigoEstudiante`),
  ADD KEY `fk_estudiante_persona` (`PersonaId`),
  ADD KEY `fk_estudiante_institucion` (`InstitucionEducativaId`),
  ADD KEY `fk_representante_persona` (`RepresentanteId`);

ALTER TABLE `finalidad`
  ADD PRIMARY KEY (`FinalidadId`),
  ADD UNIQUE KEY `Codigo` (`Codigo`);

ALTER TABLE `institucion_educativa`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `permiso`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`PermisoId`),
  ADD UNIQUE KEY `PermisoId` (`PermisoId`),
  ADD UNIQUE KEY `uk_permiso_institucion_codigo` (`InstitucionEducativaId`,`Codigo`),
  ADD KEY `fk_permiso_institucion` (`InstitucionEducativaId`);

ALTER TABLE `persona`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`PersonaId`),
  ADD UNIQUE KEY `uk_persona_id` (`PersonaId`),
  ADD UNIQUE KEY `Identificacion` (`Identificacion`),
  ADD UNIQUE KEY `uk_persona_identificacion` (`InstitucionEducativaId`,`Identificacion`),
  ADD KEY `ix_persona_nombre` (`InstitucionEducativaId`,`Apellidos`,`Nombres`),
  ADD KEY `ix_persona_estado` (`InstitucionEducativaId`,`Estado`);

ALTER TABLE `proveedor`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`ProveedorId`),
  ADD UNIQUE KEY `ProveedorId` (`ProveedorId`),
  ADD KEY `fk_proveedor_persona` (`PersonaId`);

ALTER TABLE `rol`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`RolId`),
  ADD UNIQUE KEY `RolId` (`RolId`),
  ADD UNIQUE KEY `uk_rol_institucion_nombre` (`InstitucionEducativaId`,`Nombre`),
  ADD KEY `fk_rol_institucion` (`InstitucionEducativaId`);

ALTER TABLE `rolpermiso`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`RolId`,`PermisoId`),
  ADD KEY `fk_rolpermiso_permiso` (`PermisoId`),
  ADD KEY `fk_rolpermiso_institucion` (`InstitucionEducativaId`),
  ADD KEY `fk_rolpermiso_rol` (`RolId`);

ALTER TABLE `tipodato`
  ADD PRIMARY KEY (`TipoDatoId`),
  ADD UNIQUE KEY `Codigo` (`Codigo`);

ALTER TABLE `usuario`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`PersonaId`),
  ADD UNIQUE KEY `UsuarioId` (`UsuarioId`),
  ADD UNIQUE KEY `Username` (`Username`),
  ADD KEY `fk_usuario_persona` (`PersonaId`);

ALTER TABLE `usuariorol`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`UsuarioId`,`RolId`),
  ADD KEY `fk_usuariorol_institucion` (`InstitucionEducativaId`),
  ADD KEY `RolId` (`RolId`),
  ADD KEY `fk_usuariorol_usuario` (`UsuarioId`);

ALTER TABLE `verificacion_codigo`
  ADD PRIMARY KEY (`InstitucionEducativaId`,`VerificacionId`),
  ADD UNIQUE KEY `uk_verificacion_id` (`VerificacionId`),
  ADD KEY `fk_verificacion_persona` (`PersonaId`),
  ADD KEY `ix_verificacion_busqueda` (`InstitucionEducativaId`,`TipoPersona`,`Identificacion`,`Estado`),
  ADD KEY `ix_verificacion_expira` (`FechaExpira`);

-- ==========================================
-- AUTO_INCREMENT
-- ==========================================

ALTER TABLE `auditoria` MODIFY `AuditoriaId` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;
ALTER TABLE `consentimiento` MODIFY `ConsentimientoId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;
ALTER TABLE `consentimientohistorial` MODIFY `HistorialId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;
ALTER TABLE `disclaimer` MODIFY `DisclaimerId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
ALTER TABLE `empleado` MODIFY `EmpleadoId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
ALTER TABLE `estudiante` MODIFY `EstudianteId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;
ALTER TABLE `finalidad` MODIFY `FinalidadId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
ALTER TABLE `permiso` MODIFY `PermisoId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;
ALTER TABLE `persona` MODIFY `PersonaId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;
ALTER TABLE `proveedor` MODIFY `ProveedorId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
ALTER TABLE `rol` MODIFY `RolId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;
ALTER TABLE `tipodato` MODIFY `TipoDatoId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
ALTER TABLE `usuario` MODIFY `UsuarioId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;
ALTER TABLE `verificacion_codigo` MODIFY `VerificacionId` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

-- ==========================================
-- RESTRICCIONES (CLAVES FORÁNEAS)
-- ==========================================

ALTER TABLE `auditoria`
  ADD CONSTRAINT `fk_auditoria_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_auditoria_usuario` FOREIGN KEY (`UsuarioId`) REFERENCES `usuario` (`UsuarioId`) ON UPDATE CASCADE;

ALTER TABLE `consentimiento`
  ADD CONSTRAINT `fk_consentimiento_finalidad` FOREIGN KEY (`FinalidadId`) REFERENCES `finalidad` (`FinalidadId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimiento_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimiento_persona` FOREIGN KEY (`PersonaId`) REFERENCES `persona` (`PersonaId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimiento_representante` FOREIGN KEY (`RepresentanteId`) REFERENCES `persona` (`PersonaId`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `consentimientodato`
  ADD CONSTRAINT `fk_consentimientodato_consentimiento` FOREIGN KEY (`ConsentimientoId`) REFERENCES `consentimiento` (`ConsentimientoId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimientodato_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimientodato_tipodato` FOREIGN KEY (`TipoDatoId`) REFERENCES `tipodato` (`TipoDatoId`) ON UPDATE CASCADE;

ALTER TABLE `consentimientohistorial`
  ADD CONSTRAINT `fk_consentimientohistorial_consentimiento` FOREIGN KEY (`ConsentimientoId`) REFERENCES `consentimiento` (`ConsentimientoId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimientohistorial_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_consentimientohistorial_usuario` FOREIGN KEY (`UsuarioId`) REFERENCES `usuario` (`UsuarioId`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `correo_configuracion`
  ADD CONSTRAINT `fk_correoconfig_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `disclaimer`
  ADD CONSTRAINT `fk_disclaimer_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON UPDATE CASCADE;

ALTER TABLE `empleado`
  ADD CONSTRAINT `fk_empleado_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_empleado_persona` FOREIGN KEY (`PersonaId`) REFERENCES `persona` (`PersonaId`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `estudiante`
  ADD CONSTRAINT `fk_estudiante_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_estudiante_persona` FOREIGN KEY (`PersonaId`) REFERENCES `persona` (`PersonaId`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_representante_persona` FOREIGN KEY (`RepresentanteId`) REFERENCES `persona` (`PersonaId`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `permiso`
  ADD CONSTRAINT `fk_permiso_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON UPDATE CASCADE;

ALTER TABLE `persona`
  ADD CONSTRAINT `fk_persona_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON DELETE NO ACTION ON UPDATE CASCADE;

ALTER TABLE `proveedor`
  ADD CONSTRAINT `fk_proveedor_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_proveedor_persona` FOREIGN KEY (`PersonaId`) REFERENCES `persona` (`PersonaId`) ON UPDATE CASCADE;

ALTER TABLE `rol`
  ADD CONSTRAINT `fk_rol_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON UPDATE CASCADE;

ALTER TABLE `rolpermiso`
  ADD CONSTRAINT `fk_rolpermiso_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rolpermiso_permiso` FOREIGN KEY (`PermisoId`) REFERENCES `permiso` (`PermisoId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rolpermiso_rol` FOREIGN KEY (`RolId`) REFERENCES `rol` (`RolId`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `usuario`
  ADD CONSTRAINT `fk_usuario_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_usuario_persona` FOREIGN KEY (`PersonaId`) REFERENCES `persona` (`PersonaId`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `usuariorol`
  ADD CONSTRAINT `fk_usuariorol_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_usuariorol_rol` FOREIGN KEY (`RolId`) REFERENCES `rol` (`RolId`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_usuariorol_usuario` FOREIGN KEY (`UsuarioId`) REFERENCES `usuario` (`UsuarioId`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `verificacion_codigo`
  ADD CONSTRAINT `fk_verificacion_institucion` FOREIGN KEY (`InstitucionEducativaId`) REFERENCES `institucion_educativa` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_verificacion_persona` FOREIGN KEY (`PersonaId`) REFERENCES `persona` (`PersonaId`) ON DELETE CASCADE ON UPDATE CASCADE;

COMMIT;