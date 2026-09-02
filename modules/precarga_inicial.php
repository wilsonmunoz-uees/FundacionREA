<?php
/**
 * modules/precarga_inicial.php
 * -----------------------------------------------------------------------------
 * PreCarga Inicial del padrón de la institución. Opción reservada a SuperAdmin.
 *
 * La pantalla trabaja en dos pasos, para que nadie encere una base por error:
 *
 *   Paso 1 · Validar   El archivo se sube y se analiza SIN tocar la base. Se
 *                      muestra cuántos registros trae, qué errores hay y qué
 *                      se eliminaría si se continúa.
 *   Paso 2 · Procesar  Solo aparece si el archivo está limpio. Exige marcar el
 *                      disclaimer de advertencia, escribir la confirmación y
 *                      aceptar un último aviso del navegador.
 *
 * El archivo se guarda en la sesión entre ambos pasos (codificado en base64),
 * de modo que no hace falta volver a seleccionarlo para confirmar.
 * -----------------------------------------------------------------------------
 */
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('precarga');

/** Tope del archivo aceptado por la pantalla: el mismo que aplica la API. */
const PRECARGA_MAX_BYTES = 8 * 1024 * 1024;
/** Texto que el usuario debe escribir para habilitar el procesamiento. */
const PRECARGA_CONFIRMACION = 'ENCERAR Y CARGAR';

$errores      = [];
$advertencias = [];
$resumen      = null;      // resultado de la validación
$resultado    = null;      // resultado del procesamiento
$institucion  = $_SESSION['institucion_nombre'] ?? ('institución #' . institucionActual());

/* Parentescos que la plantilla ya trae agrupados pero que la base todavía tiene
   sueltos. Conviene decirlo ANTES de subir el archivo: con la base sin migrar,
   toda fila de la hoja Estudiantes que diga ABUELO/A o TIO/A sería rechazada, y
   descubrirlo al validar tres mil filas es una pérdida de tiempo evitable. */
$relacionesRetiradas = apiMeta(apiGet('estudiantes', ['por_pagina' => 1]), 'relaciones_retiradas', []);
$faltaMigrar = is_array($relacionesRetiradas) && $relacionesRetiradas !== [];

/* ---------------------------------------------------------------------------
   Descarga de la plantilla
   --------------------------------------------------------------------------- */
if (($_GET['accion'] ?? '') === 'plantilla') {
    $ruta = __DIR__ . '/../assets/plantillas/precarga_inicial_rea.xlsx';
    if (is_file($ruta)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="precarga_inicial_rea.xlsx"');
        header('Content-Length: ' . filesize($ruta));
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($ruta);
        exit;
    }
    flashSet('error', 'No se encontró la plantilla en el servidor.');
    redirigir('precarga_inicial.php');
}

/* ---------------------------------------------------------------------------
   Paso 1 · Validar el archivo
   --------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'validar') {
    unset($_SESSION['precarga_archivo']);

    if (!csrfValido()) {
        $errores[] = 'La sesión expiró o el formulario no es válido. Vuelva a intentarlo.';
    } else {
        $subido = $_FILES['archivo'] ?? null;

        if (!$subido || ($subido['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errores[] = match ((int)($subido['error'] ?? UPLOAD_ERR_NO_FILE)) {
                UPLOAD_ERR_NO_FILE   => 'Seleccione el archivo Excel de la plantilla.',
                UPLOAD_ERR_INI_SIZE,
                UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño permitido por el servidor.',
                default              => 'No se pudo recibir el archivo. Vuelva a intentarlo.',
            };
        } elseif ((int)$subido['size'] > PRECARGA_MAX_BYTES) {
            $errores[] = 'El archivo supera el tamaño máximo permitido (8 MB).';
        } elseif (strtolower((string)pathinfo((string)$subido['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            $errores[] = 'El archivo debe ser un Excel con extensión .xlsx.';
        } else {
            $contenido = file_get_contents($subido['tmp_name']);

            if ($contenido === false || $contenido === '') {
                $errores[] = 'El archivo llegó vacío. Vuelva a intentarlo.';
            } else {
                $paquete = [
                    'nombre_archivo' => basename((string)$subido['name']),
                    'archivo_base64' => base64_encode($contenido),
                ];

                $respuesta = apiPost('precarga/previsualizar', $paquete);

                if ($respuesta['ok']) {
                    $resumen      = apiDatos($respuesta, []);
                    $advertencias = $resumen['advertencias'] ?? [];
                    $errores      = $resumen['errores'] ?? [];

                    // Se conserva para el paso 2, sin volver a pedir el archivo
                    if (!empty($resumen['puede_procesar'])) {
                        $_SESSION['precarga_archivo'] = $paquete;
                    }
                } else {
                    $errores = apiErrores($respuesta);
                }
            }
        }
    }
}

/* ---------------------------------------------------------------------------
   Paso 2 · Procesar
   --------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'procesar') {
    if (!csrfValido()) {
        $errores[] = 'La sesión expiró o el formulario no es válido. Vuelva a intentarlo.';
    } elseif (empty($_SESSION['precarga_archivo'])) {
        $errores[] = 'El archivo validado ya no está disponible. Vuelva a subirlo y validarlo.';
    } elseif (empty($_POST['acepto'])) {
        $errores[] = 'Debe marcar la casilla de aceptación de la advertencia.';
    } elseif (strtoupper(trim($_POST['confirmacion'] ?? '')) !== PRECARGA_CONFIRMACION) {
        $errores[] = 'Escriba exactamente ' . PRECARGA_CONFIRMACION . ' para confirmar la operación.';
    } else {
        $paquete = $_SESSION['precarga_archivo'];
        $paquete['confirmacion'] = PRECARGA_CONFIRMACION;

        $respuesta = apiPost('precarga/procesar', $paquete);

        unset($_SESSION['precarga_archivo']);

        if ($respuesta['ok']) {
            $resultado = apiDatos($respuesta, []);
        } else {
            $errores = apiErrores($respuesta);
        }
    }

    // Si falló la confirmación, el resumen se vuelve a mostrar para no perder el paso
    if ($errores && !empty($_SESSION['precarga_archivo'])) {
        $respuestaResumen = apiPost('precarga/previsualizar', $_SESSION['precarga_archivo']);
        if ($respuestaResumen['ok']) {
            $resumen      = apiDatos($respuestaResumen, []);
            $advertencias = $resumen['advertencias'] ?? [];
        }
    }
}

$pageTitle  = 'PreCarga Inicial';
$pageDesc   = 'Carga masiva del padrón de la institución a partir de la plantilla Excel';
$breadcrumb = [
    ['label' => 'Registro de Datos', 'url' => null],
    ['label' => 'PreCarga Inicial', 'url' => null],
];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>📥 PreCarga Inicial</h1>
        <p>
            Carga en un solo paso empleados, estudiantes, representantes y proveedores de
            <strong><?= e($institucion) ?></strong> a partir de la plantilla Excel.
        </p>
    </div>
    <div class="flex-gap">
        <a href="precarga_inicial.php?accion=plantilla" class="btn btn-secundario">⬇️ Descargar plantilla</a>
    </div>
</div>

<?php if ($faltaMigrar): ?>
    <div class="alerta alerta-advertencia">
        <strong>Ejecute la migración de parentescos antes de cargar.</strong>
        La plantilla ya trae la lista agrupada —<strong>ABUELO/A</strong>, <strong>TIO/A</strong>,
        <strong>HERMANO/A</strong>—, pero la base todavía acepta
        <?= e(implode(', ', array_keys($relacionesRetiradas))) ?> por separado, de modo que
        rechazaría esas filas.
        <div class="form-ayuda" style="margin-top:6px;">
            Ejecute <code>08_ALTER_relacion_representante.sql</code> sobre la base y vuelva a
            esta pantalla. Convierte también los registros ya grabados.
        </div>
    </div>
<?php endif; ?>

<!-- ==================== Disclaimer de advertencia ==================== -->
<div class="card precarga-aviso">
    <h3>⚠️ Antes de continuar, lea esta advertencia</h3>
    <p>
        La PreCarga Inicial <strong>ENCERA todos los datos de la institución con la que usted está
        conectado</strong> y los reemplaza por el contenido del archivo. La operación
        <strong>no se puede deshacer</strong>.
    </p>

    <div class="form-row" style="align-items:stretch;">
        <div class="precarga-columna precarga-borra">
            <h4>Se elimina de <?= e($institucion) ?></h4>
            <ul class="lista-simple">
                <li>Empleados, estudiantes y proveedores</li>
                <li>Consentimientos y su historial completo</li>
                <li>Tipos de dato autorizados en cada consentimiento</li>
                <li>Las personas que queden sin ningún vínculo</li>
            </ul>
        </div>
        <div class="precarga-columna precarga-conserva">
            <h4>No se toca</h4>
            <ul class="lista-simple">
                <li>Usuarios del sistema, roles y permisos</li>
                <li>Catálogos de finalidades y tipos de dato</li>
                <li>Disclaimers y configuración de correo</li>
                <li>Las personas con cuenta de usuario</li>
                <li>Los datos de las demás instituciones de la red</li>
            </ul>
        </div>
    </div>

    <p class="texto-mutado">
        Se recomienda respaldar la base de datos antes de ejecutar esta opción.
        Úsela solo para poblar un sistema nuevo o para reiniciar una carga mal hecha.
    </p>
</div>

<?php if ($errores): ?>
    <div class="alerta alerta-error">
        <strong>Revise lo siguiente antes de continuar:</strong>
        <ul class="lista-simple" style="margin-top:6px;">
            <?php foreach ($errores as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($resultado !== null): ?>
    <!-- ==================== Resultado de la carga ==================== -->
    <div class="alerta alerta-exito">
        <strong>PreCarga ejecutada correctamente</strong> desde el archivo
        <em><?= e($resultado['archivo'] ?? '') ?></em>.
    </div>

    <div class="form-row" style="align-items:stretch;">
        <div class="card" style="flex:1 1 320px;">
            <h3>🗑️ Datos eliminados</h3>
            <table class="tabla-datos">
                <tbody>
                <?php foreach (($resultado['eliminado'] ?? []) as $clave => $valor): ?>
                    <tr>
                        <td><?= e(ucfirst(str_replace('_', ' ', (string)$clave))) ?></td>
                        <td style="text-align:right;"><strong><?= (int)$valor ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card" style="flex:1 1 320px;">
            <h3>✅ Datos cargados</h3>
            <table class="tabla-datos">
                <tbody>
                <?php foreach (($resultado['cargado'] ?? []) as $clave => $valor): ?>
                    <tr>
                        <td><?= e(ucfirst(str_replace('_', ' ', (string)$clave))) ?></td>
                        <td style="text-align:right;"><strong><?= (int)$valor ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex-gap">
        <a href="empleados.php" class="btn btn-primario">Ver lo cargado</a>
        <a href="precarga_inicial.php" class="btn btn-secundario">Realizar otra carga</a>
    </div>

<?php else: ?>

    <!-- ==================== Paso 1 · Subir y validar ==================== -->
    <div class="card">
        <h3>Paso 1 · Suba el archivo y valídelo</h3>
        <p class="texto-mutado">
            Use la plantilla de esta pantalla. El archivo se revisa completo antes de tocar la base:
            si algo está mal, se le indica la hoja y la fila exactas y no se modifica ningún dato.
        </p>

        <form method="POST" action="precarga_inicial.php" enctype="multipart/form-data">
            <?= csrfCampo() ?>
            <input type="hidden" name="accion" value="validar">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= PRECARGA_MAX_BYTES ?>">

            <div class="form-row">
                <div class="form-group" style="flex:2 1 320px;">
                    <label for="archivo" class="campo-requerido">Archivo de la plantilla (.xlsx)</label>
                    <input type="file" name="archivo" id="archivo" accept=".xlsx" required>
                    <div class="form-ayuda">
                        Tamaño máximo: 8 MB. Las filas de ejemplo se ignoran solas.
                        Todo lo que se carga entra <strong>activo</strong>.
                    </div>
                </div>
                <div class="form-group" style="flex:0 1 200px; align-self:flex-end;">
                    <button type="submit" class="btn btn-primario">🔍 Validar archivo</button>
                </div>
            </div>
        </form>
    </div>

    <?php if ($resumen !== null): ?>
        <!-- ==================== Resumen de la validación ==================== -->
        <div class="card">
            <h3>Resultado de la validación · <em><?= e($resumen['archivo'] ?? '') ?></em></h3>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-valor"><?= (int)($resumen['resumen']['empleados'] ?? 0) ?></div>
                    <div class="kpi-label">Empleados</div>
                </div>
                <div class="kpi-card kpi-alt-2">
                    <div class="kpi-valor"><?= (int)($resumen['resumen']['estudiantes'] ?? 0) ?></div>
                    <div class="kpi-label">Estudiantes</div>
                </div>
                <div class="kpi-card kpi-alt-1">
                    <div class="kpi-valor"><?= (int)($resumen['resumen']['representantes'] ?? 0) ?></div>
                    <div class="kpi-label">Representantes</div>
                </div>
                <div class="kpi-card kpi-alt-3">
                    <div class="kpi-valor"><?= (int)($resumen['resumen']['proveedores'] ?? 0) ?></div>
                    <div class="kpi-label">Proveedores</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-valor"><?= (int)($resumen['resumen']['personas'] ?? 0) ?></div>
                    <div class="kpi-label">Personas distintas</div>
                </div>
            </div>

            <?php if ($advertencias): ?>
                <div class="alerta alerta-advertencia">
                    <strong>Avisos (no impiden la carga):</strong>
                    <ul class="lista-simple" style="margin-top:6px;">
                        <?php foreach ($advertencias as $aviso): ?>
                            <li><?= e($aviso) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <h4>Lo que se eliminará de <?= e($institucion) ?></h4>
            <div class="tabla-wrap">
                <table class="tabla-datos">
                    <thead>
                    <tr><th>Concepto</th><th style="text-align:right;">Registros actuales</th></tr>
                    </thead>
                    <tbody>
                    <?php
                    $etiquetas = [
                        'empleados'            => 'Empleados',
                        'estudiantes'          => 'Estudiantes',
                        'proveedores'          => 'Proveedores',
                        'consentimientos'      => 'Consentimientos',
                        'historial'            => 'Movimientos del historial',
                        'personas'             => 'Personas que quedarán sin vínculo',
                    ];
                    $inventario = $resumen['se_eliminara'] ?? [];
                    foreach ($etiquetas as $clave => $etiqueta):
                    ?>
                        <tr>
                            <td><?= e($etiqueta) ?></td>
                            <td style="text-align:right;"><strong><?= (int)($inventario[$clave] ?? 0) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!empty($inventario['personas_con_usuario'])): ?>
                        <tr>
                            <td class="texto-mutado">Personas con cuenta de usuario (se conservan)</td>
                            <td style="text-align:right;" class="texto-mutado">
                                <?= (int)$inventario['personas_con_usuario'] ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php if (!empty($inventario['personas_en_otra_institucion'])): ?>
                        <tr>
                            <td class="texto-mutado">
                                Personas que otra institución todavía usa (se conservan)
                            </td>
                            <td style="text-align:right;" class="texto-mutado">
                                <?= (int)$inventario['personas_en_otra_institucion'] ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($resumen['puede_procesar'])): ?>
            <!-- ==================== Paso 2 · Confirmar y procesar ==================== -->
            <div class="card precarga-confirmar">
                <h3>Paso 2 · Confirme para procesar</h3>
                <p>
                    El archivo está correcto. Al procesar se eliminarán los datos indicados arriba y se
                    cargarán los <strong><?= (int)($resumen['resumen']['total'] ?? 0) ?></strong> registros del archivo.
                    <strong>Esta acción no se puede deshacer.</strong>
                </p>

                <form method="POST" action="precarga_inicial.php" id="formPrecarga">
                    <?= csrfCampo() ?>
                    <input type="hidden" name="accion" value="procesar">

                    <div class="form-group form-check">
                        <label>
                            <input type="checkbox" name="acepto" id="acepto" value="1">
                            He leído la advertencia y entiendo que se eliminarán los datos actuales de
                            <?= e($institucion) ?>, sin posibilidad de recuperarlos.
                        </label>
                    </div>

                    <div class="form-group" style="max-width:360px;">
                        <label for="confirmacion" class="campo-requerido">
                            Escriba <code><?= PRECARGA_CONFIRMACION ?></code> para habilitar el botón
                        </label>
                        <input type="text" name="confirmacion" id="confirmacion" autocomplete="off"
                               placeholder="<?= PRECARGA_CONFIRMACION ?>">
                    </div>

                    <button type="submit" class="btn btn-peligro" id="btnProcesar" disabled>
                        🗑️ Encerar y cargar
                    </button>
                    <a href="precarga_inicial.php" class="btn btn-secundario">Cancelar</a>
                </form>
            </div>

            <script>
            (function () {
                var forma   = document.getElementById('formPrecarga');
                var acepto  = document.getElementById('acepto');
                var texto   = document.getElementById('confirmacion');
                var boton   = document.getElementById('btnProcesar');
                var CLAVE   = <?= json_encode(PRECARGA_CONFIRMACION) ?>;
                var enviado = false;

                function revisar() {
                    boton.disabled = !(acepto.checked && texto.value.trim().toUpperCase() === CLAVE);
                }

                acepto.addEventListener('change', revisar);
                texto.addEventListener('input', revisar);
                revisar();

                // Última confirmación, ya con el botón habilitado
                forma.addEventListener('submit', function (evento) {
                    if (enviado) { return; }

                    var seguro = window.confirm(
                        'Se eliminarán definitivamente los datos actuales de la institución y se ' +
                        'cargarán los del archivo.\n\nEsta acción NO se puede deshacer.\n\n¿Desea continuar?'
                    );

                    if (!seguro) {
                        evento.preventDefault();
                        return;
                    }

                    enviado = true;
                    boton.textContent = 'Procesando…';
                    // El botón se deshabilita en el siguiente ciclo, para que su
                    // valor viaje con el formulario.
                    window.setTimeout(function () { boton.disabled = true; }, 0);
                });
            })();
            </script>
        <?php else: ?>
            <div class="alerta alerta-advertencia">
                Corrija los errores señalados en el archivo y vuelva a validarlo. No se ha modificado ningún dato.
            </div>
        <?php endif; ?>
    <?php endif; ?>

<?php endif; ?>

<style>
.precarga-aviso {
    border-left: 5px solid #c62828;
    background: #fff8f8;
}
.precarga-aviso h3 { color: #b71c1c; }
.precarga-columna {
    flex: 1 1 300px;
    border-radius: 8px;
    padding: 12px 16px;
}
.precarga-columna h4 { margin: 0 0 8px; font-size: .95rem; }
.precarga-borra    { background: #fdecea; border: 1px solid #f5c6c2; }
.precarga-borra h4 { color: #b71c1c; }
.precarga-conserva { background: #e9f7ef; border: 1px solid #bfe3cc; }
.precarga-conserva h4 { color: #1b5e20; }
.precarga-confirmar { border-left: 5px solid #ef6c00; }
</style>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
