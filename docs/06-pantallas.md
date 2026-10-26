# 6. Pantallas

Tres pantallas en Twig, con un poco de JavaScript para consultar el estado y editar los textos.

**Estilo** (decidido por la persona el 25-10-2026): el de la sección de vídeos de new-studio
(`frontend/video/src/styles/v5.css` y `views/v5/`), sin pantallas largas. Barra de arriba con
pestañas, una pregunta grande por pantalla, una barra de progreso de cinco tramos y una barra fija
abajo con «Atrás» y la acción siguiente. «Subir» es un asistente de cuatro preguntas: la
referencia; la persona, el logo y la marca; qué se anuncia; y cómo se cuenta. La propuesta va en
dos columnas: el móvil con la vista previa y una tarjeta compacta por pieza que se edita en el
sitio. Referencia, rótulos y música van en paneles plegables. Los campos de cada pantalla son
los de abajo.

Una cuarta pantalla, «Mis vídeos», lista las carpetas de `var/jobs` con fecha, estado y miniatura.

Una quinta pantalla, «Crear imagen», está fuera de la barra de pasos (ver más abajo).

## 1. Subir

Un solo formulario:

| Campo | Tipo | Notas |
|---|---|---|
| Vídeo de referencia | Fichero o enlace | mp4, mov o webm. Hasta 90 s y 200 MB. O un enlace público, que se descarga con yt-dlp en el paso «Comprobar ficheros» |
| Foto de la persona | Fichero o imagen creada | JPEG, PNG o WebP. Se avisa si la cara se ve pequeña o de perfil: el resultado sale mejor con una foto de frente y de medio cuerpo. Un botón abre las imágenes de «Crear imagen» para elegir una |
| Logo | Fichero o imagen creada, opcional | JPEG, PNG o WebP. Mejor PNG con fondo transparente |
| Modo | Dos opciones grandes | «La persona habla a cámara» o «Voz en off» |
| Voz | Desplegable con botón de escuchar | Voces de ElevenLabs (`voices()` y `sample()`). En el modo persona hablando, es la voz de la persona. En voz en off, la del narrador |
| Duración | 10, 15, 20 o 30 s | 15 por defecto |
| Qué se anuncia | Texto, hasta 1.000 caracteres | Indicación: «Producto o servicio, el mensaje principal, el precio u oferta si quieres que salga, y qué debe hacer quien lo vea» |
| Nota sobre la referencia | Texto, opcional | «Qué te gusta de este vídeo» |
| Marca | Nombre, dos colores y fuente | Los colores salen del logo al subirlo (`LogoColors`) y se pueden cambiar. La fuente se elige entre las 8 de `resources/fonts`, Poppins por defecto |
| Palabras prohibidas | Texto, opcional | Separadas por comas |
| Calidad | Equilibrada o Alta | `balanced` o `high` |

Al pulsar «Preparar propuesta» se crea la carpeta del vídeo con `status: analysing` y se pasa a la
pantalla 2. Mientras el worker trabaja, la pantalla enseña en qué paso está: «Leyendo la
referencia», «Transcribiendo» o «El agente prepara la propuesta». Consulta el estado cada 2 s.

## 2. Propuesta

Arriba, el `summary` del agente y lo que ha tomado de la referencia (`reference.summary` y
`rhythm`). Junto a cada tramo de la referencia, su fotograma.

Debajo, una tarjeta por pieza, en orden:

- Número, `title`, tipo de pieza con un nombre llano («Persona a cámara», «Clip», «Imagen con
  movimiento», «Tarjeta de texto», «Logo») y duración prevista.
- Fotograma de la referencia que imita, si lo hay.
- **Voz:** texto editable.
- **Qué se ve:** `scene.action` y `startImage`, editables.
- **Texto de la tarjeta** o **texto bajo el logo**: editable.
- Coste de esa pieza.

Después, los rótulos (`overlays`), editables: texto, posición y de qué pieza a qué pieza. La música
(estilo y ánimo) y un interruptor de subtítulos.

Al editar un texto, la pantalla cuenta las palabras y avisa en el momento si la voz no cabe en la
pieza, con la misma regla de `PlanChecks` (2,5 palabras por segundo). «Guardar cambios» manda el
plan al servidor. El servidor pasa `PlanChecks` y guarda una versión nueva, o devuelve los errores
junto a cada campo.

A la derecha, o abajo en el móvil:

- **Pedir un cambio al agente:** un campo de texto y el botón «Pedir cambio» (pasa a `revising`).
  Las `questions` del agente salen aquí como sugerencias.
- **Coste estimado** total, línea a línea.
- **Generar vídeo:** botón principal. Pasa a `generating`.

## Crear imagen

Pantalla aparte, enlazada desde el menú y desde los campos de foto y logo de «Subir».

- Un campo de prompt y un selector de tipo: «Persona» (vertical, `1024x1536`), «Logo»
  (cuadrado, `1024x1024`, fondo transparente) u «Otra» (cuadrada).
- Opcional: subir una imagen de partida para editarla en vez de crear desde cero.
- Botón «Generar». La llamada la hace la web directamente (es una sola petición de unos
  segundos), con el coste aproximado escrito junto al botón.
- Debajo, la galería de imágenes creadas, con su prompt y su fecha. Cada una tiene «Usar como
  foto», «Usar como logo» y «Borrar».
- Se guardan en `var/library/<hash>.png` con un `<hash>.json` al lado (prompt, tipo, modelo,
  coste, fecha). El hash es el del tipo, el prompt y la imagen de partida: el mismo prompt no
  se paga dos veces.

Es la única excepción a la regla de que la web no llama a proveedores de pago.

## 3. Vídeo

Mientras se genera:

- Barra con los pasos: voz, imágenes, clips (3 de 5), música, montaje.
- Las imágenes de arranque aparecen en cuanto están hechas, para ver pronto si la persona se
  parece.
- Si un paso falla, el mensaje en castellano y el botón «Reintentar».

Cuando está listo:

- Reproductor vertical.
- Botón «Descargar», que entrega `anuncio-<fecha>.mp4` con la extensión `.mp4`.
- Coste real total.
- Avisos del montaje, si los hay. Por ejemplo, «La música no se pudo generar: el vídeo va sin
  música».
- «Editar textos y volver a montar»: vuelve a la propuesta. Si solo cambian rótulos, tarjetas,
  música o subtítulos, al generar solo se repite el montaje, gratis. Si cambia la voz, se repite
  la voz y los clips cuya duración pedida cambia. Lo demás se reutiliza por su hash.
