<?php
/**
 * consentimiento_verificado.php
 * -----------------------------------------------------------------------------
 * AUTOSERVICIO PÚBLICO DE CONSENTIMIENTO **CON VERIFICACIÓN DE IDENTIDAD**
 *
 * Es la variante prudente de consentimiento.php. Los enlaces son:
 *
 *   consentimiento_verificado.php?tipo=estudiante&inst=1
 *   consentimiento_verificado.php?tipo=empleado&inst=1
 *   consentimiento_verificado.php?tipo=proveedor&inst=1
 *
 * Admiten además `&doc=<identificación>`, que es como los envía el Envío Masivo:
 * el número llega precargado en el primer paso y a la persona solo le queda
 * pulsar Consultar. El campo sigue siendo editable, por si el dato no fuera
 * correcto; precargarlo no salta ninguna comprobación.
 *
 * Diferencias con el enlace abierto:
 *
 *   · NO registra ni modifica datos personales. Solo consulta si la persona
 *     ya está registrada y muestra lo que consta.
 *   · Si no está registrada, el recorrido termina ahí: no ofrece darse de alta.
 *   · Antes de dejarla decidir sobre sus datos, envía un código de 6 dígitos al
 *     correo que consta en el sistema y exige escribirlo. El código dura
 *     10 minutos y puede reenviarse.
 *   · Solo cuando el código es correcto se pasa a la pantalla de consentimiento.
 *
 * El recorrido tiene cuatro pasos:
 *
 *   1. Identificación   cédula (estudiantes y empleados) o RUC (proveedores).
 *   2. Sus datos        se muestra lo registrado, en modo de solo lectura.
 *   3. Verificación     código enviado al correo registrado, con reenvío.
 *   4. Consentimiento   se entrega el recorrido a consentimiento.php, que
 *                       muestra el disclaimer vigente y registra la decisión.
 *
 * Comparte la sesión pública con consentimiento.php (mismo `session_name`), que
 * es justamente lo que permite el traspaso del paso 4 sin volver a preguntar
 * nada. Está igual de aislada del resto del sistema: sin menú, sin sesión de
 * usuario y sin enlaces internos.
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

/* Hilo propio, distinto al del enlace abierto */
$claveHilo = 'verif_' . $tipo . '_' . $institucionId;
$hilo      = $_SESSION[$claveHilo] ?? ['paso' => 1];

/* Documento precargado desde el enlace del correo. Solo se usa para rellenar el
   campo del primer paso: la consulta la sigue disparando la persona. */
$documentoPrecargado = (string)preg_replace('/[^0-9A-Za-z]/', '', (string)($_GET['doc'] ?? ''));
$documentoPrecargado = mb_substr($documentoPrecargado, 0, 20);

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

/** Limita las consultas por sesión, para dificultar el barrido de cédulas. */
function consultasAgotadas(): bool
{
    $_SESSION['consultas_verif'] = (int)($_SESSION['consultas_verif'] ?? 0);
    return $_SESSION['consultas_verif'] >= 25;
}

$errores = [];
$avisos  = [];

/* ---------------------------------------------------------------------------
   Paso 1 → 2: consulta (solo lectura, no registra nada)
   --------------------------------------------------------------------------- */
if ($contexto !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'consultar') {

    if (!csrfPublicoValido()) {
        $errores[] = 'La página estuvo demasiado tiempo abierta. Vuelva a intentarlo.';
    } elseif (consultasAgotadas()) {
        $errores[] = 'Se realizaron demasiadas consultas. Cierre la página y vuelva a abrir el enlace en unos minutos.';
    } else {
        $_SESSION['consultas_verif']++;

        $respuesta = apiPostPublico('verificacion-publica/consultar', [
            'tipo'           => $tipo,
            'inst'           => $institucionId,
            'identificacion' => trim($_POST['identificacion'] ?? ''),
        ]);

        if (!$respuesta['ok']) {
            $errores = apiErrores($respuesta);
        } else {
            $hilo         = apiDatos($respuesta, []);
            $hilo['paso'] = 2;
            $_SESSION[$claveHilo] = $hilo;
        }
    }
}

/* ---------------------------------------------------------------------------
   Paso 2 → 3: envío del código, y reenvío dentro del paso 3
   --------------------------------------------------------------------------- */
if ($contexto !== null && $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['accion'] ?? '', ['enviar_codigo', 'reenviar_codigo'], true)) {

    $esReenvio = ($_POST['accion'] ?? '') === 'reenviar_codigo';

    if (!csrfPublicoValido()) {
        $errores[] = 'La página estuvo demasiado tiempo abierta. Vuelva a intentarlo.';
    } elseif (empty($hilo['identificacion']) || empty($hilo['existe'])) {
        $errores[] = 'Vuelva a ingresar su número de identificación.';
        $hilo = ['paso' => 1];
        unset($_SESSION[$claveHilo]);
    } else {
        $respuesta = apiPostPublico('verificacion-publica/enviar-codigo', [
            'tipo'           => $tipo,
            'inst'           => $institucionId,
            'identificacion' => $hilo['identificacion'],
        ]);

        if (!$respuesta['ok']) {
            $errores = apiErrores($respuesta);
            // Un reenvío fallido no debe devolver a la persona al principio
            if ($esReenvio) {
                $hilo['paso'] = 3;
            }
        } else {
            $envio = apiDatos($respuesta, []);

            $hilo['paso']            = 3;
            $hilo['correo_oculto']   = $envio['correo_oculto'] ?? '';
            $hilo['correo_de']       = $envio['correo_de'] ?? 'titular';
            $hilo['expira']          = $envio['expira'] ?? '';
            $hilo['expira_hora']     = $envio['expira_hora'] ?? '';
            $hilo['restantes']       = (int)($envio['segundos_restantes'] ?? 0);
            $hilo['marca']           = time();   // solo para descontar en pantalla
            $hilo['vigencia']        = (int)($envio['vigencia_minutos'] ?? 10);
            $hilo['envio']           = (int)($envio['envio'] ?? 1);
            $hilo['envios_restantes']= (int)($envio['envios_restantes'] ?? 0);
            $hilo['espera']          = (int)($envio['espera_segundos'] ?? 60);
            // La hora la da la base, el mismo reloj con el que caduca el código
            $hilo['enviado_a_las']   = $envio['emision_hora'] ?? '';

            $avisos[] = $esReenvio
                ? 'Le enviamos un código nuevo a ' . ($envio['correo_oculto'] ?? 'su correo') . '.'
                : ($envio['mensaje'] ?? 'Le enviamos un código a su correo.');
        }

        $_SESSION[$claveHilo] = $hilo;
    }
}

/* ---------------------------------------------------------------------------
   Paso 3 → 4: validación del código y traspaso al consentimiento
   --------------------------------------------------------------------------- */
if ($contexto !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'validar_codigo') {

    if (!csrfPublicoValido()) {
        $errores[] = 'La página estuvo demasiado tiempo abierta. Vuelva a intentarlo.';
    } elseif (empty($hilo['identificacion'])) {
        $errores[] = 'Vuelva a ingresar su número de identificación.';
        $hilo = ['paso' => 1];
        unset($_SESSION[$claveHilo]);
    } else {
        $respuesta = apiPostPublico('verificacion-publica/validar-codigo', [
            'tipo'           => $tipo,
            'inst'           => $institucionId,
            'identificacion' => $hilo['identificacion'],
            'codigo'         => trim($_POST['codigo'] ?? ''),
        ]);

        if (!$respuesta['ok']) {
            $errores = apiErrores($respuesta);
            $hilo['paso'] = 3;
            $_SESSION[$claveHilo] = $hilo;
        } else {
            $pase = apiDatos($respuesta, []);

            /* --- Traspaso a la pantalla de consentimiento ---------------------
               Se rearma el hilo de consentimiento.php con la ficha real de la
               persona y se le deja en su paso 3, ya con el disclaimer. Así la
               persona no vuelve a escribir nada y la decisión se registra por el
               camino de siempre, con el pase firmado como constancia de que su
               identidad fue verificada. */
            $ficha = apiPostPublico('consentimiento-publico/identificar', [
                'tipo'           => $tipo,
                'inst'           => $institucionId,
                'identificacion' => $hilo['identificacion'],
            ]);

            if (!$ficha['ok']) {
                $errores = apiErrores($ficha);
                $hilo['paso'] = 3;
                $_SESSION[$claveHilo] = $hilo;
            } else {
                $hiloConsentimiento                = apiDatos($ficha, []);
                $hiloConsentimiento['paso']        = 3;
                $hiloConsentimiento['verificado']  = true;
                $hiloConsentimiento['pase']        = $pase['pase'] ?? '';
                $hiloConsentimiento['pase_expira'] = $pase['pase_expira'] ?? '';

                $_SESSION['hilo_' . $tipo . '_' . $institucionId] = $hiloConsentimiento;
                unset($_SESSION[$claveHilo]);

                header('Location: consentimiento.php?tipo=' . urlencode(mb_strtolower($tipo))
                    . '&inst=' . $institucionId . '&verificado=1');
                exit;
            }
        }
    }
}

/* Reinicio del recorrido */
if (($_GET['reiniciar'] ?? '') === '1') {
    unset($_SESSION[$claveHilo]);
    header('Location: consentimiento_verificado.php?tipo=' . urlencode(mb_strtolower($tipo)) . '&inst=' . $institucionId);
    exit;
}

/* ---------------------------------------------------------------------------
   Preparación de la vista
   --------------------------------------------------------------------------- */
$paso         = (int)($hilo['paso'] ?? 1);
$existe       = !empty($hilo['existe']);
$datosPersona = $hilo['datos'] ?? null;
$estadoActual = $hilo['estado_actual'] ?? null;
$hayCorreo    = !empty($hilo['hay_correo']);

$documento   = $contexto['documento'] ?? 'CEDULA';
$institucion = $contexto['institucion'] ?? 'Red Educativa Arquidiocesana';

$etiquetaTipo = match ($tipo) {
    'ESTUDIANTE' => 'Estudiante',
    'EMPLEADO'   => 'Colaborador',
    'PROVEEDOR'  => 'Proveedor',
    default      => 'Titular',
};

$etiquetaDocumento = $documento === 'RUC' ? 'Número de RUC' : 'Número de cédula';

$urlBase = 'consentimiento_verificado.php?tipo=' . urlencode(mb_strtolower($tipo)) . '&inst=' . $institucionId;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Consulta verificada de consentimiento — REA</title>
    <link rel="stylesheet" href="css/consentimiento.css">
    <style>
        /* Estilos propios del paso de verificación */
        .codigo-entrada {
            font-size: 30px; letter-spacing: 12px; text-align: center;
            font-weight: 700; font-family: "Courier New", monospace;
            padding: 14px 10px; width: 100%; max-width: 320px; margin: 0 auto;
            display: block; border: 2px solid #cfd8e3; border-radius: 10px; color: #1f4e79;
        }
        .codigo-entrada:focus { outline: none; border-color: #1f4e79; }
        .destinatario {
            background: #f0f5fb; border: 1px solid #cfe0f2; border-radius: 10px;
            padding: 14px 18px; margin: 0 0 18px;
        }
        .destinatario-etiqueta { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #5b7391; }
        .destinatario-correo { font-size: 17px; font-weight: 700; color: #1f4e79; margin-top: 4px; word-break: break-all; }
        .destinatario-nota { font-size: 13px; color: #5b7391; margin-top: 6px; }
        .reenvio { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 16px; }
        .reenvio-nota { font-size: 13px; color: #6b7a8d; }
        .solo-lectura {
            background: #f6f8fb; border: 1px dashed #cfd8e3; border-radius: 8px;
            padding: 10px 14px; font-size: 13px; color: #5b6b7f; margin: 0 0 18px;
        }
        .cuenta-atras { font-weight: 700; color: #1f4e79; }
    </style>
</head>
<body>

<div class="hoja">

    <header class="cabecera">
        <img src="assets/logo.png" alt="Red Educativa Arquidiocesana (REA)" class="logo">
        <?php if ($contexto !== null): ?>
            <div class="institucion"><?= $e($institucion) ?></div>
        <?php endif; ?>
    </header>

    <?php if ($contexto !== null): ?>
        <ol class="pasos">
            <li class="<?= $paso >= 1 ? 'hecho' : '' ?><?= $paso === 1 ? ' actual' : '' ?>">
                <span class="pasos-numero">1</span> Identificación
            </li>
            <li class="<?= $paso >= 2 ? 'hecho' : '' ?><?= $paso === 2 ? ' actual' : '' ?>">
                <span class="pasos-numero">2</span> Sus datos
            </li>
            <li class="<?= $paso >= 3 ? 'hecho' : '' ?><?= $paso === 3 ? ' actual' : '' ?>">
                <span class="pasos-numero">3</span> Verificación
            </li>
            <li class="<?= $paso >= 4 ? 'hecho' : '' ?>">
                <span class="pasos-numero">4</span> Consentimiento
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

        <?php if ($avisos): ?>
            <div class="aviso aviso-exito">
                <?php foreach ($avisos as $aviso): ?>
                    <p><?= $e($aviso) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php /* ================= PASO 1 · Identificación ================= */ ?>
        <?php if ($paso === 1): ?>

            <h1>Consulta de su consentimiento</h1>
            <p class="entradilla">
                Ingrese su <?= $documento === 'RUC' ? 'RUC' : 'número de cédula' ?> para consultar lo que
                consta registrado en <strong><?= $e($institucion) ?></strong>.
            </p>

            <div class="solo-lectura">
                🔒 Esta página <strong>no registra ni modifica</strong> ningún dato: solo consulta.
                Si su registro existe, le enviaremos un código al correo que tenemos registrado para
                confirmar que es usted.
            </div>

            <form method="POST" action="<?= $e($urlBase) ?>" class="formulario">
                <?= '<input type="hidden" name="csrf" value="' . $e(csrfPublico()) . '">' ?>
                <input type="hidden" name="accion" value="consultar">
                <input type="hidden" name="tipo" value="<?= $e($tipo) ?>">
                <input type="hidden" name="inst" value="<?= $institucionId ?>">

                <div class="campo">
                    <label for="identificacion"><?= $e($etiquetaDocumento) ?> <span class="obligatorio">*</span></label>
                    <input type="text" id="identificacion" name="identificacion" required
                           inputmode="numeric" autocomplete="off" maxlength="20"
                           value="<?= $e($_POST['identificacion'] ?? $documentoPrecargado) ?>">
                    <div class="campo-ayuda">
                        <?= $documentoPrecargado !== '' && !isset($_POST['identificacion'])
                            ? 'Lo tomamos del enlace que le enviamos. Si no es el suyo, corríjalo.'
                            : 'Escriba solo los números, sin guiones ni espacios.' ?>
                    </div>
                </div>

                <button type="submit" class="boton boton-principal">Consultar</button>
            </form>

        <?php /* ================= PASO 2 · Datos registrados ================= */ ?>
        <?php elseif ($paso === 2 && !$existe): ?>

            <div class="aviso aviso-error">
                <h2>No encontramos su registro</h2>
                <p><?= $e($hilo['mensaje'] ?? 'Ese número no consta en los registros de la institución.') ?></p>
                <p class="nota">
                    Este enlace es de <strong>solo consulta</strong>: no da de alta a nadie. Si cree que
                    debería constar registrado, comuníquese con la institución educativa para que
                    completen su ficha.
                </p>
            </div>

            <a class="enlace-volver" href="<?= $e($urlBase) ?>&amp;reiniciar=1">← Consultar otro número</a>

        <?php elseif ($paso === 2): ?>

            <h1>Esto es lo que consta registrado</h1>
            <p class="entradilla">
                Revise que la información corresponde a usted. Los datos se muestran parcialmente
                ocultos por seguridad.
            </p>

            <div class="ficha">
                <div class="ficha-fila">
                    <span class="ficha-etiqueta"><?= $e($etiquetaTipo) ?></span>
                    <span class="ficha-valor"><?= $e($datosPersona['NombreCompleto'] ?? '') ?></span>
                </div>
                <div class="ficha-fila">
                    <span class="ficha-etiqueta">Identificación</span>
                    <span class="ficha-valor">
                        <?= $e($datosPersona['TipoIdentificacion'] ?? $documento) ?>
                        <?= $e($datosPersona['Identificacion'] ?? '') ?>
                    </span>
                </div>
                <?php if (!empty($datosPersona['Email'])): ?>
                <div class="ficha-fila">
                    <span class="ficha-etiqueta">Correo</span>
                    <span class="ficha-valor"><?= $e($datosPersona['Email']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($datosPersona['Telefono'])): ?>
                <div class="ficha-fila">
                    <span class="ficha-etiqueta">Teléfono</span>
                    <span class="ficha-valor"><?= $e($datosPersona['Telefono']) ?></span>
                </div>
                <?php endif; ?>

                <?php if ($tipo === 'ESTUDIANTE'): ?>
                    <?php if (!empty($datosPersona['CodigoEstudiante'])): ?>
                    <div class="ficha-fila">
                        <span class="ficha-etiqueta">Código de estudiante</span>
                        <span class="ficha-valor"><?= $e($datosPersona['CodigoEstudiante']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($datosPersona['Representante'])): ?>
                    <div class="ficha-fila">
                        <span class="ficha-etiqueta">Representante</span>
                        <span class="ficha-valor">
                            <?= $e($datosPersona['Representante']) ?>
                            <?php if (!empty($datosPersona['RepresentanteRelacion'])): ?>
                                <span class="pastilla"><?= $e($datosPersona['RepresentanteRelacion']) ?></span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                <?php elseif ($tipo === 'PROVEEDOR' && !empty($datosPersona['RazonSocial'])): ?>
                    <div class="ficha-fila">
                        <span class="ficha-etiqueta">Razón social</span>
                        <span class="ficha-valor"><?= $e($datosPersona['RazonSocial']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($estadoActual !== null): ?>
                <div class="ficha-fila">
                    <span class="ficha-etiqueta">Consentimiento actual</span>
                    <span class="ficha-valor">
                        <?php if (($estadoActual['Estado'] ?? '') === 'ACTIVO'): ?>
                            <span class="pastilla pastilla-si">Otorgado</span>
                        <?php else: ?>
                            <span class="pastilla pastilla-no">Revocado</span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php else: ?>
                <div class="ficha-fila">
                    <span class="ficha-etiqueta">Consentimiento actual</span>
                    <span class="ficha-valor"><span class="pastilla">Sin registrar</span></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="solo-lectura">
                🔒 Esta pantalla es de <strong>solo consulta</strong>. Nada de lo anterior se ha
                modificado. Para corregir algún dato, comuníquese con la institución.
            </div>

            <?php if (!$hayCorreo): ?>
                <div class="aviso aviso-error">
                    <h2>No podemos enviarle el código</h2>
                    <p>
                        <?= $tipo === 'ESTUDIANTE'
                            ? 'El representante registrado no tiene un correo electrónico en el sistema.'
                            : 'No hay un correo electrónico registrado a su nombre.' ?>
                    </p>
                    <p class="nota">
                        Comuníquese con la institución educativa para que registren un correo y podrá
                        continuar con este enlace.
                    </p>
                </div>
                <a class="enlace-volver" href="<?= $e($urlBase) ?>&amp;reiniciar=1">← Consultar otro número</a>
            <?php else: ?>
                <div class="destinatario">
                    <div class="destinatario-etiqueta">Enviaremos su código a</div>
                    <div class="destinatario-correo"><?= $e($hilo['correo_oculto'] ?? '') ?></div>
                    <div class="destinatario-nota">
                        <?= ($hilo['correo_de'] ?? '') === 'representante'
                            ? 'Es el correo del representante registrado.'
                            : 'Es el correo que consta en su ficha.' ?>
                        Si no es el suyo, comuníquese con la institución antes de continuar.
                    </div>
                </div>

                <form method="POST" action="<?= $e($urlBase) ?>" class="formulario">
                    <?= '<input type="hidden" name="csrf" value="' . $e(csrfPublico()) . '">' ?>
                    <input type="hidden" name="accion" value="enviar_codigo">
                    <input type="hidden" name="tipo" value="<?= $e($tipo) ?>">
                    <input type="hidden" name="inst" value="<?= $institucionId ?>">

                    <div class="botonera">
                        <button type="submit" class="boton boton-principal">Enviarme el código</button>
                        <a class="boton boton-secundario" href="<?= $e($urlBase) ?>&amp;reiniciar=1">Cancelar</a>
                    </div>
                </form>
            <?php endif; ?>

        <?php /* ================= PASO 3 · Código de verificación ================= */ ?>
        <?php elseif ($paso === 3): ?>

            <h1>Escriba el código que le enviamos</h1>

            <div class="destinatario">
                <div class="destinatario-etiqueta">Código enviado a</div>
                <div class="destinatario-correo"><?= $e($hilo['correo_oculto'] ?? '') ?></div>
                <div class="destinatario-nota">
                    <?= ($hilo['correo_de'] ?? '') === 'representante'
                        ? 'Correo del representante registrado.'
                        : 'Correo registrado en su ficha.' ?>
                    <?php if (!empty($hilo['enviado_a_las'])): ?>
                        Enviado a las <?= $e($hilo['enviado_a_las']) ?>.
                    <?php endif; ?>
                    <?php if ((int)($hilo['envio'] ?? 1) > 1): ?>
                        (envío n.º <?= (int)$hilo['envio'] ?>)
                    <?php endif; ?>
                </div>
            </div>

            <p class="entradilla">
                El código tiene <strong><?= (int)($hilo['vigencia'] ?? 10) ?> minutos</strong> de validez.
                <?php if (!empty($hilo['expira_hora'])): ?>
                    Caduca a las <strong><?= $e($hilo['expira_hora']) ?></strong>
                    <span id="cuentaAtras" class="cuenta-atras"></span>
                <?php endif; ?>
            </p>

            <form method="POST" action="<?= $e($urlBase) ?>" class="formulario" id="formCodigo">
                <?= '<input type="hidden" name="csrf" value="' . $e(csrfPublico()) . '">' ?>
                <input type="hidden" name="accion" value="validar_codigo">
                <input type="hidden" name="tipo" value="<?= $e($tipo) ?>">
                <input type="hidden" name="inst" value="<?= $institucionId ?>">

                <div class="campo">
                    <label for="codigo">Código de 6 dígitos <span class="obligatorio">*</span></label>
                    <input type="text" id="codigo" name="codigo" required autofocus
                           class="codigo-entrada" inputmode="numeric" autocomplete="one-time-code"
                           pattern="[0-9]*" maxlength="6" placeholder="······">
                    <div class="campo-ayuda" style="text-align:center;">
                        Revise también la carpeta de correo no deseado.
                    </div>
                </div>

                <button type="submit" class="boton boton-principal">Verificar y continuar</button>
            </form>

            <div class="reenvio">
                <form method="POST" action="<?= $e($urlBase) ?>" style="display:inline;">
                    <?= '<input type="hidden" name="csrf" value="' . $e(csrfPublico()) . '">' ?>
                    <input type="hidden" name="accion" value="reenviar_codigo">
                    <input type="hidden" name="tipo" value="<?= $e($tipo) ?>">
                    <input type="hidden" name="inst" value="<?= $institucionId ?>">
                    <button type="submit" class="boton boton-secundario" id="btnReenviar">
                        Reenviar el código
                    </button>
                </form>
                <span class="reenvio-nota" id="notaReenvio">
                    <?php if ((int)($hilo['envios_restantes'] ?? 0) > 0): ?>
                        Le quedan <?= (int)$hilo['envios_restantes'] ?> reenvío(s).
                    <?php else: ?>
                        Alcanzó el número máximo de envíos.
                    <?php endif; ?>
                </span>
            </div>

            <p class="texto-menor" style="margin-top:18px;">
                ¿El correo mostrado no es el suyo? No continúe: comuníquese con la institución educativa
                para actualizar sus datos.
            </p>

            <a class="enlace-volver" href="<?= $e($urlBase) ?>&amp;reiniciar=1">← Empezar de nuevo</a>

            <script>
            (function () {
                'use strict';

                /* Solo dígitos en el campo del código */
                var codigo = document.getElementById('codigo');
                if (codigo) {
                    codigo.addEventListener('input', function () {
                        this.value = this.value.replace(/\D/g, '').slice(0, 6);
                    });
                }

                /* Cuenta atrás de la validez del código.
                   Los segundos vienen calculados por la base de datos, que es el
                   reloj con el que se comprueba la caducidad; aquí solo se
                   descuenta lo transcurrido desde que se pintó la página. */
                var etiqueta = document.getElementById('cuentaAtras');
                var restante = <?= json_encode(max(0, (int)($hilo['restantes'] ?? 0) - (time() - (int)($hilo['marca'] ?? time())))) ?>;

                if (etiqueta && restante > 0) {

                    var pintar = function () {
                        if (restante <= 0) {
                            etiqueta.textContent = ' — el código caducó, solicite uno nuevo';
                            return;
                        }
                        var m = Math.floor(restante / 60);
                        var s = restante % 60;
                        etiqueta.textContent = ' (quedan ' + m + ':' + (s < 10 ? '0' : '') + s + ')';
                        restante--;
                        window.setTimeout(pintar, 1000);
                    };
                    pintar();
                }

                /* Espera mínima entre reenvíos */
                var espera = <?= json_encode((int)($hilo['espera'] ?? 60)) ?>;
                var boton  = document.getElementById('btnReenviar');
                var nota   = document.getElementById('notaReenvio');
                var textoOriginal = nota ? nota.textContent : '';

                if (boton && espera > 0) {
                    var quedan = espera;
                    boton.disabled = true;

                    var contar = function () {
                        if (quedan <= 0) {
                            boton.disabled = false;
                            if (nota) { nota.textContent = textoOriginal; }
                            return;
                        }
                        if (nota) { nota.textContent = 'Podrá reenviarlo en ' + quedan + ' s.'; }
                        quedan--;
                        window.setTimeout(contar, 1000);
                    };
                    contar();
                }
            })();
            </script>

        <?php endif; ?>

    <?php endif; ?>

    </main>

    <footer class="pie">
        <p>
            Red Educativa Arquidiocesana — Sistema de Gestión de Protección de Datos.<br>
            Esta página <strong>solo consulta</strong> su registro y verifica su identidad.
            No solicita contraseñas ni información de pago.
        </p>
    </footer>
</div>

</body>
</html>
