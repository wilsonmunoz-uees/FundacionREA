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
módulos, desde los enlaces públicos o desde la PreCarga Inicial, y toda la
escritura vive en `api/core/Padron.php`, que aplica una sola regla: la
identificación es la llave dentro de la institución, de modo que una persona ya
registrada se reutiliza en lugar de duplicarse.

Esto significa que `POST /api/empleados`, `/api/estudiantes` y `/api/proveedores`
reciben los datos personales en el mismo cuerpo —`identificacion`,
`tipo_identificacion`, `nombres`, `apellidos`, `email`, `telefono`, y los mismos
con prefijo `rep_` para el representante de un estudiante— en vez de un
`persona_id`.

### Verificación pública por código (SIN token)
| Método | Ruta |
|---|---|
| POST | `/api/verificacion-publica/consultar` |
| POST | `/api/verificacion-publica/enviar-codigo` |
| POST | `/api/verificacion-publica/validar-codigo` |

Atienden los **Enlaces de Consentimiento** —los que se difunden desde
*Administración › Enlaces de Consentimiento* y desde el Envío Masivo—, que
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

### PreCarga inicial
| Método | Ruta | Acceso |
|---|---|---|
| POST | `/api/precarga/previsualizar` | Solo SuperAdmin |
| POST | `/api/precarga/procesar` | Solo SuperAdmin |

Puebla de una vez el padrón de la institución activa desde la plantilla Excel.
El archivo viaja dentro del JSON, en base64, y se lee con
`api/core/LectorXlsx.php` —un lector propio sobre `ZipArchive` y `SimpleXML`,
sin librerías externas—.

`previsualizar` valida el archivo completo **sin tocar la base** y devuelve los
conteos por hoja, los errores con hoja y fila, y el inventario de lo que se
eliminaría. `procesar` repite esa validación y solo actúa si el archivo está
limpio y el cuerpo trae `confirmacion: "ENCERAR Y CARGAR"`; el encerado y la
carga van en una sola transacción.

La plantilla **no pide el estado**: una carga inicial parte de cero, de modo que
todo lo que entra queda **ACTIVO**. Dar de baja a alguien es una decisión
posterior y se hace desde la pantalla que le corresponde. Si el archivo trae una
columna `Estado` —de una plantilla anterior— se ignora, y si alguna fila la traía
en algo distinto de ACTIVO se avisa en las advertencias, para que nadie dé por
hecho que se respetó.

Lo que se elimina queda acotado a la institución del token: consentimientos con
su historial y detalle, empleados, estudiantes, proveedores y las personas que
queden sin ningún vínculo. **No se tocan** usuarios, roles, permisos, catálogos,
disclaimers, la configuración de correo, las personas con cuenta de usuario ni
los datos de las demás instituciones de la red. La operación deja una anotación
de balance en la bitácora de auditoría.

Junto con el Envío Masivo, es una de las dos opciones sin permiso asignable:
`precarga` declara `'permisos' => []` en `includes/accesos.php`, de modo que la
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
Enlaces de Consentimiento**: allí desemboca quien superó la verificación por
código, ya con la identidad confirmada.

`consentimiento.php` sigue atendiendo además el recorrido de autoservicio
—identificarse, darse de alta si no consta y decidir— para quien llegue a su
dirección directamente. **Ese recorrido ya no se publica desde ninguna pantalla
del sistema**: la opción que difundía esos enlaces se retiró, y lo que se
reparte hoy son los enlaces con verificación. Si quiere cerrarlo del todo, el
punto único es `consentimiento.php`: bastaría con exigir el pase de verificación
para continuar.

`registrar` responde `409` cuando se intenta revocar un consentimiento ya
otorgado: esa vía se tramita por correo con la institución.

### Instalación
`POST /api/setup/admin` crea la primera cuenta SuperAdmin. Solo funciona si la
institución aún no tiene usuarios.

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
modificado con su valor original y el nuevo.

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

La clase `api/core/Auditoria.php` se encarga del resto: enmascara los campos
sensibles (`PasswordHash`) como `********`, recorta los valores muy largos,
omite los campos que no cambiaron y resuelve la IP del cliente considerando los
proxys del hosting. Si la tabla `auditoria` todavía no existe, se desactiva sola
y deja un aviso en el log: la operación del usuario nunca se interrumpe.
