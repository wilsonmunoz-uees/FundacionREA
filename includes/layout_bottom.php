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
<?php /* Regla del teléfono para toda pantalla que capture uno: basta con marcar
         el campo con data-telefono. Ver api/core/Telefono.php, que es quien
         decide al guardar. */ ?>
<script src="<?= e(APP_ROOT) ?>js/telefono.js" defer></script>
</body>
</html>
