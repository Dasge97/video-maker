# 9. Cuentas, claves y gasto

Añadido el 27-10-2026 a petición de la persona: la herramienta se va a desplegar y cada quien que
la use paga sus vídeos con sus propias claves. El servidor no tiene claves de proveedores: si una
cuenta no ha puesto las suyas, no se genera nada.

## Dónde se guarda

Sigue sin haber base de datos. Cada cuenta es una carpeta:

```
var/users/<id>/
  account.json        ← correo, nombre, hash de la contraseña, claves cifradas, límite, marcas
  brands/<id>.png     ← el logo de cada marca guardada
var/library/<id>/     ← las imágenes de «Crear imagen» de esa cuenta
var/jobs/<id>/job.json  ← cada vídeo lleva "owner": el id de su cuenta
```

## Claves

- Se escriben en **Ajustes → Claves**. Al guardar, cada clave nueva se prueba con una consulta
  gratuita (`KeyCheck`): una lista de modelos o el estado de un clip que no existe. Solo un 401
  o un 403 la rechaza.
- Se guardan cifradas con libsodium (`KeyCipher`) y el secreto `APP_KEYS_SECRET` de `.env.local`.
  Nunca se vuelven a mostrar enteras: la pantalla enseña los cuatro últimos caracteres.
- La sesión no guarda las claves: `Account::__serialize` solo lleva id, correo y hash.
- `CurrentAccount` dice de quién son las claves en cada momento: en la web, la cuenta que ha
  entrado; en el proceso de fondo, el dueño del vídeo (`runAs`). Los proveedores le piden la
  clave al hacer cada llamada.
- Hacen falta Anthropic, ElevenLabs, fal.ai y OpenAI. AI Hub es opcional: sin él, la referencia
  se transcribe con ElevenLabs (`scribe_v1`, 0,40 $ la hora; unos 0,01 $ por referencia).

## Gasto y límite

- El gasto del mes es la suma de los costes apuntados en los vídeos de la cuenta (`costs[].at`
  de este mes) y de las imágenes creadas este mes (`Spending`).
- El límite es opcional, en dólares al mes. Se mira:
  - en la web, antes de crear un vídeo, pedir un cambio, generar o crear una imagen, sumando lo
    que costaría;
  - en el proceso de fondo, una vez al empezar cada paso de pago. Nunca a mitad de un paso: un
    clip ya enviado a fal.ai siempre se recoge.
- Al pasarse, el vídeo queda en «Con error» con el motivo. Tras subir el límite, «Reintentar»
  sigue donde se paró.

## Marcas guardadas

Nombre, dos colores, tipografía, palabras prohibidas y logo. Se guardan desde el paso 2 del
asistente con «Guardar la marca para otros vídeos»: si ya hay una con el mismo nombre, se
actualiza. Al elegirla, rellena esos campos y pone su logo.

## Entrar y registrarse

- `/entrar` con correo y contraseña (formulario de Symfony con CSRF, sesión de 30 días y 5
  intentos por minuto).
- `/registro` solo existe con `REGISTRO_ABIERTO=1`. Si no, las cuentas se crean con
  `php bin/console app:cuenta:crear <correo>`.
- Cada cuenta solo ve y cambia sus vídeos, sus imágenes y sus marcas: un vídeo de otra cuenta
  responde 404.
