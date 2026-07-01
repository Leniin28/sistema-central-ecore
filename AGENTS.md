# Sistema Central ECore - Instrucciones para Codex

## Contexto general

Este proyecto se llama **Sistema Central ECore**.

Es una aplicación web desarrollada con Laravel para gestionar servicios técnicos y la operación interna entre:

- **Electrocom**, como socio logístico y punto de recepción.
- **Fixop**, como socio técnico.
- **Administrador general**, responsable de la operación completa y las finanzas.

El sistema debe evolucionar como una herramienta real para la administración de servicios técnicos.

No debe tratarse como una adaptación de restaurante ni tomar decisiones basadas en equivalencias académicas anteriores.

La prioridad es mantener una aplicación clara, segura, funcional, profesional y fácil de mantener.

---

## Módulos actuales

El sistema administra:

- Clientes.
- Equipos.
- Partners.
- Categorías de servicio.
- Servicios.
- Órdenes de servicio.
- Detalles de servicios dentro de las órdenes.
- Refacciones.
- Historial de estados.
- Movimientos financieros.
- Dashboards por rol.
- Finanzas automáticas al entregar órdenes.
- Movimientos financieros manuales.

Antes de proponer cambios, Codex debe revisar el código real relacionado y no asumir que la documentación está completamente actualizada.

`README.md` y los archivos de `docs/` son documentación de apoyo. Si existe alguna diferencia entre esos documentos y el código real del proyecto, el código real tiene prioridad. Codex debe señalar la diferencia cuando sea relevante.

---

## Tecnologías y arquitectura

Mantener las tecnologías y patrones existentes:

- Laravel.
- MySQL.
- Arquitectura MVC.
- Blade.
- Livewire cuando resulte útil y ya esté disponible.
- Eloquent ORM.
- Migraciones.
- Seeders.
- Middleware.
- Autenticación.
- Roles de usuario.
- Tailwind y Flux cuando ya formen parte de la interfaz.

No convertir el sistema en una API ni instalar paquetes externos sin una necesidad clara y autorización explícita.

Preferir soluciones simples, explicables y compatibles con la arquitectura existente.

---

## Roles y permisos

El sistema utiliza estos roles:

- `admin`
- `socio_logistico`
- `socio_tecnico`

Todo cambio debe conservar la compatibilidad con estos roles.

### Administrador

Puede gestionar la operación general, catálogos, órdenes, estados y finanzas, respetando los bloqueos de integridad del sistema.

### Socio logístico

Puede trabajar con la recepción de clientes, equipos y órdenes según las rutas y permisos existentes.

Cuando corresponda, sus órdenes deben quedar asociadas y filtradas mediante su `partner_id`.

### Socio técnico

Puede consultar las órdenes asignadas a su partner técnico y realizar únicamente los cambios de estado permitidos.

No debe recibir permisos administrativos por accidente.

Las restricciones deben aplicarse en el servidor. No es suficiente ocultar botones en las vistas.

---

## Flujos críticos que deben protegerse

Ningún cambio debe romper:

- La generación automática de finanzas.
- El historial de estados.
- El cálculo de `total_cliente`.
- El cálculo de costos y utilidad.
- La prevención de movimientos financieros duplicados.
- El bloqueo de órdenes entregadas con `finanzas_generadas = true`.
- Los dashboards y sus métricas.
- El filtrado de órdenes por partner.
- Los permisos por rol.
- Las relaciones entre clientes, equipos, órdenes, servicios y refacciones.

Las operaciones que modifican varias tablas deben ejecutarse dentro de transacciones cuando sea necesario.

No modificar directamente movimientos financieros automáticos sin revisar primero `GenerarFinanzasOrdenServicio`.

No realizar backfills, recálculos históricos o regeneración de finanzas sin autorización explícita.

---

## Migraciones y seeders

No modificar migraciones existentes ni crear nuevas migraciones sin explicar primero:

- Por qué son necesarias.
- Qué tabla o campo cambiarían.
- Qué impacto tendrían sobre datos existentes.
- Cómo se verificarían.
- Qué riesgo tendría revertirlas.

No ejecutar migraciones ni seeders sin autorización explícita.

No modificar datos iniciales, usuarios de prueba, partners, categorías o servicios base sin autorización.

Cuando sea necesario cambiar una base de datos ya utilizada, preferir una nueva migración en lugar de editar una migración histórica.

---

## Planificación antes de cambios grandes

Antes de implementar un cambio grande o que afecte varios módulos, Codex debe presentar un plan que incluya:

- Objetivo.
- Archivos que se modificarían.
- Archivos nuevos que se crearían.
- Cambios de base de datos, si existen.
- Cambios de permisos o roles.
- Riesgos técnicos.
- Impacto sobre órdenes, estados y finanzas.
- Pruebas necesarias.
- Comandos de verificación.

No comenzar la implementación hasta que el usuario autorice el plan cuando haya solicitado análisis previo.

Los cambios pequeños y claramente delimitados deben mantenerse dentro del alcance solicitado.

---

## Explicaciones para estudio

Cuando el usuario solicite explicaciones para estudiar el proyecto, Codex debe:

- Explicar de forma sencilla y paso por paso.
- No asumir que el usuario conoce programación avanzada.
- Definir las palabras técnicas con ejemplos fáciles.
- Explicar qué hace cada parte, para qué sirve y cómo se conecta con las demás.
- Preferir ejemplos tomados del código real del proyecto.

---

## Seguridad y datos sensibles

No exponer información sensible en respuestas, logs, APIs, herramientas de IA o auditorías.

Tratar especialmente como sensible:

- `password`
- `password_equipo`
- Tokens de autenticación.
- Secretos de doble factor.
- Datos financieros detallados.
- Información personal de clientes.

No confiar en datos calculados por el navegador. Totales, subtotales, costos y utilidades deben validarse o calcularse nuevamente en el servidor.

---

## Preparación futura para IA, MCP o API

Cualquier integración con una IA local, MCP o API debe desarrollarse por etapas.

Primero se debe centralizar la lógica del negocio en acciones o servicios reutilizables. La interfaz Blade, una API y una herramienta de IA deben usar las mismas reglas.

Toda integracion debe incluir:

- Autenticación.
- Permisos limitados.
- Validación del lado del servidor.
- Auditoría de acciones.
- Identificación del usuario o agente.
- Límites de solicitudes.
- Protección contra operaciones duplicadas.
- Registro de resultados y errores.
- Exclusión de datos sensibles.

Las operaciones de solo lectura deben implementarse antes que las operaciones de escritura.

Una IA no debe poder directamente:

- Marcar `finanzas_generadas`.
- Crear movimientos financieros automáticos.
- Modificar órdenes entregadas.
- Saltarse el flujo de estados.
- Eliminar órdenes o movimientos sin autorización.
- Consultar `password_equipo`.
- Actuar como administrador de forma predeterminada.

Las operaciones financieras o destructivas deben requerir permisos específicos y, cuando corresponda, confirmación humana.

---

## Reglas generales de trabajo

- Leer `AGENTS.md` antes de modificar código.
- Revisar el código real antes de proponer una solución.
- Respetar cambios locales existentes del usuario.
- No modificar archivos fuera del alcance solicitado.
- No modificar `.env` sin autorización.
- No ejecutar comandos destructivos.
- No hacer commits sin solicitud explícita.
- Mantener el diseño compatible con modo oscuro.
- Conservar el estilo visual existente salvo que se autorice un rediseño.
- Reportar con honestidad qué verificaciones se ejecutaron y cuáles quedaron pendientes.

---

## Entorno local

El proyecto se ejecuta localmente mediante Laravel Herd.

La URL local depende del nombre de la carpeta del proyecto dentro de Herd. Por ejemplo:

- Carpeta `sistema-central-ecore` -> `http://sistema-central-ecore.test`
- Carpeta `sistema-central-ecore-main` -> `http://sistema-central-ecore-main.test`

Codex debe comprobar o preguntar el nombre de la carpeta antes de asumir la URL local.
