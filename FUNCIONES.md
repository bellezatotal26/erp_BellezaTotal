# Seri ERP — Guía rápida de funciones

Resumen pensado para el usuario final. Explica **qué puede hacer** el programa, sin detalles técnicos.

> Algunas funciones dependen del **plan contratado** y del **permiso** de cada usuario (Administrativo, Usuario o solo Caja/POS).

---

## Idea general

Seri ERP ayuda a manejar el día a día del negocio: vender en caja, controlar inventario, comprar a proveedores, llevar clientes, gastos, reportes y, si aplica, contabilidad, nómina y facturación electrónica.

Todo trabaja conectado: lo que vende la caja descuenta inventario; las compras aumentan stock; las ofertas se reflejan al cobrar; los reportes se alimentan de esas operaciones.

---

## Dashboard

Pantalla de inicio con el pulso del negocio.

- Resumen de productos, clientes y ventas
- Alertas de stock bajo
- Cuentas por cobrar y por pagar
- Últimas ventas
- Puede personalizarse (mostrar u ocultar bloques)

---

## Caja / POS

El punto de venta para atender al cliente.

- Abrir y cerrar caja (con conteo de dinero)
- Buscar productos por nombre o código de barras
- Vender de contado, mixto o a crédito
- Aplicar descuentos, domicilio y precio al por mayor
- Ver ofertas activas y productos sugeridos
- Crear un cliente rápido sin salir de la caja
- Registrar un gasto o un préstamo de producto desde la misma pantalla
- Imprimir o compartir ticket / PDF
- Seguir vendiendo aunque se caiga internet (ventas de contado en cola; al volver la conexión se sincronizan)

**Importante:** si un producto maneja fechas de vencimiento (lotes), la caja vende primero lo que vence antes y no usa mercancía vencida o bloqueada.

---

## Ventas

Historial y gestión de lo ya vendido.

- Consultar ventas por fecha, cliente, forma de pago o estado
- Ver detalle, cancelar con motivo o cambiar el medio de pago
- Registrar abonos a ventas a crédito
- Descargar PDF o ticket e imprimir / compartir
- Exportar listados

---

## Inventario

Catálogo de productos y servicios, y control de existencias.

- Crear, editar y organizar productos (código, código de barras, precios, stock mínimo, categoría, proveedor, unidad de medida, etc.)
- Ingresar mercancía nueva con el botón **Lote N** (ideal cuando llega otra remesa del mismo producto con distinta fecha de vencimiento)
- Controlar lotes y caducidades
- Crear ofertas temporales (por precio o porcentaje, con fecha de inicio y fin)
- Importar o exportar el catálogo en archivo CSV
- Exportar para conteo físico
- Ver movimientos y trazabilidad (qué entró, salió o se ajustó)
- Panel de **vencimientos**: ver lo vencido o por vencer, bloquear, dar de baja o corregir descuadres
- Importar catálogo desde tiendas en línea (si están conectadas)

**Regla práctica:** mismo producto + otro vencimiento = **Lote N** sobre el producto existente, no un producto nuevo.

---

## Préstamos de productos

Salida de mercancía que **no es una venta** (muestra, préstamo, uso interno, etc.).

- Registrar a quién o para qué salió
- Devolver parcial o total
- Ver alertas de préstamos abiertos

---

## Compras

Compras a proveedores y reposición de stock.

- Crear órdenes de compra, recibir mercancía y registrar pagos o abonos
- Adjuntar factura del proveedor
- Cargar una factura y dejar que el asistente la interprete (luego usted confirma antes de afectar el inventario)
- Notas de crédito a proveedor
- Panel de **reposición**: sugiere qué comprar según mínimos/máximos y permite armar pedidos por proveedor (PDF, WhatsApp o correo)

---

## Clientes

Base de clientes del negocio.

- Alta, edición y consulta
- Datos de contacto, documento y origen comercial
- Historial de facturas / ventas
- Exportar listado
- También se pueden crear al momento desde Caja, Ventas o Cotizaciones

---

## Proveedores

Directorio de proveedores.

- Datos de contacto, documento y notas
- Se usan en compras, reposición y gastos

---

## Cotizaciones

Presupuestos para el cliente.

- Armar cotización con productos y precios
- Seguir el estado (pendiente, aceptada, convertida, rechazada)
- Convertir una cotización aceptada en venta
- Descargar PDF y exportar

---

## Gastos

Control de egresos del negocio.

- Registrar gastos con categoría, forma de pago, proveedor y comprobante (foto o PDF)
- Filtrar y exportar
- También se pueden registrar desde la Caja

---

## Reportes

Análisis del negocio por periodo.

- Ventas, ticket promedio, productos y clientes destacados
- Stock y comportamiento del inventario
- En planes avanzados: rentabilidad, márgenes, métodos de pago, cobranza, caja, gráficos y exportaciones / PDF

---

## Contabilidad

Apoyo contable operativo (siempre conviene validar con el contador del negocio).

- Resumen y seguimiento de compras, gastos y movimientos
- Libro diario / mayor, balance y estados financieros básicos
- Plan de cuentas y cierre de periodos
- Comisiones de datáfono / tarjeta
- Conexión con facturación electrónica (según proveedor contratado)
- Conexión con tiendas en línea para traer catálogo

---

## Nómina

Gestión de empleados y liquidaciones (según plan).

- Datos del empleado y salario
- Liquidaciones con desprendible / PDF
- Parámetros laborales típicos de Colombia
- Marcar como pagada y relacionar con contabilidad

---

## Configuración

Ajustes del sistema (normalmente solo el administrador).

- Perfil y contraseña
- Usuarios y permisos (quién ve o edita cada módulo)
- Datos de la empresa, moneda, impuestos y apariencia
- Logo y datos que salen en tickets y PDFs
- Plan / suscripción, alertas de stock y respaldos

---

## Asistente IA y Soporte

- **Asistente IA:** consultas en lenguaje natural sobre ventas, inventario, clientes o caja (y apoyo al leer facturas de compra).
- **Soporte:** tickets para pedir ayuda al equipo técnico.
- **Notificaciones:** avisos de ventas, inventario, tickets u otras novedades.

---

## Roles típicos

| Rol | Enfoque |
|-----|---------|
| **Administrativo** | Acceso completo, incluida la configuración |
| **Usuario** | Acceso a los módulos que se le habiliten |
| **Caja / POS** | Pensado para vender: usa la caja; no ve todo el resto del sistema |

---

## Cómo se conectan las piezas (vista rápida)

1. **Inventario** define productos, precios, lotes y ofertas.  
2. **Caja** vende y descuenta stock (respetando vencimientos y ofertas).  
3. **Compras** reponen mercancía (y pueden crear o alimentar lotes).  
4. **Clientes / Cotizaciones / Crédito** alimentan la cartera.  
5. **Gastos y Nómina** registran salidas de dinero o nómina.  
6. **Reportes y Contabilidad** muestran el resultado de todo lo anterior.

---

*Documento de orientación funcional. El detalle exacto disponible en cada cuenta depende del plan y de los permisos configurados.*
