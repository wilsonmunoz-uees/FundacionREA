<?php
/**
 * plantillas/consentimiento_empleado.php
 * -----------------------------------------------------------------------------
 * Texto legal de la pantalla pública de consentimiento cuando el titular es
 * un colaborador de la institución.
 *
 * Sale con el mismo contenido que las otras dos plantillas; edítelo libremente
 * para diferenciar el disclaimer de este caso sin afectar a los demás.
 * Vea plantillas/LEEME.md para la lista de variables disponibles en $datos.
 * -----------------------------------------------------------------------------
 */

/** @var array $datos */
$e = static fn($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

$institucion = (string)($datos['institucion'] ?? 'Red Educativa Arquidiocesana');
$finalidad   = trim((string)($datos['finalidad'] ?? ''));
$tiposDato   = (array)($datos['tipos_dato'] ?? []);
?>

<h2>Consentimiento para el tratamiento de datos personales</h2>

<p>
  Esta declaración se refiere a sus datos personales como colaborador/a de la institución,
  <strong><?= $e($datos['titular'] ?? '') ?></strong>, identificado/a con
  <?= $e(mb_strtolower((string)($datos['documento'] ?? 'documento'), 'UTF-8')) ?>
  N.º <strong><?= $e($datos['identificacion'] ?? '') ?></strong>.
</p>

<p>
  Comprende los datos recabados con ocasión de la relación laboral: identificación, contacto,
  formación académica, cargo y los que resulten necesarios para la gestión del personal.
</p>

<p>
  De conformidad con la <strong>Ley Orgánica de Protección de Datos Personales</strong> y su
  reglamento, <?= $e($institucion) ?> le informa que los datos personales que reposan en sus
  archivos son tratados con fines educativos, administrativos, académicos, de comunicación
  institucional y de cumplimiento de obligaciones legales.
</p>

<?php if ($finalidad !== ''): ?>
  <div class="bloque-finalidad">
    <span class="etiqueta">Finalidad de este consentimiento</span>
    <strong><?= $e($finalidad) ?></strong>
  </div>
<?php endif; ?>

<p>
  El tratamiento se realiza bajo los principios de licitud, lealtad, transparencia, minimización,
  exactitud, seguridad y confidencialidad. Los datos no serán cedidos a terceros ajenos a la
  institución, salvo obligación legal o requerimiento de autoridad competente, y se conservarán
  únicamente durante el tiempo necesario para cumplir la finalidad que los justifica.
</p>

<?php if ($tiposDato): ?>
  <p>Los datos personales comprendidos en este consentimiento son:</p>
  <ul class="lista-datos">
    <?php foreach ($tiposDato as $tipo): ?>
      <li>
        <?= $e($tipo['Nombre'] ?? '') ?>
        <?php if (($tipo['EsSensible'] ?? 'NO') === 'SI'): ?>
          <span class="marca-sensible">dato sensible</span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<p>
  Usted puede ejercer en cualquier momento sus derechos de <strong>acceso, rectificación,
  actualización, eliminación, oposición, portabilidad</strong> y a no ser objeto de decisiones
  automatizadas, dirigiéndose a la institución por los canales habituales de contacto.
</p>

<p>
  <strong>Su decisión es libre y revocable.</strong> Otorgar el consentimiento no condiciona la
  prestación del servicio educativo, y revocarlo no tiene efectos retroactivos sobre los
  tratamientos ya realizados de forma lícita. Puede volver a esta misma pantalla cuando lo desee
  para cambiar su decisión.
</p>
