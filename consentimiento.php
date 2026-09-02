<?php
/**
 * consentimiento.php
 * -----------------------------------------------------------------------------
 * AUTOSERVICIO PÚBLICO DE CONSENTIMIENTO
 *
 * Es la pantalla que abren los tres enlaces que difunde la institución:
 *
 *   consentimiento.php?tipo=estudiante&inst=1
 *   consentimiento.php?tipo=empleado&inst=1
 *   consentimiento.php?tipo=proveedor&inst=1
 *
 * Está deliberadamente AISLADA del resto de la aplicación: no exige sesión de
 * usuario, no carga el menú ni el encabezado interno y no enlaza a ninguna
 * pantalla del sistema. Solo comparte la identidad visual.
 *
 * El recorrido tiene tres pasos:
 *
 *   1. Identificación   cédula (estudiantes y empleados) o RUC (proveedores).
 *   2. Datos            si la persona no existe se piden sus datos para el alta
 *                       —en estudiantes, también los del representante—; si ya
 *                       existe, se muestra lo que consta registrado.
 *   3. Consentimiento   se muestra el disclaimer vigente de su tipo y se
 *                       registra la decisión.
 *
 * El avance entre pasos se guarda en una sesión propia, con nombre distinto al
 * de la aplicación para no interferir con quien esté trabajando en el sistema.
 * -----------------------------------------------------------------------------
 */

session_name('rea_consentimiento');
session_start();

require_once __DIR__ . '/includes/api_client.php';

/* ---------------------------------------------------------------------------
   Contexto del enlace
   --------------------------------------------------------------------------- */
$tipo          = strtoupper(trim($_GET['tipo'] ?? $_POST['tipo'] ?? ''));
$institucionId = (int)($_GET['inst'] ?? $_POST['inst'] ?? 0);

$inicio = apiGetPublico('consentimiento-publico/inicio', ['tipo' => $tipo, 'inst' => $institucionId]);

$contexto    = $inicio['ok'] ? apiDatos($inicio, []) : null;
$errorPagina = $inicio['ok'] ? '' : apiError($inicio);

/* Cada enlace lleva su propio hilo dentro de la sesión */
$claveHilo = 'hilo_' . $tipo . '_' . $institucionId;
$hilo      = $_SESSION[$claveHilo] ?? ['paso' => 1];

/* ---------------------------------------------------------------------------
   Utilidades de la pantalla
   --------------------------------------------------------------------------- */
$e = static fn($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

function csrfPublico(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfPublicoValido(): bool
{
    return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
}

/** Limita los intentos de identificación por sesión, para dificultar el barrido de cédulas. */
function intentosAgotados(): bool
{
    $_SESSION['intentos'] = (int)($_SESSION['intentos'] ?? 0);
    return $_SESSION['intentos'] >= 25;
}

$resultado = null;
$errores   = [];

/* ---------------------------------------------------------------------------
   Paso 1 → 2: identificación
   --------------------------------------------------------------------------- */
if ($contexto !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'identificar') {

    if (!csrfPublicoValido()) {
        $errores[] = 'La página estuvo demasiado tiempo abierta. Vuelva a intentarlo.';
    } elseif (intentosAgotados()) {
        $errores[] = 'Se realizaron demasiadas consultas. Cierre la página y vuelva a abrir el enlace en unos minutos.';
    } else {
        $_SESSION['intentos']++;

        $respuesta = apiPostPublico('consentimiento-publico/identificar', [
            'tipo'           => $tipo,
            'inst'           => $institucionId,
            'identificacion' => trim($_POST['identificacion'] ?? ''),
        ]);

        if (!$respuesta['ok']) {
            $errores = apiErrores($respuesta) ?: [apiError($respuesta)];
        } else {
            $hilo = apiDatos($respuesta, []);
            $hilo['paso'] = 2;
            $_SESSION[$claveHilo] = $hilo;
        }
    }
}

/* ---------------------------------------------------------------------------
   Paso 2 → 3: confirmación de los datos
   --------------------------------------------------------------------------- */
if ($contexto !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'datos') {

    if (!csrfPublicoValido()) {
        $errores[] = 'La página estuvo demasiado tiempo abierta. Vuelva a intentarlo.';
    } elseif (empty($hilo['identificacion'])) {
        $errores[] = 'Vuelva a ingresar su número de identificación.';
        $hilo = ['paso' => 1];
    } else {
        // Los datos del alta se guardan en la sesión y se envían junto con la decisión
        $hilo['nuevos'] = [
            'nombres'          => trim($_POST['nombres'] ?? ''),
            'apellidos'        => trim($_POST['apellidos'] ?? ''),
            'email'            => trim($_POST['email'] ?? ''),
            'telefono'         => trim($_POST['telefono'] ?? ''),
            'razon_social'     => trim($_POST['razon_social'] ?? ''),
            'codigo_estudiante' => trim($_POST['codigo_estudiante'] ?? ''),
            'representante'    => [
                'identificacion' => trim($_POST['rep_identificacion'] ?? ''),
                'nombres'        => trim($_POST['rep_nombres'] ?? ''),
                'apellidos'      => trim($_POST['rep_apellidos'] ?? ''),
                'email'          => trim($_POST['rep_email'] ?? ''),
                'telefono'       => trim($_POST['rep_telefono'] ?? ''),
                'relacion'       => trim($_POST['rep_relacion'] ?? ''),
            ],
        ];
        $hilo['paso'] = 3;
        $_SESSION[$claveHilo] = $hilo;
    }
}

/* ---------------------------------------------------------------------------
   Paso 3: decisión
   --------------------------------------------------------------------------- */
if ($contexto !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'decidir') {

    $decision = strtoupper(trim($_POST['decision'] ?? ''));

    if (!csrfPublicoValido()) {
        $errores[] = 'La página estuvo demasiado tiempo abierta. Vuelva a intentarlo.';
    } elseif (!in_array($decision, ['OTORGA', 'REVOCA'], true)) {
        $errores[] = 'Indique si otorga o revoca el consentimiento.';
    } elseif (empty($hilo['identificacion'])) {
        $errores[] = 'Vuelva a ingresar su número de identificación.';
        $hilo = ['paso' => 1];
    } else {
        $respuesta = apiPostPublico('consentimiento-publico/registrar', [
            'tipo'           => $tipo,
            'inst'           => $institucionId,
            'identificacion' => $hilo['identificacion'],
            'decision'       => $decision,
            'datos'          => $hilo['nuevos'] ?? [],
            // Presente solo si se llegó desde el enlace CON VERIFICACIÓN
            'pase'           => $hilo['pase'] ?? '',
        ]);

        if (!$respuesta['ok']) {
            $errores = apiErrores($respuesta) ?: [apiError($respuesta)];
            // Con errores de datos se vuelve al formulario para corregirlos
            if (empty($hilo['existe'])) {
                $hilo['paso'] = 2;
            }
        } else {
            $resultado = apiDatos($respuesta, []);
            unset($_SESSION[$claveHilo]);
            $hilo = ['paso' => 4];
        }
    }
}

/* Reinicio del recorrido */
if (($_GET['reiniciar'] ?? '') === '1') {
    unset($_SESSION[$claveHilo]);
    header('Location: consentimiento.php?tipo=' . urlencode(mb_strtolower($tipo)) . '&inst=' . $institucionId);
    exit;
}

/* ---------------------------------------------------------------------------
   Preparación de la vista
   --------------------------------------------------------------------------- */
$paso        = (int)($hilo['paso'] ?? 1);
$existe      = !empty($hilo['existe']);
$datosPersona = $hilo['datos'] ?? null;
$puedeRevocar = $hilo['puede_revocar'] ?? true;
$estadoActual = $hilo['estado_actual'] ?? null;
$disclaimer   = $hilo['disclaimer'] ?? ($contexto['disclaimer'] ?? null);

$verificado   = !empty($hilo['verificado']);

/* Las relaciones del representante llegan desde la API, leídas del propio enum
   de la base: si esta todavía no tiene las nuevas, no se ofrecen. */
$relaciones   = $contexto['relaciones'] ?? ['MADRE', 'PADRE', 'REPRESENTANTE LEGAL', 'OTRO'];

$documento    = $contexto['documento'] ?? 'CEDULA';
$institucion  = $contexto['institucion'] ?? 'Red Educativa Arquidiocesana';

$etiquetaTipo = match ($tipo) {
    'ESTUDIANTE' => 'Estudiante',
    'EMPLEADO'   => 'Colaborador',
    'PROVEEDOR'  => 'Proveedor',
    default      => '',
};
$etiquetaDoc = $documento === 'RUC' ? 'RUC' : 'cédula';

/* Valores para repintar el formulario tras un error */
$previo = static function (string $campo, $porDefecto = '') use ($hilo) {
    return $_POST[$campo] ?? ($hilo['nuevos'][$campo] ?? $porDefecto);
};
$previoRep = static function (string $campo) use ($hilo) {
    return $_POST['rep_' . $campo] ?? ($hilo['nuevos']['representante'][$campo] ?? '');
};

$urlBase = 'consentimiento.php?tipo=' . urlencode(mb_strtolower($tipo)) . '&inst=' . $institucionId;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Consentimiento de datos personales — REA</title>
    <link rel="stylesheet" href="css/consentimiento.css">
</head>
<body>

<div class="hoja">

    <header class="cabecera">
        <img src="assets/logo.png" alt="Red Educativa Arquidiocesana (REA)" class="logo">
        <?php if ($contexto !== null): ?>
            <div class="institucion"><?= $e($institucion) ?></div>
        <?php endif; ?>
    </header>

    <?php if ($contexto !== null && $paso < 4): ?>
        <!-- Indicador de avance -->
        <ol class="pasos">
            <li class="<?= $paso >= 1 ? 'hecho' : '' ?><?= $paso === 1 ? ' actual' : '' ?>">
                <span class="pasos-numero">1</span> Identificación
            </li>
            <li class="<?= $paso >= 2 ? 'hecho' : '' ?><?= $paso === 2 ? ' actual' : '' ?>">
                <span class="pasos-numero">2</span> Sus datos
            </li>
            <li class="<?= $paso >= 3 ? 'hecho' : '' ?><?= $paso === 3 ? ' actual' : '' ?>">
                <span class="pasos-numero">3</span> Consentimiento
            </li>
        </ol>
    <?php endif; ?>

    <main class="contenido">

    <?php if ($contexto === null): ?>

        <div class="aviso aviso-error">
            <h1>No pudimos abrir esta página</h1>
            <p><?= $e($errorPagina ?: 'El enlace no es válido.') ?></p>
            <p class="nota">
                Verifique que copió el enlace completo. Si el problema continúa, comuníquese con la
                institución educativa.
            </p>
        </div>

    <?php else: ?>

        <?php if ($errores): ?>
            <div class="aviso aviso-error">
                <?php foreach ($errores as $error): ?>
                    <p><?= $e($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php /* ---------------- Resultado final ---------------- */ ?>
        <?php if ($paso === 4 && $resultado !== null): ?>

            <div class="aviso <?= ($resultado['decision'] ?? '') === 'OTORGA' ? 'aviso-exito' : 'aviso-revocado' ?>">
                <h2><?= ($resultado['decision'] ?? '') === 'OTORGA' ? '✓ Consentimiento registrado' : '✓ Revocación registrada' ?></h2>
                <p><?= $e($resultado['mensaje'] ?? '') ?></p>
                <p class="nota">
                    Fecha y hora: <?= $e(date('d/m/Y H:i', strtotime((string)($resultado['fecha'] ?? 'now')))) ?>.
                    <?php if (!empty($resultado['nuevo_registro'])): ?>
                        Sus datos quedaron registrados en la institución.
                    <?php endif; ?>
                </p>
            </div>

            <?php if (!empty($resultado['correo']['enviado'])): ?>
                <p>
                    Le enviamos la confirmación a
                    <strong><?= $e($resultado['correo']['destino']) ?></strong>.
                    <?php if ($tipo === 'ESTUDIANTE'): ?>
                        El mensaje va dirigido al representante e indica de qué representado se trata.
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <p class="texto-menor">
                    Su decisión quedó registrada. No pudimos enviarle el correo de confirmación
                    <?php if (!empty($resultado['correo']['detalle'])): ?>
                        (<?= $e($resultado['correo']['detalle']) ?>)
                    <?php endif; ?>; puede solicitarlo a la institución.
                </p>
            <?php endif; ?>

            <?php if (($resultado['decision'] ?? '') === 'OTORGA'): ?>
                <p class="texto-menor">
                    Si en el futuro desea revocar este consentimiento, escriba a la Fundación REA desde
                    el correo que tiene registrado.
                </p>
            <?php endif; ?>

            <p><a class="enlace-volver" href="<?= $e($urlBase) ?>&amp;reiniciar=1">Registrar otra persona</a></p>

        <?php /* ---------------- Paso 1: identificación ---------------- */ ?>
        <?php elseif ($paso === 1): ?>

            <h1>Consentimiento de datos personales</h1>
            <p class="entradilla">
                Esta página le permite otorgar o revocar su consentimiento para el tratamiento de sus
                datos personales
                <?php if ($tipo === 'ESTUDIANTE'): ?>
                    —o los de su representado— <?php endif; ?>en <?= $e($institucion) ?>.
            </p>

            <form method="POST" action="<?= $e($urlBase) ?>" class="formulario" autocomplete="off">
                <input type="hidden" name="csrf" value="<?= $e(csrfPublico()) ?>">
                <input type="hidden" name="accion" value="identificar">
                <input type="hidden" name="tipo" value="<?= $e($tipo) ?>">
                <input type="hidden" name="inst" value="<?= $institucionId ?>">

                <div class="campo">
                    <label for="identificacion">
                        <?= $documento === 'RUC' ? 'Número de RUC' : 'Número de cédula' ?>
                        <?php if ($etiquetaTipo !== ''): ?>
                            <span class="pastilla"><?= $e($etiquetaTipo) ?></span>
                        <?php endif; ?>
                    </label>
                    <input type="text" id="identificacion" name="identificacion" required
                           inputmode="numeric" autocomplete="off"
                           maxlength="<?= $documento === 'RUC' ? 13 : 10 ?>"
                           placeholder="<?= $documento === 'RUC' ? '0999999999001' : '0999999999' ?>"
                           value="<?= $e($_POST['identificacion'] ?? '') ?>">
                    <p class="campo-ayuda">
                        <?php if ($tipo === 'ESTUDIANTE'): ?>
                            Ingrese la cédula <strong>del estudiante</strong>, no la del representante.
                        <?php else: ?>
                            Ingrese solo números, sin guiones ni espacios.
                        <?php endif; ?>
                    </p>
                </div>

                <button type="submit" class="boton boton-principal">Continuar</button>
            </form>

        <?php /* ---------------- Paso 2: datos ---------------- */ ?>
        <?php elseif ($paso === 2): ?>

            <?php if ($existe): ?>

                <h1>Verifique sus datos</h1>
                <p class="entradilla">
                    Encontramos su registro en <?= $e($institucion) ?>. Esto es lo que consta:
                </p>

                <section class="ficha">
                    <div class="ficha-fila">
                        <span class="ficha-etiqueta">Titular de los datos</span>
                        <span class="ficha-valor"><?= $e($datosPersona['NombreCompleto'] ?? '') ?></span>
                    </div>
                    <div class="ficha-fila">
                        <span class="ficha-etiqueta">Identificación</span>
                        <span class="ficha-valor">
                            <?= $e($documento) ?> <?= $e($hilo['identificacion'] ?? '') ?>
                        </span>
                    </div>
                    <?php if (!empty($datosPersona['Email'])): ?>
                        <div class="ficha-fila">
                            <span class="ficha-etiqueta">Correo</span>
                            <span class="ficha-valor"><?= $e($datosPersona['Email']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($tipo === 'ESTUDIANTE' && !empty($datosPersona['RepNombres'])): ?>
                        <div class="ficha-fila">
                            <span class="ficha-etiqueta">Representante</span>
                            <span class="ficha-valor">
                                <?= $e(trim(($datosPersona['RepApellidos'] ?? '') . ' ' . ($datosPersona['RepNombres'] ?? ''))) ?>
                                <?php if (!empty($datosPersona['RepresentanteRelacion'])): ?>
                                    <span class="pastilla"><?= $e($datosPersona['RepresentanteRelacion']) ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if (!empty($datosPersona['RepEmail'])): ?>
                            <div class="ficha-fila">
                                <span class="ficha-etiqueta">Correo del representante</span>
                                <span class="ficha-valor"><?= $e($datosPersona['RepEmail']) ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($tipo === 'PROVEEDOR' && !empty($datosPersona['RazonSocial'])): ?>
                        <div class="ficha-fila">
                            <span class="ficha-etiqueta">Razón social</span>
                            <span class="ficha-valor"><?= $e($datosPersona['RazonSocial']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($estadoActual !== null): ?>
                        <div class="ficha-fila">
                            <span class="ficha-etiqueta">Estado actual</span>
                            <span class="ficha-valor">
                                <span class="pastilla <?= $estadoActual['Estado'] === 'ACTIVO' ? 'pastilla-si' : 'pastilla-no' ?>">
                                    <?= $estadoActual['Estado'] === 'ACTIVO' ? 'Consentimiento otorgado' : 'Consentimiento revocado' ?>
                                </span>
                            </span>
                        </div>
                    <?php endif; ?>
                </section>

                <p class="texto-menor">
                    Si algún dato no está correcto, comuníquese con la institución para actualizarlo.
                </p>

                <form method="POST" action="<?= $e($urlBase) ?>" class="formulario">
                    <input type="hidden" name="csrf" value="<?= $e(csrfPublico()) ?>">
                    <input type="hidden" name="accion" value="datos">
                    <input type="hidden" name="tipo" value="<?= $e($tipo) ?>">
                    <input type="hidden" name="inst" value="<?= $institucionId ?>">

                    <div class="botonera">
                        <a class="boton boton-secundario" href="<?= $e($urlBase) ?>&amp;reiniciar=1">Volver</a>
                        <button type="submit" class="boton boton-principal">Continuar</button>
                    </div>
                </form>

            <?php else: ?>

                <h1>Necesitamos sus datos</h1>
                <p class="entradilla">
                    No encontramos el <?= $e($etiquetaDoc) ?>
                    <strong><?= $e($hilo['identificacion'] ?? '') ?></strong> en <?= $e($institucion) ?>.
                    Complete la información para registrarlo.
                </p>

                <form method="POST" action="<?= $e($urlBase) ?>" class="formulario" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= $e(csrfPublico()) ?>">
                    <input type="hidden" name="accion" value="datos">
                    <input type="hidden" name="tipo" value="<?= $e($tipo) ?>">
                    <input type="hidden" name="inst" value="<?= $institucionId ?>">

                    <fieldset class="grupo">
                        <legend><?= $tipo === 'PROVEEDOR' ? 'Datos del contacto' : 'Datos personales' ?></legend>

                        <div class="campo-doble">
                            <div class="campo">
                                <label for="nombres">Nombres <span class="obligatorio">*</span></label>
                                <input type="text" id="nombres" name="nombres" required maxlength="100"
                                       value="<?= $e($previo('nombres')) ?>">
                            </div>
                            <div class="campo">
                                <label for="apellidos">Apellidos <span class="obligatorio">*</span></label>
                                <input type="text" id="apellidos" name="apellidos" required maxlength="100"
                                       value="<?= $e($previo('apellidos')) ?>">
                            </div>
                        </div>

                        <div class="campo-doble">
                            <div class="campo">
                                <label for="email">
                                    Correo electrónico
                                    <?php if ($tipo !== 'ESTUDIANTE'): ?><span class="obligatorio">*</span><?php endif; ?>
                                </label>
                                <input type="email" id="email" name="email" maxlength="150"
                                       <?= $tipo !== 'ESTUDIANTE' ? 'required' : '' ?>
                                       value="<?= $e($previo('email')) ?>">
                                <?php if ($tipo === 'ESTUDIANTE'): ?>
                                    <p class="campo-ayuda">Opcional. La confirmación se envía al representante.</p>
                                <?php endif; ?>
                            </div>
                            <div class="campo">
                                <label for="telefono">Teléfono</label>
                                <input type="tel" id="telefono" name="telefono" maxlength="16"
                                       inputmode="tel" pattern="^\+?[0-9]{7,15}$"
                                       title="Solo números, con un + opcional al inicio"
                                       value="<?= $e($previo('telefono')) ?>">
                                <p class="campo-ayuda">Solo números, con un + opcional al inicio. Máximo 16 caracteres.</p>
                            </div>
                        </div>

                        <?php if ($tipo === 'PROVEEDOR'): ?>
                            <div class="campo">
                                <label for="razon_social">Razón social <span class="obligatorio">*</span></label>
                                <input type="text" id="razon_social" name="razon_social" required maxlength="150"
                                       value="<?= $e($previo('razon_social')) ?>">
                            </div>
                        <?php endif; ?>
                    </fieldset>

                    <?php if ($tipo === 'ESTUDIANTE'): ?>
                        <fieldset class="grupo">
                            <legend>Datos del representante</legend>
                            <p class="campo-ayuda grupo-ayuda">
                                La confirmación del consentimiento se enviará al correo del representante.
                            </p>

                            <div class="campo-doble">
                                <div class="campo">
                                    <label for="rep_identificacion">Cédula <span class="obligatorio">*</span></label>
                                    <input type="text" id="rep_identificacion" name="rep_identificacion" required
                                           inputmode="numeric" maxlength="10"
                                           value="<?= $e($previoRep('identificacion')) ?>">
                                </div>
                                <div class="campo">
                                    <label for="rep_relacion">Relación <span class="obligatorio">*</span></label>
                                    <select id="rep_relacion" name="rep_relacion" required>
                                        <option value="">— Seleccione —</option>
                                        <?php foreach ($relaciones as $r): ?>
                                            <option value="<?= $e($r) ?>" <?= $previoRep('relacion') === $r ? 'selected' : '' ?>>
                                                <?= $e(ucfirst(mb_strtolower($r))) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="campo-doble">
                                <div class="campo">
                                    <label for="rep_nombres">Nombres <span class="obligatorio">*</span></label>
                                    <input type="text" id="rep_nombres" name="rep_nombres" required maxlength="100"
                                           value="<?= $e($previoRep('nombres')) ?>">
                                </div>
                                <div class="campo">
                                    <label for="rep_apellidos">Apellidos <span class="obligatorio">*</span></label>
                                    <input type="text" id="rep_apellidos" name="rep_apellidos" required maxlength="100"
                                           value="<?= $e($previoRep('apellidos')) ?>">
                                </div>
                            </div>

                            <div class="campo-doble">
                                <div class="campo">
                                    <label for="rep_email">Correo electrónico <span class="obligatorio">*</span></label>
                                    <input type="email" id="rep_email" name="rep_email" required maxlength="150"
                                           value="<?= $e($previoRep('email')) ?>">
                                </div>
                                <div class="campo">
                                    <label for="rep_telefono">Teléfono</label>
                                    <input type="tel" id="rep_telefono" name="rep_telefono" maxlength="16"
                                           inputmode="tel" pattern="^\+?[0-9]{7,15}$"
                                           title="Solo números, con un + opcional al inicio"
                                           value="<?= $e($previoRep('telefono')) ?>">
                                </div>
                            </div>
                        </fieldset>
                    <?php endif; ?>

                    <div class="botonera">
                        <a class="boton boton-secundario" href="<?= $e($urlBase) ?>&amp;reiniciar=1">Volver</a>
                        <button type="submit" class="boton boton-principal">Continuar</button>
                    </div>
                </form>

            <?php endif; ?>

        <?php /* ---------------- Paso 3: consentimiento ---------------- */ ?>
        <?php elseif ($paso === 3): ?>

            <?php if ($verificado): ?>
                <div class="aviso aviso-exito">
                    <h2>&#10003; Identidad verificada</h2>
                    <p>
                        Confirmamos su identidad con el código que enviamos a su correo registrado.
                        Ya puede decidir sobre el tratamiento de sus datos personales.
                    </p>
                </div>
            <?php endif; ?>

            <section class="ficha">
                <div class="ficha-fila">
                    <span class="ficha-etiqueta">Titular de los datos</span>
                    <span class="ficha-valor">
                        <?php if ($existe): ?>
                            <?= $e($datosPersona['NombreCompleto'] ?? '') ?>
                        <?php else: ?>
                            <?= $e(trim(($hilo['nuevos']['apellidos'] ?? '') . ' ' . ($hilo['nuevos']['nombres'] ?? ''))) ?>
                            <span class="pastilla">registro nuevo</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="ficha-fila">
                    <span class="ficha-etiqueta">Identificación</span>
                    <span class="ficha-valor">
                        <?= $e($documento) ?> <?= $e($hilo['identificacion'] ?? '') ?>
                        <?php if ($etiquetaTipo !== ''): ?>
                            <span class="pastilla"><?= $e($etiquetaTipo) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ($tipo === 'ESTUDIANTE'): ?>
                    <?php
                    $repNombre = $existe
                        ? trim(($datosPersona['RepApellidos'] ?? '') . ' ' . ($datosPersona['RepNombres'] ?? ''))
                        : trim(($hilo['nuevos']['representante']['apellidos'] ?? '') . ' ' . ($hilo['nuevos']['representante']['nombres'] ?? ''));
                    ?>
                    <?php if ($repNombre !== ''): ?>
                        <div class="ficha-fila">
                            <span class="ficha-etiqueta">Representante</span>
                            <span class="ficha-valor"><?= $e($repNombre) ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <?php if ($disclaimer === null): ?>

                <div class="aviso aviso-error">
                    <h2>Política no disponible</h2>
                    <p>
                        La institución todavía no ha publicado la política de protección de datos para
                        este caso. Por favor intente más tarde o comuníquese con nosotros.
                    </p>
                </div>

            <?php else: ?>

                <section class="texto-legal">
                    <h2><?= $e($disclaimer['Titulo'] ?: 'Consentimiento para el tratamiento de datos personales') ?></h2>
                    <?php /* El texto viene saneado por HtmlSeguro en la API */ ?>
                    <?= $disclaimer['Texto'] ?>
                    <p class="version-politica">Versión <?= $e($disclaimer['Version']) ?></p>
                </section>

                <section class="decision">
                    <h2>Su decisión</h2>
                    <p class="decision-ayuda">
                        Su respuesta queda registrada de inmediato con la fecha, la hora y la dirección
                        desde la que se conecta, y le enviaremos una confirmación por correo.
                    </p>

                    <form method="POST" action="<?= $e($urlBase) ?>" class="botonera" id="formDecision">
                        <input type="hidden" name="csrf" value="<?= $e(csrfPublico()) ?>">
                        <input type="hidden" name="accion" value="decidir">
                        <input type="hidden" name="tipo" value="<?= $e($tipo) ?>">
                        <input type="hidden" name="inst" value="<?= $institucionId ?>">

                        <button type="submit" name="decision" value="OTORGA" class="boton boton-si">
                            Doy mi consentimiento
                        </button>

                        <?php if ($puedeRevocar): ?>
                            <button type="submit" name="decision" value="REVOCA" class="boton boton-no">
                                Revoco mi consentimiento
                            </button>
                        <?php else: ?>
                            <span class="con-tooltip">
                                <button type="button" class="boton boton-no" disabled
                                        aria-describedby="ayudaRevocar">
                                    Revoco mi consentimiento
                                </button>
                                <span class="tooltip" id="ayudaRevocar" role="tooltip">
                                    Su consentimiento ya está registrado. Para revocarlo debe enviar un
                                    correo a la Fundación REA desde la dirección que tiene registrada.
                                </span>
                            </span>
                        <?php endif; ?>
                    </form>

                    <?php if (!$puedeRevocar): ?>
                        <p class="texto-menor nota-revocar">
                            ℹ️ La revocatoria no está disponible en línea porque su consentimiento ya
                            consta registrado. Para revocarlo, escriba a la Fundación REA desde el correo
                            que tiene registrado con nosotros.
                        </p>
                    <?php endif; ?>

                    <p><a class="enlace-volver" href="<?= $e($urlBase) ?>&amp;reiniciar=1">Cancelar y empezar de nuevo</a></p>
                </section>

            <?php endif; ?>

        <?php endif; ?>

    <?php endif; ?>

    </main>

    <footer class="pie">
        <p>
            Red Educativa Arquidiocesana — Sistema de Gestión de Protección de Datos.<br>
            Esta página registra su decisión sobre el tratamiento de sus datos personales.
            No solicita contraseñas ni información de pago.
        </p>
    </footer>
</div>

<script>
(function () {
    'use strict';

    var formulario = document.getElementById('formDecision');
    if (!formulario) { return; }

    formulario.addEventListener('submit', function (evento) {
        var boton = evento.submitter;
        if (!boton || !boton.value) { return; }

        var revoca = boton.value === 'REVOCA';
        var texto  = revoca
            ? '¿Confirma que desea REVOCAR el consentimiento para el tratamiento de los datos personales?'
            : '¿Confirma que OTORGA su consentimiento para el tratamiento de los datos personales?';

        if (!window.confirm(texto)) {
            evento.preventDefault();
            return;
        }

        // El botón pulsado no se deshabilita todavía: un control deshabilitado no
        // se envía, y con él se perdería la decisión. Se aplaza al siguiente ciclo.
        boton.textContent = 'Registrando…';
        setTimeout(function () {
            Array.prototype.forEach.call(formulario.querySelectorAll('button'), function (b) {
                b.disabled = true;
            });
        }, 0);
    });
})();
</script>

</body>
</html>
