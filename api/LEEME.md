# API REST — Sistema de Protección de Datos (REA)

Toda la conectividad con MySQL vive detrás de esta API. Las páginas del sitio
(`login.php`, `dashboard.php`, `modules/*`, `consultas/*`, `reportes/*`) ya no
abren conexiones PDO: consumen estos endpoints por HTTP mediante
`includes/api_client.php`.

## Documentación interactiva (Swagger UI)

| Recurso | URL |
|---|---|
| Interfaz Swagger UI | `/api/docs` |
| Especificación OpenAPI 3.0 | `/api/openapi.json` |

Desde `/api/docs` se pueden **probar todos los endpoints** sin salir del
navegador. El flujo es:

1. Abrir `POST /auth/login`, pulsar **Try it out** y enviar usuario, contraseña
   e institución.
2. La página captura el token de la respuesta y lo aplica sola al botón
   **Authorize**; a partir de ahí toda operación se ejecuta autenticada.
   (También puede pegarse a mano en **Authorize**.)

Swagger UI 5 está alojado en `api/docs/assets`, así que **no depende de ningún
CDN** ni de conexión a Internet. El enlace aparece en el menú lateral del
sistema para los usuarios SuperAdmin.

Para restringir la página a usuarios con sesión iniciada, cambie a `true` la
constante `DOCS_SOLO_AUTENTICADOS` al inicio de `api/docs/index.php`. La API en
sí siempre exige token, con o sin esa restricción.

La especificación se genera desde `api/openapi.php` (un arreglo PHP), por lo que
la URL del servidor se detecta sola y el mismo archivo sirve en local y en el
hosting. Al agregar un endpoint nuevo, añada su ruta allí junto al `$router->…`
correspondiente en `api/index.php`. También puede importarse el JSON en Postman,
Insomnia o cualquier generador de clientes.

## Estructura

```
api/
├── index.php            Front controller: define todas las rutas
├── openapi.php          Especificación OpenAPI 3.0 (se publica en /api/openapi.json)
├── .htaccess            Reescritura de URLs (URLs limpias) + fallback
├── core/
│   ├── Database.php     Conexión PDO única (lee el config.php del proyecto)
│   ├── Request.php      Método, ruta, query string y cuerpo JSON
│   ├── Response.php     Salida JSON uniforme
│   ├── Router.php       Enrutador con parámetros dinámicos {id}
│   ├── Auth.php         Login, tokens Bearer firmados, roles y permisos
│   └── Controller.php   Clase base: guardas de acceso, paginación, consultas
├── controllers/         Un controlador por recurso
└── docs/
    ├── index.php        Página de Swagger UI
    └── assets/          Swagger UI 5 (css + bundle js, servidos localmente)
```

`config.php` **no se modificó**: la API lee de allí los mismos datos de conexión.

## Autenticación

`POST /api/auth/login` devuelve un token firmado (HMAC-SHA256) que caduca a las
8 horas. Ese token viaja en cada llamada:

```
Authorization: Bearer <token>
```

La clave de firma se deriva automáticamente de los datos de `config.php`. Si
desea fijar una propia, agregue a `config.php` la clave `'api_secret' => '...'`
(opcional).

> **Verificación de contraseña:** `api/core/Auth.php` tiene la constante
> `VALIDAR_PASSWORD = false`, que conserva el comportamiento del sistema
> original (en `auth.php` la llamada a `password_verify()` estaba comentada).
> Cuando todos los usuarios tengan su `PasswordHash` generado con
> `password_hash()`, cámbiela a `true` para exigir contraseña real.

## Formato de respuesta

```json
{ "ok": true,  "datos": { ... }, "meta": { "total": 42, "pagina": 1, "por_pagina": 12, "total_paginas": 4 } }
{ "ok": false, "error": "mensaje", "errores": ["detalle 1", "detalle 2"] }
```

Códigos usados: `200` correcto, `201` creado, `401` sin token o token vencido,
`403` sin permisos, `404` no encontrado, `405` método no permitido,
`409` conflicto (duplicados), `422` validación, `500` error interno.

## Endpoints

### Documentación
| Método | Ruta | Acceso |
|---|---|---|
| GET | `/api/docs` | pública (configurable) |
| GET | `/api/openapi.json` | pública (configurable) |

### Sesión
| Método | Ruta | Acceso |
|---|---|---|
| POST | `/api/auth/login` | pública |
| POST | `/api/auth/logout` | pública |
| GET | `/api/auth/me` | autenticado |
| GET | `/api/auth/permiso?codigo=X` | autenticado |

### Instituciones educativas
| Método | Ruta | Acceso |
|---|---|---|
| GET | `/api/instituciones/activas` | pública (combo del login) |
| GET | `/api/instituciones` | SuperAdmin |
| POST | `/api/instituciones` | SuperAdmin |
| GET · PUT | `/api/instituciones/{id}` | SuperAdmin |
| PATCH | `/api/instituciones/{id}/estado` | SuperAdmin |

### Personas
| Método | Ruta | Acceso |
|---|---|---|
| GET | `/api/personas?q=&pagina=&estado=` | SuperAdmin, RecursosHumanos, Secretaria |
| GET | `/api/personas/opciones` | autenticado |
| POST | `/api/personas` | SuperAdmin, RecursosHumanos, Secretaria |
| GET · PUT | `/api/personas/{id}` | autenticado / roles del módulo |
| PATCH | `/api/personas/{id}/estado` | roles del módulo |
| GET | `/api/personas/{id}/ficha` | autenticado (vista 360°) |

### Empleados · Estudiantes · Proveedores
Mismo patrón para `/api/empleados`, `/api/estudiantes` y `/api/proveedores`:

| Método | Ruta |
|---|---|
| GET | `/api/{recurso}?q=&pagina=` |
| POST | `/api/{recurso}` |
| GET · PUT | `/api/{recurso}/{id}` |
| PATCH | `/api/{recurso}/{id}/estado` |

Acceso: empleados → SuperAdmin/RecursosHumanos · estudiantes → SuperAdmin/Secretaria ·
proveedores → SuperAdmin. Todo queda acotado a la institución del token.

### Consentimientos
| Método | Ruta | Nota |
|---|---|---|
| GET | `/api/consentimientos?q=&estado=&pagina=` | |
| GET | `/api/consentimientos/catalogos` | personas, finalidades y tipos de dato |
| POST | `/api/consentimientos` | crea + bitácora + detalle de tipos |
| GET · PUT | `/api/consentimientos/{id}` | el GET incluye `tipos_autorizados` |
| POST | `/api/consentimientos/{id}/revocar` | acepta `observacion` |
| POST | `/api/consentimientos/{id}/reactivar` | |

Acceso: SuperAdmin, RecursosHumanos, Secretaria **o** permiso `REGISTRO_DATOS`.
Las escrituras se ejecutan dentro de una transacción y registran automáticamente
`consentimientohistorial`.

### Usuarios, roles y permisos (SuperAdmin)
| Método | Ruta |
|---|---|
| GET | `/api/usuarios?q=&pagina=` · `/api/usuarios/personas-disponibles` |
| POST · PUT | `/api/usuarios` · `/api/usuarios/{id}` (sincroniza `usuariorol`) |
| PATCH | `/api/usuarios/{id}/estado` |
| GET·POST·PUT·PATCH | `/api/roles`, `/api/roles/{id}`, `/api/roles/{id}/estado` (sincroniza `rolpermiso`) |
| GET·POST·PUT·PATCH | `/api/permisos`, `/api/permisos/{id}`, `/api/permisos/{id}/estado` |
| GET | `/api/usuarios/politica-clave` |

**Contraseñas.** La política vive en `api/core/Password.php`: mínimo 8
caracteres, al menos una mayúscula, una minúscula y un número, y solo letras,
números y los signos `*!-_` —estos últimos opcionales—. El juego es cerrado a
propósito: estas claves se dictan por teléfono y se copian de un papel, y un
acento o una comilla curva de Word se convierten en un «no me funciona» difícil
de rastrear.

Cuando falla, se devuelven **todos** los incumplimientos a la vez, no solo el
primero. Al editar un usuario, dejar la contraseña en blanco significa «no la
cambies» y no dispara ninguna validación.

`politica-clave` devuelve esas reglas con su expresión regular para que la
pantalla las marque mientras se escribe y para el botón que genera una
contraseña que las cumple. Si la política cambia aquí, la pantalla la sigue sin
tocarse.

> Esto valida la contraseña que se va a **grabar**. No tiene relación con
> `Auth::VALIDAR_PASSWORD`, que decide si al iniciar sesión se comprueba el hash
> y que sigue en `false`.

**Errores por campo.** Las validaciones de usuarios devuelven, además de
`errores`, un arreglo `campos` con los nombres de los que fallaron:

```json
{ "ok": false, "error": "Los datos enviados no son válidos.",
  "errores": ["El correo electrónico no es válido."],
  "campos":  ["email"] }
```

Así la pantalla los señala sin tener que adivinar leyendo los mensajes, y puede
volver a pintar el formulario con lo que la persona ya había escrito en vez de
vaciarlo. Es opcional: los endpoints que no lo envían siguen funcionando igual.

### Catálogos
| Método | Ruta | Acceso |
|---|---|---|
| GET | `/api/finalidades?q=&solo_activas=1` | autenticado |
| POST·PUT·PATCH | `/api/finalidades[/{id}[/estado]]` | SuperAdmin |
| GET | `/api/tipos-dato?q=&solo_sensibles=1` | autenticado |
| POST·PUT·DELETE | `/api/tipos-dato[/{id}]` | SuperAdmin |

### Consultas
| Método | Ruta |
|---|---|
| GET | `/api/consultas/buscar-persona?q=&id=` |
| GET | `/api/consultas/historial?consentimiento_id=&desde=&hasta=&q=&pagina=` |
| GET | `/api/consultas/consentimientos-vigentes?estado=&finalidad_id=&tipo_dato_id=&q=` |

### Reportes
| Método | Ruta | Acceso |
|---|---|---|
| GET | `/api/reportes/dashboard` | autenticado |
| GET | `/api/reportes/consentimientos` | SuperAdmin o `REPORTES_EXPORTACION` |
| GET | `/api/reportes/datos-sensibles?tipo_dato_id=` | SuperAdmin o `REPORTES_EXPORTACION` |
| GET | `/api/reportes/titulares?estado=&tipo=&desde=&hasta=&q=` | SuperAdmin o `REPORTES_EXPORTACION` |
| GET | `/api/reportes/auditoria?desde=&hasta=&username=&tabla=&operacion=&q=` | SuperAdmin o `REP_AUDITORIA` |
| GET | `/api/reportes/exportar?entidad=personas` | SuperAdmin o `REPORTES_EXPORTACION` |

### Regla de institución en el login

`POST /api/auth/login` recibe `username`, `password` e `institucion_id`.

- Una cuenta corriente solo se autentica contra **su** institución; con otra
  `institucion_id` la respuesta es `401`.
- Una cuenta con el rol **SuperAdmin** (en cualquier institución) se autentica
  contra **cualquier institución activa**. El token se emite con la institución
  elegida y con el rol `SuperAdmin`, de modo que `Auth::puede()` abre todas las
  opciones y las consultas se filtran por esa institución.

La respuesta incluye `institucion_propia` y `visita`, para que el cliente pueda
avisar cuando se está trabajando en una institución ajena.

### Correo saliente
| Método | Ruta | Acceso |
|---|---|---|
| GET·PUT | `/api/correo/configuracion` | SuperAdmin o `ADM_CORREO` |
| POST | `/api/correo/probar` | SuperAdmin o `ADM_CORREO` |

El correo se arma con `api/core/Correo.php`, un cliente SMTP sobre sockets con
STARTTLS/SSL y MIME multipart, con respaldo a `mail()`. Se usa para confirmar
cada consentimiento registrado desde los enlaces públicos.

### Disclaimers
| Método | Ruta | Acceso |
|---|---|---|
| GET·POST | `/api/disclaimers` | SuperAdmin o `ADM_DISCLAIMERS` |
| GET·PUT·DELETE | `/api/disclaimers/{id}` | SuperAdmin o `ADM_DISCLAIMERS` |
| PATCH | `/api/disclaimers/{id}/activar` | SuperAdmin o `ADM_DISCLAIMERS` |

El texto llega como HTML y se depura con `api/core/HtmlSeguro.php` antes de
guardarse: solo sobreviven las etiquetas y atributos de la lista blanca, y los
enlaces que no apunten a `http`, `https` o `mailto` pierden su destino. Así un
texto con código incrustado no puede convertirse en un ataque contra quien abre
el enlace público.

### Personas (solo lectura)
| Método | Ruta | Acceso |
|---|---|---|
| GET | `/api/personas` | SuperAdmin, RecursosHumanos o Secretaria |
| GET | `/api/personas/opciones` | Cualquier sesión |
| GET | `/api/personas/{id}` | Cualquier sesión |
| GET | `/api/personas/{id}/ficha` | Cualquier sesión |

`persona` es la entidad **padre** de empleados, estudiantes, representantes y
proveedores: **no hay POST, PUT ni PATCH**. Las fichas se crean desde esos
módulos, desde los enlaces públicos o desde la Carga de Información, y toda la
escritura vive en `api/core/Padron.php`, que aplica una sola regla: la
identificación es la llave dentro de la institución, de modo que una persona ya
registrada se reutiliza en lugar de duplicarse.

Esto significa que `POST /api/empleados`, `/api/estudiantes` y `/api/proveedores`
reciben los datos personales en el mismo cuerpo —`identificacion`,
`tipo_identificacion`, `nombres`, `apellidos`, `email`, `telefono`, y los mismos
con prefijo `rep_` para el representante de un estudiante— en vez de un
`persona_id`.

### Documento de identidad

| Método | Ruta | Acceso |
|---|---|---|
| GET | `/api/documento/reglas?contexto=persona\|proveedor` | autenticado |

Las reglas viven en `api/core/Documento.php` y valen para **todos** los módulos
que piden un documento:

| Tipo | Qué se comprueba |
|---|---|
| `CEDULA` | Solo dígitos, **exactamente 10**. |
| `RUC` | Solo dígitos, **exactamente 13**. |
| `PASAPORTE` | Letras y dígitos, **entre 6 y 12 caracteres**. No hay un formato internacional único: cada país emisor usa el suyo, así que lo que se le exige es un rango. Por debajo de 6 no hay pasaporte que valga; lo que suele haber es un campo a medio escribir. |

El largo de la columna `persona`.`Identificacion` sigue actuando como último
techo: si alguien la estrechara, el formulario se entera solo y nunca deja
escribir algo que la base vaya a recortar en silencio.

Se comprueba **la forma, no la validez del número**. La cédula y el RUC
ecuatorianos llevan un dígito verificador, y el sistema llegó a comprobarlo;
esa comprobación se retiró a petición de la Fundación, porque el padrón trae
documentos de personas extranjeras y registros históricos que no la superan y
que sí deben poder cargarse. Con la regla actual `0000000000` es una cédula
aceptable: lo que se garantiza es que el dato tiene la forma que la base espera,
no que corresponda a una persona real.

El largo de la cédula y del RUC es una regla del país y está escrita en el
código. El de la columna donde el valor termina guardado
—`persona`.`Identificacion`— se lee de la propia base y solo manda para el
pasaporte, que no tiene medida única: así el formulario nunca deja escribir algo
que la base vaya a recortar en silencio, y ampliar la columna basta para ampliar
el campo. En proveedores se mira además `proveedor`.`Ruc`, que es más estrecha.

La validación ocurre sobre el valor **tal como se escribió**: si alguien pone
letras en una cédula, el sistema lo dice en vez de borrarlas sin avisar. Los
espacios, puntos y guiones sí se admiten al escribir y se quitan al guardar,
porque es como vienen los documentos copiados de otro sitio.

El endpoint solo sirve para que los formularios adapten el campo mientras se
escribe (`js/documento.js`). **Quien decide es el servidor:** una petición hecha
por fuera del navegador se rechaza igual.

La misma llamada devuelve, en la metadata, las reglas del **teléfono**
(`api/core/Telefono.php`) y las del **correo** (`api/core/CorreoElectronico.php`):
el formulario que pregunta por el documento es siempre el mismo que captura esos
dos campos, y así no hace falta una segunda vuelta por unos datos que tampoco
cambian.

### Correo electrónico

No tiene endpoint propio: la regla vive en `api/core/CorreoElectronico.php` y la
aplican **todos** los puntos por los que entra o sale una dirección —el padrón,
la Carga de Información, la configuración del correo saliente, el envío masivo y
el propio cliente SMTP—.

El correo no es un dato más de la ficha: es el único camino por el que el
sistema alcanza al titular. Por ahí van el código que comprueba su identidad, la
invitación a consentir y la confirmación de lo que decidió. Una dirección mal
escrita no falla al guardarla; falla semanas después, cuando la persona no
aparece en la cobertura y nadie sabe por qué.

Antes cada sitio la comprobaba con un `filter_var(..., FILTER_VALIDATE_EMAIL)`
suelto. Esa función es correcta pero generosa: acepta `juan@localhost`, `a@b` y
`x@dominio.c`, direcciones que la norma admite y que ningún proveedor va a
entregar. Sobre ella se exige además:

| Se exige | Rechaza |
|---|---|
| Una sola arroba, con algo a cada lado | `a@@b.com`, `@rea.com` |
| Sin espacios en ninguna parte | `ana mora@rea.com` |
| Parte local de hasta 64 caracteres, sin puntos al principio, al final ni dos seguidos | `.ana@`, `ana..b@` |
| Dominio con al menos un punto, etiquetas de 1 a 63 caracteres, sin guiones en los extremos | `juan@localhost`, `ana@-rea.com` |
| Extensión final de 2 a 24 **letras** | `x@dominio.c`, `x@dominio.123` |
| Hasta 150 caracteres, el largo de `persona`.`Email` | |

Al guardar, la dirección se pasa a minúsculas: la misma escrita de dos maneras
dejaría de parecer dos correos. Los espacios de dentro **no** se quitan; se
avisan. Quitar un espacio en silencio convertiría `ana mora@rea.com` en una
dirección distinta de la que quiso escribir quien la puso, sin que nadie se
enterara.

`js/correo.js` repite la misma comprobación mientras se escribe —se engancha
solo a todo `input[type=email]`, sin marcar nada en el HTML—, pero quien decide
es el servidor.

### Verificación pública por código (SIN token)
| Método | Ruta |
|---|---|
| POST | `/api/verificacion-publica/consultar` |
| POST | `/api/verificacion-publica/enviar-codigo` |
| POST | `/api/verificacion-publica/validar-codigo` |

Atienden los **Enlaces con Verificación** —los que se difunden desde
*Registro de Datos › Enlaces con Verificación* y desde el Envío Masivo—, que
consume `consentimiento_verificado.php`.

`consultar` no escribe nada: dice si la cédula o el RUC constan en la
institución y devuelve la ficha con el correo y el teléfono enmascarados. Si no
consta, ahí termina: por este camino nadie se da de alta.

`enviar-codigo` genera un código de 6 dígitos y lo envía al correo registrado
—el del **representante** en el caso de los estudiantes—. Responde con esa
dirección enmascarada, los segundos de validez que quedan y cuántos reenvíos
restan. Se admite un solo código vigente por identificación: pedir otro anula el
anterior, hay 60 segundos de espera entre envíos, 5 envíos por código y 10 por
hora. De la tabla `verificacion_codigo` solo se guarda el SHA-256 del código.

`validar-codigo` comprueba el código —5 intentos, después queda anulado— y
devuelve un **pase** firmado con HMAC-SHA256, válido 20 minutos. Con ese pase la
pantalla entrega el recorrido a `consentimiento.php`, que lo reenvía en
`/consentimiento-publico/registrar`; allí se verifica y queda anotado en el
historial que la identidad fue comprobada por código.

Toda la aritmética de tiempos (emisión, caducidad y espera entre envíos) la hace
MySQL con `NOW()`, no PHP: en un hospedaje compartido las dos zonas horarias
rara vez coinciden y la comparación daría un resultado equivocado.

### Carga de Información
| Método | Ruta | Acceso |
|---|---|---|
| POST | `/api/carga-informacion/previsualizar` | Solo SuperAdmin |
| POST | `/api/carga-informacion/procesar` | Solo SuperAdmin |

Puebla de una vez el padrón de la institución activa desde la plantilla Excel.
No borra nada: da de alta lo que no consta y actualiza lo que ya estaba.
El archivo viaja dentro del JSON, en base64, y se lee con
`api/core/LectorXlsx.php` —un lector propio sobre `ZipArchive` y `SimpleXML`,
sin librerías externas—.

`previsualizar` valida el archivo completo **sin tocar la base** y devuelve los
conteos por hoja, los errores con hoja y fila, y el desglose de cuántas filas
serían altas y cuántas actualizaciones. `procesar` repite esa validación y solo
actúa si el archivo está limpio y el cuerpo trae
`confirmacion: "CARGAR INFORMACION"`; todo va en una sola transacción: o entra
completo, o no entra nada.

**Antes de mirar el archivo se revisa la base.** Si la instalación todavía
arrastra algún índice único **global** del diseño de institución única
—`persona`.`Identificacion` o `estudiante`.`CodigoEstudiante`—, la carga se
rompería en cuanto apareciera alguien que ya consta en otra institución, que es
el caso corriente del representante con hijos en dos escuelas. `Padron::
indicesGlobalesPendientes()` lo detecta y `Controller::avisosIndicesHeredados()`
lo redacta, de modo que la previsualización lo dice en palabras y nombra el
script que lo corrige (`11_ALTER_unicos_por_institucion.sql`) en vez de dejar
que llegue a la pantalla un `SQLSTATE[23000] … Duplicate entry`. Por si acaso,
`Controller::errorBaseDatos()` traduce también el choque en caliente, para todas
las pantallas y no solo para esta.

La plantilla **no pide el estado**: constar en el archivo es la señal de que la
persona está vigente, de modo que
todo lo que entra queda **ACTIVO**. Dar de baja a alguien es una decisión
posterior y se hace desde la pantalla que le corresponde. Si el archivo trae una
columna `Estado` —de una plantilla anterior— se ignora, y si alguna fila la traía
en algo distinto de ACTIVO se avisa en las advertencias, para que nadie dé por
hecho que se respetó.

**Una persona en varias hojas.** La identificación es la llave: quien aparece en
Empleados y también en Proveedores —o como representante— se carga con **una sola
ficha**, no con dos. Para confirmar que de verdad es la misma persona se comparan
los nombres, pero **por palabras, no por orden**:

| Hoja | Cómo viene |
|---|---|
| Empleados | `NELLY PATRICIA` \| `BOURNE SOLIS` |
| Proveedores | `BOURNE SOLIS NELLY PATRICIA` (de la razón social) |

Es la misma señora. Media hoja de cálculo del país escribe «apellidos nombres» y
la otra media «nombres apellidos», y la hoja de Proveedores no tiene columnas de
nombre: el sistema lo toma de la razón social, que casi siempre viene con los
apellidos primero. Exigir el mismo orden rechazaba cargas correctas.

También se acepta que un nombre esté más completo que el otro —`JUAN PEREZ`
frente a `JUAN PEREZ GOMEZ`—, que es lo que pasa cuando en una hoja se omitió el
segundo apellido, y se ignoran las partículas (`de`, `la`, `y`) y las formas
societarias (`S.A.`, `Cía.`, `Ltda.`), que aparecen en unas hojas sí y en otras
no.

**Con Proveedores no hay error posible.** Esa hoja no tiene columnas de nombre:
lo que se compara de ese lado es la **razón social**, que a menudo no se parece
en nada al nombre de la persona —un empleado que además presta servicios a través
de su compañía—. Comparar un nombre contra una razón social no dice nada, así que
ahí no se reporta error: la persona es una sola, su ficha conserva el nombre bien
separado que trae su hoja propia, y la razón social se guarda donde corresponde.
Queda una advertencia informativa, **una sola para todo el archivo**, porque en
uno grande son cientos y taparían lo que sí importa:

> 12 persona(s) constan en dos hojas con nombre y razón social distintos; se
> cargan como una sola. Por ejemplo, 0704336254: «CARLOS RUIZ MERA» y
> «DISTRIBUIDORA EL SOL S.A.».

Entre hojas que sí traen nombres de persona —Empleados, Estudiantes,
Representantes— dos nombres **sin relación** bajo la misma cédula siguen siendo
un error: casi siempre es un dígito mal tecleado en el número. El mensaje muestra
los dos nombres para poder decidir de un vistazo.

En la ficha unificada mandan los nombres y apellidos **ya separados** de
Empleados, Estudiantes o Representantes; la razón social se guarda donde
corresponde, en `proveedor`.`RazonSocial`.

Lo que se elimina queda acotado a la institución del token: consentimientos con
su historial y detalle, empleados, estudiantes, proveedores y las personas que
queden sin ningún vínculo.

**Dos clases de persona se conservan aunque estén en el padrón de la institución
que se encera**, y ambas se informan en `se_eliminara`:

| Clave | Por qué se conserva |
|---|---|
| `personas_con_usuario` | Tiene cuenta de acceso; borrarla dejaría a alguien sin entrar (`usuario.PersonaId` es `ON DELETE CASCADE`) |
| `personas_en_otra_institucion` | Otra institución todavía la tiene vinculada como empleado, estudiante, representante, proveedor o titular de un consentimiento |

La segunda no debería existir —desde que el padrón es por institución, cada una
tiene su propia ficha—, pero sí aparece en bases que vienen de la versión
anterior: cuando las personas eran globales, una misma ficha podía estar
vinculada a varias instituciones, y el script que repartió el padrón (`05`)
asignó cada ficha a una sola **sin mover los vínculos de las demás**.

Intentar borrarlas rompía la carga entera:

> SQLSTATE[23000]: Cannot delete or update a parent row: a foreign key
> constraint fails (`proveedor`, CONSTRAINT `fk_proveedor_persona` …)

`proveedor` es el único de los cuatro que declara `RESTRICT`, así que era el que
saltaba; en `empleado`, `estudiante` y `consentimiento`, que declaran
`SET NULL`, el efecto habría sido peor: el vínculo de la otra institución se
quedaba sin persona, en silencio y sin que nadie se enterara. **No se tocan** usuarios, roles, permisos, catálogos,
disclaimers, la configuración de correo, las personas con cuenta de usuario ni
los datos de las demás instituciones de la red. La operación deja una anotación
de balance en la bitácora de auditoría.

Junto con el Envío Masivo, es una de las dos opciones sin permiso asignable:
`carga_informacion` declara `'permisos' => []` en `includes/accesos.php`, de modo que la
abre únicamente el rol SuperAdmin.

### Envío masivo de invitaciones
| Método | Ruta | Acceso |
|---|---|---|
| GET | `/api/envio-masivo/resumen` | SuperAdmin o Registro de Datos |
| GET | `/api/envio-masivo/destinatarios?tipo=&q=&solo_con_correo=&pagina=` | SuperAdmin o Registro de Datos |
| POST | `/api/envio-masivo/enviar` | SuperAdmin o Registro de Datos |

Escribe a estudiantes, empleados o proveedores de la institución activa con el
enlace de **consentimiento con verificación** de su tipo, ya con el número de
documento cargado (`&doc=`), de modo que quien lo abre solo tiene que continuar.

`resumen` devuelve, por cada tipo, cuántos hay y a cuántos se les puede escribir,
más el estado del SMTP de la institución. `destinatarios` es el listado paginado
que alimenta la subventana de selección individual de la pantalla; acepta
búsqueda por nombre, apellido o identificación. `enviar` recibe
`{tipo, alcance: "todos"|"seleccion", personas: [PersonaId, …]}`.

A quién se le escribe:

| Tipo | Destinatario |
|---|---|
| ESTUDIANTE | El **representante**, indicando de qué representado se trata |
| EMPLEADO | Su propio correo |
| PROVEEDOR | El correo del contacto registrado |

Quien no tenga un correo válido no recibe nada y sale nombrado en la respuesta,
dentro de `sin_correo`: no se inventa una dirección ni se interrumpe la tanda.

El envío abre **una sola conexión SMTP** para toda la tanda y la cierra al final:
abrir una por correo es mucho más lento y varios proveedores lo leen como abuso.
Por la misma razón hay un tope de **300 correos por petición**; si se pasa, el
sistema lo dice y pide dividir el envío en tandas.

El remitente y el servidor salen de `correo_configuracion` de la institución del
token —cada institución invita a su gente con su propio remitente—, y el texto
del mensaje vive en `plantillas/correo_invitacion_consentimiento.php`, editable
sin tocar el código. Una selección con identificadores de otra institución no
envía nada: la consulta está acotada por la institución del token.

Cada envío deja una anotación de balance en la bitácora de auditoría.

### Consentimiento público (SIN token)
| Método | Ruta |
|---|---|
| GET | `/api/consentimiento-publico/inicio?tipo=&inst=` |
| POST | `/api/consentimiento-publico/identificar` |
| POST | `/api/consentimiento-publico/registrar` |

Son las únicas rutas sin autenticación además de `/instituciones/activas` y
`/estado`. Las consume `consentimiento.php`, que es **la última pantalla de los
Enlaces con Verificación**: allí desemboca quien superó la verificación por
código, ya con la identidad confirmada.

**`registrar` exige el pase de verificación.** Sin él responde `403`; si el pase
caducó, `409`. Y no da de alta a nadie: si el documento no consta en la
institución responde `404`. Antes esta ruta creaba la ficha de quien no
constaba, de modo que el autoservicio abierto poblaba el padrón sin que nadie lo
hubiera cargado; el alta es hoy competencia exclusiva de la Carga de
Información. `consentimiento.php` reenvía a `consentimiento_verificado.php` a
quien llegue sin haber pasado la verificación, pero la puerta de verdad está
aquí: la comprobación no depende de ninguna pantalla.

`registrar` responde `409` también cuando se intenta revocar un consentimiento
ya otorgado: esa vía se tramita por correo con la institución.

### Instalación
**No hay endpoint de instalación.** Existió `POST /api/setup/admin`, sin
autenticación, que creaba un SuperAdmin en cualquier institución que todavía no
tuviera usuarios: bastaba registrar una institución nueva y adelantarse a su
primer usuario para quedarse con ella. El primer administrador se crea con la
carga inicial de `BaseDatos/02_DML_datos.sql`; los siguientes, desde *Usuarios
del Sistema*.

## URLs sin mod_rewrite

Si el hosting no admite `.htaccess`, la misma API responde en:

```
/api/index.php?ruta=personas/5
```

`includes/api_client.php` detecta el caso automáticamente: si la URL limpia
devuelve un 404 del servidor web, reintenta en este modo y lo recuerda en la
sesión.

## Ejemplo de uso externo (Postman / móvil)

```bash
# 1. Login
curl -X POST https://midominio.com/api/auth/login \
     -H 'Content-Type: application/json' \
     -d '{"username":"admin","password":"admin123","institucion_id":1}'

# 2. Consumir con el token recibido
curl https://midominio.com/api/personas?q=perez \
     -H 'Authorization: Bearer eyJ1aWQiOjEs...'
```

## Nota sobre el servidor web

Las páginas llaman a la API por HTTP contra el mismo dominio. Apache, nginx o
LiteSpeed atienden varias peticiones en paralelo, así que esto funciona sin
configuración adicional. Solo el servidor embebido de PHP (`php -S`) es de un
proceso: para pruebas locales, ejecútelo con varios workers:

```bash
PHP_CLI_SERVER_WORKERS=6 php -S localhost:8080 -t . router_dev.php
```

(`router_dev.php`, en la raíz, emula la reescritura del `.htaccess` y solo se
usa en desarrollo.)

## Bitácora de auditoría

Toda escritura que pasa por la API queda registrada en la tabla `auditoria`
(script `BaseDatos/01_DDL_estructura.sql`): institución, usuario, fecha y
hora, IP de origen, tabla y registro afectados, y una fila por cada campo
afectado.

**La bitácora anota el QUÉ, no el DATO.** Deja constancia de que se modificó,
por ejemplo, el correo de una persona, pero **no guarda el correo anterior ni el
nuevo**: así la propia bitácora de un sistema de protección de datos no se
convierte en una segunda copia —sin control de acceso propio y sin caducidad— de
los datos personales que custodia. Para saber qué dice hoy un registro está su
pantalla; para saber quién lo tocó y cuándo, está la bitácora.

Los valores se siguen comparando en memoria para decidir qué cambió; de esa
comparación solo queda el nombre del campo.

El registro lo hacen los propios controladores mediante los ayudantes de
`api/core/Controller.php`:

```php
// Alta
$id = (int)$this->db->lastInsertId();
$this->auditarInsercion('persona', 'PersonaId', $id);

// Cambio: se lee la fila antes de escribir y se compara después
$antes = $this->filaAuditable('persona', 'PersonaId', $id);
// ... UPDATE ...
$this->auditarActualizacion('persona', 'PersonaId', $id, $antes);

// Baja
$this->auditarEliminacion('tipodato', $id, $antes);

// Listas asociadas (roles de un usuario, permisos de un rol...)
$this->auditarLista('rol', $rolId, 'Permisos', $permisosAntes, $permisosDespues);
```

Los recursos que llevan institución pasan además el identificador como último
argumento, de modo que la lectura quede acotada a la institución del token.

La clase `api/core/Auditoria.php` se encarga del resto: omite los campos que no
cambiaron y resuelve la IP del cliente considerando los proxys del hosting. Ya no
necesita enmascarar contraseñas: como no escribe valores, no hay nada que
enmascarar —de un cambio de clave queda que se cambió, nunca la clave—. Si la tabla `auditoria` todavía no existe, se desactiva sola
y deja un aviso en el log: la operación del usuario nunca se interrumpe.
