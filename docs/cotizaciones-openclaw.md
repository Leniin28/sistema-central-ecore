# API interna: cotizaciones desde OpenClaw

Complementa `docs/cotizaciones.md` (módulo web + creación por API). Aquí se
documenta el flujo completo para OpenClaw: crear, dar seguimiento y **convertir
en orden** una cotización. Todo bajo `internal.api` (Bearer Token) +
`throttle:30,1`.

| Ruta | Uso |
|---|---|
| `POST /api/internal/quotes` | Crear cotización (cliente nuevo o existente) |
| `GET  /api/internal/quotes/pending` | Cotizaciones pendientes de respuesta |
| `GET  /api/internal/quotes/{cotizacion}/pdf` | Descargar PDF (con el mismo token) |
| `GET  /api/internal/quotes/{cotizacion}/png` | Descargar PNG (con el mismo token) |
| `POST /api/internal/quotes/{cotizacion}/convert-to-order` | Convertir en orden de servicio |

## Crear — `POST /quotes` (contrato vigente, sin cambios)

Acepta cliente existente (`cliente_id`) **o** alta rápida (`cliente.nombre` +
`telefono`/`direccion` opcionales), equipo opcional, `items[]` (tipo
`servicio|refaccion|producto|otro`, descripción, cantidad, precio_unitario),
`descuento`, `anticipo`, `notas`, `vigencia`, `external_id` (idempotencia) y
`tipo_recepcion`/`direccion_recepcion`. OpenClaw convierte el lenguaje natural a
este payload; ECore solo valida y calcula (subtotal/total/saldo).

La respuesta incluye `internal_pdf_url` / `internal_png_url` (funcionan con el
Bearer Token, sin sesión web). Ver ejemplos UTF-8 en `docs/cotizaciones.md`.

## Pendientes — `GET /quotes/pending`

Params: `older_than_days=` (mínimo de días desde la fecha de la cotización),
`cliente=` (nombre o teléfono), `limit=` (default 20, máx 50).

```json
{
  "items": [
    {
      "id": 1, "folio": "COT-20260703-0001", "estado": "enviada",
      "cliente": "Román Barrera", "telefono": "4491112233",
      "total": 1350.0, "saldo": 1350.0, "dias_pendiente": 3, "vigencia": null,
      "show_url": "http://.../admin/cotizaciones/1",
      "pdf_url": "http://.../api/internal/quotes/1/pdf",
      "png_url": "http://.../api/internal/quotes/1/png"
    }
  ],
  "warnings": []
}
```

- "Pendiente" = estado `borrador` o `enviada`. Se ordenan de más antigua a más
  reciente; si exceden `limit` se truncan con warning.
- Sirve para "cotizaciones pendientes", "cuáles no han contestado" y preparar
  seguimiento (combínalo con `message-template` de la orden cuando ya exista).

## Convertir en orden — `POST /quotes/{cotizacion}/convert-to-order`

**Payload mínimo obligatorio** (un POST vacío o incompleto responde `422` y no
tiene NINGÚN efecto: ni orden, ni cambio de estado, ni notas):

```json
{
  "external_id": "telegram-quote-convert-123",
  "recepcion": {
    "falla_reportada": "Servicio autorizado desde cotización"
  },
  "equipo": { "tipo_equipo": "Laptop" }
}
```

Payload completo recomendado:

```json
{
  "external_id": "telegram-quote-convert-123",
  "partner_logistico": "Electrocom Alameda",
  "recepcion": {
    "falla_reportada": "Servicio autorizado desde cotización",
    "notas": "Convertido desde cotización por OpenClaw"
  },
  "equipo": { "tipo_equipo": "Laptop", "marca": "Lenovo", "modelo": "ThinkPad T490", "password_equipo": null }
}
```

Validación dura (toda ANTES de la transacción, sin efectos secundarios):

- **`external_id` es obligatorio** (`422` si falta): sin él no hay idempotencia
  y un reintento duplicaría la orden.
- **`recepcion` es obligatoria** con `falla_reportada` **o** `notas` (`422` si
  faltan ambas).
- **Equipo mínimo**: si la cotización no tiene equipo, el payload debe traer
  `equipo.tipo_equipo` o `equipo.modelo` (`422` si no). Si la cotización ya
  tiene equipo, el bloque `equipo` es opcional y se ignora a favor del suyo.
- **Estados convertibles**: solo `borrador`, `enviada` o `aceptada`. Cualquier
  otro estado —`rechazada`, `vencida` o un valor fuera de catálogo como
  `cancelada`— responde **`409`** con mensaje claro ("La cotización COT-... está
  cancelada y no puede convertirse en orden.") y **cero efectos**.
- **Ya convertida**: si ya existe una orden creada desde esa cotización
  (`origen: openclaw-cotizacion` + folio en notas), se devuelve esa orden con
  `created: false` y un warning, aunque el `external_id` sea distinto. Nunca se
  duplica.

Reglas de la conversión válida:

- Crea la orden con el flujo real (`CrearOrdenServicio` + usuario de sistema
  OpenClaw): folio nuevo, estado `recibido`, historial, transacción.
- **Items → líneas** con las mismas reglas seguras de `/changes`:
  - `servicio` → match contra el catálogo activo (exacto → parcial). Sin match o
    ambiguo → **warning + nota**, sin línea facturable falsa.
  - `refaccion` / `producto` / `otro` → refacción de texto libre con el precio
    cotizado (`costo_unitario` 0: se captura en el panel).
- Equipo: usa el de la cotización; si no tiene, el del payload; si tampoco →
  warning y la orden queda sin equipo.
- `partner_logistico` con match tolerante (o `partner_recepcion_id` explícito);
  sin match → warning, sin fallar.
- La cotización pasa a **`aceptada`** (si estaba en `borrador`/`enviada`; si está
  `rechazada`/`vencida` no se cambia y se avisa con warning).
- **Relación**: el esquema no tiene FK orden↔cotización, así que el vínculo queda
  documentado en las **notas de ambas** (folios cruzados) + `origen:
  "openclaw-cotizacion"` en la orden.
- **Idempotencia** por `external_id` (el de la orden): reenviar devuelve la misma
  orden con `created: false` (`200`); la primera vez es `201`.
- No genera finanzas (eso solo pasa al entregar) y no expone contraseñas
  (`password_registrada` únicamente).

Respuesta: `created`, `id`, `folio`, `estado`, `cotizacion{id,folio,estado}`,
`cliente`, `equipo`, `partner_recepcion`, `total_cliente`, `warnings[]`,
`show_url`.

### Ejemplo (PowerShell 5.1, bytes UTF-8)

```powershell
$token = "TU_TOKEN"
$body = @'
{ "external_id": "telegram-quote-convert-123", "partner_logistico": "Electrocom Alameda",
  "recepcion": { "falla_reportada": "Servicio autorizado desde cotización" },
  "equipo": { "tipo_equipo": "Laptop" } }
'@
Invoke-RestMethod -Method Post `
  -Uri "http://sistema-central-ecore.test/api/internal/quotes/1/convert-to-order" `
  -Headers @{ Authorization = "Bearer $token"; Accept = "application/json" } `
  -ContentType "application/json; charset=utf-8" `
  -Body ([System.Text.Encoding]::UTF8.GetBytes($body))
```

```bash
curl -H "Authorization: Bearer $TOKEN" "http://sistema-central-ecore.test/api/internal/quotes/pending?older_than_days=2"
```
