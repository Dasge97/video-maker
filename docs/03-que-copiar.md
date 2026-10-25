# 3. Qué se copia de new-studio y qué no

Origen: `C:\AreaDeTrabajo\new-studio` (rama `hub-marketing`). Las rutas de origen son relativas a
esa carpeta. Solo se lee: no se cambia nada allí.

Al copiar un fichero:

- El namespace pasa de `App\Video\…` a `App\…` según la carpeta de destino.
- Se quitan las dependencias de base de datos (`EntityManagerInterface`, `Connection`, entidades).
- Se quitan las claves por agencia (`AgencyAi`, `AgencyKeyMissing`, `AiProvider`, `withKey()`).
  La clave se pasa al constructor desde `.env.local` con `%env(FAL_KEY)%` y similares.
  - Las excepciones `ProviderUnavailable` y `ProviderUncertain` viven hoy en
    `src/Video/AgentWorkflow/Gateway/`. Se copian a `src/Providers/`.
  - `AgencyKeyMissing` se sustituye por una excepción propia, `MissingKey`, que dice qué variable
    falta en `.env.local`.
- Los comentarios se dejan como están. Explican por qué se tomó cada decisión, y casi todas vienen
  de un fallo visto en una prueba real.
- Se quitan las referencias a «v5.1», «fase 1» y a documentos de new-studio que aquí no existen,
  pero se conserva la explicación.

## Se copia casi tal cual

| Origen | Destino | Qué se cambia |
|---|---|---|
| `src/Video/VideoGeneration/Render/AssBuilder.php` | `src/Render/AssBuilder.php` | Solo el namespace. Es puro: genera el fichero ASS con subtítulos palabra a palabra, rótulos, tarjetas y la zona segura de Reels |
| `src/Video/VideoGeneration/Render/BrandStyle.php` | `src/Render/BrandStyle.php` | Solo el namespace. Colores y fuente de la marca, con contraste garantizado del resaltado |
| `src/Video/VideoGeneration/Render/VideoFonts.php` | `src/Render/VideoFonts.php` | La carpeta de fuentes pasa a `resources/fonts` |
| `resources/fonts/video/*` y `resources/fonts/video-licenses/*` | `resources/fonts/` y `resources/fonts/licenses/` | Nada |
| `src/Video/VideoGeneration/Render/Renderer.php` | `src/Render/Renderer.php` | Solo el namespace. Documenta el formato del trabajo de montaje |
| `src/Video/VideoGeneration/Render/FfmpegRenderer.php` | `src/Render/FfmpegRenderer.php` | Solo el namespace. Monta pieza a pieza, une, quema el ASS, pone el logo y mezcla voz y música |
| `src/Video/VideoGeneration/Application/Ffmpeg.php` | `src/Render/Ffmpeg.php` | Quitar `testClip`, `testImage`, `tone` y la constante `FONT` (ruta de Linux, solo para clips de prueba) |
| `src/Video/VideoGeneration/Sound/VoiceTimeline.php` | `src/Sound/VoiceTimeline.php` | Solo el namespace. Es puro: reparte la voz entre piezas y fija la duración de cada una |
| `src/Video/VideoGeneration/Sound/Speech.php` | `src/Sound/Speech.php` | Solo el namespace |
| `src/Video/VideoGeneration/Sound/ElevenLabsClient.php` | `src/Providers/ElevenLabsClient.php` | La clave desde `.env.local` |
| `src/Video/VideoGeneration/Sound/ElevenLabsVoiceGateway.php` | `src/Providers/ElevenLabsVoiceGateway.php` | Quitar `withKey`. Mantener `speak`, `voices` (con caché de 1 h en `var/cache`) y `sample` |
| `src/Video/VideoGeneration/Sound/ElevenLabsMusicGateway.php` | `src/Providers/ElevenLabsMusicGateway.php` | Quitar `withKey` |
| `src/Video/VideoGeneration/Gateway/FalVideoGateway.php` | `src/Providers/FalVideoGateway.php` | Quitar `withKey`, webhook y `webhookState`. Mantener `submit`, `status`, `output`, `cancel`, `body`, `frameUri` y `dataUri` |
| `src/Video/VideoGeneration/Gateway/{VideoTask,Submitted,JobState,JobOutput,GatewayJob,VideoRejected}.php` | `src/Providers/` | `GatewayJob` deja de usar `Uuid`: basta con el `requestId` y las URLs que devolvió fal.ai |
| `src/Video/AgentWorkflow/Gateway/{ProviderUnavailable,ProviderUncertain}.php` | `src/Providers/` | Solo el namespace |
| `src/Video/VideoGeneration/Sound/SoundCatalog.php` | `src/Sound/SoundCatalog.php` | Quitar la parte de lip-sync. Mantener `voice`, `music`, `voiceUsd`, `musicUsd` y `MUSIC_TAIL_SECONDS` |
| `src/Video/VideoGeneration/Application/VideoCatalog.php` | `src/Providers/VideoCatalog.php` | Quitar `storeOverrides`, `overrides` y `pricedEndpoints` (la comprobación diaria de precios). Mantener `all`, `get`, `billedSeconds` y `clipUsd` |
| `src/Video/Reference/Application/VideoTools.php` | `src/Reference/VideoTools.php` | Cambiar `ApiException` por una excepción propia. Añadir la detección de cortes (ver `02-arquitectura.md`, paso «Fotogramas») |
| `src/Video/Reference/Application/Transcriber.php` | `src/Reference/Transcriber.php` | Solo el namespace |
| `src/Imaging/OpenAiImageGenerator.php` con `src/Imaging/Dto/{ImageRequest,ImageResult}.php` | `src/Providers/OpenAiImages.php` | Una sola clase con `generate(prompt, size)` y `edit(prompt, size, imagePath)`. La clave y el modelo desde `.env.local` |

## Se copia en parte

| Origen | Qué se lleva | Destino |
|---|---|---|
| `src/Video/VideoGeneration/Application/PromptBuilder.php` | `clipPrompt`, `picturePrompt`, `withoutWriting`, `withoutQuotesNorFonts`, `sentence`, `join`, `same`, `pictureSize`, `unusableReference` y las constantes `NO_TEXT`, `KEEP_START`, `WRITTEN`, `FONTS`, `SCREEN` y `MAX_REFERENCE_RATIO`. Se quita `referencePrompt`, `references` y todo lo que lee fichas o `ReferenceImage`. `clipPrompt` y `picturePrompt` reciben arrays sencillos (ver la tabla de abajo) | `src/Prompt/PromptBuilder.php` |
| `src/Video/VideoGeneration/Sound/Soundtrack.php` | `voiceTrack()` (corta cada trozo de voz y monta `voice.wav`), `withoutSeconds()` y la plantilla del prompt de música de `music()` | `src/Job/Steps/VoiceStep.php` y `MusicStep.php` |
| `src/Video/VideoGeneration/Render/Montage.php` | El bucle de `render()` que convierte las piezas en el trabajo de `FfmpegRenderer`: tarjetas, `logo_card`, ventanas del logo pequeño, zonas lisas, fundidos y la llamada a `AssBuilder::build`. También `logoWindows()` tal cual. Se quita `lipSync()`, el acceso a la base de datos y `BrandLogos` (el logo es `input/logo.png`) | `src/Job/Steps/AssembleStep.php` |
| `src/Video/VideoGeneration/Sound/FalLipSyncGateway.php` | Solo como modelo de llamada a fal.ai con audio: subir el audio y consultar la cola. El lip-sync no se usa | `src/Providers/FalAvatarGateway.php` (nuevo, Kling AI Avatar v2) |
| `src/Video/AgentWorkflow/Gateway/AnthropicModelGateway.php` | La forma del bucle: herramienta `submit_output` con el esquema como `input_schema`, devolver los errores de validación en la misma conversación y caché en el prompt de sistema. Las herramientas de consulta no se copian | `src/Agent/AnthropicClient.php` y `PlanAgent.php` |
| `src/Video/Shared/Schema/SchemaValidator.php` | Validar un array con un esquema de Opis y devolver los errores en texto legible | `src/Agent/SchemaValidator.php` |
| `src/Video/AgentWorkflow/Application/MontageChecks.php` | Las constantes y las reglas, reescritas como funciones puras sobre el plan nuevo (ver `04-agente.md`). La clase original depende de la base de datos | `src/Agent/PlanChecks.php` |
| `src/Video/AgentWorkflow/Application/ChosenElements.php` | Dos ideas: `knownFigures` (una cifra en la voz o en un texto que no está en lo que escribió la persona es un error) y la detección de palabras prohibidas | `src/Agent/PlanChecks.php` |
| `config/video/video_models.yaml` | Solo `balanced` (Kling O3 Standard, por defecto) y `high` (Kling O3 Pro). MiniMax 480p se deja fuera: es 480p y su salida de 480×832 obligaba a recortar | `config/models.yaml` |
| `config/video/sound.yaml` | `voice` y `music`. Sin `lipSync`. Se añade `avatar` (ver `05-proveedores.md`) | `config/sound.yaml` |

### Cómo se llama a PromptBuilder con el plan nuevo

`clipPrompt(array $shot, array $references, string $note = '', array $fonts = [])` lee
`$shot['spec']`. Con el plan del agente se construye así:

| Campo de `spec` | Sale de la pieza del plan |
|---|---|
| `action` | `scene.action` |
| `visibleState` | vacío |
| `framing` | `scene.framing` |
| `cameraMovement` | `scene.camera` |
| `lighting` | `scene.lighting` |
| `constraints` | vacío. Las restricciones nunca van al prompt como frases, porque el modelo las pintaba como texto (lecciones, punto 6) |

`$references` es la lista que espera hoy:

- La foto de la persona: `role: element`, `citation: '@Element1'`, `name: 'the person'`.
- La imagen de arranque, si la hay: `role: first_frame`, `type: start_frame`.
- El fotograma de la referencia que imita la pieza (`scene.imitateFrame`):
  `role: reference`, `imitate: ['movement', 'framing']`, `name: 'reference'`. Solo si el modelo
  tiene sitio: Kling O3 admite 4 imágenes como máximo.

`picturePrompt` recibe:

- `use: start` o `background`.
- `description`.
- `baseAssetId`: la ruta de la foto, si se edita.
- `baseKind: person` cuando se edita la foto de la persona.
- Los colores y el nombre de la marca.

## No se copia, y por qué

| Qué | Dónde está | Por qué no |
|---|---|---|
| Equipo de agentes, planificador y tareas | `src/Video/AgentWorkflow/` salvo lo citado arriba | Un solo agente entrega el plan entero. Sin tareas no hay dependencias, ni ciclos, ni actualizaciones en cadena. Los fallos 24 y 32 de `docs/videos-pendientes.md` venían de ahí |
| Revisiones, desfasados y correcciones | `src/Video/Collaboration/` | Un plan es una versión entera. No hay documentos que dependan de otros |
| Sala del equipo y coordinador | `src/Video/TeamConversation/`, `resources/video/prompts/coordinator.md` y `planner.md` | La persona habla con un solo agente desde la pantalla de la propuesta |
| Producciones, sesiones, aprobaciones y entrega | `src/Video/Production/` | Un vídeo es una carpeta con su `job.json` |
| Universos, proyectos, fichas, escenas y biblioteca | `src/Video/{Universe,Project,Library,Scene}/` | La foto y el logo se suben directamente |
| Orquestación de la generación | `GenerationService` (965 líneas), `VideoScheduler`, `JobProcessor`, `VideoRecovery`, `GenerationStatus`, `PlanBuilder`, `EditPlan` | El estado estaba repartido entre varias clases y mensajes. Si un mensaje se perdía, la generación quedaba colgada hasta que pasaba un cron. Aquí lo sustituye un worker lineal con el estado en un fichero |
| Control de gasto | `SpendGuard`, `VideoPricing`, `SoundLedger`, `SoundPricing`, `Money` | Basta con la estimación antes de generar y el coste real apuntado en `job.json`. Todo en dólares, sin conversión a euros |
| Mensajes, comandos y controladores | `Message/`, `Command/`, `Http/` de cada módulo | Dependen de Messenger y de la base de datos |
| Proveedores falsos y multiagencia | `Fake*`, `VideoGateways`, `SoundGateways`, `ConfiguredModelGateway` | Las pruebas usan respuestas grabadas (ver `07-plan-de-trabajo.md`). No hay agencias |
| Lip-sync | `FalLipSyncGateway`, `Montage::lipSync` | Lo sustituye Kling AI Avatar, que hace a la persona a partir del audio |
| ComfyUI local | `LocalVideoGateway`, `config/video/local_workflows/` | Se puede añadir más adelante si hay GPU propia |
| Plantillas de vídeo | `config/video/video_templates.yaml`, `VideoTemplates` | La estructura sale del vídeo de referencia. Las pautas de gancho, mensaje y llamada a la acción ya están en el prompt del agente |
| Frontend en Vue | `frontend/video/` | Tres pantallas en Twig con un poco de JavaScript bastan |

## Lo que se escribe nuevo

| Clase | Qué hace |
|---|---|
| `Job`, `JobStore` | Leer y escribir `job.json` de forma atómica: escribir a un fichero temporal y renombrarlo. Crear la carpeta del vídeo |
| `WorkerCommand` | El bucle del worker con `flock` por vídeo |
| `Steps\*` | Un paso por clase, cada uno idempotente: `CheckInputs`, `Frames`, `Transcribe`, `Plan`, `Revise`, `Voice`, `Images`, `Clips`, `Music`, `Assemble` |
| `Reference\Frames` | Detección de cortes y extracción de fotogramas |
| `Agent\PlanAgent` | Arma la entrada del agente, llama a Claude y aplica las comprobaciones |
| `Agent\PlanChecks` | Las comprobaciones deterministas del plan |
| `Providers\FalAvatarGateway` | Kling AI Avatar v2: imagen y audio a vídeo |
| `Estimate` | El coste estimado de un plan, con los catálogos |
| `LogoColors` | Los dos colores principales del logo |
| Controladores y plantillas | Las tres pantallas (ver `06-pantallas.md`) |
