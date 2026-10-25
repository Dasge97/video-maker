# 1. Objetivo y decisiones

## Qué hace

La persona sube tres cosas y escribe una frase:

- **Vídeo de referencia.** Un anuncio de Instagram que le gusta, de hasta 90 s. Se imita su
  estructura, su ritmo y sus tipos de plano. No se copian sus textos ni sus personas. Es
  obligatorio. Se sube el fichero o se pega un enlace público (Instagram, TikTok, YouTube), que
  se descarga con yt-dlp.
- **Foto de la persona** que sale en el anuncio. Se sube o se elige entre las imágenes creadas
  en la sección «Crear imagen».
- **Logo** de la marca. Es opcional. Se sube o se elige igual que la foto.
- **Qué se anuncia.** Producto o servicio, mensaje principal y llamada a la acción. Si tiene
  precio u oferta, se escribe aquí. El agente no inventa cifras.

El programa devuelve un MP4 vertical de 720×1280 con voz, subtítulos palabra a palabra, rótulos,
logo y música.

## Los dos modos

| Modo | Quién habla | Qué se ve |
|---|---|---|
| **Persona hablando** (`talking_person`) | La persona de la foto, con una voz de ElevenLabs elegida para ella | Planos de la persona hablando a cámara. Entre ellos, planos de apoyo, tarjetas de texto y el logo |
| **Voz en off** (`voice_over`) | Un narrador de ElevenLabs | Planos generados, que pueden incluir a la persona sin hablar, tarjetas de texto y el logo |

La persona elige el modo en el primer paso.

## Decisiones tomadas

1. **PHP con Symfony 7.4.** Así se copian casi tal cual las piezas de new-studio que funcionan.
2. **Primero la voz, después el vídeo.** Se genera la voz y se mide cuánto dura cada trozo. Con esa
   duración se pide cada clip. Los motivos:
   - Un clip se puede recortar o alargar sin que se note. Una voz acelerada o frenada sí se nota.
   - Los modelos de vídeo solo hacen duraciones de segundos enteros. Se pide el entero inmediatamente
     superior a lo que dura la voz y el montaje recorta el sobrante.
   - Para que la persona hable a cámara, el modelo Kling AI Avatar necesita el audio como entrada.
3. **La persona habla con un modelo que genera el vídeo a partir del audio** (Kling AI Avatar v2 en
   fal.ai). No se usa lip-sync sobre un clip ya hecho. En new-studio ese método dejaba la cara
   descuadrada con la voz y congelada al final del clip.
4. **Revisión antes de pagar.** El agente propone un plan y la persona lo ve con su coste estimado.
   Puede editar los textos a mano o pedir un cambio al agente en una frase. Los clips se pagan
   solo cuando pulsa «Generar».
5. **Un solo agente.** Una llamada a Claude recibe los fotogramas de la referencia, su
   transcripción, la foto, el logo y lo que se anuncia, y devuelve el plan entero. No hay
   guionista, cámara, editor ni revisor por separado.
6. **Sin base de datos.** Cada vídeo es una carpeta en `var/jobs/<id>/` con su `job.json`.
7. **Un solo formato: vertical 9:16.** Duración de 10 a 30 s, 15 s por defecto.
8. **Sección «Crear imagen».** Decidido por la persona el 25-10-2026. Una pantalla sencilla:
   se escribe un prompt, se genera una imagen con OpenAI Images y queda guardada para usarla como
   foto de la persona o como logo. No es obligatoria: siempre se puede subir la imagen.
9. **Voz por defecto en castellano.** Se elige una voz nativa en castellano de ElevenLabs con la
   persona en la fase 3. «George» deja de ser la voz por defecto.

## Fuera del alcance de la primera versión

- Otros formatos (4:5, 1:1, 16:9).
- Varias personas en el mismo vídeo.
- Imágenes de producto subidas por la persona. Si se añaden más adelante, pasan por la pieza
  `product` de new-studio.
- Usuarios, clientes, fichas de recursos y versiones.
- Límites de gasto diarios o mensuales. Basta con enseñar el coste estimado antes de generar y
  el real al terminar.
- Webhooks de fal.ai. Se consulta el estado cada pocos segundos, que en local es suficiente.
