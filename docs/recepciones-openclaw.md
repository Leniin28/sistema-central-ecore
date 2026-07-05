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
- `tipo_recepcion` opcional (`sucursal|domicilio|directo`, default `directo`).
- `servicios[]` / `refacciones[]` opcionales: `descripcion*`, `precio` (`>= 0`).
- `external_id` opcional — **idempotencia**: reenviar el mismo (p. ej. el `message_id`
  de Telegram) devuelve la orden ya creada (`created: false`, `200`) sin duplicar.

Si solo llega **cliente + equipo + falla**, se crea la orden base (estado `recibido`)
sin servicios ni refacciones.

### Servicios y refacciones: por qué van a notas

Los detalles facturables (`OrdenServicioDetalle`) requieren un `servicio_id` del
catálogo y un precio verificado. Lo extraído de una etiqueta es texto libre de baja
confianza, así que **no se crean líneas facturables**: se guardan como texto en las
**notas** de la orden y se devuelven en `warnings` para que el staff los confirme y
cargue en el panel. `total_cliente` queda en `0` y **no se generan movimientos
financieros**.

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
  "show_url": "http://sistema-central-ecore.test/admin/ordenes-servicio/12",
  "mensaje_resumen": "Recepción registrada: orden OS-20260705-0001 para José Luis Olvera (Laptop HP).",
  "warnings": [ "Los servicios llegaron como texto libre; cárgalos desde el catálogo…" ]
}
```

### Seguridad del `password_equipo`

- La respuesta **nunca** devuelve el valor de `password_equipo`: solo el booleano
  `password_equipo_registrada`. El valor sí se guarda en el equipo y es visible en el
  panel autenticado.
- Los logs registran `orden_id`/`folio`/`external_id`, **nunca** la contraseña ni el
  payload en claro.

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
