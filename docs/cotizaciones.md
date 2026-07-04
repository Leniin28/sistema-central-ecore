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
`descuento`, `anticipo`, `notas`, `vigencia` y `external_id` (idempotencia: si se
repite el mismo `external_id` se devuelve la cotización ya creada, con la misma
`internal_pdf_url`). No expone finanzas ni datos sensibles (nunca `password_equipo`).

Respuesta `201` (campos relevantes):

```json
{
  "id": 12,
  "folio": "COT-20260704-0001",
  "external_id": "openclaw-msg-001",
  "total": 1000.0,
  "saldo": 800.0,
  "internal_pdf_url": "http://sistema-central-ecore.test/api/internal/quotes/12/pdf",
  "pdf_download_endpoint": "/api/internal/quotes/12/pdf",
  "pdf_url": "http://.../admin/cotizaciones/12/pdf",
  "show_url": "http://.../admin/cotizaciones/12",
  "web_urls_require_session": true
}
```

- **`internal_pdf_url` / `pdf_download_endpoint`**: es la ruta que OpenClaw debe
  usar para descargar el PDF con el Bearer Token, sin sesión web.
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
2. De la respuesta toma `internal_pdf_url` y descarga el PDF con el mismo token.
3. Adjunta ese PDF al chat con `sendDocument` de la Bot API de Telegram.
4. El envío como imagen (`sendPhoto`) queda para la fase posterior de PNG descrita
   abajo; mientras tanto el PDF vía `sendDocument` cubre el caso de uso.

## PDF

Generado con `barryvdh/laravel-dompdf` (v3, motor dompdf puro PHP — sin binarios
externos, funciona sin problema en Herd/Windows). Un único servicio
(`ExportarCotizacionPdf`) alimenta las tres salidas:

- **Panel web** (requiere sesión): `/{admin|logistica}/cotizaciones/{id}/pdf`.
- **API interna** (Bearer Token, sin sesión): `/api/internal/quotes/{id}/pdf`.
- **Vista imprimible** en navegador: `/{admin|logistica}/cotizaciones/{id}/plantilla`.

## Fase posterior: export a PNG (pendiente, NO implementada)

Objetivo futuro: enviar la cotización como imagen por Telegram (OpenClaw).

- **Opción recomendada**: `spatie/browsershot` renderizando
  `cotizaciones/plantilla` a PNG (misma vista, fidelidad total).
- **Dependencias necesarias**: Node.js + Puppeteer (descarga un Chromium
  headless, ~300 MB) o apuntar Browsershot a un Chrome/Edge ya instalado con
  `->setChromePath()`.
- **Riesgos en Windows/Herd**: rutas de node/npm no visibles para PHP-FPM de
  Herd, bloqueos de antivirus/Defender al lanzar Chromium, procesos zombis, y
  actualizaciones de Chrome que rompen Puppeteer. Por eso se pospuso.
- **Alternativa sin Node**: Imagick + Ghostscript para rasterizar el PDF ya
  existente (frágil en Windows: requiere extensión Imagick compilada para la
  versión exacta de PHP de Herd).
- **Integración con Telegram**: el flujo previsto es que OpenClaw llame a
  `POST /api/internal/quotes`, reciba `id`/`folio`, y luego pida
  `GET /api/internal/quotes/{id}/png` (endpoint futuro, mismo middleware
  `internal.api`) para adjuntar la imagen con `sendPhoto`. Mientras tanto ya puede
  descargar el PDF vía `GET /api/internal/quotes/{id}/pdf` con el Bearer Token
  (sin sesión web) y adjuntarlo con `sendDocument`.
