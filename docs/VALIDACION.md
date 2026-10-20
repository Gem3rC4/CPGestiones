# Verificación de la entrega

Fecha: 7 de octubre de 2026. Entorno local de desarrollo, datos ficticios.

La actualización de registro también se aplicó a los seis clientes ficticios de esta instalación. Se guardó previamente una copia cifrada de clientes en `.local/`, excluida del ZIP; las cuentas se compararon antes y después y quedaron sin cambios. Se conserva la llave original de la aplicación.

## Resultado

- **PHPUnit: 44 pruebas aprobadas, 489 comprobaciones.** Se ejecutaron contra MongoDB real con replica set, en bases aisladas `cp_gestiones_<pid>_test`, `cp_gestiones_registration_<pid>_test` y `cp_gestiones_ecosystem_<pid>_test`. No se utilizaron las bases de los compañeros.
- **Frontend:** compilación de Vue/Vite completada. Se revisó en el navegador la versión compilada, no una maqueta estática.
- **Dependencias PHP:** se instaló Laravel Boost y sus dependencias de desarrollo conforme a las instrucciones locales del proyecto. No se agregaron dependencias de ejecución para registrar clientes. Se conserva `composer.lock`.
- **Código PHP:** formato aplicado con Laravel Pint; las pruebas se ejecutaron después.
- **Entorno Windows de esta sesión:** PHP pudo abrir los archivos, pero `is_readable`/`is_writable` devolvieron falso dentro del espacio de trabajo. Se utilizó el modo oficial de entrada estándar de Pint y PHPUnit con `--no-configuration tests`, autoload de su ejecutable y variables `APP_ENV=testing`, `BCRYPT_ROUNDS=4`, `CACHE_STORE=array`. No se modificaron dependencias ni permisos de Windows para evitar estas comprobaciones. Los tests conservaron sus bases aisladas y sus protecciones antes de limpiarlas.
- **Arranque:** sintaxis de PowerShell comprobada y `Iniciar-Portal.ps1 -SinNavegador` ejecutado con los servicios ya iniciados. No se ha comprobado un reinicio completo de Windows.
- **Disponibilidad:** API local `/api/v1/health` y panel local respondieron HTTP 200. El endpoint de salud es de disponibilidad del proceso; no sustituye una comprobación de MongoDB.

## Casos cubiertos

| Área | Evidencia automatizada |
| --- | --- |
| Dinero | Conversión exacta a centavos, redondeo y rechazo de flotantes, negativos, formatos ambiguos e importes fuera de rango. |
| Sesiones | Rechazo sin token, validación de campos, permisos de cajero, contraseñas ocultas, cinco fallos y bloqueo, login posterior y revocación. |
| Transferencias | Dos asientos balanceados, reintento sin duplicación, rechazo de clave usada con otro cuerpo, saldo insuficiente. |
| Reversión | Fallo en el destino después de debitar el origen: ambos saldos, movimientos e idempotencia regresan al estado anterior. |
| Concurrencia | Seis procesos PHP independientes intentaron transferir Q8 desde una cuenta con Q10: solo uno tuvo éxito; saldo final Q2. |
| Titularidad | Banca no puede consultar ni debitar cuentas de otro cliente usando una referencia distinta. |
| Tarjetas | Solo riesgos decide, no se saltan etapas, aceptación con referencia, emisión única y límite aprobado conservado. |
| Bitácora | Cadena válida y detección de modificación directa de un evento en la base de pruebas. |
| Planillas | Prohibición de autoaprobación, aislamiento por empresa, reversión de todo el lote por saldo insuficiente y reintento sin pagar dos veces. |
| Crédito | Devengo diario exacto, corte único, mínimo calculado, pago aplicado primero a interés y reintento sin duplicación. |
| Ahorro | Fondo de intereses insuficiente revierte el abono; al fondearlo, el reintento genera un solo devengo y un solo par de movimientos. |
| Eventos | Respuesta 503 deja pendiente, respeta espera, reintenta con el mismo ID y firma el cuerpo exacto; HTTP 200 lo marca enviado. Se utilizó un receptor HTTP simulado, sin contactar servicios externos. |
| Acceso central | Contraseña hasheada y oculta, sesiones separadas y revocables, vencimiento, bloqueo, rechazo de clientes sin contraseña y entradas inválidas; espacios preservados y máximo de 72 bytes. |
| Gestiones aceptadas | Solo Analítica registra su máximo, evaluación inmutable, consentimiento obligatorio y llegada en trámite. Duplicados no generan otra gestión ni alerta. |
| Límite manual | Debe indicarlo el operador autorizado; omisión, exceso, versión antigua y roles no autorizados se rechazan. Emisión única y máximo de Analítica conservado. |
| Préstamos autorizados | Crean un crédito separado, sin tarjeta ni cambio de saldo bancario. |
| Alertas | Visibilidad por permiso, lectura por empleado y solo de IDs indicados; recepción deduplicada y rechazo de eventos que pretenden completar operaciones. |
| Salud | Respuestas públicas mínimas en JSON, sin exponer configuración o secretos. |
| Registro de banca | JSON proporcionado, fecha ISO o día/mes/año, ingresos numéricos o texto y apertura de un único ahorro con saldo cero; no crea usuario administrativo ni token de cliente. |
| Identidad | DPI cifrado, huella única, enmascaramiento, rechazo de correo/DPI/referencia duplicados y de campos ajenos al contrato como password, rol o saldo. |
| Validación de registro | Fechas imposibles, minoría de edad, empleo, antigüedad incoherente, precisión/rango de ingresos y tipos inválidos; también cumpleaños 18 e ingreso cero. |
| Registro concurrente | Cuatro procesos independientes con claves distintas y la misma identidad: exactamente un cliente, una cuenta, un resultado confirmado y dos eventos de seguridad. |
| Reversión del alta | Producto ahorro desactivado después de iniciar el alta: ningún cliente, cuenta, evento ni clave parcial queda confirmado. Un reintento posterior puede completarse. |
| Perfil financiero | Actualización con versión, reintento equivalente sin duplicación, rechazo de versión antigua y de cambios de identidad. |
| Resumen | Solo cuentas del cliente indicado; cuenta bloqueada muestra disponible cero y conserva saldo contable. |
| Actualización de datos anteriores | Cifrado y normalización repetibles sin cambiar IDs, números ni saldos; colisiones de correos normalizados detienen el proceso antes de escribir. |

Estas pruebas cubren escenarios concretos; no equivalen a una auditoría bancaria, una certificación de seguridad ni una prueba de carga masiva.

## Contratos y archivo de entrega

Se verifican automáticamente la lectura JSON de OpenAPI y Postman, la igualdad de las 51 combinaciones método/ruta frente a Laravel, identificadores de operación únicos y referencias locales del contrato. Registro/login, recepción de gestiones, evaluaciones y alertas incluyen esquemas de entrada y ejemplos de consumo con su credencial correspondiente; algunas respuestas de operaciones generales conservan el esquema genérico de éxito. No se ejecutó un validador formal completo de OpenAPI ni se importó la colección en una instalación de Postman. El adaptador de ejemplo pasó la comprobación de sintaxis de Node.

El ZIP contiene código, archivos de versiones, configuración de ejemplo, documentación y capturas. Excluye `.env`, credenciales locales, bases, registros, cachés, dependencias instaladas y herramientas portátiles. El empaquetado verifica integridad ZIP y ausencia de la llave de aplicación y contraseña de esta demostración.

## Revisión visual

Se comprobaron el inicio de sesión y la navegación en la versión compilada. El módulo muestra **Gestiones**, **Nueva gestión** y exactamente las opciones **Todos los estados / En trámite / Emitida / Rechazada**. El filtro En trámite devuelve cero coincidencias en esta base; al volver a Todos se recuperan las cuatro gestiones históricas en evaluación, sin alterar sus estados.

La campana muestra el contador, la actualización cada cinco segundos y el estado vacío. El detalle muestra **DETALLE DE GESTIONES**, el máximo de Analítica pendiente y la integridad del historial. No aparecieron errores ni advertencias en la consola capturada de esta revisión. `gestiones-alertas.jpg` documenta la vista actual; `acceso.jpg` y `vista-previa.jpg` corresponden a la revisión anterior.

No se añadieron eventos ficticios a la base del portal para llenar la campana. La recepción de alertas y las decisiones de emisión se verificaron con las pruebas de integración aisladas. La prueba conjunta con los sistemas de los compañeros sigue pendiente.

La revisión automática de permisos bloqueó el envío de una solicitud desde el navegador al clasificarlo como operación financiera. No se reintentó esa acción por otra vía. La prueba visual de envío queda para el usuario; los casos de escritura del backend se comprobaron mediante la suite aislada. No se afirma una prueba visual completa de todos los formularios ni de todos los tamaños de pantalla.

## Límites de esta verificación

- Las API de CP Analítica y CP Banca Virtual no están conectadas ni probadas entre servidores. El registro sí se adaptó al JSON proporcionado; falta que Banca Virtual integre la llamada con una credencial privada. La página pública de registro solo se consultó para identificar campos, sin enviar formularios.
- Docker Compose está preparado, pero no se ejecutó porque Docker Desktop no estaba activo. Se utilizó MongoDB local independiente en el puerto 27028.
- El modo de desarrollo con recarga automática no forma parte de la comprobación final; se verificó la compilación y su servidor de vista previa.
- El programador de tareas no queda ejecutándose al abrir el panel. Inícialo con `php artisan schedule:work` para ejecutar planillas, eventos y cierres. Los servicios de negocio se probaron de forma directa, sin esperar al horario nocturno.
- No se desplegó en Internet ni se conectó Atlas. No se probaron fallos físicos de disco, caída completa del replica set, restauración de respaldos ni carga sostenida de cientos de usuarios.
- Se incluyen reglas académicas de intereses y crédito, referencias de tarjeta simuladas y transferencias dentro del banco. No hay emisión de tarjetas reales, red interbancaria, buró crediticio ni desembolso, amortización o cobranza de préstamos. Los créditos personales, vehiculares e hipotecarios se guardan únicamente como autorizaciones, separados de tarjetas y sin abonar dinero.

## Reproducir

Desde `backend`, con PHP y MongoDB configurados:

```text
php vendor/phpunit/phpunit/phpunit
```

Desde `frontend`:

```text
npm ci
npm run build
npm run preview
```

Consulta `README.md` para instalación y arranque; `DECISIONES.md` explica las reglas y sus límites.
