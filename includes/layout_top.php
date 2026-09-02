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
                <button class="menu-toggle" onclick="document.getElementById('sidebarApp').classList.toggle('abierto')">☰</button>
                <div>
                    <span class="topbar-eyebrow">Protección de Datos</span>
                    <div class="topbar-titulo">
                        <?= e($institucionNombre ?: 'Red Educativa Arquidiocesana') ?>
                        <?php if (!empty($_SESSION['institucion_visita'])): ?>
                            <?php /* El SuperAdmin entra en cualquier institución: se le recuerda
                                     en cuál está trabajando, porque no es la suya. */ ?>
                            <span class="badge-institucion" title="Está trabajando en una institución distinta a la de su cuenta">
                                otra institución
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="topbar-usuario">
                <div class="usuario-meta" style="text-align:right;">
                    <strong><?= e($_SESSION['username'] ?? '') ?></strong><br>
                    <?= e(implode(', ', $_SESSION['roles'] ?? [])) ?>
                </div>
                <div class="avatar-usuario"><?= e(iniciales($_SESSION['username'] ?? '?')) ?></div>
                <a href="<?= e(APP_ROOT) ?>cambiar_clave.php" class="logout-btn" title="Cambiar mi contraseña">Contraseña</a>
                <a href="<?= e(APP_ROOT) ?>logout.php" class="logout-btn" title="Cerrar sesión">Salir</a>
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
