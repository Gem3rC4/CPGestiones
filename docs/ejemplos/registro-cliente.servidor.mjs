// Solo para el servidor de Banca Virtual. Requiere Node con fetch (18+).
// El llamador persiste clienteRef y claveOperacion antes de intentar el registro.
// Gestiones valida y hashea la contraseña. No guardar ni registrar su valor en logs.
export async function registrarEnGestiones({ formulario, clienteRef, claveOperacion }) {
  const base = process.env.CP_GESTIONES_URL;
  const token = process.env.CP_GESTIONES_BANK_TOKEN;
  if (!base || !token || !clienteRef || !claveOperacion) {
    throw new Error('Configura la integración y persiste la identidad/clave de operación.');
  }
  const url = new URL(`${base.replace(/\/$/, '')}/banca/registro`);
  const local = ['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname);
  if (url.protocol !== 'https:' && !(local && url.protocol === 'http:')) {
    throw new Error('La integración remota requiere HTTPS.');
  }
  const payload = {
    cliente_ref: String(clienteRef),
    nombre: formulario.nombre,
    apellido: formulario.apellido,
    correo_electronico: formulario.correo_electronico,
    contrasena: formulario.contrasena,
    dpi: formulario.dpi,
    nit: formulario.nit,
    fecha_nacimiento: formulario.fecha_nacimiento,
    tipo_empleo: formulario.tipo_empleo,
    ingresos_mensuales_q: formulario.ingresos_mensuales_q,
    antiguedad_laboral_meses: formulario.antiguedad_laboral_meses,
  };
  let response;
  try {
    response = await fetch(url, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json',
        'Content-Type': 'application/json', 'Idempotency-Key': claveOperacion },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(10000),
      redirect: 'error',
    });
  } catch {
    // Puede haberse confirmado: nunca crear otra referencia o clave por un timeout.
    return { estado: 'pendiente', reintentarMismaOperacion: true, codigo: 'NETWORK_ERROR' };
  }
  let result;
  try { result = await response.json(); } catch {
    return { estado: 'pendiente', reintentarMismaOperacion: true, codigo: 'INVALID_RESPONSE' };
  }
  if (response.status === 201 && result.ok === true && result.data?.cliente?.cliente_ref === String(clienteRef)
      && result.data.cliente.id && result.data.cuenta?.id) {
    // Conservar solo la referencia/IDs de integración; Gestiones es la fuente maestra.
    return { estado: 'confirmado', cliente: result.data.cliente, cuenta: result.data.cuenta, resumen: result.data.resumen };
  }
  const retry = response.status >= 500 || response.status === 429
    || (response.status === 409 && result.code === 'CONFLICT') || response.status === 201;
  return {
    estado: retry ? 'pendiente' : 'requiere_revision',
    reintentarMismaOperacion: retry,
    http: response.status,
    codigo: result.code ?? 'UNEXPECTED_RESPONSE',
    errores: result.errors ?? {},
    seguimiento: result.request_id,
    esperaSegundos: response.headers.get('Retry-After'),
  };
}
