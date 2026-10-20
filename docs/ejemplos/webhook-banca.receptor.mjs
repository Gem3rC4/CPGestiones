// Ejemplo para Banca Virtual: recibir los avisos que envía CP Gestiones (webhook firmado).
// Node 18+, sin dependencias. Ejecutar: CP_WEBHOOK_SECRET=... node webhook-banca.receptor.mjs
// En la reunión: publica esta ruta en tu servidor (por ejemplo /api/gestiones/webhook) y pega su URL
// y el mismo secreto en CP Gestiones → Integraciones → Conexiones → CP Banca Virtual.
import { createServer } from 'node:http'
import { createHmac, timingSafeEqual } from 'node:crypto'

const SECRET = process.env.CP_WEBHOOK_SECRET
const PORT = Number(process.env.PORT || 8093)
const seen = new Set() // En producción guarda los event_id en tu base de datos.

function validSignature(rawBody, timestamp, signature) {
  if (!SECRET || !timestamp || !signature) return false
  if (Math.abs(Date.now() / 1000 - Number(timestamp)) > 300) return false // tolera 5 minutos
  const expected = createHmac('sha256', SECRET).update(`${timestamp}.${rawBody}`).digest('hex')
  return expected.length === signature.length && timingSafeEqual(Buffer.from(expected), Buffer.from(signature))
}

createServer((req, res) => {
  if (req.method !== 'POST') { res.writeHead(405).end(); return }
  let raw = ''
  req.on('data', chunk => { raw += chunk })
  req.on('end', () => {
    // Verifica sobre el cuerpo EXACTO recibido, antes de convertirlo a JSON.
    if (!validSignature(raw, req.headers['x-cp-timestamp'], req.headers['x-cp-signature'])) {
      res.writeHead(401, { 'Content-Type': 'application/json' }).end(JSON.stringify({ ok: false }))
      return
    }
    const event = JSON.parse(raw)
    if (!seen.has(event.event_id)) {
      seen.add(event.event_id)
      if (event.type === 'solicitud.actualizada') {
        // event.data: { solicitud_id, gestion_ref, estado, sequence, nota, limite_asignado_centavos, tarjeta_id, credito_id }
        // Ej.: si estado === 'emitida', marca la gestión gestion_ref como "Completada" y muestra el límite asignado.
        console.log(`Gestión ${event.data.gestion_ref ?? event.data.solicitud_id}: ${event.data.estado}`)
      } else if (event.type === 'conexion.prueba') {
        console.log('Prueba de conexión recibida desde CP Gestiones')
      }
    }
    // Responde 2xx rápido: si no, Gestiones reintenta (hasta 8 veces).
    res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ ok: true }))
  })
}).listen(PORT, () => console.log(`Receptor de avisos de CP Gestiones en http://127.0.0.1:${PORT}`))
