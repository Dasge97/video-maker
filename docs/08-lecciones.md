# 8. Lecciones de las pruebas de new-studio

Fallos vistos en seis pruebas reales de new-studio. El detalle está en
`C:\AreaDeTrabajo\new-studio\docs\videos-pendientes.md`. Aquí van los que afectan a video-maker y
qué hacer con cada uno.

## Ya resueltos en el código que se copia (no deshacer)

| Fallo | Dónde está el arreglo |
|---|---|
| La primera palabra se cortaba | `VoiceTimeline`: 0,3 s de silencio al principio y cortes 0,15 s antes de cada palabra |
| Bandas negras arriba y abajo | `Ffmpeg::segment` escala y recorta (`force_original_aspect_ratio=increase,crop`), no rellena |
| La palabra resaltada no se veía sobre tarjetas del color de la marca | `BrandStyle::highlightOn` y las zonas lisas que `Montage` pasa a `AssBuilder` |
| La música se cortaba antes del final | Se pide 4 s más larga y se funde en el montaje (`FfmpegRenderer::audioFilter`) |
| Textos y logo bajo los botones de Reels | Zona segura de `AssBuilder` (`SAFE_TOP`, `SAFE_BOTTOM`, `SAFE_SIDE`) |
| La tipografía dependía de las fuentes del ordenador | `VideoFonts` y `fontsdir` en el filtro `ass` |
| Volumen desigual y música demasiado baja con voz | `sidechaincompress` suave y `loudnorm` a −14 LUFS |
| El modelo de vídeo pintaba textos e instrucciones | `PromptBuilder::withoutWriting`, `NO_TEXT` y `KEEP_START`. Las restricciones no van al prompt |
| Cortes dentro de un clip | La transición nunca va en el prompt y se pide «one single continuous shot» |
| Un logo mandado como «producto» al modelo de vídeo | `PromptBuilder::unusableReference`: una imagen con proporción mayor que 2 nunca es referencia |
| Imagen de arranque recortada desde el centro, cortando cabezas | `FalVideoGateway::frameUri` conserva la parte de arriba o rellena con fondo desenfocado |
| Imagen de arranque con otra cara | `picturePrompt` edita la foto de la persona, no la describe |

## Evitados por el diseño de video-maker

| Fallo en new-studio | Por qué aquí no pasa |
|---|---|
| La cara de la persona no cuadraba con su voz y se congelaba al final (lip-sync) | Kling AI Avatar genera a la persona a partir del audio |
| Un cambio pequeño rehacía todo en cadena y costaba hasta 5 $ | Un plan es una versión entera. Los ficheros se reutilizan por hash |
| Tareas que se esperaban entre sí para siempre | No hay tareas |
| Fichas desfasadas en el vídeo | No hay fichas: la foto y el logo están en la carpeta del vídeo |
| Generación colgada si se perdía un mensaje de la cola | No hay cola. El worker lee `job.json` en cada vuelta |
| Cada agente con una regla distinta de ritmo de voz | Hay un solo agente y una sola regla, también en `PlanChecks` |
| Las mejoras del revisor no se aplicaban | No hay revisor. Las comprobaciones son código |
| La descarga no llevaba la extensión `.mp4` | Se fija el nombre en la cabecera `Content-Disposition` |

## A vigilar en la implementación

1. **Pagar dos veces.** Apunta el `request_id` en `job.json` antes de esperar. Reintenta la
   descarga, no el clip. No apuntes como gastado un clip que fal.ai rechazó. Cancela en fal.ai un
   clip abandonado. En new-studio fueron los fallos 21, 22 y 23.
2. **Rótulo que tapa la cara** (fallos 28 y 36). Ningún rótulo `top` ni `center` sobre
   `talking_person`. Lo comprueba `PlanChecks`.
3. **Duración que se pasa tras un cambio** (fallo 34). `PlanChecks` se pasa también al editar a
   mano, no solo con el agente.
4. **Logo sin contraste en el `logo_card`** (fallo 33). Si el fondo es liso, usa el color de marca
   con más contraste frente al logo: compara con `BrandStyle::contrast` contra el color medio del
   logo. Si ninguno llega a 3:1, usa blanco o casi negro.
5. **El modelo de vídeo cambia la escena a mitad de clip** (fallo 38). Clips cortos, de 5 s como
   máximo cuando se pueda, y una sola acción por clip.
6. **Instrucciones pintadas como texto** (fallo 37). El prompt del agente lo prohíbe y
   `PlanChecks` rechaza comillas en los campos de la escena. Si aun así aparece texto en un clip,
   la persona puede editar `scene.action` y regenerar solo esa pieza.
7. **Voz que entra de golpe** (fallo 31). Pendiente de oír. Si se nota, un fundido de entrada de
   0,05 s en cada trozo de `voice.wav`.
