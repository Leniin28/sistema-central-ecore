# Graphify en Sistema Central ECore

## Qué es

Graphify es un grafo de conocimiento del código de ECore. Convierte el proyecto (modelos, controladores, acciones, servicios, vistas Blade, migraciones, tests, `AGENTS.md`, `README.md`) en un grafo consultable con comunidades detectadas automáticamente. Su propósito es que las tareas con IA identifiquen primero los archivos relevantes **sin leer el proyecto completo**, ahorrando tokens y tiempo.

- CLI global: `graphify` (instalado con `uv tool`, v0.9.6+).
- Intérprete Python pineado en `graphify-out/.graphify_python` (venv de uv: `%APPDATA%\uv\tools\graphifyy`).
- Funciona sin clave de API para código: la extracción de PHP/JS/Blade es estructural (AST, gratis). Solo docs/imágenes usan LLM.

## Dónde se genera

Todo vive en `graphify-out/` (ignorado por Git, se regenera):

| Archivo | Contenido |
|---|---|
| `graph.json` | Grafo crudo (nodos, aristas, comunidades) — fuente para consultas |
| `graph.html` | Visualización interactiva; abrir directo en el navegador |
| `GRAPH_REPORT.md` | Reporte auditable: god nodes, conexiones sorprendentes, comunidades |
| `cost.json` | Tokens consumidos por corrida |
| `cache/` | Caché de extracción (evita re-extraer archivos sin cambios) |

## Cómo actualizarlo

**Automático:** hay un hook `post-commit` (y `post-checkout`) instalado con `graphify hook install`. Tras cada commit re-extrae solo los archivos de código cambiados (AST, sin LLM) en segundo plano, sin bloquear el commit. Log: `~/.cache/graphify-rebuild.log`.

**Manual:**

```bash
graphify update .        # incremental: solo archivos cambiados (código, sin LLM)
graphify cluster-only .  # re-clustering + reporte sobre el grafo existente
```

Si cambian documentos o imágenes (no código), correr `/graphify . --update` desde una sesión de Claude Code para que la extracción semántica use subagentes.

Para desinstalar o revisar el hook: `graphify hook status` / `graphify hook uninstall`. Para saltarlo en un commit: `GRAPHIFY_SKIP_HOOK=1 git commit ...`.

## Cómo consultarlo

```bash
graphify query "¿Cómo funciona la generación automática de finanzas?"
graphify query "¿Qué archivos toca el flujo de estados de órdenes?" --budget 1500
graphify path "OrdenServicioController" "MovimientoFinanciero"   # ruta más corta entre dos conceptos
graphify explain "GenerarFinanzasOrdenServicio"                  # explicación de un nodo
```

La respuesta es un subgrafo acotado (mucho más barato que grep o leer archivos completos).

## Qué está excluido

Graphify excluye automáticamente `vendor/`, `node_modules/`, `storage/`, `bootstrap/cache/`, `public/build/` y `graphify-out/`. También omite archivos sensibles (`.env` y similares: 4 archivos fueron detectados y saltados en la corrida inicial). Se indexan: `app/`, `routes/`, `resources/views/`, `database/`, `tests/`, `config/`, `composer.json`, `package.json`, `AGENTS.md`, `README.md`, workflows de CI.

## Reglas para agentes IA (obligatorias antes de modificar código)

1. **Antes de abrir archivos**, ejecutar al menos una consulta para acotar el alcance:
   - `graphify query "¿Cómo está organizado <módulo> en ECore?"`
   - `graphify query "¿Qué archivos se verían afectados por <cambio>?"`
   - `graphify explain "<clase o símbolo>"`
   - `graphify path "<símbolo A>" "<símbolo B>"`
2. **No explorar todo el proyecto** (grep masivo, leer carpetas enteras) si Graphify puede identificar primero los archivos relevantes. Abrir solo los archivos que el grafo señale.
3. Leer `GRAPH_REPORT.md` únicamente para revisiones de arquitectura amplias.
4. **Después de modificar código**, correr `graphify update .` (o dejar que el hook post-commit lo haga al commitear).
5. Si una consulta no devuelve nada útil (grafo desactualizado), regenerar con `graphify update .` y reintentar antes de caer a exploración manual.
6. Estas reglas complementan, no sustituyen, las reglas de `AGENTS.md` (flujos críticos de finanzas, roles, migraciones).

## Verificación manual rápida

```bash
graphify hook status                      # ambos hooks "installed"
graphify query "finanzas automáticas"     # debe devolver GenerarFinanzasOrdenServicio y relacionados
start graphify-out/graph.html             # abre el grafo interactivo (Windows)
```

## Notas de entorno (Herd / Windows)

- El proyecto corre con Laravel Herd; Graphify no toca la configuración de Herd ni el `.env`.
- El hook corre bajo Git Bash (sh) de Git for Windows; el rebuild se lanza como proceso separado (no bloquea commits) y limita workers a 1 en Windows para evitar problemas de pipes.
- La corrida inicial indexó 254 archivos (~71k palabras): 696 nodos, 896 aristas, 206 comunidades.
