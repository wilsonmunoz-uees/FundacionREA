<?php
/**
 * plantillas/correo_clave_temporal.php
 * -----------------------------------------------------------------------------
 * Correo con la contraseña temporal de una cuenta recién creada, o restablecida.
 *
 * Es el único lugar por donde esa contraseña sale del sistema. Quien administra
 * las cuentas no llega a verla en ningún momento: se genera, se cifra y se envía
 * aquí, y el titular está obligado a cambiarla en su primer ingreso.
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

$institucion = (string)($datos['institucion'] ?? 'Red Educativa Arquidiocesana');
$username    = (string)($datos['username'] ?? '');
$clave       = (string)($datos['clave'] ?? '');
$enlace      = (string)($datos['enlace'] ?? '');
$esNueva     = !empty($datos['es_nueva']);
?>
<div style="font-family: Arial, Helvetica, sans-serif; font-size: 15px; color: #14161c; line-height: 1.6; max-width: 620px; margin: 0 auto;">

    <div style="background: #7d1128; color: #ffffff; padding: 18px 22px; border-radius: 8px 8px 0 0;">
        <div style="font-size: 12px; letter-spacing: .08em; text-transform: uppercase; opacity: .85;">
            Sistema de Protección de Datos
        </div>
        <div style="font-size: 19px; font-weight: bold; margin-top: 3px;">
            <?= $e($institucion) ?>
        </div>
    </div>

    <div style="border: 1px solid #e3e6ec; border-top: none; border-radius: 0 0 8px 8px; padding: 22px;">

        <p style="margin: 0 0 14px;">Estimado/a usuario/a:</p>

        <p style="margin: 0 0 14px;">
            <?= $esNueva
                ? 'Se ha creado su cuenta para acceder al sistema de protección de datos personales de '
                : 'Se ha restablecido la contraseña de su cuenta en el sistema de protección de datos personales de ' ?>
            <strong><?= $e($institucion) ?></strong>.
        </p>

        <table role="presentation" cellpadding="0" cellspacing="0"
               style="width: 100%; background: #f6f7f9; border: 1px solid #e3e6ec; border-radius: 6px; margin: 0 0 16px;">
            <tr>
                <td style="padding: 14px 18px;">
                    <div style="font-size: 12px; color: #667085; text-transform: uppercase; letter-spacing: .05em;">
                        Usuario
                    </div>
                    <div style="font-size: 17px; font-weight: bold; margin-bottom: 12px;">
                        <?= $e($username) ?>
                    </div>

                    <div style="font-size: 12px; color: #667085; text-transform: uppercase; letter-spacing: .05em;">
                        Contraseña temporal
                    </div>
                    <div style="font-size: 20px; font-weight: bold; font-family: 'Courier New', monospace; letter-spacing: .05em;">
                        <?= $e($clave) ?>
                    </div>
                </td>
            </tr>
        </table>

        <p style="margin: 0 0 14px; padding: 12px 16px; background: #fdf2dd; border-left: 4px solid #9a5b00; border-radius: 4px;">
            <strong>Esta contraseña es de un solo uso.</strong> Al ingresar por primera vez, el sistema le
            pedirá que la cambie por una suya antes de dejarle continuar. Nadie más la conoce, y nadie
            de la institución puede consultarla.
        </p>

        <?php if ($enlace !== ''): ?>
            <p style="margin: 0 0 18px; text-align: center;">
                <a href="<?= $e($enlace) ?>"
                   style="display: inline-block; background: #7d1128; color: #ffffff; text-decoration: none;
                          padding: 12px 26px; border-radius: 6px; font-weight: bold;">
                    Ingresar al sistema
                </a>
            </p>
        <?php endif; ?>

        <p style="margin: 0 0 14px; font-size: 13px; color: #667085;">
            Si usted no esperaba este correo, avise al administrador del sistema y no utilice la
            contraseña. Por seguridad, no reenvíe este mensaje a nadie.
        </p>

    </div>

    <p style="font-size: 12px; color: #98a2b3; text-align: center; margin: 14px 0 0;">
        Este es un mensaje automático del Sistema de Gestión de Protección de Datos Personales.
        Por favor, no responda a esta dirección.
    </p>
</div>
