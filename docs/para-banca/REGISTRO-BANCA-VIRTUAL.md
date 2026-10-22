# Para Banca Virtual: registro y cuentas con el mismo número

El cliente y sus cuentas se crean en **CP Gestiones**. El registro de Banca Virtual pide solo los campos de su formulario (nombre, apellido, correo, contraseña, DPI y fecha de nacimiento): **no pide número de cuenta**. El DPI es el enlace entre los dos sistemas.

## Flujo

1. **Gestiones · Clientes:** el banco registra la ficha (nombre, apellido, correo, DPI único, NIT, teléfono, fecha de nacimiento, ingreso mensual, meses laborando).
2. **Gestiones · Cuentas → «Crear cuenta»:** titular, tipo y monto de apertura (mínimo Q100.00). Gestiones genera el número (10 dígitos, no cambia) y entrega el comprobante en PDF.
3. **Gestiones → Banca (automático):** `POST /admin/cuentas` con el **mismo número** y `POST /admin/abonos` con el depósito de apertura (`origen: CP-GESTIONES-APERTURAS`, `referencia: CP-<transacción>`). Usa la llave de servicio configurada en Integraciones.
4. **Banca · Registrarse:** Banca llama `POST /banca/registro` de Gestiones con los campos del formulario.
   - Si el DPI ya es cliente del banco: correo, fecha de nacimiento y nombre deben coincidir con la ficha. Se activa su acceso y Gestiones vuelve a enviar sus cuentas a Banca con el mismo número (Banca no las duplica).
   - Si el DPI todavía no es cliente: se crea solo su acceso, sin cuentas. Las cuentas se abren después en Gestiones y llegan solas a Banca.
5. **Banca · Inicio / Mis cuentas:** las cuentas aparecen con el mismo número, tipo y saldo.

Esto ya funciona con el código actual de Banca Virtual, sin cambios.

## Parche opcional (formulario y respaldo)

[`registro-banca-virtual.patch`](registro-banca-virtual.patch), sobre `5a10e62`. Aplicar con `git apply` desde la raíz de su repositorio.

| Archivo | Cambio |
| --- | --- |
| `app/registro/page.tsx` | Mismos campos de siempre, más «Confirmar contraseña»; ambas con botón de ojo y aviso si no coinciden. «¿Ya tienes cuenta? Ingresar». Sin número de cuenta. |
| `components/ui/Field.tsx`, `components/icons.tsx` | `PasswordField` con botón de ojo. |
| `cp/cp-auth.service.ts` | Tipa la respuesta del registro (cuentas con número, saldo y referencia de apertura). |
| `auth/auth.service.ts` | Respaldo: después de registrar, da de alta localmente las cuentas que devuelve Gestiones con el mismo número (idempotente). Si una cuenta se crea en ese momento, acredita la apertura con la referencia de Gestiones; el abono posterior de Gestiones responde `duplicado`. |

## Errores que verá el cliente

| Código | Mensaje |
| --- | --- |
| `409 CUSTOMER_EXISTS` | «Este DPI ya tiene acceso a Banca Virtual. Ingresa con tu correo y contraseña.» (también si el correo ya es de otra persona) |
| `409 CUSTOMER_LINK_REJECTED` | «Los datos no coinciden con los registrados en el banco para ese DPI (correo, fecha de nacimiento…).» Dice qué campos, nunca los valores. |

Antes de cada gestión, Banca vuelve a llamar `POST /banca/registro` para confirmar que el cliente existe; Gestiones responde `409 CUSTOMER_EXISTS` y la gestión sigue.
