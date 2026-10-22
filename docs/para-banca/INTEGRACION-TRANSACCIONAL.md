# Integración transaccional CP Gestiones ↔ CP Banca Virtual

**Objetivo:** que el dinero que el banco envía desde la cuenta concentradora (desembolsos de préstamos, fondeos y dispersión de planillas) llegue a la cuenta del cliente una sola vez, sin pérdidas, y que la Banca Virtual lo muestre en tiempo real.

## 1. Decisión de arquitectura

| Tema | Decisión |
| --- | --- |
| Dónde viven los saldos | **Un solo libro contable: CP Gestiones** (MongoDB Atlas). Es el núcleo bancario: cuenta concentradora, partida doble, préstamos, planillas. |
| Papel de Banca Virtual | **Canal del cliente.** Muestra saldos y movimientos leyéndolos de Gestiones y pide operaciones por API. No guarda saldos propios. |
| ¿Base de datos compartida? | **No.** Nadie se conecta a la base del otro: todo pasa por API con token. La base de Gestiones sigue siendo la única fuente de verdad. |
| Estilo de integración | **REST + webhooks** (no GraphQL: ya existe la API REST documentada y los dos backends la usan). |
| Comandos (pedir que algo pase) | **Banca → Gestiones**, REST síncrono, con token de servicio e `Idempotency-Key`. |
| Eventos (avisar que algo pasó) | **Gestiones → Banca**, webhook firmado. **Gestiones es el emisor (publisher); Banca, el receptor (subscriber).** |

**¿Por qué no mantener dos saldos?** Hoy Banca guarda cuentas y saldos en su PostgreSQL y Gestiones en MongoDB. Un desembolso o una planilla se registran en Gestiones y Banca no se entera. Sincronizar dos libros contables sin una transacción distribuida termina en diferencias: un evento que llega dos veces, o uno que no llega. Con un solo libro ese problema desaparece: Banca siempre muestra el saldo real.

## 2. Diagnóstico actual (21/10/2026)

| Componente | CP Gestiones | CP Banca Virtual |
| --- | --- | --- |
| Cuenta concentradora y partida doble | ✅ | — |
| Desembolso de préstamo al aprobar (atómico) | ✅ | Tiene su propio módulo de préstamos local |
| Planilla pagada en una sola transacción (todo o nada) | ✅ | — |
| Cuentas y saldos | ✅ Fuente oficial | ⚠️ Tabla `cuentas` propia (11 lugares del código cambian `saldo`) |
| Webhooks firmados con reintentos (patrón outbox) | ✅ Emisor listo | ❌ No hay receptor |
| Login y registro con Gestiones | ✅ | ✅ (arreglado en `b894a33`) |

## 3. Flujos

### 3.1 Desembolso de una gestión aprobada (préstamo)

1. El cliente envía la gestión desde Banca: `POST /banca/solicitudes`.
2. El banco la aprueba en Gestiones, con monto, cuotas, tasa, día de pago y cuenta destino.
3. Gestiones hace **en una sola transacción**: concentradora − monto, cuenta del cliente + monto, plan de cuotas, bitácora firmada y los eventos en la cola de salida (outbox).
4. Gestiones envía el webhook `cuenta.movimiento` (con el **saldo nuevo**) y `solicitud.actualizada` (estado `emitida`, `credito_id`).
5. Banca valida la firma, registra el `event_id`, avisa al cliente (push) y refresca la pantalla con `GET /banca/resumen` y `GET /creditos`.

### 3.2 Dispersión de planilla

1. El representante de la empresa (rol Empresa) carga y autoriza la planilla en Gestiones.
2. Gestiones valida los fondos y mueve, en **una sola transacción**, de la cuenta de la empresa a la cuenta de cada colaborador. O se pagan todos o ninguno.
3. Sale un `cuenta.movimiento` por cada colaborador. Banca notifica a cada uno.

### 3.3 Fondeo desde la cuenta concentradora

El administrador (o el rol Mixto/Corporativo para empresas) fondea desde el panel. El dinero sale de la concentradora y Banca recibe el `cuenta.movimiento`.

### 3.4 Operaciones que inicia el cliente en Banca

Transferir, pagar una cuota o pagar un servicio: Banca llama a la API de Gestiones con `Idempotency-Key`. Gestiones mueve el dinero, responde con el resultado y también emite el `cuenta.movimiento`.

## 4. Contratos

Base: `https://cp-gestiones-api.onrender.com/api/v1`. Todas las llamadas llevan `Authorization: Bearer <token de servicio de Banca>`.

### 4.1 APIs REST que Banca debe usar (ya existen)

| Para | Método y ruta | Notas |
| --- | --- | --- |
| Saldos y cuentas del cliente | `GET /banca/resumen` | Con `X-Cliente-Token` o `?dpi=`. Sin caché. |
| Movimientos de una cuenta | `GET /cuentas/{id}/movimientos?page=1` | 50 por página, del más reciente al más antiguo. |
| Transferir | `POST /transferencias` | `Idempotency-Key` obligatorio. |
| Préstamos y cuotas | `GET /creditos`, `GET /creditos/{id}` | Reemplaza el módulo local de préstamos. |
| Pagar cuota | `POST /creditos/{id}/pagos` | `Idempotency-Key` obligatorio. |
| Pagar servicio | `POST /banca/pagos-servicios` | `Idempotency-Key` obligatorio. |
| Abrir cuenta | `POST /banca/cuentas` | `Idempotency-Key` obligatorio. |

### 4.2 Webhook (Gestiones → Banca)

Banca publica una ruta HTTPS, por ejemplo `POST https://bancavirtual-kp9z.onrender.com/api/v1/webhooks/cp-gestiones`. Esa URL y un secreto compartido se configuran en Gestiones → **Integraciones → Conexiones → CP Banca Virtual**.

Encabezados que envía Gestiones:

```http
Content-Type: application/json
X-CP-Timestamp: 1792605600
X-CP-Signature: <hex de HMAC-SHA256(secreto, "<timestamp>.<cuerpo exacto>")>
Idempotency-Key: <event_id>
```

Cuerpo de `cuenta.movimiento`:

```json
{
  "event_id": "3f1c2a7e-9a40-4d61-8a51-0b7c1f9e2d11",
  "type": "cuenta.movimiento",
  "data": {
    "cliente_id": "6ad920b56e039468bb0fca25",
    "cuenta_id": "6ad920b56e039468bb0fca27",
    "numero": "7449916469",
    "transaccion_id": "b1d1c9e4-…",
    "tipo": "desembolso_prestamo",
    "concepto": "Desembolso de préstamo CRE-62A672F7F828",
    "importe_centavos": 2400000,
    "saldo_centavos": 2400590,
    "saldo": "24005.90"
  }
}
```

`tipo` puede ser `deposito`, `retiro`, `transferencia`, `planilla`, `desembolso_prestamo`, `pago_prestamo`, `pago_tarjeta`, `pago_servicio`, `interes_ahorro`, entre otros. Otros eventos: `solicitud.actualizada` (cambio de estado de una gestión) y `conexion.prueba` (botón *Probar* del panel).

**Respuesta esperada de Banca:** `2xx` en menos de 15 segundos, después de guardar el `event_id`. Cualquier otra respuesta hace que Gestiones reintente.

## 5. Seguridad y garantías

| Riesgo | Cómo se evita | Responsable |
| --- | --- | --- |
| Pago duplicado por reintento de red | `Idempotency-Key` en toda escritura: misma clave y mismo cuerpo devuelven el mismo resultado; misma clave con otro cuerpo da `409 IDEMPOTENCY_MISMATCH`. Banca genera la clave **antes** de llamar y la reutiliza al reintentar. | Ambos |
| Evento procesado dos veces | Gestiones entrega **al menos una vez**. Banca guarda cada `event_id` en una tabla con restricción `UNIQUE` y descarta los repetidos. | Banca |
| Evento perdido | Patrón *outbox*: el evento se guarda en la **misma transacción** que el movimiento. Si Banca no responde, se reintenta hasta 8 veces con espera creciente (hasta 1 hora); los fallidos quedan visibles en el panel. | Gestiones |
| Eventos fuera de orden | El evento trae el **saldo final** (`saldo_centavos`), no solo el importe. Banca muestra ese saldo o vuelve a consultar `GET /banca/resumen`; **nunca suma importes por su cuenta**. | Banca |
| Webhook falso | Firma HMAC-SHA256 con secreto compartido, comparación de tiempo constante, rechazo de firmas con más de 5 minutos y verificación sobre el cuerpo **sin modificar**. | Banca |
| Robo o mal uso del token | Token de servicio de 64 caracteres solo en el servidor (nunca en el navegador ni en GitHub). Se emite y revoca desde el panel, con permisos limitados al rol de Banca. Siempre HTTPS: Gestiones se niega a enviar webhooks a URLs sin HTTPS. | Ambos |
| Cliente operando cuentas ajenas | Gestiones verifica que la cuenta pertenezca al cliente de la sesión (`X-Cliente-Token` o DPI). Si no, responde `403`. | Gestiones |
| Saldo negativo o carreras | Débito condicional atómico en MongoDB, transacciones multi-documento y la concentradora nunca negativa. Probado con 6 procesos simultáneos. | Gestiones |
| Diferencias no detectadas | Conciliación diaria: Banca compara lo que muestra contra `GET /banca/resumen`. Gestiones mantiene la suma de todos los saldos en cero. | Ambos |

## 6. Checklist de trabajo

### CP Gestiones (Gerber)

- [x] Cuenta concentradora con partida doble para fondeos, préstamos, tarjetas e intereses.
- [x] Desembolso de préstamo y pago de planilla atómicos.
- [x] Webhook firmado con outbox, reintentos y el evento `cuenta.movimiento` con saldo final.
- [x] `Idempotency-Key` y token de servicio con permisos por sistema.
- [ ] Configurar en **Integraciones → Conexiones** la URL del webhook de Banca y el secreto compartido; probarlo con el botón *Probar*.
- [ ] Agregar `secuencia` (contador por cuenta) y `correo_electronico` al evento, para ordenar y para ubicar al cliente sin depender del id interno.
- [ ] Endpoint de conciliación incremental: `GET /banca/movimientos?desde=<fecha ISO>`.
- [ ] Botón en el panel para reintentar eventos fallidos (hoy solo por consola).

### CP Banca Virtual (Fernando)

- [ ] **Receptor del webhook:** ruta HTTPS que valida la firma sobre el cuerpo exacto, guarda el `event_id` (tabla `eventos_cp` con `UNIQUE`) y responde `2xx`. Hay un ejemplo listo: [`docs/ejemplos/webhook-banca.receptor.mjs`](../ejemplos/webhook-banca.receptor.mjs).
- [ ] Guardar `cp_cliente_id` en `clientes` (lo devuelve el login y el registro en `cliente.id`) para ubicar a quién pertenece cada evento.
- [ ] **Leer saldos y movimientos de Gestiones** (`GET /banca/resumen`, `GET /cuentas/{id}/movimientos`) y dejar de modificar `cuentas.saldo` localmente.
- [ ] Transferencias, pago de servicios y préstamos llamando a las APIs de Gestiones, con `Idempotency-Key` guardada antes de cada llamada.
- [ ] Variables de entorno: `CP_GESTIONES_TOKEN` y `CP_WEBHOOK_SECRET`, solo en el servidor.
- [ ] Notificación push al recibir `cuenta.movimiento` (por ejemplo: "Recibiste Q 4,500.00 · Planilla Quincena").
- [ ] Conciliación diaria contra `GET /banca/resumen`.

**Plan B, si no da tiempo de dejar de usar la tabla `cuentas`:** usarla solo como copia de lectura. Al recibir un evento, actualizar el saldo con `saldo_centavos` (no sumar importes) solo si la `secuencia` es mayor que la última guardada. Ante cualquier duda, volver a consultar a Gestiones. Nunca permitir que Banca mueva dinero sin pasar por Gestiones.

## 7. Pruebas de aceptación

1. Aprobar un préstamo en Gestiones → la app muestra el nuevo saldo y la notificación en menos de 1 minuto.
2. Pagar una planilla de 5 colaboradores → los 5 ven el depósito; la empresa baja exactamente el total.
3. Enviar el mismo webhook dos veces → Banca lo aplica una sola vez.
4. Apagar el receptor de Banca, desembolsar y volver a encenderlo → el evento llega en un reintento, sin duplicarse.
5. Repetir una transferencia con la misma `Idempotency-Key` → un solo movimiento; con otro monto → `409`.
6. Webhook con firma alterada o de más de 5 minutos → `401` y nada se aplica.
7. La conciliación diaria no encuentra diferencias.

## 8. Orden sugerido

1. Webhook: receptor en Banca, configuración en el panel y prueba de conexión.
2. Saldos y movimientos leídos de Gestiones.
3. Operaciones del cliente por API (transferencias, cuotas, servicios).
4. Notificaciones push y conciliación diaria.
