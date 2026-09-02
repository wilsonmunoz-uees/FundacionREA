<?php
/**
 * modules/disclaimers.php
 * -----------------------------------------------------------------------------
 * Parámetros: disclaimers de políticas de protección de datos.
 *
 * Cada disclaimer aplica a un tipo de persona, lleva una versión y un texto
 * enriquecido. El que está ACTIVO es el que verá quien abra el enlace público
 * de su tipo; al activar uno, los demás de ese tipo quedan inactivos.
 *
 * El editor de texto enriquecido es propio, sin librerías externas, y el HTML
 * se depura en la API antes de guardarse.
 * -----------------------------------------------------------------------------
 */
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('disclaimers');

const TIPOS_PERSONA = [
    'ESTUDIANTE' => 'Estudiantes',
    'EMPLEADO'   => 'Empleados',
    'PROVEEDOR'  => 'Proveedores',
];

$accion  = $_GET['accion'] ?? 'listar';
$id      = (int)($_GET['id'] ?? 0);
$errores = [];

/* ---------------------------------------------------------------------------
   Guardar (alta y cambio)
   --------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    if (!csrfValido()) {
        $errores[] = 'La sesión expiró o el formulario no es válido. Vuelva a intentarlo.';
    } else {
        $datos = [
            'tipo_persona' => $_POST['tipo_persona'] ?? '',
            'version'      => trim($_POST['version'] ?? ''),
            'titulo'       => trim($_POST['titulo'] ?? ''),
            'texto'        => $_POST['texto'] ?? '',
            'estado'       => !empty($_POST['activar']) ? 'ACTIVO' : 'INACTIVO',
        ];

        $idEditar  = (int)($_POST['id'] ?? 0);
        $respuesta = $idEditar > 0
            ? apiPut('disclaimers/' . $idEditar, $datos)
            : apiPost('disclaimers', $datos);

        if ($respuesta['ok']) {
            flashSet('exito', apiDatos($respuesta, [])['mensaje'] ?? 'Disclaimer guardado.');
            redirigir('disclaimers.php');
        }
        $errores = apiErrores($respuesta) ?: [apiError($respuesta)];
        $accion  = $idEditar > 0 ? 'editar' : 'crear';
        $id      = $idEditar;
    }
}

/* ---------------------------------------------------------------------------
   Activar y eliminar
   --------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['activar', 'eliminar'], true)) {
    if (!csrfValido()) {
        flashSet('error', 'La sesión expiró o el formulario no es válido.');
    } else {
        $idAccion  = (int)($_POST['id'] ?? 0);
        $respuesta = $_POST['accion'] === 'activar'
            ? apiPatch('disclaimers/' . $idAccion . '/activar')
            : apiDelete('disclaimers/' . $idAccion);

        $respuesta['ok']
            ? flashSet('exito', apiDatos($respuesta, [])['mensaje'] ?? 'Listo.')
            : flashSet('error', apiError($respuesta));
    }
    redirigir('disclaimers.php');
}

/* ---------------------------------------------------------------------------
   Datos de la pantalla
   --------------------------------------------------------------------------- */
$registro = null;
if ($accion === 'editar' && $id > 0 && !$errores) {
    $respuesta = apiGet('disclaimers/' . $id);
    if (!$respuesta['ok']) {
        flashSet('error', apiError($respuesta));
        redirigir('disclaimers.php');
    }
    $registro = apiDatos($respuesta, []);
}

$listado  = apiGet('disclaimers', ['por_pagina' => 100]);
$filas    = apiDatos($listado, []);
$vigentes = apiMeta($listado, 'vigentes', []);

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

/* Valor de cada campo al repintar el formulario */
$valor = static function (string $campo, $porDefecto = '') use ($registro) {
    return $_POST[$campo] ?? ($registro[ucfirst($campo)] ?? $porDefecto);
};

$pageTitle  = 'Disclaimers de Datos';
$breadcrumb = [['label' => 'Administración', 'url' => null], ['label' => 'Disclaimers de Datos', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>📜 Disclaimers de Protección de Datos</h1>
        <p>Textos de política que se muestran a cada persona al momento de dar su consentimiento.</p>
    </div>
    <div class="flex-gap">
        <?php if ($accion === 'listar'): ?>
            <a href="disclaimers.php?accion=crear" class="btn btn-primario">+ Nuevo Disclaimer</a>
        <?php else: ?>
            <a href="disclaimers.php" class="btn btn-secundario">← Volver al listado</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($errores): ?>
    <div class="alerta alerta-error">
        <?php foreach ($errores as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($accion === 'listar'): ?>

    <!-- Qué está vigente hoy -->
    <div class="form-row">
        <?php foreach (TIPOS_PERSONA as $clave => $etiqueta): ?>
            <?php $vigente = $vigentes[$clave] ?? null; ?>
            <div class="card" style="flex:1 1 220px;">
                <h3 class="mb-0"><?= e($etiqueta) ?></h3>
                <?php if ($vigente): ?>
                    <p class="texto-mutado" style="margin:6px 0 0;">
                        Vigente: <strong>versión <?= e($vigente['Version']) ?></strong><br>
                        desde <?= f_fecha($vigente['FechaVigencia']) ?>
                    </p>
                <?php else: ?>
                    <p class="texto-mutado" style="margin:6px 0 0;">
                        ⚠️ Sin disclaimer vigente. El enlace público de este tipo no podrá recoger
                        consentimientos.
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h3>Todos los disclaimers</h3>

        <div class="tabla-wrap">
            <table class="tabla-datos">
                <thead>
                <tr>
                    <th>Tipo de Persona</th><th>Versión</th><th>Título</th>
                    <th>Estado</th><th>Vigente desde</th><th>Creado por</th><th>Acciones</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($filas)): ?>
                    <tr><td colspan="7" class="tabla-vacia">Todavía no hay disclaimers registrados.</td></tr>
                <?php endif; ?>
                <?php foreach ($filas as $f): ?>
                    <tr>
                        <td><span class="badge badge-neutro"><?= e(TIPOS_PERSONA[$f['TipoPersona']] ?? $f['TipoPersona']) ?></span></td>
                        <td><strong><?= e($f['Version']) ?></strong></td>
                        <td><?= truncar($f['Titulo'] ?: '—', 42) ?></td>
                        <td>
                            <span class="badge <?= $f['Estado'] === 'ACTIVO' ? 'badge-activo' : 'badge-inactivo' ?>">
                                <?= $f['Estado'] === 'ACTIVO' ? 'Vigente' : 'Inactivo' ?>
                            </span>
                        </td>
                        <td><?= $f['Estado'] === 'ACTIVO' ? f_fecha($f['FechaVigencia']) : '—' ?></td>
                        <td class="texto-mutado"><?= e($f['Username'] ?: '—') ?><br><small><?= f_fecha($f['FechaCreacion']) ?></small></td>
                        <td>
                            <div class="flex-gap">
                                <a href="disclaimers.php?accion=editar&id=<?= (int)$f['DisclaimerId'] ?>"
                                   class="btn btn-sm btn-secundario">Editar</a>

                                <?php if ($f['Estado'] !== 'ACTIVO'): ?>
                                    <form method="POST" action="disclaimers.php" style="display:inline;"
                                          onsubmit="return confirm('Este disclaimer pasará a ser el vigente para <?= e(TIPOS_PERSONA[$f['TipoPersona']] ?? '') ?>, y el actual quedará inactivo.\n\n¿Continuar?');">
                                        <?= csrfCampo() ?>
                                        <input type="hidden" name="accion" value="activar">
                                        <input type="hidden" name="id" value="<?= (int)$f['DisclaimerId'] ?>">
                                        <button type="submit" class="btn btn-sm btn-exito">Activar</button>
                                    </form>

                                    <form method="POST" action="disclaimers.php" style="display:inline;"
                                          onsubmit="return confirm('¿Eliminar definitivamente este disclaimer?');">
                                        <?= csrfCampo() ?>
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id" value="<?= (int)$f['DisclaimerId'] ?>">
                                        <button type="submit" class="btn btn-sm btn-peligro">Eliminar</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p class="texto-mutado">
            Solo puede haber un disclaimer vigente por tipo de persona. Para cambiar el texto sin perder
            constancia de lo que aceptaron quienes ya respondieron, cree una versión nueva y actívela en
            lugar de editar la vigente: el consentimiento guarda la versión que cada persona aceptó.
        </p>
    </div>

<?php else: ?>

    <?php $esNuevo = ($accion === 'crear'); ?>

    <form method="POST" action="disclaimers.php" id="formDisclaimer">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="guardar">
        <input type="hidden" name="id" value="<?= $esNuevo ? 0 : (int)($registro['DisclaimerId'] ?? $id) ?>">

        <div class="card">
            <?php encabezadoFormulario($esNuevo ? 'Nuevo disclaimer' : 'Editar disclaimer', 'disclaimers.php'); ?>

            <div class="form-row">
                <div class="form-group">
                    <label for="tipo_persona" class="campo-requerido">Tipo de persona</label>
                    <select name="tipo_persona" id="tipo_persona" required>
                        <option value="">— Seleccione —</option>
                        <?php foreach (TIPOS_PERSONA as $clave => $etiqueta): ?>
                            <option value="<?= e($clave) ?>"
                                <?= ($_POST['tipo_persona'] ?? $registro['TipoPersona'] ?? '') === $clave ? 'selected' : '' ?>>
                                <?= e($etiqueta) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-ayuda">A qué enlace público corresponde este texto.</div>
                </div>

                <div class="form-group" style="flex:0 1 160px;">
                    <label for="version" class="campo-requerido">Versión</label>
                    <input type="text" name="version" id="version" required maxlength="20"
                           placeholder="1.0" value="<?= e($_POST['version'] ?? $registro['Version'] ?? '') ?>">
                </div>

                <div class="form-group" style="flex:2 1 300px;">
                    <label for="titulo">Título</label>
                    <input type="text" name="titulo" id="titulo" maxlength="150"
                           placeholder="Consentimiento para el tratamiento de datos personales"
                           value="<?= e($_POST['titulo'] ?? $registro['Titulo'] ?? '') ?>">
                    <div class="form-ayuda">Encabezado que se muestra sobre el texto.</div>
                </div>
            </div>

            <div class="form-group form-check">
                <label>
                    <input type="checkbox" name="activar" value="1"
                        <?= (!empty($_POST['activar']) || ($registro['Estado'] ?? '') === 'ACTIVO') ? 'checked' : '' ?>>
                    Dejarlo vigente para este tipo de persona
                </label>
                <div class="form-ayuda">
                    Al marcarlo, el disclaimer que estuviera vigente para ese tipo pasa a inactivo.
                </div>
            </div>
        </div>

        <div class="card">
            <h3>Texto del disclaimer</h3>

            <!-- Editor de texto enriquecido (sin librerías externas) -->
            <div class="editor">
                <div class="editor-barra" role="toolbar" aria-label="Formato del texto">
                    <button type="button" data-orden="bold" title="Negrita (Ctrl+B)"><strong>N</strong></button>
                    <button type="button" data-orden="italic" title="Cursiva (Ctrl+I)"><em>C</em></button>
                    <button type="button" data-orden="underline" title="Subrayado (Ctrl+U)"><u>S</u></button>
                    <span class="editor-separador"></span>
                    <button type="button" data-bloque="p" title="Párrafo normal">Párrafo</button>
                    <button type="button" data-bloque="h2" title="Título">Título</button>
                    <button type="button" data-bloque="h3" title="Subtítulo">Subtítulo</button>
                    <span class="editor-separador"></span>
                    <button type="button" data-orden="insertUnorderedList" title="Lista con viñetas">• Lista</button>
                    <button type="button" data-orden="insertOrderedList" title="Lista numerada">1. Lista</button>
                    <span class="editor-separador"></span>
                    <button type="button" id="btnEnlace" title="Insertar enlace">🔗 Enlace</button>
                    <button type="button" data-orden="removeFormat" title="Quitar formato">✕ Formato</button>
                    <span class="editor-separador"></span>
                    <button type="button" data-orden="undo" title="Deshacer (Ctrl+Z)">↶</button>
                    <button type="button" data-orden="redo" title="Rehacer (Ctrl+Y)">↷</button>
                </div>

                <div class="editor-area" id="editorArea" contenteditable="true" role="textbox"
                     aria-multiline="true" aria-label="Texto del disclaimer"><?= $_POST['texto'] ?? $registro['Texto'] ?? '<p></p>' ?></div>
            </div>

            <textarea name="texto" id="campoTexto" hidden></textarea>

            <p class="form-ayuda">
                Escriba como en un procesador de texto. Al guardar, el sistema conserva solo el formato
                permitido (párrafos, negrita, cursiva, subrayado, títulos, listas y enlaces) y descarta
                cualquier otro contenido.
            </p>

            <div class="flex-gap" style="margin-top:16px;">
                <button type="submit" class="btn btn-primario">💾 Guardar disclaimer</button>
                <a href="disclaimers.php" class="btn btn-secundario">Cancelar</a>
                <button type="button" class="btn btn-secundario" id="btnVista">👁️ Vista previa</button>
            </div>
        </div>

        <div class="card" id="cajaVista" hidden>
            <h3>Así lo verá la persona</h3>
            <div class="vista-previa" id="vistaPrevia"></div>
        </div>
    </form>

    <script>
    (function () {
        'use strict';

        var area   = document.getElementById('editorArea');
        var campo  = document.getElementById('campoTexto');
        var formulario = document.getElementById('formDisclaimer');
        if (!area || !campo) { return; }

        function ejecutar(orden, valor) {
            area.focus();
            document.execCommand(orden, false, valor || null);
        }

        document.querySelectorAll('.editor-barra button[data-orden]').forEach(function (b) {
            b.addEventListener('click', function () { ejecutar(b.getAttribute('data-orden')); });
        });

        document.querySelectorAll('.editor-barra button[data-bloque]').forEach(function (b) {
            b.addEventListener('click', function () {
                ejecutar('formatBlock', '<' + b.getAttribute('data-bloque') + '>');
            });
        });

        document.getElementById('btnEnlace').addEventListener('click', function () {
            var url = window.prompt('Dirección del enlace:', 'https://');
            if (!url) { return; }
            if (!/^(https?:\/\/|mailto:)/i.test(url)) {
                window.alert('El enlace debe empezar con http://, https:// o mailto:');
                return;
            }
            ejecutar('createLink', url);
        });

        // El contenido pegado entra como texto plano: así no se cuela formato ajeno
        area.addEventListener('paste', function (evento) {
            evento.preventDefault();
            var texto = (evento.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, texto);
        });

        // Vista previa
        var cajaVista = document.getElementById('cajaVista');
        var vista     = document.getElementById('vistaPrevia');
        document.getElementById('btnVista').addEventListener('click', function () {
            vista.innerHTML = area.innerHTML;
            cajaVista.hidden = !cajaVista.hidden;
            if (!cajaVista.hidden) { cajaVista.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });

        // El contenido del editor viaja en el campo oculto
        formulario.addEventListener('submit', function (evento) {
            campo.value = area.innerHTML;

            if (area.textContent.trim() === '') {
                evento.preventDefault();
                window.alert('Escriba el texto del disclaimer antes de guardar.');
                area.focus();
            }
        });
    })();
    </script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
