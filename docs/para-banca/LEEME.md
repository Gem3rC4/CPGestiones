# Para Banca Virtual: clientes creados en CP Gestiones no pueden iniciar sesión

**Síntoma:** un cliente que el banco registró en CP Gestiones (con correo y contraseña) intenta entrar a Banca Virtual y la app responde **"Cliente no encontrado"**.

## Qué pasa

1. Banca Virtual llama a `POST /banca/auth/login` de CP Gestiones → **responde 200** (la contraseña es correcta).
2. Como ese correo no existe en la base de Banca, `AuthService.crearLocalDesdeCp()` lo inserta en `clientes`.
3. Dentro de la misma transacción llama a `this.clientes.obtener(row.id)`, que consulta con **otra conexión** del pool. Esa conexión todavía no ve el `INSERT` sin confirmar → `NotFoundException('Cliente no encontrado')` → la transacción se deshace.

Archivo: `backend/banca-virtual-api/src/auth/auth.service.ts`, método `crearLocalDesdeCp` (línea ~193 en el commit `5701e92`).

Las credenciales están bien: CP Gestiones guarda la contraseña con bcrypt y la valida en el login. No hace falta compartir la base de datos ni tokens nuevos.

## Prueba reproducida (21/10/2026)

Se levantó el backend de Banca Virtual del commit `5701e92` **sin modificar**, con su propio esquema PostgreSQL (`database/01…04`), conectado a CP Gestiones. Un cliente creado en el panel de Gestiones con contraseña:

| Prueba | Resultado |
| --- | --- |
| `POST /banca/auth/login` directo en CP Gestiones | `200 ok: true` (correo y contraseña correctos) |
| `POST /api/v1/auth/login` en Banca, código original | `404 {"message":"Cliente no encontrado"}` |
| Mismo login en Banca **con el parche** | `200` con `accessToken`; el cliente queda en `clientes` con su DPI |
| Segundo login con el parche | `200` (ya existe localmente) |

El texto "Cliente no encontrado" no existe en CP Gestiones: solo en `clientes.service.ts` y `seguridad.service.ts` de Banca.

## La corrección (ya probada)

Parche: [`login-clientes-de-gestiones.patch`](login-clientes-de-gestiones.patch)

- Lee al cliente **después** de confirmar la transacción.
- Guarda el **DPI** (cifrado y con su `dpi_hash`), que CP Gestiones ahora devuelve en el login (`cliente.dpi`), para que las demás llamadas a CP (gestiones, etc.) funcionen.
- Si el perfil solo trae el nombre completo, lo separa en nombre y apellido.

Comprobado: aplica limpio sobre `5701e92` y `npx tsc --noEmit` compila sin errores.

```bash
git apply login-clientes-de-gestiones.patch
```

Después, un commit y un push para que Render vuelva a desplegar el backend de Banca.

## Respuesta del login de CP Gestiones (contrato v3)

`POST https://cp-gestiones-api.onrender.com/api/v1/banca/auth/login` con el token de integración:

```json
{ "correo_electronico": "fercho@gmail.com", "contrasena": "********" }
```

```json
{
  "ok": true,
  "data": {
    "token_cliente": "64 caracteres…",
    "expires_in": 3600,
    "cliente": {
      "id": "6716…", "nombre": "Fercho", "apellido": "", "nombre_completo": "Fercho",
      "correo_electronico": "fercho@gmail.com", "dpi": "1234567895667", "dpi_enmascarado": "*********5667",
      "fecha_nacimiento": "2000-01-01", "ingresos_mensuales_q": "6500.00", "acceso_app": true, "perfil_completo": false
    }
  }
}
```

## Cómo probarlo

1. En el panel de CP Gestiones: **Clientes → Registrar cliente** con correo, DPI, fecha de nacimiento y **contraseña de Banca Virtual**.
2. En Banca Virtual, iniciar sesión con ese correo y esa contraseña → debe entrar al Inicio.
3. Repetir el login: ahora usa el registro local creado en el paso anterior.

## Mientras no se aplique el parche

Registra al cliente en Gestiones **sin contraseña** y pídele que se registre en la app de Banca Virtual con el **mismo DPI, correo y fecha de nacimiento**: CP Gestiones vincula ambos perfiles y el login funciona.

## Pendiente relacionado: saldos

Banca Virtual guarda sus propias cuentas y saldos en su base PostgreSQL. Un depósito, una planilla o un préstamo hechos en CP Gestiones cambian la cuenta de CP Gestiones, que la app no muestra. Para que los saldos coincidan, las pantallas de cuentas deben leerlos de CP Gestiones (`GET /banca/resumen`, sin caché). Detalle en la sección 2.2 de [GUIA-BANCA-VIRTUAL.md](../GUIA-BANCA-VIRTUAL.md).
