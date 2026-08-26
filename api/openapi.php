<?php
/**
 * api/openapi.php
 * -----------------------------------------------------------------------------
 * Especificación OpenAPI 3.0 de la API REST del Sistema de Gestión de
 * Protección de Datos - Red Educativa Arquidiocesana (REA).
 *
 * Este archivo devuelve la especificación como arreglo PHP; el front controller
 * la publica en /api/openapi.json y Swagger UI la consume desde /api/docs.
 *
 * La URL del servidor se detecta sola, así que el documento sirve igual en
 * localhost que en el hosting, sin editar nada.
 * -----------------------------------------------------------------------------
 */

/* ==========================================================================
   Utilidades para no repetir estructuras
   ========================================================================== */

/** Envoltura estándar de respuesta correcta: { ok, datos } */
$sobre = static function (array $datos): array {
    return [
        'type'       => 'object',
        'properties' => [
            'ok'    => ['type' => 'boolean', 'example' => true],
            'datos' => $datos,
        ],
    ];
};

/** Envoltura de listado paginado: { ok, datos: [...], meta } */
$sobreLista = static function (string $ref, array $metaExtra = []): array {
    $meta = [
        'type'       => 'object',
        'properties' => array_merge([
            'total'         => ['type' => 'integer', 'example' => 42],
            'pagina'        => ['type' => 'integer', 'example' => 1],
            'por_pagina'    => ['type' => 'integer', 'example' => 12],
            'total_paginas' => ['type' => 'integer', 'example' => 4],
        ], $metaExtra),
    ];

    return [
        'type'       => 'object',
        'properties' => [
            'ok'    => ['type' => 'boolean', 'example' => true],
            'datos' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . $ref]],
            'meta'  => $meta,
        ],
    ];
};

/** Respuesta 200 con un cuerpo JSON dado. */
$respuesta = static function (string $descripcion, array $esquema): array {
    return [
        'description' => $descripcion,
        'content'     => ['application/json' => ['schema' => $esquema]],
    ];
};

/** Cuerpo de petición JSON obligatorio. */
$cuerpo = static function (string $ref): array {
    return [
        'required' => true,
        'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/' . $ref]]],
    ];
};

/** Respuesta de operación de escritura: { ok, datos: { <clave>, mensaje } } */
$respuestaEscritura = static function (string $descripcion, string $claveId) use ($respuesta): array {
    return $respuesta($descripcion, [
        'type'       => 'object',
        'properties' => [
            'ok'    => ['type' => 'boolean', 'example' => true],
            'datos' => [
                'type'       => 'object',
                'properties' => [
                    $claveId  => ['type' => 'integer', 'example' => 15],
                    'mensaje' => ['type' => 'string', 'example' => 'Operación realizada correctamente.'],
                ],
            ],
        ],
    ]);
};

/** Errores comunes reutilizables en cada operación. */
$erroresComunes = static function (array $extra = []): array {
    // Ojo: se usa la unión (+) y no array_merge, porque PHP convierte las claves
    // '401'/'403' en enteros y array_merge las renumeraría.
    $respuestas = $extra + [
        '401' => ['$ref' => '#/components/responses/NoAutenticado'],
        '403' => ['$ref' => '#/components/responses/Prohibido'],
    ];
    ksort($respuestas);
    return $respuestas;
};

/* ==========================================================================
   Bloques CRUD repetitivos (personas, empleados, estudiantes, proveedores...)
   ========================================================================== */

/**
 * Genera las rutas /recurso, /recurso/{id} y /recurso/{id}/estado
 * a partir de una configuración mínima.
 */
$crud = static function (array $cfg) use ($sobre, $sobreLista, $respuesta, $cuerpo, $respuestaEscritura, $erroresComunes): array {
    $ruta       = $cfg['ruta'];                 // 'personas'
    $etiqueta   = $cfg['tag'];                  // 'Personas'
    $singular   = $cfg['singular'];             // 'persona'
    $esquema    = $cfg['esquema'];              // 'Persona'
    $entrada    = $cfg['entrada'];              // 'PersonaEntrada'
    $claveId    = $cfg['clave_id'];             // 'PersonaId'
    $acceso     = $cfg['acceso'];               // texto con los roles permitidos
    $metaExtra  = $cfg['meta_extra'] ?? [];
    $filtros    = $cfg['filtros'] ?? [];
    $conEstado  = $cfg['con_estado'] ?? true;
    $busqueda   = $cfg['busqueda'] ?? 'Texto libre de búsqueda.';

    $parametrosListado = array_merge([
        [
            'name'        => 'q',
            'in'          => 'query',
            'description' => $busqueda,
            'schema'      => ['type' => 'string'],
        ],
        ['$ref' => '#/components/parameters/Pagina'],
        ['$ref' => '#/components/parameters/PorPagina'],
    ], $filtros);

    $paths = [];

    $paths['/' . $ruta] = [
        'get' => [
            'tags'        => [$etiqueta],
            'summary'     => 'Listar ' . $ruta,
            'description' => "Listado paginado.\n\n**Acceso:** " . $acceso,
            'operationId' => 'listar' . ucfirst(str_replace('-', '', $ruta)),
            'parameters'  => $parametrosListado,
            'responses'   => $erroresComunes([
                '200' => $respuesta('Listado paginado.', $sobreLista($esquema, $metaExtra)),
            ]),
        ],
        'post' => [
            'tags'        => [$etiqueta],
            'summary'     => 'Crear ' . $singular,
            'description' => "**Acceso:** " . $acceso,
            'operationId' => 'crear' . $esquema,
            'requestBody' => $cuerpo($entrada),
            'responses'   => $erroresComunes([
                '201' => $respuestaEscritura('Registro creado.', $claveId),
                '409' => ['$ref' => '#/components/responses/Conflicto'],
                '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
            ]),
        ],
    ];

    $paths['/' . $ruta . '/{id}'] = [
        'get' => [
            'tags'        => [$etiqueta],
            'summary'     => 'Obtener ' . $singular,
            'description' => "**Acceso:** " . $acceso,
            'operationId' => 'ver' . $esquema,
            'parameters'  => [['$ref' => '#/components/parameters/IdRuta']],
            'responses'   => $erroresComunes([
                '200' => $respuesta('Registro encontrado.', $sobre(['$ref' => '#/components/schemas/' . $esquema])),
                '404' => ['$ref' => '#/components/responses/NoEncontrado'],
            ]),
        ],
        'put' => [
            'tags'        => [$etiqueta],
            'summary'     => 'Actualizar ' . $singular,
            'description' => "**Acceso:** " . $acceso,
            'operationId' => 'actualizar' . $esquema,
            'parameters'  => [['$ref' => '#/components/parameters/IdRuta']],
            'requestBody' => $cuerpo($entrada),
            'responses'   => $erroresComunes([
                '200' => $respuestaEscritura('Registro actualizado.', $claveId),
                '404' => ['$ref' => '#/components/responses/NoEncontrado'],
                '409' => ['$ref' => '#/components/responses/Conflicto'],
                '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
            ]),
        ],
    ];

    if ($conEstado) {
        $paths['/' . $ruta . '/{id}/estado'] = [
            'patch' => [
                'tags'        => [$etiqueta],
                'summary'     => 'Activar / inactivar ' . $singular,
                'description' => "Si no se envía cuerpo, alterna el estado actual (ACTIVO ↔ INACTIVO).\n\n**Acceso:** " . $acceso,
                'operationId' => 'estado' . $esquema,
                'parameters'  => [['$ref' => '#/components/parameters/IdRuta']],
                'requestBody' => [
                    'required' => false,
                    'content'  => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CambioEstado']]],
                ],
                'responses'   => $erroresComunes([
                    '200' => $respuesta('Estado actualizado.', [
                        'type'       => 'object',
                        'properties' => [
                            'ok'    => ['type' => 'boolean', 'example' => true],
                            'datos' => [
                                'type'       => 'object',
                                'properties' => [
                                    $claveId  => ['type' => 'integer', 'example' => 15],
                                    'estado'  => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']],
                                    'mensaje' => ['type' => 'string', 'example' => 'Estado actualizado.'],
                                ],
                            ],
                        ],
                    ]),
                    '404' => ['$ref' => '#/components/responses/NoEncontrado'],
                ]),
            ],
        ];
    }

    return $paths;
};

/* ==========================================================================
   Detección de la URL pública de la API
   ========================================================================== */

$esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443);

$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$rutaBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php')), '/');
$servidor = ($esHttps ? 'https' : 'http') . '://' . $host . ($rutaBase === '' ? '' : $rutaBase);

/* ==========================================================================
   Documento OpenAPI
   ========================================================================== */

$spec = [
    'openapi' => '3.0.3',

    'info' => [
        'title'       => 'API REST - Protección de Datos (REA)',
        'version'     => '1.0.0',
        'description' => <<<'TXT'
API del **Sistema de Gestión de Protección de Datos** de la Red Educativa
Arquidiocesana. Concentra toda la conectividad con la base de datos: las
páginas del sitio la consumen por HTTP y no abren conexiones propias.

### Cómo autenticarse desde esta página

1. Ejecute `POST /auth/login` con usuario, contraseña e institución.
2. Copie el valor de `datos.token` de la respuesta.
3. Pulse **Authorize** (arriba a la derecha) y pegue el token.
4. Todas las operaciones siguientes viajarán con `Authorization: Bearer <token>`.

El token es autocontenido (firmado con HMAC-SHA256) y caduca a las 8 horas.

### Multi-institución

Cada token queda ligado a una institución educativa. Los listados y las
escrituras se filtran automáticamente por ella: no hace falta enviar el
identificador de institución en cada llamada.

### Formato de respuesta

```json
{ "ok": true,  "datos": { }, "meta": { } }
{ "ok": false, "error": "mensaje", "errores": ["detalle"] }
```
TXT,
        'contact' => [
            'name' => 'Red Educativa Arquidiocesana (REA)',
        ],
    ],

    'servers' => [
        ['url' => $servidor, 'description' => 'Servidor actual'],
    ],

    'tags' => [
        ['name' => 'Sesión',          'description' => 'Autenticación y contexto del usuario'],
        ['name' => 'Instituciones',   'description' => 'Instituciones educativas'],
        ['name' => 'Personas',        'description' => 'Directorio general de personas (entidad base)'],
        ['name' => 'Empleados',       'description' => 'Personal de la institución'],
        ['name' => 'Estudiantes',     'description' => 'Alumnos matriculados y representantes'],
        ['name' => 'Proveedores',     'description' => 'Proveedores de bienes y servicios'],
        ['name' => 'Consentimientos', 'description' => 'Núcleo del sistema: consentimientos y su bitácora'],
        ['name' => 'Usuarios',        'description' => 'Cuentas de acceso y asignación de roles'],
        ['name' => 'Roles',           'description' => 'Roles y sus permisos'],
        ['name' => 'Permisos',        'description' => 'Catálogo de permisos por módulo'],
        ['name' => 'Catálogos',       'description' => 'Finalidades del tratamiento y tipos de dato personal'],
        ['name' => 'Consultas',       'description' => 'Búsquedas transversales y auditoría'],
        ['name' => 'Reportes',        'description' => 'Indicadores, reportes de cumplimiento y exportación'],
        ['name' => 'Correo',          'description' => 'Servidor de correo saliente de la institución'],
        ['name' => 'Disclaimers',     'description' => 'Textos de política de datos: versión, vigencia y tipo de persona'],
        ['name' => 'Consentimiento Público', 'description' => 'Rutas SIN token de los tres enlaces abiertos de autoservicio'],
        ['name' => 'Verificación Pública', 'description' => 'Rutas SIN token de los enlaces de consentimiento con verificación de identidad'],
        ['name' => 'PreCarga',        'description' => 'Carga masiva del padrón de la institución desde la plantilla Excel'],
        ['name' => 'Envío Masivo',    'description' => 'Invitaciones por correo al consentimiento con verificación'],
        ['name' => 'Instalación',     'description' => 'Puesta en marcha inicial'],
    ],

    'security' => [['bearerAuth' => []]],
];

/* --------------------------------------------------------------------------
   Componentes reutilizables
   -------------------------------------------------------------------------- */

$spec['components'] = [

    'securitySchemes' => [
        'bearerAuth' => [
            'type'         => 'http',
            'scheme'       => 'bearer',
            'bearerFormat' => 'Token firmado (HMAC-SHA256)',
            'description'  => 'Token devuelto por POST /auth/login. Pegue aquí solo el token, sin la palabra "Bearer".',
        ],
    ],

    'parameters' => [
        'IdRuta' => [
            'name'     => 'id',
            'in'       => 'path',
            'required' => true,
            'schema'   => ['type' => 'integer', 'minimum' => 1],
            'description' => 'Identificador del registro.',
        ],
        'Pagina' => [
            'name'        => 'pagina',
            'in'          => 'query',
            'description' => 'Número de página (empieza en 1).',
            'schema'      => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
        ],
        'PorPagina' => [
            'name'        => 'por_pagina',
            'in'          => 'query',
            'description' => 'Registros por página (máximo 500).',
            'schema'      => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500],
        ],
    ],

    'schemas' => [

        /* ---------- Envolturas y utilidades ---------- */

        'Error' => [
            'type'       => 'object',
            'properties' => [
                'ok'      => ['type' => 'boolean', 'example' => false],
                'error'   => ['type' => 'string', 'example' => 'No cuenta con permisos suficientes.'],
                'errores' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Presente solo en errores de validación.',
                ],
            ],
        ],

        'CambioEstado' => [
            'type'       => 'object',
            'properties' => [
                'estado' => [
                    'type'        => 'string',
                    'enum'        => ['ACTIVO', 'INACTIVO'],
                    'description' => 'Opcional. Si se omite, se alterna el estado actual.',
                ],
            ],
        ],

        'Opcion' => [
            'type'        => 'object',
            'description' => 'Par valor/etiqueta para alimentar listas desplegables.',
            'properties'  => [
                'PersonaId' => ['type' => 'integer', 'example' => 9],
                'etiqueta'  => ['type' => 'string', 'example' => 'MUÑOZ RECALDE WILSON - 0916686009'],
            ],
        ],

        /* ---------- Sesión ---------- */

        'LoginEntrada' => [
            'type'     => 'object',
            'required' => ['username', 'password', 'institucion_id'],
            'properties' => [
                'username'       => ['type' => 'string', 'example' => 'admin'],
                'password'       => ['type' => 'string', 'format' => 'password', 'example' => 'admin123'],
                'institucion_id' => ['type' => 'integer', 'example' => 1],
            ],
        ],

        'LoginRespuesta' => [
            'type'       => 'object',
            'properties' => [
                'ok'    => ['type' => 'boolean', 'example' => true],
                'datos' => [
                    'type'       => 'object',
                    'properties' => [
                        'token'    => ['type' => 'string', 'description' => 'Token Bearer para las siguientes llamadas.'],
                        'expira'   => ['type' => 'integer', 'description' => 'Marca de tiempo Unix de caducidad.'],
                        'vigencia' => ['type' => 'integer', 'example' => 28800, 'description' => 'Segundos de vigencia.'],
                        'usuario'  => ['$ref' => '#/components/schemas/UsuarioSesion'],
                    ],
                ],
            ],
        ],

        'UsuarioSesion' => [
            'type'       => 'object',
            'properties' => [
                'usuario_id'         => ['type' => 'integer', 'example' => 1],
                'persona_id'         => ['type' => 'integer', 'example' => 1],
                'username'           => ['type' => 'string', 'example' => 'admin'],
                'email'              => ['type' => 'string', 'nullable' => true],
                'institucion_id'     => ['type' => 'integer', 'example' => 1],
                'institucion_nombre' => ['type' => 'string', 'example' => 'Escuela 1'],
                'institucion_propia' => ['type' => 'integer', 'example' => 1, 'description' => 'Institución a la que pertenece la cuenta.'],
                'visita'             => ['type' => 'boolean', 'example' => false, 'description' => 'true cuando un SuperAdmin trabaja en una institución distinta a la suya.'],
                'roles'              => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['SuperAdmin']],
                'permisos'           => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['REGISTRO_DATOS']],
            ],
        ],

        /* ---------- Instituciones ---------- */

        'Institucion' => [
            'type'       => 'object',
            'properties' => [
                'id'              => ['type' => 'integer', 'example' => 1],
                'nombre'          => ['type' => 'string', 'example' => 'Unidad Educativa San José'],
                'direccion'       => ['type' => 'string'],
                'telefono'        => ['type' => 'string'],
                'nombre_logotipo' => ['type' => 'string', 'nullable' => true],
                'estado'          => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']],
            ],
        ],

        'InstitucionEntrada' => [
            'type'     => 'object',
            'required' => ['nombre', 'direccion', 'telefono'],
            'properties' => [
                'id'              => ['type' => 'integer', 'description' => 'Obligatorio al crear (identificador numérico manual).'],
                'nombre'          => ['type' => 'string', 'maxLength' => 50],
                'direccion'       => ['type' => 'string', 'maxLength' => 100],
                'telefono'        => ['type' => 'string', 'maxLength' => 20],
                'nombre_logotipo' => ['type' => 'string', 'nullable' => true],
                'estado'          => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO'], 'default' => 'ACTIVO'],
            ],
        ],

        /* ---------- Personas ---------- */

        'Persona' => [
            'type'       => 'object',
            'properties' => [
                'InstitucionEducativaId' => ['type' => 'integer', 'description' => 'Institución a la que pertenece la ficha.'],
                'InstitucionEducativaId' => ['type' => 'integer', 'description' => 'Institución a la que pertenece la ficha.'],
                'PersonaId'          => ['type' => 'integer', 'example' => 9],
                'TipoIdentificacion' => ['type' => 'string', 'enum' => ['CEDULA', 'RUC', 'PASAPORTE', ''], 'nullable' => true],
                'Identificacion'     => ['type' => 'string', 'nullable' => true, 'example' => '0916686009'],
                'Nombres'            => ['type' => 'string', 'example' => 'WILSON FIDEL'],
                'Apellidos'          => ['type' => 'string', 'example' => 'MUÑOZ RECALDE'],
                'Email'              => ['type' => 'string', 'nullable' => true],
                'Telefono'           => ['type' => 'string', 'nullable' => true],
                'Estado'             => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']],
            ],
        ],

        'PersonaEntrada' => [
            'type'     => 'object',
            'required' => ['nombres', 'apellidos'],
            'properties' => [
                'tipo_identificacion' => ['type' => 'string', 'enum' => ['CEDULA', 'RUC', 'PASAPORTE', '']],
                'identificacion'      => ['type' => 'string', 'maxLength' => 50, 'nullable' => true],
                'nombres'             => ['type' => 'string', 'maxLength' => 100, 'example' => 'María José'],
                'apellidos'           => ['type' => 'string', 'maxLength' => 100, 'example' => 'Pérez Loor'],
                'email'               => ['type' => 'string', 'format' => 'email', 'nullable' => true],
                'telefono'            => ['type' => 'string', 'maxLength' => 20, 'nullable' => true],
                'estado'              => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO'], 'default' => 'ACTIVO'],
            ],
        ],

        'FichaPersona' => [
            'type'        => 'object',
            'description' => 'Vista 360° de una persona dentro de la institución activa.',
            'properties'  => [
                'persona'         => ['$ref' => '#/components/schemas/Persona'],
                'empleado'        => ['allOf' => [['$ref' => '#/components/schemas/Empleado']], 'nullable' => true],
                'estudiante'      => ['allOf' => [['$ref' => '#/components/schemas/Estudiante']], 'nullable' => true],
                'proveedor'       => ['allOf' => [['$ref' => '#/components/schemas/Proveedor']], 'nullable' => true],
                'usuario'         => ['allOf' => [['$ref' => '#/components/schemas/Usuario']], 'nullable' => true],
                'consentimientos' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Consentimiento']],
            ],
        ],

        /* ---------- Empleados ---------- */

        'Empleado' => [
            'type'       => 'object',
            'properties' => [
                'InstitucionEducativaId' => ['type' => 'integer'],
                'EmpleadoId'             => ['type' => 'integer', 'example' => 3],
                'PersonaId'              => ['type' => 'integer', 'nullable' => true],
                'Estado'                 => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']],
                'Nombres'                => ['type' => 'string', 'description' => 'Dato de la persona vinculada.'],
                'Apellidos'              => ['type' => 'string', 'description' => 'Dato de la persona vinculada.'],
                'Identificacion'         => ['type' => 'string', 'nullable' => true],
                'Email'                  => ['type' => 'string', 'nullable' => true],
            ],
        ],

        'EmpleadoEntrada' => [
            'type'     => 'object',
            'required' => ['persona_id'],
            'properties' => [
                'persona_id'   => ['type' => 'integer', 'example' => 9],
                'estado'       => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO'], 'default' => 'ACTIVO'],
            ],
        ],

        /* ---------- Estudiantes ---------- */

        'Estudiante' => [
            'type'       => 'object',
            'properties' => [
                'InstitucionEducativaId' => ['type' => 'integer'],
                'EstudianteId'           => ['type' => 'integer', 'example' => 5],
                'PersonaId'              => ['type' => 'integer', 'nullable' => true],
                'CodigoEstudiante'       => ['type' => 'string', 'example' => 'EST-2026-014'],
                'RepresentanteId'        => ['type' => 'integer', 'nullable' => true],
                'RepresentanteRelacion'  => ['type' => 'string', 'nullable' => true, 'enum' => ['MADRE', 'PADRE', 'ABUELO', 'ABUELA', 'TIO', 'TIA', 'REPRESENTANTE LEGAL', 'TUTOR/A', 'OTRO', null]],
                'Estado'                 => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']],
                'Nombres'                => ['type' => 'string'],
                'Apellidos'              => ['type' => 'string'],
                'RepNombres'             => ['type' => 'string', 'nullable' => true],
                'RepApellidos'           => ['type' => 'string', 'nullable' => true],
                'RepIdentificacion'      => ['type' => 'string', 'nullable' => true, 'description' => 'Solo en la consulta individual.'],
            ],
        ],

        'EstudianteEntrada' => [
            'type'     => 'object',
            'required' => ['persona_id', 'codigo_estudiante'],
            'properties' => [
                'persona_id'             => ['type' => 'integer', 'example' => 9],
                'codigo_estudiante'      => ['type' => 'string', 'maxLength' => 20, 'example' => 'EST-2026-014'],
                'representante_id'       => ['type' => 'integer', 'nullable' => true, 'description' => 'No puede ser la misma persona que el estudiante.'],
                'representante_relacion' => ['type' => 'string', 'enum' => ['MADRE', 'PADRE', 'ABUELO', 'ABUELA', 'TIO', 'TIA', 'REPRESENTANTE LEGAL', 'TUTOR/A', 'OTRO']],
                'estado'                 => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO'], 'default' => 'ACTIVO'],
            ],
        ],

        /* ---------- Proveedores ---------- */

        'Proveedor' => [
            'type'       => 'object',
            'properties' => [
                'InstitucionEducativaId' => ['type' => 'integer'],
                'ProveedorId'            => ['type' => 'integer', 'example' => 2],
                'PersonaId'              => ['type' => 'integer'],
                'Ruc'                    => ['type' => 'string', 'nullable' => true],
                'RazonSocial'            => ['type' => 'string', 'example' => 'Servicios Educativos S.A.'],
                'Estado'                 => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']],
                'Nombres'                => ['type' => 'string', 'nullable' => true],
                'Apellidos'              => ['type' => 'string', 'nullable' => true],
                'Identificacion'         => ['type' => 'string', 'nullable' => true, 'description' => 'Solo en la consulta individual.'],
            ],
        ],

        'ProveedorEntrada' => [
            'type'     => 'object',
            'required' => ['persona_id', 'razon_social'],
            'properties' => [
                'persona_id'   => ['type' => 'integer', 'description' => 'Persona de contacto.'],
                'ruc'          => ['type' => 'string', 'maxLength' => 20],
                'razon_social' => ['type' => 'string', 'maxLength' => 150],
                'estado'       => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO'], 'default' => 'ACTIVO'],
            ],
        ],

        /* ---------- Consentimientos ---------- */

        'Consentimiento' => [
            'type'       => 'object',
            'properties' => [
                'InstitucionEducativaId' => ['type' => 'integer'],
                'ConsentimientoId'       => ['type' => 'integer', 'example' => 12],
                'PersonaId'              => ['type' => 'integer', 'nullable' => true],
                'FinalidadId'            => ['type' => 'integer', 'nullable' => true],
                'FechaConsentimiento'    => ['type' => 'string', 'example' => '2026-08-20 10:30:00'],
                'FechaRevocacion'        => ['type' => 'string', 'nullable' => true],
                'RepresentanteId'        => ['type' => 'integer', 'nullable' => true],
                'MedioConsentimiento'    => ['type' => 'string', 'nullable' => true, 'enum' => ['WEB', 'EMAIL', 'WHATSAPP', 'APP', null]],
                'VersionPolitica'        => ['type' => 'string', 'nullable' => true, 'example' => 'v1.0'],
                'IpOrigen'               => ['type' => 'string', 'nullable' => true],
                'Estado'                 => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']],
                'Nombres'                => ['type' => 'string', 'nullable' => true],
                'Apellidos'              => ['type' => 'string', 'nullable' => true],
                'Identificacion'         => ['type' => 'string', 'nullable' => true, 'description' => 'Solo en la consulta individual.'],
                'FinalidadNombre'        => ['type' => 'string', 'nullable' => true],
                'RepNombres'             => ['type' => 'string', 'nullable' => true, 'description' => 'Solo en la consulta individual.'],
                'RepApellidos'           => ['type' => 'string', 'nullable' => true, 'description' => 'Solo en la consulta individual.'],
                'RepIdentificacion'      => ['type' => 'string', 'nullable' => true, 'description' => 'Solo en la consulta individual.'],
                'tipos_autorizados'      => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer'],
                    'description' => 'Solo en la consulta individual: TipoDatoId autorizados.',
                ],
            ],
        ],

        'ConsentimientoEntrada' => [
            'type'     => 'object',
            'required' => ['persona_id', 'finalidad_id'],
            'properties' => [
                'persona_id'           => ['type' => 'integer', 'example' => 9, 'description' => 'Titular del dato.'],
                'finalidad_id'         => ['type' => 'integer', 'example' => 1],
                'representante_id'     => ['type' => 'integer', 'nullable' => true, 'description' => 'Si el titular es menor de edad.'],
                'medio'                => ['type' => 'string', 'enum' => ['WEB', 'EMAIL', 'WHATSAPP', 'APP']],
                'version_politica'     => ['type' => 'string', 'maxLength' => 50, 'example' => 'v1.0'],
                'fecha_consentimiento' => ['type' => 'string', 'example' => '2026-08-20 10:30:00', 'description' => 'Si se omite, se usa la fecha y hora actual.'],
                'ip_origen'            => ['type' => 'string', 'maxLength' => 20],
                'tipos_autorizados'    => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer'],
                    'example'     => [1, 3],
                    'description' => 'TipoDatoId autorizados. Los no incluidos se guardan como NO autorizados.',
                ],
            ],
        ],

        'CatalogosConsentimiento' => [
            'type'       => 'object',
            'properties' => [
                'personas'    => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Opcion']],
                'finalidades' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Finalidad']],
                'tipos_dato'  => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TipoDato']],
            ],
        ],

        'TitularConsentimiento' => [
            'type'        => 'object',
            'description' => 'Fila del reporte de titulares (consentimiento otorgado o revocado).',
            'properties'  => [
                'ConsentimientoId'    => ['type' => 'integer'],
                'FechaConsentimiento' => ['type' => 'string', 'example' => '2026-08-20 10:30:00'],
                'FechaRevocacion'     => ['type' => 'string', 'nullable' => true],
                'Estado'              => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']],
                'EstadoTexto'         => ['type' => 'string', 'enum' => ['CONSENTIDO', 'REVOCADO']],
                'IpOrigen'            => ['type' => 'string', 'nullable' => true],
                'MedioConsentimiento' => ['type' => 'string', 'nullable' => true],
                'PersonaId'           => ['type' => 'integer'],
                'Nombres'             => ['type' => 'string'],
                'Apellidos'           => ['type' => 'string'],
                'Identificacion'      => ['type' => 'string', 'nullable' => true],
                'RazonSocial'         => ['type' => 'string', 'nullable' => true, 'description' => 'Presente si el titular es proveedor.'],
                'Titular'             => ['type' => 'string', 'description' => 'Apellidos y nombres, o la razón social si es proveedor.'],
                'TipoPersona'         => ['type' => 'string', 'example' => 'Estudiante / Empleado'],
                'FinalidadNombre'     => ['type' => 'string', 'nullable' => true],
                'EsEstudiante'        => ['type' => 'integer'],
                'EsEmpleado'          => ['type' => 'integer'],
            ],
        ],

        'MovimientoAuditoria' => [
            'type'        => 'object',
            'description' => 'Fila de la bitácora de auditoría: un campo afectado de un registro. Anota QUÉ se tocó, nunca el contenido del dato.',
            'properties'  => [
                'AuditoriaId'    => ['type' => 'integer'],
                'FechaHora'      => ['type' => 'string', 'example' => '2026-08-20 10:30:00'],
                'Username'       => ['type' => 'string', 'nullable' => true, 'description' => 'Usuario que ejecutó la acción.'],
                'IpOrigen'       => ['type' => 'string', 'nullable' => true],
                'Tabla'          => ['type' => 'string', 'example' => 'persona'],
                'RegistroId'     => ['type' => 'string', 'nullable' => true, 'description' => 'Identificador del registro afectado.'],
                'Operacion'      => ['type' => 'string', 'enum' => ['INSERT', 'UPDATE', 'DELETE']],
                'OperacionTexto' => ['type' => 'string', 'enum' => ['ALTA', 'CAMBIO', 'BAJA']],
                'Campo'          => ['type' => 'string', 'nullable' => true, 'description' => 'Qué dato se tocó. La bitácora **no guarda el contenido**: ni el valor anterior ni el nuevo.'],
            ],
        ],

        'Disclaimer' => [
            'type'        => 'object',
            'description' => 'Texto de política de datos para un tipo de persona.',
            'properties'  => [
                'DisclaimerId'  => ['type' => 'integer'],
                'TipoPersona'   => ['type' => 'string', 'enum' => ['ESTUDIANTE', 'EMPLEADO', 'PROVEEDOR']],
                'Version'       => ['type' => 'string', 'example' => '1.0'],
                'Titulo'        => ['type' => 'string', 'nullable' => true],
                'Texto'         => ['type' => 'string', 'description' => 'HTML ya depurado.'],
                'Estado'        => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO'], 'description' => 'ACTIVO = es el vigente para su tipo.'],
                'FechaCreacion' => ['type' => 'string', 'nullable' => true],
                'FechaVigencia' => ['type' => 'string', 'nullable' => true],
                'Username'      => ['type' => 'string', 'nullable' => true],
            ],
        ],

        'DisclaimerEntrada' => [
            'type'     => 'object',
            'required' => ['tipo_persona', 'version', 'texto'],
            'properties' => [
                'tipo_persona' => ['type' => 'string', 'enum' => ['ESTUDIANTE', 'EMPLEADO', 'PROVEEDOR']],
                'version'      => ['type' => 'string', 'maxLength' => 20, 'example' => '1.0'],
                'titulo'       => ['type' => 'string', 'maxLength' => 150],
                'texto'        => ['type' => 'string', 'description' => 'Texto enriquecido; se depura al guardar.'],
                'estado'       => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO'], 'default' => 'INACTIVO'],
            ],
        ],

        'UsuarioBusqueda' => [
            'type'        => 'object',
            'description' => 'Fila reducida para la subpantalla de búsqueda de usuarios.',
            'properties'  => [
                'UsuarioId'      => ['type' => 'integer'],
                'Username'       => ['type' => 'string'],
                'Estado'         => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']],
                'UltimoAcceso'   => ['type' => 'string', 'nullable' => true],
                'Nombres'        => ['type' => 'string', 'nullable' => true],
                'Apellidos'      => ['type' => 'string', 'nullable' => true],
                'Identificacion' => ['type' => 'string', 'nullable' => true],
            ],
        ],

        'Historial' => [
            'type'       => 'object',
            'properties' => [
                'InstitucionEducativaId' => ['type' => 'integer'],
                'HistorialId'            => ['type' => 'integer'],
                'ConsentimientoId'       => ['type' => 'integer', 'nullable' => true],
                'EstadoAnterior'         => ['type' => 'string', 'nullable' => true, 'enum' => ['ACTIVO', 'INACTIVO', null]],
                'EstadoNuevo'            => ['type' => 'string', 'nullable' => true],
                'Accion'                 => ['type' => 'string', 'example' => 'REVOCACION', 'enum' => ['CREACION', 'MODIFICACION', 'REVOCACION', 'REACTIVACION']],
                'FechaAccion'            => ['type' => 'string', 'example' => '2026-08-20 16:53:46'],
                'UsuarioId'              => ['type' => 'integer', 'nullable' => true],
                'IpOrigen'               => ['type' => 'string', 'nullable' => true],
                'Observacion'            => ['type' => 'string', 'nullable' => true],
                'Nombres'                => ['type' => 'string', 'nullable' => true],
                'Apellidos'              => ['type' => 'string', 'nullable' => true],
                'Username'               => ['type' => 'string', 'nullable' => true],
                'FinalidadNombre'        => ['type' => 'string', 'nullable' => true],
            ],
        ],

        /* ---------- Usuarios, roles y permisos ---------- */

        'Usuario' => [
            'type'       => 'object',
            'properties' => [
                'InstitucionEducativaId' => ['type' => 'integer'],
                'UsuarioId'              => ['type' => 'integer', 'example' => 1],
                'PersonaId'              => ['type' => 'integer'],
                'Username'               => ['type' => 'string', 'example' => 'admin'],
                'Email'                  => ['type' => 'string', 'nullable' => true],
                'UltimoAcceso'           => ['type' => 'string', 'nullable' => true],
                'Estado'                 => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']],
                'Nombres'                => ['type' => 'string'],
                'Apellidos'              => ['type' => 'string'],
                'Roles'                  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Nombres de los roles asignados (solo en el listado).'],
                'roles_asignados'        => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'RolId asignados (solo en la consulta individual).'],
                'roles_disponibles'      => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Rol']],
            ],
            'description' => 'Nunca incluye PasswordHash.',
        ],

        'UsuarioEntrada' => [
            'type'     => 'object',
            'required' => ['username'],
            'properties' => [
                'persona_id'       => ['type' => 'integer', 'description' => 'Obligatorio al crear. No se modifica al actualizar.'],
                'username'         => ['type' => 'string', 'maxLength' => 50, 'example' => 'jperez'],
                'email'            => ['type' => 'string', 'format' => 'email', 'nullable' => true],
                'password'         => ['type' => 'string', 'format' => 'password', 'minLength' => 8, 'description' => 'Obligatoria al crear. Al actualizar, envíe vacío para no cambiarla.'],
                'password_confirm' => ['type' => 'string', 'format' => 'password'],
                'estado'           => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO'], 'default' => 'ACTIVO'],
                'roles'            => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'RolId a asignar; reemplaza la asignación anterior.'],
            ],
        ],

        'Rol' => [
            'type'       => 'object',
            'properties' => [
                'InstitucionEducativaId' => ['type' => 'integer'],
                'RolId'                  => ['type' => 'integer', 'example' => 1],
                'Nombre'                 => ['type' => 'string', 'example' => 'SuperAdmin'],
                'Descripcion'            => ['type' => 'string', 'nullable' => true],
                'Estado'                 => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']],
                'TotalPermisos'          => ['type' => 'integer', 'description' => 'Solo en el listado.'],
                'permisos_asignados'     => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Solo en la consulta individual.'],
                'permisos_disponibles'   => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Permiso']],
            ],
        ],

        'RolEntrada' => [
            'type'     => 'object',
            'required' => ['nombre'],
            'properties' => [
                'nombre'      => ['type' => 'string', 'maxLength' => 50, 'example' => 'Secretaria'],
                'descripcion' => ['type' => 'string', 'maxLength' => 255],
                'estado'      => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO'], 'default' => 'ACTIVO'],
                'permisos'    => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'PermisoId a asignar; reemplaza la asignación anterior.'],
            ],
        ],

        'Permiso' => [
            'type'       => 'object',
            'properties' => [
                'InstitucionEducativaId' => ['type' => 'integer'],
                'PermisoId'              => ['type' => 'integer', 'example' => 4],
                'Codigo'                 => ['type' => 'string', 'example' => 'REGISTRO_DATOS'],
                'Nombre'                 => ['type' => 'string'],
                'Modulo'                 => ['type' => 'string', 'nullable' => true, 'enum' => ['ADMINISTRACION', 'REGISTRO_DATOS', 'CONSULTA_BUSQUEDAS', 'REPORTES_EXPORTACION', null]],
                'Descripcion'            => ['type' => 'string', 'nullable' => true],
                'Estado'                 => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']],
            ],
        ],

        'PermisoEntrada' => [
            'type'     => 'object',
            'required' => ['codigo', 'nombre'],
            'properties' => [
                'codigo'      => ['type' => 'string', 'maxLength' => 50, 'example' => 'REPORTES_EXPORTACION'],
                'nombre'      => ['type' => 'string', 'maxLength' => 100],
                'modulo'      => ['type' => 'string', 'enum' => ['ADMINISTRACION', 'REGISTRO_DATOS', 'CONSULTA_BUSQUEDAS', 'REPORTES_EXPORTACION']],
                'descripcion' => ['type' => 'string', 'maxLength' => 255],
                'estado'      => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO'], 'default' => 'ACTIVO'],
            ],
        ],

        /* ---------- Catálogos ---------- */

        'Finalidad' => [
            'type'       => 'object',
            'properties' => [
                'FinalidadId' => ['type' => 'integer', 'example' => 1],
                'Codigo'      => ['type' => 'string', 'example' => 'MATRICULA'],
                'Nombre'      => ['type' => 'string', 'example' => 'Consentimiento de uso de datos'],
                'Descripcion' => ['type' => 'string', 'nullable' => true],
                'Activo'      => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']],
            ],
        ],

        'FinalidadEntrada' => [
            'type'     => 'object',
            'required' => ['codigo', 'nombre'],
            'properties' => [
                'codigo'      => ['type' => 'string', 'maxLength' => 50],
                'nombre'      => ['type' => 'string', 'maxLength' => 150],
                'descripcion' => ['type' => 'string'],
                'activo'      => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO'], 'default' => 'ACTIVO'],
            ],
        ],

        'TipoDato' => [
            'type'       => 'object',
            'properties' => [
                'TipoDatoId' => ['type' => 'integer', 'example' => 3],
                'Codigo'     => ['type' => 'string', 'example' => 'SALUD'],
                'Nombre'     => ['type' => 'string', 'example' => 'Datos de salud'],
                'Categoria'  => ['type' => 'string', 'nullable' => true],
                'EsSensible' => ['type' => 'string', 'enum' => ['SI', 'NO']],
            ],
        ],

        'TipoDatoEntrada' => [
            'type'     => 'object',
            'required' => ['codigo', 'nombre'],
            'properties' => [
                'codigo'      => ['type' => 'string', 'maxLength' => 50],
                'nombre'      => ['type' => 'string', 'maxLength' => 150],
                'categoria'   => ['type' => 'string', 'maxLength' => 100],
                'es_sensible' => ['type' => 'string', 'enum' => ['SI', 'NO'], 'default' => 'NO'],
            ],
        ],
    ],

    'responses' => [
        'NoAutenticado' => [
            'description' => 'Falta el token o está vencido.',
            'content'     => ['application/json' => [
                'schema'  => ['$ref' => '#/components/schemas/Error'],
                'example' => ['ok' => false, 'error' => 'No autenticado o token expirado.'],
            ]],
        ],
        'Prohibido' => [
            'description' => 'El usuario no tiene el rol o permiso necesario.',
            'content'     => ['application/json' => [
                'schema'  => ['$ref' => '#/components/schemas/Error'],
                'example' => ['ok' => false, 'error' => 'No cuenta con permisos suficientes para acceder a este módulo.'],
            ]],
        ],
        'NoEncontrado' => [
            'description' => 'El registro no existe en la institución activa.',
            'content'     => ['application/json' => [
                'schema'  => ['$ref' => '#/components/schemas/Error'],
                'example' => ['ok' => false, 'error' => 'Registro no encontrado.'],
            ]],
        ],
        'Conflicto' => [
            'description' => 'Choca con un registro existente (código o identificación duplicada).',
            'content'     => ['application/json' => [
                'schema'  => ['$ref' => '#/components/schemas/Error'],
                'example' => ['ok' => false, 'error' => 'Ya existe una persona registrada con esa identificación.'],
            ]],
        ],
        'ErrorValidacion' => [
            'description' => 'Los datos enviados no superaron la validación.',
            'content'     => ['application/json' => [
                'schema'  => ['$ref' => '#/components/schemas/Error'],
                'example' => [
                    'ok'      => false,
                    'error'   => 'Los datos enviados no son válidos.',
                    'errores' => ['Los nombres son obligatorios.', 'El correo electrónico no es válido.'],
                ],
            ]],
        ],
    ],
];

/* --------------------------------------------------------------------------
   Rutas
   -------------------------------------------------------------------------- */

$spec['paths'] = [];

/* ---------- Servicio ---------- */

$spec['paths']['/estado'] = [
    'get' => [
        'tags'        => ['Sesión'],
        'summary'     => 'Estado del servicio',
        'description' => 'Comprobación rápida de que la API responde. No requiere token.',
        'operationId' => 'estadoServicio',
        'security'    => [],
        'responses'   => [
            '200' => $respuesta('Servicio operativo.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'estado' => ['type' => 'string', 'example' => 'operativo'],
                    'hora'   => ['type' => 'string', 'format' => 'date-time'],
                ],
            ])),
        ],
    ],
];

/* ---------- Sesión ---------- */

$spec['paths']['/auth/login'] = [
    'post' => [
        'tags'        => ['Sesión'],
        'summary'     => 'Iniciar sesión y obtener el token',
        'description' => "Valida las credenciales contra la institución indicada y devuelve un token Bearer con 8 horas de vigencia.\n\n**Regla de institución:** una cuenta corriente solo se autentica contra su propia institución; con otra `institucion_id` la respuesta es `401`. Una cuenta con el rol `SuperAdmin` se autentica contra **cualquier institución activa** y recibe el token con ese rol, de modo que abre todas las opciones y trabaja sobre los datos de la institución elegida.\n\nLa respuesta incluye `institucion_propia` y `visita`, útiles para avisar en pantalla cuando se está trabajando en una institución ajena.\n\nUse `GET /instituciones/activas` para conocer los identificadores disponibles.",
        'operationId' => 'login',
        'security'    => [],
        'requestBody' => $cuerpo('LoginEntrada'),
        'responses'   => [
            '200' => $respuesta('Credenciales aceptadas.', ['$ref' => '#/components/schemas/LoginRespuesta']),
            '401' => [
                'description' => 'Credenciales incorrectas, cuenta inactiva o institución no disponible.',
                'content'     => ['application/json' => [
                    'schema'  => ['$ref' => '#/components/schemas/Error'],
                    'example' => ['ok' => false, 'error' => 'Credenciales incorrectas o cuenta inactiva.'],
                ]],
            ],
            '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
        ],
    ],
];

$spec['paths']['/auth/logout'] = [
    'post' => [
        'tags'        => ['Sesión'],
        'summary'     => 'Cerrar sesión',
        'description' => 'Los tokens son autocontenidos y caducan solos; el cliente simplemente descarta el suyo.',
        'operationId' => 'logout',
        'security'    => [],
        'responses'   => [
            '200' => $respuesta('Sesión finalizada.', $sobre([
                'type'       => 'object',
                'properties' => ['mensaje' => ['type' => 'string', 'example' => 'Sesión finalizada.']],
            ])),
        ],
    ],
];

$spec['paths']['/auth/me'] = [
    'get' => [
        'tags'        => ['Sesión'],
        'summary'     => 'Contexto del token vigente',
        'description' => 'Devuelve usuario, institución, roles y permisos asociados al token.',
        'operationId' => 'contextoSesion',
        'responses'   => $erroresComunes([
            '200' => $respuesta('Contexto del usuario autenticado.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'usuario_id'     => ['type' => 'integer'],
                    'username'       => ['type' => 'string'],
                    'institucion_id' => ['type' => 'integer'],
                    'roles'          => ['type' => 'array', 'items' => ['type' => 'string']],
                    'permisos'       => ['type' => 'array', 'items' => ['type' => 'string']],
                    'expira'         => ['type' => 'integer'],
                    'detalle'        => ['type' => 'object', 'nullable' => true],
                ],
            ])),
        ]),
    ],
];

$spec['paths']['/auth/permiso'] = [
    'get' => [
        'tags'        => ['Sesión'],
        'summary'     => 'Verificar un permiso puntual',
        'operationId' => 'verificarPermiso',
        'parameters'  => [[
            'name'     => 'codigo',
            'in'       => 'query',
            'required' => true,
            'schema'   => ['type' => 'string', 'example' => 'REPORTES_EXPORTACION'],
            'description' => 'Código del permiso a verificar.',
        ]],
        'responses' => $erroresComunes([
            '200' => $respuesta('Resultado de la verificación.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'codigo' => ['type' => 'string'],
                    'tiene'  => ['type' => 'boolean'],
                ],
            ])),
            '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
        ]),
    ],
];

/* ---------- Instituciones ---------- */

$spec['paths']['/instituciones/activas'] = [
    'get' => [
        'tags'        => ['Instituciones'],
        'summary'     => 'Instituciones activas (endpoint público)',
        'description' => 'Alimenta el combo de la pantalla de login. No requiere token.',
        'operationId' => 'institucionesActivas',
        'security'    => [],
        'responses'   => [
            '200' => $respuesta('Listado de instituciones activas.', $sobre([
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => ['type' => 'integer', 'example' => 1],
                        'nombre' => ['type' => 'string', 'example' => 'Escuela 1'],
                    ],
                ],
            ])),
        ],
    ],
];

$spec['paths'] += $crud([
    'ruta'       => 'instituciones',
    'tag'        => 'Instituciones',
    'singular'   => 'institución',
    'esquema'    => 'Institucion',
    'entrada'    => 'InstitucionEntrada',
    'clave_id'   => 'id',
    'acceso'     => 'SuperAdmin',
    'busqueda'   => 'Busca por nombre o dirección.',
    'meta_extra' => ['siguiente_id' => ['type' => 'integer', 'description' => 'Identificador sugerido para el alta.']],
]);

/* ---------- Personas (solo lectura: ya no tiene CRUD) ---------- */

/* `persona` es la entidad PADRE de empleados, estudiantes, representantes y
   proveedores, y no tiene mantenimiento propio: no se crea, edita ni da de baja
   por su cuenta. Sus fichas nacen desde esos módulos, desde los enlaces
   públicos o desde la PreCarga Inicial. Por eso aquí solo hay GET. */

$spec['paths']['/personas'] = [
    'get' => [
        'tags' => ['Personas'], 'summary' => 'Listado del padrón de la institución',
        'description' => "Personas registradas en la institución activa. Lo consumen las pantallas que eligen a alguien ya registrado —consentimientos y usuarios— y la subpantalla de búsqueda.\n\n**No hay alta ni edición por esta vía:** los datos personales se capturan en Empleados, Estudiantes o Proveedores, y la ficha se crea o se reutiliza al guardar.\n\n**Acceso:** SuperAdmin, RecursosHumanos o Secretaria",
        'operationId' => 'listarPersonas',
        'parameters' => [
            ['name' => 'q', 'in' => 'query', 'description' => 'Busca por nombres, apellidos, identificación o correo.', 'schema' => ['type' => 'string']],
            ['name' => 'estado', 'in' => 'query', 'description' => 'Filtra por estado.', 'schema' => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']]],
            ['name' => 'excluir', 'in' => 'query', 'description' => 'Omite un PersonaId concreto (por ejemplo, el titular al elegir su representante).', 'schema' => ['type' => 'integer']],
            ['name' => 'sin_usuario', 'in' => 'query', 'description' => 'Con valor 1 devuelve solo personas que aún no tienen cuenta de usuario en la institución activa.', 'schema' => ['type' => 'integer', 'enum' => [0, 1]]],
            ['$ref' => '#/components/parameters/Pagina'],
            ['$ref' => '#/components/parameters/PorPagina'],
        ],
        'responses' => $erroresComunes(['200' => $respuesta('Personas de la institución.', $sobreLista('Persona'))]),
    ],
];

$spec['paths']['/personas/opciones'] = [
    'get' => [
        'tags' => ['Personas'], 'summary' => 'Personas activas para un desplegable',
        'description' => "Pares PersonaId / etiqueta de las personas activas de la institución.",
        'operationId' => 'opcionesPersonas',
        'responses' => $erroresComunes(['200' => $respuesta('Opciones.', $sobre([
            'type'  => 'array',
            'items' => ['type' => 'object', 'properties' => [
                'PersonaId' => ['type' => 'integer'],
                'etiqueta'  => ['type' => 'string', 'example' => 'PEREZ ANA - 0912345678'],
            ]],
        ]))]),
    ],
];

$spec['paths']['/personas/{id}'] = [
    'get' => [
        'tags' => ['Personas'], 'summary' => 'Una persona del padrón',
        'operationId' => 'verPersona',
        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
        'responses' => $erroresComunes([
            '200' => $respuesta('Persona.', $sobre(['$ref' => '#/components/schemas/Persona'])),
            '404' => ['$ref' => '#/components/responses/NoEncontrado'],
        ]),
    ],
];

$spec['paths']['/personas/opciones'] = [
    'get' => [
        'tags'        => ['Personas'],
        'summary'     => 'Personas activas para listas desplegables',
        'description' => "Devuelve `PersonaId` y una etiqueta legible.\n\n**Acceso:** cualquier usuario autenticado.",
        'operationId' => 'opcionesPersonas',
        'responses'   => $erroresComunes([
            '200' => $respuesta('Opciones disponibles.', $sobre([
                'type'  => 'array',
                'items' => ['$ref' => '#/components/schemas/Opcion'],
            ])),
        ]),
    ],
];

$spec['paths']['/personas/{id}/ficha'] = [
    'get' => [
        'tags'        => ['Personas'],
        'summary'     => 'Ficha 360° de una persona',
        'description' => "Datos personales, vínculos institucionales (empleado, estudiante, proveedor, usuario) y consentimientos otorgados.\n\n**Acceso:** cualquier usuario autenticado.",
        'operationId' => 'fichaPersona',
        'parameters'  => [['$ref' => '#/components/parameters/IdRuta']],
        'responses'   => $erroresComunes([
            '200' => $respuesta('Ficha completa.', $sobre(['$ref' => '#/components/schemas/FichaPersona'])),
            '404' => ['$ref' => '#/components/responses/NoEncontrado'],
        ]),
    ],
];

/* ---------- Empleados, estudiantes y proveedores ---------- */

$spec['paths'] += $crud([
    'ruta'     => 'empleados',
    'tag'      => 'Empleados',
    'singular' => 'empleado',
    'esquema'  => 'Empleado',
    'entrada'  => 'EmpleadoEntrada',
    'clave_id' => 'EmpleadoId',
    'acceso'   => 'SuperAdmin o RecursosHumanos',
    'busqueda' => 'Busca por nombres, apellidos o identificación de la persona.',
]);

$spec['paths'] += $crud([
    'ruta'     => 'estudiantes',
    'tag'      => 'Estudiantes',
    'singular' => 'estudiante',
    'esquema'  => 'Estudiante',
    'entrada'  => 'EstudianteEntrada',
    'clave_id' => 'EstudianteId',
    'acceso'   => 'SuperAdmin o Secretaria',
    'busqueda' => 'Busca por nombres, apellidos, código de estudiante o identificación.',
]);

$spec['paths'] += $crud([
    'ruta'     => 'proveedores',
    'tag'      => 'Proveedores',
    'singular' => 'proveedor',
    'esquema'  => 'Proveedor',
    'entrada'  => 'ProveedorEntrada',
    'clave_id' => 'ProveedorId',
    'acceso'   => 'SuperAdmin',
    'busqueda' => 'Busca por razón social o RUC.',
]);

/* ---------- Consentimientos ---------- */

$accesoConsentimientos = 'SuperAdmin, RecursosHumanos o Secretaria — o cualquier usuario con el permiso `REGISTRO_DATOS`';

$spec['paths']['/consentimientos'] = [
    'get' => [
        'tags'        => ['Consentimientos'],
        'summary'     => 'Listar consentimientos',
        'description' => "**Acceso:** " . $accesoConsentimientos,
        'operationId' => 'listarConsentimientos',
        'parameters'  => [
            ['name' => 'q', 'in' => 'query', 'description' => 'Busca por titular o finalidad.', 'schema' => ['type' => 'string']],
            ['name' => 'estado', 'in' => 'query', 'description' => 'ACTIVO = vigentes, INACTIVO = revocados.', 'schema' => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']]],
            ['$ref' => '#/components/parameters/Pagina'],
            ['$ref' => '#/components/parameters/PorPagina'],
        ],
        'responses' => $erroresComunes([
            '200' => $respuesta('Listado paginado.', $sobreLista('Consentimiento')),
        ]),
    ],
    'post' => [
        'tags'        => ['Consentimientos'],
        'summary'     => 'Registrar consentimiento',
        'description' => "Crea el consentimiento, guarda el detalle de tipos de dato autorizados y registra la acción `CREACION` en la bitácora. Todo dentro de una transacción.\n\n**Acceso:** " . $accesoConsentimientos,
        'operationId' => 'crearConsentimiento',
        'requestBody' => $cuerpo('ConsentimientoEntrada'),
        'responses'   => $erroresComunes([
            '201' => $respuestaEscritura('Consentimiento registrado.', 'ConsentimientoId'),
            '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
        ]),
    ],
];

$spec['paths']['/consentimientos/catalogos'] = [
    'get' => [
        'tags'        => ['Consentimientos'],
        'summary'     => 'Catálogos para el formulario',
        'description' => "Personas activas, finalidades activas y tipos de dato, en una sola llamada.\n\n**Acceso:** " . $accesoConsentimientos,
        'operationId' => 'catalogosConsentimiento',
        'responses'   => $erroresComunes([
            '200' => $respuesta('Catálogos.', $sobre(['$ref' => '#/components/schemas/CatalogosConsentimiento'])),
        ]),
    ],
];

$spec['paths']['/consentimientos/{id}'] = [
    'get' => [
        'tags'        => ['Consentimientos'],
        'summary'     => 'Obtener consentimiento',
        'description' => "Incluye el arreglo `tipos_autorizados`.\n\n**Acceso:** " . $accesoConsentimientos,
        'operationId' => 'verConsentimiento',
        'parameters'  => [['$ref' => '#/components/parameters/IdRuta']],
        'responses'   => $erroresComunes([
            '200' => $respuesta('Consentimiento encontrado.', $sobre(['$ref' => '#/components/schemas/Consentimiento'])),
            '404' => ['$ref' => '#/components/responses/NoEncontrado'],
        ]),
    ],
    'put' => [
        'tags'        => ['Consentimientos'],
        'summary'     => 'Actualizar consentimiento',
        'description' => "Reescribe el detalle de tipos autorizados y registra `MODIFICACION` en la bitácora.\n\n**Acceso:** " . $accesoConsentimientos,
        'operationId' => 'actualizarConsentimiento',
        'parameters'  => [['$ref' => '#/components/parameters/IdRuta']],
        'requestBody' => $cuerpo('ConsentimientoEntrada'),
        'responses'   => $erroresComunes([
            '200' => $respuestaEscritura('Consentimiento actualizado.', 'ConsentimientoId'),
            '404' => ['$ref' => '#/components/responses/NoEncontrado'],
            '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
        ]),
    ],
];

foreach ([
    'revocar'   => ['Revocar consentimiento', 'Marca el consentimiento como INACTIVO, sella `FechaRevocacion` y registra `REVOCACION`.', 'INACTIVO'],
    'reactivar' => ['Reactivar consentimiento', 'Devuelve el consentimiento a ACTIVO, limpia `FechaRevocacion` y registra `REACTIVACION`.', 'ACTIVO'],
] as $accion => [$resumen, $descripcion, $estadoFinal]) {
    $spec['paths']['/consentimientos/{id}/' . $accion] = [
        'post' => [
            'tags'        => ['Consentimientos'],
            'summary'     => $resumen,
            'description' => $descripcion . "\n\n**Acceso:** " . $accesoConsentimientos,
            'operationId' => $accion . 'Consentimiento',
            'parameters'  => [['$ref' => '#/components/parameters/IdRuta']],
            'requestBody' => [
                'required' => false,
                'content'  => ['application/json' => ['schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'observacion' => ['type' => 'string', 'description' => 'Texto que queda en la bitácora.'],
                    ],
                ]]],
            ],
            'responses' => $erroresComunes([
                '200' => $respuesta('Operación realizada.', $sobre([
                    'type'       => 'object',
                    'properties' => [
                        'ConsentimientoId' => ['type' => 'integer'],
                        'estado'           => ['type' => 'string', 'example' => $estadoFinal],
                        'mensaje'          => ['type' => 'string'],
                    ],
                ])),
                '404' => ['$ref' => '#/components/responses/NoEncontrado'],
            ]),
        ],
    ];
}

/* ---------- Usuarios, roles y permisos ---------- */

$spec['paths'] += $crud([
    'ruta'       => 'usuarios',
    'tag'        => 'Usuarios',
    'singular'   => 'usuario',
    'esquema'    => 'Usuario',
    'entrada'    => 'UsuarioEntrada',
    'clave_id'   => 'UsuarioId',
    'acceso'     => 'SuperAdmin',
    'busqueda'   => 'Busca por nombre de usuario o nombre de la persona.',
    'meta_extra' => ['roles_disponibles' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Rol']]],
]);

$spec['paths']['/usuarios/buscar'] = [
    'get' => [
        'tags'        => ['Usuarios'],
        'summary'     => 'Búsqueda de usuarios para la subpantalla de selección',
        'description' => "Listado paginado y reducido de los usuarios de la institución activa. Alimenta el cuadro modal de la bitácora de auditoría; no devuelve contraseñas ni datos de contacto.\n\n**Acceso:** SuperAdmin, permiso `SEG_USUARIOS` o permiso `REP_AUDITORIA`",
        'operationId' => 'buscarUsuarios',
        'parameters'  => [
            [
                'name'        => 'q',
                'in'          => 'query',
                'description' => 'Busca por nombre de usuario, nombres, apellidos o identificación.',
                'schema'      => ['type' => 'string'],
            ],
            ['$ref' => '#/components/parameters/Pagina'],
            ['$ref' => '#/components/parameters/PorPagina'],
        ],
        'responses' => $erroresComunes([
            '200' => $respuesta('Usuarios encontrados.', $sobreLista('UsuarioBusqueda')),
        ]),
    ],
];

$spec['paths']['/usuarios/personas-disponibles'] = [
    'get' => [
        'tags'        => ['Usuarios'],
        'summary'     => 'Personas sin cuenta de usuario',
        'description' => "Personas activas que aún no tienen usuario en la institución activa.\n\n**Acceso:** SuperAdmin",
        'operationId' => 'personasSinUsuario',
        'responses'   => $erroresComunes([
            '200' => $respuesta('Personas disponibles.', $sobre([
                'type'  => 'array',
                'items' => ['$ref' => '#/components/schemas/Opcion'],
            ])),
        ]),
    ],
];

$spec['paths'] += $crud([
    'ruta'       => 'roles',
    'tag'        => 'Roles',
    'singular'   => 'rol',
    'esquema'    => 'Rol',
    'entrada'    => 'RolEntrada',
    'clave_id'   => 'RolId',
    'acceso'     => 'SuperAdmin',
    'busqueda'   => 'Busca por nombre del rol.',
    'meta_extra' => ['permisos_disponibles' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Permiso']]],
]);

$spec['paths'] += $crud([
    'ruta'       => 'permisos',
    'tag'        => 'Permisos',
    'singular'   => 'permiso',
    'esquema'    => 'Permiso',
    'entrada'    => 'PermisoEntrada',
    'clave_id'   => 'PermisoId',
    'acceso'     => 'SuperAdmin',
    'busqueda'   => 'Busca por código o nombre.',
    'meta_extra' => ['modulos' => ['type' => 'array', 'items' => ['type' => 'string']]],
]);

/* ---------- Catálogos ---------- */

$spec['paths'] += $crud([
    'ruta'     => 'finalidades',
    'tag'      => 'Catálogos',
    'singular' => 'finalidad',
    'esquema'  => 'Finalidad',
    'entrada'  => 'FinalidadEntrada',
    'clave_id' => 'FinalidadId',
    'acceso'   => 'lectura: cualquier usuario autenticado · escritura: SuperAdmin',
    'busqueda' => 'Busca por nombre o código.',
    'filtros'  => [[
        'name'        => 'solo_activas',
        'in'          => 'query',
        'description' => 'Con valor 1 devuelve únicamente las finalidades activas.',
        'schema'      => ['type' => 'integer', 'enum' => [0, 1]],
    ]],
]);

$spec['paths'] += $crud([
    'ruta'       => 'tipos-dato',
    'tag'        => 'Catálogos',
    'singular'   => 'tipo de dato',
    'esquema'    => 'TipoDato',
    'entrada'    => 'TipoDatoEntrada',
    'clave_id'   => 'TipoDatoId',
    'acceso'     => 'lectura: cualquier usuario autenticado · escritura: SuperAdmin',
    'busqueda'   => 'Busca por nombre, código o categoría.',
    'con_estado' => false,
    'filtros'    => [[
        'name'        => 'solo_sensibles',
        'in'          => 'query',
        'description' => 'Con valor 1 devuelve únicamente los tipos marcados como sensibles.',
        'schema'      => ['type' => 'integer', 'enum' => [0, 1]],
    ]],
]);

$spec['paths']['/tipos-dato/{id}']['delete'] = [
    'tags'        => ['Catálogos'],
    'summary'     => 'Eliminar tipo de dato',
    'description' => "Falla con 409 si el tipo de dato ya se usa en consentimientos.\n\n**Acceso:** SuperAdmin",
    'operationId' => 'eliminarTipoDato',
    'parameters'  => [['$ref' => '#/components/parameters/IdRuta']],
    'responses'   => $erroresComunes([
        '200' => $respuestaEscritura('Tipo de dato eliminado.', 'TipoDatoId'),
        '404' => ['$ref' => '#/components/responses/NoEncontrado'],
        '409' => ['$ref' => '#/components/responses/Conflicto'],
    ]),
];

/* ---------- Consultas ---------- */

$spec['paths']['/consultas/buscar-persona'] = [
    'get' => [
        'tags'        => ['Consultas'],
        'summary'     => 'Buscar persona (vista 360°)',
        'description' => "Devuelve las coincidencias y, si hay una sola o se indica `id`, también su ficha completa.\n\n**Acceso:** cualquier usuario autenticado.",
        'operationId' => 'buscarPersona',
        'parameters'  => [
            ['name' => 'q', 'in' => 'query', 'description' => 'Nombre, apellido, identificación o correo.', 'schema' => ['type' => 'string']],
            ['name' => 'id', 'in' => 'query', 'description' => 'PersonaId a detallar.', 'schema' => ['type' => 'integer']],
        ],
        'responses' => $erroresComunes([
            '200' => $respuesta('Resultados de la búsqueda.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'resultados' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Persona']],
                    'persona_id' => ['type' => 'integer'],
                    'ficha'      => ['allOf' => [['$ref' => '#/components/schemas/FichaPersona']], 'nullable' => true],
                ],
            ])),
        ]),
    ],
];

$spec['paths']['/consultas/historial'] = [
    'get' => [
        'tags'        => ['Consultas'],
        'summary'     => 'Bitácora de auditoría de consentimientos',
        'description' => "Movimientos de creación, modificación, revocación y reactivación.\n\n**Acceso:** cualquier usuario autenticado.",
        'operationId' => 'historialConsentimientos',
        'parameters'  => [
            ['name' => 'consentimiento_id', 'in' => 'query', 'description' => 'Filtra por un consentimiento concreto.', 'schema' => ['type' => 'integer']],
            ['name' => 'desde', 'in' => 'query', 'description' => 'Fecha inicial (YYYY-MM-DD).', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'hasta', 'in' => 'query', 'description' => 'Fecha final (YYYY-MM-DD).', 'schema' => ['type' => 'string', 'format' => 'date']],
            ['name' => 'q', 'in' => 'query', 'description' => 'Busca por titular o usuario que ejecutó la acción.', 'schema' => ['type' => 'string']],
            ['$ref' => '#/components/parameters/Pagina'],
            ['$ref' => '#/components/parameters/PorPagina'],
        ],
        'responses' => $erroresComunes([
            '200' => $respuesta('Movimientos encontrados.', $sobreLista('Historial')),
        ]),
    ],
];

$spec['paths']['/consultas/consentimientos-vigentes'] = [
    'get' => [
        'tags'        => ['Consultas'],
        'summary'     => 'Consentimientos vigentes / revocados',
        'description' => "Permite filtrar por finalidad y por tipo de dato autorizado.\n\n**Acceso:** cualquier usuario autenticado.",
        'operationId' => 'consentimientosVigentes',
        'parameters'  => [
            ['name' => 'estado', 'in' => 'query', 'description' => 'ACTIVO (por defecto), INACTIVO o vacío para todos.', 'schema' => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO', '']]],
            ['name' => 'finalidad_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
            ['name' => 'tipo_dato_id', 'in' => 'query', 'description' => 'Solo consentimientos que autorizan ese tipo de dato.', 'schema' => ['type' => 'integer']],
            ['name' => 'q', 'in' => 'query', 'description' => 'Nombre o apellido del titular.', 'schema' => ['type' => 'string']],
            ['$ref' => '#/components/parameters/Pagina'],
            ['$ref' => '#/components/parameters/PorPagina'],
        ],
        'responses' => $erroresComunes([
            '200' => $respuesta('Consentimientos encontrados.', $sobreLista('Consentimiento', [
                'finalidades' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Finalidad']],
                'tipos_dato'  => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TipoDato']],
            ])),
        ]),
    ],
];

/* ---------- Reportes ---------- */

$spec['paths']['/reportes/dashboard'] = [
    'get' => [
        'tags'        => ['Reportes'],
        'summary'     => 'Indicadores del panel principal',
        'description' => "KPIs de la institución activa más la actividad reciente.\n\n**Acceso:** cualquier usuario autenticado.",
        'operationId' => 'dashboard',
        'responses'   => $erroresComunes([
            '200' => $respuesta('Indicadores y actividad reciente.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'kpis' => [
                        'type'       => 'object',
                        'properties' => [
                            'estudiantes'               => ['type' => 'integer'],
                            'empleados'                 => ['type' => 'integer'],
                            'consentimientos_activos'   => ['type' => 'integer'],
                            'consentimientos_revocados' => ['type' => 'integer'],
                            'proveedores'               => ['type' => 'integer'],
                            'usuarios'                  => ['type' => 'integer'],
                        ],
                    ],
                    'ultimos_consentimientos' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Consentimiento']],
                    'ultimo_historial'        => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Historial']],
                ],
            ])),
        ]),
    ],
];

$accesoReportes = 'SuperAdmin o permiso `REPORTES_EXPORTACION`';

$spec['paths']['/reportes/consentimientos'] = [
    'get' => [
        'tags'        => ['Reportes'],
        'summary'     => 'Consentimientos por finalidad, medio y mes',
        'description' => "**Acceso:** " . $accesoReportes,
        'operationId' => 'reporteConsentimientos',
        'responses'   => $erroresComunes([
            '200' => $respuesta('Datos del reporte.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'por_finalidad' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'Nombre'    => ['type' => 'string'],
                                'total'     => ['type' => 'integer'],
                                'activos'   => ['type' => 'integer'],
                                'revocados' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'por_medio' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'medio' => ['type' => 'string'],
                                'total' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'por_mes' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'periodo' => ['type' => 'string', 'example' => '2026-08'],
                                'total'   => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'totales' => [
                        'type'       => 'object',
                        'properties' => [
                            't' => ['type' => 'integer', 'description' => 'Total'],
                            'a' => ['type' => 'integer', 'description' => 'Vigentes'],
                            'r' => ['type' => 'integer', 'description' => 'Revocados'],
                        ],
                    ],
                    'maximos' => [
                        'type'        => 'object',
                        'description' => 'Valores máximos para dimensionar las barras del reporte.',
                        'properties'  => [
                            'finalidad' => ['type' => 'integer'],
                            'medio'     => ['type' => 'integer'],
                            'mes'       => ['type' => 'integer'],
                        ],
                    ],
                ],
            ])),
        ]),
    ],
];

$spec['paths']['/reportes/datos-sensibles'] = [
    'get' => [
        'tags'        => ['Reportes'],
        'summary'     => 'Tratamiento de datos sensibles',
        'description' => "Resumen por tipo de dato sensible y detalle de titulares con autorización vigente (máximo 200 filas).\n\n**Acceso:** " . $accesoReportes,
        'operationId' => 'reporteDatosSensibles',
        'parameters'  => [[
            'name'        => 'tipo_dato_id',
            'in'          => 'query',
            'description' => 'Limita el detalle a un tipo de dato sensible.',
            'schema'      => ['type' => 'integer'],
        ]],
        'responses' => $erroresComunes([
            '200' => $respuesta('Datos del reporte.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'resumen' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'TipoDatoId'           => ['type' => 'integer'],
                                'Nombre'               => ['type' => 'string'],
                                'Categoria'            => ['type' => 'string', 'nullable' => true],
                                'autorizados_vigentes' => ['type' => 'integer'],
                                'autorizados_total'    => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'detalle' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'PersonaId'           => ['type' => 'integer'],
                                'Nombres'             => ['type' => 'string'],
                                'Apellidos'           => ['type' => 'string'],
                                'TipoDatoNombre'      => ['type' => 'string'],
                                'FinalidadNombre'     => ['type' => 'string', 'nullable' => true],
                                'FechaConsentimiento' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'tipos_sensibles' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TipoDato']],
                    'tipo_dato_id'    => ['type' => 'integer'],
                ],
            ])),
        ]),
    ],
];

$spec['paths']['/reportes/titulares'] = [
    'get' => [
        'tags'        => ['Reportes'],
        'summary'     => 'Detalle de titulares con consentimiento otorgado o revocado',
        'description' => "Listado paginado para el reporte de titulares: fecha de consentimiento y de revocación, titular (razón social si es proveedor), tipo de vínculo, estado e IP de origen.\n\nEl bloque `meta.totales` trae los conteos del conjunto filtrado completo, no solo de la página.\n\n**Acceso:** " . $accesoReportes,
        'operationId' => 'reporteTitulares',
        'parameters'  => [
            [
                'name'        => 'estado',
                'in'          => 'query',
                'description' => 'ACTIVO = consentimiento vigente, INACTIVO = revocado. Vacío = todos.',
                'schema'      => ['type' => 'string', 'enum' => ['', 'ACTIVO', 'INACTIVO']],
            ],
            [
                'name'        => 'tipo',
                'in'          => 'query',
                'description' => 'Tipo de persona dentro de la institución.',
                'schema'      => ['type' => 'string', 'enum' => ['todos', 'estudiantes', 'empleados', 'proveedores'], 'default' => 'todos'],
            ],
            [
                'name'        => 'desde',
                'in'          => 'query',
                'description' => 'Fecha inicial del consentimiento (YYYY-MM-DD). Opcional.',
                'schema'      => ['type' => 'string', 'format' => 'date'],
            ],
            [
                'name'        => 'hasta',
                'in'          => 'query',
                'description' => 'Fecha final del consentimiento (YYYY-MM-DD). Opcional.',
                'schema'      => ['type' => 'string', 'format' => 'date'],
            ],
            [
                'name'        => 'q',
                'in'          => 'query',
                'description' => 'Busca por nombres, apellidos o identificación del titular.',
                'schema'      => ['type' => 'string'],
            ],
            ['$ref' => '#/components/parameters/Pagina'],
            ['$ref' => '#/components/parameters/PorPagina'],
        ],
        'responses' => $erroresComunes([
            '200' => $respuesta('Detalle de titulares.', $sobreLista('TitularConsentimiento', [
                'totales' => [
                    'type'       => 'object',
                    'properties' => [
                        'registros'   => ['type' => 'integer'],
                        'consentidos' => ['type' => 'integer'],
                        'revocados'   => ['type' => 'integer'],
                    ],
                ],
                'filtros' => ['type' => 'object', 'description' => 'Filtros efectivamente aplicados.'],
            ])),
        ]),
    ],
];

$spec['paths']['/reportes/auditoria'] = [
    'get' => [
        'tags'        => ['Reportes'],
        'summary'     => 'Bitácora de auditoría de la base de datos',
        'description' => "Movimientos registrados automáticamente por la API en la institución del token: alta, cambio o baja, con el usuario, la fecha y hora, la IP de origen, la tabla y el registro afectados, y **qué campo** se tocó.\n\nSe genera una fila por cada campo que cambió. La bitácora anota el QUÉ, no el DATO: **no guarda el valor anterior ni el nuevo**, de modo que no se convierte en una segunda copia de los datos personales que el sistema custodia.\n\nEl bloque `meta.totales` trae los conteos del conjunto filtrado completo, y `meta.tablas` la lista de tablas presentes en la bitácora.\n\nRequiere que se haya ejecutado el script `BaseDatos/01_DDL_estructura.sql`; si la tabla no existe se devuelve `503`.\n\n**Acceso:** SuperAdmin o permiso `REP_AUDITORIA`",
        'operationId' => 'reporteAuditoria',
        'parameters'  => [
            [
                'name'        => 'desde',
                'in'          => 'query',
                'description' => 'Fecha inicial del movimiento (YYYY-MM-DD). Opcional.',
                'schema'      => ['type' => 'string', 'format' => 'date'],
            ],
            [
                'name'        => 'hasta',
                'in'          => 'query',
                'description' => 'Fecha final del movimiento (YYYY-MM-DD). Opcional.',
                'schema'      => ['type' => 'string', 'format' => 'date'],
            ],
            [
                'name'        => 'username',
                'in'          => 'query',
                'description' => 'Nombre de usuario exacto. Opcional; vacío = todos los usuarios.',
                'schema'      => ['type' => 'string'],
            ],
            [
                'name'        => 'tabla',
                'in'          => 'query',
                'description' => 'Tabla afectada. Opcional.',
                'schema'      => ['type' => 'string', 'example' => 'persona'],
            ],
            [
                'name'        => 'operacion',
                'in'          => 'query',
                'description' => 'Tipo de movimiento. Vacío = todos.',
                'schema'      => ['type' => 'string', 'enum' => ['', 'INSERT', 'UPDATE', 'DELETE']],
            ],
            [
                'name'        => 'q',
                'in'          => 'query',
                'description' => 'Busca por el nombre del campo afectado o por el identificador del registro.',
                'schema'      => ['type' => 'string'],
            ],
            ['$ref' => '#/components/parameters/Pagina'],
            ['$ref' => '#/components/parameters/PorPagina'],
        ],
        'responses' => $erroresComunes([
            '200' => $respuesta('Movimientos registrados.', $sobreLista('MovimientoAuditoria', [
                'totales' => [
                    'type'       => 'object',
                    'properties' => [
                        'registros' => ['type' => 'integer'],
                        'altas'     => ['type' => 'integer'],
                        'cambios'   => ['type' => 'integer'],
                        'bajas'     => ['type' => 'integer'],
                        'usuarios'  => ['type' => 'integer', 'description' => 'Usuarios distintos en el conjunto filtrado.'],
                    ],
                ],
                'tablas'  => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Tablas presentes en la bitácora de la institución.',
                ],
                'filtros' => ['type' => 'object', 'description' => 'Filtros efectivamente aplicados.'],
            ])),
            '503' => $respuesta('La bitácora todavía no está instalada (falta ejecutar 01_DDL_estructura.sql).', ['$ref' => '#/components/schemas/Error']),
        ]),
    ],
];

$spec['paths']['/reportes/exportar'] = [
    'get' => [
        'tags'        => ['Reportes'],
        'summary'     => 'Datos para exportación (CSV)',
        'description' => "Devuelve encabezados y filas listos para construir un CSV. Sin el parámetro `entidad` devuelve el catálogo de exportaciones disponibles.\n\n**Acceso:** " . $accesoReportes,
        'operationId' => 'exportarDatos',
        'parameters'  => [[
            'name'        => 'entidad',
            'in'          => 'query',
            'description' => 'Conjunto de datos a exportar.',
            'schema'      => [
                'type' => 'string',
                'enum' => ['personas', 'empleados', 'estudiantes', 'proveedores', 'consentimientos', 'historial'],
            ],
        ]],
        'responses' => $erroresComunes([
            '200' => $respuesta('Filas listas para exportar.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'entidad'     => ['type' => 'string'],
                    'titulo'      => ['type' => 'string'],
                    'encabezados' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'filas'       => [
                        'type'  => 'array',
                        'items' => ['type' => 'array', 'items' => ['type' => 'string', 'nullable' => true]],
                    ],
                    'total'       => ['type' => 'integer'],
                ],
            ])),
            '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
        ]),
    ],
];

/* ---------- Instalación ---------- */

$spec['paths']['/consentimiento-publico/inicio'] = [
    'get' => [
        'tags' => ['Consentimiento Público'], 'summary' => 'Apertura de un enlace de consentimiento',
        'description' => "**Ruta pública: no requiere token.** La consume `consentimiento.php`, la pantalla de autoservicio que abren los tres enlaces que difunde la institución.\n\nDevuelve el nombre de la institución, qué documento se pide en el primer paso (cédula o RUC, según el tipo) y el disclaimer vigente de ese tipo de persona.",
        'operationId' => 'inicioConsentimientoPublico',
        'security' => [],
        'parameters' => [
            ['name' => 'tipo', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['ESTUDIANTE', 'EMPLEADO', 'PROVEEDOR']]],
            ['name' => 'inst', 'in' => 'query', 'required' => true, 'description' => 'Institución educativa del enlace.', 'schema' => ['type' => 'integer']],
        ],
        'responses' => [
            '200' => $respuesta('Contexto del enlace.', $sobre(['type' => 'object'])),
            '404' => $respuesta('Enlace o institución no válidos.', ['$ref' => '#/components/schemas/Error']),
        ],
    ],
];

$spec['paths']['/consentimiento-publico/identificar'] = [
    'post' => [
        'tags' => ['Consentimiento Público'], 'summary' => 'Buscar a la persona por su documento',
        'description' => "**Ruta pública: no requiere token.**\n\nSegundo paso del recorrido: busca la cédula —o el RUC, si es proveedor— en la tabla que corresponde al tipo, dentro de la institución del enlace.\n\nSi existe devuelve sus datos y el estado de su consentimiento; si no, `existe` viene en false y la pantalla pide los datos para el alta. `puede_revocar` viene en false cuando la persona ya tiene el consentimiento otorgado: en ese caso debe escribir a la institución.",
        'operationId' => 'identificarConsentimientoPublico',
        'security' => [],
        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
            'type' => 'object', 'required' => ['tipo', 'inst', 'identificacion'],
            'properties' => [
                'tipo'           => ['type' => 'string', 'enum' => ['ESTUDIANTE', 'EMPLEADO', 'PROVEEDOR']],
                'inst'           => ['type' => 'integer'],
                'identificacion' => ['type' => 'string', 'description' => 'Cédula de 10 dígitos, o RUC de 10 a 13.'],
            ],
        ]]]],
        'responses' => [
            '200' => $respuesta('Resultado de la búsqueda.', $sobre(['type' => 'object'])),
            '404' => $respuesta('Enlace o institución no válidos.', ['$ref' => '#/components/schemas/Error']),
            '422' => $respuesta('El documento no tiene el formato esperado.', ['$ref' => '#/components/schemas/Error']),
        ],
    ],
];

$spec['paths']['/consentimiento-publico/registrar'] = [
    'post' => [
        'tags' => ['Consentimiento Público'], 'summary' => 'Registrar la decisión del titular',
        'description' => "**Ruta pública: no requiere token.**\n\nTercer paso. Si la persona no existía, la da de alta junto con su vínculo institucional —en estudiantes crea también al representante— a partir del bloque `datos`.\n\nLuego registra la decisión en `consentimiento` (creándola o actualizándola), marca el detalle por tipo de dato, deja constancia en `consentimientohistorial` con la IP y `UsuarioId` nulo, y envía el correo de confirmación. En estudiantes ese correo va al representante e indica de qué representado se trata.\n\nResponde `409` si se intenta revocar un consentimiento ya otorgado: esa vía debe tramitarse por correo con la institución.",
        'operationId' => 'registrarConsentimientoPublico',
        'security' => [],
        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
            'type' => 'object', 'required' => ['tipo', 'inst', 'identificacion', 'decision'],
            'properties' => [
                'tipo'           => ['type' => 'string', 'enum' => ['ESTUDIANTE', 'EMPLEADO', 'PROVEEDOR']],
                'inst'           => ['type' => 'integer'],
                'identificacion' => ['type' => 'string'],
                'decision'       => ['type' => 'string', 'enum' => ['OTORGA', 'REVOCA']],
                'datos'          => [
                    'type'        => 'object',
                    'description' => 'Solo cuando la persona no existe: nombres, apellidos, email, telefono y, según el tipo, razon_social, codigo_estudiante y el objeto representante.',
                ],
                'pase' => [
                    'type' => 'string',
                    'description' => 'Solo si se llega desde un enlace CON VERIFICACIÓN: pase firmado devuelto por /verificacion-publica/validar-codigo. Si viene y no es válido, la petición se rechaza con 409.',
                ],
            ],
        ]]]],
        'responses' => [
            '200' => $respuesta('Decisión registrada.', $sobre(['type' => 'object'])),
            '404' => $respuesta('Enlace o institución no válidos.', ['$ref' => '#/components/schemas/Error']),
            '409' => $respuesta('Revocatoria no permitida en línea, o falta configurar la finalidad.', ['$ref' => '#/components/schemas/Error']),
            '422' => $respuesta('Datos incompletos o no válidos.', ['$ref' => '#/components/schemas/Error']),
        ],
    ],
];

$spec['paths']['/correo/configuracion'] = [
    'get' => [
        'tags' => ['Correo'], 'summary' => 'Configuración del servidor de correo',
        'description' => "Datos SMTP de la institución activa. La contraseña nunca se devuelve: solo se informa si hay una guardada (`clave_definida`).\n\n**Acceso:** SuperAdmin o permiso `ADM_CORREO`",
        'operationId' => 'verConfiguracionCorreo',
        'responses' => $erroresComunes(['200' => $respuesta('Configuración vigente.', $sobre(['type' => 'object']))]),
    ],
    'put' => [
        'tags' => ['Correo'], 'summary' => 'Guardar la configuración de correo',
        'description' => "Guarda servidor, puerto, seguridad, credenciales y remitente. Si `clave` viene vacía se conserva la guardada. Con `activo` en false el sistema envía con mail() de PHP.\n\n**Acceso:** SuperAdmin o permiso `ADM_CORREO`",
        'operationId' => 'guardarConfiguracionCorreo',
        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
            'type' => 'object',
            'properties' => [
                'activo'           => ['type' => 'boolean'],
                'servidor'         => ['type' => 'string', 'example' => 'smtp.gmail.com'],
                'puerto'           => ['type' => 'integer', 'example' => 587],
                'seguridad'        => ['type' => 'string', 'enum' => ['NINGUNA', 'TLS', 'SSL']],
                'usuario'          => ['type' => 'string'],
                'clave'            => ['type' => 'string', 'description' => 'Vacío = conservar la guardada.'],
                'remitente_correo' => ['type' => 'string', 'format' => 'email'],
                'remitente_nombre' => ['type' => 'string'],
            ],
        ]]]],
        'responses' => $erroresComunes(['200' => $respuesta('Configuración guardada.', $sobre(['type' => 'object']))]),
    ],
];

$spec['paths']['/correo/probar'] = [
    'post' => [
        'tags' => ['Correo'], 'summary' => 'Enviar un mensaje de prueba',
        'description' => "Envía un correo de prueba con la configuración guardada e informa si salió por SMTP o por mail().\n\n**Acceso:** SuperAdmin o permiso `ADM_CORREO`",
        'operationId' => 'probarCorreo',
        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
            'type' => 'object', 'required' => ['correo'],
            'properties' => ['correo' => ['type' => 'string', 'format' => 'email']],
        ]]]],
        'responses' => $erroresComunes([
            '200' => $respuesta('Mensaje enviado.', $sobre(['type' => 'object'])),
            '502' => $respuesta('El servidor de correo rechazó el mensaje.', ['$ref' => '#/components/schemas/Error']),
        ]),
    ],
];

$spec['paths']['/disclaimers'] = [
    'get' => [
        'tags' => ['Disclaimers'], 'summary' => 'Listado de disclaimers',
        'description' => "Textos de política de la institución activa. `meta.vigentes` indica cuál está ACTIVO para cada tipo de persona.\n\n**Acceso:** SuperAdmin o permiso `ADM_DISCLAIMERS`",
        'operationId' => 'listarDisclaimers',
        'parameters' => [
            ['name' => 'tipo', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['ESTUDIANTE', 'EMPLEADO', 'PROVEEDOR']]],
            ['name' => 'estado', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['ACTIVO', 'INACTIVO']]],
            ['$ref' => '#/components/parameters/Pagina'],
            ['$ref' => '#/components/parameters/PorPagina'],
        ],
        'responses' => $erroresComunes(['200' => $respuesta('Disclaimers.', $sobreLista('Disclaimer'))]),
    ],
    'post' => [
        'tags' => ['Disclaimers'], 'summary' => 'Registrar un disclaimer',
        'description' => "Crea una versión nueva. El HTML del texto se depura antes de guardarse: solo sobreviven párrafos, negrita, cursiva, subrayado, títulos, listas, tablas y enlaces seguros.\n\nCon `estado` en ACTIVO queda vigente y los demás de ese tipo pasan a INACTIVO.\n\n**Acceso:** SuperAdmin o permiso `ADM_DISCLAIMERS`",
        'operationId' => 'crearDisclaimer',
        'requestBody' => $cuerpo('DisclaimerEntrada'),
        'responses' => $erroresComunes(['201' => $respuestaEscritura('Disclaimer registrado.', 'DisclaimerId')]),
    ],
];

$spec['paths']['/disclaimers/{id}'] = [
    'get' => [
        'tags' => ['Disclaimers'], 'summary' => 'Un disclaimer con su texto completo',
        'operationId' => 'verDisclaimer',
        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
        'responses' => $erroresComunes([
            '200' => $respuesta('Disclaimer.', $sobre(['$ref' => '#/components/schemas/Disclaimer'])),
            '404' => ['$ref' => '#/components/responses/NoEncontrado'],
        ]),
    ],
    'put' => [
        'tags' => ['Disclaimers'], 'summary' => 'Actualizar un disclaimer',
        'operationId' => 'actualizarDisclaimer',
        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
        'requestBody' => $cuerpo('DisclaimerEntrada'),
        'responses' => $erroresComunes([
            '200' => $respuestaEscritura('Disclaimer actualizado.', 'DisclaimerId'),
            '404' => ['$ref' => '#/components/responses/NoEncontrado'],
        ]),
    ],
    'delete' => [
        'tags' => ['Disclaimers'], 'summary' => 'Eliminar un disclaimer',
        'description' => "No se puede eliminar el que está vigente: active otro para ese tipo de persona primero.",
        'operationId' => 'eliminarDisclaimer',
        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
        'responses' => $erroresComunes([
            '200' => $respuestaEscritura('Disclaimer eliminado.', 'DisclaimerId'),
            '409' => $respuesta('Es el disclaimer vigente.', ['$ref' => '#/components/schemas/Error']),
        ]),
    ],
];

$spec['paths']['/disclaimers/{id}/activar'] = [
    'patch' => [
        'tags' => ['Disclaimers'], 'summary' => 'Dejarlo vigente para su tipo de persona',
        'description' => "Marca este disclaimer como ACTIVO y pasa a INACTIVO los demás del mismo tipo.\n\n**Acceso:** SuperAdmin o permiso `ADM_DISCLAIMERS`",
        'operationId' => 'activarDisclaimer',
        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
        'responses' => $erroresComunes(['200' => $respuestaEscritura('Disclaimer vigente.', 'DisclaimerId')]),
    ],
];

$spec['paths']['/setup/admin'] = [
    'post' => [
        'tags'        => ['Instalación'],
        'summary'     => 'Crear el primer usuario SuperAdmin',
        'description' => 'Solo funciona mientras la institución no tenga ningún usuario. Después queda cerrado permanentemente.',
        'operationId' => 'crearAdministrador',
        'security'    => [],
        'requestBody' => [
            'required' => false,
            'content'  => ['application/json' => ['schema' => [
                'type'       => 'object',
                'properties' => [
                    'institucion_id' => ['type' => 'integer', 'default' => 1],
                    'username'       => ['type' => 'string', 'default' => 'admin'],
                    'password'       => ['type' => 'string', 'format' => 'password', 'default' => 'admin123'],
                    'rol_id'         => ['type' => 'integer', 'default' => 1],
                    'nombres'        => ['type' => 'string', 'default' => 'Admin'],
                    'apellidos'      => ['type' => 'string', 'default' => 'Sistema'],
                    'identificacion' => ['type' => 'string'],
                    'email'          => ['type' => 'string', 'format' => 'email'],
                ],
            ]]],
        ],
        'responses' => [
            '201' => $respuesta('Administrador creado.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'PersonaId'    => ['type' => 'integer'],
                    'UsuarioId'    => ['type' => 'integer'],
                    'username'     => ['type' => 'string'],
                    'rol_asignado' => ['type' => 'boolean'],
                    'mensaje'      => ['type' => 'string'],
                ],
            ])),
            '409' => [
                'description' => 'La institución ya tiene usuarios registrados.',
                'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ],
            '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
        ],
    ],
];


/* --------------------------------------------------------------------------
   PreCarga inicial (solo SuperAdmin)
   -------------------------------------------------------------------------- */

$precargaEntrada = [
    'required'   => ['archivo_base64'],
    'type'       => 'object',
    'properties' => [
        'nombre_archivo' => [
            'type'        => 'string',
            'description' => 'Nombre original del archivo, solo para mostrarlo y registrarlo en la bitácora.',
            'example'     => 'precarga_inicial_rea.xlsx',
        ],
        'archivo_base64' => [
            'type'        => 'string',
            'format'      => 'byte',
            'description' => 'Contenido del .xlsx codificado en base64. Máximo 8 MB una vez decodificado.',
        ],
    ],
];

$spec['paths']['/precarga/previsualizar'] = [
    'post' => [
        'tags' => ['PreCarga'], 'summary' => 'Validar la plantilla sin tocar la base',
        'description' => "Lee y valida el archivo Excel **sin modificar ningún dato**. Devuelve los conteos por hoja, los errores encontrados con su hoja y fila, las advertencias y el inventario de lo que se eliminaría si la carga se confirma.\n\nLas filas de ejemplo de la plantilla (las que empiezan con «(EJEMPLO») se ignoran solas.\n\nLa plantilla no pide el estado: **todo lo que se carga entra ACTIVO**. Si el archivo trae una columna `Estado` de una plantilla anterior, se ignora y se avisa en las advertencias.\n\n**Acceso:** solo el rol SuperAdmin.",
        'operationId' => 'previsualizarPreCarga',
        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $precargaEntrada]]],
        'responses' => $erroresComunes([
            '200' => $respuesta('Resultado de la validación.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'archivo'        => ['type' => 'string'],
                    'resumen'        => [
                        'type'       => 'object',
                        'properties' => [
                            'empleados'      => ['type' => 'integer'],
                            'estudiantes'    => ['type' => 'integer'],
                            'representantes' => ['type' => 'integer'],
                            'proveedores'    => ['type' => 'integer'],
                            'personas'       => ['type' => 'integer', 'description' => 'Personas distintas, ya unificadas entre hojas.'],
                            'total'          => ['type' => 'integer'],
                        ],
                    ],
                    'errores'        => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['Hoja Empleados, fila 5: faltan los nombres o los apellidos.']],
                    'advertencias'   => ['type' => 'array', 'items' => ['type' => 'string']],
                    'puede_procesar' => ['type' => 'boolean', 'description' => 'true solo si no hay errores y hay al menos una fila.'],
                    'se_eliminara'   => [
                        'type'       => 'object',
                        'description' => 'Registros que hoy tiene la institución y que la carga eliminaría.',
                        'properties' => [
                            'empleados'            => ['type' => 'integer'],
                            'estudiantes'          => ['type' => 'integer'],
                            'proveedores'          => ['type' => 'integer'],
                            'consentimientos'      => ['type' => 'integer'],
                            'historial'            => ['type' => 'integer'],
                            'personas'             => ['type' => 'integer'],
                            'personas_con_usuario' => ['type' => 'integer', 'description' => 'Personas que NO se eliminan porque tienen cuenta de usuario.'],
                        ],
                    ],
                ],
            ])),
            '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
        ]),
    ],
];

$spec['paths']['/precarga/procesar'] = [
    'post' => [
        'tags' => ['PreCarga'], 'summary' => 'Encerar la institución y cargar la plantilla',
        'description' => "**Operación destructiva e irreversible.** Elimina los datos de la institución del token y los reemplaza por los del archivo, todo dentro de una sola transacción: o entra completo, o no entra nada.\n\nRepite la validación de `/precarga/previsualizar` y solo actúa si el archivo está limpio y el cuerpo trae `confirmacion` con el texto exacto `ENCERAR Y CARGAR`.\n\n**Se elimina** (solo de la institución activa): consentimientos con su historial y detalle, empleados, estudiantes, proveedores y las personas que queden sin ningún vínculo.\n\n**No se toca:** usuarios, roles, permisos, catálogos, disclaimers, configuración de correo, las personas con cuenta de usuario ni los datos de otras instituciones.\n\nDeja una anotación de balance en la bitácora de auditoría.\n\n**Acceso:** solo el rol SuperAdmin.",
        'operationId' => 'procesarPreCarga',
        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
            'required'   => ['archivo_base64', 'confirmacion'],
            'type'       => 'object',
            'properties' => $precargaEntrada['properties'] + [
                'confirmacion' => [
                    'type'        => 'string',
                    'enum'        => ['ENCERAR Y CARGAR'],
                    'description' => 'Confirmación explícita del usuario. Sin ella la operación se rechaza.',
                ],
            ],
        ]]]],
        'responses' => $erroresComunes([
            '200' => $respuesta('Carga ejecutada.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'mensaje'   => ['type' => 'string', 'example' => 'PreCarga ejecutada correctamente.'],
                    'archivo'   => ['type' => 'string'],
                    'eliminado' => [
                        'type'       => 'object',
                        'properties' => [
                            'historial'            => ['type' => 'integer'],
                            'consentimiento_datos' => ['type' => 'integer'],
                            'consentimientos'      => ['type' => 'integer'],
                            'estudiantes'          => ['type' => 'integer'],
                            'empleados'            => ['type' => 'integer'],
                            'proveedores'          => ['type' => 'integer'],
                            'personas'             => ['type' => 'integer'],
                        ],
                    ],
                    'cargado' => [
                        'type'       => 'object',
                        'properties' => [
                            'personas'    => ['type' => 'integer'],
                            'empleados'   => ['type' => 'integer'],
                            'estudiantes' => ['type' => 'integer'],
                            'proveedores' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ])),
            '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
        ]),
    ],
];


/* --------------------------------------------------------------------------
   Envío masivo de invitaciones (SuperAdmin o Registro de Datos)
   -------------------------------------------------------------------------- */

$envioTipo = [
    'type'        => 'string',
    'enum'        => ['ESTUDIANTE', 'EMPLEADO', 'PROVEEDOR'],
    'description' => 'Grupo al que va dirigido el envío.',
];

$envioPersona = [
    'type'       => 'object',
    'properties' => [
        'PersonaId'      => ['type' => 'integer'],
        'Identificacion' => ['type' => 'string'],
        'Nombre'         => ['type' => 'string'],
    ],
];

$spec['paths']['/envio-masivo/resumen'] = [
    'get' => [
        'tags' => ['Envío Masivo'], 'summary' => 'Cuántos hay de cada tipo y a cuántos se les puede escribir',
        'description' => "Conteos por grupo de la institución del token, con cuántos tienen un correo válido y cuántos no lo tienen —esos no reciben nada—, más el estado del servidor de correo saliente configurado para esa institución.\n\nEn estudiantes el correo es el del **representante**, no el del alumno.\n\n**Acceso:** SuperAdmin o el rol Registro de Datos.",
        'operationId' => 'resumenEnvioMasivo',
        'responses' => $erroresComunes([
            '200' => $respuesta('Resumen por grupo.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'tipos' => [
                        'type'        => 'object',
                        'description' => 'Una entrada por cada tipo de persona.',
                        'additionalProperties' => [
                            'type'       => 'object',
                            'properties' => [
                                'total'      => ['type' => 'integer', 'example' => 7],
                                'con_correo' => ['type' => 'integer', 'example' => 6],
                                'sin_correo' => ['type' => 'integer', 'example' => 1],
                                'escribe_a'  => ['type' => 'string', 'enum' => ['titular', 'representante']],
                            ],
                        ],
                    ],
                    'maximo' => ['type' => 'integer', 'example' => 300, 'description' => 'Tope de correos por petición.'],
                    'correo' => [
                        'type'       => 'object',
                        'properties' => [
                            'smtp_activo' => ['type' => 'boolean'],
                            'remitente'   => ['type' => 'string', 'format' => 'email'],
                        ],
                    ],
                ],
            ])),
        ]),
    ],
];

$spec['paths']['/envio-masivo/destinatarios'] = [
    'get' => [
        'tags' => ['Envío Masivo'], 'summary' => 'Listado paginado para elegir uno por uno',
        'description' => "Alimenta la subventana de selección individual de la pantalla. Devuelve, de la institución del token, las personas activas del tipo indicado con el correo al que se les escribiría.\n\n`Destinatario` es el correo de destino: el de la persona, o el de su representante cuando el tipo es ESTUDIANTE. `TieneCorreo` en `false` significa que esa persona no puede recibir la invitación.\n\n**Acceso:** SuperAdmin o el rol Registro de Datos.",
        'operationId' => 'destinatariosEnvioMasivo',
        'parameters'  => [
            ['name' => 'tipo', 'in' => 'query', 'required' => true, 'schema' => $envioTipo],
            ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string'],
             'description' => 'Busca por nombre, apellido o identificación.'],
            ['name' => 'solo_con_correo', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [0, 1]],
             'description' => 'En 1 deja fuera a quienes no tienen correo registrado.'],
            ['$ref' => '#/components/parameters/Pagina'],
        ],
        'responses' => $erroresComunes([
            '200' => $respuesta('Página de destinatarios.', [
                'type'       => 'object',
                'properties' => [
                    'ok'    => ['type' => 'boolean', 'example' => true],
                    'datos' => ['type' => 'array', 'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'PersonaId'      => ['type' => 'integer'],
                            'Identificacion' => ['type' => 'string'],
                            'Nombres'        => ['type' => 'string'],
                            'Apellidos'      => ['type' => 'string'],
                            'Destinatario'   => ['type' => 'string', 'nullable' => true],
                            'Representante'  => ['type' => 'string', 'description' => 'Solo en estudiantes.'],
                            'TieneCorreo'    => ['type' => 'boolean'],
                        ],
                    ]],
                    'meta' => [
                        'type'       => 'object',
                        'properties' => [
                            'total'         => ['type' => 'integer'],
                            'pagina'        => ['type' => 'integer'],
                            'por_pagina'    => ['type' => 'integer', 'example' => 10],
                            'total_paginas' => ['type' => 'integer'],
                            'tipo'          => $envioTipo,
                            'documento'     => ['type' => 'string', 'enum' => ['CEDULA', 'RUC']],
                        ],
                    ],
                ],
            ]),
            '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
        ]),
    ],
];

$spec['paths']['/envio-masivo/enviar'] = [
    'post' => [
        'tags' => ['Envío Masivo'], 'summary' => 'Enviar las invitaciones',
        'description' => "Escribe a cada destinatario con el enlace de **consentimiento con verificación** de su tipo, con su número de documento ya cargado en la dirección (`&doc=`), de modo que quien lo abre solo tiene que continuar. Al abrirlo se le enviará un código a ese mismo correo.\n\nEl remitente y el servidor salen de la configuración de correo de la institución del token; el texto del mensaje vive en `plantillas/correo_invitacion_consentimiento.php`.\n\nCon `alcance: \"seleccion\"` se acota a los `personas` indicados; un identificador de otra institución sencillamente no coincide y no se le escribe. Quien no tenga correo válido sale nombrado en `sin_correo` y la tanda continúa.\n\nToda la tanda usa **una sola conexión SMTP**. El tope es de 300 correos por petición.\n\nNo modifica ningún dato: puede repetirse cuantas veces haga falta. Deja una anotación de balance en la bitácora de auditoría.\n\n**Acceso:** SuperAdmin o el rol Registro de Datos.",
        'operationId' => 'enviarEnvioMasivo',
        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
            'required'   => ['tipo', 'alcance'],
            'type'       => 'object',
            'properties' => [
                'tipo'    => $envioTipo,
                'alcance' => [
                    'type'        => 'string',
                    'enum'        => ['todos', 'seleccion'],
                    'description' => '`todos`: el grupo completo. `seleccion`: solo los `personas` indicados.',
                ],
                'personas' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer'],
                    'description' => 'PersonaId elegidos. Obligatorio cuando `alcance` es `seleccion`.',
                ],
            ],
        ]]]],
        'responses' => $erroresComunes([
            '200' => $respuesta('Balance del envío.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'tipo'       => $envioTipo,
                    'alcance'    => ['type' => 'string', 'enum' => ['todos', 'seleccion']],
                    'total'      => ['type' => 'integer', 'description' => 'Destinatarios considerados.'],
                    'enviados'   => ['type' => 'integer'],
                    'sin_correo' => ['type' => 'array', 'items' => $envioPersona,
                                     'description' => 'No tienen correo registrado: no recibieron nada.'],
                    'fallidos'   => ['type' => 'array', 'items' => $envioPersona,
                                     'description' => 'El servidor de correo los rechazó; incluye `detalle`.'],
                    'via'        => ['type' => 'string', 'example' => 'SMTP'],
                    'mensaje'    => ['type' => 'string', 'example' => 'Se enviaron 24 invitación(es).'],
                ],
            ])),
            '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
        ]),
    ],
];


/* --------------------------------------------------------------------------
   Verificación pública por código (enlaces con verificación)
   -------------------------------------------------------------------------- */

$verifEntrada = [
    'required'   => ['tipo', 'inst', 'identificacion'],
    'type'       => 'object',
    'properties' => [
        'tipo'           => ['type' => 'string', 'enum' => ['ESTUDIANTE', 'EMPLEADO', 'PROVEEDOR']],
        'inst'           => ['type' => 'integer', 'description' => 'Institución educativa del enlace.'],
        'identificacion' => ['type' => 'string', 'description' => 'Cédula, o RUC en el caso de proveedores.'],
    ],
];

$spec['paths']['/verificacion-publica/consultar'] = [
    'post' => [
        'tags' => ['Verificación Pública'], 'summary' => 'Consultar si la persona está registrada',
        'description' => "**Ruta pública: no requiere token.** La consume `consentimiento_verificado.php`.\n\n**No escribe nada.** Dice si la cédula o el RUC constan en la institución y, si constan, devuelve la ficha con el correo y el teléfono **enmascarados**, el estado del consentimiento y a qué dirección se enviaría el código.\n\nSi no consta, responde `existe: false` y el recorrido termina ahí: por este camino nadie se da de alta.",
        'operationId' => 'consultarVerificacionPublica',
        'security' => [],
        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $verifEntrada]]],
        'responses' => [
            '200' => $respuesta('Resultado de la consulta.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'tipo'           => ['type' => 'string'],
                    'documento'      => ['type' => 'string', 'enum' => ['CEDULA', 'RUC']],
                    'identificacion' => ['type' => 'string'],
                    'existe'         => ['type' => 'boolean'],
                    'datos'          => [
                        'type'        => 'object',
                        'nullable'    => true,
                        'description' => 'Solo si existe. Email y Teléfono vienen enmascarados.',
                    ],
                    'estado_actual'  => ['type' => 'object', 'nullable' => true],
                    'puede_revocar'  => ['type' => 'boolean'],
                    'hay_correo'     => ['type' => 'boolean', 'description' => 'false si no hay a dónde enviar el código.'],
                    'correo_oculto'  => ['type' => 'string', 'example' => 'an•••••z@correo.com'],
                    'correo_de'      => ['type' => 'string', 'enum' => ['titular', 'representante']],
                ],
            ])),
            '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
        ],
    ],
];

$spec['paths']['/verificacion-publica/enviar-codigo'] = [
    'post' => [
        'tags' => ['Verificación Pública'], 'summary' => 'Enviar o reenviar el código al correo registrado',
        'description' => "**Ruta pública: no requiere token.**\n\nGenera un código de 6 dígitos y lo envía al correo que consta en el sistema —el del **representante** en el caso de los estudiantes—. De la tabla `verificacion_codigo` solo se guarda el SHA-256 del código, nunca su valor.\n\nLímites: **10 minutos** de validez, un solo código vigente por identificación (pedir otro anula el anterior), **60 segundos** de espera entre envíos, **5 envíos** por código y **10 por hora**.\n\nSi el correo no sale, el código se anula y se responde 502: un código que no llegó no sirve de nada.",
        'operationId' => 'enviarCodigoVerificacion',
        'security' => [],
        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => $verifEntrada]]],
        'responses' => [
            '200' => $respuesta('Código enviado.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'enviado'            => ['type' => 'boolean'],
                    'correo_oculto'      => ['type' => 'string', 'example' => 'an•••••z@correo.com'],
                    'correo_de'          => ['type' => 'string', 'enum' => ['titular', 'representante']],
                    'envio'              => ['type' => 'integer', 'description' => '1 el original, 2+ los reenvíos.'],
                    'es_reenvio'         => ['type' => 'boolean'],
                    'envios_restantes'   => ['type' => 'integer'],
                    'expira'             => ['type' => 'string', 'example' => '2026-08-25 16:43:00'],
                    'expira_hora'        => ['type' => 'string', 'example' => '16:43'],
                    'emision_hora'       => ['type' => 'string', 'example' => '16:33'],
                    'segundos_restantes' => ['type' => 'integer', 'description' => 'Calculado por la base de datos.'],
                    'vigencia_minutos'   => ['type' => 'integer', 'example' => 10],
                    'espera_segundos'    => ['type' => 'integer', 'example' => 60],
                    'mensaje'            => ['type' => 'string'],
                ],
            ])),
            '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
            '502' => [
                'description' => 'El correo no se pudo enviar; el código quedó anulado.',
                'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ],
        ],
    ],
];

$spec['paths']['/verificacion-publica/validar-codigo'] = [
    'post' => [
        'tags' => ['Verificación Pública'], 'summary' => 'Validar el código y obtener el pase',
        'description' => "**Ruta pública: no requiere token.**\n\nComprueba el código. Se admiten **5 intentos**; al sexto el código queda anulado y hay que solicitar otro. Un código acertado se marca usado y no vuelve a servir.\n\nDevuelve un **pase** firmado con HMAC-SHA256, válido 20 minutos, que acredita la verificación. La pantalla lo reenvía en `/consentimiento-publico/registrar`, donde se comprueba y queda anotado en el historial que la identidad fue verificada por código.",
        'operationId' => 'validarCodigoVerificacion',
        'security' => [],
        'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
            'required'   => ['tipo', 'inst', 'identificacion', 'codigo'],
            'type'       => 'object',
            'properties' => $verifEntrada['properties'] + [
                'codigo' => ['type' => 'string', 'example' => '049217', 'description' => 'Los 6 dígitos recibidos por correo.'],
            ],
        ]]]],
        'responses' => [
            '200' => $respuesta('Identidad verificada.', $sobre([
                'type'       => 'object',
                'properties' => [
                    'verificado'     => ['type' => 'boolean'],
                    'tipo'           => ['type' => 'string'],
                    'identificacion' => ['type' => 'string'],
                    'pase'           => ['type' => 'string', 'description' => 'Pase firmado, válido 20 minutos.'],
                    'pase_expira'    => ['type' => 'string'],
                    'mensaje'        => ['type' => 'string'],
                ],
            ])),
            '422' => ['$ref' => '#/components/responses/ErrorValidacion'],
        ],
    ],
];

$spec['paths']['/reportes/cobertura-correo'] = [
    'get' => [
        'tags' => ['Reportes'], 'summary' => 'Cuántas personas tienen correo para el código',
        'description' => "Por cada tipo de persona: total de registros activos, cuántos tienen un correo al que enviarles el código de verificación y cuántos no. En estudiantes cuenta el correo del **representante**, que es a quien se le escribe.\n\nLa usa la pantalla de Enlaces de Consentimiento para avisar a quién no alcanzaría ese enlace.\n\n**Acceso:** SuperAdmin o permiso `ADM_ENLACES_VERIF`",
        'operationId' => 'coberturaCorreo',
        'responses' => $erroresComunes(['200' => $respuesta('Cobertura por tipo de persona.', $sobre([
            'type'       => 'object',
            'properties' => [
                'ESTUDIANTE' => ['$ref' => '#/components/schemas/CoberturaCorreo'],
                'EMPLEADO'   => ['$ref' => '#/components/schemas/CoberturaCorreo'],
                'PROVEEDOR'  => ['$ref' => '#/components/schemas/CoberturaCorreo'],
            ],
        ]))]),
    ],
];

$spec['components']['schemas']['CoberturaCorreo'] = [
    'type'       => 'object',
    'properties' => [
        'total'      => ['type' => 'integer'],
        'con_correo' => ['type' => 'integer'],
        'sin_correo' => ['type' => 'integer'],
    ],
];

/* Orden alfabético estable de las rutas para que Swagger UI las agrupe igual */
ksort($spec['paths']);

return $spec;
