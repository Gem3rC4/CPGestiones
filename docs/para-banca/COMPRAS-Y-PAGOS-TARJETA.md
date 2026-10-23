# Para Banca Virtual: bug del límite de crédito (compras que superan el límite)

## Qué pasaba

Tarjeta con límite de Q10,000. En «Mis tarjetas» se hicieron compras por Q19,000 (Agencias Way 5,000, Fernando 10,000, Campero 1,000, TacoBell 3,000) y todas se aprobaron. En CP Gestiones la tarjeta seguía con deuda Q0.00 y sin compras.

**Causa (en `tarjetas.service.ts` de Banca Virtual):**

1. «Simular compra» (`consumoDemo`) valida contra el disponible **local** y suma la compra solo en su base. Nunca le pregunta a CP Gestiones.
2. Al sincronizar con `GET /tarjetas` de CP (`sincronizarConCp`), `saldo_utilizado` se reemplaza por la deuda que tiene CP, que era Q0.00. El disponible vuelve a Q10,000 y la siguiente compra se aprueba otra vez, sin fin.
3. Lo mismo con «Pagar tarjeta»: el pago solo se aplicaba localmente y nunca liberaba crédito en CP.

## Sobre su commit `4460dfe`

Su cambio suma las compras locales a la deuda que informa CP. Eso frena el exceso en Banca Virtual, pero CP Gestiones nunca se entera de las compras: no registra la deuda, los estados de cuenta salen en Q0.00 y no queda historial central.

El parche lo reemplaza: las compras pasan por CP y la deuda de CP ya las incluye. Por eso el parche también quita esa suma (si se dejaran las dos cosas, cada compra se contaría dos veces). Aplica sobre `4460dfe`.

## La corrección

Parche: [`compras-y-pagos-tarjeta.patch`](compras-y-pagos-tarjeta.patch). Toca solo `backend/banca-virtual-api/src/tarjetas/tarjetas.service.ts` y aplica sobre su último commit (`4460dfe`). No toca su pantalla de fechas de corte y pago.

- **Compras:** si la tarjeta es del banco (`cp_tarjeta_id`), la compra la autoriza CP Gestiones con `POST /tarjetas/{id}/autorizaciones`.
  - CP valida el límite en tiempo real y de forma atómica: dos compras simultáneas no pueden pasarse del límite.
  - CP registra cada intento, aprobado o rechazado.
  - Si CP la rechaza, el cliente ve el motivo («La compra supera el crédito disponible de la tarjeta.», código `LIMITE_EXCEDIDO`) y en Banca no se registra nada.
- **Pagos:** se aplican primero en CP con `POST /tarjetas/{id}/pagos` y `{ dpi, monto, origen_numero }` (el número de cuenta es el mismo en ambos sistemas). Eso reduce la deuda y libera crédito; si CP lo rechaza, en Banca no se mueve nada.
- **Saldo usado y disponible:** siguen viniendo de CP en cada sincronización, que ahora sí es correcta porque las compras y los pagos pasan por CP.
- **Tarjetas solo locales** (sin `cp_tarjeta_id`): siguen igual que antes.

## Prueba de punta a punta (22/10/2026)

Entorno: CP Gestiones local + su backend `1310854` con el parche + PostgreSQL. Tarjeta de Q10,000 y cuenta con Q2,000.

| Paso | Resultado |
| --- | --- |
| Compra Q5,000 (Agencias Way) | Aprobada |
| Compra Q10,000 (Fernando) | **Rechazada**: «La compra supera el crédito disponible de la tarjeta.» |
| Compras Q1,000 (Campero) y Q3,000 (TacoBell) | Aprobadas |
| Compra Q1,000.01 | **Rechazada** |
| Compra Q1,000 | Aprobada: disponible Q0.00 |
| Compra Q1 | **Rechazada** (sin crédito, bloqueo hasta pagar) |
| «Mis tarjetas» | Disponible Q0.00, saldo a pagar Q10,000.00 |
| Deuda en CP Gestiones | Exactamente Q10,000.00 |
| Pago de Q500 desde Banca | Llega a CP: deuda Q9,500.00 |
| Compra Q501 / Q500 / Q1 | Rechazada / Aprobada / Rechazada |

En CP Gestiones quedaron registradas las 10 compras (5 aprobadas y 5 rechazadas), con monto, comercio, fecha, estado y cliente.

Las compras de la prueba anterior que se hicieron solo en Banca (los Q19,000) existen únicamente en su tabla `tarjeta_movimientos`. Conviene borrarlas o hacer la limpieza (`09_limpieza_clientes.sql`) antes de seguir probando.
