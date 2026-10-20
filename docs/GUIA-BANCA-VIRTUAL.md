# Guía de APIs de CP Gestiones para Banca Virtual

Esta guía es para quien desarrolla **CP Banca Virtual**. CP Gestiones es el sistema central: guarda los clientes, sus contraseñas, cuentas, saldos, movimientos, tarjetas y gestiones. Banca Virtual no necesita una base propia para esos datos; los consulta y modifica por estas APIs.

Detalles extra (validaciones campo por campo y casos límite) están en [REGISTRO-BANCA.md](REGISTRO-BANCA.md) e [INTEGRACION.md](INTEGRACION.md). La colección de Postman [CP-Gestiones.postman_collection.json](CP-Gestiones.postman_collection.json) ya apunta a la URL en línea (grupo **banca**).

---

## 1. Datos de conexión

| Dato | Valor |
| --- | --- |
| URL base | `https://cp-gestiones-api.onrender.com/api/v1` |
| Comprobar que está en línea | `GET https://cp-gestiones-api.onrender.com/api/v1/health` → `{"ok":true,...}` |
| Token de integración | Te lo entrega el administrador de CP Gestiones (se genera en el panel → **Integraciones**). Es una cadena de 64 caracteres. |

Encabezados en **todas** las peticiones:

```http
Authorization: Bearer TOKEN_DE_INTEGRACION_BANCA
Accept: application/json
Content-Type: application/json
```

> ⚠️ **El token va solo en el servidor de Banca Virtual** (por ejemplo, funciones de servidor de Vercel, una API de Next.js o un backend en Node/PHP), guardado en una variable de entorno como `CP_GESTIONES_TOKEN`. **Nunca** en variables `VITE_` / `NEXT_PUBLIC_`, en el JavaScript del navegador ni en GitHub. Con ese token se mueve dinero de cualquier cliente. Además, la API rechaza llamadas directas desde el navegador (CORS).

> El servidor gratuito se "duerme" si nadie lo usa; la primera llamada puede tardar hasta 60 segundos. Usa un timeout de al menos 60 s.

---

## 2. Reglas que aplican a todo

1. **`cliente_ref` = el ID de tu usuario.** Al registrar un cliente le das un identificador estable de tu sistema (UUID o ID de tu tabla de usuarios, como texto). Después lo envías en cada consulta (`?cliente_ref=...`) o escritura (`"cliente_ref": "..."` en el JSON). Tómalo siempre de la **sesión del usuario en tu servidor**, nunca de lo que mande el navegador: Gestiones confía en lo que dice tu servidor.
2. **`Idempotency-Key` en cada escritura** (registro, transferencias, aperturas, pagos…): un texto único por operación, de 8 a 120 caracteres (`A-Z a-z 0-9 _ . : -`), por ejemplo un UUID. Si la petición falla por timeout, **reintenta con la misma clave y el mismo cuerpo**: Gestiones no la ejecuta dos veces. Para una operación nueva, usa una clave nueva. No la llevan: login, logout, cambio de contraseña, cerrar una sesión y las consultas `GET`.
3. **Importes como texto con punto decimal:** `"150.25"`. Sin comas, sin `Q`, sin negativos. Las respuestas traen los saldos en dos formas: `saldo` (`"150.25"`) y `saldo_centavos` (`15025`, entero). Para sumar, usa centavos.
4. **Respuestas:** éxito `{"ok": true, "data": ...}`. Las listas vienen paginadas: los registros en `data.data`, más `current_page`, `last_page`, `total`.
5. **Errores:** `{"ok": false, "message": "...", "code": "...", "errors": {...}}`. Muestra `message` al usuario; `errors` trae los mensajes por campo cuando es `422`.

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
| Registro de usuario | `POST /banca/registro` |
| Iniciar / cerrar sesión | `POST /banca/auth/login`, `GET /banca/auth/sesion`, `POST /banca/auth/logout` |
| Inicio (saldo total y "Mis cuentas") | `GET /banca/resumen` |
| Detalle / movimientos de una cuenta | `GET /cuentas/{id}/movimientos` |
| Abrir otra cuenta | `POST /cuentas` |
| Agregar beneficiario / confirmar cuenta destino | `GET /banca/cuentas/destino` |
| Transferir (mis cuentas, beneficiario u otra cuenta) | `POST /transferencias` |
| Pagar servicios (catálogo, pagar e historial) | `GET /banca/servicios`, `POST /banca/pagos-servicios`, `GET /banca/pagos-servicios` |
| Productos (catálogo) | `GET /productos` |
| Solicitar tarjeta de débito | `POST /solicitudes` |
| Gestiones (seguimiento) | `GET /solicitudes`, `GET /solicitudes/{id}`, `GET /creditos` |
| Aceptar oferta de crédito evaluada por Analítica | `POST /banca/gestiones` |
| Mis tarjetas (límite, saldo, estados de cuenta) | `GET /tarjetas`, `GET /tarjetas/{id}/estados-cuenta` |
| Pagar tarjeta / bloquear tarjeta | `POST /tarjetas/{id}/pagos`, `PATCH /tarjetas/{id}/estado` |
| Mi perfil (ver y editar datos financieros) | `GET /banca/perfil`, `PATCH /banca/perfil/finanzas` |
| Seguridad: cambiar contraseña | `POST /banca/auth/contrasena` |
| Seguridad: sesiones activas y cerrar una sesión | `GET /banca/auth/sesiones`, `POST /banca/auth/sesiones/{id}/revocar` |
| Avisar a los operadores del banco (opcional) | `POST /banca/eventos` |

**Se manejan en Banca Virtual** (no necesitan API de Gestiones): simulador de préstamos, tipo de cambio, agencias y cajeros, la lista de beneficiarios guardados y la programación de transferencias. Para ejecutar una transferencia programada, tu servidor llama a `POST /transferencias` en la fecha indicada. La verificación en dos pasos del cliente y "Plazo fijo" todavía no tienen API.

---

## 4. Detalle de cada API

### 4.1 Registro — `POST /banca/registro`

Crea el cliente, guarda su contraseña (cifrada) y le abre una **cuenta de ahorro con Q0.00**, todo en una sola operación.

```http
POST /api/v1/banca/registro
Idempotency-Key: registro-6f1c2a1e-uuid
```

```json
{
  "cliente_ref": "usr_8f3a2c",
  "nombre": "Juan",
  "apellido": "Pérez",
  "correo_electronico": "juan.perez@ejemplo.com",
  "contrasena": "EjemploSeguro123",
  "dpi": "1234567890123",
  "nit": "1234567-8",
  "fecha_nacimiento": "25/10/1990",
  "tipo_empleo": "Asalariado",
  "ingresos_mensuales_q": 5000.00,
  "antiguedad_laboral_meses": 24
}
```

- Todos los campos son obligatorios y **no se aceptan campos extra** (422).
- `contrasena`: 12 a 72 caracteres, con mayúscula, minúscula y número.
- `dpi`: 13 dígitos. `nit`: dígitos con guion opcional y dígito final o `K`.
- `fecha_nacimiento`: `dd/mm/aaaa` o `aaaa-mm-dd`, mayor de 18 años.
- `tipo_empleo`: `Asalariado` o `Independiente`.

Respuesta **201**:

```json
{
  "ok": true,
  "data": {
    "cliente": {
      "id": "6716a3f0c2b1e4a5d6f7a8b9", "cliente_ref": "usr_8f3a2c",
      "nombre": "Juan", "apellido": "Pérez", "nombre_completo": "Juan Pérez",
      "correo_electronico": "juan.perez@ejemplo.com", "dpi_enmascarado": "*********0123",
      "nit": "1234567-8", "fecha_nacimiento": "1990-10-25", "tipo_empleo": "Asalariado",
      "ingresos_mensuales_q": "5000.00", "antiguedad_laboral_meses": 24, "perfil_version": 1
    },
    "cuenta": {
      "id": "6716a3f0c2b1e4a5d6f7a8c0", "numero": "2738729879", "numero_formateado": "2-738-729879",
      "nombre": "Mi cuenta de ahorro", "tipo": "ahorro", "moneda": "GTQ", "estado": "activa",
      "saldo": "0.00", "saldo_centavos": 0, "saldo_disponible": "0.00", "saldo_disponible_centavos": 0
    },
    "resumen": { "cantidad_cuentas": 1, "saldo_total": "0.00", "saldo_disponible": "0.00" }
  }
}
```

**Guarda `data.cliente.id`** junto a tu usuario: lo necesitas como `cliente_id` para abrir cuentas y solicitar productos. Errores comunes: `409 CUSTOMER_EXISTS` (ya hay un cliente con ese correo, DPI o `cliente_ref`), `422` con `errors` por campo.

### 4.2 Iniciar sesión — `POST /banca/auth/login`

Gestiones valida la contraseña (no la guardes en Banca). Sin `Idempotency-Key`.

```json
{ "correo_electronico": "juan.perez@ejemplo.com", "contrasena": "EjemploSeguro123" }
```

Opcional: agrega `"dispositivo": "Chrome en Windows"` (máx. 120 caracteres) e `"ip": "IP-real-del-cliente"` para que aparezcan en *Sesiones activas* (punto 4.15). Respuesta 200: `data.token_cliente` (sesión de 60 minutos), `data.expires_in` (3600) y `data.cliente` (mismo objeto del registro, incluye `cliente_ref`). Guarda `token_cliente` en la **sesión de tu servidor** (cookie HttpOnly), no en `localStorage`. Cinco contraseñas incorrectas bloquean al cliente 15 minutos; la respuesta siempre es el mismo `401 INVALID_CREDENTIALS`.

- `GET /banca/auth/sesion` con encabezado extra `X-Cliente-Token: <token_cliente>` → confirma que la sesión sigue vigente y devuelve `data.cliente` y `data.expires_at`.
- `POST /banca/auth/logout` con `X-Cliente-Token` → cierra esa sesión.

### 4.3 Inicio — `GET /banca/resumen?cliente_ref=usr_8f3a2c`

```json
{
  "ok": true,
  "data": {
    "cliente": { "id": "...", "nombre_completo": "Juan Pérez", "...": "..." },
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

Es exactamente lo que muestra la tarjeta "Saldo total en quetzales" y la lista "Mis cuentas". Una cuenta bloqueada muestra saldo disponible 0.

### 4.4 Movimientos de una cuenta — `GET /cuentas/{id}/movimientos?cliente_ref=...&page=1`

Lista paginada (50 por página, más recientes primero). Cada movimiento: `importe_centavos` (negativo = salida), `saldo_centavos` (saldo después del movimiento), `concepto`, `tipo` (`transferencia`, `deposito`, `retiro`, `pago_tarjeta`, `planilla`, `interes_ahorro`…), `transaccion_id` y `created_at`.

### 4.5 Abrir otra cuenta — `POST /cuentas`

```json
{ "cliente_ref": "usr_8f3a2c", "cliente_id": "6716a3f0c2b1e4a5d6f7a8b9", "producto": "monetaria", "nombre": "Mis gastos" }
```

`producto`: `ahorro` o `monetaria`. `nombre` es opcional. Se abre con Q0.00 y responde 201 con la cuenta (incluye `numero` de 10 dígitos).

### 4.6 Transferir — `POST /transferencias`

Para **"Otra cuenta" y beneficiarios** envía el número de 10 dígitos que escribe el cliente:

```json
{
  "cliente_ref": "usr_8f3a2c",
  "origen_id": "6716a3f0c2b1e4a5d6f7a8c0",
  "destino_numero": "2738729879",
  "monto": "150.00",
  "concepto": "Pago de almuerzo"
}
```

Para **"Mis cuentas"** puedes enviar `destino_id` (el `id` de la cuenta) en lugar de `destino_numero`; nunca ambos. Respuesta 201:

```json
{ "ok": true, "data": { "transaccion_id": "b3e1…", "monto": "150.00", "moneda": "GTQ", "estado": "completada" } }
```

`concepto` va de 3 a 160 caracteres (si el cliente lo deja vacío, manda uno por defecto, por ejemplo "Transferencia"). Errores: `409 INSUFFICIENT_FUNDS`, `409 ACCOUNT_BLOCKED`, `404 ACCOUNT_NOT_FOUND` (número inexistente), `403` (la cuenta de origen no es del cliente), `422 SAME_ACCOUNT`. Solo hay transferencias dentro del banco (no interbancarias).

### 4.7 Catálogo — `GET /productos`

Lista paginada con `code`, `name`, `type` (`ahorro`, `monetaria`, `debito`, `credito`, `prestamo`), `category` (`clasica`, `oro`, `platino`), `annual_bps` (tasa anual en puntos base: 3600 = 36 %), `max_limit_cents` y `active`. Códigos: `ahorro`, `monetaria`, `debito`, `credito-clasica`, `credito-oro`, `credito-platino`, `credito-personal`, `credito-vehicular`, `credito-hipotecario`.

### 4.8 Productos de crédito: aceptar la oferta — `POST /banca/gestiones`

Las tarjetas de crédito y los préstamos pasan primero por **CP Analítica**, que registra su evaluación en Gestiones con un `evaluacion_ref`. Cuando el cliente acepta la oferta en Banca:

```json
{
  "cliente_ref": "usr_8f3a2c",
  "gestion_ref": "100029",
  "evaluacion_ref": "eval-100029",
  "referencia_aceptacion": "consent-100029",
  "aceptada_en": "2026-10-20T11:30:00-06:00"
}
```

- `gestion_ref`: tu número de gestión. `evaluacion_ref`: el que te dio Analítica. `referencia_aceptacion`: identificador de la aceptación del cliente. `aceptada_en`: fecha y hora con zona, sin milisegundos.
- Responde 201 con la gestión en estado `en_tramite`; **guarda `data.id`**. En ese momento el operador del banco recibe una alerta, revisa y asigna el límite.
- Errores: `409 MANAGEMENT_IN_PROGRESS` ("Ya tienes una gestión en curso"), `409 ASSESSMENT_ALREADY_USED`, `409 RISK_APPROVAL_REQUIRED` (la evaluación fue rechazada), `404` (no existe la evaluación para ese cliente).

### 4.9 Tarjeta de débito — `POST /solicitudes`

```json
{ "cliente_ref": "usr_8f3a2c", "cliente_id": "6716a3f0c2b1e4a5d6f7a8b9", "producto": "debito", "cuenta_id": "6716a3f0c2b1e4a5d6f7a8c0" }
```

Queda en `aprobada`. Para registrar que el cliente acepta, usa la transición del punto 4.10 con `"estado": "aceptada"`. Después el banco la pasa a trámite y la emite.

### 4.10 Gestiones (seguimiento)

- `GET /solicitudes?cliente_ref=...` → lista de gestiones del cliente. Campos útiles: `id`, `folio`, `producto`, `tipo`, `estado`, `version`, `limite_aprobado_centavos`, `limite_asignado_centavos`, `tarjeta_id` / `credito_id`, `created_at`. Filtro opcional `&estado=en_tramite`.
- `GET /solicitudes/{id}?cliente_ref=...` → `data.solicitud` y `data.historial` (cada cambio de estado con fecha y nota, ideal para la línea de tiempo "Enviada → … → Producto emitido").
- Estados: `en_evaluacion`, `revision_manual`, `aprobada`, `aceptada`, `en_tramite`, `emitida`, `rechazada`, `cancelada`.
- El cliente solo puede **aceptar o cancelar** una gestión `aprobada`: `POST /solicitudes/{id}/transiciones` con `{"cliente_ref":"...","version":1,"estado":"aceptada","nota":"Aceptada por el cliente en Banca Virtual","referencia_aceptacion":"consent-123"}` (`version` = la actual de la gestión; si cambió, recibes `409 STALE_VERSION`: vuelve a consultarla).
- `GET /creditos?cliente_ref=...` → préstamos autorizados (personal, vehicular, hipotecario) con `referencia`, `limite_centavos` y `estado`.

### 4.11 Mis tarjetas

- `GET /tarjetas?cliente_ref=...` → `referencia`, `ultimos_cuatro`, `tipo` (`credito`/`debito`), `categoria`, `estado`, `limite_centavos`, `principal_centavos` + `interes_centavos` (= deuda actual). Crédito disponible = `limite_centavos - principal_centavos - interes_centavos`.
- `GET /tarjetas/{id}/estados-cuenta?cliente_ref=...` → cortes con `fecha_corte`, `fecha_pago`, `saldo_centavos`, `minimo_centavos`, `pagado_centavos`.
- Pagar: `POST /tarjetas/{id}/pagos` con `{"cliente_ref":"...","origen_id":"<cuenta del cliente>","monto":"250.00"}`. No puede exceder la deuda (`422 PAYMENT_EXCEEDS_DEBT`).
- Bloquear / desbloquear: `PATCH /tarjetas/{id}/estado` con `{"cliente_ref":"...","estado":"bloqueada","nota":"Bloqueo solicitado por el cliente"}` (o `"activa"`).

### 4.12 Mi perfil

- `GET /banca/perfil?cliente_ref=...` → mismo objeto `cliente` del registro (incluye `perfil_version`).
- `PATCH /banca/perfil/finanzas` (con `Idempotency-Key`):

```json
{ "cliente_ref": "usr_8f3a2c", "perfil_version": 1, "tipo_empleo": "Independiente", "ingresos_mensuales_q": "6500.25", "antiguedad_laboral_meses": 36 }
```

Envía la `perfil_version` que consultaste; si otro cambio se adelantó recibes `409 STALE_PROFILE`. Nombre, DPI, correo y fecha de nacimiento no se editan aquí.

### 4.13 Avisos al banco (opcional) — `POST /banca/eventos`

El registro y la aceptación de gestiones ya crean alertas automáticamente. Este endpoint es para avisos adicionales:

```json
{ "evento_id": "evt-0001", "tipo": "registro.iniciado", "referencia": "registro-0001" }
```

Tipos: `registro.iniciado` (sin `cliente_ref`), `cliente.registrado`, `cuenta.abierta`, `gestion.en_tramite` (estos tres con `cliente_ref` y como `referencia` el `id` en Gestiones).

---

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
{ "cliente_ref": "usr_8f3a2c", "origen_id": "6716a3f0c2b1e4a5d6f7a8c0", "servicio": "eegsa", "referencia_servicio": "NIS-1234", "monto": "125.50" }
```

- `origen_id`: la cuenta del cliente que eligió en "Pagar con".
- `referencia_servicio`: el número que escribió el cliente (4 a 40 letras, números o guiones; se guarda en mayúsculas).
- No se aceptan otros campos.

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

**Historial de pagos:** `GET /banca/pagos-servicios?cliente_ref=...&page=1` → lista paginada (25 por página, más recientes primero) con los mismos campos del comprobante.

> Es una simulación académica: el dinero sale de la cuenta del cliente hacia una cuenta interna de liquidación del banco; no se conecta con las empresas reales.

### 4.15 Seguridad: sesiones activas

Usan el encabezado `X-Cliente-Token` con el `token_cliente` del login (además del token de integración).

- `GET /banca/auth/sesiones` → sesiones vigentes del cliente:

```json
{ "ok": true, "data": [
  { "id": "6716c2…", "actual": true, "dispositivo": "Chrome en Windows", "ip": "203.0.113.7",
    "inicio": "2026-10-20T16:55:00+00:00", "ultima_actividad": "2026-10-20T17:00:00+00:00", "vence": "2026-10-20T17:55:00+00:00" }
] }
```

  `actual: true` marca la sesión desde la que consultas (la etiqueta "Esta sesión"). `ultima_actividad` se actualiza cada vez que llamas a `GET /banca/auth/sesion` (como máximo una vez por minuto).
- `POST /banca/auth/sesiones/{id}/revocar` → cierra esa sesión (`data.era_actual` indica si era la tuya). Solo puede cerrar sesiones del mismo cliente.

### 4.16 Seguridad: cambiar contraseña

`POST /banca/auth/contrasena` con `X-Cliente-Token` (sin `Idempotency-Key`):

```json
{ "contrasena_actual": "EjemploSeguro123", "contrasena_nueva": "NuevaClave2026x" }
```

- La nueva sigue la regla del registro: 12 a 72 caracteres, con mayúscula, minúscula y número, y distinta de la actual.
- Respuesta 200: `{ "contrasena_actualizada": true, "sesiones_cerradas": 1 }`. **Se cierran las demás sesiones del cliente**; la actual sigue activa.
- Si la actual es incorrecta: `422` con `errors.contrasena_actual`. Cuenta como intento fallido: 5 errores bloquean al cliente 15 minutos (igual que el login). Límite: 5 intentos por minuto por sesión.
- El campo "Repite la contraseña nueva" de la pantalla se valida en Banca; no lo envíes.

### 4.17 Confirmar una cuenta destino

`GET /banca/cuentas/destino?cliente_ref=usr_8f3a2c&numero=2000000002`: úsalo al **agregar un beneficiario** o antes de confirmar una transferencia, para mostrar a quién se le enviará el dinero:

```json
{ "ok": true, "data": { "numero": "2000000002", "numero_formateado": "2-000-000002", "tipo": "ahorro", "moneda": "GTQ", "titular": "María L.", "propia": false } }
```

Solo muestra el primer nombre y la inicial del apellido; nunca saldos ni datos personales. `propia: true` indica que la cuenta es del mismo cliente. Si no existe o está bloqueada: `404 ACCOUNT_NOT_FOUND`.

---

## 5. Ejemplo de código (servidor en JavaScript)

Funciona en funciones de servidor de Vercel, rutas API de Next.js o Node 18+:

```js
// Solo en el servidor. CP_GESTIONES_TOKEN se configura en las variables de entorno (no públicas).
const BASE = 'https://cp-gestiones-api.onrender.com/api/v1'

export async function gestiones(method, path, body, idempotencyKey) {
  const response = await fetch(BASE + path, {
    method,
    headers: {
      Authorization: `Bearer ${process.env.CP_GESTIONES_TOKEN}`,
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : {}),
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

// Uso:
const resumen = await gestiones('GET', `/banca/resumen?cliente_ref=${encodeURIComponent(usuario.id)}`)
const transferencia = await gestiones('POST', '/transferencias', {
  cliente_ref: usuario.id, origen_id: cuentaId, destino_numero: '2738729879', monto: '150.00', concepto: 'Pago de almuerzo',
}, crypto.randomUUID()) // guarda esta clave si vas a reintentar
```

---

## 6. Para empezar a probar

1. Pide el token al administrador de CP Gestiones y guárdalo en tu servidor como `CP_GESTIONES_TOKEN`.
2. Prueba `GET /health` y luego registra un cliente de prueba con `POST /banca/registro`.
3. Inicia sesión con ese cliente (`POST /banca/auth/login`) y muestra su `GET /banca/resumen` en Inicio.
4. Para tener saldo de prueba, el cajero del banco hace un depósito desde el panel de Gestiones (Cuentas → Depósito o retiro) a la cuenta del cliente.
5. Prueba una transferencia entre dos clientes de prueba por número de cuenta.

Si una respuesta no es la esperada, comparte el `request_id` que viene en el error: con él se encuentra la petición en los registros del servidor.
