<?php
/**
 * includes/layout_top.php
 * Encabezado común (HTML head + sidebar + topbar) para todas las páginas internas.
 * Variables esperadas antes del include:
 *   $pageTitle    (string) Título de la página / módulo
 *   $pageDesc     (string, opcional) Subtítulo descriptivo
 *   $breadcrumb   (array, opcional) [['label' => 'Registro de Datos', 'url' => null], ...]
 * Requiere que APP_ROOT esté definido y que auth.php + functions.php ya se hayan cargado.
 */
if (!defined('APP_ROOT')) {
    define('APP_ROOT', '');
}
$pageTitle = $pageTitle ?? 'Sistema de Protección de Datos';
$breadcrumb = $breadcrumb ?? [];

// El nombre de la institución llega desde la API al iniciar sesión y queda en la sesión.
$institucionNombre = $_SESSION['institucion_nombre'] ?? '';
if ($institucionNombre === '' && institucionActual()) {
    $institucionNombre = apiDatos(apiGet('auth/me'), [])['detalle']['InstitucionNombre'] ?? '';
    $_SESSION['institucion_nombre'] = $institucionNombre;
}
$flash = flashGet();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> - REA | Protección de Datos</title>
    <link rel="stylesheet" href="<?= e(APP_ROOT) ?>css/style.css">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/menu.php'; ?>

    <div class="main-area">
        <header class="topbar">
            <div class="flex-gap">
                <?php /* El alternar lo toma js/menu_movil.js, que añade además el
                         fondo para cerrar, la tecla Esc y el manejo del foco. El
                         onclick se mantiene como respaldo por si ese archivo no
                         llegara a cargarse: abrir el menú es lo mínimo. */ ?>
                <button type="button" class="menu-toggle" aria-controls="sidebarApp"
                        onclick="document.getElementById('sidebarApp').classList.toggle('abierto')">☰</button>
                <?php /* El logotipo de la institución activa acompaña a su nombre: una
                         cuenta puede entrar en varias, y el escudo se reconoce de un
                         vistazo mucho antes que un nombre largo. Sustituye a la
                         pastilla de «otra institución», que decía lo mismo con menos
                         claridad y ocupaba sitio en la barra. */ ?>
                <?php /* `width` y `height` van como ATRIBUTOS, no solo en la hoja de
                         estilos: el archivo mide 150×150 y, si la hoja no llegara a
                         cargarse —o el navegador sirviera una copia vieja—, la imagen
                         se dibujaría a tamaño natural y reventaría la barra. Con los
                         atributos puestos entra bien aunque no haya CSS, y además el
                         navegador le reserva el hueco antes de descargarla, con lo que
                         la barra no da el salto al aparecer. */ ?>
                <?php $logoTopbar = logoInstitucion(); ?>
                <img src="<?= e($logoTopbar['url']) ?>" alt="" class="topbar-logo"
                     width="38" height="38">
                <div class="topbar-identidad">
                    <span class="topbar-eyebrow">Protección de Datos</span>
                    <div class="topbar-titulo" title="<?= e($institucionNombre ?: 'Red Educativa Arquidiocesana') ?>">
                        <?= e($institucionNombre ?: 'Red Educativa Arquidiocesana') ?>
                    </div>
                </div>
            </div>
            <div class="topbar-usuario">
                <div class="usuario-meta" style="text-align:right;">
                    <strong><?= e($_SESSION['username'] ?? '') ?></strong><br>
                    <?= e(implode(', ', $_SESSION['roles'] ?? [])) ?>
                </div>
                <div class="avatar-usuario"><?= e(iniciales($_SESSION['username'] ?? '?')) ?></div>
                <?php /* En un teléfono el texto de estos botones se lleva el ancho que
                         necesita el nombre de la institución, así que ahí queda solo el
                         icono; el nombre sigue en el title y en aria-label, de modo que
                         quien navega con lector de pantalla no pierde nada. */ ?>
                <a href="<?= e(APP_ROOT) ?>cambiar_clave.php" class="logout-btn"
                   title="Cambiar mi contraseña" aria-label="Cambiar mi contraseña">
                    <span aria-hidden="true">🔑</span><span class="solo-ancho">Contraseña</span>
                </a>
                <a href="<?= e(APP_ROOT) ?>logout.php" class="logout-btn"
                   title="Cerrar sesión" aria-label="Cerrar sesión">
                    <span aria-hidden="true">⏻</span><span class="solo-ancho">Salir</span>
                </a>
            </div>
        </header>

        <div class="content-wrap">
            <?php if (!empty($breadcrumb)): ?>
            <div class="breadcrumb">
                <a href="<?= e(APP_ROOT) ?>dashboard.php">Panel Principal</a>
                <?php foreach ($breadcrumb as $i => $item): ?>
                    &nbsp;/&nbsp;
                    <?php if (!empty($item['url'])): ?>
                        <a href="<?= e(APP_ROOT . $item['url']) ?>"><?= e($item['label']) ?></a>
                    <?php else: ?>
                        <span class="actual"><?= e($item['label']) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($flash): ?>
                <div class="alerta alerta-<?= e($flash['tipo']) ?>"><?= e($flash['mensaje']) ?></div>
            <?php endif; ?>
