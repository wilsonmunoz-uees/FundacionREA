/**
 * js/correo_configuracion.js
 * ---------------------------------------------------------------------------
 * Preajustes de proveedor en la Configuración de Correo.
 *
 * Servidor, puerto y seguridad son los tres datos que más se copian mal de
 * cualquier instructivo, y equivocarse en uno da siempre el mismo resultado:
 * «no se pudo enviar», sin decir cuál de los tres está mal. Estos botones los
 * ponen de una vez y explican qué esperar del usuario y de la contraseña en
 * cada caso, que es lo que de verdad cambia entre proveedores.
 *
 * Es una comodidad, no un requisito: el bloque viene oculto en el HTML y solo
 * aparece si este archivo llega a cargarse. Sin él, los campos se escriben a
 * mano exactamente igual que antes.
 */
(function () {
    'use strict';

    var caja = document.getElementById('preajustesCorreo');
    if (!caja) {
        return;
    }

    var preajustes;
    try {
        preajustes = JSON.parse(caja.getAttribute('data-preajustes') || '[]');
    } catch (e) {
        return;
    }
    if (!preajustes.length) {
        return;
    }

    var servidor  = document.getElementById('servidor');
    var puerto    = document.getElementById('puerto');
    var seguridad = document.getElementById('seguridad');
    var ayuda     = document.getElementById('ayudaUsuario');
    if (!servidor || !puerto || !seguridad) {
        return;
    }

    // El texto original de la ayuda, para poder volver a él.
    var ayudaInicial = ayuda ? ayuda.textContent : '';

    var etiqueta = document.createElement('div');
    etiqueta.className = 'form-ayuda';
    etiqueta.style.marginBottom = '6px';
    etiqueta.textContent = 'Empiece por un preajuste y corrija lo que haga falta:';
    caja.appendChild(etiqueta);

    var fila = document.createElement('div');
    fila.className = 'flex-gap';
    fila.style.flexWrap = 'wrap';
    caja.appendChild(fila);

    preajustes.forEach(function (p) {
        var boton = document.createElement('button');
        boton.type = 'button';               // dentro de un form, si no, lo envía
        boton.className = 'btn btn-secundario btn-sm';
        boton.textContent = p.nombre;
        boton.addEventListener('click', function () {
            servidor.value = p.servidor;
            puerto.value   = p.puerto;
            seguridad.value = p.seguridad;
            if (ayuda) {
                ayuda.textContent = p.ayuda || ayudaInicial;
            }
            /* El servidor es lo primero que hay que rematar —el preajuste trae
               «sudominio» de ejemplo—, así que el foco va ahí con el texto
               seleccionado, listo para escribir encima. */
            servidor.focus();
            servidor.select();
        });
        fila.appendChild(boton);
    });

    caja.hidden = false;
}());
