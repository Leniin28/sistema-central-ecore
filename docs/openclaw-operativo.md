# ECore como backend operativo de OpenClaw

Mapa general de la API interna que OpenClaw (asistente por Telegram, texto y
audio) usa para operar el negocio. ECore es la **fuente de verdad y la lógica de
negocio segura**; OpenClaw es la **interfaz conversacional** (visión, lenguaje
natural, decidir cuándo consultar/enviar).

## Reparto de responsabilidades

| Hace OpenClaw | Hace ECore |
|---|---|
| Leer fotos de etiquetas (visión) y armar el payload | Validar, crear la orden real (folio, historial, transacción) |
| Convertir lenguaje natural a estados/payloads canónicos | Rechazar estados inválidos, exigir confirmación para entregar |
| Decidir cuándo pedir resúmenes/seguimientos (timers) | Calcular datos reales y marcar qué es estimado |
| Redacción fina de mensajes al cliente y envío | Plantillas deterministas con datos reales, sin enviar nada |
| Generar `external_id` únicos (p. ej. id de mensaje de Telegram) | Idempotencia: mismo `external_id` = misma operación, sin duplicar |

## Seguridad (todas las rutas)

- Prefijo `/api/internal`, middleware `internal.api`: Bearer Token
  (`OPENCLAW_INTERNAL_API_TOKEN` en `.env`) + `throttle:30,1`.
- Sin token válido → `401`; token no configurado en el servidor → `403`.
- **`password_equipo` jamás se devuelve** en ninguna respuesta; solo
  `password_registrada` (booleano). Tampoco se escribe en logs ni notas.
- Operaciones de escritura en transacción DB; las que mutan órdenes usan lock.
- Ambigüedad (partner, servicio de catálogo) → **warning, nunca inventar**.
- Actor de escrituras: usuario de sistema OpenClaw
  (`OPENCLAW_SYSTEM_USER_EMAIL`, rol admin), auditado en historial/notas.
- Desde PowerShell 5.1 envía el cuerpo como **bytes UTF-8** (ver
  `docs/cotizaciones.md`); si no, los acentos rompen el JSON con `422` engañosos.

## Endpoints por flujo

**Órdenes de servicio** (detalle en `docs/ordenes-openclaw.md`):

| Ruta | Uso |
|---|---|
| `POST /receptions` | Crear orden desde foto de etiqueta (`docs/recepciones-openclaw.md`) |
| `GET  /service-orders/{orden}` | Consultar orden por id o folio |
| `POST /service-orders/{orden}/profile` | Corregir cliente/equipo/recepción/partner |
| `POST /service-orders/{orden}/changes` | Agregar servicios/refacciones/notas |
| `POST /service-orders/{orden}/status` | Cambiar estado (`entregado` exige confirmación) |
| `POST /service-orders/{orden}/message-template` | Texto de mensaje para el cliente |

**Cotizaciones** (detalle en `docs/cotizaciones-openclaw.md`):

| Ruta | Uso |
|---|---|
| `POST /quotes` | Crear cotización (payload flexible) |
| `GET  /quotes/pending` | Pendientes de respuesta |
| `GET  /quotes/{cotizacion}/pdf` · `/png` | Documentos con el mismo token |
| `POST /quotes/{cotizacion}/convert-to-order` | Cotización aceptada → orden |

**Catálogo de servicios** (ver sección al final de este documento):

| Ruta | Uso |
|---|---|
| `GET /services` | Servicios del catálogo (activos por default) |
| `POST /services/match` | Match asesor de texto libre contra el catálogo |

**Consulta, reportes y finanzas** (detalle en `docs/reportes-openclaw.md`):

| Ruta | Uso |
|---|---|
| `GET /search` | Búsqueda unificada clientes/órdenes/cotizaciones |
| `GET /reports/daily` · `/weekly` | Resumen operativo (`/resumen_dia`) |
| `GET /reports/cash-cut` | Corte diario/semanal (real + estimado marcado) |
| `GET /follow-ups` | Pendientes/atrasos accionables |
| `POST /expenses` · `GET /expenses` | Gastos operativos (afectan el corte) |

## Idempotencia (resumen)

| Operación | Mecanismo |
|---|---|
| Recepciones y conversión de cotización | `external_id` en la **orden** (reenvío devuelve la misma orden) |
| `/changes`, `/status`, `/profile` | Ledger `openclaw_order_changes` por operación |
| Cotizaciones (`POST /quotes`) | `external_id` en la cotización |
| Gastos (`POST /expenses`) | `external_id` único en `movimientos_financieros` |

Regla para OpenClaw: **siempre** mandar `external_id` derivado del mensaje de
Telegram (`telegram-<tipo>-<message_id>`), y reusar el mismo en reintentos.

## Finanzas: qué es real y qué es estimado

- Los movimientos financieros **solo** se generan al marcar `entregado` (flujo
  existente `GenerarFinanzasOrdenServicio`) o manualmente (panel / `POST
  /expenses`). Ningún otro endpoint toca finanzas.
- Reportes y corte suman movimientos reales; el "pendiente por cobrar" es un
  **estimado** de órdenes listas sin entregar y siempre viene etiquetado así.
- Cotizaciones "aprobadas hoy" es aproximado (no hay timestamp de cambio de
  estado); el campo lleva su nota.

## Catálogo dinámico de servicios para OpenClaw

Los servicios se crean, editan y desactivan desde el panel de ECore. **OpenClaw
no debe hardcodear el catálogo**: debe consultarlo antes de decidir qué servicio
agregar a una orden o cotización (cuando el usuario diga "optimización",
"bisagras", "reemplazo de disco", etc.). Ninguno de estos endpoints crea ni
modifica servicios.

### `GET /api/internal/services`

Query params: `q=` (búsqueda tolerante a acentos/mayúsculas por nombre,
descripción y categoría), `active=true|false` (default `true`), `category=`,
`limit=` (default 50, máx 100).

```json
{
  "items": [
    {
      "id": 1,
      "nombre": "Servicio de Optimización",
      "descripcion": null,
      "categoria": "General",
      "precio_base": 550.0,
      "activo": true,
      "aliases": ["servicio de optimización", "servicio de optimizacion", "optimizacion", "general"]
    }
  ],
  "total": 1,
  "warnings": []
}
```

- `aliases` son variantes derivadas del nombre/categoría (no hay columna de
  aliases en BD): sirven para matching coloquial del lado de OpenClaw.
- `precio_base` es el precio actual del panel: si OpenClaw no manda precio en
  `/changes`, este es el que se usa.
- `q` sin coincidencias → lista vacía + warning (no elegir a ciegas).

### `POST /api/internal/services/match`

Match **asesor** de texto libre contra el catálogo activo, con la misma regla
estricta que usa `/changes` (exacto → parcial) más un puntaje por palabras para
frases largas.

```json
{ "text": "optimización completa limpieza pasta termica sistema optimizado", "limit": 5 }
```

```json
{
  "match": { "id": 2, "nombre": "Servicio de Optimización", "categoria": "General", "precio_base": 550.0, "confidence": "high" },
  "confidence": "high",
  "candidates": [ { "id": 2, "nombre": "Servicio de Optimización", "categoria": "General", "precio_base": 550.0, "score": 1.0 } ],
  "warnings": []
}
```

- `confidence: high` → OpenClaw puede enviar ese `servicio_id` directamente.
- `confidence: ambiguous` → `match: null` + candidatos: **preguntar al usuario**,
  nunca decidir automáticamente.
- `confidence: none` → `match: null` + warning: el servicio no existe o está
  desactivado; usar `servicios_sugeridos` o pedir aclaración.

### Flujo recomendado para OpenClaw

1. Consultar `GET /services` (o `POST /services/match` con la frase del
   usuario) **en el momento**, no de una lista memorizada: el catálogo cambia.
2. Con match claro (`high`), enviar **`servicio_id`** a `/changes`, recepciones
   o al armar items de cotización (mejor que mandar solo la descripción).
3. Sin match claro (`ambiguous`/`none`), mostrar candidatos y pedir aclaración
   al usuario; como último recurso enviar `servicios_sugeridos` (queda como
   nota, nunca como línea facturable).
4. Nunca hardcodear el catálogo ni inventar servicios: la API no los crea.

### Ejemplos

```powershell
$token = "TU_TOKEN"
Invoke-RestMethod -Uri "http://sistema-central-ecore.test/api/internal/services?q=optimizacion" `
  -Headers @{ Authorization = "Bearer $token"; Accept = "application/json" }

$body = @'
{ "text": "optimización completa con limpieza y pasta térmica" }
'@
Invoke-RestMethod -Method Post -Uri "http://sistema-central-ecore.test/api/internal/services/match" `
  -Headers @{ Authorization = "Bearer $token"; Accept = "application/json" } `
  -ContentType "application/json; charset=utf-8" `
  -Body ([System.Text.Encoding]::UTF8.GetBytes($body))
```

```bash
curl -H "Authorization: Bearer $TOKEN" "http://sistema-central-ecore.test/api/internal/services?q=optimizacion"
curl -X POST http://sistema-central-ecore.test/api/internal/services/match \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json; charset=utf-8" \
  --data '{"text":"optimización completa con limpieza y pasta térmica"}'
```
