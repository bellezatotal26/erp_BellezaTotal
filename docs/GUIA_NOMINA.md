# Guía de uso: módulo de Nómina (Seri ERP)

Documento para operar el módulo **sin experiencia previa en software de nómina**.  
Incluye lo entregado hasta **Fase 2** (liquidación mejorada + PILA operativa).  
La **nómina electrónica DIAN** y el **portal del empleado** aún no están; se evalúan con el cliente.

---

## 1. Idea general

La nómina hace tres cosas distintas:

1. **Calcula** cuánto gana cada empleado (devengado), qué se le descuenta y cuánto cuesta a la empresa.  
2. **Contabiliza** ese costo (gastos + deudas) y marca el **neto** como pagado al empleado.  
3. **Ayuda a pagar terceros** (PILA, fondo de cesantías) y a **armar la planilla** para el operador.

| Momento | Qué es | Dónde |
|--------|--------|--------|
| Preparar | Parámetros legales, turnos, fichas de empleados (incl. afiliaciones) | Parámetros → Horarios → Empleados |
| Liquidar | Calcular el mes, novedades, HE, revisar y contabilizar | Liquidaciones |
| PILA | Validar y exportar datos para el operador | Detalle → **PILA / seguridad social** |
| Pagar terceros | Bajar pasivos ya causados (planilla, fondo, etc.) | Pagos de nómina |

---

## 2. Orden la primera vez

No empiece por liquidar.

```
1. Configuración (NIT empresa)
2. Parámetros de nómina
3. Horarios (opcional pero útil)
4. Empleados (salario + afiliaciones PILA)
5. Liquidaciones (borrador → revisar → Contabilizar y pagar)
6. PILA / seguridad social (export CSV/TXT)
7. Pagos de nómina (cuando pague la planilla o el fondo)
```

### 2.1 Configuración de la empresa
En **Configuración** del ERP complete al menos:

- Razón social  
- **NIT** (`company_nit`)

Sin NIT, el export PILA avisa el faltante.

### 2.2 Parámetros
Pestaña **Parámetros**. Valores de ley / empresa: SMMLV, auxilio, aportes, parafiscales, provisiones, HE, FSP, tope IBC, exoneración 114-1, etc.

- Use el ícono **i** en cada campo.  
- Botón **Sugerir valores legales 2026** para rellenar bases y recargos (revise y guarde).  
- No los cambie a diario: inicio de año o cuando el contador lo indique.

Bloques importantes recientes:

| Bloque | Para qué |
|--------|----------|
| Exoneración 114-1 automática | Si el salario &lt; 10 SMMLV → salud empleador, SENA e ICBF en 0 % (la caja sí se calcula) |
| FSP | % (típicamente 1 %) cuando el IBC &gt; 4 SMMLV |
| Tope IBC | Máximo en SMMLV (legal: 25) |
| ARL (por defecto) | % global si el empleado no tiene tasa propia |

### 2.3 Horarios
Plantillas de turno (ej. L–V 8:00–17:00). Sirven para **precargar** RNO / HE al crear la liquidación; luego se ajustan a mano.

### 2.4 Empleados
Datos laborales **y** afiliaciones para PILA:

**Datos básicos:** documento, nombre, cargo, salario, contrato, horario, medio de pago, auxilio de transporte, estado.

**Riesgo / exoneración:**

- **ARL %** — tasa de la clase de riesgo del empleado (vacío = usa el % global de Parámetros).  
- **Forzar exoneración 114-1** — opcional; si Parámetros tiene automática, ya aplica a salarios &lt; 10 SMMLV.

**Afiliaciones PILA (obligatorias para exportar bien):**

| Campo | Ejemplo |
|-------|---------|
| EPS | EPS010 · Sura |
| AFP / Pensión | 230301 · Porvenir · o 25-14 Colpensiones |
| ARL (administradora) | 14-11 · Sura *(código, no el %)* |
| Caja | CCF24 · Compensar |
| Tipo cotizante | 1 · Dependiente (12/19 aprendices) |
| Subtipo | Normalmente `00` |

Las comisiones fijas quincenales se editan en **Parámetros** (sección comisiones), no en el modal del empleado.

---

## 3. Liquidaciones (cómo se usa el mes)

### 3.1 Crear
**Liquidaciones → Nueva liquidación**

1. Elija empleados (uno o varios).  
2. Año / mes / días / fecha y medio de pago del neto.  
3. Casillas:

| Casilla | Efecto | Cuándo |
|--------|--------|--------|
| Prima | Suma al neto | Junio / diciembre (o cuando corresponda) |
| Cesantías e intereses | Provisión contable; **no** suma al neto | Si provisiona mes a mes |
| Provisión vacaciones | Provisión contable; **no** suma al neto | Igual |
| Parafiscales | Caja, SENA, ICBF | Casi siempre |
| Precargar RNO / HE | Trae horas del horario | Si el turno refleja la realidad |

La **ARL** se calcula siempre.

4. **Calcular** → queda en **borrador**.

### 3.2 Revisar el borrador
En el detalle verá:

- Totales: devengado − deducciones = **neto**  
- Segmentos: aportes empresa, provisiones, **costo total empresa**  
- Tabla por empleado  

Botones útiles en borrador:

| Botón | Qué hace |
|-------|----------|
| **Inc.** | Días y tipo de incapacidad (enfermedad / laboral) |
| **Nov.** | Novedades del periodo (ver §4) |
| Captura masiva HE | HED, HEN, RNO, Dom., HEDD, HEND |
| **CSV costo empresa** | Excel/CSV para contador/auditoría |
| **PILA / seguridad social** | Validar y exportar planilla (§5) |
| **PDF liquidación** / **PDF** por empleado | Comprobantes internos |
| **Contabilizar y pagar** | Cierra: asiento + neto pagado |
| **Cancelar borrador** | Descarta sin asiento |

### 3.3 Estados

| Estado | Significado |
|--------|-------------|
| Borrador | Editable (Inc., Nov., HE) |
| Pagada / Contabilizada | Ya hay asiento; anulación solo con motivo |
| Cancelada | No se usa; puede crear otra del mismo periodo |

---

## 4. Novedades y reglas de cálculo (Fase 1)

En el detalle, botón **Nov.** por empleado (solo borrador):

| Campo | Efecto |
|-------|--------|
| **Días no remunerados** | Bajan salario y auxilio (suspensión / licencia no remunerada) |
| **Días de vacaciones disfrutadas** | Se pagan al 100 % del día y entran al IBC |
| **Otras deducciones** | Préstamos, libranzas, embargos → bajan el neto |

Otras reglas automáticas:

- **FSP:** si IBC &gt; 4 SMMLV, se descuenta el % de Parámetros (suele ser 1 %).  
- **Tope IBC:** no cotiza por encima de N × SMMLV (por defecto 25).  
- **Exoneración 114-1:** automática y/o forzada en el empleado.  
- **Incapacidad:** días 1–2 al 100 %; desde el día 3 al % de Parámetros (laboral: 100 %).  
- **Auxilio de transporte:** solo si casilla activa y salario ≤ 2 SMMLV; proporcional a días **laborados**.  
- **Comisión fija:** no es base de aportes (fuera del IBC).

---

## 5. PILA operativa (Fase 2)

Objetivo: sacar del ERP un archivo con lo necesario para liquidar la planilla **tipo E** en el operador (SOI, miplanilla, etc.), y después registrar el pago en Seri.

### 5.1 Antes de exportar
1. Empleados con EPS / AFP / ARL / Caja.  
2. NIT de la empresa en Configuración.  
3. Liquidación del periodo ya calculada (idealmente revisada).

### 5.2 Pantalla PILA
Desde el detalle: **PILA / seguridad social**.

Verá:

- Si está **Lista para exportar** o **Revisar datos**  
- Errores/avisos (falta EPS, IBC en cero, días fuera de 1–30, sin NIT…)  
- Totales de aportes empleado y empresa  
- Tabla por cotizante (días, IBC, códigos, total línea, 114-1)

### 5.3 Descargas

| Archivo | Uso |
|---------|-----|
| **CSV** | Revisar en Excel o cargar/asistirse en el operador |
| **TXT** | Separado por `\|`, útil para revisión o integraciones simples |

Incluye periodo, NIT, documento, tipo cotizante, códigos, días cotizados, IBC, aportes y observaciones de validación.

### 5.4 Qué NO es (importante)
- **No** es el archivo fijo completo del Anexo Técnico 2 de MinSalud (eso lo arma el operador al liquidar).  
- **No** transmite a la DIAN (nómina electrónica = fase futura).  
- **No** paga sola: después de pagar en el banco/operador, registre el egreso en **Pagos de nómina**.

### 5.5 Después de pagar la planilla
**Pagos de nómina → Seguridad social y parafiscales (PILA)**

1. **Usar saldos** (o digite valores).  
2. Fecha, medio, referencia (Nº planilla).  
3. **Registrar pago** → baja el pasivo (no crea gasto nuevo; el costo ya se causó al liquidar).

Para cesantías al fondo, intereses o vacaciones pagadas: tarjeta **Prestaciones y salarios por pagar**.

**Salarios por pagar (neto pendiente)** = cuenta 250505. Si usó *Contabilizar y pagar*, suele estar en **$0**.

---

## 6. Contabilidad (versión simple)

Al **Contabilizar y pagar**:

1. Gasto de nómina (5105xx: sueldos, aportes, provisiones…).  
2. Pasivos (EPS, pensión/FSP, ARL, caja, SENA, ICBF, cesantías…).  
3. Neto al empleado desde **banco** o **caja de nómina 110510** — **nunca** el cajón POS.

Por eso:

- No vuelva a registrar el sueldo en **Gastos** (duplicaría el costo).  
- **Pagos de nómina** solo apaga deudas ya causadas.  
- Otras deducciones quedan como retención por cruzar (pasivo).

---

## 7. Flujo mensual resumido

```
Empleados al día (salario + afiliaciones)
        │
        ▼
Nueva liquidación → borrador
        │
        ├─ Inc. / Nov. / HE masivas
        ├─ CSV costo empresa (opcional)
        └─ PILA / seguridad social → CSV/TXT → operador
        │
        ▼
Contabilizar y pagar  →  neto al empleado + asiento
        │
        ▼
Pagos de nómina       →  cuando pague PILA o fondo
```

### Junio / diciembre
Marque **Incluir prima**.

### Enero
Intereses de cesantías en Pagos de nómina (si hay saldo). Actualice SMMLV y auxilio en Parámetros a inicio de año.

---

## 8. Glosario corto

| Término | Significado |
|--------|-------------|
| Devengado / bruto | Lo ganado antes de descuentos |
| Deducciones | Salud, pensión, FSP, otras |
| Neto | Lo que se gira al empleado |
| IBC | Base de cotización (salario + vacaciones pagadas + HE, con tope) |
| Costo empresa | Devengado + aportes patronales/parafiscales + provisiones |
| Provisión | Reserva (cesantías/vacaciones); no aumenta el neto del mes |
| Prima | Prestación que sí se paga al empleado (semestral) |
| PILA | Planilla de seguridad social y parafiscales |
| Contabilizar y pagar | Asiento + neto marcado como pagado |

---

## 9. Checklist antes de contabilizar

- [ ] Parámetros del año (SMMLV, auxilio, %, FSP, 114-1)  
- [ ] Empleados activos con salario  
- [ ] Afiliaciones PILA si va a exportar planilla  
- [ ] NIT en Configuración  
- [ ] Periodo y días correctos  
- [ ] Inc. / Nov. / HE revisadas  
- [ ] Casillas de prima / provisiones según el mes  
- [ ] Fecha y medio de pago del neto acordes a la realidad  
- [ ] (Opcional) CSV costo + validación PILA sin errores críticos  

---

## 10. Errores frecuentes

1. Liquidar sin parámetros / empleados / afiliaciones.  
2. Registrar el sueldo otra vez en Gastos.  
3. Confundir **Pagos de nómina** con pagar el sueldo (el sueldo va en Contabilizar y pagar).  
4. Asustarse si “Salarios por pagar” está en $0 (normal tras Contabilizar y pagar).  
5. Marcar prima todos los meses.  
6. Usar el cajón POS para nómina.  
7. Exportar PILA sin EPS/AFP/ARL y esperar que el operador la acepte.  
8. Creer que el CSV/TXT de Seri ya es “nómina electrónica DIAN”.

---

## 11. Alcance actual vs pendiente

| Capacidad | Estado |
|-----------|--------|
| Cálculo automático (devengados, deducciones, prestaciones) | Sí (Fase 1) |
| Novedades, FSP, 114-1, ARL por empleado, CSV costo | Sí (Fase 1) |
| Afiliaciones + validación + export PILA operativo | Sí (Fase 2) |
| Pago de pasivos PILA / prestaciones en el ERP | Sí |
| Documento soporte nómina electrónica DIAN (XML/CUNE) | Pendiente (consulta cliente) |
| Portal de autogestión del empleado | Pendiente |
| Reportes legales ampliados (cert. 220, etc.) | Parcial / pendiente |

---

Si algo no cuadra con lo que indique el **contador** de la empresa, prevalece su criterio: el sistema aplica reglas generales Colombia y parámetros editables.
