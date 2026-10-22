<script setup>
import { ref, reactive, onMounted } from 'vue'
import { Link2, PlugZap, Save } from 'lucide-vue-next'
import { api, get, message, date } from '../services/api'
const help = {
  banca: { url: 'URL del servidor de Banca Virtual que recibirá los avisos (webhook), por ejemplo https://banca-virtual-five.vercel.app/api/gestiones/webhook', secret: 'Secreto compartido para firmar los avisos (HMAC). Tu compañero usa el mismo valor para verificarlos.' },
  banca_abonos: { url: 'Ruta de BancaVirtual que acredita los pagos en sus cuentas (planillas, préstamos y fondeos). Ya viene puesta: https://bancavirtual-kp9z.onrender.com/api/v1/admin/abonos', secret: 'Llave de servicio (X-Service-Key) que te dio tu compañero. Se guarda cifrada y nunca se vuelve a mostrar. "Probar" no acredita dinero.' },
  riesgos: { url: 'URL de CP Analítica que recibirá las solicitudes de evaluación (opcional en el flujo actual).', secret: 'Token que entregó CP Analítica para aceptar las llamadas.' },
}
const connections = ref([]), forms = reactive({}), busy = ref(''), error = ref(''), results = reactive({})
async function load() {
  try {
    connections.value = await get('/integraciones/conexiones')
    for (const c of connections.value) forms[c.sistema] = { url: c.url || '', secreto: '' }
  } catch (e) { error.value = message(e) }
}
async function save(system) {
  busy.value = system; error.value = ''
  try {
    const payload = { url: forms[system].url.trim(), ...(forms[system].secreto ? { secreto: forms[system].secreto } : {}) }
    await api.patch(`/integraciones/conexiones/${system}`, payload)
    forms[system].secreto = ''
    await load()
  } catch (e) { error.value = message(e) } finally { busy.value = '' }
}
async function test(system) {
  busy.value = system; error.value = ''
  try { results[system] = (await api.post(`/integraciones/conexiones/${system}/probar`)).data.data; await load() }
  catch (e) { error.value = message(e) } finally { busy.value = '' }
}
onMounted(load)
</script>

<template>
  <section class="panel connections-panel">
    <div class="panel-heading"><div><h3>Conexiones con los otros sistemas</h3><p>Pega aquí las URL reales durante la integración. Se aplican al instante, sin volver a desplegar; el secreto se guarda cifrado y nunca se muestra.</p></div><Link2 :size="20"/></div>
    <div v-if="error" class="error-box connections-error" role="alert">{{ error }}</div>
    <div class="connections-grid">
      <form v-for="c in connections" :key="c.sistema" class="connection-card" @submit.prevent="save(c.sistema)">
        <div class="connection-title"><b>{{ c.nombre }}</b><span class="badge" :class="c.url ? 'configurada' : 'por_configurar'">{{ c.url ? (c.origen === 'panel' ? 'Configurada en el panel' : 'Configurada en el servidor') : 'Por configurar' }}</span></div>
        <label>URL<input v-model="forms[c.sistema].url" type="url" required maxlength="300" :placeholder="c.sistema === 'banca' ? 'https://…/api/gestiones/webhook' : 'https://…'"><small>{{ help[c.sistema].url }}</small></label>
        <label>{{ c.sistema === 'banca' ? 'Secreto compartido' : c.sistema === 'banca_abonos' ? 'Llave de servicio (X-Service-Key)' : 'Token de CP Analítica' }}<input v-model="forms[c.sistema].secreto" type="password" minlength="16" maxlength="200" autocomplete="new-password" :placeholder="c.secreto_configurado ? '•••••••• (déjalo vacío para conservarlo)' : 'Mínimo 16 caracteres'"><small>{{ help[c.sistema].secret }}</small></label>
        <div class="connection-actions"><button class="primary" :disabled="busy === c.sistema"><Save :size="15"/>Guardar</button><button type="button" class="secondary" :disabled="busy === c.sistema || !c.url" @click="test(c.sistema)"><PlugZap :size="15"/>Probar conexión</button></div>
        <p v-if="results[c.sistema] || c.ultima_prueba" class="connection-result" :class="{ ok: (results[c.sistema] || c.ultima_prueba).conectado }">
          {{ (results[c.sistema] || c.ultima_prueba).conectado ? '✓' : '✗' }} {{ (results[c.sistema] || c.ultima_prueba).mensaje }}
          <small>{{ (results[c.sistema] || c.ultima_prueba).http_status ? `HTTP ${(results[c.sistema] || c.ultima_prueba).http_status} · ` : '' }}{{ (results[c.sistema] || c.ultima_prueba).milisegundos }} ms · {{ date((results[c.sistema] || c.ultima_prueba).probado_el) }}</small>
        </p>
      </form>
    </div>
  </section>
</template>

<style scoped>
.connections-panel{margin-bottom:22px}.connections-panel .panel-heading>svg{color:#87aaff}.connections-error{margin:0 22px 16px}.connections-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;padding:0 22px 22px}.connection-card{border:1px solid #2a3a52;border-radius:10px;padding:16px;display:grid;gap:14px;background:#101a2c}.connection-title{display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:13px}.connection-card label{font-size:11px}.connection-card input{font-size:12px;padding:10px}.connection-actions{display:flex;gap:10px;flex-wrap:wrap}.connection-result{font-size:12px;color:#ee9ca5;line-height:1.6}.connection-result.ok{color:#6cd5ac}.connection-result small{display:block;font-size:10px;color:#7d92b0}@media (max-width:900px){.connections-grid{grid-template-columns:1fr}}
</style>
