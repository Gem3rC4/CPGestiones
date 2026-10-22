# Para Banca Virtual: eliminar clientes y limpiar los datos

CP Gestiones ya borra de forma física y completa a un cliente: su ficha, su acceso a Banca Virtual, cuentas, movimientos, tarjetas, préstamos y gestiones. Banca Virtual guarda su propia copia del cliente. Si esa copia no se borra, al volver a registrarse con el mismo correo reaparecen sus cuentas y movimientos viejos. Por eso hacen falta dos cambios en su lado.

Parche: [`eliminar-y-limpiar-clientes.patch`](eliminar-y-limpiar-clientes.patch), sobre su último commit. Se aplica junto con los otros dos parches de esta carpeta.

## 1. Endpoint que llama CP Gestiones al eliminar un cliente

`POST /api/v1/admin/clientes/eliminar` con la cabecera `X-Service-Key` (la misma de abonos):

```json
{ "dpi": "3068226670601", "correo": "cliente@correo.com" }
```

- Busca la ficha por DPI o por correo; nunca toca administradores.
- Borra la ficha y todo lo que depende de ella: cuentas y movimientos, transferencias, transferencias programadas, tarjetas y sus movimientos, préstamos y cuotas, pagos de servicios, abonos, solicitudes, beneficiarios, sesiones y notificaciones. Todo en una transacción.
- Si una transferencia tocó la cuenta de otro cliente, se borra el registro de la transferencia; los movimientos y el saldo del otro cliente no cambian.
- Respuesta 200: `{ "ok": true, "eliminado": true }`. Si no existía, responde `eliminado: false` (idempotente).

CP Gestiones lo llama solo, con reintentos, al eliminar un cliente o al reiniciar los datos. Una vez confirmado, Gestiones no conserva el DPI ni el correo.

## 2. Limpieza inicial (una sola vez)

1. El administrador principal reinicia los datos en CP Gestiones: **Integraciones → Mantenimiento → Reiniciar datos**, escribiendo `ELIMINAR TODO`.
2. Ustedes ejecutan en su base de datos `database/09_limpieza_clientes.sql` (incluido en el parche).
   - Borra todos los clientes (rol `CLIENTE`) y sus datos.
   - Conserva la estructura, el catálogo de servicios y los administradores.
   - Al final muestra un conteo, que debe salir todo en 0.

## Prueba (22/10/2026, PostgreSQL local con `database/01…07`)

| Prueba | Resultado |
| --- | --- |
| Eliminar un cliente por DPI | `eliminado: true`. Se fueron su cuenta, su movimiento, su notificación y la transferencia hacia otro cliente. |
| El otro cliente | Conserva su cuenta y su movimiento. |
| Repetir la eliminación | `eliminado: false`, sin error. |
| Sin `X-Service-Key` | 401 |
| `09_limpieza_clientes.sql` | Conteo en 0; solo queda el administrador. |
