# 5. Proveedores

Todas las claves van en `.env.local`, que no se versiona. Ver `CLAUDE.md`, sección «Claves de los
proveedores».

| Variable | Para qué |
|---|---|
| `FAL_KEY` | Clips y persona hablando |
| `ELEVENLABS_API_KEY` | Voz y música |
| `OPENAI_API_KEY`, `OPENAI_IMAGE_MODEL` | Imágenes de arranque y fondos. Modelo por defecto `gpt-image-2.5-sunburst`, el mejor editando según `src/Ai/AiModels.php` de new-studio |
| `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL` | El agente |
| `AIHUB_URL`, `AIHUB_API_KEY` | Transcripción de la referencia |
| `VIDEO_MODEL` | `balanced` (por defecto) o `high` |

Los precios de fal.ai se comprobaron el 25-10-2026 con su API de precios
(`GET https://api.fal.ai/v1/models/pricing?endpoint_id=…`). Los de ElevenLabs siguen sin confirmar.
Resultados de las pruebas de la fase 3 en `var/probe/` (no se versiona).

## fal.ai: clips (Kling O3 reference-to-video)

Se copia `FalVideoGateway` (ver `03-que-copiar.md`). Resumen de lo que hace:

- `POST https://queue.fal.run/{endpoint}` con `Authorization: Key …`, `X-Fal-Store-IO: 0` y el
  cuerpo de `FalVideoGateway::body`. Devuelve `request_id`, `status_url`, `response_url` y
  `cancel_url`.
- `GET status_url` devuelve `IN_QUEUE`, `IN_PROGRESS` o `COMPLETED`. Si viene `error`, ha fallado.
- `GET response_url` devuelve `video.url`, que se descarga a `clips/<hash>.mp4`.
- Las imágenes van como data URI en JPEG, preparadas por `dataUri` y `frameUri`: transparencia
  aplanada, proporción entre 0,4 y 2,5, lado largo de 1024 px y lado corto de 300 px como mínimo.

| id | Endpoint | Duración | $/s |
|---|---|---|---|
| `balanced` | `fal-ai/kling-video/o3/standard/reference-to-video` | 3–15 s, como texto `"5"` | 0,14 |
| `high` | `fal-ai/kling-video/o3/pro/reference-to-video` | 3–15 s, como texto `"5"` | 0,14 |

new-studio tenía 0,084 y 0,112 $/s. La API de precios da 0,14 $/s a los dos: la calidad alta no
cuesta más.

- La persona va en `elements: [{frontal_image_url, reference_image_urls}]` y se cita en el prompt
  como `@Element1`.
- El fotograma de la referencia va en `image_urls` y se cita como `@Image1`.
- La imagen de arranque va en `start_image_url`.
- Como máximo 4 imágenes por clip.
- Kling admite `start_image_url` junto con `elements` (prueba del 25-10-2026). Un clip con la
  persona lleva las dos cosas. La prueba devolvió un vídeo de 720×1280 y 3,04 s, en unos 2 minutos.
- Una pieza de 2,4 s se pide de 3 s, el mínimo del modelo, y el montaje la recorta.
  `VideoCatalog::billedSeconds` hace este cálculo.

## fal.ai: persona hablando (Kling AI Avatar v2)

Clase nueva, `FalAvatarGateway`. Usa la misma cola de fal.ai que los clips.

| Endpoint | $/s de vídeo |
|---|---|
| `fal-ai/kling-video/ai-avatar/v2/standard` (por defecto) | 0,0562 |
| `fal-ai/kling-video/ai-avatar/v2/pro` | 0,115 |

- Cuerpo: `{ "image_url": …, "audio_url": …, "prompt": "…" }`.
- `image_url` es la imagen de arranque de la pieza, en 9:16. `audio_url` es el trozo de `voice.wav`
  de la pieza, en MP3 o WAV.
- `prompt` describe solo el gesto y la actitud («habla a cámara con naturalidad, gestos suaves con
  las manos»). Se construye con `scene.action` pasado por `PromptBuilder::withoutWriting`.
- El vídeo dura lo mismo que el audio. Llega en `video.url`, con el audio incluido. El montaje usa
  solo la imagen: la voz sale de `voice.wav`.
- Comprobado el 25-10-2026 con una pieza suelta:
  - Acepta el audio como data URI en `audio_url`.
  - Con 3,39 s de audio devuelve 3,47 s de vídeo, a 720×1280, en unos 2 minutos.
  - El precio de la tabla.
- Pendiente de que la persona lo oiga: si la boca cuadra con la voz en castellano
  (`var/probe/avatar.mp4`).
- Añadir a `config/sound.yaml`:

```yaml
avatar:
  default: standard
  models:
    standard: { endpoint: fal-ai/kling-video/ai-avatar/v2/standard, usdPerSecond: 0.0562, minSeconds: 2, maxSeconds: 10 }
    pro:      { endpoint: fal-ai/kling-video/ai-avatar/v2/pro, usdPerSecond: 0.115, minSeconds: 2, maxSeconds: 10 }
```

Documentación: https://fal.ai/models/fal-ai/kling-video/ai-avatar/v2/standard y
https://fal.ai/docs/model-api-reference/video-generation-api/kling-ai-avatar

## ElevenLabs: voz

Se copian `ElevenLabsClient` y `ElevenLabsVoiceGateway`.

- `POST /v1/text-to-speech/{voice}/with-timestamps?output_format=mp3_44100_128` con
  `{text, model_id: "eleven_multilingual_v2"}`. Devuelve `audio_base64` y el tiempo de cada
  carácter, que `VoiceTimeline` usa para los cortes y los subtítulos.
- `GET /v2/voices` lista las voces para elegir en la primera pantalla.
- Voz por defecto: `gD1IexrzCvsXPHUuT0s3` («Audicion — Sara Martin», castellano peninsular),
  elegida por la persona el 25-10-2026. La otra opción que le gustó es «Audicion — Selena»
  (`W0HU8ZBOwXO2C6hd1BEX`). La pantalla «Subir» enseña primero las voces en castellano.
- 0,08 $ por cada 1.000 caracteres (sin confirmar).

## ElevenLabs: música

Se copia `ElevenLabsMusicGateway`.

- `POST /v1/music` con `{prompt, music_length_ms, model_id: "music_v1", force_instrumental: true}`.
- Se pide 4 s más larga que el vídeo. El montaje la funde al final.
- Prompt: `"{estilo}. {ánimo}. Música instrumental, sin voces, para un vídeo corto de redes
  sociales, con energía constante hasta el final."`, con el estilo y el ánimo pasados por
  `withoutSeconds`.
- 0,15 $/min (sin confirmar).

## OpenAI: imágenes

Se adapta `OpenAiImageGenerator` de new-studio.

- Sin foto de partida: `POST /v1/images/generations`.
- Editando la foto de la persona: `POST /v1/images/edits`, multipart con la imagen.
- Tamaño para 9:16: `864x1536` (`PromptBuilder::pictureSize`).
- Prompts con `PromptBuilder::picturePrompt`: imagen de arranque con la persona, imagen de arranque
  sin ella y fondo de tarjeta o de logo.
- El coste real se lee de `usage` en la respuesta.

## AI Hub: transcripción

Se copia `Transcriber`.

- `POST {AIHUB_URL}/v1/transcribe`, multipart con `file` y `request={"timestamps":"segment"}`.
- Después `GET /v1/jobs/{id}` cada 3 s, hasta 600 s.
- Devuelve `{available, text, language, segments[{start, end, text}]}`.
- Antes de mandar el vídeo, se extrae solo el audio con
  `ffmpeg -i ref.mp4 -vn -ac 1 -ar 16000 audio.wav`, para no subir el vídeo entero.

## Coste orientativo de un anuncio de 15 s

| Concepto | Modo persona hablando | Modo voz en off |
|---|---|---|
| Agente (1 plan) | según el modelo | según el modelo |
| Imágenes (3–4) | ~0,20 $ | ~0,20 $ |
| Persona hablando (8 s, Standard) | ~0,45 $ | — |
| Clips Kling O3 Standard (2 de 3–4 s) | ~0,60 $ | ~1,00 $ (3 clips) |
| Voz y música | ~0,05 $ | ~0,05 $ |
| **Total sin el agente** | **~1,30 $** | **~1,25 $** |

`Estimate` calcula la cifra de cada plan con los catálogos y la pantalla de la propuesta la enseña
línea a línea.
