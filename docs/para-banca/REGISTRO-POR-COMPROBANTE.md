# Para Banca Virtual: registro con el comprobante de apertura

Desde ahora el cliente y su cuenta se crean **solo en CP Gestiones**. En Banca Virtual, «Registrarse» únicamente valida los datos del comprobante de apertura y crea la contraseña. No se crea otra cuenta ni otro número.

## Flujo

1. **Gestiones · Clientes:** el banco registra la ficha (nombre, apellido, correo, DPI único, NIT, teléfono, fecha de nacimiento, ingreso mensual, meses laborando).
2. **Gestiones · Cuentas → «Crear cuenta»:** titular, tipo (monetaria o ahorro) y monto de apertura (mínimo Q100.00). Gestiones genera el número (10 dígitos, no cambia nunca) y entrega el comprobante en PDF.
3. **Gestiones → Banca (automático):** `POST /admin/cuentas` con el **mismo número** y luego `POST /admin/abonos` con el depósito de apertura (`origen: CP-GESTIONES-APERTURAS`, `referencia: CP-<transacción>`). Usa la misma llave de servicio de los abonos.
4. **Banca · Registrarse:** el cliente escribe nombre, apellido, DPI, número de cuenta, correo, fecha de nacimiento (NIT opcional) y crea su contraseña. Banca llama `POST /banca/registro` de Gestiones con `numero_cuenta`.
5. **Banca · Inicio / Mis cuentas:** la cuenta aparece con el mismo número, tipo y saldo.

## Qué cambia en su código

Parche: [`registro-por-comprobante.patch`](registro-por-comprobante.patch) (sobre `da5a5fb`). Aplicar con `git apply docs/para-banca/registro-por-comprobante.patch` desde la raíz de su repositorio.

| Archivo | Cambio |
| --- | --- |
| `auth/auth.schema.ts` | `RegisterDto.numeroCuenta` obligatorio (10 dígitos). |
| `cp/cp-auth.service.ts` | `registrar` envía `numero_cuenta` (y `nit` si viene); tipa la respuesta con las cuentas. |
| `auth/auth.service.ts` | Tras registrar, `sincronizarCuentas`: llama a su propio `registrarDesdeCp` con el número de Gestiones (idempotente). Si la cuenta se crea en ese momento, acredita la apertura con la `apertura_referencia` de Gestiones; cuando llegue el abono de Gestiones, responden `duplicado` y no se suma dos veces. |
| `app/registro/page.tsx` | Campos del comprobante, «Crear contraseña» y «Confirmar contraseña» con ojo y validación de que coincidan, botón «Crear cuenta» y «¿Ya tienes cuenta? Ingresar». |
| `components/ui/Field.tsx`, `components/icons.tsx` | `PasswordField` con botón de ojo (`EyeIcon` / `EyeOffIcon`). |
| `interfaces/auth.interface.ts` | `numeroCuenta` y `nit` en `RegisterRequest`. |

Mientras no apliquen el parche, el registro en Banca responde «Ingresa el número de cuenta de tu comprobante de apertura», porque Gestiones ya lo exige.

## Prueba de punta a punta (21/10/2026, local)

Gestiones con estos cambios + su backend `da5a5fb` con el parche + PostgreSQL con `database/01…07`:

| Prueba | Resultado |
| --- | --- |
| Cuenta creada en Gestiones con Q250.75 | Banca la recibe con el mismo número y saldo 250.75 |
| Registro con un número de cuenta de otra persona | 422 «El número de cuenta no pertenece al DPI ingresado…» |
| Registro con DPI inexistente | 404 «No encontramos un cliente con ese DPI…» |
| Registro con otro correo | 409 «Los datos no coinciden… (correo)…» |
| Registro correcto | 201; Mis cuentas muestra 1 cuenta, mismo número y saldo |
| Segundo registro con el mismo DPI | 409 (ya tiene acceso) |
| Cliente que se registra antes de que llegue la cuenta | El registro la trae con el mismo número y Q100.00; el abono posterior de Gestiones responde `duplicado` y el saldo sigue en Q100.00 |
| Inicio de sesión con la contraseña creada | 200 |

Su límite de 5 registros por minuto sigue activo.
