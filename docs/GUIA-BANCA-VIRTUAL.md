# Guía de APIs de CP Gestiones para Banca Virtual

Esta guía es para quien desarrolla **CP Banca Virtual**. CP Gestiones es el sistema central: guarda los perfiles de acceso de los clientes (con su contraseña), sus cuentas, saldos, movimientos, tarjetas y gestiones. Banca Virtual no necesita una base propia para esos datos: los consulta y modifica por estas APIs.

> **Versión 2 del contrato (20/10/2026).** Cambios respecto a la versión anterior:
> - El cliente se identifica por su **DPI** en todas las llamadas (`dpi`). `cliente_ref` ya no es necesario; se sigue aceptando para no romper lo que ya esté conectado.
> - El **registro es mínimo** (nombre, apellido, correo, contraseña, DPI y fecha de nacimiento) y **ya no abre una cuenta**. Las cuentas se abren desde el apartado **Cuentas** (`POST /banca/cuentas`).
> - Las **gestiones se solicitan con `POST /banca/solicitudes`** y caen de inmediato en el buzón de Gestiones del banco. Ahí se piden los datos detallados (NIT, empleo, ingresos…).
> - Nuevo **"Olvidé mi contraseña"** (`POST /banca/auth/recuperar`).
> - Contraseñas de clientes: **mínimo 8 caracteres, con letras y números**.

La colección de Postman [CP-Gestiones.postman_collection.json](CP-Gestiones.postman_collection.json) apunta a la URL en línea (grupo **banca**).

---

## 1. Datos de conexión

| Dato | Valor |
| --- | --- |
| URL base | `https://cp-gestiones-api.onrender.com/api/v1` |
| Comprobar que está en línea | `GET https://cp-gestiones-api.onrender.com/api/v1/health` → `{"ok":true,...}` |
| Token de integración | Te lo entrega el administrador de CP Gestiones (panel → **Integraciones** → *Tokens de integración*). Es una cadena de 64 caracteres. |

Encabezados en **todas** las peticiones:

```http
Authorization: Bearer TOKEN_DE_INTEGRACION_BANCA
Accept: application/json
Content-Type: application/json
```

> ⚠️ **El token va solo en el servidor de Banca Virtual** (funciones de servidor de Vercel, una API de Next.js o un backend en Node/PHP), guardado en una variable de entorno como `CP_GESTIONES_TOKEN`. **Nunca** en variables `VITE_` / `NEXT_PUBLIC_`, en el JavaScript del navegador ni en GitHub. Con ese token se mueve dinero de cualquier cliente. Además, la API rechaza llamadas directas desde el navegador (CORS).

> El servidor gratuito se "duerme" si nadie lo usa; la primera llamada puede tardar hasta 60 segundos. Usa un timeout de al menos 60 s.

---

## 2. Reglas que aplican a todo

1. **El cliente se identifica por su DPI** (13 dígitos, sin espacios ni guiones): `?dpi=3068226670601` en las consultas `GET` o `"dpi": "3068226670601"` en el JSON de las escrituras. Tómalo siempre de la **sesión del usuario en tu servidor** (lo devuelve el login como parte de su perfil que tú guardas), nunca de lo que mande el navegador: Gestiones confía en lo que dice tu servidor. Si no envías el DPI: `422 CLIENT_SCOPE_REQUIRED`; si no existe: `404 CUSTOMER_NOT_FOUND`.
2. **`Idempotency-Key` en cada escritura** (registro, abrir cuenta, gestiones, transferencias, pagos…): un texto único por operación, de 8 a 120 caracteres (`A-Z a-z 0-9 _ . : -`), por ejemplo un UUID. Si la petición falla por timeout, **reintenta con la misma clave y el mismo cuerpo**: Gestiones no la ejecuta dos veces. Para una operación nueva, usa una clave nueva. No la llevan: login, logout, recuperar o cambiar contraseña, cerrar una sesión y las consultas `GET`.
3. **Importes:** como texto con punto decimal, `"150.25"` (sin comas, sin `Q`, sin negativos). `ingresos_mensuales_q` y `monto_solicitado` aceptan también número JSON. Las respuestas traen `saldo` (`"150.25"`) y `saldo_centavos` (`15025`); para sumar, usa centavos.
4. **Respuestas:** éxito `{"ok": true, "data": ...}`. Las listas vienen paginadas: los registros en `data.data`, más `current_page`, `last_page`, `total`.
5. **Errores:** `{"ok": false, "message": "...", "code": "...", "errors": {...}}`. Muestra `message` al usuario; con `422`, `errors` trae el mensaje de cada campo (en español).

| HTTP | Significado |
| --- | --- |
| 401 | Token de integración inválido/vencido, contraseña incorrecta (`INVALID_CREDENTIALS`) o sesión del cliente vencida (`CUSTOMER_SESSION_EXPIRED`). |
| 403 | El recurso no es de ese cliente o el token no tiene permiso. |
| 404 | No existe (cliente, cuenta, número de cuenta…). |
| 409 | Regla de negocio: fondos insuficientes, ya existe, gestión en curso, versión desactualizada, etc. Revisa `code`. |
| 422 | Datos inválidos: revisa `errors`. |
| 429 | Demasiadas peticiones; espera lo que indique `Retry-After`. |
| 500 / 503 | Error del servidor o base no disponible. **No muestres éxito**; reintenta con la misma `Idempotency-Key`. |

---

## 3. Qué API usa cada pantalla

| Pantalla de Banca Virtual | API |
| --- | --- |
| Crea tu cuenta (registro) | `POST /banca/registro` |
| Ingresa / Salir | `POST /banca/auth/login`, `GET /banca/auth/sesion`, `POST /banca/auth/logout` |
| ¿Olvidaste tu contraseña? | `POST /banca/auth/recuperar` |
| Inicio (saldo total y "Mis cuentas") | `GET /banca/resumen` |
| Cuentas: abrir una cuenta | `POST /banca/cuentas` |
| Movimientos de una cuenta | `GET /cuentas/{id}/movimientos` |
| Agregar beneficiario / confirmar cuenta destino | `GET /banca/cuentas/destino` |
| Transferir (mis cuentas, beneficiario u otra cuenta) | `POST /transferencias` |
| Pagar servicios (catálogo, pagar e historial) | `GET /banca/servicios`, `POST /banca/pagos-servicios`, `GET /banca/pagos-servicios` |
| Productos (catálogo) | `GET /productos` |
| Solicitar un producto (tarjeta, crédito, débito) | `POST /banca/solicitudes` |
| Gestiones en línea (seguimiento) | `GET /solicitudes`, `GET /solicitudes/{id}`, `GET /creditos` |
| Aceptar o rechazar la oferta | `POST /solicitudes/{id}/transiciones` |
| Mis tarjetas (límite, saldo, estados de cuenta) | `GET /tarjetas`, `GET /tarjetas/{id}/estados-cuenta` |
| Pagar tarjeta / bloquear tarjeta | `POST /tarjetas/{id}/pagos`, `PATCH /tarjetas/{id}/estado` |
| Mi perfil (ver y editar datos financieros) | `GET /banca/perfil`, `PATCH /banca/perfil/finanzas` |
| Seguridad: cambiar contraseña | `POST /banca/auth/contrasena` |
| Seguridad: sesiones activas y cerrar una sesión | `GET /banca/auth/sesiones`, `POST /banca/auth/sesiones/{id}/revocar` |

**Se manejan en Banca Virtual** (no necesitan API de Gestiones): simulador de préstamos, tipo de cambio, agencias y cajeros, la lista de beneficiarios guardados y la programación de transferencias (en la fecha indicada tu servidor llama a `POST /transferencias`). La verificación en dos pasos del cliente y "Plazo fijo" todavía no tienen API.

---

## 4. Detalle de cada API

### 4.1 Registro — `POST /banca/registro`

Crea **solo el perfil de acceso** a la app. No abre cuentas ni pide datos financieros.

```http
POST /api/v1/banca/registro
Idempotency-Key: registro-6f1c2a1e-uuid
```

```json
{
  "nombre": "Fernando",
  "apellido": "Fajardo",
  "correo_electronico": "ferfajardo@creditpulse.com",
  "contrasena": "MiClave2026",
  "dpi": "3068226670601",
  "fecha_nacimiento": "12/06/2003"
}
```

- `contrasena`: 8 a 72 caracteres, con al menos una letra y un número.
- `dpi`: 13 dígitos. Es la **llave** que une al usuario de la app con su información en el banco.
- `fecha_nacimiento`: `dd/mm/aaaa` o `aaaa-mm-dd`; mayor de 18 años. Se usa también para recuperar la contraseña.
- Solo esos campos son obligatorios. Si tu formulario actual todavía envía `nit`, `tipo_empleo`, `ingresos_mensuales_q`, `antiguedad_laboral_meses` o `cliente_ref`, se aceptan y se guardan, pero ya no hace falta pedirlos aquí. Cualquier otro campo se rechaza (422).

Respuesta **201**:

```json
{
  "ok": true,
  "data": {
    "cliente": {
      "id": "6716a3f0c2b1e4a5d6f7a8b9", "nombre": "Fernando", "apellido": "Fajardo", "nombre_completo": "Fernando Fajardo",
      "correo_electronico": "ferfajardo@creditpulse.com", "dpi_enmascarado": "*********0601", "fecha_nacimiento": "2003-06-12",
      "nit": null, "telefono": null, "tipo_empleo": null, "ingresos_mensuales_q": null, "antiguedad_laboral_meses": null,
      "perfil_version": 1, "perfil_completo": false, "acceso_app": true
    },
    "vinculado": false,
    "cuenta": null,
    "cuentas": [],
    "resumen": { "moneda": "GTQ", "cantidad_cuentas": 0, "saldo_total": "0.00", "saldo_disponible": "0.00", "saldo_total_centavos": 0, "saldo_disponible_centavos": 0 }
  }
}
```

**Cliente que ya existe en el banco (vinculación por DPI).** Si el banco ya había registrado a esa persona en una agencia (con sus cuentas), al registrarse en la app con **el mismo DPI, el mismo correo y la misma fecha de nacimiento** se activa su acceso y responde `"vinculado": true` con sus cuentas existentes. Si el DPI existe pero el correo o la fecha no coinciden: `409 CUSTOMER_LINK_REJECTED` (mensaje: acudir a una agencia). Si ese DPI, correo o usuario ya tiene acceso a la app: `409 CUSTOMER_EXISTS`.

### 4.2 Iniciar sesión — `POST /banca/auth/login`

Gestiones valida la contraseña (no la guardes en Banca). Sin `Idempotency-Key`.

```json
{ "correo_electronico": "ferfajardo@creditpulse.com", "contrasena": "MiClave2026", "dispositivo": "Chrome en Windows", "ip": "203.0.113.7" }
```

`dispositivo` (máx. 120 caracteres) e `ip` (IP real del cliente) son opcionales; aparecen en *Sesiones activas* (4.16). Respuesta 200: `data.token_cliente` (sesión de 60 minutos), `data.expires_in` (3600) y `data.cliente` (mismo objeto del registro). Guarda en la **sesión de tu servidor** (cookie HttpOnly) el `token_cliente` y el DPI del usuario: el DPI lo usarás en todas las demás llamadas. No lo guardes en `localStorage`. Cinco contraseñas incorrectas bloquean al cliente 15 minutos; la respuesta siempre es el mismo `401 INVALID_CREDENTIALS`.

- `GET /banca/auth/sesion` con encabezado extra `X-Cliente-Token: <token_cliente>` → confirma que la sesión sigue vigente y devuelve `data.cliente` y `data.expires_at`.
- `POST /banca/auth/logout` con `X-Cliente-Token` → cierra esa sesión.

### 4.3 Olvidé mi contraseña — `POST /banca/auth/recuperar`

El cliente demuestra su identidad con su DPI, su correo y su fecha de nacimiento, y elige una contraseña nueva. Sin `Idempotency-Key` ni `X-Cliente-Token`.

```json
{ "dpi": "3068226670601", "correo_electronico": "ferfajardo@creditpulse.com", "fecha_nacimiento": "12/06/2003", "contrasena_nueva": "OtraClave2027" }
```

- Respuesta 200: `{ "contrasena_restablecida": true, "sesiones_cerradas": 2 }`. Se cierran **todas** las sesiones abiertas; el cliente vuelve a iniciar sesión con la contraseña nueva.
- Si algún dato no coincide (o el cliente está bloqueado): `422 RECOVERY_REJECTED`, siempre con el mismo mensaje, sin decir qué dato falló. Cada intento fallido cuenta para el bloqueo de 15 minutos (5 intentos), igual que el login. Límite: 5 intentos por minuto por DPI.

### 4.4 Inicio — `GET /banca/resumen?dpi=3068226670601`

```json
{
  "ok": true,
  "data": {
    "cliente": { "id": "...", "nombre_completo": "Fernando Fajardo", "...": "..." },
    "cuentas": [
      { "id": "6716a3f0c2b1e4a5d6f7a8c0", "numero": "2738729879", "numero_formateado": "2-738-729879",
        "nombre": "Mi cuenta de ahorro", "tipo": "ahorro", "estado": "activa",
        "saldo": "0.00", "saldo_disponible": "0.00", "saldo_centavos": 0, "saldo_disponible_centavos": 0 }
    ],
    "resumen": { "moneda": "GTQ", "cantidad_cuentas": 1, "saldo_total": "0.00", "saldo_disponible": "0.00",
                 "saldo_total_centavos": 0, "saldo_disponible_centavos": 0 }
  }
}
```

Es lo que muestra la tarjeta "Saldo total en quetzales" y la lista "Mis cuentas". Una cuenta bloqueada muestra saldo disponible 0. Recién registrado, el cliente tiene `cuentas: []`: muéstrale el botón para abrir su primera cuenta.

### 4.5 Cuentas: abrir una cuenta — `POST /banca/cuentas`

```json
{ "dpi": "3068226670601", "producto": "ahorro", "nombre": "Mi cuenta de ahorro" }
```

`producto`: `ahorro` o `monetaria`. `nombre` es opcional (máx. 60 caracteres). Se abre con Q0.00 y responde 201 con la cuenta (`id`, `numero` de 10 dígitos, `numero_formateado`, `tipo`, `saldo`…). El banco recibe una alerta. Puede abrir varias.

### 4.6 Movimientos de una cuenta — `GET /cuentas/{id}/movimientos?dpi=...&page=1`

Lista paginada (50 por página, más recientes primero). Cada movimiento: `importe_centavos` (negativo = salida), `saldo_centavos` (saldo después del movimiento), `concepto`, `tipo` (`transferencia`, `deposito`, `retiro`, `pago_servicio`, `pago_tarjeta`, `interes_ahorro`…), `transaccion_id` y `created_at`.

### 4.7 Transferir — `POST /transferencias`

Para **"Otra cuenta" y beneficiarios**, envía el número de 10 dígitos que escribe el cliente:

```json
{ "dpi": "3068226670601", "origen_id": "6716a3f0c2b1e4a5d6f7a8c0", "destino_numero": "2738729879", "monto": "150.00", "concepto": "Pago de almuerzo" }
```

Para **"Mis cuentas"** puedes enviar `destino_id` (el `id` de la cuenta) en lugar de `destino_numero`; nunca ambos. Respuesta 201:

```json
{ "ok": true, "data": { "transaccion_id": "b3e1…", "monto": "150.00", "moneda": "GTQ", "estado": "completada" } }
```

`concepto` va de 3 a 160 caracteres (si el cliente lo deja vacío, manda uno por defecto, por ejemplo "Transferencia"). Errores: `409 INSUFFICIENT_FUNDS`, `409 ACCOUNT_BLOCKED`, `404 ACCOUNT_NOT_FOUND` (número inexistente), `403` (la cuenta de origen no es del cliente), `422 SAME_ACCOUNT`. Solo hay transferencias dentro del banco.

### 4.8 Confirmar una cuenta destino — `GET /banca/cuentas/destino?dpi=...&numero=2000000002`

Úsalo al **agregar un beneficiario** o antes de confirmar una transferencia, para mostrar a quién se le enviará el dinero:

```json
{ "ok": true, "data": { "numero": "2000000002", "numero_formateado": "2-000-000002", "tipo": "ahorro", "moneda": "GTQ", "titular": "María L.", "propia": false } }
```

Solo muestra el primer nombre y la inicial del apellido; nunca saldos ni datos personales. `propia: true` indica que la cuenta es del mismo cliente. Si no existe o está bloqueada: `404 ACCOUNT_NOT_FOUND`.

### 4.9 Catálogo — `GET /productos`

Lista paginada con `code`, `name`, `type` (`ahorro`, `monetaria`, `debito`, `credito`, `prestamo`), `category` (`clasica`, `oro`, `platino`), `annual_bps` (tasa anual en puntos base: 3600 = 36 %), `max_limit_cents` y `active`. Códigos: `ahorro`, `monetaria`, `debito`, `credito-clasica`, `credito-oro`, `credito-platino`, `credito-personal`, `credito-vehicular`, `credito-hipotecario`.

### 4.10 Solicitar un producto (gestión) — `POST /banca/solicitudes`

Es la única forma de crear una gestión: el personal del banco ya no puede crearlas, solo procesarlas. Apenas el cliente la envía, **aparece en el buzón "Gestiones" del banco** y el personal recibe una alerta.

**Aquí se piden los datos detallados del cliente** (la primera vez). Si ya están en su perfil (ver `GET /banca/perfil`, campo `perfil_completo`), puedes omitirlos o enviarlos actualizados.

Tarjeta de crédito o préstamo:

```json
{
  "dpi": "3068226670601",
  "producto": "credito-personal",
  "monto_solicitado": "25000.00",
  "gestion_ref": "100040",
  "nit": "116698640",
  "telefono": "55513002",
  "tipo_empleo": "Asalariado",
  "ingresos_mensuales_q": 9500,
  "antiguedad_laboral_meses": 30
}
```

- `producto`: `credito-clasica`, `credito-oro`, `credito-platino`, `credito-personal`, `credito-vehicular`, `credito-hipotecario` o `debito`.
- `monto_solicitado`: obligatorio para créditos y tarjetas de crédito (no puede pasar del máximo de la categoría: `422 PRODUCT_LIMIT`).
- `gestion_ref`: opcional, tu número de gestión ("Gestión #100040").
- Datos del cliente: `nit`, `tipo_empleo` (`Asalariado` / `Independiente`), `ingresos_mensuales_q`, `antiguedad_laboral_meses` (no mayor que su edad en meses) y `telefono` (opcional). Si falta alguno que el perfil tampoco tiene, responde `422` con `errors` de cada dato faltante ("Completa este dato para solicitar el producto."). La fecha de nacimiento no se puede cambiar aquí.

Tarjeta de débito:

```json
{ "dpi": "3068226670601", "producto": "debito", "cuenta_id": "6716a3f0c2b1e4a5d6f7a8c0" }
```

`cuenta_id` es opcional: si no lo envías se vincula su primera cuenta activa. Sin cuentas: `422` en `cuenta_id` ("Abre una cuenta…").

Respuesta 201 con la gestión: `id` (**guárdalo**), `folio`, `producto`, `estado` (`en_evaluacion` para créditos, `aprobada` para débito), `version`, `limite_solicitado_centavos`, `gestion_ref`, `solicitante` y `created_at`. Otros errores: `409 MANAGEMENT_IN_PROGRESS` ("Ya tienes una gestión en curso" de ese producto), `422` en `producto` si no existe.

### 4.11 Gestiones en línea (seguimiento)

- `GET /solicitudes?dpi=...` → gestiones del cliente: `id`, `folio`, `gestion_ref`, `producto`, `estado`, `version`, `limite_solicitado_centavos`, `limite_aprobado_centavos`, `limite_asignado_centavos`, `tarjeta_id` / `credito_id`, `created_at`. Filtro opcional `&estado=en_tramite`.
- `GET /solicitudes/{id}?dpi=...` → `data.solicitud` y `data.historial` (cada cambio de estado con fecha y nota, ideal para la línea de tiempo).
- `GET /creditos?dpi=...` → préstamos autorizados con `referencia`, `limite_centavos` y `estado`.

Cómo se ven los pasos de tu pantalla:

| Paso en la app | `estado` en Gestiones | Quién lo mueve |
| --- | --- | --- |
| Enviada / En evaluación | `en_evaluacion` | Banca, al crearla con `POST /banca/solicitudes` |
| Aprobada (con límite) / No aprobada | `aprobada` / `rechazada` (o `revision_manual`) | CP Analítica (o el gerente en revisión manual) |
| Aceptaste | `aceptada` | Banca, cuando el cliente acepta (abajo) |
| En trámite — "El banco gestiona tu producto" | `en_tramite` | Personal del banco |
| Completada / Producto emitido | `emitida` | Personal del banco, al asignar el límite y emitir |

**Aceptar o rechazar la oferta** (solo con la gestión en `aprobada`): `POST /solicitudes/{id}/transiciones` con `Idempotency-Key`:

```json
{ "dpi": "3068226670601", "version": 2, "estado": "aceptada", "nota": "Aceptada por el cliente en Banca Virtual", "referencia_aceptacion": "consent-100040" }
```

Para rechazarla usa `"estado": "cancelada"` (sin `referencia_aceptacion`). `version` es la actual de la gestión; si cambió, recibes `409 STALE_VERSION`: vuelve a consultarla.

### 4.12 Mis tarjetas

- `GET /tarjetas?dpi=...` → `referencia`, `ultimos_cuatro`, `tipo` (`credito`/`debito`), `categoria`, `estado`, `limite_centavos`, `principal_centavos` + `interes_centavos` (= deuda actual). Crédito disponible = `limite_centavos - principal_centavos - interes_centavos`.
- `GET /tarjetas/{id}/estados-cuenta?dpi=...` → cortes con `fecha_corte`, `fecha_pago`, `saldo_centavos`, `minimo_centavos`, `pagado_centavos`.
- Pagar: `POST /tarjetas/{id}/pagos` con `{"dpi":"...","origen_id":"<cuenta del cliente>","monto":"250.00"}`. No puede exceder la deuda (`422 PAYMENT_EXCEEDS_DEBT`).
- Bloquear / desbloquear: `PATCH /tarjetas/{id}/estado` con `{"dpi":"...","estado":"bloqueada","nota":"Bloqueo solicitado por el cliente"}` (o `"activa"`).

### 4.13 Mi perfil

- `GET /banca/perfil?dpi=...` → el objeto `cliente` (incluye `perfil_version` y `perfil_completo`).
- `PATCH /banca/perfil/finanzas` (con `Idempotency-Key`):

```json
{ "dpi": "3068226670601", "perfil_version": 2, "tipo_empleo": "Independiente", "ingresos_mensuales_q": "6500.25", "antiguedad_laboral_meses": 36 }
```

Envía la `perfil_version` que consultaste; si otro cambio se adelantó recibes `409 STALE_PROFILE`. Nombre, DPI, correo y fecha de nacimiento no se editan aquí.

### 4.14 Pagar servicios

**Catálogo:** `GET /banca/servicios` devuelve los 7 servicios de la pantalla, con el texto del campo que debe pedir el formulario:

```json
{ "ok": true, "data": [
  { "codigo": "empagua", "nombre": "Agua potable (EMPAGUA)", "categoria": "Agua", "campo": "Número de servicio" },
  { "codigo": "eegsa", "nombre": "Energía eléctrica (EEGSA)", "categoria": "Energía", "campo": "Número de contador (NIS)" },
  { "codigo": "energuate", "nombre": "Energía eléctrica (Energuate)", "categoria": "Energía", "campo": "Número de contador (NIS)" },
  { "codigo": "impuestos-municipales", "nombre": "Impuestos municipales", "categoria": "Gobierno", "campo": "Número de boleta" },
  { "codigo": "internet-cable", "nombre": "Internet y cable", "categoria": "Internet", "campo": "Número de contrato" },
  { "codigo": "claro", "nombre": "Telefonía móvil (Claro)", "categoria": "Telefonía", "campo": "Número de teléfono" },
  { "codigo": "tigo", "nombre": "Telefonía móvil (Tigo)", "categoria": "Telefonía", "campo": "Número de teléfono" }
] }
```

**Pagar:** `POST /banca/pagos-servicios` (con `Idempotency-Key`):

```json
{ "dpi": "3068226670601", "origen_id": "6716a3f0c2b1e4a5d6f7a8c0", "servicio": "eegsa", "referencia_servicio": "NIS-1234", "monto": "125.50" }
```

- `origen_id`: la cuenta del cliente que eligió en "Pagar con".
- `referencia_servicio`: el número que escribió el cliente (4 a 40 letras, números o guiones; se guarda en mayúsculas).

Respuesta 201 (sirve como comprobante):

```json
{ "ok": true, "data": {
  "id": "6716b0c1…", "comprobante": "b4958d96-cfd6-4719-83e3-2678fe9e16e5",
  "servicio": "eegsa", "servicio_nombre": "Energía eléctrica (EEGSA)", "categoria": "Energía",
  "referencia_servicio": "NIS-1234", "cuenta_numero": "2738729879",
  "monto": "125.50", "monto_centavos": 12550, "moneda": "GTQ", "estado": "completado",
  "fecha": "2026-10-20T18:00:00+00:00", "saldo_disponible": "374.50"
} }
```

Errores: `409 INSUFFICIENT_FUNDS`, `409 ACCOUNT_BLOCKED`, `403` (la cuenta no es del cliente), `422` con `errors.referencia_servicio`, `errors.servicio` o `errors.monto`. El pago también aparece en los movimientos de la cuenta (`tipo: pago_servicio`).

**Historial de pagos:** `GET /banca/pagos-servicios?dpi=...&page=1` → lista paginada (25 por página, más recientes primero) con los mismos campos del comprobante.

> Es una simulación académica: el dinero sale de la cuenta del cliente hacia una cuenta interna de liquidación del banco; no se conecta con las empresas reales.

### 4.15 Seguridad: cambiar contraseña — `POST /banca/auth/contrasena`

Con `X-Cliente-Token` (sin `Idempotency-Key`):

```json
{ "contrasena_actual": "MiClave2026", "contrasena_nueva": "NuevaClave2027" }
```

- La nueva sigue la regla del registro (8 a 72 caracteres, con letras y números) y debe ser distinta de la actual.
- Respuesta 200: `{ "contrasena_actualizada": true, "sesiones_cerradas": 1 }`. **Se cierran las demás sesiones del cliente**; la actual sigue activa.
- Si la actual es incorrecta: `422` con `errors.contrasena_actual`. Cuenta como intento fallido (5 errores bloquean 15 minutos). Límite: 5 intentos por minuto por sesión.
- El campo "Repite la contraseña nueva" de la pantalla se valida en Banca; no lo envíes.

### 4.16 Seguridad: sesiones activas

Usan el encabezado `X-Cliente-Token`.

- `GET /banca/auth/sesiones` → sesiones vigentes del cliente:

```json
{ "ok": true, "data": [
  { "id": "6716c2…", "actual": true, "dispositivo": "Chrome en Windows", "ip": "203.0.113.7",
    "inicio": "2026-10-20T16:55:00+00:00", "ultima_actividad": "2026-10-20T17:00:00+00:00", "vence": "2026-10-20T17:55:00+00:00" }
] }
```

  `actual: true` marca la sesión desde la que consultas ("Esta sesión"). `ultima_actividad` se actualiza cada vez que llamas a `GET /banca/auth/sesion` (como máximo una vez por minuto).
- `POST /banca/auth/sesiones/{id}/revocar` → cierra esa sesión (`data.era_actual` indica si era la tuya). Solo puede cerrar sesiones del mismo cliente.

### 4.17 Avisos al banco (opcional) — `POST /banca/eventos`

El registro, las cuentas y las gestiones ya crean alertas automáticamente en el panel del banco. Este endpoint es solo para avisos adicionales:

```json
{ "evento_id": "evt-0001", "tipo": "registro.iniciado", "referencia": "registro-0001" }
```

Tipos: `registro.iniciado` (sin DPI), `cliente.registrado`, `cuenta.abierta`, `gestion.en_tramite` (estos tres con `dpi` y como `referencia` el `id` en Gestiones).

---

## 5. Ejemplo de código (servidor en JavaScript)

Funciona en funciones de servidor de Vercel, rutas API de Next.js o Node 18+:

```js
// Solo en el servidor. CP_GESTIONES_TOKEN se configura en las variables de entorno (no públicas).
const BASE = 'https://cp-gestiones-api.onrender.com/api/v1'

export async function gestiones(method, path, body, idempotencyKey, extraHeaders = {}) {
  const response = await fetch(BASE + path, {
    method,
    headers: {
      Authorization: `Bearer ${process.env.CP_GESTIONES_TOKEN}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}),
      ...extraHeaders,
    },
    body: body ? JSON.stringify(body) : undefined,
    signal: AbortSignal.timeout(60_000),
  })
  const json = await response.json()
  if (!json.ok) {
    const error = new Error(json.message)
    Object.assign(error, { status: response.status, code: json.code, errors: json.errors })
    throw error
  }
  return json.data
}

// Uso (el DPI sale de la sesión del usuario en tu servidor):
const resumen = await gestiones('GET', `/banca/resumen?dpi=${usuario.dpi}`)
const cuenta = await gestiones('POST', '/banca/cuentas', { dpi: usuario.dpi, producto: 'ahorro' }, crypto.randomUUID())
const gestion = await gestiones('POST', '/banca/solicitudes', {
  dpi: usuario.dpi, producto: 'credito-personal', monto_solicitado: '25000.00',
  nit: '116698640', tipo_empleo: 'Asalariado', ingresos_mensuales_q: 9500, antiguedad_laboral_meses: 30,
}, crypto.randomUUID()) // guarda la clave si vas a reintentar
```

---

## 6. Para empezar a probar

1. Pide el token al administrador de CP Gestiones y guárdalo en tu servidor como `CP_GESTIONES_TOKEN`.
2. Prueba `GET /health` y registra un cliente de prueba con `POST /banca/registro`.
3. Inicia sesión (`POST /banca/auth/login`), abre una cuenta (`POST /banca/cuentas`) y muestra `GET /banca/resumen` en Inicio.
4. Para tener saldo de prueba, el cajero o el administrador hace un depósito desde el panel de Gestiones (**Cuentas → Depósito o retiro**) a la cuenta del cliente.
5. Prueba una transferencia entre dos clientes de prueba por número de cuenta y una gestión (`POST /banca/solicitudes`): debe aparecer en el buzón **Gestiones** del panel.

Si una respuesta no es la esperada, comparte el `request_id` que viene en el error: con él se encuentra la petición en los registros del servidor.
