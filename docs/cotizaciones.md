# Módulo de cotizaciones

## Qué hace

Permite crear presupuestos (cotizaciones) para clientes, con conceptos de tipo
`servicio`, `refaccion`, `producto` u `otro`. Los totales (subtotal, descuento,
total, anticipo, saldo) **siempre se calculan en el servidor**
(`App\Actions\Cotizaciones\CalcularTotalesCotizacion`). El folio se genera con
bloqueo (`COT-YYYYMMDD-0001`), igual que el de órdenes de servicio.

Estados: `borrador`, `enviada`, `aceptada`, `rechazada`, `vencida`.
Solo `borrador` y `enviada` son editables; salir de un estado final requiere admin.

## Piezas principales

| Pieza | Archivo |
|---|---|
| Modelos | `app/Models/Cotizacion.php`, `app/Models/CotizacionItem.php` |
| Actions | `app/Actions/Cotizaciones/{CalcularTotalesCotizacion,CrearCotizacion,ActualizarCotizacion,CambiarEstadoCotizacion}.php` |
| PDF | `app/Services/ExportarCotizacionPdf.php` + `resources/views/cotizaciones/{pdf,plantilla,_documento}.blade.php` |
| CRUD web | `app/Http/Controllers/CotizacionController.php` + vistas en `resources/views/cotizaciones/` |
| API interna | `app/Http/Controllers/Api/InternalQuoteController.php` + `routes/api.php` + `app/Http/Middleware/VerifyInternalApiToken.php` |
| Config del negocio | `config/negocio.php` (variables `NEGOCIO_*` en `.env`) |

Roles: `admin` ve y administra todo; `socio_logistico` solo ve cotizaciones de su
`partner_id`; `socio_tecnico` no tiene acceso.

## API interna (OpenClaw)

Dos rutas, ambas protegidas por el middleware `internal.api`
(`VerifyInternalApiToken`) y con rate limit de 30 solicitudes/minuto. Ninguna
requiere sesión web: se autentican con el mismo Bearer Token interno
(`OPENCLAW_INTERNAL_API_TOKEN` en `.env`). Si el token no está configurado en el
servidor el endpoint responde `403`; con token ausente o incorrecto responde `401`.

| Ruta | Uso |
|---|---|
| `POST /api/internal/quotes` | Crear cotización |
| `GET /api/internal/quotes/{cotizacion}/pdf` | Descargar el PDF de una cotización |

### Crear cotización por API

`POST /api/internal/quotes` acepta `cliente_id` **o** `cliente {nombre, telefono?,
direccion?}` para alta rápida, `equipo_id` o `equipo {tipo_equipo, marca?, modelo?,
numero_serie?}`, `items[]` (`tipo`, `descripcion`, `cantidad`, `precio_unitario`),
`descuento`, `anticipo`, `notas`, `vigencia`, `external_id` (idempotencia: si se
repite el mismo `external_id` se devuelve la cotización ya creada, con la misma
`internal_pdf_url`), y opcionalmente `tipo_recepcion`
(`en_negocio` | `recogido_a_domicilio`) con `direccion_recepcion`. Si es recogida a
domicilio y no se manda `direccion_recepcion`, se usa `cliente.direccion` como
respaldo; si tampoco hay dirección, responde `422`. No expone finanzas ni datos
sensibles (nunca `password_equipo`).

Respuesta `201` (campos relevantes):

```json
{
  "id": 12,
  "folio": "COT-20260704-0001",
  "external_id": "openclaw-msg-001",
  "total": 1000.0,
  "saldo": 800.0,
  "tipo_recepcion": "recogido_a_domicilio",
  "direccion_recepcion": "Av. Convención 500, Aguascalientes",
  "internal_pdf_url": "http://sistema-central-ecore.test/api/internal/quotes/12/pdf",
  "internal_png_url": "http://sistema-central-ecore.test/api/internal/quotes/12/png",
  "pdf_download_endpoint": "/api/internal/quotes/12/pdf",
  "png_download_endpoint": "/api/internal/quotes/12/png",
  "pdf_url": "http://.../admin/cotizaciones/12/pdf",
  "show_url": "http://.../admin/cotizaciones/12",
  "web_urls_require_session": true
}
```

- **`internal_pdf_url` / `internal_png_url`** (y sus variantes `*_download_endpoint`
  relativas): rutas que OpenClaw debe usar para descargar PDF/PNG con el Bearer
  Token, sin sesión web.
- **`pdf_url` / `show_url`**: rutas del panel web; solo funcionan para usuarios
  autenticados con sesión (`web_urls_require_session: true`). No sirven para OpenClaw.

### Descargar el PDF por API interna

`GET /api/internal/quotes/{cotizacion}/pdf` con el mismo Bearer Token devuelve el
PDF binario (`Content-Type: application/pdf`) como descarga
`cotizacion-COT-YYYYMMDD-0001.pdf`. Reutiliza el servicio `ExportarCotizacionPdf`
(el mismo del panel web). Si la cotización no existe responde `404`. Registra un
log básico de cada descarga interna.

> Nota de aislamiento: las cotizaciones creadas por la API interna no pertenecen a
> ningún `partner_id` (el token interno es de sistema, no de un socio). El control
> de acceso de estas rutas es el Bearer Token, no el filtro por partner (que sí
> aplica en el panel web para `socio_logistico`).

### Ejemplos (PowerShell)

Crear una cotización:

```powershell
$token = "TU_TOKEN"
$crear = Invoke-RestMethod -Method Post `
  -Uri "http://sistema-central-ecore.test/api/internal/quotes" `
  -Headers @{ Authorization = "Bearer $token" } `
  -ContentType "application/json" `
  -Body '{"cliente":{"nombre":"Cliente Telegram","telefono":"5551112233"},"items":[{"tipo":"servicio","descripcion":"Diagnóstico","cantidad":1,"precio_unitario":250}],"anticipo":100,"external_id":"msg-001"}'

$crear.folio            # COT-20260704-0001
$crear.internal_pdf_url # URL interna del PDF
```

Descargar el PDF de esa cotización a un archivo local:

```powershell
Invoke-WebRequest -Method Get `
  -Uri $crear.internal_pdf_url `
  -Headers @{ Authorization = "Bearer $token" } `
  -OutFile "$env:TEMP\cotizacion.pdf"
```

Equivalente con curl:

```bash
curl -H "Authorization: Bearer $TOKEN" \
  http://sistema-central-ecore.test/api/internal/quotes/12/pdf \
  -o cotizacion.pdf
```

### Cómo lo usará OpenClaw desde Telegram

1. El usuario pide una cotización por Telegram; OpenClaw arma el JSON y llama a
   `POST /api/internal/quotes` con el Bearer Token (usando el `message_id` de
   Telegram como `external_id` para que reintentos no dupliquen).
2. De la respuesta toma `internal_pdf_url` o `internal_png_url` y descarga el
   archivo con el mismo token.
3. Adjunta el PNG al chat con `sendPhoto`, o el PDF con `sendDocument`.

## Plantilla, logo y datos del negocio

La plantilla (`resources/views/cotizaciones/_documento.blade.php`) es compartida
por la vista imprimible, el PDF y el PNG. Muestra logo, nombre, teléfono, correo,
folio, fecha, vigencia, estado, cliente, equipo, **ubicación de recepción**,
conceptos, subtotal, descuento, total, anticipo, saldo, notas y leyenda.

- **Logo**: `public/images/logo-ecore.png`, configurable con `NEGOCIO_LOGO`
  (ruta relativa a `public/`). Se incrusta en base64 para que funcione igual en
  navegador, dompdf y captura headless. Si el archivo no existe, se muestra el
  nombre del negocio en texto.
- **Datos del negocio**: `config/negocio.php` (`NEGOCIO_NOMBRE`, `NEGOCIO_ESLOGAN`,
  `NEGOCIO_TELEFONO`, etc.). Defaults: E-Core / Mantenimiento de Software /
  4494226522.

## Recepción del equipo

Cada cotización guarda un **snapshot** de dónde se recibió el equipo
(`tipo_recepcion`: `en_negocio` | `recogido_a_domicilio`, y
`direccion_recepcion`). Se guarda en la propia cotización —no se deriva del
cliente— para que el documento conserve la dirección usada al emitirla aunque el
cliente cambie después. Con `en_negocio` la plantilla muestra el nombre del
`partner` (sucursal) si la cotización tiene uno; con `recogido_a_domicilio` la
dirección es obligatoria (el formulario web la pide y la API responde `422` si
falta).

## PDF

Generado con `barryvdh/laravel-dompdf` (v3, motor dompdf puro PHP — sin binarios
externos, funciona sin problema en Herd/Windows). Un único servicio
(`ExportarCotizacionPdf`) alimenta las tres salidas:

- **Panel web** (requiere sesión): `/{admin|logistica}/cotizaciones/{id}/pdf`.
- **API interna** (Bearer Token, sin sesión): `/api/internal/quotes/{id}/pdf`.
- **Vista imprimible** en navegador: `/{admin|logistica}/cotizaciones/{id}/plantilla`.

## PNG (implementado)

`ExportarCotizacionPng` renderiza `resources/views/cotizaciones/png.blade.php`
(misma plantilla `_documento`, lienzo de 800 px) y toma la captura con un
**navegador headless ya instalado** — sin dependencias de Composer ni Node:

1. Resuelve el navegador: `COTIZACIONES_BROWSER_BIN` del `.env` si está definido;
   si no, Microsoft Edge en sus rutas estándar de Windows, luego Chrome, y por
   último `google-chrome`/`chromium` en el PATH (para CI Linux).
2. Escribe la vista a un HTML temporal en `storage/app/cotizaciones-png/` y ejecuta
   `--headless=new --screenshot` a escala 2x (imagen nítida de 1600 px de ancho).
3. Recorta el blanco sobrante inferior con GD (incluido en Herd) y borra los
   temporales.

Rutas (las tres devuelven `image/png` como descarga `cotizacion-FOLIO.png`):

- **Panel web**: `/{admin|logistica}/cotizaciones/{id}/png` (botón "Descargar PNG"
  en el detalle).
- **API interna**: `GET /api/internal/quotes/{id}/png` con Bearer Token.

Si no hay navegador disponible, el panel responde `503` con instrucciones y la API
devuelve JSON `503`; define `COTIZACIONES_BROWSER_BIN` en el `.env` en ese caso.
