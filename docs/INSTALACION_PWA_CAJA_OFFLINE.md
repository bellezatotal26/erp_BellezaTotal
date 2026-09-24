# Seri Caja — instalación offline (PWA) y limitantes

Guía para instalar el punto de venta de **Seri ERP** en un computador de escritorio, usarlo sin internet y sincronizar al recuperar la conexión.

**Ámbito:** solo el módulo **Caja / POS**.  
**Cliente de referencia:** Belleza Total (`https://software.bellezatotal.co`).

---

## 1. Qué es esto

Seri Caja es una **aplicación web instalable (PWA)**:

- Se abre como una ventana propia (sin barra del navegador).
- Puede vender **de contado** aunque se caiga internet.
- Las ventas quedan en una **cola local** y se **suben al servidor** al volver online.
- El catálogo de productos se guarda en el PC (IndexedDB) para buscar y vender offline.

No es un programa `.exe` independiente ni una copia completa de toda la base de datos.

---

## 2. Requisitos

| Requisito | Detalle |
|-----------|---------|
| Navegador | **Google Chrome** o **Microsoft Edge** (recomendados). Firefox tiene soporte limitado de “instalar app”. |
| Sistema | Windows 10/11 (o cualquier PC con Chrome/Edge). |
| Conexión | HTTPS (el sitio ya lo usa). |
| Usuario | Sesión iniciada en el ERP con permiso de **Caja**. |
| Primera vez | Abrir **Caja al menos una vez con internet** (descarga catálogo y “shell” offline). |

---

## 3. Instalación paso a paso

### A. Preparar el PC

1. Abrir Chrome o Edge.
2. Ir a: `https://software.bellezatotal.co/app/caja` (o la URL de Caja de su dominio).
3. Iniciar sesión.
4. Esperar a que cargue la Caja (productos / POS visible).

### B. Instalar la aplicación

**Opción 1 — Banner en Caja**  
Si aparece el aviso verde *“Instale Seri Caja en este computador…”*, pulsar **Instalar**.

**Opción 2 — Menú del navegador**  
1. Menú **⋮** (arriba a la derecha).  
2. Buscar **Instalar Seri Caja** / **Instalar aplicación** / **Aplicaciones → Instalar este sitio como aplicación**.  
3. Confirmar.

### C. Abrirla después

- Menú Inicio de Windows → **Seri Caja**, o  
- Icono en el escritorio / barra de tareas (si lo ancló).

La app debería abrir directo en **Caja**.

---

## 4. Cómo funciona el modo offline

### Antes de quedar sin red (recomendado)

1. Abrir Seri Caja **con internet**.
2. Confirmar en la línea de estado algo como:  
   `Catálogo local: N productos` · `En línea`.
3. Así el PC ya tiene productos y precios para vender sin red.

### Mientras no hay internet

- Puede **buscar productos** del catálogo local.
- Puede **vender de contado** (efectivo / medios que no exijan servidor).
- Las ventas se guardan en cola: verá avisos de *“venta(s) pendiente(s) de sincronizar”* y *“Sin conexión”*.
- **No** se puede (bloqueado a propósito):
  - Venta a **crédito**
  - Factura electrónica (si aplica)
  - **Cerrar caja** (hasta sincronizar y tener red)

### Al recuperar internet

1. El sistema intenta sincronizar solo (evento “online”, al volver a la pestaña, o Background Sync).
2. Verá mensaje de ventas sincronizadas, o de **conflictos** (ej. stock insuficiente en servidor).
3. Revisar conflictos con el botón correspondiente: **Reintentar** o revisar stock; **no Descartar** sin confirmar que la venta no debe existir.

---

## 5. Limitantes (importante)

| Sí se puede offline | No se puede / no está diseñado offline |
|---------------------|----------------------------------------|
| Cobrar ventas de contado en Caja | Nómina, consignación (admin), compras, contabilidad, reportes, configuración |
| Buscar productos del catálogo ya descargado | Alta de productos nuevos en inventario |
| Cola de ventas + sync al volver | Garantizar 100 % el mismo stock que el servidor (puede haber conflictos) |
| Instalar en escritorio (Chrome/Edge) | App nativa Windows independiente (tipo instalador MSI) |
| Trabajar un rato sin red | Días enteros sin abrir nunca online (el catálogo se desactualiza) |

### Limitantes de negocio

1. **Stock:** offline usa stock local. Si otro PC vendió lo mismo, al sincronizar puede haber **conflicto**.  
2. **Descartar conflicto:** borra la venta local; no la registra en el servidor.  
3. **Caja cerrada en servidor:** las ventas en cola pueden quedar en *reintento* hasta abrir caja.  
4. **Catálogo viejo:** precios/stock cambian en el servidor; conviene abrir Caja online cada día o turno.  
5. **Un PC ≠ toda la empresa offline:** cada equipo tiene su propia cola local.  
6. **No es backup de la empresa:** la verdad de los datos sigue siendo el servidor.

---

## 6. Buenas prácticas para el cliente

1. Instalar Seri Caja solo en los PCs del punto de venta.  
2. Abrir la app **con internet al inicio del día** (refrescar catálogo).  
3. Si hay corte de luz/internet: vender de contado; al volver, **esperar el sync** antes de cerrar caja.  
4. Nunca pulsar **Descartar** en un conflicto sin revisar con el administrador.  
5. Cuadrar caja el mismo día.  
6. No depender del offline para créditos ni trámites administrativos.

---

## 7. Solución de problemas rápida

| Problema | Qué hacer |
|----------|-----------|
| No aparece “Instalar” | Usar Chrome/Edge; abrir Caja con HTTPS y sesión; probar menú ⋮ |
| Offline no vende / no hay productos | Abrir Caja **online** una vez y esperar el catálogo local |
| Ventas no sincronizan | Verificar internet, caja **abierta** en el servidor, revisar conflictos |
| App abre pero se ve vieja | Cerrar Seri Caja, abrir de nuevo; o borrar datos del sitio en el navegador y reinstalar |
| ¿Desinstalar? | Windows → Configuración → Aplicaciones → Seri Caja → Desinstalar |

---

## 8. Archivos técnicos (referencia para soporte)

| Pieza | Ubicación |
|-------|-----------|
| Manifest PWA | `public/manifest.php` |
| Service Worker | `public/sw.js` (vía `public/sw.php`) |
| Cola / catálogo offline | `public/assets/js/pos-offline.js` |
| UI Caja | `public/assets/js/caja.js`, `app/Views/tenant/caja.php` |
| Sync servidor | `app/Services/OfflineSaleSyncService.php` |

---

*Documento orientado a operación en un solo cliente (standalone). Actualizar si cambia el dominio o el flujo de instalación.*
