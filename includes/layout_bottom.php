<?php
/**
 * includes/layout_bottom.php
 * Cierre común de la estructura HTML abierta en layout_top.php.
 */
?>
            <div class="footer-app">
                Sistema de Gestión de Protección de Datos &mdash; Red Educativa Arquidiocesana (REA)
            </div>
        </div>
    </div>
</div>
<?php
// Subpantalla de búsqueda (personas o usuarios): se imprime solo si la página
// usó el componente selectorEntidad() (includes/selector_entidad.php).
if (function_exists('selectorEntidadModal')) {
    selectorEntidadModal();
}
?>
<?php /* Reglas de captura para toda pantalla del sistema. Avisan mientras se
         escribe, pero quien decide al guardar es el servidor:

           · teléfono  -> marcar el campo con data-telefono   (api/core/Telefono.php)
           · correo    -> se engancha solo a input[type=email] (api/core/CorreoElectronico.php)

         El documento lo carga campos_persona.php, que es quien lo dibuja. */ ?>
<script src="<?= e(APP_ROOT) ?>js/telefono.js" defer></script>
<script src="<?= e(APP_ROOT) ?>js/correo.js" defer></script>
<?php /* El menú lateral como cajón en tableta y teléfono: fondo para cerrar,
         tecla Esc y bloqueo del desplazamiento de detrás. */ ?>
<script src="<?= e(APP_ROOT) ?>js/menu_movil.js" defer></script>
</body>
</html>
