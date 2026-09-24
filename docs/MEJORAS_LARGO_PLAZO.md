# Mejoras a largo plazo — Seri ERP (Belleza Total)

Lista de sugerencias para ir mejorando el sistema **sin prisa**, cuando el cliente lo pida o haya hueco entre entregas.  
Ordenadas de más impacto operativo a más “calidad / arquitectura”.

**Contexto:** ERP standalone (un solo cliente), sin panel SaaS. Priorizar Caja, inventario, consignación, nómina y estabilidad.

---

## A. Seguridad y control de acceso

| # | Mejora | Para qué sirve |
|---|--------|----------------|
| A1 | Permisos más estrictos en **IA / Tickets / Media** | Que un rol POS no use asistente IA ni abra archivos de otros módulos sin permiso. *(Aplazado a propósito; retomarlo más adelante.)* |
| A2 | CSRF también por header HTTP | Más robusto si en el futuro hay APIs JSON puras. |
| A3 | No dejar scripts `_diag` / `_migrate` / `_repair` activos en producción | Ya deshabilitados (404). Mantener esa política. |
| A4 | Rotación / política de claves de administrador | Buena práctica si hay varios usuarios. |

---

## B. Dinero, caja y contabilidad

| # | Mejora | Para qué sirve |
|---|--------|----------------|
| B1 | Nómina pagada en **efectivo** → egreso automático en caja | Que el cajón físico cuadre con el pago de sueldos. |
| B2 | No marcar nómina como “pagada” si la contabilidad falló del todo | Evitar estados a medias. |
| B3 | Alertas en Dashboard: “ventas sin movimiento de caja” / “asientos pendientes” | Ver problemas el mismo día. |
| B4 | Reporte simple de descuadres caja vs ventas del día | Arqueo más rápido. |
| B5 | Migraciones de BD solo en mantenimiento (menos DDL al vender) | Menos errores raros de transacciones. |

---

## C. Offline / PWA (Caja)

| # | Mejora | Para qué sirve |
|---|--------|----------------|
| C1 | Indicador claro “última sync del catálogo” + botón “Actualizar catálogo” | El cajero sabe si los precios están frescos. |
| C2 | Límite / aviso si la cola offline es muy grande | Evitar acumular días de ventas sin sync. |
| C3 | Impresión de ticket provisional offline (marca “PENDIENTE SYNC”) | Entregar comprobante al cliente sin red. |
| C4 | Multi-dispositivo: política clara “un POS principal offline” | Menos conflictos de stock entre PCs. |
| C5 | Guía impresa / PDF para el cliente | Ya existe `docs/INSTALACION_PWA_CAJA_OFFLINE.md`. |

---

## D. Consignación

| # | Mejora | Para qué sirve |
|---|--------|----------------|
| D1 | Evitar doble clic al crear consignante (debounce / disable botón) | Menos duplicados. |
| D2 | Informe “por liquidar” exportable (Excel/PDF) por consignante | Facilitar pago al tercero. |
| D3 | NC parcial más guiada en UI | Menos errores al devolver consignado. |
| D4 | Badge muy visible en carrito POS | Ya hay flags API; pulir UX si el cliente lo pide. |

---

## E. Nómina

| # | Mejora | Para qué sirve |
|---|--------|----------------|
| E1 | Parámetros SMMLV / auxilio actualizados por año (recordatorio en UI) | Cumplir cambios legales Colombia. |
| E2 | Historial de liquidaciones + filtro por empleado | Consultas rápidas. |
| E3 | PILA / nómina electrónica | Solo si el cliente lo exige (proyecto grande). |
| E4 | Quincenas formales | Solo si el negocio liquida así. |

---

## F. Inventario y compras

| # | Mejora | Para qué sirve |
|---|--------|----------------|
| F1 | Alertas de stock mínimo / vencimientos más visibles en Dashboard | Reposición a tiempo. |
| F2 | Import CSV con validación previa (filas con error) | Menos productos mal cargados. |
| F3 | Unificar mensajes de “vender sin stock” vs consignación | Menos confusión en caja. |

---

## G. Experiencia de usuario

| # | Mejora | Para qué sirve |
|---|--------|----------------|
| G1 | Manual corto “día a día” (caja → venta → cierre) | Capacitación del personal. |
| G2 | Tour / tooltips solo en Caja la primera vez | Menos dependencia de soporte. |
| G3 | Modo “solo POS” aún más limpio (menos menús) | Cajero no se pierde. |
| G4 | Notificaciones de conflictos offline en el header | No solo dentro de Caja. |

---

## H. Estabilidad y operaciones

| # | Mejora | Para qué sirve |
|---|--------|----------------|
| H1 | Backup automático programado (cPanel / cron) + aviso si falla | Recuperación ante desastre. |
| H2 | `migrate.php` solo por Terminal (nunca por URL pública) | Política ya recomendada. |
| H3 | Log de errores de dinero visible para admin (últimos N) | Diagnosticar sin pedir FTP. |
| H4 | Pruebas de humo mensuales (checklist: caja, consignación, nómina) | Detectar regresiones. |
| H5 | Regenerar dump `bellezat_erp.sql` tras cada cambio grande de schema | Backup “foto” alineada al código. |

---

## I. Arquitectura (cuando el producto madure)

| # | Mejora | Para qué sirve |
|---|--------|----------------|
| I1 | Tabla `schema_migrations` + migraciones numeradas | Saber qué versión de BD tiene el cliente. |
| I2 | Quitar `ensureSchema` del camino caliente de ventas | DDL nunca en medio de una venta. |
| I3 | Cola de jobs para contabilidad post-venta | Respuestas más rápidas en caja. |
| I4 | Separar “app POS” de “backoffice” (opcional) | Menos peso en el PC de caja. |

---

## Cómo usar esta lista

1. Con el cliente: priorizar **B**, **C** y **D** según dolor real (caja, offline, consignación).  
2. Interno: no mezclar 5 mejoras grandes en un solo ZIP; entregar de a 1–2.  
3. Antes de tocar **E3/E4/I***: confirmar que el negocio lo necesita (costo alto).  
4. Retomar **A1** (permisos IA/tickets/media) en una entrega de seguridad ligera.

---

*Documento vivo: ir tachando o moviendo ítems cuando se implementen.*
