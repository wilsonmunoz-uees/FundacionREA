# Logotipos de las instituciones educativas

Un archivo por institución, en PNG, nombrado con el **código de la institución
en dos dígitos**:

```
01.png   Unidad Educativa Particular Cardenal Richard Cushing
02.png   Unidad Educativa Fiscomisional San Juan Bosco
...
21.png   Unidad Educativa Particular Las Cumbres
```

El código es el mismo `id` que la institución tiene en la tabla
`institucion_educativa`. La institución 7 es `07.png`, no `7.png`.

## Dónde se usan

- La **barra superior** de todas las pantallas, junto al nombre de la
  institución en la que se está trabajando.
- La **cabecera de los reportes**, tanto en pantalla como en el PDF que se
  descarga, junto al nombre completo de la institución.

Todo eso sale de una sola función, `logoInstitucion()` en
`includes/functions.php`. Si hay que cambiar dónde viven los archivos o cómo se
llaman, ese es el único sitio que se toca.

## Si una institución no tiene su logotipo

No pasa nada: se usa el de la Red Educativa Arquidiocesana (`assets/logo.png`).
Un reporte sin ninguna marca se ve roto, y el de la red siempre es cierto porque
todas las instituciones pertenecen a ella.

Para añadir el que falte, basta con dejar el archivo aquí con el nombre que le
toca. No hay nada que registrar en la base ni en el código.

## Cómo deben ser los archivos

- **PNG**, cuadrados. Los actuales son de 150 × 150 px, que es suficiente: en
  pantalla se muestran a 38 px y en el PDF a unos 11 mm.
- La **transparencia se admite**. Al incrustarlos en el PDF se rellena el fondo
  con blanco, que es el color de la hoja.
- Conviene que el escudo llegue hasta los bordes del cuadrado: los márgenes
  vacíos dentro de la imagen lo hacen verse más pequeño de lo que es.

## El reporte de la red no lleva estos logotipos

*Tablero Comparativo de la Red Educativa* abarca a las 21 instituciones a la
vez, así que lleva el logotipo de la Red. Poner el de una escuela diría algo
falso sobre el alcance del documento.
