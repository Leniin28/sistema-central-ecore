# API interna de recepciones (OpenClaw / foto de etiqueta)

Permite que OpenClaw registre una **recepción/orden de servicio** a partir de los
datos que un modelo de visión extrae de una **foto de etiqueta física** enviada por
Telegram.

## Ruta y seguridad

| Ruta | Uso |
|---|---|
| `POST /api/internal/receptions` | Crear recepción/orden desde datos de etiqueta |

- Mismo middleware que la API de cotizaciones: `internal.api`
  (`VerifyInternalApiToken`) + rate limit `30/min`. Sin sesión web: Bearer Token
  interno (`OPENCLAW_INTERNAL_API_TOKEN`). Token ausente/incorrecto → `401`;
  token no configurado en el servidor → `403`.
- **Validación 100% en servidor** (`InternalReceptionController`).
- Reutiliza la Action de órdenes existente: `RegistrarRecepcionDesdeOpenClaw`
  orquesta cliente/equipo y delega en `App\Actions\Ordenes\CrearOrdenServicio`
  (folio `OS-YYYYMMDD-0001`, historial de estados, transacción). No duplica lógica.

## Payload

```json
{
  "cliente": { "nombre": "José Luis Olvera", "telefono": "4494156210" },
  "equipo": {
    "tipo_equipo": "Laptop", "marca": "HP", "modelo": "Laptop HP",
    "numero_serie": null, "password_equipo": "884960"
  },
  "recepcion": {
    "falla_reportada": "Le falla el lector y está lenta",
    "fecha_etiqueta": "2026-06-29",
    "folio_externo": "4837",
    "origen": "telegram_foto_etiqueta",
    "partner_logistico": "Electrocom Alameda",
    "notas": "Datos extraídos de etiqueta física"
  },
  "servicios":   [ { "descripcion": "Servicio de Optimización", "precio": 550 } ],
  "refacciones": [ { "descripcion": "SSD 120GB", "precio": 680 } ],
  "notas": "Aclaraciones adicionales enviadas por Telegram",
  "external_id": "telegram-photo-<message_id>"
}
```

Reglas de validación (todo opcional salvo lo indicado):

- **`cliente_id`** (existente) **o** `cliente { nombre* , telefono?, correo?, tipo_cliente? }`.
  `cliente.nombre` es obligatorio en alta rápida. El teléfono se normaliza a dígitos.
- **`equipo`** obligatorio; requiere **`tipo_equipo` o `modelo`** (al menos uno).
  `marca`, `numero_serie`, `password_equipo` opcionales.
- `recepcion.falla_reportada` opcional pero recomendada (si falta, se avisa en `warnings`).
  `recepcion.fecha_etiqueta` (fecha), `folio_externo`, `origen`, `notas` opcionales.
- **Sucursal / partner logístico** (opcional): ver sección siguiente.
- `tipo_recepcion` opcional (`sucursal|domicilio|directo`, default `directo`).
- `servicios[]` / `refacciones[]` opcionales: `descripcion*`, `precio` (`>= 0`).
- `external_id` opcional — **idempotencia**: reenviar el mismo (p. ej. el `message_id`
  de Telegram) devuelve la orden ya creada (`created: false`, `200`) sin duplicar.

Si solo llega **cliente + equipo + falla**, se crea la orden base (estado `recibido`)
sin servicios ni refacciones.

### Sucursal / partner logístico

La etiqueta física suele indicar la sucursal donde se recibió el equipo (p. ej.
*Electrocom Alameda*, *Electrocom Rodolfo*). Se resuelve al `partner_recepcion_id`
de la orden (el mismo "Partner logístico" del formulario web). Campos aceptados
(por orden de preferencia):

1. **`partner_recepcion_id`** (id explícito) — top-level o en `recepcion`. Se valida
   en servidor: debe ser un partner **logístico y activo**; si no, responde `422`.
2. **Nombre de la sucursal** — `recepcion.partner_logistico` (canónico), o los alias
   `recepcion.partner_logistico_nombre` / `recepcion.sucursal_nombre`, o el atajo
   top-level `partner_logistico`.

Resolución por nombre (solo partners logísticos activos), tolerante a variantes:

- Insensible a mayúsculas/acentos/espacios.
- Coincidencia exacta normalizada primero; si no, coincidencia parcial en cualquier
  dirección — `"Alameda"` resuelve a `"Electrocom Alameda"`.
- **No adivina:** si el texto coincide con más de un partner (p. ej. `"Electrocom"`)
  o con ninguno, **no falla la recepción**: crea la orden **sin partner**, agrega un
  `warning` y conserva el texto detectado en las notas para asignarlo en el panel.

El partner asignado (o `null`) se devuelve en la respuesta como `partner_recepcion`.
Las órdenes por API interna siguen sin exigir partner (una recepción mínima es válida).

### Servicios y refacciones (líneas reales)

Desde la versión de jul 2026 los servicios y refacciones se agregan como **líneas
reales** de la orden (reutilizando `AplicarCambiosOrdenDesdeOpenClaw` — el mismo
motor del endpoint de edición, ver `docs/ordenes-openclaw.md`). `total_cliente` se
recalcula con la fórmula del panel. Campos por línea:

- **`servicios[]`**: `servicio_id` (catálogo) **o** `descripcion` (se intenta empatar
  con el catálogo por nombre), `precio_cliente` (o `precio`; default = `precio_base`
  del catálogo), `cantidad` (default 1), `notas`.
- **`refacciones[]`**: `descripcion*`, `precio_cliente` (o `precio`), `costo_unitario`
  (default 0), `cantidad` (default 1), `notas`. Aceptan **texto libre**.

Reglas del auto-match de servicios (solo catálogo activo, insensible a mayúsculas/
acentos): match exacto y luego parcial; **no adivina** — si no hay match o es
ambiguo, **no se crea línea facturable falsa**: se devuelve un `warning` y el texto
queda en las notas. Las refacciones siempre se crean (texto libre permitido).

No se generan movimientos financieros al agregar líneas (eso solo ocurre al pasar la
orden a `entregado`). Las líneas agregadas se devuelven en `servicios_agregados` /
`refacciones_agregadas`.

## Respuesta

`201` cuando crea, `200` cuando ya existía (idempotencia):

```json
{
  "created": true,
  "id": 12,
  "folio": "OS-20260705-0001",
  "estado": "recibido",
  "external_id": "telegram-photo-123",
  "origen": "telegram_foto_etiqueta",
  "cliente": { "id": 4, "nombre": "José Luis Olvera" },
  "equipo": {
    "id": 7, "tipo_equipo": "Laptop", "marca": "HP", "modelo": "Laptop HP",
    "password_equipo_registrada": true
  },
  "partner_recepcion": { "id": 1, "nombre": "Electrocom Alameda" },
  "total_cliente": 680.0,
  "servicios_agregados": [],
  "refacciones_agregadas": [
    { "descripcion": "SSD 120GB", "cantidad": 1, "costo_unitario": 400.0,
      "precio_unitario_cliente": 680.0, "precio_total_cliente": 680.0 }
  ],
  "show_url": "http://sistema-central-ecore.test/admin/ordenes-servicio/12",
  "mensaje_resumen": "Recepción registrada: orden OS-20260705-0001 para José Luis Olvera (Laptop HP).",
  "warnings": [ "No se encontró en el catálogo un servicio que coincida con \"…\"; …" ]
}
```

### Seguridad del `password_equipo`

- La respuesta **nunca** devuelve el valor de `password_equipo`: solo el booleano
  `password_equipo_registrada`. El valor sí se guarda en el equipo y es visible en el
  panel autenticado.
- Los logs registran `orden_id`/`folio`/`external_id`, **nunca** la contraseña ni el
  payload en claro.

### Pendientes

- **Seeder de partners logísticos** (sucursales como *Electrocom Alameda* /
  *Electrocom Rodolfo*) y del **usuario de sistema OpenClaw** para producción. Hoy
  ambos se crean/siembran a mano; la resolución por nombre solo acierta si los
  partners existen con nombres reconocibles. Sin seeder por ahora (decisión del
  2026-07-05).
- **Revisión de nombres del catálogo de servicios** para reducir ambigüedad del
  auto-match (hay varios servicios que contienen "optimización" y el match cae a
  warning). Pospuesto (2026-07-05) hasta probar el flujo real con OpenClaw
  integrado al endpoint de cambios.

### Atribución (usuario de sistema)

Las órdenes exigen un creador real (FK no nula) y la API interna no tiene sesión, así
que se atribuyen a un **usuario de sistema** (`OPENCLAW_SYSTEM_USER_EMAIL`, default
`openclaw-bot@sistema.local`, rol `admin`, password aleatorio inutilizable). Se crea
de forma perezosa la primera vez; **conviene pre-sembrarlo en producción**. Estas
órdenes no pertenecen a ningún `partner_id` (el token es de sistema, no de un socio).

## Cómo probar

> **Windows PowerShell 5.1:** envía el cuerpo como **bytes UTF-8** (los acentos de la
> falla/nombre rompen el JSON si se manda como string). Ver `docs/cotizaciones.md`.

```powershell
$token = "TU_TOKEN"
$body = @'
{ "cliente": { "nombre": "José Luis Olvera", "telefono": "4494156210" },
  "equipo": { "tipo_equipo": "Laptop", "marca": "HP", "modelo": "Laptop HP", "password_equipo": "884960" },
  "recepcion": { "falla_reportada": "Le falla el lector y está lenta", "origen": "telegram_foto_etiqueta" },
  "servicios": [ { "descripcion": "Servicio de Optimización", "precio": 550 } ],
  "external_id": "telegram-photo-123" }
'@

Invoke-RestMethod -Method Post `
  -Uri "http://sistema-central-ecore.test/api/internal/receptions" `
  -Headers @{ Authorization = "Bearer $token"; Accept = "application/json" } `
  -ContentType "application/json; charset=utf-8" `
  -Body ([System.Text.Encoding]::UTF8.GetBytes($body))
```

```bash
curl -X POST http://sistema-central-ecore.test/api/internal/receptions \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json; charset=utf-8" \
  -H "Accept: application/json" \
  --data '{"cliente":{"nombre":"José Luis Olvera"},"equipo":{"tipo_equipo":"Laptop","marca":"HP"},"recepcion":{"falla_reportada":"No enciende"},"external_id":"telegram-photo-123"}'
```

## Flujo en OpenClaw (Telegram)

1. El usuario envía una foto de la etiqueta; el modelo de visión de OpenClaw extrae
   los campos y arma el JSON (usando el `message_id` de Telegram como `external_id`).
2. `POST /api/internal/receptions` con el Bearer Token.
3. De la respuesta toma `folio`, `mensaje_resumen` y `warnings` para responder al chat;
   `show_url` sirve al staff (requiere sesión) para completar servicios/precios.
