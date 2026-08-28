<?php
/**
 * modules/carga_inicial.php
 * -----------------------------------------------------------------------------
 * Carga inicial de empleados, estudiantes, representantes y proveedores a
 * partir de la plantilla Excel.
 *
 * El proceso tiene tres pasos, para que nadie encere la base por accidente:
 *
 *   1. Subir el archivo. Se lee aquí con includes/lector_xlsx.php y se envía a
 *      la API para su validación; no se escribe nada todavía.
 *   2. Revisar el resumen, los errores y la advertencia de lo que se borrará.
 *   3. Confirmar la advertencia y ejecutar. Recién ahí la API encera y carga.
 *
 * Entre el paso 1 y el 3 el contenido leído viaja en la sesión, de modo que no
 * haya que subir el archivo dos veces.
 * -----------------------------------------------------------------------------
 */
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lector_xlsx.php';

requireAcceso('carga_inicial');
$institucionId = institucionActual();

const HOJAS_ESPERADAS = ['Empleados', 'Estudiantes', 'Representantes', 'Proveedores'];
const CLAVE_SESION    = 'carga_inicial';
const MAX_MB          = 8;

$paso        = 'inicio';   // inicio | revision | resultado
$validacion  = null;
$resultado   = null;
$errorCarga  = '';

/* ---------------------------------------------------------------------------
   Paso 1: subida y validación
   --------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'validar') {
    unset($_SESSION[CLAVE_SESION]);

    $archivo = $_FILES['archivo'] ?? null;

    if (!csrfValido()) {
        $errorCarga = 'La sesión expiró o el formulario no es válido. Vuelva a intentarlo.';
    } elseif (!$archivo || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
        $errorCarga = 'Seleccione el archivo Excel que desea cargar.';
    } elseif ($archivo['error'] === UPLOAD_ERR_INI_SIZE || $archivo['error'] === UPLOAD_ERR_FORM_SIZE) {
        $errorCarga = 'El archivo supera el tamaño permitido por el servidor (' . MAX_MB . ' MB).';
    } elseif ($archivo['error'] !== UPLOAD_ERR_OK) {
        $errorCarga = 'No se pudo recibir el archivo. Vuelva a intentarlo.';
    } elseif ($archivo['size'] > MAX_MB * 1024 * 1024) {
        $errorCarga = 'El archivo pesa más de ' . MAX_MB . ' MB. Divida la carga en varios archivos.';
    } elseif (strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
        $errorCarga = 'El archivo debe estar en formato Excel (.xlsx). '
                    . 'Si lo tiene en .xls o .csv, ábralo en Excel y use «Guardar como → Libro de Excel (*.xlsx)».';
    } else {
        try {
            $libro = new LectorXlsx($archivo['tmp_name']);

            $faltantes = [];
            foreach (HOJAS_ESPERADAS as $hoja) {
                if (!$libro->tieneHoja($hoja)) {
                    $faltantes[] = $hoja;
                }
            }

            if ($faltantes) {
                $errorCarga = 'Al archivo le faltan las hojas: ' . implode(', ', $faltantes)
                            . '. Descargue la plantilla y vuelva a llenarla sin cambiar los nombres de las hojas.';
            } else {
                $hojas = [];
                foreach (HOJAS_ESPERADAS as $hoja) {
                    $hojas[strtolower($hoja)] = $libro->hoja($hoja);
                }

                $respuesta = apiPost('carga-inicial/validar', ['hojas' => $hojas]);

                if (!$respuesta['ok']) {
                    $errorCarga = apiError($respuesta);
                } else {
                    $validacion = apiDatos($respuesta, []);
                    $_SESSION[CLAVE_SESION] = [
                        'hojas'   => $hojas,
                        'archivo' => $archivo['name'],
                        'momento' => date('Y-m-d H:i:s'),
                    ];
                    $paso = 'revision';
                }
            }
        } catch (RuntimeException $ex) {
            $errorCarga = $ex->getMessage();
        }
    }
}

/* ---------------------------------------------------------------------------
   Paso 3: ejecución
   --------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'ejecutar') {
    $guardado = $_SESSION[CLAVE_SESION] ?? null;

    if (!csrfValido()) {
        $errorCarga = 'La sesión expiró o el formulario no es válido. Vuelva a subir el archivo.';
        unset($_SESSION[CLAVE_SESION]);
    } elseif (!$guardado) {
        $errorCarga = 'La sesión de carga expiró. Vuelva a subir el archivo.';
    } elseif (($_POST['confirmacion'] ?? '') !== 'CONFIRMO') {
        $errorCarga = 'Debe marcar la casilla de confirmación para ejecutar la carga inicial.';
        $validacion = apiDatos(apiPost('carga-inicial/validar', ['hojas' => $guardado['hojas']]), []);
        $paso = 'revision';
    } else {
        $respuesta = apiPost('carga-inicial/ejecutar', [
            'hojas'        => $guardado['hojas'],
            'confirmacion' => 'CONFIRMO',
        ]);

        if (!$respuesta['ok']) {
            $errorCarga = apiError($respuesta);
            $errores    = apiErrores($respuesta);
            if ($errores) {
                $errorCarga .= ' ' . implode(' ', array_slice($errores, 0, 5));
            }
            $validacion = apiDatos(apiPost('carga-inicial/validar', ['hojas' => $guardado['hojas']]), []);
            $paso = 'revision';
        } else {
            $resultado = apiDatos($respuesta, []);
            $nombreArchivo = $guardado['archivo'];
            unset($_SESSION[CLAVE_SESION]);
            $paso = 'resultado';
        }
    }
}

$rutaPlantilla = APP_ROOT . 'assets/plantillas/carga_inicial_rea.xlsx';

$pageTitle  = 'Carga Inicial';
$breadcrumb = [['label' => 'Administración', 'url' => null], ['label' => 'Carga Inicial', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>📥 Carga Inicial</h1>
        <p>Poblado masivo de empleados, estudiantes, representantes y proveedores desde un archivo Excel.</p>
    </div>
    <div class="flex-gap">
        <a href="<?= e($rutaPlantilla) ?>" class="btn btn-secundario" download>⬇️ Descargar plantilla</a>
    </div>
</div>

<?php if ($errorCarga !== ''): ?>
    <div class="alerta alerta-error"><?= e($errorCarga) ?></div>
<?php endif; ?>

<?php if ($paso === 'inicio'): ?>

    <div class="alerta alerta-advertencia">
        <strong>⚠️ Esta opción encera la información de la institución.</strong>
        Úsela para poblar un sistema nuevo o para reiniciar una carga mal hecha. Antes de ejecutar
        nada verá el detalle exacto de lo que se va a borrar y podrá cancelar.
    </div>

    <div class="form-row" style="align-items:stretch;">
        <div class="card" style="flex:1 1 420px;">
            <h3>1. Descargue y llene la plantilla</h3>
            <p class="texto-mutado">
                La plantilla trae cuatro hojas —Empleados, Estudiantes, Representantes y Proveedores— con
                los encabezados que el sistema espera, listas desplegables y una fila de ejemplo que puede
                dejar tal cual: se ignora automáticamente.
            </p>
            <ul class="lista-simple">
                <li>Una fila por persona; la <strong>identificación</strong> no puede repetirse.</li>
                <li>Si alguien es empleado y además representante, regístrelo en las dos hojas con la
                    misma identificación: se crea una sola persona.</li>
                <li>En Estudiantes, la <em>Identificación del Representante</em> debe existir en la hoja
                    Representantes.</li>
                <li>No cambie los nombres de las hojas ni de las columnas.</li>
            </ul>
            <a href="<?= e($rutaPlantilla) ?>" class="btn btn-secundario" download>⬇️ Descargar plantilla Excel</a>
        </div>

        <div class="card" style="flex:1 1 380px;">
            <h3>2. Suba el archivo lleno</h3>
            <form method="POST" enctype="multipart/form-data" action="carga_inicial.php">
                <?= csrfCampo() ?>
                <input type="hidden" name="accion" value="validar">

                <div class="form-group">
                    <label class="campo-requerido" for="archivo">Archivo Excel (.xlsx)</label>
                    <input type="file" name="archivo" id="archivo" accept=".xlsx" required>
                    <div class="form-ayuda">Tamaño máximo <?= MAX_MB ?> MB · hasta 5.000 filas por hoja.</div>
                </div>

                <button type="submit" class="btn btn-primario">🔍 Validar archivo</button>
            </form>

            <p class="texto-mutado" style="margin-top:14px;">
                Al validar, el sistema solo <strong>lee</strong> el archivo y le muestra un resumen.
                Nada se borra ni se guarda en este paso.
            </p>
        </div>
    </div>

<?php elseif ($paso === 'revision' && $validacion !== null): ?>

    <?php
    $resumen  = $validacion['resumen'] ?? [];
    $errores  = $validacion['errores'] ?? [];
    $avisos   = $validacion['avisos'] ?? [];
    $aBorrar  = $validacion['a_borrar'] ?? [];
    $valido   = !empty($validacion['valido']);
    $archivoNombre = $_SESSION[CLAVE_SESION]['archivo'] ?? 'archivo.xlsx';
    ?>

    <div class="card">
        <div class="flex-entre">
            <h3 class="mb-0">Contenido leído de <em><?= e($archivoNombre) ?></em></h3>
            <a href="carga_inicial.php" class="btn btn-sm btn-secundario">Subir otro archivo</a>
        </div>

        <div class="kpi-grid" style="margin-top:14px;">
            <div class="kpi-card">
                <div class="kpi-valor"><?= (int)($resumen['personas'] ?? 0) ?></div>
                <div class="kpi-label">Personas distintas</div>
            </div>
            <div class="kpi-card kpi-alt-1">
                <div class="kpi-valor"><?= (int)($resumen['empleados'] ?? 0) ?></div>
                <div class="kpi-label">Empleados</div>
            </div>
            <div class="kpi-card kpi-alt-2">
                <div class="kpi-valor"><?= (int)($resumen['estudiantes'] ?? 0) ?></div>
                <div class="kpi-label">Estudiantes</div>
            </div>
            <div class="kpi-card kpi-alt-2">
                <div class="kpi-valor"><?= (int)($resumen['representantes'] ?? 0) ?></div>
                <div class="kpi-label">Representantes</div>
            </div>
            <div class="kpi-card kpi-alt-1">
                <div class="kpi-valor"><?= (int)($resumen['proveedores'] ?? 0) ?></div>
                <div class="kpi-label">Proveedores</div>
            </div>
            <div class="kpi-card <?= $errores ? 'kpi-alt-3' : '' ?>">
                <div class="kpi-valor"><?= (int)($resumen['errores'] ?? 0) ?></div>
                <div class="kpi-label">Errores encontrados</div>
            </div>
        </div>

        <p class="texto-mutado">
            <?= (int)($resumen['estudiantes_con_representante'] ?? 0) ?> de
            <?= (int)($resumen['estudiantes'] ?? 0) ?> estudiantes quedarán enlazados a su representante.
        </p>
    </div>

    <?php if ($errores): ?>
        <div class="card">
            <h3>❌ Corrija estos errores antes de continuar</h3>
            <p class="texto-mutado">
                Mientras exista al menos un error, la carga no se puede ejecutar. Arréglelos en el
                Excel y vuelva a subir el archivo.
            </p>
            <ul class="lista-errores">
                <?php foreach ($errores as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if ((int)($resumen['errores'] ?? 0) > count($errores)): ?>
                <p class="texto-mutado">
                    Se muestran los primeros <?= count($errores) ?> de
                    <?= (int)$resumen['errores'] ?> errores.
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($avisos): ?>
        <div class="card">
            <h3>ℹ️ Avisos (no impiden la carga)</h3>
            <ul class="lista-simple">
                <?php foreach ($avisos as $aviso): ?>
                    <li><?= e($aviso) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($validacion['muestra'])): ?>
        <div class="card">
            <h3>Muestra de las personas que se crearán</h3>
            <div class="tabla-wrap">
                <table class="tabla-datos">
                    <thead>
                    <tr>
                        <th>Identificación</th><th>Tipo</th><th>Apellidos</th><th>Nombres</th>
                        <th>Correo</th><th>Teléfono</th><th>Estado</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($validacion['muestra'] as $p): ?>
                        <tr>
                            <td><?= e($p['identificacion'] ?? '') ?></td>
                            <td><?= e($p['tipo'] ?: '—') ?></td>
                            <td><strong><?= e($p['apellidos'] ?? '') ?></strong></td>
                            <td><?= e($p['nombres'] ?? '') ?></td>
                            <td><?= e($p['email'] ?: '—') ?></td>
                            <td><?= e($p['telefono'] ?: '—') ?></td>
                            <td><?= badgeEstado($p['estado'] ?? 'ACTIVO') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="texto-mutado">
                Se muestran las primeras <?= count($validacion['muestra']) ?> personas de
                <?= (int)($resumen['personas'] ?? 0) ?>.
            </p>
        </div>
    <?php endif; ?>

    <div class="card card-peligro">
        <h3>⚠️ Advertencia: la carga inicial encera la información</h3>

        <p>
            Al ejecutar la carga, el sistema <strong>borrará de forma permanente e irreversible</strong>
            la siguiente información de <strong><?= e($_SESSION['institucion_nombre'] ?? 'esta institución') ?></strong>,
            y luego cargará el contenido del archivo:
        </p>

        <div class="tabla-wrap">
            <table class="tabla-datos">
                <thead>
                <tr><th>Información que se borrará</th><th>Registros actuales</th></tr>
                </thead>
                <tbody>
                    <tr><td>Consentimientos</td><td><strong><?= (int)($aBorrar['consentimientos'] ?? 0) ?></strong></td></tr>
                    <tr><td>Historial de consentimientos</td><td><strong><?= (int)($aBorrar['historial'] ?? 0) ?></strong></td></tr>
                    <tr><td>Empleados</td><td><strong><?= (int)($aBorrar['empleados'] ?? 0) ?></strong></td></tr>
                    <tr><td>Estudiantes</td><td><strong><?= (int)($aBorrar['estudiantes'] ?? 0) ?></strong></td></tr>
                    <tr><td>Proveedores</td><td><strong><?= (int)($aBorrar['proveedores'] ?? 0) ?></strong></td></tr>
                    <tr>
                        <td>Personas del directorio</td>
                        <td>
                            <strong><?= (int)($aBorrar['personas'] ?? 0) ?></strong>
                            <span class="texto-mutado">
                                (se conservan <?= (int)($aBorrar['personas_protegidas'] ?? 0) ?> con cuenta de usuario)
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p style="margin-top:14px;"><strong>No se tocan:</strong></p>
        <ul class="lista-simple">
            <li>Usuarios del sistema, roles, permisos y sus asignaciones.</li>
            <li>Las personas que tienen cuenta de usuario (su cuenta depende de ellas).</li>
            <li>Los catálogos de Finalidades del Tratamiento y Tipos de Dato Personal.</li>
            <li>Las instituciones educativas y la bitácora de auditoría.</li>
            <li>La información de las demás instituciones.</li>
        </ul>

        <p class="texto-mutado">
            Esta operación no se puede deshacer desde el sistema. Si tiene dudas, saque antes un
            respaldo de la base de datos.
        </p>

        <?php if ($valido): ?>
            <form method="POST" action="carga_inicial.php" id="formEjecutar">
                <?= csrfCampo() ?>
                <input type="hidden" name="accion" value="ejecutar">

                <div class="form-group form-check">
                    <label>
                        <input type="checkbox" name="confirmacion" value="CONFIRMO" id="chkConfirmo" required>
                        Entiendo que esta acción borra la información indicada y no se puede deshacer.
                    </label>
                </div>

                <div class="flex-gap">
                    <button type="submit" class="btn btn-peligro" id="btnEjecutar" disabled>
                        🗑️ Encerar y cargar
                    </button>
                    <a href="carga_inicial.php" class="btn btn-secundario">Cancelar</a>
                </div>
            </form>
        <?php else: ?>
            <div class="alerta alerta-error" style="margin-bottom:0;">
                La carga está bloqueada porque el archivo tiene errores. Corríjalos y vuelva a subirlo.
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($paso === 'resultado' && $resultado !== null): ?>

    <?php
    $borrado   = $resultado['borrado'] ?? [];
    $insertado = $resultado['insertado'] ?? [];
    ?>

    <div class="alerta alerta-exito">
        <strong>✅ <?= e($resultado['mensaje'] ?? 'Carga completada.') ?></strong>
        Archivo procesado: <em><?= e($nombreArchivo ?? '') ?></em>.
    </div>

    <div class="form-row" style="align-items:stretch;">
        <div class="card" style="flex:1 1 340px;">
            <h3>Se cargó</h3>
            <table class="tabla-datos">
                <tbody>
                    <tr><td>Personas nuevas</td><td><strong><?= (int)($insertado['personas_nuevas'] ?? 0) ?></strong></td></tr>
                    <tr><td>Personas actualizadas</td><td><strong><?= (int)($insertado['personas_actualizadas'] ?? 0) ?></strong></td></tr>
                    <tr><td>Empleados</td><td><strong><?= (int)($insertado['empleados'] ?? 0) ?></strong></td></tr>
                    <tr><td>Estudiantes</td><td><strong><?= (int)($insertado['estudiantes'] ?? 0) ?></strong></td></tr>
                    <tr><td>Proveedores</td><td><strong><?= (int)($insertado['proveedores'] ?? 0) ?></strong></td></tr>
                </tbody>
            </table>
        </div>

        <div class="card" style="flex:1 1 340px;">
            <h3>Se borró</h3>
            <table class="tabla-datos">
                <tbody>
                    <tr><td>Consentimientos</td><td><strong><?= (int)($borrado['consentimientos'] ?? 0) ?></strong></td></tr>
                    <tr><td>Detalle de consentimientos</td><td><strong><?= (int)($borrado['detalle_consentimientos'] ?? 0) ?></strong></td></tr>
                    <tr><td>Historial</td><td><strong><?= (int)($borrado['historial'] ?? 0) ?></strong></td></tr>
                    <tr><td>Empleados</td><td><strong><?= (int)($borrado['empleados'] ?? 0) ?></strong></td></tr>
                    <tr><td>Estudiantes</td><td><strong><?= (int)($borrado['estudiantes'] ?? 0) ?></strong></td></tr>
                    <tr><td>Proveedores</td><td><strong><?= (int)($borrado['proveedores'] ?? 0) ?></strong></td></tr>
                    <tr><td>Personas</td><td><strong><?= (int)($borrado['personas'] ?? 0) ?></strong></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($resultado['avisos'])): ?>
        <div class="card">
            <h3>ℹ️ Avisos de la carga</h3>
            <ul class="lista-simple">
                <?php foreach ($resultado['avisos'] as $aviso): ?>
                    <li><?= e($aviso) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <h3>Siguientes pasos</h3>
        <ul class="lista-simple">
            <li><a href="personas.php">Revise el directorio de personas</a> para confirmar la información cargada.</li>
            <li><a href="consentimientos.php">Registre los consentimientos</a>: la carga inicial no los crea.</li>
            <li><a href="<?= e(APP_ROOT) ?>reportes/reporte_auditoria.php">Consulte la bitácora de auditoría</a>:
                el movimiento quedó registrado con su usuario, la fecha y la IP.</li>
        </ul>
        <a href="carga_inicial.php" class="btn btn-secundario">Realizar otra carga</a>
    </div>

<?php endif; ?>

<script>
(function () {
    'use strict';
    // El botón destructivo solo se habilita cuando se marca la casilla
    var chk = document.getElementById('chkConfirmo');
    var btn = document.getElementById('btnEjecutar');
    if (!chk || !btn) { return; }

    chk.addEventListener('change', function () { btn.disabled = !chk.checked; });

    document.getElementById('formEjecutar').addEventListener('submit', function (evento) {
        if (!confirm('Esta acción borrará la información indicada y no se puede deshacer.\n\n¿Desea continuar con la carga inicial?')) {
            evento.preventDefault();
            return;
        }
        btn.disabled = true;
        btn.textContent = 'Procesando, no cierre esta página…';
    });
})();
</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
