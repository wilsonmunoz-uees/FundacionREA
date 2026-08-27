# Plantillas editables

Este archivo contiene el texto del correo que sale fuera del sistema. Puede
editarlo libremente: es HTML con algunas variables de PHP intercaladas. No hace
falta tocar nada más del código.

| Archivo | Dónde se usa |
|---|---|
| `correo_confirmacion.php` | Correo que confirma la decisión tomada en el enlace público de consentimiento. |
| `correo_codigo_verificacion.php` | Correo con el código de los enlaces de consentimiento. |
| `correo_invitacion_consentimiento.php` | Correo de invitación que sale desde *Envío Masivo de Invitaciones*. |

> **Los textos legales (disclaimers) ya no están aquí.** Se administran desde la
> aplicación, en *Administración › Disclaimers de Datos*, con un editor de texto
> enriquecido y control de versión y vigencia.

## Variables disponibles

Dentro del archivo existe el arreglo `$datos`. Use siempre `$e(...)` para
imprimir un valor: escapa el texto y evita que un dato con comillas o signos
rompa el mensaje.

| Variable | Contenido |
|---|---|
| `$datos['tipo']` | ESTUDIANTE, EMPLEADO o PROVEEDOR |
| `$datos['decision']` | OTORGA o REVOCA |
| `$datos['titular']` | Nombre del titular de los datos |
| `$datos['identificacion']` | Número de documento del titular |
| `$datos['documento']` | CEDULA o RUC |
| `$datos['representante']` | Nombre del representante (solo estudiantes) |
| `$datos['es_representante']` | `true` si el correo va dirigido al representante |
| `$datos['institucion']` | Nombre de la institución educativa |
| `$datos['version']` | Versión del disclaimer que se aceptó |
| `$datos['fecha']` | Fecha y hora del registro, ya con formato |

## Cuidados

- El archivo se ejecuta como PHP. Si queda con un error de sintaxis, el sistema
  usa un texto de respaldo y anota el problema en el log: **el correo sale
  igual**, solo que con el texto mínimo.
- Guarde el archivo en **UTF-8** para que los acentos se vean bien.
- Escriba los estilos dentro de cada etiqueta (`style="..."`). Los lectores de
  correo suelen descartar las hojas de estilo externas.


## correo_codigo_verificacion.php

Correo con el código de los **Enlaces de Consentimiento**. Se
envía antes de mostrar el disclaimer, para comprobar que quien abrió el enlace
es la persona registrada.

Variables disponibles en `$datos`:

| Clave | Contenido |
|---|---|
| `codigo` | Los 6 dígitos que debe escribir la persona |
| `titular` | Nombre completo del titular de los datos |
| `identificacion` | Cédula o RUC consultado |
| `documento` | `CEDULA` o `RUC` |
| `tipo` | `ESTUDIANTE`, `EMPLEADO` o `PROVEEDOR` |
| `es_representante` | `true` cuando el correo va al representante de un estudiante |
| `institucion` | Nombre de la institución educativa |
| `minutos` | Minutos de validez del código (10) |
| `expira` | Hora de caducidad, ya formateada |
| `fecha` | Momento en que se solicitó |

Si borra el archivo o queda con un error, el sistema usa un texto de respaldo y
el correo sale igual; el problema se anota en el log.


## correo_invitacion_consentimiento.php

Correo de invitación del **Envío Masivo de Invitaciones** (*Registro de Datos ›
Envío Masivo*). Lleva el enlace de consentimiento **con verificación** del tipo
de persona, con su documento ya cargado: quien lo abre solo tiene que continuar.

En el caso de los estudiantes va dirigido al **representante** —es su correo el
que consta en la ficha— y nombra al representado.

Variables disponibles en `$datos`:

| Clave | Contenido |
|---|---|
| `enlace` | Dirección completa del enlace con verificación, con `&doc=` ya puesto |
| `titular` | Nombre completo del titular de los datos |
| `identificacion` | Cédula o RUC que viene precargado en el enlace |
| `documento` | `CEDULA` o `RUC` |
| `tipo` | `ESTUDIANTE`, `EMPLEADO` o `PROVEEDOR` |
| `es_representante` | `true` cuando el correo va al representante de un estudiante |
| `representante` | Nombre del representante (solo estudiantes) |
| `institucion` | Nombre de la institución educativa |
| `version` | Versión vigente del disclaimer |
| `fecha` | Fecha del envío |

El correo sale por el servidor SMTP de la institución con la que se inició
sesión (*Administración › Configuración de Correo*): cada institución invita a su
gente con su propio remitente. Este archivo es solo el **texto** del mensaje; el
**remitente y el servidor** se administran desde esa pantalla.

Igual que las demás plantillas, si el archivo falta o queda con un error el
sistema usa un texto de respaldo y el correo sale de todos modos.
