# Pruebas reales

Resultados de las pruebas con proveedores de pago. Las carpetas de cada vídeo están en `var/jobs/`
(no se versionan); las entradas y los planes, en `tests/fixtures/`.

Material de las pruebas del 25-10-2026:

- Referencia: un anuncio UGC vertical de YouTube Shorts (`vNo12pMbo5M`, 86 s, en inglés), dado
  como enlace y descargado con yt-dlp.
- Foto y logo: hechos con «Crear imagen» (0,01 $ cada uno).
- Marca inventada: «Brisa», botella con filtro de carbón, 24,90 €, envío gratis.

## Fase 2: el agente

| | Persona hablando | Voz en off |
|---|---|---|
| Fotogramas de la referencia | 7 (7 planos detectados) | 7 |
| Vueltas de corrección | 2 (un texto de 359 caracteres en un campo de 300) | 1 |
| Coste del agente | 0,31 $ | 0,16 $ |
| Tiempo del análisis completo | 1 min 37 s | 58 s |
| ¿Imita la referencia? | Sí: empieza hablando a cámara con el móvil, alterna planos de producto y persona, cierra con el logo | Sí, con planos de producto y de la persona bebiendo |
| ¿Respeta el ritmo de voz? | Sí | Sí |
| ¿Inventa algo? | No. Solo usa 24,90 € y «envío gratis», que estaban en el texto | No |
| Preguntas que propone | Si hay foto real de la botella; si se quiere una versión de 20–30 s | — |

## Fase 3: proveedores, uno a uno

| Prueba | Resultado |
|---|---|
| Voz de ElevenLabs (Sara Martin) | Un tiempo por cada uno de los 51 caracteres. 3,39 s |
| Imagen de arranque editando la foto | La cara es la de la foto. 864×1536 aceptado. 0,02 $. **La camiseta cambió al color de la marca**: el prompt ya no pasa los colores de marca cuando sale la persona |
| Kling AI Avatar v2 Standard | Acepta el audio como data URI. 3,39 s de audio dan 3,47 s de vídeo a 720×1280. Unos 2 minutos. **Pendiente: oír si la boca cuadra con el castellano** (`var/probe/avatar.mp4`) |
| Kling O3 Standard con `element` e imagen de arranque | fal.ai acepta los dos juntos. 3,04 s a 720×1280. Unos 2 minutos |
| Música de 19 s | 19,04 s |
| Precios (API de precios de fal.ai) | Kling O3 Standard y Pro: **0,14 $/s** (new-studio tenía 0,084 y 0,112). Avatar: 0,0562 $/s, como estaba |

## Fase 4: generación completa

| | Persona hablando | Voz en off |
|---|---|---|
| Piezas | 2 persona a cámara, 1 clip, 1 imagen con movimiento, 1 logo | 4 clips, 1 tarjeta, 1 logo |
| Duración pedida / obtenida | 15 s / 16,2 s | 15 s / 15,0 s |
| Coste estimado de la generación | 1,00 $ (con los precios corregidos) | 1,84 $ (con los precios corregidos) |
| Coste real de la generación | 0,98 $ | 1,81 $ |
| Coste real con el agente | 1,29 $ | 1,97 $ |
| Tiempo de la generación | 3 min 24 s | 4 min 23 s |

Pruebas de recuperación (vídeo de persona hablando):

- Se cerró el proceso de fondo con los tres clips en fal.ai y se volvió a abrir: siguió
  preguntando por ellos y no reenvió ninguno (3 envíos en total).
- Volver a generar sin cambios: 7 s y 0 $.
- Cambiar un rótulo y volver a generar: nueva versión del plan, solo se repite el montaje, 0 $.

Qué se ve mal o falta revisar:

- La boca de la persona hablando: hay que verlo con sonido.
- «24,90 €» se lee bien, pero el subtítulo parte «€» en su propio grupo.
- En la primera prueba con proveedores simulados, los tramos hechos desde un JPEG salían en rango
  de color completo y el montaje perdía las últimas piezas. Corregido en `Ffmpeg::LIMITED` y
  `Ffmpeg::COLOR_TAGS`; lo cubre la prueba de extremo a extremo.
