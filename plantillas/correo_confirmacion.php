<?php
/**
 * plantillas/correo_confirmacion.php
 * -----------------------------------------------------------------------------
 * Correo que confirma la decisión tomada en el enlace público de consentimiento.
 *
 * Se envía después de registrar la decisión. En el caso de los estudiantes va
 * dirigido al representante e indica que se trata de su representado.
 *
 * Puede editar este archivo libremente; vea plantillas/LEEME.md para la lista
 * de variables disponibles en $datos.
 *
 * Los estilos van escritos dentro de cada etiqueta (style="...") a propósito:
 * los lectores de correo suelen descartar las hojas de estilo.
 * -----------------------------------------------------------------------------
 */

/** @var array $datos */
$e = static fn($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');

$otorga          = ($datos['decision'] ?? '') === 'OTORGA';
$esRepresentante = !empty($datos['es_representante']);
$titular         = (string)($datos['titular'] ?? '');
$institucion     = (string)($datos['institucion'] ?? 'Red Educativa Arquidiocesana');

$color      = $otorga ? '#12734a' : '#9a5b00';
$colorFondo = $otorga ? '#e4f7ec' : '#fdf2dd';
$colorBorde = $otorga ? '#c3ead4' : '#f2dcb0';
$titulo     = $otorga ? 'Consentimiento otorgado' : 'Consentimiento revocado';

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

    <!-- Resultado -->
    <tr>
      <td style="padding:24px 28px 0;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
               style="background:<?= $colorFondo ?>;border:1px solid <?= $colorBorde ?>;border-radius:10px;">
          <tr>
            <td style="padding:15px 18px;">
              <div style="font-size:17px;font-weight:bold;color:<?= $color ?>;">
                <?= $otorga ? '&#10003;' : '&#9888;' ?> <?= $e($titulo) ?>
              </div>
              <div style="font-size:13px;color:<?= $color ?>;margin-top:4px;">
                Registrado el <?= $e($datos['fecha'] ?? '') ?>
              </div>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- Cuerpo -->
    <tr>
      <td style="padding:22px 28px;color:#34495e;font-size:15px;line-height:1.65;">

        <?php if ($esRepresentante): ?>
          <p style="margin:0 0 16px;">Estimado/a representante:</p>
          <p style="margin:0 0 16px;">
            Le confirmamos que quedó registrada su decisión sobre el tratamiento de los datos
            personales de su representado/a
            <strong style="color:#14161c;"><?= $e($titular) ?></strong>, estudiante de nuestra institución.
          </p>
        <?php else: ?>
          <p style="margin:0 0 16px;">
            Estimado/a <strong style="color:#14161c;"><?= $e($titular) ?></strong>:
          </p>
          <p style="margin:0 0 16px;">
            Le confirmamos que quedó registrada su decisión sobre el tratamiento de sus datos
            personales, en su calidad de <?= $e($tipoTexto) ?> de nuestra institución.
          </p>
        <?php endif; ?>

        <!-- Detalle del registro -->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
               style="background:#f6f8fb;border:1px solid #e6e9f0;border-radius:10px;margin:0 0 18px;">
          <tr>
            <td style="padding:6px 16px 12px;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font-size:13px;">
                <tr>
                  <td style="padding:8px 0;color:#667085;width:44%;">Titular de los datos</td>
                  <td style="padding:8px 0;color:#14161c;font-weight:bold;"><?= $e($titular) ?></td>
                </tr>
                <tr>
                  <td style="padding:8px 0;color:#667085;border-top:1px solid #e6e9f0;">Identificación</td>
                  <td style="padding:8px 0;color:#14161c;border-top:1px solid #e6e9f0;">
                    <?= $e($datos['documento'] ?? '') ?> <?= $e($datos['identificacion'] ?? '') ?>
                  </td>
                </tr>
                <?php if ($esRepresentante && !empty($datos['representante'])): ?>
                <tr>
                  <td style="padding:8px 0;color:#667085;border-top:1px solid #e6e9f0;">Representante</td>
                  <td style="padding:8px 0;color:#14161c;border-top:1px solid #e6e9f0;"><?= $e($datos['representante']) ?></td>
                </tr>
                <?php endif; ?>
                <tr>
                  <td style="padding:8px 0;color:#667085;border-top:1px solid #e6e9f0;">Decisión</td>
                  <td style="padding:8px 0;font-weight:bold;color:<?= $color ?>;border-top:1px solid #e6e9f0;">
                    <?= $otorga ? 'Consentimiento otorgado' : 'Consentimiento revocado' ?>
                  </td>
                </tr>
                <tr>
                  <td style="padding:8px 0;color:#667085;border-top:1px solid #e6e9f0;">Política aceptada</td>
                  <td style="padding:8px 0;color:#14161c;border-top:1px solid #e6e9f0;">Versión <?= $e($datos['version'] ?? '') ?></td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <?php if ($otorga): ?>
          <p style="margin:0 0 12px;">
            Si en el futuro desea <strong>revocar</strong> este consentimiento, escríbanos desde este
            mismo correo y atenderemos su solicitud.
          </p>
        <?php else: ?>
          <p style="margin:0 0 12px;">
            La revocación no tiene efectos retroactivos sobre los tratamientos ya realizados de forma
            lícita. Si desea volver a otorgar su consentimiento, puede hacerlo desde el mismo enlace.
          </p>
        <?php endif; ?>

        <p style="margin:0;font-size:13px;color:#667085;">
          Si usted no realizó esta acción, comuníquese con la institución a la brevedad.
        </p>
      </td>
    </tr>

    <!-- Pie -->
    <tr>
      <td style="padding:16px 28px 22px;background:#f6f8fb;border-top:1px solid #e6e9f0;font-size:11px;color:#8a93a5;line-height:1.6;">
        Este mensaje fue enviado por <?= $e($institucion) ?> — Red Educativa Arquidiocesana.
        Es una confirmación automática; por favor no responda a este correo salvo que deba
        solicitar la revocación de su consentimiento.
      </td>
    </tr>
  </table>
</div>
