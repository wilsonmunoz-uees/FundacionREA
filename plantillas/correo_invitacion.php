<?php
/**
 * plantillas/correo_invitacion.php
 * -----------------------------------------------------------------------------
 * Cuerpo del correo que invita a otorgar o revocar el consentimiento.
 * Puede editar este archivo libremente; vea plantillas/LEEME.md para la lista
 * de variables disponibles en $datos.
 *
 * Los estilos van escritos dentro de cada etiqueta (style="...") a propósito:
 * los lectores de correo suelen descartar las hojas de estilo.
 * -----------------------------------------------------------------------------
 */

/** @var array $datos */
$e = static fn($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

$esRepresentante = !empty($datos['es_representante']);
$titular         = (string)($datos['titular'] ?? '');
$institucion     = (string)($datos['institucion'] ?? 'Red Educativa Arquidiocesana');
$finalidad       = trim((string)($datos['finalidad'] ?? ''));
$mensaje         = trim((string)($datos['mensaje'] ?? ''));
$enlace          = (string)($datos['enlace'] ?? '');

$tipoTexto = match (strtoupper((string)($datos['tipo'] ?? ''))) {
    'ESTUDIANTE' => 'estudiante',
    'EMPLEADO'   => 'colaborador',
    default      => 'proveedor',
};
?>
<div style="margin:0;padding:24px 12px;background:#f4f6fa;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e6e9f0;">

    <!-- Cabecera -->
    <tr>
      <td style="padding:22px 28px 16px;border-bottom:3px solid #c8102e;">
        <div style="font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#c8102e;font-weight:bold;">
          Protección de Datos Personales
        </div>
        <div style="font-size:19px;font-weight:bold;color:#14161c;margin-top:4px;">
          <?= $e($institucion) ?>
        </div>
      </td>
    </tr>

    <!-- Cuerpo -->
    <tr>
      <td style="padding:26px 28px;color:#34495e;font-size:15px;line-height:1.65;">

        <?php if ($esRepresentante): ?>
          <p style="margin:0 0 16px;">Estimado/a representante:</p>
          <p style="margin:0 0 16px;">
            Nos dirigimos a usted en calidad de representante de su representado/a
            <strong style="color:#14161c;"><?= $e($titular) ?></strong>, estudiante de nuestra institución.
          </p>
        <?php else: ?>
          <p style="margin:0 0 16px;">
            Estimado/a <strong style="color:#14161c;"><?= $e($titular) ?></strong>:
          </p>
          <p style="margin:0 0 16px;">
            Nos dirigimos a usted en su calidad de <?= $e($tipoTexto) ?> de nuestra institución.
          </p>
        <?php endif; ?>

        <p style="margin:0 0 16px;">
          En cumplimiento de la Ley Orgánica de Protección de Datos Personales, necesitamos registrar
          su decisión sobre el tratamiento de los datos personales
          <?= $esRepresentante ? 'de su representado/a' : 'que usted nos ha confiado' ?>.
        </p>

        <?php if ($finalidad !== ''): ?>
          <p style="margin:0 0 16px;padding:12px 14px;background:#fdeaee;border-left:3px solid #c8102e;">
            <strong style="color:#8f0a1f;">Finalidad del tratamiento:</strong><br>
            <?= $e($finalidad) ?>
          </p>
        <?php endif; ?>

        <?php if ($mensaje !== ''): ?>
          <p style="margin:0 0 16px;"><?= nl2br($e($mensaje)) ?></p>
        <?php endif; ?>

        <p style="margin:0 0 20px;">
          <strong style="color:#14161c;">Por favor dé clic en el siguiente enlace, que abrirá una
          pantalla para el consentimiento de uso de datos:</strong>
        </p>

        <!-- Botón del enlace -->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 20px;">
          <tr>
            <td style="background:#c8102e;border-radius:8px;">
              <a href="<?= $e($enlace) ?>"
                 style="display:inline-block;padding:13px 28px;color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;">
                Abrir la pantalla de consentimiento
              </a>
            </td>
          </tr>
        </table>

        <p style="margin:0 0 6px;font-size:12px;color:#667085;">
          Si el botón no funciona, copie y pegue esta dirección en su navegador:
        </p>
        <p style="margin:0 0 20px;font-size:12px;word-break:break-all;">
          <a href="<?= $e($enlace) ?>" style="color:#c8102e;"><?= $e($enlace) ?></a>
        </p>

        <p style="margin:0;font-size:13px;color:#667085;">
          En esa pantalla podrá <strong>otorgar</strong> o <strong>revocar</strong> el consentimiento
          cuando lo desee. Su decisión queda registrada de inmediato y puede cambiarla más adelante
          volviendo a este mismo enlace.
        </p>
      </td>
    </tr>

    <!-- Pie -->
    <tr>
      <td style="padding:16px 28px 22px;background:#f6f8fb;border-top:1px solid #e6e9f0;font-size:11px;color:#8a93a5;line-height:1.6;">
        Este mensaje fue enviado por <?= $e($institucion) ?> — Red Educativa Arquidiocesana.
        Si lo recibió por error, por favor ignórelo y no dé clic en el enlace.
        Por favor no responda a este correo.
      </td>
    </tr>
  </table>
</div>
