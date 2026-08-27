<?php
/**
 * plantillas/correo_codigo_verificacion.php
 * -----------------------------------------------------------------------------
 * Correo con el código de verificación de los enlaces con verificación.
 *
 * Se envía ANTES de mostrar el disclaimer, para comprobar que quien abrió el
 * enlace es la persona registrada. En el caso de los estudiantes va dirigido al
 * representante e indica de quién se trata.
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

$esRepresentante = !empty($datos['es_representante']);
$titular         = (string)($datos['titular'] ?? '');
$institucion     = (string)($datos['institucion'] ?? 'Red Educativa Arquidiocesana');
$codigo          = (string)($datos['codigo'] ?? '');
$minutos         = (int)($datos['minutos'] ?? 10);

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
      <td style="padding:24px 28px 8px;color:#34495e;font-size:15px;line-height:1.65;">

        <?php if ($esRepresentante): ?>
          <p style="margin:0 0 16px;">Estimado/a representante:</p>
          <p style="margin:0 0 18px;">
            Alguien está consultando el consentimiento de datos personales de su representado/a
            <strong style="color:#14161c;"><?= $e($titular) ?></strong>.
            Para continuar, escriba el siguiente código en la pantalla:
          </p>
        <?php else: ?>
          <p style="margin:0 0 16px;">
            Estimado/a <strong style="color:#14161c;"><?= $e($titular) ?></strong>:
          </p>
          <p style="margin:0 0 18px;">
            Para continuar con la consulta de su consentimiento de datos personales, en su calidad
            de <?= $e($tipoTexto) ?> de nuestra institución, escriba el siguiente código en la pantalla:
          </p>
        <?php endif; ?>

        <!-- El código -->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
               style="background:#f0f5fb;border:1px solid #cfe0f2;border-radius:10px;margin:0 0 18px;">
          <tr>
            <td align="center" style="padding:20px 16px;">
              <div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;color:#5b7391;">
                Código de verificación
              </div>
              <div style="font-size:34px;font-weight:bold;letter-spacing:10px;color:#1f4e79;margin-top:8px;">
                <?= $e($codigo) ?>
              </div>
              <div style="font-size:13px;color:#5b7391;margin-top:10px;">
                Válido durante <?= $minutos ?> minutos, hasta las <strong><?= $e($datos['expira'] ?? '') ?></strong>
              </div>
            </td>
          </tr>
        </table>

        <!-- Datos de la consulta -->
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
                <tr>
                  <td style="padding:8px 0;color:#667085;border-top:1px solid #e6e9f0;">Solicitado el</td>
                  <td style="padding:8px 0;color:#14161c;border-top:1px solid #e6e9f0;">
                    <?= $e($datos['fecha'] ?? '') ?>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>

        <!-- Advertencia -->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
               style="background:#fdf2dd;border:1px solid #f2dcb0;border-radius:10px;">
          <tr>
            <td style="padding:14px 18px;color:#8a5a00;font-size:13px;line-height:1.6;">
              <strong>Si usted no solicitó este código, ignore este mensaje.</strong>
              Sin el código nadie puede continuar, y la consulta no modifica ninguno de sus datos.
              Nunca comparta este código con terceros: el personal de la institución jamás se lo pedirá.
            </td>
          </tr>
        </table>

      </td>
    </tr>

    <!-- Pie -->
    <tr>
      <td style="padding:16px 28px 24px;border-top:1px solid #e6e9f0;color:#8a94a6;font-size:12px;line-height:1.6;">
        Este mensaje se generó automáticamente; por favor no responda a esta dirección.<br>
        Red Educativa Arquidiocesana &mdash; Sistema de Gestión de Protección de Datos.
      </td>
    </tr>

  </table>
</div>
