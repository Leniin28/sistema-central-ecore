# API interna: búsqueda, reportes, seguimientos, mensajes y gastos (OpenClaw)

Endpoints de **consulta y operación diaria** para OpenClaw. Todos van bajo
`/api/internal`, con middleware `internal.api` (Bearer Token) + `throttle:30,1`,
y **nunca** exponen `password_equipo`. ECore devuelve datos y warnings; OpenClaw
decide cuándo consultar y cómo redactar (no hay timers en ECore).

| Ruta | Uso |
|---|---|
| `GET /api/internal/search` | Búsqueda unificada de clientes/órdenes/cotizaciones |
| `GET /api/internal/reports/daily` | Resumen del día (`/resumen_dia`) |
| `GET /api/internal/reports/weekly` | Resumen semanal |
| `GET /api/internal/reports/cash-cut` | Corte de caja diario/semanal |
| `GET /api/internal/follow-ups` | Pendientes y atrasos accionables |
| `POST /api/internal/service-orders/{orden}/message-template` | Texto de mensaje para el cliente |
| `POST /api/internal/expenses` | Registrar gasto operativo |
| `GET /api/internal/expenses` | Consultar gastos operativos |

> Para consultar el **catálogo de servicios** (y hacer match de texto libre),
> ver `docs/openclaw-operativo.md` §"Catálogo dinámico de servicios":
> `GET /api/internal/services` y `POST /api/internal/services/match`.

## Búsqueda — `GET /search`

Query params: `q` (nombre, teléfono, folio, equipo, sucursal), `type`
(`all|clients|orders|quotes`, default `all`), `estado`, `partner`, `date_from`,
`date_to` (sobre fecha de recepción / fecha de cotización), `limit` (default 10,
máx 50).

```
GET /api/internal/search?q=Barrera&type=all
GET /api/internal/search?q=OS-20260705-0004&type=orders
GET /api/internal/search?estado=listo_para_entregar&type=orders
GET /api/internal/search?partner=Electrocom%20Alameda&type=orders
```

Respuesta: `{ "clientes": [...], "ordenes": [...], "cotizaciones": [...], "warnings": [] }`.

- Órdenes incluyen `estado`, `estado_label`, `cliente`, `telefono`, `equipo`,
  `sucursal`, `total_cliente`, fechas y `show_url`. Cotizaciones incluyen
  `estado`, `total`, `saldo`, `cliente`, fechas y `show_url`. Clientes incluyen
  `ordenes_activas`.
- Más resultados que `limit` → se truncan y se devuelve un `warning`.
- Estado inexistente para órdenes → `warning` con la lista de estados válidos.
- Sin ningún criterio → `warning` (no es error).
- Nota: el `LIKE` de MySQL en producción es insensible a acentos; en los tests
  (SQLite) no. Para búsquedas robustas OpenClaw puede enviar el texto sin acentos.

## Resumen diario — `GET /reports/daily`

Params: `date=YYYY-MM-DD` (default hoy), `partner=` (nombre, match tolerante;
filtra órdenes/movimientos por sucursal de recepción), `include_details=true`.

```json
{
  "date": "2026-07-05",
  "ordenes": {
    "creadas": 4, "entregadas": 1, "listas_para_entregar": 2, "en_proceso": 3,
    "sin_tecnico": 2, "por_estado": { "recibido": 2, "en_proceso": 3, "listo_para_entregar": 2 }
  },
  "cotizaciones": {
    "creadas": 2, "pendientes": 4, "aprobadas": 1,
    "aprobadas_nota": "Aproximado por fecha de última edición (el modelo no guarda cuándo cambió el estado).",
    "total_pendiente": 2500.0
  },
  "finanzas": {
    "source": "movimientos_financieros (real)",
    "ingresos": 80.0, "egresos": 50.0, "utilidad": 30.0,
    "pendiente_por_cobrar_estimado": 500.0
  },
  "alertas": [ "2 órdenes listas para entregar", "2 órdenes sin técnico asignado" ],
  "warnings": [],
  "detalles": { "ordenes_creadas": [...], "ordenes_listas": [...], "cotizaciones_pendientes": [...] }
}
```

Semántica (importante para redactar bien el resumen):

- `creadas`/`entregadas` son **del periodo** (por `fecha_recepcion`/`fecha_entrega`).
- `por_estado`, `listas_para_entregar`, `en_proceso`, `sin_tecnico` y
  `cotizaciones.pendientes` son **snapshot actual** (lo accionable hoy), no del periodo.
- `finanzas` es **real** (suma de movimientos financieros del periodo, incluye
  gastos operativos). `pendiente_por_cobrar_estimado` es **estimado**: suma de
  `total_cliente` de órdenes `listo_para_entregar` (aún sin finanzas).
- `cotizaciones.aprobadas` es **aproximado** (ver `aprobadas_nota`).

`GET /reports/weekly` es igual con `week_start=YYYY-MM-DD` (default lunes de la
semana actual) y agrega `week_end`; acumula 7 días.

## Corte — `GET /reports/cash-cut`

Params: `period=daily|weekly` (default daily), `date=` (daily), `week_start=`
(weekly), `partner=`, `include_details=true`.

```json
{
  "period": "daily", "from": "2026-07-05", "to": "2026-07-05",
  "source": "movimientos_financieros (real)",
  "ingresos": 600.0, "egresos": 400.0, "utilidad": 200.0,
  "ordenes_entregadas": 1, "servicios": 0.0, "refacciones": 600.0,
  "pendiente_por_cobrar_estimado": 750.0,
  "pendiente_por_cobrar_nota": "Estimado: suma de total_cliente de órdenes listas para entregar (aún sin finanzas).",
  "warnings": [],
  "detalles": [ { "fecha": "2026-07-05", "tipo": "egreso", "categoria": "gasolina", "monto": 100.0, "descripcion": "...", "orden_servicio_id": null } ]
}
```

- `ingresos/egresos/utilidad` son **reales** (movimientos del periodo: finanzas
  de órdenes entregadas + movimientos manuales + gastos OpenClaw).
- `servicios`/`refacciones` son la venta (precio cliente) de las órdenes
  **entregadas en el periodo**.
- No duplica lógica de finanzas: solo lee `movimientos_financieros`, que genera
  el flujo existente (`GenerarFinanzasOrdenServicio`) al entregar.

## Seguimientos — `GET /follow-ups`

Params: `type=orders|quotes|all` (default all), `overdue_days=` (umbral
override), `estado=`, `partner=`, `date=` (fecha de referencia, default hoy).

Detecta (umbral por defecto en días, medido desde el último cambio de estado):

| Situación | Umbral |
|---|---|
| Orden `recibido` sin avance | 2 |
| Orden `en_diagnostico` estancada | 3 |
| Orden `listo_para_entregar` no entregada | 1 |
| Cotización `borrador`/`enviada` sin respuesta | 2 |
| Orden activa sin técnico asignado | inmediato |
| Refacciones con costo o precio en 0 (orden activa) | inmediato |

Respuesta: `{ "date": "...", "items": [ { "type": "order|quote", "folio": "...",
"cliente": "...", "reason": "Lista para entregar desde hace 2 días",
"suggested_action": "Enviar mensaje al cliente para agendar la entrega",
"show_url": "..." } ], "warnings": [] }`.

## Mensajes — `POST /service-orders/{orden}/message-template`

Genera **texto listo para copiar/pegar**; ECore **no envía nada** (sin WhatsApp).

```json
{ "tipo": "estado", "estado": "listo_para_entregar", "tono": "amable",
  "incluir_total": true, "incluir_sucursal": true }
```

```json
{ "tipo": "manual", "instruccion": "ya quedó lista y puedes pasar por ella hoy" }
```

- `tipo: estado` — plantillas para los 9 estados canónicos (`en_fixop` usa la de
  "en proceso"); `estado` default = estado actual de la orden. Tonos: `amable`
  (default), `formal`, `breve`.
- `incluir_total` usa `total_cliente` real; si es 0 → warning y no se incluye.
  `incluir_sucursal` usa el partner de recepción; sin partner → warning.
- Cliente sin teléfono → warning (el mensaje se genera igual).
- `tipo: manual` — `instruccion` obligatoria (`422` si falta); se inserta tal
  cual tras el saludo con folio y equipo. OpenClaw hace la redacción fina.
- Respuesta: `{ "id", "folio", "estado", "message", "warnings" }`. Nunca
  contraseñas.

## Gastos operativos — `/expenses`

`POST /api/internal/expenses`:

```json
{
  "descripcion": "Compra USB 16GB", "monto": 50, "categoria": "refaccion",
  "proveedor": "Mercado Libre", "fecha": "2026-07-05",
  "notas": "Registrado por Telegram", "external_id": "telegram-expense-123"
}
```

- Categorías OpenClaw → categorías del panel: `refaccion→refaccion`,
  `herramienta→herramientas`, `gasolina→gasolina`, `envio→transporte`,
  `comida→gasto_operativo`, `otro→otro`. Otra categoría → `422`.
- Se guarda como `MovimientoFinanciero` **egreso manual** (`orden_servicio_id =
  null`), igual que el formulario manual del panel. **Sí afecta el corte** (entra
  a los egresos reales de su fecha); no se mezcla con las finanzas generadas por
  órdenes (esas llevan `orden_servicio_id`).
- Idempotencia con `external_id` (columna única en `movimientos_financieros`):
  reenviar → `200` con `created: false`, sin duplicar.
- `fecha` default hoy. `monto` > 0.

`GET /api/internal/expenses?date=...` (o `date_from`/`date_to`, `categoria`,
`limit` máx 100): lista **solo** egresos manuales/operativos con `total`.

## Cómo probar (PowerShell 5.1, cuerpo como bytes UTF-8)

```powershell
$token = "TU_TOKEN"
Invoke-RestMethod -Uri "http://sistema-central-ecore.test/api/internal/reports/daily?include_details=true" `
  -Headers @{ Authorization = "Bearer $token"; Accept = "application/json" }

$body = @'
{ "descripcion": "Compra USB 16GB", "monto": 50, "categoria": "refaccion", "external_id": "telegram-expense-123" }
'@
Invoke-RestMethod -Method Post -Uri "http://sistema-central-ecore.test/api/internal/expenses" `
  -Headers @{ Authorization = "Bearer $token"; Accept = "application/json" } `
  -ContentType "application/json; charset=utf-8" `
  -Body ([System.Text.Encoding]::UTF8.GetBytes($body))
```

```bash
curl -H "Authorization: Bearer $TOKEN" "http://sistema-central-ecore.test/api/internal/search?q=Barrera&type=all"
curl -H "Authorization: Bearer $TOKEN" "http://sistema-central-ecore.test/api/internal/follow-ups?type=all"
```
