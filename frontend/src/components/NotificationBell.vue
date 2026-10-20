<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import { Bell, CheckCheck, ChevronLeft, ChevronRight, X, ArrowUpRight } from 'lucide-vue-next'
import { get, api, identifier, date, message } from '../services/api'

const emit = defineEmits(['open', 'received'])
const root = ref(), trigger = ref(), shown = ref(false), expanded = ref(false)
const alerts = ref([]), unread = ref(0), page = ref(1), lastPage = ref(1)
const error = ref(''), reading = ref(false), loading = ref(false)
let timer, disposed = false, newest = null, revision = 0

async function load() {
  if (loading.value || disposed || document.hidden) return
  const request = ++revision
  loading.value = true
  try {
    const result = await get('/alertas', { page: page.value })
    if (disposed || request !== revision) return
    alerts.value = result.alertas.data
    unread.value = result.sin_leer
    lastPage.value = result.alertas.last_page
    error.value = ''
    if (page.value === 1) {
      const latest = identifier(alerts.value[0]) || ''
      if (newest !== null && newest !== latest) emit('received')
      newest = latest
    }
  } catch (e) { if (!disposed && request === revision) error.value = message(e) }
  finally { if (!disposed) loading.value = false }
}
async function markRead(items) {
  const ids = items.filter(item => !item.leida).map(identifier)
  if (!ids.length) return true
  reading.value = true
  revision++
  try {
    await api.post('/alertas/leidas', { ids })
    if (disposed) return false
    for (const alert of alerts.value) if (ids.includes(identifier(alert))) alert.leida = true
    unread.value = Math.max(0, unread.value - ids.length)
    error.value = ''
    return true
  } catch (e) { if (!disposed) error.value = message(e); return false }
  finally { if (!disposed) reading.value = false }
}
async function openItem(alert) {
  if (!await markRead([alert])) return
  if (alert.recurso) { shown.value = false; emit('open', alert) }
}
function close() { shown.value = false; trigger.value?.focus() }
async function toggle() {
  shown.value = !shown.value
  if (shown.value) { await load(); await nextTick(); root.value?.querySelector('.alert-panel button')?.focus() }
}
async function changePage(value) {
  if (loading.value || reading.value) return
  page.value = value
  await load()
}
function outside(event) { if (!root.value?.contains(event.target)) shown.value = false }
function visibility() { if (!document.hidden && !reading.value) load() }
function schedule() {
  timer = setTimeout(async () => {
    if (!reading.value) await load()
    if (!disposed) schedule()
  }, 5000)
}
onMounted(() => { load(); schedule(); document.addEventListener('pointerdown', outside); document.addEventListener('visibilitychange', visibility) })
onUnmounted(() => { disposed = true; revision++; clearTimeout(timer); document.removeEventListener('pointerdown', outside); document.removeEventListener('visibilitychange', visibility) })
</script>

<template>
  <div ref="root" class="notifications" @keydown.esc.stop.prevent="close">
    <button ref="trigger" class="icon-button alert-trigger" :class="{'has-alert-error': error}" :aria-label="`Alertas: ${unread} sin leer${error ? '. No se pudieron actualizar' : ''}`" :aria-expanded="shown" aria-controls="operations-alerts" @click="toggle">
      <Bell :size="20"/>
      <span v-if="unread" class="alert-count">{{ unread > 99 ? '99+' : unread }}</span>
      <span v-else-if="error" class="alert-count">!</span>
    </button>
    <section v-if="shown" id="operations-alerts" class="alert-panel" aria-label="Alertas del banco">
      <header><div><h3>Alertas</h3><small>{{ unread }} sin leer · Actualización cada 5 s</small></div><button class="icon-button" aria-label="Cerrar alertas" @click="close"><X :size="18"/></button></header>
      <div v-if="error" class="alert-error" role="alert">{{ error }}<button class="text-button" :disabled="loading" @click="load">Reintentar</button></div>
      <div class="alert-tools"><button class="text-button" :disabled="reading || loading || !alerts.some(a=>!a.leida)" @click="markRead(expanded ? alerts : alerts.slice(0,5))"><CheckCheck :size="15"/> Marcar visibles como leídas</button></div>
      <div class="alert-list">
        <article v-for="alert in (expanded ? alerts : alerts.slice(0,5))" :key="identifier(alert)" :class="{unread:!alert.leida}">
          <button :disabled="reading" @click="openItem(alert)"><span class="alert-title">{{ alert.titulo }}<span v-if="!alert.leida" class="unread-dot" aria-label="Sin leer"></span></span><span class="alert-message">{{ alert.mensaje }}</span><small>{{ date(alert.created_at) }}</small><span v-if="alert.recurso" class="alert-link">Abrir registro <ArrowUpRight :size="13"/></span></button>
        </article>
        <div v-if="!alerts.length" class="alert-empty"><Bell :size="24"/><b>{{ loading ? 'Consultando alertas…' : 'Todo al día' }}</b><p v-if="!loading">Los registros y las gestiones recibidas aparecerán aquí.</p></div>
      </div>
      <footer v-if="expanded"><button class="icon-button" :disabled="page <= 1 || loading || reading" aria-label="Alertas anteriores" @click="changePage(page-1)"><ChevronLeft :size="17"/></button><small>Página {{ page }} de {{ lastPage }}</small><button class="icon-button" :disabled="page >= lastPage || loading || reading" aria-label="Más alertas" @click="changePage(page+1)"><ChevronRight :size="17"/></button></footer>
      <footer v-else><button class="text-button" @click="expanded=true">Ver todas</button></footer>
    </section>
  </div>
</template>

<style scoped>
.notifications{position:relative}.alert-trigger{position:relative;border:1px solid var(--border);padding:9px}.alert-count{position:absolute;top:-7px;right:-6px;border-radius:12px;min-width:18px;padding:2px 4px;background:#f2717b;color:white;font-size:10px;font-weight:700}.alert-panel{position:absolute;top:48px;right:0;width:390px;max-width:calc(100vw - 24px);background:var(--panel);border:1px solid var(--border);border-radius:14px;box-shadow:0 18px 65px #0008;z-index:40;overflow:hidden}.alert-panel header{display:flex;justify-content:space-between;align-items:center;padding:18px 19px;border-bottom:1px solid var(--border)}.alert-panel header small{display:block;margin-top:5px}.alert-tools{padding:9px 16px}.alert-list{max-height:min(55vh,440px);overflow:auto}.alert-list article{border-bottom:1px solid var(--border)}.alert-list article.unread{background:#648cff0b}.alert-list article>button{width:100%;padding:17px 19px;text-align:left;display:flex;flex-direction:column;align-items:flex-start;gap:7px;border:0;background:none}.alert-list article>button:hover{background:#648cff12}.alert-title{font-size:13px;font-weight:700}.unread-dot{display:inline-block;width:7px;height:7px;background:var(--blue);border-radius:50%;margin-left:8px}.alert-message{font-size:12px;line-height:1.65;color:var(--muted);overflow-wrap:anywhere}.alert-link{display:flex;align-items:center;gap:5px;font-size:11px;color:#99b6ff}.alert-empty{padding:35px 26px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:12px;color:var(--muted);font-size:12px;line-height:1.6}.alert-empty b{color:var(--text);font-size:14px}.alert-panel footer{display:flex;align-items:center;justify-content:center;gap:15px;padding:11px;border-top:1px solid var(--border)}.alert-error{padding:12px 18px;color:#ffb6b6;font-size:12px}.has-alert-error{border-color:#d5855a}@media(max-width:700px){.alert-panel{position:fixed;top:72px;right:12px;width:390px}}
</style>
