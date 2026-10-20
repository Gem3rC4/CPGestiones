<script setup>
import { ref, onMounted } from 'vue'
import { KeyRound, Copy, Check, Plus, X } from 'lucide-vue-next'
import { api, get, message, date } from '../services/api'
const systems = { banca: 'CP Banca Virtual', riesgos: 'CP Analítica' }
const tokens = ref([]), loading = ref(false), busy = ref(false), error = ref(''), issued = ref(null), copied = ref(false)
const form = ref({ cliente: 'banca', dias: 90 })
async function load() {
  loading.value = true
  try { tokens.value = await get('/integraciones/tokens') } catch (e) { error.value = message(e) } finally { loading.value = false }
}
async function create() {
  busy.value = true; error.value = ''; copied.value = false
  try { issued.value = (await api.post('/integraciones/tokens', form.value)).data.data; await load() }
  catch (e) { error.value = message(e) } finally { busy.value = false }
}
async function copy() {
  try { await navigator.clipboard.writeText(issued.value.token); copied.value = true }
  catch { error.value = 'No se pudo copiar automáticamente. Selecciona el token y cópialo con Ctrl + C.' }
}
async function revoke(token) {
  if (!window.confirm(`¿Revocar el token de ${systems[token.client]} que termina en ${token.hint || '····'}? Ese sistema dejará de conectarse de inmediato.`)) return
  busy.value = true; error.value = ''
  try { await api.post(`/integraciones/tokens/${token.id}/revocar`); if (issued.value?.id === token.id) issued.value = null; await load() }
  catch (e) { error.value = message(e) } finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <section class="panel tokens-panel">
    <div class="panel-heading"><div><h3>Tokens de integración</h3><p>Credenciales para que el servidor de cada sistema consuma esta API. Solo el administrador puede emitirlas o revocarlas.</p></div><KeyRound :size="20"/></div>
    <div class="tokens-body">
      <div v-if="error" class="error-box" role="alert">{{ error }}</div>
      <form class="token-form" @submit.prevent="create">
        <label>Sistema<select v-model="form.cliente"><option value="banca">CP Banca Virtual</option><option value="riesgos">CP Analítica</option></select></label>
        <label>Vigencia<select v-model.number="form.dias"><option :value="30">30 días</option><option :value="90">90 días</option><option :value="180">180 días</option><option :value="365">1 año</option></select></label>
        <button class="primary" :disabled="busy"><Plus :size="16"/>{{ busy ? 'Generando…' : 'Generar token' }}</button>
      </form>
      <div v-if="issued" class="token-issued" role="status">
        <div class="token-issued-head"><b>Token de {{ systems[issued.client] }} · vence {{ date(issued.expires_at) }}</b><button class="icon-button" aria-label="Ocultar token" @click="issued = null"><X :size="18"/></button></div>
        <div class="token-value"><input :value="issued.token" readonly aria-label="Token generado" @focus="$event.target.select()"><button class="secondary" type="button" @click="copy"><component :is="copied ? Check : Copy" :size="15"/>{{ copied ? 'Copiado' : 'Copiar' }}</button></div>
        <small>Se muestra <b>una sola vez</b>: el sistema guarda solo una huella. Compártelo por un medio privado; debe ir en las variables de entorno del <b>servidor</b> de ese sistema, nunca en el código del navegador ni en GitHub.</small>
      </div>
      <div class="table-wrap"><table><thead><tr><th>SISTEMA</th><th>TERMINA EN</th><th>CREADO POR</th><th>CREADO</th><th>VENCE</th><th></th></tr></thead><tbody>
        <tr v-for="token in tokens" :key="token.id"><td>{{ systems[token.client] || token.client }}</td><td><code>····{{ token.hint || '????' }}</code></td><td>{{ token.created_by || '—' }}</td><td>{{ date(token.created_at) }}</td><td>{{ date(token.expires_at) }}</td><td><button class="text-button danger" :disabled="busy" @click="revoke(token)">Revocar</button></td></tr>
      </tbody></table><p v-if="!tokens.length" class="empty-inline">{{ loading ? 'Consultando tokens…' : 'No hay tokens activos. Genera uno para conectar Banca Virtual o Analítica.' }}</p></div>
    </div>
  </section>
</template>

<style scoped>
.tokens-panel{margin-bottom:22px}.tokens-panel .panel-heading>svg{color:#87aaff}.tokens-body{padding:0 22px 20px}.token-form{display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:18px}.token-form label{font-size:11px;min-width:180px}.token-form select{padding:10px;font-size:12px}.token-issued{border:1px solid #2d7458;background:#1c594320;border-radius:9px;padding:16px;margin-bottom:18px;display:grid;gap:12px}.token-issued-head{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:#a9e7cf}.token-value{display:flex;gap:10px}.token-value input{font-family:ui-monospace,Consolas,monospace;font-size:12px}.token-issued small{line-height:1.7;font-size:11px;color:#9fc7b8}code{font-family:ui-monospace,Consolas,monospace;font-size:11px;color:#c5d2e5}.danger{color:#ee9ca5}@media (max-width:640px){.token-value{flex-direction:column}.token-form label{min-width:0;flex:1}}
</style>
