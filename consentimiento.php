<?php
/**
 * consentimiento.php
 * -----------------------------------------------------------------------------
 * ÚLTIMO PASO DE LOS ENLACES CON VERIFICACIÓN
 *
 * Aquí desemboca quien ya comprobó su identidad con el código que
 * `consentimiento_verificado.php` le envió a su correo registrado: se le
 * muestra el disclaimer vigente de su tipo y se registra su decisión.
 *
 * NO ES UNA PUERTA DE ENTRADA. Antes esta misma pantalla atendía un
 * autoservicio abierto —identificarse, darse de alta si no constaba y
 * decidir—, y por ahí entraban altas de personas sin que nadie las hubiera
 * cargado. El alta es hoy competencia exclusiva de la Carga de Información,
 * de modo que ese recorrido se retiró: quien abra esta dirección sin haber
 * pasado por la verificación es enviado al enlace que corresponde.
 *
 * Está deliberadamente AISLADA del resto de la aplicación: no exige sesión de
 * usuario, no carga el menú ni el encabezado interno y no enlaza a ninguna
 * pantalla del sistema. Solo comparte la identidad visual.
 *
 * El traspaso llega por la sesión pública —que comparte con
 * `consentimiento_verificado.php` por llevar el mismo `session_name`— y trae
 * el pase firmado que acredita la verificación. Sin ese pase la API rechaza
 * la decisión, de modo que la comprobación no depende de esta pantalla.
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

$resultado = null;
$errores   = [];

/* ---------------------------------------------------------------------------
   Puerta: solo se pasa con la identidad ya verificada
   ---------------------------------------------------------------------------
   El traspaso lo deja `consentimiento_verificado.php` en la sesión pública, con
   el documento de la persona y el pase firmado. Quien llegue por su cuenta
   —copiando la dirección, o volviendo sobre un enlace antiguo— es enviado al
   enlace con verificación, que es la única entrada.

   Se deja pasar la pantalla de resultado (el POST de la decisión, que ya vació
   el hilo) para que la persona vea su comprobante. */
$traspasoValido = !empty($hilo['identificacion']) && !empty($hilo['pase']);
$enviandoDecision = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'decidir';

if ($contexto !== null && !$traspasoValido && !$enviandoDecision) {
    header('Location: consentimiento_verificado.php?tipo=' . urlencode(mb_strtolower($tipo))
           . '&inst=' . $institucionId);
    exit;
}

/* ---------------------------------------------------------------------------
   La decisión
   --------------------------------------------------------------------------- */
if ($contexto !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'decidir') {

    $decision = strtoupper(trim($_POST['decision'] ?? ''));

    if (!csrfPublicoValido()) {
        $errores[] = 'La página estuvo demasiado tiempo abierta. Vuelva a intentarlo.';
    } elseif (!in_array($decision, ['OTORGA', 'REVOCA'], true)) {
        $errores[] = 'Indique si otorga o revoca el consentimiento.';
    } elseif (empty($hilo['identificacion']) || empty($hilo['pase'])) {
        $errores[] = 'Su verificación caducó. Vuelva a abrir el enlace y solicite un código nuevo.';
    } else {
        $respuesta = apiPostPublico('consentimiento-publico/registrar', [
            'tipo'           => $tipo,
            'inst'           => $institucionId,
            'identificacion' => $hilo['identificacion'],
            'decision'       => $decision,
            // Acredita que la identidad se comprobó con el código enviado al
            // correo registrado. Sin él la API rechaza la decisión.
            'pase'           => $hilo['pase'] ?? '',
        ]);

        if (!$respuesta['ok']) {
            $errores = apiErrores($respuesta) ?: [apiError($respuesta)];
        } else {
            $resultado = apiDatos($respuesta, []);
            unset($_SESSION[$claveHilo]);
            $hilo = ['paso' => 4];
        }
    }
}

/* Reinicio del recorrido: se vuelve al principio, que es el enlace verificado */
if (($_GET['reiniciar'] ?? '') === '1') {
    unset($_SESSION[$claveHilo]);
    header('Location: consentimiento_verificado.php?tipo=' . urlencode(mb_strtolower($tipo))
           . '&inst=' . $institucionId);
    exit;
}

/* ---------------------------------------------------------------------------
   Preparación de la vista
   --------------------------------------------------------------------------- */
$paso        = (int)($hilo['paso'] ?? 3);
$datosPersona = $hilo['datos'] ?? null;
$puedeRevocar = $hilo['puede_revocar'] ?? true;
$estadoActual = $hilo['estado_actual'] ?? null;
$disclaimer   = $hilo['disclaimer'] ?? ($contexto['disclaimer'] ?? null);

$verificado   = !empty($hilo['verificado']);

$documento    = $contexto['documento'] ?? 'CEDULA';
$institucion  = $contexto['institucion'] ?? 'Red Educativa Arquidiocesana';

$etiquetaTipo = match ($tipo) {
    'ESTUDIANTE' => 'Estudiante',
    'EMPLEADO'   => 'Colaborador',
    'PROVEEDOR'  => 'Proveedor',
    default      => '',
};
$etiquetaDoc = $documento === 'RUC' ? 'RUC' : 'cédula';

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
        <?php /* El mismo indicador de cuatro pasos que trae el enlace con
                 verificación: la persona llega aquí con los tres primeros
                 cumplidos y solo le queda decidir. */ ?>
        <ol class="pasos">
            <li class="hecho"><span class="pasos-numero">1</span> Identificación</li>
            <li class="hecho"><span class="pasos-numero">2</span> Sus datos</li>
            <li class="hecho"><span class="pasos-numero">3</span> Verificación</li>
            <li class="hecho actual"><span class="pasos-numero">4</span> Consentimiento</li>
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

            <p><a class="enlace-volver" href="<?= $e($urlBase) ?>&amp;reiniciar=1">Registrar otra decisión</a></p>

        <?php /* ---------------- El consentimiento ---------------- */ ?>
        <?php else: ?>

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
                    <span class="ficha-valor"><?= $e($datosPersona['NombreCompleto'] ?? '') ?></span>
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
                    <?php $repNombre = trim(($datosPersona['RepApellidos'] ?? '') . ' ' . ($datosPersona['RepNombres'] ?? '')); ?>
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
