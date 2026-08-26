<?php
/**
 * modules/enlaces_verificados.php
 * -----------------------------------------------------------------------------
 * Enlaces de Consentimiento, listos para difundir.
 *
 * Cada institución educativa tiene su propio juego —estudiantes, empleados y
 * proveedores—. No dan de alta a nadie: consultan lo que ya está registrado y,
 * antes de dejar decidir, envían un código al correo que consta en el sistema.
 *
 * La pantalla los muestra con un botón para copiarlos, señala si el tipo tiene
 * disclaimer vigente y avisa cuántas personas de cada tipo no podrían usarlos
 * por no tener correo registrado, que es la única condición que los bloquea.
 *
 * El archivo conserva su nombre por ser un identificador interno ya repartido;
 * lo que cambió es el nombre de la opción.
 * -----------------------------------------------------------------------------
 */
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('enlaces_verificados');

$institucionId     = institucionActual();
$institucionNombre = $_SESSION['institucion_nombre'] ?? 'esta institución';

/* URL pública del sistema, deducida de la propia dirección de esta pantalla */
$esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$carpeta = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$baseUrl = $esquema . '://' . $host . $carpeta;

/* Disclaimer vigente de cada tipo */
$vigentes = apiMeta(apiGet('disclaimers', ['por_pagina' => 1]), 'vigentes', []);

/* Cobertura de correo: sin correo registrado, el enlace verificado no sirve */
$cobertura = apiDatos(apiGet('reportes/cobertura-correo'), []);

$tipos = [
    'ESTUDIANTE' => [
        'etiqueta'  => 'Estudiantes',
        'icono'     => '🎓',
        'parametro' => 'estudiante',
        'pide'      => 'Cédula del estudiante',
        'nota'      => 'El código se envía al correo del representante registrado, indicando de qué '
                     . 'representado se trata.',
    ],
    'EMPLEADO' => [
        'etiqueta'  => 'Empleados',
        'icono'     => '👥',
        'parametro' => 'empleado',
        'pide'      => 'Cédula del colaborador',
        'nota'      => 'El código se envía al correo que consta en su ficha.',
    ],
    'PROVEEDOR' => [
        'etiqueta'  => 'Proveedores',
        'icono'     => '📦',
        'parametro' => 'proveedor',
        'pide'      => 'RUC del proveedor',
        'nota'      => 'El código se envía al correo del contacto registrado.',
    ],
];

$pageTitle  = 'Enlaces de Consentimiento';
$breadcrumb = [
    ['label' => 'Administración', 'url' => null],
    ['label' => 'Enlaces de Consentimiento', 'url' => null],
];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>🔗 Enlaces de Consentimiento</h1>
        <p>Enlaces públicos de solo consulta que confirman la identidad con un código enviado por correo.</p>
    </div>
    <div class="flex-gap">
        <?php if (puedeAcceder('envio_masivo')): ?>
        <a href="envio_masivo.php" class="btn btn-secundario">📨 Envío masivo</a>
        <?php endif; ?>
        <a href="disclaimers.php" class="btn btn-secundario">📜 Disclaimers</a>
        <a href="correo_configuracion.php" class="btn btn-secundario">⚙️ Configurar correo</a>
    </div>
</div>

<div class="alerta alerta-info">
    Estos enlaces corresponden a <strong><?= e($institucionNombre) ?></strong>.
    <strong>No registran ni modifican datos</strong>: solo muestran lo que ya consta y, antes de permitir
    decidir, envían un código de verificación al correo registrado. Úselos cuando el padrón ya esté
    cargado y quiera asegurarse de que quien decide es el titular.
</div>

<?php foreach ($tipos as $clave => $tipo): ?>
    <?php
    $enlace  = $baseUrl . '/consentimiento_verificado.php?tipo=' . $tipo['parametro'] . '&inst=' . $institucionId;
    $vigente = $vigentes[$clave] ?? null;
    $datos   = $cobertura[$clave] ?? null;
    ?>
    <div class="card">
        <div class="flex-entre">
            <h3 class="mb-0"><?= $tipo['icono'] ?> <?= e($tipo['etiqueta']) ?></h3>
            <div class="flex-gap">
                <?php if ($datos !== null): ?>
                    <?php $sinCorreo = (int)($datos['sin_correo'] ?? 0); ?>
                    <span class="badge <?= $sinCorreo === 0 ? 'badge-activo' : 'badge-inactivo' ?>">
                        <?= (int)($datos['con_correo'] ?? 0) ?> de <?= (int)($datos['total'] ?? 0) ?> con correo
                    </span>
                <?php endif; ?>
                <?php if ($vigente): ?>
                    <span class="badge badge-activo">Disclaimer versión <?= e($vigente['Version']) ?></span>
                <?php else: ?>
                    <span class="badge badge-inactivo">Sin disclaimer vigente</span>
                <?php endif; ?>
            </div>
        </div>

        <p class="texto-mutado" style="margin:8px 0 4px;">
            <strong>Primer paso:</strong> <?= e($tipo['pide']) ?>. <?= e($tipo['nota']) ?>
        </p>

        <div class="caja-enlace">
            <input type="text" readonly value="<?= e($enlace) ?>" id="enlace_<?= e($clave) ?>"
                   onclick="this.select();" aria-label="Enlace verificado para <?= e($tipo['etiqueta']) ?>">
            <button type="button" class="btn btn-secundario btn-copiar" data-destino="enlace_<?= e($clave) ?>">
                📋 Copiar
            </button>
            <a href="<?= e($enlace) ?>" class="btn btn-secundario" target="_blank" rel="noopener">Abrir</a>
        </div>

        <?php if (!$vigente): ?>
            <p class="texto-mutado" style="margin-bottom:0;">
                ⚠️ Todavía no hay un disclaimer vigente para este tipo de persona. El enlace verifica la
                identidad igual, pero al llegar al consentimiento avisará que la política no está
                disponible. <a href="disclaimers.php?accion=crear">Cree y active uno</a>.
            </p>
        <?php endif; ?>

        <?php if ($datos !== null && (int)($datos['sin_correo'] ?? 0) > 0): ?>
            <p class="texto-mutado" style="margin-bottom:0;">
                ⚠️ <strong><?= (int)$datos['sin_correo'] ?></strong>
                <?= $clave === 'ESTUDIANTE' ? 'estudiante(s) cuyo representante no tiene' : 'registro(s) sin' ?>
                correo electrónico. Esas personas no podrán usar este enlace hasta que se registre su
                correo desde <a href="<?= e(strtolower($tipo['parametro'])) ?>s.php">
                <?= e($tipo['etiqueta']) ?></a>.
            </p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div class="card">
    <h3>Cómo funciona el recorrido</h3>
    <ol class="lista-simple">
        <li><strong>Identificación.</strong> La persona ingresa su cédula —o el RUC, si es proveedor—.
            El sistema solo consulta: no crea ni modifica nada.</li>
        <li><strong>Sus datos.</strong> Si está registrada, se le muestra lo que consta, con el correo y
            el teléfono parcialmente ocultos. Si no está registrada, ahí termina el recorrido y se le
            indica que se comunique con la institución.</li>
        <li><strong>Verificación.</strong> Se envía un código de 6 dígitos al correo registrado, con
            <strong>10 minutos</strong> de validez. La pantalla muestra siempre a qué dirección se envió,
            parcialmente oculta, y ofrece reenviarlo.</li>
        <li><strong>Consentimiento.</strong> Con el código correcto se pasa a la pantalla de
            consentimiento de siempre: disclaimer vigente y botones para otorgar o revocar.</li>
    </ol>

    <h3>Qué hacen y qué no</h3>
    <div class="tabla-wrap">
        <table class="tabla-datos">
            <tbody>
            <tr><td>Da de alta a quien no está registrado</td><td><strong>No</strong></td></tr>
            <tr><td>Modifica los datos de la persona</td><td><strong>Nunca</strong></td></tr>
            <tr><td>Comprueba la identidad</td><td><strong>Código por correo, 10 minutos</strong></td></tr>
            <tr><td>Requiere correo registrado</td><td><strong>Sí</strong></td></tr>
            <tr><td>Registra la decisión</td><td><strong>Sí</strong></td></tr>
            </tbody>
        </table>
    </div>

    <p class="texto-mutado">
        Como no dan de alta a nadie, el padrón debe estar cargado antes de difundirlos: quien no conste
        —o no tenga correo registrado— no puede usarlos. Para poblarlo de una vez está la
        <strong>PreCarga Inicial</strong>.
    </p>

    <p class="texto-mutado">
        Las decisiones tomadas por este camino quedan anotadas en el historial señalando que la identidad
        fue verificada por código. Del código no se guarda su valor, solo su huella, y caduca a los
        10 minutos o en cuanto se usa.
    </p>
    <p class="texto-mutado">
        Para que el envío funcione debe estar configurado el correo saliente de la institución en
        <a href="correo_configuracion.php">Configuración de Correo</a>.
    </p>
</div>

<script>
(function () {
    'use strict';

    document.querySelectorAll('.btn-copiar').forEach(function (boton) {
        boton.addEventListener('click', function () {
            var campo = document.getElementById(boton.getAttribute('data-destino'));
            if (!campo) { return; }

            campo.select();
            campo.setSelectionRange(0, 99999);   // en móviles hace falta el rango

            var copiado = false;
            try { copiado = document.execCommand('copy'); } catch (e) { copiado = false; }

            if (!copiado && navigator.clipboard) {
                navigator.clipboard.writeText(campo.value).then(function () { avisar(boton); });
                return;
            }
            if (copiado) { avisar(boton); }
        });
    });

    function avisar(boton) {
        var original = boton.textContent;
        boton.textContent = '✓ Copiado';
        boton.classList.add('btn-exito');
        setTimeout(function () {
            boton.textContent = original;
            boton.classList.remove('btn-exito');
        }, 1800);
    }
})();
</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
