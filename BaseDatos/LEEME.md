# Scripts de base de datos

Dos archivos, en orden. El primero crea la estructura; el segundo carga los
datos con los que el sistema arranca.

| Archivo | Contenido |
|---|---|
| `01_DDL_estructura.sql` | **Estructura (DDL).** Base de datos, 19 tablas, claves, índices y las 33 relaciones entre ellas. |
| `02_DML_datos.sql` | **Datos (DML).** Institución, catálogos, 21 permisos, 5 roles con sus asignaciones, disclaimers y cuentas de acceso. |

Motor: **MySQL 5.7 o superior**, o **MariaDB 10.3 o superior**.

## Instalación

```bash
mysql -u USUARIO -p --default-character-set=utf8mb4 < 01_DDL_estructura.sql
mysql -u USUARIO -p --default-character-set=utf8mb4 < 02_DML_datos.sql
```

Desde phpMyAdmin: pestaña **Importar**, primero un archivo y luego el otro,
dejando el juego de caracteres en **utf-8**.

> **Los dos archivos están guardados en UTF-8.** El `--default-character-set=utf8mb4`
> del ejemplo no es un adorno: sin él, algunos clientes de MySQL se conectan en
> latin1 y las tildes y las eñes entran dobladas —«Muñoz» se convierte en
> «MuÃ±oz»—. No es un error del archivo ni de la base: es la conexión con la que
> se cargó. Si ya le ocurrió, vuelva a cargar el DML con la opción puesta.
> Lo mismo vale si edita estos archivos: guárdelos siempre en UTF-8.

Cada script termina con consultas de verificación que muestran lo que quedó
creado. El DDL debe reportar **19 tablas y 33 claves foráneas**; el DML, los
conteos de cada catálogo y qué permisos otorga cada rol.

Después de instalar, revise que `config.php` apunte a la base correcta:

```php
'db_name' => 'ezyro_42650191_protecciondatos',
```

## Cómo entrar la primera vez

| Usuario | Contraseña | Qué ve |
|---|---|---|
| `admin` | `Clave2026*` | Todo el sistema, en cualquier institución |

**Cambie esa contraseña en cuanto entre**, desde *Usuarios del Sistema*.

El DML crea además cuatro cuentas de prueba —`seguridades`, `registro`,
`consultas` y `reportes`, con la misma contraseña— para comprobar que cada rol
ve exactamente lo que le corresponde. **Elimínelas en producción:**

```sql
DELETE FROM usuario WHERE Username IN ('seguridades','registro','consultas','reportes');
```

Si no las quiere ni siquiera al instalar, no ejecute la sección 9 del DML.

## Cómo está organizado cada script

Ambos están divididos en secciones numeradas y comentadas.

**`01_DDL_estructura.sql`**

| Sección | Qué crea |
|---|---|
| 1 | Preparación de la base de datos |
| 2 | Núcleo multi-institución: `institucion_educativa` |
| 3 | Catálogos: `finalidad`, `tipodato` |
| 4 | Padrón de personas por institución: `persona` |
| 5 | Vínculos: `empleado`, `estudiante`, `proveedor` |
| 6 | Consentimientos: `consentimiento`, `consentimientodato`, `consentimientohistorial` |
| 7 | Seguridad: `rol`, `permiso`, `rolpermiso`, `usuario`, `usuariorol` |
| 8 | Parámetros: `disclaimer`, `correo_configuracion`, `verificacion_codigo` |
| 9 | Auditoría: `auditoria` |
| 10 | Integridad referencial: todas las claves foráneas juntas |
| 11 | Verificación |
| 12 | Notas para bases de datos ya existentes |

Las claves foráneas van al final a propósito: así el orden de creación de las
tablas no importa y se pueden leer juntas, con el criterio de borrado de cada
una (`RESTRICT` protege, `SET NULL` conserva el histórico, `CASCADE` arrastra el
detalle).

**`02_DML_datos.sql`**

| Sección | Qué carga |
|---|---|
| 1 | Preparación e institución sobre la que se carga |
| 2 | Institución educativa |
| 3 | Catálogos: finalidades y tipos de dato |
| 4 | Permisos, agrupados por módulo |
| 5 | Roles |
| 6 | Asignación de permisos a cada rol |
| 7 | Disclaimers de política, uno por tipo de persona |
| 8 | Cuenta de administrador |
| 9 | Usuarios de prueba (opcional) |
| 10 | Verificación |

**Todo el DML es idempotente:** puede ejecutarlo las veces que quiera sin
duplicar nada. Cada inserción comprueba antes si el registro existe, y las
referencias se resuelven por código o por nombre —nunca por un número fijo—, de
modo que no depende de los valores que haya tomado el `AUTO_INCREMENT`.

## Instalar una segunda institución

El DML carga los datos de la institución indicada al inicio del archivo:

```sql
SET @institucion := 1;
```

Para preparar otra, cree primero su fila en `institucion_educativa`, cambie ese
número y vuelva a ejecutar el script: creará sus propios permisos, roles y
disclaimers sin tocar los de la primera.

Los índices de `rol.Nombre` y `permiso.Codigo` son **únicos por institución**,
de modo que dos instituciones pueden tener roles con el mismo nombre. Si su base
viene de una versión anterior donde eran únicos globales, la sección 12 del DDL
explica cómo corregirlo.

## Actualizar una base de datos ya existente

El DDL no altera lo que ya exista: los `CREATE TABLE` llevan `IF NOT EXISTS`.
Para actualizar en lugar de instalar desde cero, la **sección 12** del propio
archivo indica qué ejecutar: las tablas incorporadas después de la versión
inicial (`auditoria`, `disclaimer`, `correo_configuracion`) con sus claves
foráneas, el ajuste de los índices por institución y la limpieza de dos tablas
que quedaron sin uso.

### Cambios de esta versión

Respecto de la versión anterior del DDL, la estructura cambió en estos puntos:

| Tabla | Cambio |
|---|---|
| `persona` | Se elimina la columna `FechaNacimiento` |
| `empleado` | Se eliminan las columnas `Cargo` y `Departamento` |
| `estudiante` | `RepresentanteRelacion` cambia de lista (ver abajo); se elimina `Carrera_Especialidad` |
| `verificacion_codigo` | **Tabla nueva.** Códigos de un solo uso de los enlaces con verificación |
| `persona` | **Pasa a ser por institución:** nueva columna `InstitucionEducativaId`, primaria `(InstitucionEducativaId, PersonaId)`, clave foránea contra la institución y la identificación única **dentro de cada institución** |

Los **parentescos del representante** se agrupan y se agrega uno nuevo:

| Antes | Después |
|---|---|
| `ABUELO`, `ABUELA` | `ABUELO/A` |
| `TIO`, `TIA` | `TIO/A` |
| — | `HERMANO/A` *(nuevo)* |

El resto —`MADRE`, `PADRE`, `REPRESENTANTE LEGAL`, `TUTOR/A`, `OTRO`— no cambia.
Para llevar una base ya instalada a la lista nueva, ejecute
`08_ALTER_relacion_representante.sql`, que se entrega aparte. **No estreche el
enum a mano:** MySQL convertiría en cadena vacía a todo estudiante que diga
`ABUELO`, `ABUELA`, `TIO` o `TIA`, y se perdería su parentesco sin aviso. El
script lo hace en tres pasos —amplía la lista, convierte los datos y recién
entonces la deja definitiva— y comprueba al final que nadie quedó en blanco.

Las pantallas no necesitan cambio: leen las opciones del propio enum, de modo
que en cuanto el script se ejecute los desplegables muestran la lista nueva.

La bitácora de auditoría deja de guardar el contenido de los datos: se eliminan
las columnas `ValorAnterior` y `ValorNuevo` de `auditoria`. A partir de aquí
anota el **QUÉ**, no el **DATO** —que se modificó, por ejemplo, el correo de una
persona, con quién, cuándo y desde qué IP, pero no el correo—, de modo que la
propia bitácora de un sistema de protección de datos no sea una segunda copia,
sin control de acceso propio y sin caducidad, de lo que custodia. Para llevar una
base ya instalada a este modelo, ejecute `07_ALTER_auditoria_sin_valores.sql`,
que se entrega aparte. **Respalde antes:** al eliminar las columnas se pierde
también lo que ya estaba grabado en ellas.

En los **datos** cambió un nombre. La opción que publicaba los enlaces abiertos
de consentimiento se retiró, y la que quedó —los enlaces con verificación de
identidad— pasó a llamarse **Enlaces de Consentimiento**. El permiso conserva su
código `ADM_ENLACES_VERIF`, que es un identificador interno: cambiarlo dejaría
huérfanas las asignaciones de rol ya hechas. Lo que cambia es el nombre que se
ve en *Permisos* y en *Roles*:

```sql
UPDATE permiso
   SET Nombre = 'Enlaces de Consentimiento'
 WHERE Codigo = 'ADM_ENLACES_VERIF';
```

Está incluido en `02_DML_datos.sql`, que puede volver a ejecutarse sin
duplicar nada. La opción retirada no tenía permiso propio —se apoyaba en
`ADM_CORREO`, el de la configuración de correo—, de modo que no hay ninguna fila
que eliminar.

### Si una pantalla falla con «Unknown column»

Significa que la base y el código no coinciden. En lugar de ir pantalla por
pantalla, ejecute `04_REVISION_estructura.sql`, que se entrega aparte: **no
modifica nada**, solo compara su base contra la estructura esperada y le dice de
una vez qué tabla o columna falta. Después ejecute el script de actualización.

Si su base ya estaba instalada, **no vuelva a ejecutar el DDL**: aplique el
script de actualización `03_ALTER_actualizacion_estructura.sql`, que se entrega
aparte. Es idempotente y trae un bloque opcional para resguardar el contenido de
las tres columnas antes de eliminarlas. Respalde la base antes de ejecutarlo:
eliminar una columna elimina también su contenido.

Las pantallas del sistema ya no piden ni muestran esos tres campos, y la
plantilla de la **PreCarga Inicial** (*Registro de Datos › PreCarga Inicial*,
solo para SuperAdmin) tampoco los incluye.

El sistema tolera que la base esté un paso atrás en un punto concreto: las
relaciones del representante que ofrecen las pantallas se leen del propio enum
de `estudiante`.`RepresentanteRelacion`, de modo que nunca se ofrece una que la
base no pueda guardar. Si intenta grabar una relación que su base todavía no
reconoce, el sistema lo dice con claridad en vez de dejar que MySQL responda con
un error de truncamiento.

`verificacion_codigo` guarda los códigos de los **Enlaces de Consentimiento**. De cada código solo se conserva su huella SHA-256, nunca su
valor; caduca a los 10 minutos y las filas de más de un día se borran solas en
la siguiente consulta. La tabla puede vaciarse en cualquier momento sin
consecuencias: no tiene valor histórico.

## Cómo se relacionan las tablas

```
institucion_educativa
    │
    ├─→ persona ──────────────────┐   (el padrón de la institución)
    │      ↑                      │
    │      │ PersonaId            ↓
    ├─→ empleado ─────────────────┤
    ├─→ estudiante ───────────────┤   (también apunta a su representante)
    ├─→ proveedor ────────────────┤
    ├─→ consentimiento ───────────┤
    │       ├─→ consentimientodato ─→ tipodato
    │       ├─→ consentimientohistorial
    │       └─→ finalidad
    ├─→ usuario ──────────────────┘
    │       └─→ usuariorol ─→ rol ─→ rolpermiso ─→ permiso
    ├─→ disclaimer
    ├─→ correo_configuracion
    ├─→ verificacion_codigo ──────→ persona
    └─→ auditoria
```

Todo cuelga de `institucion_educativa`: cada tabla de datos lleva su
`InstitucionEducativaId` y ninguna consulta del sistema devuelve filas de otra
institución.


`persona` es el padrón de cada institución: dentro de ella no puede repetirse un
documento, y sobre esas fichas se apoyan los vínculos institucionales. Por eso la
misma persona puede ser empleado y representante de un estudiante **de la misma
institución** sin duplicarse.

Es una entidad **padre**, y como tal **no tiene mantenimiento propio**: no hay
opción de menú para personas. Sus fichas nacen desde Empleados, Estudiantes
—titular y representante—, Proveedores, los enlaces públicos o la PreCarga
Inicial, y se reutilizan cuando el documento ya consta. Toda la escritura pasa
por un único punto del código, `api/core/Padron.php`.

Entre instituciones no se comparte nada: si la misma persona se relaciona con dos
de ellas, cada una tiene su propia ficha y ninguna ve la de la otra. `PersonaId`
sigue siendo único en toda la base —es lo que permite que las demás tablas lo
referencien con una sola columna—, pero siempre se lee junto con su institución.

Para llevar una base ya instalada a este modelo, ejecute
`05_ALTER_persona_por_institucion.sql`, que se entrega aparte: agrega la columna,
asigna la institución 1 a las personas que ya existen, reorganiza los índices y
crea la clave foránea.

Tenga presente que `usuario.PersonaId` está declarado `ON DELETE CASCADE`:
borrar una persona elimina también su cuenta de acceso.

# Scripts de base de datos

Dos archivos, en orden. El primero crea la estructura; el segundo carga los
datos con los que el sistema arranca.

| Archivo | Contenido |
|---|---|
| `01_DDL_estructura.sql` | **Estructura (DDL).** Base de datos, 19 tablas, claves, índices y las 33 relaciones entre ellas. |
| `02_DML_datos.sql` | **Datos (DML).** Institución, catálogos, 21 permisos, 5 roles con sus asignaciones, disclaimers y cuentas de acceso. |

Motor: **MySQL 5.7 o superior**, o **MariaDB 10.3 o superior**.

## Instalación

```bash
mysql -u USUARIO -p < 01_DDL_estructura.sql
mysql -u USUARIO -p < 02_DML_datos.sql
```

Desde phpMyAdmin: pestaña **Importar**, primero un archivo y luego el otro.

Cada script termina con consultas de verificación que muestran lo que quedó
creado. El DDL debe reportar **19 tablas y 33 claves foráneas**; el DML, los
conteos de cada catálogo y qué permisos otorga cada rol.

Después de instalar, revise que `config.php` apunte a la base correcta:

```php
'db_name' => 'ezyro_42650191_protecciondatos',
```

## Cómo entrar la primera vez

| Usuario | Contraseña | Qué ve |
|---|---|---|
| `admin` | `Clave2026*` | Todo el sistema, en cualquier institución |

**Cambie esa contraseña en cuanto entre**, desde *Usuarios del Sistema*.

El DML crea además cuatro cuentas de prueba —`seguridades`, `registro`,
`consultas` y `reportes`, con la misma contraseña— para comprobar que cada rol
ve exactamente lo que le corresponde. **Elimínelas en producción:**

```sql
DELETE FROM usuario WHERE Username IN ('seguridades','registro','consultas','reportes');
```

Si no las quiere ni siquiera al instalar, no ejecute la sección 9 del DML.

## Cómo está organizado cada script

Ambos están divididos en secciones numeradas y comentadas.

**`01_DDL_estructura.sql`**

| Sección | Qué crea |
|---|---|
| 1 | Preparación de la base de datos |
| 2 | Núcleo multi-institución: `institucion_educativa` |
| 3 | Catálogos: `finalidad`, `tipodato` |
| 4 | Padrón de personas por institución: `persona` |
| 5 | Vínculos: `empleado`, `estudiante`, `proveedor` |
| 6 | Consentimientos: `consentimiento`, `consentimientodato`, `consentimientohistorial` |
| 7 | Seguridad: `rol`, `permiso`, `rolpermiso`, `usuario`, `usuariorol` |
| 8 | Parámetros: `disclaimer`, `correo_configuracion`, `verificacion_codigo` |
| 9 | Auditoría: `auditoria` |
| 10 | Integridad referencial: todas las claves foráneas juntas |
| 11 | Verificación |
| 12 | Notas para bases de datos ya existentes |

Las claves foráneas van al final a propósito: así el orden de creación de las
tablas no importa y se pueden leer juntas, con el criterio de borrado de cada
una (`RESTRICT` protege, `SET NULL` conserva el histórico, `CASCADE` arrastra el
detalle).

**`02_DML_datos.sql`**

| Sección | Qué carga |
|---|---|
| 1 | Preparación e institución sobre la que se carga |
| 2 | Institución educativa |
| 3 | Catálogos: finalidades y tipos de dato |
| 4 | Permisos, agrupados por módulo |
| 5 | Roles |
| 6 | Asignación de permisos a cada rol |
| 7 | Disclaimers de política, uno por tipo de persona |
| 8 | Cuenta de administrador |
| 9 | Usuarios de prueba (opcional) |
| 10 | Verificación |

**Todo el DML es idempotente:** puede ejecutarlo las veces que quiera sin
duplicar nada. Cada inserción comprueba antes si el registro existe, y las
referencias se resuelven por código o por nombre —nunca por un número fijo—, de
modo que no depende de los valores que haya tomado el `AUTO_INCREMENT`.

## Instalar una segunda institución

El DML carga los datos de la institución indicada al inicio del archivo:

```sql
SET @institucion := 1;
```

Para preparar otra, cree primero su fila en `institucion_educativa`, cambie ese
número y vuelva a ejecutar el script: creará sus propios permisos, roles y
disclaimers sin tocar los de la primera.

Los índices de `rol.Nombre` y `permiso.Codigo` son **únicos por institución**,
de modo que dos instituciones pueden tener roles con el mismo nombre. Si su base
viene de una versión anterior donde eran únicos globales, la sección 12 del DDL
explica cómo corregirlo.

## Actualizar una base de datos ya existente

El DDL no altera lo que ya exista: los `CREATE TABLE` llevan `IF NOT EXISTS`.
Para actualizar en lugar de instalar desde cero, la **sección 12** del propio
archivo indica qué ejecutar: las tablas incorporadas después de la versión
inicial (`auditoria`, `disclaimer`, `correo_configuracion`) con sus claves
foráneas, el ajuste de los índices por institución y la limpieza de dos tablas
que quedaron sin uso.

### Cambios de esta versión

Respecto de la versión anterior del DDL, la estructura cambió en estos puntos:

| Tabla | Cambio |
|---|---|
| `persona` | Se elimina la columna `FechaNacimiento` |
| `empleado` | Se eliminan las columnas `Cargo` y `Departamento` |
| `estudiante` | `RepresentanteRelacion` cambia de lista (ver abajo); se elimina `Carrera_Especialidad` |
| `verificacion_codigo` | **Tabla nueva.** Códigos de un solo uso de los enlaces con verificación |
| `persona` | **Pasa a ser por institución:** nueva columna `InstitucionEducativaId`, primaria `(InstitucionEducativaId, PersonaId)`, clave foránea contra la institución y la identificación única **dentro de cada institución** |

Los **parentescos del representante** se agrupan y se agrega uno nuevo:

| Antes | Después |
|---|---|
| `ABUELO`, `ABUELA` | `ABUELO/A` |
| `TIO`, `TIA` | `TIO/A` |
| — | `HERMANO/A` *(nuevo)* |

El resto —`MADRE`, `PADRE`, `REPRESENTANTE LEGAL`, `TUTOR/A`, `OTRO`— no cambia.
Para llevar una base ya instalada a la lista nueva, ejecute
`08_ALTER_relacion_representante.sql`, que se entrega aparte. **No estreche el
enum a mano:** MySQL convertiría en cadena vacía a todo estudiante que diga
`ABUELO`, `ABUELA`, `TIO` o `TIA`, y se perdería su parentesco sin aviso. El
script lo hace en tres pasos —amplía la lista, convierte los datos y recién
entonces la deja definitiva— y comprueba al final que nadie quedó en blanco.

Las pantallas no necesitan cambio: leen las opciones del propio enum, de modo
que en cuanto el script se ejecute los desplegables muestran la lista nueva.

La bitácora de auditoría deja de guardar el contenido de los datos: se eliminan
las columnas `ValorAnterior` y `ValorNuevo` de `auditoria`. A partir de aquí
anota el **QUÉ**, no el **DATO** —que se modificó, por ejemplo, el correo de una
persona, con quién, cuándo y desde qué IP, pero no el correo—, de modo que la
propia bitácora de un sistema de protección de datos no sea una segunda copia,
sin control de acceso propio y sin caducidad, de lo que custodia. Para llevar una
base ya instalada a este modelo, ejecute `07_ALTER_auditoria_sin_valores.sql`,
que se entrega aparte. **Respalde antes:** al eliminar las columnas se pierde
también lo que ya estaba grabado en ellas.

En los **datos** cambió un nombre. La opción que publicaba los enlaces abiertos
de consentimiento se retiró, y la que quedó —los enlaces con verificación de
identidad— pasó a llamarse **Enlaces de Consentimiento**. El permiso conserva su
código `ADM_ENLACES_VERIF`, que es un identificador interno: cambiarlo dejaría
huérfanas las asignaciones de rol ya hechas. Lo que cambia es el nombre que se
ve en *Permisos* y en *Roles*:

```sql
UPDATE permiso
   SET Nombre = 'Enlaces de Consentimiento'
 WHERE Codigo = 'ADM_ENLACES_VERIF';
```

Está incluido en `02_DML_datos.sql`, que puede volver a ejecutarse sin
duplicar nada. La opción retirada no tenía permiso propio —se apoyaba en
`ADM_CORREO`, el de la configuración de correo—, de modo que no hay ninguna fila
que eliminar.

### Si una pantalla falla con «Unknown column»

Significa que la base y el código no coinciden. En lugar de ir pantalla por
pantalla, ejecute `04_REVISION_estructura.sql`, que se entrega aparte: **no
modifica nada**, solo compara su base contra la estructura esperada y le dice de
una vez qué tabla o columna falta. Después ejecute el script de actualización.

Si su base ya estaba instalada, **no vuelva a ejecutar el DDL**: aplique el
script de actualización `03_ALTER_actualizacion_estructura.sql`, que se entrega
aparte. Es idempotente y trae un bloque opcional para resguardar el contenido de
las tres columnas antes de eliminarlas. Respalde la base antes de ejecutarlo:
eliminar una columna elimina también su contenido.

Las pantallas del sistema ya no piden ni muestran esos tres campos, y la
plantilla de la **PreCarga Inicial** (*Registro de Datos › PreCarga Inicial*,
solo para SuperAdmin) tampoco los incluye.

El sistema tolera que la base esté un paso atrás en un punto concreto: las
relaciones del representante que ofrecen las pantallas se leen del propio enum
de `estudiante`.`RepresentanteRelacion`, de modo que nunca se ofrece una que la
base no pueda guardar. Si intenta grabar una relación que su base todavía no
reconoce, el sistema lo dice con claridad en vez de dejar que MySQL responda con
un error de truncamiento.

`verificacion_codigo` guarda los códigos de los **Enlaces de Consentimiento**. De cada código solo se conserva su huella SHA-256, nunca su
valor; caduca a los 10 minutos y las filas de más de un día se borran solas en
la siguiente consulta. La tabla puede vaciarse en cualquier momento sin
consecuencias: no tiene valor histórico.

## Cómo se relacionan las tablas

```
institucion_educativa
    │
    ├─→ persona ──────────────────┐   (el padrón de la institución)
    │      ↑                      │
    │      │ PersonaId            ↓
    ├─→ empleado ─────────────────┤
    ├─→ estudiante ───────────────┤   (también apunta a su representante)
    ├─→ proveedor ────────────────┤
    ├─→ consentimiento ───────────┤
    │       ├─→ consentimientodato ─→ tipodato
    │       ├─→ consentimientohistorial
    │       └─→ finalidad
    ├─→ usuario ──────────────────┘
    │       └─→ usuariorol ─→ rol ─→ rolpermiso ─→ permiso
    ├─→ disclaimer
    ├─→ correo_configuracion
    ├─→ verificacion_codigo ──────→ persona
    └─→ auditoria
```

Todo cuelga de `institucion_educativa`: cada tabla de datos lleva su
`InstitucionEducativaId` y ninguna consulta del sistema devuelve filas de otra
institución.


`persona` es el padrón de cada institución: dentro de ella no puede repetirse un
documento, y sobre esas fichas se apoyan los vínculos institucionales. Por eso la
misma persona puede ser empleado y representante de un estudiante **de la misma
institución** sin duplicarse.

Es una entidad **padre**, y como tal **no tiene mantenimiento propio**: no hay
opción de menú para personas. Sus fichas nacen desde Empleados, Estudiantes
—titular y representante—, Proveedores, los enlaces públicos o la PreCarga
Inicial, y se reutilizan cuando el documento ya consta. Toda la escritura pasa
por un único punto del código, `api/core/Padron.php`.

Entre instituciones no se comparte nada: si la misma persona se relaciona con dos
de ellas, cada una tiene su propia ficha y ninguna ve la de la otra. `PersonaId`
sigue siendo único en toda la base —es lo que permite que las demás tablas lo
referencien con una sola columna—, pero siempre se lee junto con su institución.

Para llevar una base ya instalada a este modelo, ejecute
`05_ALTER_persona_por_institucion.sql`, que se entrega aparte: agrega la columna,
asigna la institución 1 a las personas que ya existen, reorganiza los índices y
crea la clave foránea.

Tenga presente que `usuario.PersonaId` está declarado `ON DELETE CASCADE`:
borrar una persona elimina también su cuenta de acceso.
