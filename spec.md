# video-maker

Anuncios verticales de Reels de 10 a 30 segundos a partir de un vídeo de referencia (o sin él), la
foto de una persona, el logo y los productos y lugares de la marca. Un solo agente prepara la
propuesta y un proceso de fondo hace la voz, los clips, la música y el montaje. El detalle está en
`docs/`.

## Deployment

- mode: multi-container
- compose_file: docker-compose.production.yml
- public_service: web
- internal_port: 80
- healthcheck_path: /salud

### Services

- web
- app
- worker
