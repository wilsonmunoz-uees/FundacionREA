# Scripts de base de datos

Dos archivos, en orden. El primero crea la estructura; el segundo carga los
datos con los que el sistema arranca.

| Archivo | Contenido |
|---|---|
| `01_DDL_estructura.sql` | **Estructura (DDL).** Base de datos, 20 tablas, claves, índices y las 36 relaciones entre ellas. |
| `02_DML_datos.sql` | **Datos (DML).** Las **21 instituciones** de la red con su dirección y su teléfono, catálogos, permisos y roles replicados a cada institución, disclaimers y la cuenta de administrador. |

Motor: **MySQL 5.7 o superior**, o **MariaDB 10.3 o superior**.

## Instalación

Los scripts **no crean ni seleccionan la base de datos**: se ejecutan sobre una
que ya exista y esté elegida. Créela primero y nómbrela en la orden:

```bash
mysql -u USUARIO -p -e "CREATE DATABASE NOMBRE_BASE DEFAULT CHARACTER SET utf8mb4"
mysql -u USUARIO -p --default-character-set=utf8mb4 NOMBRE_BASE < 01_DDL_estructura.sql
mysql -u USUARIO -p --default-character-set=utf8mb4 NOMBRE_BASE < 02_DML_datos.sql
```

Desde phpMyAdmin: elija primero la base en el panel de la izquierda y luego
pestaña **Importar**, primero un archivo y luego el otro, dejando el juego de
caracteres en **utf-8**.

> **Los dos archivos están guardados en UTF-8.** El `--default-character-set=utf8mb4`
> del ejemplo no es un adorno: sin él, algunos clientes de MySQL se conectan en
> latin1 y las tildes y las eñes entran dobladas —«Muñoz» se convierte en
> «MuÃ±oz»—. No es un error del archivo ni de la base: es la conexión con la que
> se cargó. Si ya le ocurrió, vuelva a cargar el DML con la opción puesta.
> Lo mismo vale si edita estos archivos: guárdelos siempre en UTF-8.

El DDL crea **20 tablas y 36 claves foráneas**. Al terminar el DML debe haber
**21 instituciones**, y cada una con su propio juego de roles y permisos: el
script los define una vez para la institución 1 y los replica a las demás.

> **La contraseña del correo saliente va en blanco a propósito.** Este archivo
> se copia, se sube al repositorio y se manda por correo; una contraseña escrita
> aquí dentro es una contraseña publicada. Se escribe una sola vez desde
> *Registro de Datos › Configuración de Correo*, y el sistema la guarda sin
> devolverla nunca a ninguna pantalla ni a la bitácora. Hasta que se escriba, el
> sistema no puede enviar códigos de verificación ni confirmaciones.

Después de instalar, revise que `config.php` apunte a la base correcta:

```php
'db_name' => 'ezyro_42650191_protecciondatos',
```

## Cómo entrar la primera vez

| Usuario | Qué ve |
|---|---|
| `admin` | Todo el sistema, en cualquier institución de la red |

Es la **única** cuenta que crea el DML, y es de la institución 1. Su contraseña
va como hash en el script; **cámbiela en cuanto entre**, desde *Usuarios del
Sistema*.

Las demás cuentas se crean desde la propia aplicación, cada una en su
institución: el sistema genera su contraseña y se la envía por correo a la
persona, de modo que quien administra no llega a conocerla.

## El correo saliente se configura, no viene puesto

La carga inicial deja preparada la ficha de `correo_configuracion` —servidor,
puerto, usuario y remitente— pero **con la contraseña en blanco**: hasta que se
escriba desde *Registro de Datos › Configuración de Correo*, el sistema no
puede enviar códigos de verificación ni confirmaciones.

Antes esa contraseña viajaba escrita en el propio archivo. Un archivo de carga
inicial se copia, se sube al repositorio y se manda por correo; una contraseña
ahí dentro es una contraseña publicada. Si su base todavía tiene la que venía
en versiones anteriores, cámbiela en el proveedor de correo y vuelva a
escribirla desde la pantalla: el sistema la guarda y no la devuelve nunca a
ninguna pantalla ni a la bitácora.

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
| 7 | Seguridad: `rol`, `permiso`, `rolpermiso`, `usuario`, `usuariorol`, `usuario_institucion` |
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

| Orden | Qué carga |
|---|---|
| 1 | Las 21 instituciones educativas de la red |
| 2 | Catálogos: finalidades y tipos de dato |
| 3 | La persona del administrador |
| 4 | Roles de la institución 1, y su copia a las otras 20 |
| 5 | Permisos de la institución 1, y su copia a las otras 20 |
| 6 | Qué permisos tiene cada rol, en cada institución |
| 7 | Configuración de correo (con la contraseña en blanco) |
| 8 | Disclaimers de política, uno por tipo de persona |
| 9 | La cuenta `admin` y su rol |
| 10 | Las instituciones en las que puede entrar cada cuenta |

**El DML es para una base recién creada**, no para una que ya tenga datos: las
inserciones llevan identificadores fijos y se pisarían con lo que hubiera. Para
actualizar una instalación en marcha están los scripts `03_` a `13_`, que se
entregan aparte.

## Añadir una institución más

El DML ya deja cargadas las **21 instituciones** de la red, y a cada una le
replica los roles y los permisos: los define una sola vez para la institución 1
y los copia al resto con un `INSERT ... SELECT`.

Para incorporar una institución nueva más adelante, dese de alta su ficha desde
*Registro de Datos › Instituciones Educativas* y después repita esos dos bloques
del DML —el de `rol` y el de `permiso` que empiezan con `INSERT ... SELECT`—,
que copiarán a la nueva lo que ya tienen las demás sin tocar nada de lo
existente. Recuerde dejar su logotipo en `assets/logos/NN.png`.

Los índices de `rol.Nombre` y `permiso.Codigo` son **únicos por institución**,
de modo que dos instituciones pueden tener roles con el mismo nombre. Si su base
viene de una versión anterior donde eran únicos globales, la sección 12 del DDL
explica cómo corregirlo.

### La misma persona en varias instituciones

Lo mismo vale para las personas, y conviene tenerlo claro porque es lo que más
confunde: **la identificación es única dentro de cada institución, no en toda la
red.** Un padre con hijos en dos escuelas es representante en las dos, y un
empleado de una institución puede ser proveedor de otra. Cada institución guarda
entonces su propia ficha de esa persona, con su propio correo y su propio
teléfono, y sus consentimientos son también los suyos: eso es precisamente lo
que permite que una institución no vea los datos de otra.

El índice que lo garantiza es `uk_persona_identificacion
(InstitucionEducativaId, Identificacion)`. Si su base viene de cuando el sistema
atendía a una sola institución, arrastra además un único **global** llamado
`Identificacion`, y ese sobra: mientras esté, registrar en la segunda
institución a alguien que ya consta en la primera falla con

```
Duplicate entry '0925651671' for key 'Identificacion'
```

Le ocurre igual a empleados, estudiantes, representantes y proveedores, porque
todos se apoyan en `persona`. `estudiante`.`CodigoEstudiante` tiene el mismo
resto —cada institución numera a sus alumnos por su cuenta—. Para corregirlo
ejecute `11_ALTER_unicos_por_institucion.sql`, que se entrega aparte: solo
retira restricciones que sobran, no borra ni modifica ninguna fila. La **Carga
de Información** comprueba esto antes de tocar nada y, si los índices viejos
siguen ahí, lo dice en palabras y no ejecuta la carga.

`usuario`.`Username` **sí sigue siendo único en toda la red**, y se dejó así a
propósito: afecta a quién puede entrar al sistema. Si la Fundación quisiera que
cada institución tuviera su propio `admin`, habría que decidirlo primero y
migrar las cuentas existentes con cuidado.

### Una cuenta que entra en varias instituciones

`usuario`.`InstitucionEducativaId` dice a qué institución **pertenece** la
cuenta, y en esa entra siempre. La tabla `usuario_institucion` dice en qué
**otras** se le ha dado permiso de entrar: una fila por institución.

Es lo que permite que la coordinadora que atiende dos escuelas use un solo
usuario en vez de dos, sin tener que darle SuperAdmin —que le abriría la red
entera—. Al ingresar elige la institución, y dentro ve únicamente los datos de
esa institución, como cualquier otra cuenta.

**Los roles no viajan con la persona.** Se asignan por institución en
`usuariorol`, que ya llevaba `InstitucionEducativaId`, de modo que la misma
cuenta puede tener *Consultas* en una escuela y *Registro de Datos* en otra.
Entrar sin roles en una institución es posible y significa lo que parece: la
cuenta entra, pero no ve ninguna opción hasta que se le asigne alguno.

El **SuperAdmin no necesita constar** en esta tabla: entra en cualquier
institución activa por su rol, y allí lo hace con todos los permisos. Eso no
cambia.

Quien concede estos accesos es **solo el SuperAdmin**, desde *Usuarios del
Sistema*. Un administrador corriente sigue gestionando las cuentas de su
institución —crearlas, renombrarlas, inactivarlas, darles roles— pero no puede
abrirles la puerta de otra: dar acceso a los datos personales de otra comunidad
educativa no debería poder decidirse sin quien responde por toda la red.

Para incorporarla a una base ya instalada, ejecute
`12_ALTER_usuario_instituciones.sql`, que se entrega aparte. Siembra una fila
por cuenta con su propia institución —es decir, exactamente el acceso que cada
una tiene hoy—, de modo que al terminar nadie entra donde no entraba ni deja de
entrar donde entraba.

### El nombre y el teléfono de la institución

Dos columnas de `institucion_educativa` se quedaron cortas y se ampliaron:

| Columna | Antes | Ahora | Por qué |
|---|---|---|---|
| `nombre` | 50 | **100** | Los nombres completos de las instituciones de la red no caben en cincuenta caracteres, y había que abreviarlos a mano |
| `telefono` | 20 | **50** | Cabe el contacto tal como lo publica la escuela: una central con extensión, dos números, un celular de guardia |

El teléfono de la **institución** admite además **letras**: no es el dato
personal de nadie, es la forma de contacto de la organización, y lo único que se
le exige es que lleve algún dígito. El teléfono de una **persona** no cambia:
sigue siendo un número marcable de hasta 16 caracteres, solo dígitos. Son dos
reglas distintas a propósito, y conviven en `api/core/Telefono.php`.

Para incorporarlo a una base ya instalada, ejecute
`13_ALTER_institucion_nombre_telefono.sql`, que se entrega aparte. Las columnas
solo se **amplían**, nunca se estrechan, de modo que no se pierde ni se recorta
nada de lo que ya estaba grabado.

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
| `estudiante` | `RepresentanteRelacion` amplía su lista con `ABUELO`, `ABUELA`, `TIO`, `TIA` y `TUTOR/A`; se elimina `Carrera_Especialidad` |
| `verificacion_codigo` | **Tabla nueva.** Códigos de un solo uso de los enlaces con verificación |
| `persona` | **Pasa a ser por institución:** nueva columna `InstitucionEducativaId`, primaria `(InstitucionEducativaId, PersonaId)`, clave foránea contra la institución y la identificación única **dentro de cada institución** |
| `persona` | Se retira el único **global** `Identificacion`, resto del diseño de institución única (ver *La misma persona en varias instituciones*) |
| `estudiante` | El único global `CodigoEstudiante` pasa a ser `uk_estudiante_codigo (InstitucionEducativaId, CodigoEstudiante)` |
| `usuario_institucion` | **Tabla nueva.** En qué instituciones puede iniciar sesión cada cuenta (ver *Una cuenta que entra en varias instituciones*) |
| `institucion_educativa` | `nombre` pasa de 50 a **100** caracteres, y `telefono` de 20 a **50** (ver *El nombre y el teléfono de la institución*) |

La bitácora de auditoría deja de guardar el contenido de los datos: se eliminan
las columnas `ValorAnterior` y `ValorNuevo` de `auditoria`. A partir de aquí
anota el **QUÉ**, no el **DATO** —que se modificó, por ejemplo, el correo de una
persona, con quién, cuándo y desde qué IP, pero no el correo—, de modo que la
propia bitácora de un sistema de protección de datos no sea una segunda copia,
sin control de acceso propio y sin caducidad, de lo que custodia. Para llevar una
base ya instalada a este modelo, ejecute `07_ALTER_auditoria_sin_valores.sql`,
que se entrega aparte. **Respalde antes:** al eliminar las columnas se pierde
también lo que ya estaba grabado en ellas.

En los **datos** cambió un nombre. La opción que publicaba los enlaces
abiertos de consentimiento se retiró del menú y del fuente, y la que quedó —la
que verifica la identidad con un código enviado al correo registrado— se llama
**Enlaces con Verificación**. El permiso conserva su código
`ADM_ENLACES_VERIF`, que es un identificador interno: cambiarlo dejaría
huérfanas las asignaciones de rol ya hechas. Lo que cambia es el nombre que se
ve en *Permisos* y en *Roles*:

```sql
UPDATE permiso
   SET Nombre = 'Enlaces con Verificación'
 WHERE Codigo = 'ADM_ENLACES_VERIF';
```

Está incluido en `02_DML_datos.sql`, que puede volver a ejecutarse sin
duplicar nada; sobre una base ya instalada se aplica con
`10_RENOMBRA_enlaces_con_verificacion.sql`, que se entrega aparte. La opción
retirada no tenía permiso propio —se apoyaba en `ADM_CORREO`, el de la
configuración de correo—, de modo que no hay ninguna fila que eliminar.

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
plantilla de la **Carga de Información** (*Registro de Datos › Carga de
Información*, solo para SuperAdmin) tampoco los incluye.

El sistema tolera que la base esté un paso atrás en un punto concreto: las
relaciones del representante que ofrecen las pantallas se leen del propio enum
de `estudiante`.`RepresentanteRelacion`, de modo que nunca se ofrece una que la
base no pueda guardar. Si intenta grabar una relación que su base todavía no
reconoce, el sistema lo dice con claridad en vez de dejar que MySQL responda con
un error de truncamiento.

`verificacion_codigo` guarda los códigos de los **Enlaces con Verificación**.
De cada código solo se conserva su huella SHA-256, nunca su
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
—titular y representante—, Proveedores, los enlaces públicos o la Carga de
Información, y se reutilizan cuando el documento ya consta. Toda la escritura pasa
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
