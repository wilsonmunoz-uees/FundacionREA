<?php
/**
 * modules/carga_informacion.php
 * -----------------------------------------------------------------------------
 * Carga de Información del padrón de la institución. Opción reservada a
 * SuperAdmin, y única vía de alta de empleados, estudiantes y proveedores.
 *
 * La carga es DIFERENCIAL: no borra nada. Lo que no está se da de alta, y lo que
 * ya está se actualiza con los datos del archivo. Puede repetirse tantas veces
 * como haga falta.
 *
 * La pantalla trabaja en dos pasos:
 *
 *   Paso 1 · Validar   El archivo se sube y se analiza SIN tocar la base. Se
 *                      muestra cuántos registros trae, qué errores hay y —lo
 *                      importante— cuántos serían altas y cuántos cambios.
 *   Paso 2 · Procesar  Solo aparece si el archivo está limpio. Exige escribir la
 *                      confirmación.
 *
 * El archivo se guarda en la sesión entre ambos pasos (codificado en base64),
 * de modo que no hace falta volver a seleccionarlo para confirmar.
 * -----------------------------------------------------------------------------
 */
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('carga_informacion');

/** Tope del archivo aceptado por la pantalla: el mismo que aplica la API. */
const CARGA_MAX_BYTES = 8 * 1024 * 1024;
/** Texto que el usuario debe escribir para habilitar el procesamiento. */
const CARGA_CONFIRMACION = 'CARGAR INFORMACION';

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
    $ruta = __DIR__ . '/../assets/plantillas/carga_informacion_rea.xlsx';
    if (is_file($ruta)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="carga_informacion_rea.xlsx"');
        header('Content-Length: ' . filesize($ruta));
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($ruta);
        exit;
    }
    flashSet('error', 'No se encontró la plantilla en el servidor.');
    redirigir('carga_informacion.php');
}

/* ---------------------------------------------------------------------------
   Paso 1 · Validar el archivo
   --------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'validar') {
    unset($_SESSION['carga_archivo']);

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
        } elseif ((int)$subido['size'] > CARGA_MAX_BYTES) {
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

                $respuesta = apiPost('carga-informacion/previsualizar', $paquete);

                if ($respuesta['ok']) {
                    $resumen      = apiDatos($respuesta, []);
                    $advertencias = $resumen['advertencias'] ?? [];
                    $errores      = $resumen['errores'] ?? [];

                    // Se conserva para el paso 2, sin volver a pedir el archivo
                    if (!empty($resumen['puede_procesar'])) {
                        $_SESSION['carga_archivo'] = $paquete;
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
    } elseif (empty($_SESSION['carga_archivo'])) {
        $errores[] = 'El archivo validado ya no está disponible. Vuelva a subirlo y validarlo.';
    } elseif (strtoupper(trim($_POST['confirmacion'] ?? '')) !== CARGA_CONFIRMACION) {
        $errores[] = 'Escriba exactamente ' . CARGA_CONFIRMACION . ' para confirmar la operación.';
    } else {
        $paquete = $_SESSION['carga_archivo'];
        $paquete['confirmacion'] = CARGA_CONFIRMACION;

        $respuesta = apiPost('carga-informacion/procesar', $paquete);

        unset($_SESSION['carga_archivo']);

        if ($respuesta['ok']) {
            $resultado = apiDatos($respuesta, []);
        } else {
            $errores = apiErrores($respuesta);
        }
    }

    // Si falló la confirmación, el resumen se vuelve a mostrar para no perder el paso
    if ($errores && !empty($_SESSION['carga_archivo'])) {
        $respuestaResumen = apiPost('carga-informacion/previsualizar', $_SESSION['carga_archivo']);
        if ($respuestaResumen['ok']) {
            $resumen      = apiDatos($respuestaResumen, []);
            $advertencias = $resumen['advertencias'] ?? [];
        }
    }
}

/** Etiquetas de las cuatro tablas del padrón, en el orden en que se muestran. */
$grupos = [
    'personas'    => 'Personas del padrón',
    'empleados'   => 'Empleados',
    'estudiantes' => 'Estudiantes',
    'proveedores' => 'Proveedores',
];

$pageTitle  = 'Carga de Información';
$pageDesc   = 'Alta y actualización del padrón de la institución a partir de la plantilla Excel';
$breadcrumb = [
    ['label' => 'Registro de Datos', 'url' => null],
    ['label' => 'Carga de Información', 'url' => null],
];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>📥 Carga de Información</h1>
        <p>
            Incorpora empleados, estudiantes, representantes y proveedores de
            <strong><?= e($institucion) ?></strong> a partir de la plantilla Excel.
        </p>
    </div>
    <div class="flex-gap">
        <a href="carga_informacion.php?accion=plantilla" class="btn btn-secundario">⬇️ Descargar plantilla</a>
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

<!-- ==================== Cómo funciona ==================== -->
<div class="card carga-aviso">
    <h3>ℹ️ Cómo trabaja esta carga</h3>
    <p>
        La carga es <strong>diferencial</strong>: se compara el archivo con lo que la institución ya
        tiene y <strong>no se elimina nada</strong>. Puede ejecutarla cuantas veces necesite.
    </p>

    <div class="form-row" style="align-items:stretch;">
        <div class="carga-columna carga-alta">
            <h4>Si la persona no consta</h4>
            <ul class="lista-simple">
                <li>Se crea su ficha en el padrón</li>
                <li>Se crea su vínculo como empleado, estudiante o proveedor</li>
                <li>Queda en estado <strong>ACTIVO</strong></li>
            </ul>
        </div>
        <div class="carga-columna carga-cambio">
            <h4>Si la persona ya consta</h4>
            <ul class="lista-simple">
                <li>Se actualizan nombres, correo y teléfono con lo que traiga el archivo</li>
                <li>Se actualizan el código, el representante y la razón social</li>
                <li>Vuelve a quedar <strong>ACTIVO</strong>, aunque estuviera inactiva</li>
            </ul>
        </div>
        <div class="carga-columna carga-conserva">
            <h4>No se toca</h4>
            <ul class="lista-simple">
                <li>Consentimientos y su historial: siguen siendo válidos</li>
                <li>Quien no aparezca en el archivo, se queda como está</li>
                <li>Usuarios, roles, permisos y catálogos</li>
                <li>Los datos de las demás instituciones de la red</li>
            </ul>
        </div>
    </div>

    <p class="texto-mutado">
        Una celda vacía nunca borra lo que ya estaba grabado. Para dar de baja a alguien, use el
        botón <em>Inactivar</em> de su pantalla.
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
    <?php $aplicado = $resultado['aplicado'] ?? []; ?>
    <div class="alerta alerta-exito">
        <strong>Carga ejecutada correctamente</strong> desde el archivo
        <em><?= e($resultado['archivo'] ?? '') ?></em>.
    </div>

    <div class="card">
        <h3>✅ Lo que se aplicó</h3>
        <div class="tabla-wrap">
            <table class="tabla-datos">
                <thead>
                <tr>
                    <th>Concepto</th>
                    <th style="text-align:right;">Altas</th>
                    <th style="text-align:right;">Actualizaciones</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($grupos as $clave => $etiqueta): ?>
                    <tr>
                        <td><?= e($etiqueta) ?></td>
                        <td style="text-align:right;"><strong><?= (int)($aplicado[$clave]['altas'] ?? 0) ?></strong></td>
                        <td style="text-align:right;"><strong><?= (int)($aplicado[$clave]['actualizaciones'] ?? 0) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex-gap">
        <a href="empleados.php" class="btn btn-primario">Ver lo cargado</a>
        <a href="carga_informacion.php" class="btn btn-secundario">Realizar otra carga</a>
    </div>

<?php else: ?>

    <!-- ==================== Paso 1 · Subir y validar ==================== -->
    <div class="card">
        <h3>Paso 1 · Suba el archivo y valídelo</h3>
        <p class="texto-mutado">
            Use la plantilla de esta pantalla. El archivo se revisa completo antes de tocar la base:
            si algo está mal, se le indica la hoja y la fila exactas y no se modifica ningún dato.
        </p>

        <form method="POST" action="carga_informacion.php" enctype="multipart/form-data">
            <?= csrfCampo() ?>
            <input type="hidden" name="accion" value="validar">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= CARGA_MAX_BYTES ?>">

            <div class="form-row">
                <div class="form-group" style="flex:2 1 320px;">
                    <label for="archivo" class="campo-requerido">Archivo de la plantilla (.xlsx)</label>
                    <input type="file" name="archivo" id="archivo" accept=".xlsx" required>
                    <div class="form-ayuda">
                        Tamaño máximo: 8 MB. Las filas de ejemplo se ignoran solas.
                        Todo lo que se carga queda <strong>activo</strong>.
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

            <h4>Cómo quedaría <?= e($institucion) ?></h4>
            <div class="tabla-wrap">
                <table class="tabla-datos">
                    <thead>
                    <tr>
                        <th>Concepto</th>
                        <th style="text-align:right;">Tiene hoy</th>
                        <th style="text-align:right;">Altas</th>
                        <th style="text-align:right;">Actualizaciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php
                    $impacto    = $resumen['impacto'] ?? [];
                    $inventario = $resumen['inventario'] ?? [];
                    foreach ($grupos as $clave => $etiqueta):
                        $altas   = (int)($impacto[$clave]['altas'] ?? 0);
                        $cambios = (int)($impacto[$clave]['actualizaciones'] ?? 0);
                    ?>
                        <tr>
                            <td><?= e($etiqueta) ?></td>
                            <td style="text-align:right;"><?= (int)($inventario[$clave] ?? 0) ?></td>
                            <td style="text-align:right;">
                                <strong class="carga-numero-alta"><?= $altas > 0 ? '+' . $altas : '0' ?></strong>
                            </td>
                            <td style="text-align:right;">
                                <strong class="carga-numero-cambio"><?= $cambios ?></strong>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="form-ayuda">
                Ninguna fila se elimina. Lo que hoy tiene la institución y no viene en el archivo
                permanece igual.
            </p>
        </div>

        <?php if (!empty($resumen['puede_procesar'])): ?>
            <!-- ==================== Paso 2 · Confirmar y procesar ==================== -->
            <?php
            $totalAltas = 0;
            $totalCambios = 0;
            foreach (['empleados', 'estudiantes', 'proveedores'] as $clave) {
                $totalAltas   += (int)($resumen['impacto'][$clave]['altas'] ?? 0);
                $totalCambios += (int)($resumen['impacto'][$clave]['actualizaciones'] ?? 0);
            }
            ?>
            <div class="card carga-confirmar">
                <h3>Paso 2 · Confirme para procesar</h3>
                <p>
                    El archivo está correcto. Al procesar se darán de alta
                    <strong><?= $totalAltas ?></strong> vínculo(s) y se actualizarán
                    <strong><?= $totalCambios ?></strong>. No se eliminará ningún dato.
                </p>

                <form method="POST" action="carga_informacion.php" id="formCarga">
                    <?= csrfCampo() ?>
                    <input type="hidden" name="accion" value="procesar">

                    <div class="form-group" style="max-width:360px;">
                        <label for="confirmacion" class="campo-requerido">
                            Escriba <code><?= CARGA_CONFIRMACION ?></code> para habilitar el botón
                        </label>
                        <input type="text" name="confirmacion" id="confirmacion" autocomplete="off"
                               placeholder="<?= CARGA_CONFIRMACION ?>">
                    </div>

                    <button type="submit" class="btn btn-primario" id="btnProcesar" disabled>
                        📥 Cargar información
                    </button>
                    <a href="carga_informacion.php" class="btn btn-secundario">Cancelar</a>
                </form>
            </div>

            <script>
            (function () {
                var forma   = document.getElementById('formCarga');
                var texto   = document.getElementById('confirmacion');
                var boton   = document.getElementById('btnProcesar');
                var CLAVE   = <?= json_encode(CARGA_CONFIRMACION) ?>;
                var enviado = false;

                function revisar() {
                    boton.disabled = texto.value.trim().toUpperCase() !== CLAVE;
                }

                texto.addEventListener('input', revisar);
                revisar();

                forma.addEventListener('submit', function () {
                    if (enviado) { return; }
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
.carga-aviso {
    border-left: 5px solid #1565c0;
    background: #f7fbff;
}
.carga-aviso h3 { color: #0d47a1; }
.carga-columna {
    flex: 1 1 260px;
    border-radius: 8px;
    padding: 12px 16px;
}
.carga-columna h4 { margin: 0 0 8px; font-size: .95rem; }
.carga-alta      { background: #e9f7ef; border: 1px solid #bfe3cc; }
.carga-alta h4   { color: #1b5e20; }
.carga-cambio    { background: #fff8e1; border: 1px solid #f0dca8; }
.carga-cambio h4 { color: #8a6100; }
.carga-conserva  { background: #eef2f7; border: 1px solid #d3dbe5; }
.carga-conserva h4 { color: #37474f; }
.carga-confirmar { border-left: 5px solid #1565c0; }
.carga-numero-alta   { color: #1b5e20; }
.carga-numero-cambio { color: #8a6100; }
</style>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
