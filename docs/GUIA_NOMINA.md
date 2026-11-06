# Guía sencilla: módulo de Nómina (Seri ERP)

Documento para quien **no ha usado software de nómina**. Explica qué hace cada pestaña, en qué orden trabajar y qué significa lo contable, sin jerga innecesaria.

---

## 1. Idea general (en una frase)

La nómina calcula **cuánto le corresponde a cada empleado** en un mes, **cuánto cuesta a la empresa** (aportes, prestaciones) y **registra eso en contabilidad**. Luego, aparte, se pagan cosas como la planilla PILA o las cesantías al fondo.

Piensa en tres momentos distintos:

| Momento | Qué es | Dónde en el sistema |
|--------|--------|---------------------|
| **Preparar** | Datos base: empleados, turnos, % legales | Parámetros → Horarios → Empleados |
| **Liquidar** | Calcular el mes y contabilizar | Liquidaciones |
| **Pagar terceros** | EPS, pensión, fondo de cesantías, etc. | Pagos de nómina |

---

## 2. Orden recomendado la primera vez

Siga este orden; no empiece por “Liquidaciones”.

```
1. Parámetros     → 2. Horarios     → 3. Empleados
         ↓
4. Liquidaciones (borrador → revisar → Contabilizar y pagar)
         ↓
5. Pagos de nómina (PILA / prestaciones, cuando corresponda)
```

### Paso 1 — Parámetros
Aquí viven los **números de la ley y de la empresa**: SMMLV, auxilio de transporte, % de salud/pensión, ARL, parafiscales, cesantías, prima, vacaciones, horas extras, etc.

- Si no está seguro, use los valores sugeridos del sistema (botón de sugerencia / valores por defecto Colombia).
- Pase el cursor por el ícono **i** de cada campo: ahí está la explicación corta.
- **No los cambie a diario.** Se revisan al inicio de año o cuando cambie la norma / el contador lo indique.

### Paso 2 — Horarios
Plantillas de turno (ej. “Lun–Vie 8:00–17:00”, “Turno noche”).

- Sirven para **estimar** horas nocturnas y extras.
- Al crear la liquidación puede **precargar** esas horas; después las puede ajustar a mano.

### Paso 3 — Empleados
Ficha de cada persona: documento, salario, si recibe auxilio de transporte, horario, comisión, etc.

- Sin empleados activos **no hay liquidación**.
- El salario y el horario son lo que más impacta el cálculo.

### Paso 4 — Liquidaciones
Aquí se “cierra el mes” de uno o varios empleados.

1. **Nueva liquidación** → elija año/mes, días, empleados y casillas (prima, cesantías, vacaciones, parafiscales…).
2. Queda en **borrador**: aún puede editar HE, incapacidades, etc.
3. Revise totales (bruto, deducciones, neto, costo empresa).
4. Pulse **Contabilizar y pagar** cuando esté correcto (acción definitiva).

### Paso 5 — Pagos de nómina
No es el sueldo del empleado (eso ya salió con “Contabilizar y pagar”).  
Aquí paga lo que la empresa **aún debe a terceros** o prestaciones pendientes:

- Planilla **PILA** (salud, pensión, ARL, caja, SENA, ICBF).
- **Cesantías** al fondo, intereses, vacaciones, y solo si aplica el **neto pendiente** de salarios.

---

## 3. Qué hace cada pestaña

### Empleados
Alta / edición / estado activo. Datos que alimentan el cálculo (salario, auxilio, horario, comisiones).

### Horarios
Turnos reutilizables. Se asignan al empleado. Opcional pero muy útil si hay turnos nocturnos o extras fijas.

### Liquidaciones
El corazón del módulo: una liquidación = un periodo (mes) + un conjunto de empleados.

Estados típicos:

- **Borrador** → se puede editar o cancelar.
- **Pagada / contabilizada** → ya generó asiento; no la “deshaga” a la ligera (hay anulación controlada si aplica).

### Pagos de nómina
Registro de pagos de **pasivos** ya causados.  
**No genera gasto nuevo** (el gasto se registró al liquidar): solo baja la deuda contra banco o caja de nómina.

### Parámetros
Configuración legal/operativa. Es el “motor” de porcentajes y bases.

---

## 4. Palabras que confunden (glosario corto)

| Término | Significado simple |
|--------|---------------------|
| **Bruto / devengado** | Lo ganado antes de descuentos (salario, HE, prima si aplica…). |
| **Deducciones** | Lo que se quita al empleado (salud 4%, pensión 4%, etc.). |
| **Neto** | Lo que realmente se le gira al empleado (bruto − deducciones). |
| **Costo empresa** | Neto + aportes del empleador + parafiscales + provisiones. Es lo que “cuesta” la nómina a la compañía. |
| **Contabilizar** | Crear el asiento contable (gasto + deudas). |
| **Contabilizar y pagar** | Contabiliza **y** registra que el neto ya se pagó al empleado (sale de banco / caja nómina). |
| **Provisión** | Guardar mensualmente un pedazo de cesantías/vacaciones para no pagar todo de golpe después. **No aumenta el neto** del mes. |
| **Prima** | Prestación que **sí se paga al empleado** (suele ser junio y diciembre). |
| **PILA** | Planilla con la que se pagan seguridad social y parafiscales a las entidades. |
| **Salarios por pagar (neto pendiente)** | Cuenta **250505**: neto del empleado **aún no desembolsado**. Si usó “Contabilizar y pagar”, normalmente está en **$0**. |

---

## 5. Flujo mensual típico (organización práctica)

### Cada mes
1. Verifique que empleados y salarios estén al día.
2. Si hubo turnos especiales / HE reales, prepárelas (horario o captura masiva en el detalle).
3. **Nueva liquidación** del mes (todos o los que correspondan).
4. En el detalle: revise HE, incapacidades, totales.
5. **Contabilizar y pagar** (cuando el dinero del neto ya salió o sale ese día).
6. Cuando pague la **planilla PILA**, vaya a **Pagos de nómina** → tarjeta PILA → “Usar saldos” → Registrar pago.
7. Cuando consigne **cesantías** al fondo (o pague intereses/vacaciones), use la tarjeta **Prestaciones y salarios por pagar**.

### Junio y diciembre
- Marque **Incluir prima de servicios** en la nueva liquidación (el sistema suele sugerirlo esos meses).

### Enero (o cuando pague intereses de cesantías)
- Use **Pagos de nómina** → Intereses sobre cesantías (si hay saldo).

### Inicio de año
- Actualice **SMMLV** y **auxilio de transporte** en Parámetros.

---

## 6. Contabilidad: qué pasa “por detrás” (versión simple)

Al **Contabilizar y pagar**:

1. Se registra el **gasto** de nómina (cuentas 5105xx: sueldos, aportes, provisiones…).
2. Se registran las **deudas** (pasivos): EPS, pensión, ARL, caja, cesantías, etc.
3. El **neto** del empleado sale de **banco** o **caja de nómina (110510)** — **no** del cajón del POS de ventas.

Por eso:

- No vuelva a registrar el sueldo como “gasto” en el módulo de Gastos (se duplicaría).
- En **Pagos de nómina** solo está “apagando” las deudas (PILA, fondo, etc.), no creando costo nuevo.

---

## 7. Casillas al crear la liquidación (qué marcar)

| Casilla | ¿Qué hace? | ¿Cuándo marcarla? |
|--------|------------|-------------------|
| **Prima** | Paga prima al empleado (suma al neto) | Junio y diciembre (o cuando corresponda) |
| **Cesantías e intereses** | Provisiona el mes (contabilidad); **no** suma al neto | Si quiere provisionar mes a mes (recomendado con contador) |
| **Vacaciones (provisión)** | Provisiona vacaciones; **no** suma al neto | Igual: si provisiona mensualmente |
| **Parafiscales** | Calcula caja, SENA, ICBF | Casi siempre (salvo indicación del contador / exoneración) |
| **Precargar HE según horario** | Trae extras/nocturnas del turno | Si los horarios reflejan la realidad |

La **ARL** se calcula siempre (no depende de la casilla de parafiscales).

---

## 8. Errores frecuentes

1. **Empezar por liquidar sin parámetros ni empleados** → resultados vacíos o incorrectos.
2. **Registrar el sueldo otra vez en Gastos** → costo duplicado.
3. **Confundir “Pagos de nómina” con pagar el sueldo** → el sueldo va en “Contabilizar y pagar”; PILA/fondo van en Pagos de nómina.
4. **Asustarse porque “Salarios por pagar” está en $0** → es normal si ya pagó el neto al liquidar.
5. **Marcar prima todos los meses** → la prima no es mensual; es semestral (en la práctica junio/diciembre).
6. **Usar el cajón POS para nómina** → el módulo usa caja de nómina / banco a propósito, separado de la caja de ventas.

---

## 9. Mini checklist antes de contabilizar

- [ ] Parámetros del año correctos (SMMLV, auxilio, %).
- [ ] Empleados activos con salario y (si aplica) horario.
- [ ] Periodo y días correctos en la liquidación.
- [ ] HE / incapacidades revisadas en el detalle.
- [ ] Casillas de prima / provisiones según el mes.
- [ ] Medio y fecha de pago del neto acordes a la realidad.
- [ ] Confirmación: “Contabilizar y pagar” (acción que genera asiento).

---

## 10. Resumen visual del ciclo

```
Parámetros + Horarios + Empleados
              │
              ▼
     Nueva liquidación (borrador)
              │
              ▼
   Ajustar HE / incapacidades
              │
              ▼
     Contabilizar y pagar
       ├─► Gasto en contabilidad
       ├─► Neto al empleado (banco / caja nómina)
       └─► Deudas PILA / prestaciones
              │
              ▼
   Pagos de nómina (cuando pague planilla o fondo)
```

---

Si algo no cuadra con lo que indica el contador de la empresa, **prevalece el criterio del contador** sobre esta guía: el sistema aplica reglas generales Colombia y parámetros editables.
