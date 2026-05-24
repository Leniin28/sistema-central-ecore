# Sistema Central ECore

## Descripción

Sistema Central ECore es una plataforma web desarrollada en Laravel para gestionar mantenimiento de equipos, socios operativos, órdenes de servicio, estados, marketing y finanzas. El sistema permite registrar clientes, equipos, servicios, órdenes, avances de reparación y movimientos financieros desde una aplicación web con Blade.

## Contexto académico

El proyecto original solicitado era un sistema de órdenes para restaurante. Sistema Central ECore adapta esa lógica a un sistema administrativo real de servicios técnicos, manteniendo los conceptos académicos de órdenes, detalles, estados, roles y cálculos, pero aplicados a un negocio de mantenimiento y reparación de equipos.

## Equivalencias con el sistema de restaurante

- Platillos → Servicios
- Orden de comida → Orden de servicio
- Detalle de orden → Servicios incluidos en una orden
- Cocina → Flujo técnico
- Cocinero → Técnico / socio técnico
- Total de orden → Total cobrado al cliente

## Tecnologías utilizadas

- Laravel 13
- MySQL
- Blade
- Livewire starter kit
- Laravel Fortify
- Eloquent ORM
- Migraciones
- Seeders
- Middleware
- Arquitectura MVC

## Roles del sistema

- Administrador: gestiona la operación completa del sistema, catálogos, órdenes, estados y finanzas.
- Socio logístico: registra clientes, equipos y órdenes recibidas según su partner o sucursal.
- Socio técnico: revisa y actualiza órdenes asignadas dentro del flujo técnico.

## Usuarios de prueba

Administrador:

- email: admin@ecore.test
- password: password

Electrocom Alameda:

- email: electrocom.alameda@ecore.test
- password: password

Electrocom Rodolfo:

- email: electrocom.rodolfo@ecore.test
- password: password

Fixop:

- email: fixop@ecore.test
- password: password

## Módulos implementados

- Autenticación
- Roles y middleware
- Dashboards por rol
- Clientes
- Equipos
- Partners
- Categorías de servicio
- Servicios
- Órdenes de servicio
- Detalles de servicios dentro de órdenes
- Estados e historial
- Finanzas automáticas
- Movimientos financieros manuales
- Dashboard administrativo con métricas reales
- Dashboards de socios con métricas filtradas por partner

## Flujo principal del sistema

1. Cliente entrega equipo.
2. Se registra cliente y equipo.
3. Se crea una orden de servicio.
4. Se agregan servicios a la orden.
5. Se calcula subtotal y total.
6. La orden avanza por estados.
7. Se entrega el equipo.
8. Se generan finanzas automáticamente.

## Estados de una orden

- Recibido
- En diagnóstico
- Cotización pendiente
- Cotización aprobada
- En proceso
- En Fixop
- Listo para entregar
- Entregado
- Cancelado

## Finanzas

El sistema registra la parte financiera de las órdenes y permite consultar ingresos, egresos y balance.

- Ingreso del cliente
- Comisión logística
- Pago técnico
- Utilidad neta
- Movimientos manuales de marketing

Las finanzas se generan automáticamente cuando una orden cambia a estado entregado. Además, las órdenes entregadas con finanzas generadas quedan bloqueadas para evitar inconsistencias o movimientos duplicados.

## Base de datos

Tablas principales:

- users
- partners
- clientes
- equipos
- categorias_servicio
- servicios
- ordenes_servicio
- orden_servicio_detalles
- historial_estados
- movimientos_financieros

## Instalación local

Instalar dependencias:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Configurar MySQL en `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=sistema_central_ecore
DB_USERNAME=root
DB_PASSWORD=tu_password
```

Ejecutar migraciones, seeders y compilar assets:

```bash
php artisan migrate --seed
npm run build
```

Nota para Windows PowerShell: si `npm run build` está bloqueado, usar:

```bash
npm.cmd run build
```

## URL local con Laravel Herd

```txt
http://sistema-central-ecore.test
```

## Checklist académico cumplido

- Laravel
- MySQL
- MVC
- CRUD
- Autenticación
- Roles
- Middleware
- Relaciones entre tablas
- Cálculo automático de totales
- Guardado en múltiples tablas
- Flujo de estados
- Vistas distintas por rol
- Dashboard con métricas
- Finanzas automáticas

## Notas importantes

- El sistema fue pensado para un negocio real.
- No es una API; es una aplicación web con Blade.
- Las finanzas se generan al entregar una orden.
- Las órdenes entregadas con finanzas generadas quedan bloqueadas para cambios de estado.
- Los movimientos financieros manuales permiten registrar marketing, FB Ads, inversión publicitaria y otros ingresos/egresos.
