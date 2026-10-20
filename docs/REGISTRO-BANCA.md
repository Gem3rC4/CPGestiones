# Registro desde Banca Virtual — API v1.2

Contrato actualizado el 7 de octubre de 2026, basado en el JSON del formulario facilitado por el equipo. El registro y la apertura se verifican con MongoDB real en pruebas aisladas. La conexión al servidor de Banca Virtual aún debe realizarla su responsable.

## Qué debe integrar tu compañero

**Gestiones guarda y valida las contraseñas de clientes**, según la decisión actualizada del equipo. El registro crea identidad, hash de contraseña y primera cuenta de ahorro con Q0.00. No crea un usuario del portal administrativo. Después se inicia sesión por la API de acceso de clientes.

El navegador envía a **su propio servidor de Banca Virtual** el formulario actual, incluida `contrasena`. Ese servidor asigna una referencia persistente y llama a Gestiones enviando los campos siguientes, incluida la contraseña por HTTPS. Banca no conserva otra base maestra de credenciales. No basta con cambiar una URL en el navegador: la credencial de integración debe permanecer en el servidor.

| Método y ruta, bajo `/api/v1` | Uso |
| --- | --- |
| `POST /banca/registro` | Crear cliente y primera cuenta de ahorro en una transacción. |
| `POST /banca/auth/login` | Validar credenciales del cliente y emitir su token de sesión. |
| `GET /banca/auth/sesion` | Comprobar la sesión central con `X-Cliente-Token`. |
| `POST /banca/auth/logout` | Revocar la sesión central del cliente. |
| `GET /banca/perfil?cliente_ref=...` | Mostrar identidad y datos financieros del cliente. |
| `PATCH /banca/perfil/finanzas` | Guardar ingresos, tipo de empleo y antigüedad. |
| `GET /banca/resumen?cliente_ref=...` | Mostrar cuentas, saldos y cantidad de cuentas en Inicio. |

Base local: `http://127.0.0.1:8001/api/v1`. Esta dirección solo sirve en la computadora que ejecuta Gestiones. La URL HTTPS pública se definirá al desplegar. No se han incluido tokens reales en el código ni en Postman.

## Petición de registro

```http
POST /api/v1/banca/registro
Authorization: Bearer TOKEN_DE_SERVICIO_BANCA
Accept: application/json
Content-Type: application/json
Idempotency-Key: registro-banca-juan-001
```

```json
{
  "cliente_ref": "banca-juan-001",
  "nombre": "Juan",
  "apellido": "Pérez",
  "correo_electronico": "juan.perez@ejemplo.com",
  "contrasena": "EjemploSeguro123!",
  "dpi": "1234567890123",
  "nit": "1234567-8",
  "fecha_nacimiento": "25/10/1990",
  "tipo_empleo": "Asalariado",
  "ingresos_mensuales_q": 5000.00,
  "antiguedad_laboral_meses": 24
}
```

Los campos del ejemplo son obligatorios. El servidor **conserva `contrasena` y agrega `cliente_ref`** al formulario compartido. Usa una lista explícita de campos. Si llegan roles, saldos, `password` u otros campos fuera del contrato, Gestiones responde 422. `contrasena` requiere 12 caracteres, mayúscula, minúscula y número; máximo 72 bytes, sin caracteres nulos. Se guarda como hash bcrypt, jamás en respuestas, bitácoras ni resultados de idempotencia. El fingerprint de idempotencia protege la contraseña con HMAC.

`cliente_ref` debe ser el ID estable del usuario en Banca Virtual, por ejemplo un UUID o ID convertido a texto; no utilices correo ni DPI como ID mutable. Admite hasta 100 caracteres de letras ASCII, números y `_.:@-`, empezando con letra o número. Banca debe obtener esta referencia del registro de usuario que controla su servidor; para consultas posteriores, de su sesión autenticada. La referencia por sí sola no autentica a nadie. Gestiones confía en la identidad que declara el servidor autorizado de banca.

### Validaciones y formatos

| Campo | Regla y persistencia |
| --- | --- |
| `nombre`, `apellido` | Letras Unicode, espacios, apóstrofos y guiones; máximo 75 y 74 caracteres. Se guardan separados y también como nombre completo para el portal. |
| `correo_electronico` | Correo válido, máximo 180 caracteres; normalización a minúsculas y unicidad. |
| `dpi` | Texto de exactamente 13 dígitos, único. Se cifra al guardarlo y se indexa una huella HMAC para detectar duplicados. Las respuestas de banca muestran solo los últimos cuatro dígitos. |
| `nit` | Texto: parte numérica de 1–12 dígitos y un carácter final numérico o K, con guion opcional. Se normaliza K a mayúscula. |
| `fecha_nacimiento` | Fecha real `dd/mm/aaaa` o `aaaa-mm-dd`, desde 1900-01-01. Política académica de registro: al menos 18 años cumplidos, según fecha de Guatemala. Se devuelve como ISO `aaaa-mm-dd`. |
| `tipo_empleo` | Asalariado o Independiente, sin distinguir mayúsculas. Persistido como código en minúscula y presentado con inicial mayúscula. |
| `ingresos_mensuales_q` | Número JSON o texto decimal, de 0.00 a 1000000000.00, máximo dos decimales. La respuesta ofrece texto decimal y centavos enteros. No acepta negativos ni separadores de miles. |
| `antiguedad_laboral_meses` | Entero de 0 a 1500, además no puede superar la edad del cliente en meses. No acredita que exista esa relación laboral. |

Las validaciones de DPI y NIT comprueban formato, no identidad ante RENAP/SAT ni dígitos verificadores oficiales. Los datos financieros son declaraciones del cliente, no información corroborada. El formulario no informa deuda: se guarda `deuda_centavos: null` y `deuda_declarada: false`; **no se interpreta un dato ausente como deuda cero ni como aprobación crediticia**. Analítica debe solicitar la información faltante para evaluar.

## Respuesta 201

Ejemplo ilustrativo; los identificadores y el número los genera Gestiones:

```json
{
  "ok": true,
  "data": {
    "cliente": {
      "id": "0123456789abcdef01234567",
      "cliente_ref": "banca-juan-001",
      "nombre": "Juan",
      "apellido": "Pérez",
      "nombre_completo": "Juan Pérez",
      "correo_electronico": "juan.perez@ejemplo.com",
      "dpi_enmascarado": "*********0123",
      "nit": "1234567-8",
      "fecha_nacimiento": "1990-10-25",
      "tipo_empleo": "Asalariado",
      "ingresos_mensuales_q": "5000.00",
      "ingreso_centavos": 500000,
      "antiguedad_laboral_meses": 24,
      "deuda_declarada": false,
      "perfil_version": 1,
      "perfil_completo": true
    },
    "cuenta": {
      "id": "1123456789abcdef01234567",
      "numero": "2738729879",
      "numero_formateado": "2-738-729879",
      "nombre": "Mi cuenta de ahorro",
      "tipo": "ahorro",
      "moneda": "GTQ",
      "estado": "activa",
      "saldo_centavos": 0,
      "saldo_disponible_centavos": 0,
      "saldo": "0.00",
      "saldo_disponible": "0.00"
    },
    "resumen": {
      "moneda": "GTQ",
      "cantidad_cuentas": 1,
      "saldo_total_centavos": 0,
      "saldo_disponible_centavos": 0,
      "saldo_total": "0.00",
      "saldo_disponible": "0.00"
    }
  }
}
```

El registro crea el cliente, la cuenta y los eventos de seguridad junto con el resultado de idempotencia. Si cualquiera falla, todo se revierte. No crea depósitos, tarjetas ni evaluaciones. El número nuevo tiene diez dígitos y es único; guárdalo como texto. Usa `id` para operar contra las rutas existentes que reciben un identificador interno.

`GET /banca/perfil` devuelve el mismo objeto cliente directamente en `data`. `GET /banca/resumen` devuelve `data.cliente`, `data.cuentas` (arreglo) y `data.resumen`. Una cuenta bloqueada conserva su saldo contable pero su saldo disponible se presenta en cero. Solo se contemplan GTQ y cuentas de ese cliente; no se mezclan con caja ni con otros titulares.

## Actualización financiera

```http
PATCH /api/v1/banca/perfil/finanzas
Authorization: Bearer TOKEN_DE_SERVICIO_BANCA
Content-Type: application/json
Accept: application/json
Idempotency-Key: perfil-banca-juan-001-cambio-01
```

```json
{
  "cliente_ref": "banca-juan-001",
  "perfil_version": 1,
  "tipo_empleo": "Independiente",
  "ingresos_mensuales_q": 6500.25,
  "antiguedad_laboral_meses": 36
}
```

Devuelve 200 con el perfil en `data` y `perfil_version: 2`. La versión debe ser la última consultada. Si otro guardado se adelantó, se responde 409 `STALE_PROFILE`: consultar el perfil y pedir que el usuario revise sus cambios antes de realizar otra operación. No admite cambios de nombre, DPI, correo, fecha de nacimiento ni cuentas desde esta ruta.

## Reintentos y duplicados

1. Banca conserva una operación **pendiente** con referencia y clave estables. Gestiones es la fuente maestra del cliente y su contraseña. El formulario público de Banca necesita protección contra abusos. No guardar la contraseña en logs, archivos de operaciones pendientes ni almacenamiento del navegador.
2. Su servidor llama a Gestiones con los campos del contrato y la contraseña recibida. Solo confirma la apertura tras recibir 201 y conservar los IDs de integración. Después usa el login central. No registra el cuerpo del registro/login en logs.
3. Ante un timeout, 503 o conflicto concurrente `CONFLICT`, conserva la misma referencia y clave. Reintenta con los mismos datos dentro de la petición; si se perdió la contraseña en memoria, pide que el cliente vuelva a ingresarla. No la almacenes para un trabajo diferido. Un reintento idéntico tras una confirmación devuelve el mismo resultado, sin abrir otra cuenta. La normalización permite repetir ingresos numéricos como texto y fechas equivalentes.
4. Si el usuario corrige datos después de un error 422, crea una nueva operación con nueva clave. Si hubo un timeout ambiguo, resuelve primero la operación original; no generes otra identidad.
5. Un 409 `CUSTOMER_EXISTS` no autoriza a vincularse al cliente existente. Detén el alta para revisión o recuperación de acceso coordinada con Gestiones; no intentes recuperar titularidad por coincidencia de correo o DPI.

La transacción cubre MongoDB de Gestiones. **No existe una transacción distribuida con la base de Banca Virtual.** Si Banca falla al guardar los IDs tras el 201, debe recuperar el mismo resultado reintentando con la misma operación, sin borrar cuentas de Gestiones. No guardes copias de los saldos como fuente de verdad: consúltalos desde el resumen.

La respuesta de un reintento de registro es la fotografía original de apertura. Para ver saldos o perfil posteriores, usa los GET. El orden de propiedades de los objetos JSON no tiene significado.

## Errores que debe manejar el formulario

```json
{
  "ok": false,
  "message": "Revisa los campos indicados.",
  "code": "VALIDATION_ERROR",
  "errors": {
    "fecha_nacimiento": ["Usa una fecha real en formato dd/mm/aaaa o aaaa-mm-dd."]
  },
  "request_id": "identificador-de-seguimiento"
}
```

| HTTP / código | Acción del consumidor |
| --- | --- |
| 401 | En registro: revisar credencial del servidor. En login: `INVALID_CREDENTIALS` indica contraseña incorrecta o acceso bloqueado. En sesión: `CUSTOMER_SESSION_EXPIRED` indica vencimiento/revocación. |
| 403 `BANK_SERVICE_REQUIRED` o permiso denegado | Usar exclusivamente el token `banca` con los permisos correspondientes. Un administrador o riesgos no puede usar estas rutas. |
| 404 | La referencia no corresponde a un cliente de Gestiones. |
| 409 `CUSTOMER_EXISTS` | Alta duplicada. No se devuelve la identidad ajena ni se cambia la vinculación. |
| 409 `IDEMPOTENCY_MISMATCH` | La clave ya corresponde a otros datos u otra ruta. No repetirla para una operación distinta. |
| 409 `PRODUCT_UNAVAILABLE` | El catálogo no permite abrir ahorro. No se confirmó ningún cliente parcial. |
| 409 `STALE_PROFILE` / `PROFILE_INCOMPLETE` | Recargar versión o completar identidad anterior mediante revisión del banco. |
| 409 `CONFLICT` | Colisión de índice o petición concurrente. Reintentar de forma limitada con la misma clave. |
| 422 | Mostrar `errors` por campo. `campos_no_admitidos` señala datos fuera del contrato; `IDEMPOTENCY_REQUIRED` exige el encabezado. |
| 429 | Respetar `Retry-After`. Límite actual: 180 peticiones/minuto por cliente (`cliente_ref` o `X-Cliente-Token`) y 1800 peticiones/minuto en total para la integración. |
| 500 / 503 o timeout | No mostrar éxito; conservar operación pendiente, referencia y clave para conciliación/reintento. |

## Credencial, ejemplo y Postman

En Gestiones, el responsable autorizado ejecuta `php artisan cp:token banca`. Caduca a los 30 días. Guárdala en una variable privada del **backend** de banca, nunca en variables `VITE_`, `NEXT_PUBLIC_`, bundles del navegador ni repositorios.

El archivo [registro-cliente.servidor.mjs](ejemplos/registro-cliente.servidor.mjs) contiene un adaptador de ejemplo para Node con `fetch`. Muestra la selección explícita de campos, timeout y tratamiento de respuestas. No implementa la interfaz ni las cookies de sesión de tu compañero; debe llamarse con una referencia y clave persistentes, manteniendo la contraseña solo en memoria durante la petición. El mismo contrato se puede consumir desde PHP, C# u otro backend.

En [CP-Gestiones.postman_collection.json](CP-Gestiones.postman_collection.json), abre el grupo **banca** y configura `baseUrl`, `bankToken`, `cliente_ref` y `idempotencyKey`. Cambia la clave para cada operación nueva y consérvala para reintentos. El registro usa la variable privada `customerPassword`; el login guarda `customerToken` para consultar/cerrar esa sesión. Usa una base de pruebas; ejecutar el POST sí crea un cliente y una cuenta.

## Actualizar una instalación anterior

Haz un respaldo y conserva la `APP_KEY` original. Detén peticiones y trabajadores durante la actualización; el comando no activa mantenimiento por sí mismo. En `backend`:

```text
php artisan cp:upgrade-registration
```

El comando comprueba primero duplicados de DPI, correo normalizado y referencia. Si encuentra colisiones, se detiene antes de escribir; no fusiona personas automáticamente. Después prepara índices y cifra los DPI anteriores, conservando `_id`, vínculos, cuentas y saldos. Puede ejecutarse otra vez. Si se interrumpe entre clientes, las escrituras ya realizadas son completas y el comando continúa con los restantes al repetirlo.

Los índices únicos de `dpi_hash`, `email` y `external_ref` protegen los registros simultáneos. Para una base nueva, `php artisan cp:install` los crea desde el inicio. No regeneres `APP_KEY` en una base existente: se necesita para descifrar DPI y calcular sus huellas. Una rotación requiere una migración específica.

No se inventan nombres separados, fecha de nacimiento o antigüedad para clientes anteriores: se devuelven como `null` y `perfil_completo: false`. Su actualización financiera devuelve `PROFILE_INCOMPLETE`. Tampoco se renumeran cuentas existentes con prefijo `CP`; si se utilizan en banca, su interfaz deberá conservar esos identificadores o migrarlos mediante un proceso posterior.

Las respuestas generales de clientes ahora omiten el DPI completo y exponen `dpi_enmascarado`; el panel fue adaptado. La consulta protegida de pendientes de Analítica conserva acceso explícito al DPI y añade fecha, empleo y antigüedad para su propósito de evaluación. No se reescriben copias históricas, backups o respuestas de idempotencia antiguas que pudieran contener datos previos; hay que considerar esas copias en cualquier migración posterior de protección de datos.

## Correspondencia con las pantallas compartidas

| Pantalla | Cobertura actual |
| --- | --- |
| Registro, Mi perfil, Inicio | Alta, ahorro inicial, perfil financiero y resumen centralizados. |
| Abrir otra cuenta, transferir dentro del banco | Rutas existentes de cuentas y transferencias. "Otra cuenta" y beneficiarios envían `destino_numero` (10 dígitos); "Mis cuentas" puede usar `destino_id`. Ver INTEGRACION.md. |
| Mis tarjetas, Gestiones | Recepción de ofertas aceptadas, límite manual sujeto al máximo de Analítica, tarjetas o créditos autorizados según producto. Contrato en INTEGRACION.md; conexión entre servidores pendiente. |
| Beneficiarios, transferencias programadas, pago de servicios | No implementados por estas rutas; requieren una siguiente etapa. |
| Préstamos, plazo fijo, divisas, mapa de agencias | Existe autorización de créditos personales, vehiculares e hipotecarios; no hay desembolso, simulador, cuotas, plazo fijo, divisas ni mapa. |
| Contraseña y sesiones del cliente | Hash, login, consulta y revocación centralizados en Gestiones. MFA, cambio y recuperación de contraseña pendientes. Los usuarios del panel siguen siendo usuarios internos. |

La compatibilidad documentada corresponde a los datos de registro facilitados y a las respuestas aquí definidas. No afirma integración completa con todos los módulos de las capturas.

## Login y sesión central de clientes

Estas rutas son llamadas **entre servidores**, con `Authorization: Bearer TOKEN_BANCA`. La contraseña del cliente nunca se envía al login administrativo `/auth/login`.

```http
POST /api/v1/banca/auth/login
Content-Type: application/json
Authorization: Bearer TOKEN_BANCA
```

```json
{"correo_electronico":"juan.perez@ejemplo.com","contrasena":"EjemploSeguro123!"}
```

Responde 200 con `data.token_cliente`, `data.expires_in` (3600 segundos) y `data.cliente`. El token del cliente se guarda hasheado en `sesiones_clientes`, separado de tokens administrativos y de integración. No es un Bearer válido para el panel. Cinco fallos bloquean ese cliente 15 minutos. El login tiene límite adicional de 10 intentos por correo por minuto y no usa Idempotency-Key.

- `GET /banca/auth/sesion`: requiere token de integración y encabezado `X-Cliente-Token`. Valida vigencia y devuelve el perfil/fecha de vencimiento.
- `POST /banca/auth/logout`: mismos encabezados, revoca esa sesión; no requiere clave de idempotencia. Una sesión vencida o revocada devuelve 401.

El backend de Banca mantiene el token del cliente en su sesión del servidor, identificada mediante cookie HttpOnly/Secure/SameSite y protección CSRF adecuada. Antes de usar las APIs bancarias, valida la sesión central y toma `cliente_ref` de la identidad devuelta. No confía en referencias elegidas desde el navegador. Los endpoints de negocio siguen usando el token privado de integración y esa referencia, por lo que ese control corresponde al backend de Banca.

Los clientes anteriores sin contraseña central permanecen sin acceso central; no se inventaron claves ni se copiaron contraseñas de otro sistema. Requieren una incorporación/recuperación de credenciales con verificación de identidad. La recuperación por correo y el segundo factor aún no están implementados.

Esta versión sustituye la decisión anterior de conservar contraseñas en Banca. Como el equipo confirmó que aún no había integrado las APIs, se actualiza el contrato antes de su primera integración: ahora `contrasena` es obligatoria. Ejecuta `php artisan cp:install` para preparar las nuevas colecciones e índices; conserva la APP_KEY existente.
