# 7. Plan de trabajo

Cada fase termina con algo que se puede probar. No se pasa a la siguiente hasta que la anterior
funciona. Las fases que gastan dinero piden permiso a la persona antes de la primera llamada de pago.

## Fase 1. Esqueleto y montaje sin proveedores (gratis)

- Proyecto Symfony 7.4 con las dependencias de `02-arquitectura.md`. Git inicializado, con
  `var/` y `.env.local` en `.gitignore`.
- Se copian las piezas de montaje: `AssBuilder`, `BrandStyle`, `VideoFonts`, `Renderer`,
  `FfmpegRenderer`, `Ffmpeg`, `VoiceTimeline`, `Speech` y las fuentes.
- Se escribe `AssembleStep` a partir de `Montage::render`.
- **Prueba:** un comando `app:demo-montage` que monta un vídeo de 12 s con:
  - dos clips de color hechos con ffmpeg (`testsrc2`);
  - una `text_card`;
  - un `logo_card` con un PNG de prueba;
  - un rótulo y subtítulos a partir de palabras con tiempos inventados;
  - un tono como voz y otro como música.

  Se mira el resultado a ojo: zona segura, contraste, fundidos y que no haya bandas negras.
- Pruebas unitarias de `VoiceTimeline` (cortes, silencio inicial, piezas sin voz) y de
  `Montage::logoWindows`.

## Fase 2. Referencia y agente

- `Job`, `JobStore`, `WorkerCommand` y los pasos de análisis: `CheckInputs`, `Frames`,
  `Transcribe` y `Plan`.
- `PlanChecks` con una prueba unitaria por regla de `04-agente.md`. Se toman como ejemplo los casos
  de `tests/Video/Unit/AgentWorkflow/MontageChecksTest.php` de new-studio.
- `AnthropicClient` y `PlanAgent`.
- Pantallas 1 y 2 sin el botón «Generar». La referencia por enlace con yt-dlp entra aquí.
- `OpenAiImages` y la pantalla «Crear imagen» (ver `06-pantallas.md`). Se adelanta a esta fase
  para que la prueba del agente pueda usar una foto y un logo creados ahí. Cada imagen es de
  pago: se pide permiso antes de la primera.
- **Prueba (de pago: solo el agente):** con un anuncio real de Instagram, una foto y un logo,
  en los dos modos. Se revisa:
  - si el plan imita la referencia;
  - si respeta el ritmo de voz;
  - si inventa algo;
  - cuántas vueltas de corrección necesita.

  Se guardan las entradas y los planos obtenidos en `tests/fixtures/` para las pruebas siguientes,
  sin volver a pagar.
- Se ajusta `prompts/agente.md` con lo que se vea.

## Fase 3. Proveedores, pieza a pieza (de pago, poco)

Antes de juntar nada, un comando `app:probe <proveedor>` hace una sola llamada de cada tipo y
guarda el resultado en `var/probe/`:

1. Voz de ElevenLabs con una frase de 10 palabras: comprobar los tiempos por carácter.
2. Imagen de arranque editando la foto: comprobar que la cara es la de la persona.
3. Kling AI Avatar con esa imagen y la frase. Comprobar:
   - si acepta el audio como data URI;
   - si la boca cuadra con la voz en castellano;
   - la duración devuelta.
4. Kling O3 Standard con la persona como `element` y una imagen de arranque a la vez. Comprobar si
   fal.ai admite los dos juntos. Si no, aplicar la alternativa de `05-proveedores.md`.
5. Música de 19 s.
6. Lista de voces de ElevenLabs filtrada por castellano. La persona elige la voz por defecto.

Se apuntan los resultados y los precios reales en `config/*.yaml`, y en `05-proveedores.md` se
quitan las marcas «sin confirmar» de lo comprobado.

## Fase 4. Generación completa

- Pasos `Voice`, `Images`, `Clips` y `Music`, con las reglas del paso de clips de
  `02-arquitectura.md`: no pagar dos veces, reintentar solo la descarga y cancelar lo abandonado.
- `Estimate` y el botón «Generar».
- Pantalla 3.
- **Prueba:** un anuncio de 15 s en cada modo con el plan guardado en la fase 2. Se apunta en
  `docs/pruebas.md`:
  - coste estimado frente al real;
  - tiempo total;
  - qué se ve mal.
- **Prueba de recuperación:**
  - cerrar el worker en mitad de los clips y volver a abrirlo. Debe seguir sin reenviar nada;
  - cambiar un rótulo y regenerar. Solo se repite el montaje, a 0 $.

## Fase 5. Pulido

- Pedir cambios al agente (`revising`) y edición de textos con comprobación en el momento.
- Pantalla «Mis vídeos».
- README en inglés y castellano según las reglas de `C:\Users\tekil\.claude\CLAUDE.md`, con
  capturas reales en `docs/img/`.
- Proponer a la persona un aviso al móvil por el Kit (`POST http://localhost:8790/api/avisar`)
  cuando un vídeo termina o falla.

## Pruebas automáticas

- PHPUnit para todo lo puro: `PlanChecks`, `VoiceTimeline`, `AssBuilder`, `BrandStyle`,
  `PromptBuilder`, `FalVideoGateway::body`, `Estimate` y `LogoColors`. De new-studio se puede
  copiar y adaptar `tests/Video/Unit/VideoGeneration/FalVideoGatewayTest.php`.
- Los proveedores se prueban con `MockHttpClient` de Symfony y respuestas grabadas en
  `tests/fixtures/`. No se copian las clases `Fake*` de new-studio.
- Una prueba de extremo a extremo con todos los proveedores simulados. Va de `analysing` a
  `ready` y comprueba que el MP4 existe y dura lo esperado (±0,5 s).
