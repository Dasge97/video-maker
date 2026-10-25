# 4. El agente

Un solo agente sustituye al coordinador, al planificador, al analista de la referencia, al
guionista, a la cámara, al editor y al revisor de new-studio. Recibe todo de una vez y entrega el
plan entero.

- Prompt de sistema: [../prompts/agente.md](../prompts/agente.md). Es la fusión de
  `resources/video/prompts/common.md`, `reference-analyst.md` y `roles/{screenwriter,
  audiovisual_director,editor,continuity_reviewer}.md` de new-studio, sin lo que solo servía para
  coordinar un equipo.
- Esquema de la salida: [../prompts/plan.schema.json](../prompts/plan.schema.json).

## La llamada

Messages API de Anthropic. Antes de escribir el cliente, carga la skill `claude-api`.

- Modelo: variable `ANTHROPIC_MODEL`, por defecto `claude-opus-5-5`. `claude-sonnet-5-5` es más
  barato. Se puede probar cuando todo funcione.
- Una herramienta, `submit_plan`, con `plan.schema.json` como `input_schema` y
  `tool_choice: {type: "tool", name: "submit_plan"}`.
- El prompt de sistema lleva `cache_control` efímero, como hace `AnthropicModelGateway`.
- Como máximo 16.000 tokens de salida.
- Como máximo 3 vueltas de corrección. Si a la tercera sigue habiendo errores, el vídeo pasa a
  `failed` con la lista de errores, en castellano.

### Mensaje de la persona (contenido del primer turno)

Un mensaje con bloques en este orden:

1. Por cada fotograma, un bloque de texto `Fotograma N · segundo S · plano de A a B s` y después
   la imagen en JPEG base64, de 768 px de ancho.
2. Texto `Foto de la persona` y la imagen, reducida a 1024 px.
3. Si hay logo: texto `Logo de la marca` y la imagen, reducida a 512 px y aplanada sobre blanco.
4. Un bloque de texto con los datos en JSON:

```json
{
  "mode": "talking_person",
  "durationSeconds": 15,
  "product": "Lo que escribió la persona sobre qué se anuncia",
  "note": "Nota sobre la referencia, o vacío",
  "brand": { "name": "OVE", "colors": "#FF6A00, #1D1D1B", "forbiddenWords": ["barato"] },
  "hasLogo": true,
  "reference": {
    "durationSeconds": 22.4,
    "transcript": [ { "start": 0.0, "end": 2.1, "text": "¿Sabías que…" } ]
  }
}
```

### Petición de cambio

Cuando la persona pide un cambio desde la propuesta, se abre una conversación nueva. Lleva los
mismos bloques que la primera. Al final se añade otro bloque de texto con el plan actual en JSON y
la frase: `Petición de cambio de la persona: «…». Cambia solo lo que pide y entrega el plan entero.`

## Comprobaciones (`PlanChecks`)

Funciones puras sobre el plan y los datos. Devuelven una lista de errores en castellano, cada uno
diciendo la pieza y qué corregir. Las cifras vienen de las constantes de `MontageChecks.php` de
new-studio y se copian como constantes.

| Regla | Detalle |
|---|---|
| Ritmo de la voz | Palabras de `voice` ≤ 2,5 × `plannedSeconds` de cada pieza. Una cifra cuenta como una palabra |
| Duración total | La suma de `plannedSeconds` está dentro del 10 % de la pedida, con un margen mínimo de 1 s. Además, la duración estimada de cada pieza con voz, `max(plannedSeconds, palabras / 2)`, sumada, tampoco se sale de ese margen |
| Rótulos legibles | `text` ≤ 40 caracteres. El tiempo que cubren sus piezas ≥ `max(1,5, palabras / 2)` |
| Rótulos bien colocados | `fromPiece` ≤ `toPiece` ≤ número de piezas. Dos rótulos no comparten posición en la misma pieza. El overlay `logo` no cubre un `logo_card`. Ningún rótulo `top` o `center` cubre una pieza `talking_person` |
| Un texto, una vez | El texto de un rótulo no repite el de una `text_card` o un `logo_card` de la misma pieza |
| Campos por tipo | `scene` solo y siempre en `talking_person`, `generated_clip` e `image_motion`. `motion` solo y siempre en `image_motion`. `card` en `text_card`. `logo` en `logo_card`. `startImage` obligatorio en `talking_person` e `image_motion` |
| Modo | `voice_over`: ninguna pieza `talking_person`. `talking_person`: al menos una, y la suma de sus `plannedSeconds` ≥ la mitad de la duración. Cada `talking_person` lleva voz y dura de 2 a 10 s |
| Logo | Sin logo en los datos, ni `logo_card` ni overlay `logo` |
| Cifras inventadas | Toda cifra de `voice`, `overlays[].text`, `card.text`, `logo.text` y `startImage` aparece en `product`, `note` o el nombre de la marca. Se sigue la idea de `ChosenElements::knownFigures` |
| Palabras prohibidas | Ninguna palabra prohibida en voz, rótulos, tarjetas ni `startImage`. Se busca también en otro género y número: se quitan las terminaciones `-o`, `-a`, `-os`, `-as`, `-es` y `-s` de las dos palabras y se compara lo que queda |
| Nada escrito para el modelo de vídeo | `scene.action`, `framing`, `camera` y `lighting` no llevan texto entre comillas («», "", ''). Si lo llevan, es error. `PromptBuilder::withoutWriting` lo limpia igualmente al construir el prompt |
| Fotogramas | `imitateFrame` y `beats[].frame` existen entre los fotogramas enviados |
| Coste | Como máximo 5 piezas que generan clip (`talking_person` + `generated_clip`). Es un límite de seguridad y se puede cambiar en la configuración |

Estas comprobaciones se pasan también cuando la persona edita textos a mano. Si alguna falla, la
pantalla enseña el error y no guarda la versión.

## Cuánto cuesta el agente

Una llamada con 16 fotogramas, la foto y el logo lleva unas 25.000 a 30.000 fichas de entrada y
unas 3.000 de salida. Cada cambio pedido al agente cuesta lo mismo. Los precios por ficha del
modelo se consultan en la skill `claude-api` al implementar, no se escriben de memoria. El coste
real se apunta en `costs` con el `usage` que devuelve la API.
