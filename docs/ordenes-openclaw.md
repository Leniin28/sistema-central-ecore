# API interna: editar órdenes y cambiar estados desde OpenClaw

Permite que OpenClaw, desde Telegram: (1) agregue **servicios y refacciones reales**
(y notas) a una **orden existente**, y (2) **cambie el estado** de la orden,
incluida la entrega final con confirmación explícita.

Comparte el motor con la recepción: las líneas usan la Action
`App\Actions\Ordenes\AplicarCambiosOrdenDesdeOpenClaw`, que **agrega** líneas (no
reemplaza, a diferencia del panel) y recalcula `total_cliente` con la misma fórmula
del panel (`CalcularTotalesOrdenServicio`). Los estados usan
`CambiarEstadoOrdenDesdeOpenClaw`, que replica el flujo del panel
(`OrdenServicioEstadoController`) y reutiliza **exactamente** el servicio existente
`GenerarFinanzasOrdenServicio` al entregar.

## Rutas y seguridad

| Ruta | Uso |
|---|---|
| `POST /api/internal/service-orders/{orden}/changes` | Agregar servicios/refacciones/notas |
| `POST /api/internal/service-orders/{orden}/status` | Cambiar el estado de la orden |

- `{orden}` acepta **id numérico** o **folio** (`OS-20260705-0004`).
- Middleware `internal.api` (Bearer Token) + `throttle:30,1`, igual que cotizaciones
  y recepciones. Validación 100% en servidor.
- Orden inexistente → `404`.
- Orden con **finanzas cerradas** (`finanzas_generadas` o estado `entregado`) → `409`
  (no se aplica nada). Refleja la regla del panel, que bloquea editar órdenes cerradas.
- No expone `password_equipo` en ninguna respuesta.
- Auditoría: el historial de estados se registra con el **usuario de sistema
  OpenClaw** (`OPENCLAW_SYSTEM_USER_EMAIL`), igual que la creación de recepciones.

## Agregar servicios/refacciones — payload

**Formato recomendado** (listas en la raíz). Por tolerancia también se acepta el
wrapper `agregar` con las mismas listas dentro
(`{"agregar": {"servicios": [...], "refacciones": [...]}}`); si ambas formas
vienen, gana la raíz.

```json
{
  "external_id": "telegram-edit-123",
  "servicios": [
    { "servicio_id": 1, "precio_cliente": 550, "cantidad": 1, "notas": "..." },
    { "descripcion": "optimización", "precio_cliente": 550 }
  ],
  "servicios_sugeridos": [ { "descripcion": "limpieza", "precio": 200 } ],
  "refacciones": [
    { "descripcion": "USB 16GB", "cantidad": 1, "costo_unitario": 50, "precio_cliente": 80, "notas": "..." }
  ],
  "notas": "Agregar USB de 16GB"
}
```

- **`servicios[]`** — `servicio_id` (catálogo, validado) **o** `descripcion` (auto-match
  por nombre). `precio_cliente` (o `precio`; default `precio_base`), `cantidad`
  (default 1), `notas`. Sin match claro → `warning` + nota, **sin línea falsa**.
- **`servicios_sugeridos[]`** — siempre quedan como nota + `warning` (nunca facturable).
- **`refacciones[]`** — texto libre. `descripcion*`, `precio_cliente` (o `precio`),
  `costo_unitario` (default 0), `cantidad` (default 1), `notas`.
- **`external_id`** — idempotencia de la edición (ledger `openclaw_order_changes`):
  reenviar el mismo no vuelve a aplicar (`aplicado: false`, `duplicado: true`).
- Precios `>= 0`; cantidades enteras `>= 1`.

Todo se aplica dentro de una transacción. Agregar líneas **no** genera finanzas.

## Respuesta

```json
{
  "aplicado": true,
  "duplicado": false,
  "id": 5,
  "folio": "OS-20260705-0004",
  "cliente": "Román Barrera",
  "estado": "recibido",
  "total_cliente": 760.0,
  "servicios_agregados": [
    { "servicio_id": 1, "nombre": "Optimización", "cantidad": 1, "precio_unitario": 550.0, "subtotal": 550.0 }
  ],
  "refacciones_agregadas": [
    { "descripcion": "USB 16GB", "cantidad": 1, "costo_unitario": 50.0, "precio_unitario_cliente": 80.0, "precio_total_cliente": 80.0 }
  ],
  "warnings": [],
  "show_url": "http://sistema-central-ecore.test/admin/ordenes-servicio/5"
}
```

## Cambiar estado — payload

`POST /api/internal/service-orders/{orden}/status`

```json
{
  "estado": "en_proceso",
  "notas": "Cambio solicitado por Telegram",
  "external_id": "telegram-status-123",
  "confirm_final_delivery": false
}
```

**Estados válidos** (canónicos; cualquier otro → `422`):
`recibido`, `en_diagnostico`, `cotizacion_pendiente`, `cotizacion_aprobada`,
`en_proceso`, `en_fixop`, `listo_para_entregar`, `entregado`, `cancelado`.

> OpenClaw debe mapear el lenguaje natural al estado canónico: "en reparación" →
> `en_proceso`, "lista" → `listo_para_entregar`, "entrégala" → `entregado`.
> **No existe** `en_reparacion`.

Reglas:

- El actor es el usuario de sistema OpenClaw (admin) → puede hacer cualquier
  transición, igual que un admin en el panel. Queda en `historial_estados`.
- **`entregado` exige `confirm_final_delivery: true`.** Entregar cierra la orden y
  genera los movimientos financieros reales (mismo `GenerarFinanzasOrdenServicio`
  del panel: ingreso, comisión logística, pago técnico, egresos de refacciones,
  `utilidad_neta`, `finanzas_generadas=true`). Sin el flag → `409` con
  `requires_confirmation: true` y **sin efectos** (el `external_id` no se consume:
  el reintento confirmado puede reutilizarlo).
- Orden ya entregada/cerrada → `409`.
- Repetir el estado actual → `200` con `cambiado: false` y un warning (sin efectos).
- **Idempotencia**: reenviar el mismo `external_id` → `200` con `cambiado: false`,
  `duplicado: true`; no duplica historial ni finanzas. (Además,
  `GenerarFinanzasOrdenServicio` es idempotente por sí mismo.)

### Respuesta del cambio de estado

```json
{
  "cambiado": true,
  "duplicado": false,
  "id": 6,
  "folio": "OS-20260705-0006",
  "estado_anterior": "en_proceso",
  "estado_actual": "entregado",
  "cliente": "Cliente Flujo Estados",
  "equipo": { "tipo_equipo": "Laptop", "marca": "HP", "modelo": null },
  "total_cliente": 80.0,
  "finanzas_generadas": true,
  "fecha_entrega": "2026-07-05T12:34:56-06:00",
  "show_url": "http://sistema-central-ecore.test/admin/ordenes-servicio/6",
  "warnings": []
}
```

(`equipo` solo incluye tipo/marca/modelo — nunca `password_equipo`.)

## Cómo probar

> **Windows PowerShell 5.1:** envía el cuerpo como **bytes UTF-8** (ver
> `docs/cotizaciones.md`).

```powershell
$token = "TU_TOKEN"
$body = @'
{ "refacciones": [ { "descripcion": "USB 16GB", "cantidad": 1, "costo_unitario": 50, "precio_cliente": 80, "notas": "Solicitado por Telegram" } ],
  "notas": "Agregar USB de 16GB",
  "external_id": "telegram-edit-123" }
'@

Invoke-RestMethod -Method Post `
  -Uri "http://sistema-central-ecore.test/api/internal/service-orders/OS-20260705-0004/changes" `
  -Headers @{ Authorization = "Bearer $token"; Accept = "application/json" } `
  -ContentType "application/json; charset=utf-8" `
  -Body ([System.Text.Encoding]::UTF8.GetBytes($body))
```

```bash
curl -X POST http://sistema-central-ecore.test/api/internal/service-orders/OS-20260705-0004/changes \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json; charset=utf-8" -H "Accept: application/json" \
  --data '{"servicios":[{"servicio_id":1,"cantidad":1}],"external_id":"telegram-edit-124"}'
```

Cambiar estado (PowerShell):

```powershell
$body = @'
{ "estado": "en_proceso", "notas": "Cambio solicitado por Telegram", "external_id": "telegram-status-123" }
'@
Invoke-RestMethod -Method Post `
  -Uri "http://sistema-central-ecore.test/api/internal/service-orders/OS-20260705-0004/status" `
  -Headers @{ Authorization = "Bearer $token"; Accept = "application/json" } `
  -ContentType "application/json; charset=utf-8" `
  -Body ([System.Text.Encoding]::UTF8.GetBytes($body))
```

Entregar con confirmación (curl):

```bash
curl -X POST http://sistema-central-ecore.test/api/internal/service-orders/OS-20260705-0004/status \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json; charset=utf-8" -H "Accept: application/json" \
  --data '{"estado":"entregado","notas":"Entrega confirmada por Telegram","external_id":"telegram-status-124","confirm_final_delivery":true}'
```

## Limitaciones del auto-match de servicios

- Solo empata contra servicios de catálogo **activos**, por nombre normalizado
  (mayúsculas/acentos/espacios). Match exacto y luego parcial bidireccional.
- **No adivina:** nombres ambiguos (varios servicios contienen el texto, p. ej.
  `"optimización"` si hay varias variantes) o sin match → `warning`, sin línea. En ese
  caso usa `servicio_id` explícito o agrega el servicio en el panel.
- No se crean servicios de catálogo nuevos desde la API (por diseño).
