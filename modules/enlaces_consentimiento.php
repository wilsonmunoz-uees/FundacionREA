<?php
/**
 * modules/enlaces_consentimiento.php
 * -----------------------------------------------------------------------------
 * Los tres enlaces públicos de consentimiento, listos para difundir.
 *
 * Cada institución educativa tiene su propio juego de enlaces —estudiantes,
 * empleados y proveedores—, porque quien se registre desde ellos queda asociado
 * a esa institución. La pantalla los muestra con un botón para copiarlos y
 * señala si el tipo tiene disclaimer vigente: sin él, el enlace abre pero no
 * puede recoger consentimientos.
 * -----------------------------------------------------------------------------
 */
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('correo_configuracion');

$institucionId     = institucionActual();
$institucionNombre = $_SESSION['institucion_nombre'] ?? 'esta institución';

/* URL pública del sistema, deducida de la propia dirección de esta pantalla */
$esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$carpeta = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$baseUrl = $esquema . '://' . $host . $carpeta;

/* Disclaimer vigente de cada tipo */
$vigentes = apiMeta(apiGet('disclaimers', ['por_pagina' => 1]), 'vigentes', []);

$tipos = [
    'ESTUDIANTE' => [
        'etiqueta'  => 'Estudiantes',
        'icono'     => '🎓',
        'parametro' => 'estudiante',
        'pide'      => 'Cédula del estudiante',
        'nota'      => 'Si el estudiante no está registrado, se piden sus datos y los de su representante. '
                     . 'La confirmación llega al correo del representante.',
    ],
    'EMPLEADO' => [
        'etiqueta'  => 'Empleados',
        'icono'     => '👥',
        'parametro' => 'empleado',
        'pide'      => 'Cédula del colaborador',
        'nota'      => 'Si no está registrado, se piden sus datos personales.',
    ],
    'PROVEEDOR' => [
        'etiqueta'  => 'Proveedores',
        'icono'     => '📦',
        'parametro' => 'proveedor',
        'pide'      => 'RUC del proveedor',
        'nota'      => 'Si no está registrado, se piden los datos del contacto y la razón social.',
    ],
];

$pageTitle  = 'Enlaces de Consentimiento';
$breadcrumb = [['label' => 'Administración', 'url' => null], ['label' => 'Enlaces de Consentimiento', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>🔗 Enlaces de Consentimiento</h1>
        <p>Direcciones públicas para que estudiantes, empleados y proveedores registren su decisión.</p>
    </div>
    <div class="flex-gap">
        <a href="disclaimers.php" class="btn btn-secundario">📜 Disclaimers</a>
        <a href="correo_configuracion.php" class="btn btn-secundario">⚙️ Configurar correo</a>
    </div>
</div>

<div class="alerta alerta-info">
    Estos enlaces corresponden a <strong><?= e($institucionNombre) ?></strong>. Quien se registre desde
    ellos quedará asociado a esta institución, de modo que cada institución debe difundir los suyos.
    Puede compartirlos por correo, WhatsApp, o publicarlos en la página web de la institución.
</div>

<?php foreach ($tipos as $clave => $tipo): ?>
    <?php
    $enlace  = $baseUrl . '/consentimiento.php?tipo=' . $tipo['parametro'] . '&inst=' . $institucionId;
    $vigente = $vigentes[$clave] ?? null;
    ?>
    <div class="card">
        <div class="flex-entre">
            <h3 class="mb-0"><?= $tipo['icono'] ?> <?= e($tipo['etiqueta']) ?></h3>
            <?php if ($vigente): ?>
                <span class="badge badge-activo">Disclaimer versión <?= e($vigente['Version']) ?></span>
            <?php else: ?>
                <span class="badge badge-inactivo">Sin disclaimer vigente</span>
            <?php endif; ?>
        </div>

        <p class="texto-mutado" style="margin:8px 0 4px;">
            <strong>Primer paso:</strong> <?= e($tipo['pide']) ?>. <?= e($tipo['nota']) ?>
        </p>

        <div class="caja-enlace">
            <input type="text" readonly value="<?= e($enlace) ?>" id="enlace_<?= e($clave) ?>"
                   onclick="this.select();" aria-label="Enlace para <?= e($tipo['etiqueta']) ?>">
            <button type="button" class="btn btn-secundario btn-copiar" data-destino="enlace_<?= e($clave) ?>">
                📋 Copiar
            </button>
            <a href="<?= e($enlace) ?>" class="btn btn-secundario" target="_blank" rel="noopener">Abrir</a>
        </div>

        <?php if (!$vigente): ?>
            <p class="texto-mutado" style="margin-bottom:0;">
                ⚠️ Todavía no hay un disclaimer vigente para este tipo de persona. El enlace abre, pero
                al llegar al paso del consentimiento avisará que la política no está disponible.
                <a href="disclaimers.php?accion=crear">Cree y active uno</a>.
            </p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div class="card">
    <h3>Cómo funciona el recorrido</h3>
    <ol class="lista-simple">
        <li><strong>Identificación.</strong> La persona ingresa su cédula —o el RUC, si es proveedor— y
            el sistema la busca en la institución.</li>
        <li><strong>Datos.</strong> Si ya está registrada, se le muestra lo que consta para que lo
            verifique. Si no, se le piden los datos para darla de alta; en el caso de los estudiantes se
            piden además los del representante.</li>
        <li><strong>Consentimiento.</strong> Se muestra el disclaimer vigente de su tipo y los botones
            para otorgar o revocar. Quien ya tenía el consentimiento otorgado no puede revocarlo en
            línea: el botón aparece deshabilitado, indicándole que debe escribir a la Fundación REA.</li>
    </ol>
    <p class="texto-mutado">
        Cada decisión queda registrada en los consentimientos y en su historial, con la fecha, la hora y
        la dirección desde la que se conectó, y se envía un correo de confirmación. En los estudiantes ese
        correo va al representante e indica de qué representado se trata.
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
