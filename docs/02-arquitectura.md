# 2. Arquitectura

## Piezas

```
Navegador ──► Symfony (controladores + Twig)
                 │  escribe la carpeta del vídeo y su job.json
                 ▼
             var/jobs/<id>/
                 ▲
                 │  lee job.json, hace el siguiente paso y lo vuelve a escribir
             php bin/console app:worker   (un único proceso de fondo)
                 │
                 ├── ffmpeg / ffprobe
                 ├── AI Hub (transcripción)
                 ├── Anthropic (el agente)
                 ├── OpenAI Images (imágenes de arranque y fondos)
                 ├── ElevenLabs (voz y música)
                 └── fal.ai (clips y persona hablando)
```

- **Web:** Symfony 7.4 con Twig. Sirve las pantallas y unos pocos endpoints JSON para consultar el
  estado. Nunca llama a un proveedor de pago. Solo escribe ficheros y cambia el estado de `job.json`.
- **Worker:** `app:worker` es un comando que recorre `var/jobs/*/job.json` cada 2 segundos. Cada
  vez hace el siguiente paso pendiente de un vídeo y guarda el estado. Para trabajar con un vídeo
  toma un bloqueo de fichero (`job.lock` con `flock`), así dos procesos no tocan el mismo vídeo.
  Se arranca a mano junto al servidor. Puede añadirse un script de composer que arranque los dos.

## Dependencias de composer

`symfony/framework-bundle`, `symfony/twig-bundle`, `symfony/http-client`, `symfony/process`,
`symfony/yaml`, `symfony/mime`, `symfony/uid`, `symfony/console`, `symfony/dotenv`,
`intervention/image` ^4.4 y `opis/json-schema` ^2.6. Todas en las mismas versiones que
`C:\AreaDeTrabajo\new-studio\composer.json`.

## Carpetas del proyecto

```
video-maker/
  bin/console
  config/
    models.yaml            ← copia recortada de new-studio/config/video/video_models.yaml
    sound.yaml             ← copia recortada de new-studio/config/video/sound.yaml
  prompts/
    agente.md              ← prompt del agente (borrador ya escrito)
    plan.schema.json       ← esquema de su salida (ya escrito)
  resources/fonts/         ← las 8 fuentes de new-studio/resources/fonts/video (+ licencias)
  src/
    Command/WorkerCommand.php
    Controller/            ← pantallas y endpoints
    Job/                   ← Job (lee y escribe job.json), JobStore, Steps (los pasos)
    Agent/                 ← AnthropicClient, PlanAgent, PlanChecks
    Reference/             ← VideoTools, Transcriber, Frames
    Providers/             ← copiados: FalVideoGateway, ElevenLabs*, OpenAiImages, FalAvatar
    Render/                ← copiados: AssBuilder, BrandStyle, VideoFonts, FfmpegRenderer, Ffmpeg
    Sound/                 ← copiados: VoiceTimeline, Speech
    Prompt/PromptBuilder.php  ← copiado y recortado
  templates/
  var/jobs/                ← un vídeo por carpeta (no se versiona)
  var/library/             ← imágenes de «Crear imagen» (no se versiona)
```

## La carpeta de un vídeo

```
var/jobs/<id>/
  job.json
  job.lock
  log.txt                     ← una línea por paso, por llamada a proveedor y por error
  input/  reference.mp4  person.jpg  logo.png
  reference/  frame-00.jpg …  frames.json  transcript.json
  agent/  plan-001.json  plan-002.json …   ← cada versión del plan
          conversation-001.json …          ← mensajes enviados y recibidos (sin claves)
  audio/  speech-<hash>.mp3  speech-<hash>.json  voice.wav  music.mp3
  images/ <hash>.png          ← imágenes de arranque y fondos, por el hash de su prompt
  clips/  <hash>.mp4          ← clips, por el hash de lo que se pidió
  out/    video.mp4  poster.jpg
```

Los ficheros de `audio/`, `images/` y `clips/` se nombran por el hash de lo que se pidió:
proveedor, modelo, prompt, duración y hash de las imágenes de entrada. Si la persona cambia un
texto y vuelve a generar, todo lo que no ha cambiado se reutiliza sin pagar. new-studio lo hace
igual (`Soundtrack::speech`, `Montage::lipSync`).

## job.json

```json
{
  "id": "01JD…",
  "createdAt": "2026-10-25T10:00:00+02:00",
  "status": "analysing",
  "step": "frames",
  "error": null,
  "input": {
    "mode": "talking_person",
    "durationSeconds": 15,
    "product": "Texto libre: qué se anuncia, mensaje, precio si lo hay, llamada a la acción",
    "note": "Nota opcional de la persona sobre la referencia",
    "voiceId": "JBFqnCBsd6RMkjVDRZzb",
    "model": "balanced",
    "brand": { "name": "OVE", "colors": "#FF6A00, #1D1D1B", "font": "Poppins", "forbiddenWords": [] }
  },
  "reference": { "durationSeconds": 22.4, "hasAudio": true, "frames": 14, "transcript": true },
  "plan": { "version": 2, "file": "agent/plan-002.json", "warnings": [] },
  "estimate": { "usd": 2.10, "lines": [ { "what": "Clip 2 (Kling O3 Standard, 4 s)", "usd": 0.34 } ] },
  "providerJobs": {
    "clip:<hash>": { "endpoint": "…", "requestId": "…", "statusUrl": "…", "responseUrl": "…", "submittedAt": "…", "state": "submitted" }
  },
  "costs": [ { "at": "…", "what": "voice", "usd": 0.02 } ],
  "result": { "file": "out/video.mp4", "durationSeconds": 15.3 }
}
```

### Estados

| status | Quién lo pone | Qué hace el worker |
|---|---|---|
| `analysing` | La web, al subir los ficheros | Pasos de análisis: comprobar ficheros, fotogramas, transcripción, agente |
| `proposal` | El worker, cuando hay un plan válido | Nada. Espera a la persona |
| `revising` | La web, cuando la persona pide un cambio al agente | Llama al agente con el plan actual y la petición. Vuelve a `proposal` |
| `generating` | La web, cuando la persona pulsa «Generar» | Pasos de generación: voz, imágenes, clips, música, montaje |
| `ready` | El worker | Nada. El vídeo está en `out/video.mp4` |
| `failed` | El worker | Nada. `error` dice qué paso falló y por qué, en castellano |

Desde `failed` la persona puede pulsar «Reintentar». El estado vuelve al anterior y el worker
sigue por el paso que falló. Lo que ya está en disco no se repite.

Un plan editado a mano por la persona se guarda como una versión nueva del plan. Antes de
guardarlo se pasan las comprobaciones (ver `04-agente.md`).

## Los pasos

### Análisis (`analysing`)

1. **Comprobar ficheros.** Si la referencia llegó como enlace, primero se descarga con
   `yt-dlp -f "mp4/best" --max-filesize 200M -o input/reference.mp4 <enlace>` (con
   `symfony/process`). Si falla (Instagram pide a veces iniciar sesión), el vídeo pasa a `failed`
   con el mensaje «No se ha podido descargar el vídeo del enlace. Descárgalo y súbelo como
   fichero.». El vídeo de referencia se mira con ffprobe: mp4, mov o webm, máximo
   90 s y 200 MB. La foto y el logo deben ser JPEG, PNG o WebP. La foto se reescala a 1536 px como
   máximo. Del logo se sacan dos colores principales con Intervention Image, sin contar el blanco,
   el negro ni el fondo transparente. Esos dos colores se proponen como colores de marca y la
   persona puede cambiarlos.
2. **Fotogramas.** Primero se detectan los cortes reales con
   `ffmpeg -i ref.mp4 -vf "select='gt(scene,0.3)',showinfo" -f null -`, leyendo `pts_time` de la
   salida. Se toma un fotograma en el centro de cada plano detectado, a 768 px de ancho, con un
   máximo de 16. Si hay menos de 4 cortes, se muestrea a intervalos fijos como hace hoy
   `VideoTools`. `frames.json` guarda `{index, atSeconds, shotStart, shotEnd, path}`.
3. **Transcripción.** AI Hub con `Transcriber`. Si falla o no hay audio, se sigue sin ella y se
   apunta un aviso.
4. **Agente.** `PlanAgent` envía todo a Claude y recibe el plan. Las comprobaciones le devuelven
   los errores, con un máximo de 3 vueltas. El resultado pasa a `proposal` con el coste estimado.

### Generación (`generating`)

Los pasos van en este orden. La voz va primero porque fija la duración de cada pieza.

1. **Voz.** Cada hablante se lee en una sola llamada a ElevenLabs. En el modo persona hablando
   hay un hablante, la persona. En el modo voz en off, el narrador. `VoiceTimeline::build` reparte
   la voz entre las piezas y devuelve la duración real de cada una, las palabras con sus tiempos
   para los subtítulos y el total. Se monta `voice.wav` con el método de
   `Soundtrack::voiceTrack` de new-studio.
2. **Imágenes.** Se hacen las imágenes de arranque y los fondos que pide el plan con OpenAI
   Images. Una imagen de arranque con la persona se hace editando su foto, para que la cara sea
   la suya.
3. **Clips.** Se envían todos a fal.ai, con un máximo de 3 a la vez, y se consulta su estado cada
   10 s.
   - `talking_person`: Kling AI Avatar v2. Recibe la imagen de arranque de la pieza (o la foto) y
     el trozo de `voice.wav` que suena en la pieza. El vídeo dura lo que el audio.
   - `generated_clip`: Kling O3 reference-to-video. Si la persona sale, su foto va como
     `element`. Si hay imagen de arranque, va como `start_image_url`. La duración pedida es
     `ceil(duración de la pieza)`, dentro del rango del modelo.
4. **Música.** ElevenLabs Music, 4 s más larga que el vídeo. Si falla, el vídeo sale sin música
   y con un aviso. La música no bloquea el vídeo.
5. **Montaje.** Se arma el trabajo de `FfmpegRenderer` igual que hace `Montage::render` en
   new-studio, sin base de datos (ver `03-que-copiar.md`). Después se saca `poster.jpg` del
   segundo 1.

### Reglas del paso de clips (el que cuesta dinero)

- Antes de enviar un clip se mira `providerJobs["clip:<hash>"]`.
  - Si ya existe y está `submitted`, se consulta su estado. No se reenvía.
  - Si existe `clips/<hash>.mp4`, el clip ya está hecho.
- La respuesta de fal.ai con el `request_id` se guarda en `job.json` en cuanto llega, antes de
  hacer nada más.
- Errores del envío. La clasificación es la de new-studio:
  - `ProviderUnavailable` (429, 502, 503 o sin conexión): no se envió nada. Se reintenta tras 30 s,
    hasta 5 veces.
  - `VideoRejected` (otros 4xx): fal.ai no cobra. Se marca el paso como fallido con el mensaje de
    fal.ai.
  - `ProviderUncertain` (se cortó la conexión o pasó el tiempo de espera): puede que se haya
    cobrado. No se reintenta solo. El vídeo pasa a `failed` con el mensaje «No sabemos si fal.ai
    recibió el clip N. Reintentar puede cobrarlo dos veces.»
- Si la descarga del vídeo falla, se reintenta solo la descarga, hasta 5 veces. El clip no se
  vuelve a pedir.
- Si un clip pasa 20 minutos sin terminar, se cancela en fal.ai con `cancel_url` y se marca fallido.
- El coste se apunta en `costs` solo cuando fal.ai devuelve el clip terminado.
