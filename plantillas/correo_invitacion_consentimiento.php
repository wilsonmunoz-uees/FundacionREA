<?php
/**
 * plantillas/correo_invitacion_consentimiento.php
 * -----------------------------------------------------------------------------
 * Correo de invitación al consentimiento, el que sale desde el Envío Masivo.
 *
 * Lleva el enlace de consentimiento CON VERIFICACIÓN del tipo de persona, ya con
 * su número de documento precargado: quien lo abre solo tiene que continuar.
 *
 * En el caso de los estudiantes va dirigido al representante e indica de qué
 * representado se trata.
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
$enlace          = (string)($datos['enlace'] ?? '#');

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
            Nos dirigimos a usted como representante de
            <strong style="color:#14161c;"><?= $e($titular) ?></strong>, estudiante de nuestra
            institución, para solicitarle que registre su decisión sobre el tratamiento de los
            datos personales de su representado/a.
          </p>
        <?php else: ?>
          <p style="margin:0 0 16px;">
            Estimado/a <strong style="color:#14161c;"><?= $e($titular) ?></strong>:
          </p>
          <p style="margin:0 0 18px;">
            En su calidad de <?= $e($tipoTexto) ?> de nuestra institución, le solicitamos que
            registre su decisión sobre el tratamiento de sus datos personales.
          </p>
        <?php endif; ?>

        <!-- Llamada a la acción -->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
               style="background:#f0f5fb;border:1px solid #cfe0f2;border-radius:10px;margin:0 0 18px;">
          <tr>
            <td align="center" style="padding:22px 18px;">
              <div style="font-size:14px;color:#34495e;margin-bottom:14px;">
                Pulse el botón para abrir la pantalla de consentimiento:
              </div>
              <a href="<?= $e($enlace) ?>"
                 style="display:inline-block;background:#1f4e79;color:#ffffff;text-decoration:none;
                        font-size:16px;font-weight:bold;padding:13px 30px;border-radius:8px;">
                Registrar mi consentimiento
              </a>
              <div style="font-size:12px;color:#5b7391;margin-top:14px;line-height:1.5;">
                Su <?= $e(strtolower((string)($datos['documento'] ?? 'documento'))) ?>
                <strong><?= $e($datos['identificacion'] ?? '') ?></strong> ya viene cargado en el enlace.
              </div>
            </td>
          </tr>
        </table>

        <p style="margin:0 0 18px;font-size:14px;">
          Para confirmar que es usted, la pantalla le enviará un <strong>código de verificación</strong>
          a este mismo correo. Escríbalo y podrá revisar sus datos y decidir.
        </p>

        <!-- Enlace en texto, por si el botón no funciona -->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
               style="background:#f6f8fb;border:1px solid #e6e9f0;border-radius:10px;margin:0 0 18px;">
          <tr>
            <td style="padding:14px 16px;font-size:12px;color:#667085;line-height:1.6;">
              Si el botón no funciona, copie esta dirección en su navegador:<br>
              <span style="color:#1f4e79;word-break:break-all;"><?= $e($enlace) ?></span>
            </td>
          </tr>
        </table>

        <!-- Advertencia -->
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
               style="background:#fdf2dd;border:1px solid #f2dcb0;border-radius:10px;">
          <tr>
            <td style="padding:14px 18px;color:#8a5a00;font-size:13px;line-height:1.6;">
              La pantalla <strong>no le pedirá contraseñas ni datos de pago</strong>, y no modifica su
              información: solo muestra lo que consta registrado y recoge su decisión. Si no esperaba
              este mensaje, comuníquese con la institución antes de continuar.
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
