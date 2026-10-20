<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue'
import { LayoutDashboard, ClipboardList, Wallet, CreditCard, Building2, Users, ShieldCheck, ScrollText, PlugZap, ChevronDown, Bell, Search, RefreshCw, LogOut, Menu, X, CheckCircle2, CircleHelp, ArrowUpRight, Boxes, Activity, Circle } from 'lucide-vue-next'
import LoginView from './views/LoginView.vue'
import DashboardView from './views/DashboardView.vue'
import RecordsView from './views/RecordsView.vue'
import OperationModal from './components/OperationModal.vue'
import DetailDrawer from './components/DetailDrawer.vue'
import StatusBadge from './components/StatusBadge.vue'
import NotificationBell from './components/NotificationBell.vue'
import { api, get, setToken, hasToken, message, label, date, identifier } from './services/api'
import { formSpec } from './services/forms'
const session=ref(null), expired=ref(false), module=ref('dashboard'), sidebar=ref(false), busy=ref(false), error=ref(''), toast=ref(''), updated=ref(null)
const dashboard=ref(null), rows=ref([]), pagination=ref(null), page=ref(1), state=ref(''), integrations=ref(null), spec=ref(null), detail=ref(null)
const lookups=reactive({clientes:[],cuentas:[],productos:[],empresas:[],corporativos:[]})
const lookupError=ref('')
let generation=0, toastTimer
const can=permission=>session.value?.permissions.includes('*') || session.value?.permissions.includes(permission)
const nav=[
 {key:'dashboard',name:'Resumen general',icon:LayoutDashboard,group:'OPERACIÓN',permission:'dashboard.read'},
 {key:'solicitudes',name:'Gestiones',icon:ClipboardList,group:'OPERACIÓN',permission:'solicitudes.read'},
 {key:'cuentas',name:'Cuentas',icon:Wallet,group:'OPERACIÓN',permission:'cuentas.read'},
 {key:'creditos',name:'Créditos',icon:Wallet,group:'OPERACIÓN',permission:'solicitudes.read'},
 {key:'tarjetas',name:'Tarjetas',icon:CreditCard,group:'OPERACIÓN',permission:'tarjetas.read'},
 {key:'empresas',name:'Empresas',icon:Building2,group:'BANCA CORPORATIVA',permission:'empresas.read'},
 {key:'planillas',name:'Planillas',icon:Users,group:'BANCA CORPORATIVA',permission:'planillas.read'},
 {key:'clientes',name:'Clientes',icon:Users,group:'ADMINISTRACIÓN',permission:'clientes.read'},
 {key:'productos',name:'Productos y políticas',icon:Boxes,group:'ADMINISTRACIÓN',permission:'productos.read'},
 {key:'usuarios',name:'Usuarios internos',icon:ShieldCheck,group:'ADMINISTRACIÓN',permission:'usuarios.read'},
 {key:'bitacora',name:'Bitácora de gestiones',icon:ScrollText,group:'ADMINISTRACIÓN',permission:'bitacora.read'},
 {key:'seguridad',name:'Actividad y seguridad',icon:Activity,group:'ADMINISTRACIÓN',permission:'seguridad.read'},
 {key:'integraciones',name:'Integraciones',icon:PlugZap,group:'ECOSISTEMA',permission:'integraciones.read'},
]
const allowed=computed(()=>nav.filter(n=>can(n.permission)))
const current=computed(()=>nav.find(n=>n.key===module.value))
const navGroups=computed(()=>[...new Set(allowed.value.map(n=>n.group))])
const initials=computed(()=>session.value?.user?.name?.split(' ').slice(0,2).map(x=>x[0]).join('') || 'CP')
function clearData() { generation++; dashboard.value=null;rows.value=[];pagination.value=null;integrations.value=null;updated.value=null;error.value='';lookupError.value='';for(const key of Object.keys(lookups)) lookups[key]=[] }
const isLocalHost=['localhost','127.0.0.1'].includes(window.location.hostname)
const MODULE_KEY='cp-gestiones.module'
const remembered=()=>{ try { return sessionStorage.getItem(MODULE_KEY) } catch { return null } }
watch(module, value=>{ try { sessionStorage.setItem(MODULE_KEY, value) } catch {} })
async function authenticated(data, preferred=null) { clearData(); session.value=data; expired.value=false; module.value=allowed.value.some(n=>n.key===preferred) ? preferred : (allowed.value[0]?.key || 'planillas'); await loadLookups(); await load() }
// A reload keeps the tab's token: validate it with the API before showing the portal again.
const restoring=ref(hasToken()), restoreError=ref('')
async function restoreSession() {
 restoreError.value=''
 try {
  const me=(await api.get('/auth/me', { timeout: 70000 })).data.data
  if(!me.user) { setToken(null); restoring.value=false; return }
  restoring.value=false
  await authenticated({ user: me.user, permissions: me.permissions || [] }, remembered())
 } catch(e) {
  if(e.response?.status===401) { restoring.value=false; return }
  restoreError.value=message(e)
 }
}
function abandonRestore() { setToken(null); restoring.value=false; restoreError.value='' }
async function loadLookups() {
  const owner=session.value
  lookupError.value=''
  const tasks=['clientes','cuentas','productos','empresas'].filter(k=>can(`${k}.read`)).map(async key=>{
    const records=[]
    let currentPage=1,lastPage=1
    do {
      if(session.value!==owner) return
      const result=await get(`/${key}`,{per_page:100,page:currentPage})
      records.push(...result.data);lastPage=result.last_page;currentPage++
    } while(currentPage<=lastPage)
    if(session.value===owner) lookups[key]=records
  })
  if(can('empresas.write')) tasks.push(get('/usuarios-corporativos').then(result=>{if(session.value===owner) lookups.corporativos=result}))
  const results=await Promise.allSettled(tasks)
  if(session.value===owner && results.some(r=>r.status==='rejected')) lookupError.value='No fue posible cargar algunos catálogos. Actualiza para volver a intentar.'
}
async function load() {
 if(!session.value) return
 const request=++generation, target=module.value
 busy.value=true; error.value=''
 try {
  const data=await get(target==='dashboard'?'/dashboard':target==='integraciones'?'/integraciones':`/${target}`,{page:page.value,estado:state.value || undefined})
  if(request!==generation) return
  if(target==='dashboard') dashboard.value=data
  else if(target==='integraciones') integrations.value=data
  else {rows.value=data.data;pagination.value=data}
  updated.value=new Date()
 } catch(e) { if(request===generation) error.value=message(e) }
 finally { if(request===generation) busy.value=false }
}
function navigate(key) { module.value=key;sidebar.value=false;page.value=1;state.value='';detail.value=null;load() }
async function refresh() { await loadLookups();await load() }
function action(kind,item={}) { if(lookupError.value) return;spec.value=formSpec(kind,item,lookups);detail.value=null }
async function openAlert(alert) {
 if(!alert.recurso) return
 const key=alert.recurso
 if(!can(`${key}.read`)) return
 navigate(key)
 if(key==='solicitudes') detail.value={module:key,item:{id:alert.recurso_id}}
 else { await loadLookups(); const item=lookups[key]?.find(x=>identifier(x)===alert.recurso_id); if(item && module.value===key) detail.value={module:key,item} }
}
function open(item) { detail.value={module:module.value==='dashboard'?'solicitudes':module.value,item} }
async function saved() { spec.value=null;toast.value='La operación se guardó correctamente.';clearTimeout(toastTimer);toastTimer=setTimeout(()=>toast.value='',5000);await refresh() }
async function logout() { try {await api.post('/auth/logout')} catch {} finally {session.value=null;setToken(null);detail.value=null;spec.value=null;clearData()} }
function sessionExpired(){session.value=null;expired.value=true;detail.value=null;spec.value=null;clearData()}
onMounted(()=>{ window.addEventListener('session-expired',sessionExpired); if(restoring.value) restoreSession() })
onUnmounted(()=>{window.removeEventListener('session-expired',sessionExpired);clearTimeout(toastTimer)})
</script>
<template><main v-if="restoring" class="restore-screen" aria-live="polite"><span class="brand-symbol">CP<span>✓</span></span><template v-if="!restoreError"><RefreshCw :size="18" class="spinning"/><p>Restableciendo tu sesión…</p><small>Si el servidor estaba inactivo puede tardar hasta un minuto.</small></template><template v-else><div class="error-box" role="alert">{{ restoreError }}</div><div class="restore-actions"><button class="primary" @click="restoreSession">Reintentar</button><button class="secondary" @click="abandonRestore">Ingresar de nuevo</button></div></template></main><LoginView v-else-if="!session" :expired="expired" @authenticated="authenticated"/><div v-else class="app-shell">
 <button v-if="sidebar" class="sidebar-backdrop" aria-label="Cerrar menú" @click="sidebar=false"></button>
 <aside class="sidebar" :class="{shown:sidebar}"><a class="brand" href="#" @click.prevent="navigate(allowed[0].key)"><span class="brand-symbol">CP<span>✓</span></span><span>CP GESTIONES<small>PORTAL DE OPERACIONES</small></span></a><div class="workspace-label"><span class="workspace-icon"><Building2 :size="17"/></span><span>CreditPulse<small>Banca y operaciones</small></span><span class="environment-pill">{{ isLocalHost ? 'LOCAL' : 'EN LÍNEA' }}</span></div><nav aria-label="Navegación principal"><div v-for="group in navGroups" :key="group" class="nav-group"><p>{{ group }}</p><button v-for="item in allowed.filter(n=>n.group===group)" :key="item.key" :class="{active:module===item.key}" :aria-current="module===item.key?'page':undefined" @click="navigate(item.key)"><component :is="item.icon" :size="18"/><span>{{ item.name }}</span><span v-if="item.key==='solicitudes' && dashboard?.pendientes" class="nav-count">{{ dashboard.pendientes }}</span></button></div></nav><div class="sidebar-footer"><ShieldCheck :size="18"/><span>Operación con trazabilidad<small>Proyecto universitario</small></span></div></aside>
 <div class="main-shell"><header class="topbar"><div class="breadcrumb"><button class="icon-button menu-toggle" aria-label="Abrir menú" @click="sidebar=true"><Menu :size="22"/></button><span>Portal de operaciones</span><span class="breadcrumb-slash">/</span><b>{{ current?.name }}</b></div><div class="topbar-right"><NotificationBell v-if="can('clientes.read') || can('cuentas.read') || can('solicitudes.read')" :key="identifier(session.user)" @open="openAlert" @received="refresh"/><span class="connection-status"><span class="live-dot"></span>Sesión activa</span><span class="topbar-divider"></span><span class="user-avatar">{{ initials }}</span><span class="user-info"><b>{{ session.user.name }}</b><small>{{ label(session.user.role) }}</small></span><button class="icon-button" aria-label="Cerrar sesión" title="Cerrar sesión" @click="logout"><LogOut :size="19"/></button></div></header>
 <main class="content"><div class="page-topline"><p><span class="live-dot"></span> CONTROL OPERATIVO</p><button class="refresh-button" :disabled="busy" @click="refresh"><RefreshCw :size="14" :class="{spinning:busy}"/>{{ busy ? 'Actualizando…' : 'Actualizar' }}</button></div><div v-if="error || lookupError" class="error-box" role="alert">{{ error || lookupError }}<button class="text-button" @click="refresh">Reintentar</button></div><div v-if="busy" class="loading-line" aria-label="Cargando"></div>
 <DashboardView v-if="module==='dashboard'" :data="dashboard" :clients="lookups.clientes" :can="can" @navigate="navigate" @action="action" @open="open"/>
 <section v-else-if="module==='integraciones'"><div class="section-heading"><div><h2>Un banco. Tres sistemas conectados.</h2><p>Supervisa los puntos de conexión y la entrega de eventos.</p></div></div><div class="integration-grid"><article class="panel integration-card"><span class="metric-icon blue"><Wallet :size="24"/></span><p class="eyebrow">EXPERIENCIA DEL CLIENTE</p><h3>CP Banca Virtual</h3><p>Cuentas, solicitudes, transferencias y seguimiento de gestiones.</p><StatusBadge :value="integrations?.banca.configurada?'configurada':'por_configurar'"/><small>{{ integrations?.banca.modo }}</small></article><article class="panel integration-card"><span class="metric-icon teal"><ShieldCheck :size="24"/></span><p class="eyebrow">DECISIONES DE CRÉDITO</p><h3>CP Analítica</h3><p>Evaluación de solicitudes y determinación del límite autorizado.</p><StatusBadge :value="integrations?.riesgos.configurada?'configurada':'por_configurar'"/><small>{{ integrations?.riesgos.modo }}</small></article></div><div class="notice">Para habilitar las conexiones necesitamos las direcciones y los contratos de API de ambos sistemas. Los eventos permanecen guardados hasta configurar su entrega.</div><section class="panel"><div class="panel-heading"><div><h3>Entrega de eventos</h3><p>{{ integrations?.eventos_pendientes || 0 }} pendientes · {{ integrations?.eventos_fallidos || 0 }} requieren revisión</p></div></div><div class="table-wrap"><table><thead><tr><th>EVENTO</th><th>ESTADO</th><th>INTENTOS</th><th>REGISTRO</th></tr></thead><tbody><tr v-for="event in integrations?.eventos" :key="event.id"><td>{{ event.type }}</td><td><StatusBadge :value="event.status"/></td><td>{{ event.attempts }}</td><td>{{ date(event.created_at) }}</td></tr></tbody></table><p v-if="!integrations?.eventos.length" class="empty-inline">Aún no hay eventos de integración.</p></div></section></section>
 <RecordsView v-else :module="module" :rows="rows" :pagination="pagination" :clients="lookups.clientes" :can="can" @action="action" @open="open" @page="value=>{page=value;load()}" @filter="value=>{state=value;page=1;load()}"/>
 <footer class="content-footer"><span>CP Gestiones <span>·</span> Información centralizada y actividad registrada</span><span v-if="updated">Actualizado {{ new Intl.DateTimeFormat('es-GT',{hour:'2-digit',minute:'2-digit',timeZone:'America/Guatemala'}).format(updated) }}</span></footer></main></div>
 <div v-if="toast" class="toast" role="status"><CheckCircle2 :size="20"/>{{ toast }}</div><OperationModal v-if="spec" :spec="spec" @close="spec=null" @saved="saved"/><DetailDrawer v-if="detail" :module="detail.module" :item="detail.item" :can="can" :current-user="session.user" @close="detail=null" @action="action"/>
</div></template>
