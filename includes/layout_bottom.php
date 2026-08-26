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
</body>
</html>
