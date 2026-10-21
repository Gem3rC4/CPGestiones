# Reglas y alcance

## Responsabilidad de cada sistema

CP Gestiones conserva identidades operativas, clientes, catálogo, cuentas, movimientos, solicitudes, tarjetas y planillas. CP Analítica determina el resultado de riesgo. Banca Virtual presenta registro/login; Gestiones valida credenciales, conserva sus hashes y emite sesiones de cliente separadas de los empleados. Banca solicita operaciones desde su servidor. Ningún frontend recibe credenciales de integración ni acceso a MongoDB.

La API es una propuesta de contrato versionada. Las rutas existentes de los otros sistemas todavía se desconocen. Un cambio compatible se añade a `/api/v1`; un cambio incompatible requiere otra versión o un adaptador explícito. No se debe compartir directamente la base entre los proyectos.

## Registro de clientes

La decisión del equipo del 7 de octubre centraliza contraseñas y login en Gestiones. `/banca/registro` admite el formulario con `contrasena` y agrega `cliente_ref`. Cliente, hash bcrypt, ahorro inicial con saldo cero, alerta, actividad e idempotencia se confirman juntos. `/banca/auth/login` valida la contraseña y emite una sesión separada de clientes; nunca crea roles internos. Las rutas `/banca` exigen la identidad del servidor `banca`.

El DPI se cifra con la llave de la aplicación y su huella HMAC sirve para unicidad. Las respuestas de banca y del directorio lo enmascaran. Analítica accede al documento completo solo por la consulta autorizada de evaluación. El correo normalizado y la referencia también son únicos. La versión del perfil evita sobrescribir un cambio financiero concurrente. Una deuda no declarada se representa con `null`, no con cero. La guía `REGISTRO-BANCA.md` documenta validaciones académicas, actualización de datos anteriores y límites del contrato.

## Dinero y consistencia

Una transferencia registra su clave de repetición, comprueba y debita el origen, acredita el destino e inserta ambos asientos dentro de una transacción MongoDB. Un error revierte todo. Los índices únicos protegen claves, números de cuenta, solicitudes activas y emisiones.

Se requiere replica set o clúster compatible con transacciones. No hay fallback a escrituras sin transacción. El replica set de un nodo sirve para desarrollo; no proporciona alta disponibilidad.

Las claves de repetición se conservan sin caducidad. Reusar una clave con otro cuerpo devuelve 409. Un conflicto concurrente puede responder 409: repite la misma petición con la misma clave. Después de una respuesta incierta nunca generes otra clave para el mismo movimiento.

Caja, intereses y liquidaciones (comercios, pagos de tarjeta y servicios) son cuentas internas que inician en cero. **Caja** e **intereses** son contrapartidas de dinero que entra desde fuera del libro (efectivo recibido en ventanilla y gasto de intereses del banco), por lo que pueden quedar en negativo hasta el límite del dominio: así un depósito funciona desde una instalación nueva y la suma de todos los saldos sigue siendo cero. Las cuentas de clientes y de liquidación nunca se sobregiran. `cp:demo` sigue cargando dinero ficticio a la caja para la demostración local. Las cuentas internas no se exponen como destinos de transferencias normales. La apertura `apertura_demo` no constituye un libro mayor contable completo de un banco.

## Intereses y mínimos

Son políticas académicas, no tarifas reales ni reglas legales. El catálogo inicial usa ahorro 2% anual y crédito 36%, 30% y 24% anual. Las tasas se expresan en puntos base; 100 puntos equivalen al 1%.

Crédito: `redondear(capital_en_centavos × tasa_en_puntos_base ÷ 3,650,000)`. Se usa base de 365 días y redondeo de medio centavo hacia arriba, sobre el capital pendiente al ejecutar el cierre. No se cobra interés sobre el interés. El cierre se programa a las 23:59 de Guatemala y es único por tarjeta y fecha.

El corte inicial es el día 20, con vencimiento 20 días después. Si el día configurado no existe en un mes, se usa el último. El mínimo es el menor entre la deuda y el mayor entre Q100 y el 5% de la deuda. Los pagos cubren primero intereses y después capital; su aplicación a mínimos pendientes empieza por el estado más antiguo.

Los saltos de fecha de crédito se detectan: no se calculan días históricos con el saldo actual. Un cierre omitido necesita conciliación y una política de reconstrucción histórica. No se implementan mora, seguros, comisiones, períodos de gracia, refinanciamientos ni saldo promedio diario.

Ahorro: devengo y abono diario con tasa anual / 365, desde la cuenta interna `intereses` (contrapartida que refleja el gasto acumulado del banco). Si un abono falla (por ejemplo, la cuenta superaría el límite de saldo), se revierte completo y ese día queda en `pendientes` sin afectar a las demás cuentas. Las cuentas mantienen su tasa inicial; las nuevas usan la tasa vigente del catálogo. Las tarjetas conservan una copia de la política de la solicitud. Cambiar el catálogo no altera obligaciones anteriores.

## Planillas

El operador debe pertenecer a la empresa; otro miembro con rol aprobador autoriza. Administrador y gerente tienen acceso operativo transversal según sus permisos, pero tampoco autorizan una planilla creada por sí mismos. Cada lote admite hasta 500 cuentas distintas del mismo banco y moneda.

La autorización coloca la planilla en una cola persistida en MongoDB. `cp:payrolls` trabaja fuera de la petición web y ejecuta el lote en una sola transacción. Un error de fondos o cuenta bloqueada revierte todo. Las pendientes muestran el error y se reintentan; las pagadas no se repiten. Esto funciona por procesos programados; no se presenta `QUEUE_CONNECTION=sync` como una cola asíncrona.

## Seguridad y bitácora

Se eligieron tokens opacos aleatorios, guardados mediante SHA-256, para permitir revocación inmediata. No son JWT. Cada petición revisa usuario, vigencia y permisos actuales. El panel guarda el token en memoria: recargar la página requiere ingresar otra vez.

La bitácora contiene el flujo de gestiones y aprobaciones de tarjetas y créditos. Cada evento tiene HMAC enlazado al anterior y se inserta junto al cambio de estado. El modelo y la API no permiten editarla ni eliminarla. La cadena detecta alteraciones si el atacante no posee la llave de la aplicación. Quien controle base y llave puede reconstruirla: no equivale a WORM ni a inmutabilidad absoluta. Cambiar `APP_KEY` sin migrar las firmas invalida la verificación histórica.

Antes de usar datos reales se requieren HTTPS, privilegios mínimos, auditoría externa inmutable, respaldo y recuperación, monitorización, gestión de secretos, MFA y revisión de seguridad. El limitador de tasa utiliza archivos locales; varios servidores necesitan un almacén compartido. El bloqueo por fallos sí es transaccional y está en MongoDB.

## Pendientes externos

1. Contratos y URL reales de ambos sistemas, con pruebas de extremo a extremo.
2. Integrar la autoridad de identidad ya acordada (Banca Virtual) y revisar cualquier migración de usuarios reales/referencias anteriores sin duplicados.
3. Políticas bancarias acordadas entre los equipos.
4. Alojamiento PHP, ejecución programada, almacenamiento persistente y Atlas.
5. Módulos adicionales de préstamos y conectores interbancarios si entran al alcance final. No se confirma una salida a otro banco sin respuesta de su red.

## Gestiones, alertas y préstamos

El filtro operativo tiene Todos los estados, En trámite, Emitida y Rechazada. Los estados históricos y rutas `/solicitudes` permanecen para conservar compatibilidad y trazabilidad; los textos del módulo se presentan como Gestiones.

El nuevo flujo recibe una evaluación de Analítica por su token exclusivo y la aceptación posterior desde Banca. Una evaluación solo puede enlazarse una vez; la gestión aceptada llega directamente a `en_tramite`. Gerente/administrador introduce un límite positivo al emitir; nunca supera el máximo aprobado por Analítica ni lo solicitado. Se conserva por separado el máximo original y el importe elegido. También puede rechazar el trámite con nota. Versiones, índices únicos, transacciones e idempotencia evitan resoluciones simultáneas y duplicación de productos.

Los productos personales, vehiculares e hipotecarios se registran como créditos autorizados en `creditos`; no crean tarjetas. Su emisión no mueve dinero ni presume una tasa, plazo, tabla de pagos o garantía. No se implementó desembolso, cuotas, cobranza ni cálculo de intereses de préstamos. El catálogo muestra sus condiciones como pendientes, sin inventar una tasa de 0%.

Las alertas se guardan en MongoDB y se consultan cada cinco segundos mientras el panel está visible. Cada empleado mantiene sus lecturas; los permisos limitan los recursos visibles. No se usa WebSocket y puede existir la demora del intervalo más la red. Los eventos entrantes son tipos acotados y autentificados; no permiten cambiar datos maestros o saldos mediante una notificación. No se guardan contraseñas ni DPI en el mensaje de alerta.

## Banca Virtual: registro, cuentas y gestiones (versión 2)

- El registro en la app crea solo el perfil de acceso (nombre, apellido, correo, contraseña, DPI y fecha de nacimiento). No abre cuentas.
- El DPI es la llave que une el perfil de la app con el cliente del banco. Un cliente registrado en agencia se vincula cuando se registra en la app con el mismo DPI, correo y fecha de nacimiento (si el banco la registró) y aún no tenía acceso. Conocer un DPI no basta para tomar un perfil.
- Las cuentas se abren desde el apartado Cuentas de la app o en agencia.
- Las gestiones nacen únicamente en Banca Virtual y caen en el buzón desde que el cliente las envía. Ahí se piden los datos detallados (NIT, empleo, ingresos, antigüedad). El personal ya no puede crearlas.
- Se eliminaron "Referencia en Banca Virtual" y "Cuotas mensuales de deuda" del registro asistido. Los clientes antiguos conservan su referencia y la API la sigue aceptando por compatibilidad.
- "Olvidé mi contraseña" no usa correo, por decisión del equipo: el cliente confirma DPI, correo y fecha de nacimiento. Es menos seguro que un código por correo porque esos datos no son secretos; los intentos fallidos comparten el bloqueo del login y se limitan a 5 por minuto.
- Las contraseñas de clientes piden 8 caracteres con letras y números, igual que las pantallas de Banca. Las del personal siguen pidiendo 12 con mayúscula, minúscula y número.

## Aprobación final en Gestiones (20/10/2026)

- La evaluación de CP Analítica y la aceptación del cliente ocurren en Banca Virtual. Banca envía la gestión después con `POST /banca/solicitudes` y llega como **En evaluación**, que en Gestiones significa *pendiente de la aprobación final del banco*. No se envía una nueva solicitud de riesgo.
- El personal con permiso de gestión (gerente o administrador) usa **Aprobar**: asigna el límite, que no puede superar el monto aprobado por Analítica si Banca lo informó, ni el monto solicitado. Así se emite la tarjeta o el crédito y la gestión pasa a **Emitida**.
- El personal ya no rechaza gestiones: llegan aprobadas por Analítica. Los filtros del buzón son Todos, En evaluación y Emitida.

## Versión 3 (21/10/2026)

- **Tres roles.** Administrador (todo; único que crea usuarios y asigna roles), Operador (transacciones y reportes) y Empresa (planillas de una sola empresa, `usuarios.empresa_id`). Los siete roles anteriores se migran solos al arrancar: los de personal pasan a Operador y los corporativos a Empresa con su empresa. Las restricciones se aplican en la API (middleware `permit`) y el panel solo muestra lo permitido.
- **JWT con refresh token.** El login del panel entrega un access token JWT (HS256, 15 minutos, firmado con una llave derivada de `APP_KEY`) y un refresh token opaco de 12 horas del que solo se guarda el hash. Cada renovación rota el refresh token; reutilizar uno ya rotado cierra la sesión completa. El JWT lleva el id de la sesión, así que cerrar sesión lo invalida al momento. Los tokens de integración siguen siendo opacos.
- **Cuenta concentradora.** Fondos propios del banco (Q50,000,000.00) registrados como movimiento desde la cuenta de capital, para que la suma de todos los saldos siga en cero. Los préstamos se desembolsan desde ella y las cuotas regresan a ella.
- **Préstamos.** Al aprobar se definen cuotas, tasa anual y día de pago (1–28). Cuota fija (sistema francés) en centavos; la última cuota absorbe el redondeo. Los pagos cubren cuotas en orden, intereses primero, y quedan en la bitácora firmada de la gestión. El día de pago, a las 18:00, lo que siga pendiente se debita de la cuenta del préstamo.
- **Tarjetas.** Al aprobar se definen día de corte, día de pago y la cuenta del cargo automático. El corte genera el estado de cuenta; el día de pago se debita lo que falte de ese corte. El cierre diario ahora recupera los días que el servidor gratuito no ejecutó, en lugar de dejar la tarjeta detenida.
- **Planillas.** Ya no hay doble autorización ni cola: autorizar paga en la misma transacción (todo o nada) y valida antes que la empresa tenga fondos. Los colaboradores son clientes reales con su propia cuenta.
- **Login de clientes creados en el panel.** El login de Banca devuelve el DPI completo y acepta `X-Cliente-Token` como identificador, porque esos clientes no existen en la base propia de Banca.
- **API externa.** Tipo de cambio del Banco de Guatemala (servicio SOAP), con respaldo en ExchangeRate-API y último valor conocido. Se muestra en el resumen y en los reportes PDF.
- **Reportes PDF.** Se generan en el navegador (jsPDF, cargado solo al usarlo) con datos filtrados por la API, para no cargar el servidor gratuito.
