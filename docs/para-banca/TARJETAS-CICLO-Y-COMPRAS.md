# Para Banca Virtual: fechas de corte y pago, y autorización de compras

Dos APIs nuevas de CP Gestiones para las tarjetas que emite el banco. Usan el mismo token de integración (`Authorization: Bearer …`) y el DPI del cliente, como el resto de la guía.

## 1. Ciclo de facturación (corte y pago)

`GET /api/v1/banca/tarjetas/ciclos?dpi=3068226670601`

```json
{ "ok": true, "data": [ {
  "tarjeta_id": "6716…", "referencia": "CP-1A2B3C4D5E6F", "ultimos_cuatro": "4321", "estado": "activa",
  "dia_corte": 5, "dia_pago": 25,
  "fecha_corte": "2026-11-05",
  "fecha_limite_pago": "2026-10-25",
  "cargo_automatico": true, "cuenta_cargo_numero": "2123456789",
  "limite_centavos": 500000, "deuda_centavos": 12000, "disponible_centavos": 488000,
  "pago_pendiente": { "periodo": "2026-10", "fecha_corte": "2026-10-05", "fecha_limite_pago": "2026-10-25", "saldo_centavos": 12000, "minimo_centavos": 1000, "pagado_centavos": 0 },
  "ultimo_corte": "2026-10-05"
} ] }
```

- `fecha_corte`: el próximo corte.
- `fecha_limite_pago`: hasta cuándo pagar el estado de cuenta pendiente; si no hay uno pendiente, la fecha de pago del próximo corte.
- También por tarjeta: `GET /api/v1/tarjetas/{tarjeta_id}/ciclo?dpi=…`.
- Y dentro de `GET /api/v1/tarjetas?dpi=…` (la que ya consultan al sincronizar), cada tarjeta de crédito trae el mismo objeto en `ciclo`.

## 2. Autorización de compras

`POST /api/v1/tarjetas/{tarjeta_id}/autorizaciones` (con `Idempotency-Key`)

```json
{ "dpi": "3068226670601", "monto": "600.00", "comercio": "Super La Torre", "concepto": "Despensa", "referencia": "opcional-id-de-su-sistema" }
```

- **Aprobada → 201**: `data.estado = "aprobada"`, `data.codigo = "APROBADA"`, `data.disponible_centavos`, `data.transaccion_id`. Se carga a la tarjeta.
- **Rechazada → 422**, sin cargo:

| `code` | Cuándo |
| --- | --- |
| `LIMITE_EXCEDIDO` | Tarjeta de crédito: la compra supera el crédito disponible. |
| `FONDOS_INSUFICIENTES` | Tarjeta de débito: la cuenta no tiene saldo suficiente. |
| `TARJETA_BLOQUEADA` | La tarjeta está bloqueada. |

- Aprobada o rechazada, **cada intento queda registrado** en Gestiones con monto, fecha, comercio, estado, código, cliente y canal.
  - Historial de una tarjeta: `GET /api/v1/tarjetas/{tarjeta_id}/compras?dpi=…`.
  - El personal del banco ve todas las compras en el panel.
- Reenviar con la misma `Idempotency-Key` devuelve la misma respuesta sin cobrar dos veces.

## Parche para su app

[`tarjetas-ciclo-y-compras.patch`](tarjetas-ciclo-y-compras.patch), sobre su último commit. Antes de aplicarlo, ejecuten `database/08_ciclo_tarjetas.sql`.

- **«Mis tarjetas»:** muestra el próximo corte, la fecha límite de pago, el pago mínimo y el total a pagar. Se guardan al sincronizar con `GET /tarjetas`.
- **Compras:** su endpoint de compra (`POST /tarjetas/:id/consumo-demo`) pasa por la autorización de Gestiones cuando la tarjeta es del banco. El rechazo llega al cliente con el motivo.
