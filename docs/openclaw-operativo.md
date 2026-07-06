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
