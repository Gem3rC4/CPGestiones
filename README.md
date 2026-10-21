# CP Gestiones

Portal de operaciones del ecosistema CreditPulse. Proyecto universitario construido con PHP, Laravel, MongoDB, Vue y Axios, tomando como referencia los cinco documentos del curso.

## Abrir esta instalación

El portal está preparado en **http://127.0.0.1:5174** y la API en **http://127.0.0.1:8001/api/v1**. Las credenciales generadas para esta computadora están en `ACCESO-LOCAL.txt`. Los nombres, documentos, cuentas y fondos de la demostración son ficticios.

Si cierras los procesos o reinicias Windows, ejecuta `Iniciar-Portal.ps1` desde PowerShell. Inicia servicios locales en segundo plano y aprovecha las herramientas portátiles preparadas en la carpeta de trabajo. No cambia tus otros proyectos.

El ZIP de código excluye contraseñas, `.env`, bases de datos, herramientas portátiles, `vendor` y `node_modules`. Para usarlo en otra computadora sigue la instalación de abajo.

## Funciones implementadas

| Módulo | Comportamiento |
| --- | --- |
| Acceso y usuarios | Login, contraseñas con hash, tokens opacos revocables de 60 minutos, bloqueo de 15 minutos tras 5 fallos, limitación de solicitudes y permisos por rol. |
| Clientes | Registro desde Banca Virtual y apertura atómica de ahorro; perfil financiero versionado, DPI cifrado y enmascarado, unicidad de DPI/correo/referencia e idempotencia. |
| Productos | Ahorro, monetaria, débito, tarjetas Clásica/Oro/Platino y autorización de préstamos personales, vehiculares e hipotecarios. Las condiciones de préstamos quedan por definir. |
| Cuentas | Apertura con saldo cero, bloqueo, movimientos, depósitos y retiros contra una cuenta interna de caja. |
| Transferencias internas | Centavos exactos, débito condicionado a saldo, dos asientos por movimiento, transacción MongoDB y protección ante reintentos. |
| Gestiones | Recepción de ofertas aceptadas, cuatro filtros operativos, límite asignado manualmente sin exceder Analítica, rechazo/emisión y bitácora. |
| Alertas | Campana, contador, lectura por empleado, apertura del registro y actualización cada cinco segundos. |
| Créditos | Registro de préstamos autorizados, separado de tarjetas. No realiza desembolsos. |
| Tarjetas | Consumos, pagos desde cuenta del titular, bloqueo y estados de cuenta. Referencias de simulación; no se generan PAN ni CVV reales. |
| Intereses | Devengo diario y corte de crédito; pago mínimo; abono diario de ahorro. Políticas académicas documentadas. |
| Empresas y planillas | Membresía, operador y aprobador distintos, hasta 500 beneficiarios, dispersión programada, transacción completa y reintento sin duplicar pagos. |
| Bitácora | Gestiones de productos y aprobaciones: secuencia, actor, estado, motivo y cadena HMAC verificable. Sin rutas para editar o eliminar. |
| Seguridad | Registro separado de accesos, cambios de permisos y operaciones. |
| Banca Virtual | Registro, login, cambio de contraseña y sesiones activas del cliente; pago de servicios con historial; confirmación de cuenta destino y transferencias por número. Guía en `docs/GUIA-BANCA-VIRTUAL.md`. |
| Integración | API v1, tokens separados de banca y riesgos, cola persistente de eventos, reintentos y webhooks firmados. |

## Integración real pendiente

La API de registro ya está adaptada al JSON que compartiste. Gestiones guarda los hashes de contraseñas y valida el login de clientes; el servidor de Banca envía los datos y la contraseña a `POST /api/v1/banca/registro` y agrega una referencia estable del usuario. Consulta **[Registro desde Banca Virtual](docs/REGISTRO-BANCA.md)** para el contrato, respuestas, errores y ejemplo de integración. También se incorporaron consultas de perfil/resumen y actualización financiera.

Los otros dos sistemas **todavía no están conectados**. El responsable de banca debe incorporar la llamada en su servidor; la conexión con Analítica requiere acordar su contrato. Las 51 rutas están descritas en `docs/openapi.json` y en la colección Postman. `docs/INTEGRACION.md` cubre los demás flujos.

Los adaptadores y variables están preparados. No se modificó el código de tus compañeros. Si sus contratos son diferentes, habrá que adaptar los conectores de CP Gestiones. No es posible garantizar compatibilidad automática solo a partir de las imágenes.

El despliegue gratuito y MongoDB Atlas quedan pendientes, como solicitaste. Tampoco se implementó una red interbancaria, emisión física de tarjetas, consultas a burós crediticios ni cálculo del score: requieren servicios externos. El catálogo incluye cuentas, tarjetas y autorización de créditos personales, vehiculares e hipotecarios. Desembolso, amortización y cobranza de préstamos siguen pendientes.

## Arquitectura

```text
cp-gestiones/
├── backend/
│   ├── app/Http/Controllers     Endpoints y respuestas HTTP
│   ├── app/Http/Requests        Validación de entradas
│   ├── app/Http/Middleware      Tokens, permisos y seguimiento
│   ├── app/Services             Reglas de negocio y transacciones
│   ├── app/Repositories         Persistencia
│   ├── app/Models               Documentos MongoDB
│   ├── app/Support              Aritmética monetaria
│   ├── routes/api.php           API REST /api/v1
│   ├── routes/console.php       Instalación y procesos programados
│   └── tests/                  Pruebas unitarias e integración
├── frontend/src/
│   ├── components/             Formularios, estados y detalle
│   ├── views/                  Login, resumen y consultas
│   └── services/               Axios, formatos y formularios
├── docs/                       Contratos y guía técnica
├── compose.yaml                MongoDB local como replica set
└── Iniciar-Portal.ps1           Arranque de esta instalación
```

Se mantiene la separación Laravel/Vue del curso. Los importes se almacenan como enteros de 64 bits en centavos; las operaciones monetarias reciben texto decimal (`"1500.25"`). El campo de ingreso en las nuevas rutas de banca también acepta números JSON, que se validan y convierten a centavos antes de persistir. El límite del dominio es Q1,000,000,000.00. No se usa `float` para calcular saldos.

## Instalar en otra computadora

Requisitos: PHP 8.4 o superior compatible con Laravel 13; extensiones `mongodb`, `mbstring`, `openssl`, `fileinfo`, `curl` y `zip`; Composer; Node.js 22.18 o superior; MongoDB con replica set. Se incluyen archivos de bloqueo de versiones.

1. En la raíz, inicia la base con `docker compose up -d`. Espera a que termine `mongo-init`. La base se expone solo en `127.0.0.1:27017`; esta configuración sin autenticación es exclusivamente para desarrollo local.
2. En `backend`, ejecuta `composer install`, copia `.env.example` a `.env` y ejecuta `php artisan key:generate`.
3. Confirma `DB_URI="mongodb://127.0.0.1:27017/?replicaSet=rs0"` y `DB_DATABASE=cp_gestiones`. Para Atlas usa su URI y un usuario con permisos limitados.
4. Ejecuta `php artisan cp:install`. Crea colecciones, índices, catálogo y la cuenta concentradora del banco (Q50,000,000.00 de fondos iniciales para préstamos). No se requieren tablas SQL.
5. Ejecuta `php artisan cp:admin tu-correo "Tu nombre"` para crear el administrador. Desde el panel creas después a los operadores, a los representantes de empresa, a los clientes y sus cuentas.
6. En `frontend`, ejecuta `npm ci` y `npm run build`.
7. En `backend`, inicia `php -S 127.0.0.1:8001 -t public server.php`.
8. En otra terminal, dentro de `frontend`, ejecuta `npm run preview`. Abre http://127.0.0.1:5174. Para desarrollar con recarga automática usa `npm run dev`.
9. En una tercera terminal, dentro de `backend`, ejecuta `php artisan schedule:work` para activar eventos, el cierre diario de tarjetas (23:59) y los cobros automáticos de cuotas y tarjetas (18:00). El administrador también puede ejecutar los cobros desde el Resumen.

La base de esta computadora usa el puerto **27028** para mantenerla separada de los ejercicios de clase. Su `.env` ya está configurado. Docker está preparado como alternativa, pero no se ejecutó porque Docker Desktop no estaba activo; las pruebas se hicieron contra un MongoDB local real con replica set.

## Despliegue en línea (gratuito)

| Pieza | Plataforma | Configuración en el repositorio |
| --- | --- | --- |
| Código fuente | GitHub | — |
| Base de datos | MongoDB Atlas (clúster M0) | No se usa Neon: Neon es PostgreSQL y este sistema usa MongoDB con transacciones. |
| API Laravel | Render (Docker, plan free) | `render.yaml`, `backend/Dockerfile`, `backend/docker/start.sh` |
| Panel Vue | Vercel | `frontend/vercel.json` |
| Mantener la API despierta | GitHub Actions | `.github/workflows/mantener-activo.yml` |

1. **Atlas:** crea un clúster M0, un usuario de base de datos y permite el acceso desde `0.0.0.0/0` (Render gratis no tiene IP fija). Copia la cadena `mongodb+srv://…`.
2. **Render:** *New > Blueprint*, elige este repositorio. Completa `DB_URI` (cadena de Atlas), `FRONTEND_URL` (la URL de Vercel; puedes ponerla después), `INITIAL_ADMIN_EMAIL` e `INITIAL_ADMIN_PASSWORD` (12+ caracteres con mayúscula, minúscula y número). `APP_KEY` la genera Render: **no la cambies después**, cifra los DPI y firma la bitácora. Al arrancar, el contenedor crea índices, catálogo y el primer administrador, y ejecuta los procesos programados cada minuto.
3. **Vercel:** *Add New > Project*, elige el repositorio, *Root Directory* `frontend` y la variable `VITE_API_URL=https://<tu-api>.onrender.com/api/v1`.
4. Vuelve a Render y pon `FRONTEND_URL=https://<tu-panel>.vercel.app` (varias separadas por coma).
5. En GitHub, *Settings > Secrets and variables > Actions > Variables*, crea `BACKEND_URL=https://<tu-api>.onrender.com`. Render gratis se suspende tras 15 minutos sin tráfico y eso detendría planillas y cierres.

En línea la base empieza con el catálogo, la cuenta concentradora y el administrador; no hay datos de prueba (los que creó la versión anterior se eliminan solos al arrancar). Los tokens para Banca y Analítica los genera el administrador en el panel → **Integraciones** → *Tokens de integración*. La guía para el equipo de Banca es [docs/GUIA-BANCA-VIRTUAL.md](docs/GUIA-BANCA-VIRTUAL.md).

## Roles

El menú tiene tres módulos: **Operaciones** (resumen, gestiones, cuentas, préstamos, tarjetas, transacciones, reportes), **Banca corporativa** (empresas, sus usuarios y cuentas, colaboradores, planillas) y **Administración** (clientes, usuarios del banco, productos, bitácora, seguridad, integraciones). La API valida cada permiso aunque se llame sin el panel.

| Rol | Operaciones | Banca corporativa | Administración |
| --- | --- | --- | --- |
| **Administrador** | ✅ | ✅ | ✅ Único que crea usuarios del banco. El administrador original está protegido: nadie más puede cambiar su rol, desactivarlo ni cambiar su contraseña. |
| **Mixto** | ✅ | ✅ | ❌ |
| **Operador** | ✅ | ❌ | ❌ |
| **Corporativo** | ❌ | ✅ (crea usuarios de empresas, cuentas corporativas, fondeos y planillas) | ❌ |
| **Empresa** | ❌ | Solo su empresa: colaboradores, planillas y su reporte | ❌ |

## Recorrido de demostración

1. Como administrador, en **Clientes → Registrar cliente**, registra a una persona con contraseña de Banca Virtual y una cuenta de ahorro. Ya puede iniciar sesión en Banca Virtual con su correo.
2. Registra a la empresa como cliente con una cuenta monetaria, deposítale fondos (**Cuentas → Depósito o retiro**) y regístrala en **Empresas**.
3. En **Colaboradores → Asignar colaborador**, asigna a la persona del paso 1 con su puesto y salario.
4. En **Usuarios y roles**, crea un usuario con rol **Empresa** para esa empresa. Con ese usuario, carga la planilla y autorízala: el dinero pasa de la cuenta de la empresa a la de cada colaborador en una sola operación.
5. Cuando una gestión llegue desde Banca Virtual, ábrela en **Gestiones → Aprobar**. Para un préstamo defines monto, cuotas, tasa, día de pago y cuenta: se desembolsa desde la cuenta concentradora. Para una tarjeta defines día de corte, día de pago y la cuenta del cargo automático.
6. En **Reportes** descarga o imprime los tres PDF: planillas por empresa, estado de cuenta de un cliente y transacciones globales.

## Pruebas

Desde `backend`, ejecuta `php artisan test --compact`. Usa la conexión MongoDB configurada y bases aisladas `cp_gestiones_<pid>_test`, `cp_gestiones_registration_<pid>_test` y `cp_gestiones_ecosystem_<pid>_test`. Las pruebas comprueban el nombre antes de limpiar únicamente sus bases. Resultado actual: 71 pruebas, más de 1,000 comprobaciones.

Se verificaron autenticación, autorización, exactitud monetaria, reversión ante errores, idempotencia, aislamiento por empresa/cliente, bitácora, cortes, pagos y concurrencia. Consulta `docs/VALIDACION.md` para el resultado final y las limitaciones de la revisión visual.

## Referencias

Los cinco documentos de Semana 11 aportaron el patrón de capas, la integración oficial MongoDB y el frontend Vue/Axios. Se contrastaron las transacciones con la [documentación de MongoDB](https://www.mongodb.com/docs/drivers/php/laravel-mongodb/current/interact-data/transactions/) y su [guía Laravel](https://www.mongodb.com/docs/drivers/php/laravel-mongodb/current/get-started/). No se reutilizaron las contraseñas del ejemplo del sanatorio.
