<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue'
import { LayoutDashboard, ClipboardList, Wallet, CreditCard, Building2, Users, ShieldCheck, ScrollText, PlugZap, RefreshCw, LogOut, Menu, CheckCircle2, Boxes, Activity, ArrowLeftRight, Contact, FileText, HandCoins } from 'lucide-vue-next'
import LoginView from './views/LoginView.vue'
import DashboardView from './views/DashboardView.vue'
import RecordsView from './views/RecordsView.vue'
import ReportsView from './views/ReportsView.vue'
import OperationModal from './components/OperationModal.vue'
import ReceiptModal from './components/ReceiptModal.vue'
import DetailDrawer from './components/DetailDrawer.vue'
import StatusBadge from './components/StatusBadge.vue'
import NotificationBell from './components/NotificationBell.vue'
import IntegrationTokens from './components/IntegrationTokens.vue'
import IntegrationConnections from './components/IntegrationConnections.vue'
import { api, get, setSession, hasToken, message, label, date, identifier, money } from './services/api'
import { formSpec } from './services/forms'
const session=ref(null), expired=ref(false), module=ref('dashboard'), sidebar=ref(false), busy=ref(false), error=ref(''), toast=ref(''), updated=ref(null)
const dashboard=ref(null), rows=ref([]), pagination=ref(null), integrations=ref(null), spec=ref(null), detail=ref(null)
// Server-side query of the current listing: page, status, text, order and dates.
const query=reactive({page:1,estado:'',q:'',orden:'',direccion:'',desde:'',hasta:''})
const lookups=reactive({clientes:[],cuentas:[],productos:[],empresas:[],colaboradores:[],candidatos:[]})
const lookupError=ref('')
let generation=0, toastTimer
const can=permission=>[].concat(permission).some(p=>session.value?.permissions.includes('*') || session.value?.permissions.includes(p))
const nav=[
 {key:'dashboard',name:'Resumen general',icon:LayoutDashboard,group:'OPERACIÓN',permission:'dashboard.read'},
 {key:'solicitudes',name:'Gestiones',icon:ClipboardList,group:'OPERACIÓN',permission:'solicitudes.read'},
 {key:'cuentas',name:'Cuentas',icon:Wallet,group:'OPERACIÓN',permission:'cuentas.read'},
 {key:'creditos',name:'Préstamos',icon:HandCoins,group:'OPERACIÓN',permission:'creditos.read'},
 {key:'tarjetas',name:'Tarjetas',icon:CreditCard,group:'OPERACIÓN',permission:'tarjetas.read'},
 {key:'movimientos',name:'Transacciones',icon:ArrowLeftRight,group:'OPERACIÓN',permission:'movimientos.read'},
 {key:'reportes',name:'Reportes',icon:FileText,group:'OPERACIÓN',permission:['reportes.planillas','reportes.cuentas','reportes.transacciones']},
 {key:'empresas',name:'Empresas',icon:Building2,group:'BANCA CORPORATIVA',permission:'empresas.read'},
 {key:'colaboradores',name:'Colaboradores',icon:Contact,group:'BANCA CORPORATIVA',permission:'colaboradores.read'},
 {key:'planillas',name:'Planillas',icon:Users,group:'BANCA CORPORATIVA',permission:'planillas.read'},
 {key:'clientes',name:'Clientes',icon:Users,group:'ADMINISTRACIÓN',permission:'clientes.read'},
 {key:'productos',name:'Productos y políticas',icon:Boxes,group:'ADMINISTRACIÓN',permission:'productos.read'},
 {key:'usuarios',name:'Usuarios del banco',icon:ShieldCheck,group:'ADMINISTRACIÓN',permission:'usuarios.read'},
 {key:'bitacora',name:'Bitácora de gestiones',icon:ScrollText,group:'ADMINISTRACIÓN',permission:'bitacora.read'},
 {key:'seguridad',name:'Actividad y seguridad',icon:Activity,group:'ADMINISTRACIÓN',permission:'seguridad.read'},
 {key:'integraciones',name:'Integraciones',icon:PlugZap,group:'ECOSISTEMA',permission:'integraciones.read'},
]
// Each menu group is a module; a role only sees the modules it has (Administración is administrator only).
const groupPermission={'OPERACIÓN':'modulo.operaciones','BANCA CORPORATIVA':'modulo.corporativo','ADMINISTRACIÓN':'modulo.administracion','ECOSISTEMA':'modulo.administracion'}
// Reports live in Operaciones; a corporate-only role finds its payroll report under Banca corporativa.
const groupOf=n=>n.key==='reportes' && !can('modulo.operaciones') ? 'BANCA CORPORATIVA' : n.group
const allowed=computed(()=>{ const items=nav.map(n=>({...n,group:groupOf(n)})).filter(n=>can(n.permission) && can(groupPermission[n.group])); const moved=items.filter(n=>n.key==='reportes' && n.group!=='OPERACIÓN'); return [...items.filter(n=>!moved.includes(n)),...moved] })
const current=computed(()=>nav.find(n=>n.key===module.value))
// Phones: up to four main sections in a bottom bar; the rest stay in the menu.
const bottomNav=computed(()=>{ const preferred=['dashboard','solicitudes','cuentas','clientes']; const items=preferred.map(k=>allowed.value.find(n=>n.key===k)).filter(Boolean); return (items.length ? items : allowed.value).slice(0,4) })
const shortName={dashboard:'Resumen',solicitudes:'Gestiones',cuentas:'Cuentas',clientes:'Clientes',movimientos:'Transacciones',reportes:'Reportes',empresas:'Empresa',colaboradores:'Equipo',planillas:'Planillas'}
// While the drawer is open the page behind it must not scroll; Escape closes it.
watch(sidebar, open=>{ document.body.style.overflow = open ? 'hidden' : '' })
function closeOnEscape(event){ if(event.key==='Escape' && sidebar.value) sidebar.value=false }
const navGroups=computed(()=>[...new Set(allowed.value.map(n=>n.group))])
const initials=computed(()=>session.value?.user?.name?.split(' ').slice(0,2).map(x=>x[0]).join('') || 'CP')
const roleLabel=computed(()=>session.value?.empresa ? `Empresa · ${session.value.empresa.nombre}` : label(session.value?.user?.role))
function clearData() { generation++; dashboard.value=null;rows.value=[];pagination.value=null;integrations.value=null;updated.value=null;error.value='';lookupError.value='';for(const key of Object.keys(lookups)) lookups[key]=[] }
function resetQuery() { Object.assign(query,{page:1,estado:'',q:'',orden:'',direccion:'',desde:'',hasta:''}) }
const isLocalHost=['localhost','127.0.0.1'].includes(window.location.hostname)
const MODULE_KEY='cp-gestiones.module'
const remembered=()=>{ try { return sessionStorage.getItem(MODULE_KEY) } catch { return null } }
watch(module, value=>{ try { sessionStorage.setItem(MODULE_KEY, value) } catch {} })
async function authenticated(data, preferred=null) {
 clearData(); resetQuery(); session.value={user:data.user, permissions:data.permissions || [], empresa:data.empresa || null}; expired.value=false
 const home=data.user?.role==='empresa' ? 'planillas' : allowed.value[0]?.key
 module.value=allowed.value.some(n=>n.key===preferred) ? preferred : (home || 'reportes')
 if(!session.value.empresa && data.user?.role==='empresa') get('/auth/me').then(me=>{ if(session.value) session.value.empresa=me.empresa }).catch(()=>{})
 await loadLookups(); await load()
}
// A reload keeps the tab's tokens: validate them with the API (the access token is renewed if it expired).
const restoring=ref(hasToken()), restoreError=ref('')
async function restoreSession() {
 restoreError.value=''
 try {
  const me=(await api.get('/auth/me', { timeout: 70000 })).data.data
  if(!me.user) { setSession(null); restoring.value=false; return }
  restoring.value=false
  await authenticated({ user: me.user, permissions: me.permissions || [], empresa: me.empresa }, remembered())
 } catch(e) {
  if(e.response?.status===401) { restoring.value=false; return }
  restoreError.value=message(e)
 }
}
function abandonRestore() { setSession(null); restoring.value=false; restoreError.value='' }
async function loadLookups() {
  const owner=session.value
  lookupError.value=''
  const tasks=['clientes','cuentas','productos','empresas','colaboradores'].filter(k=>can(`${k}.read`)).map(async key=>{
    const records=[]
    let currentPage=1,lastPage=1
    do {
      if(session.value!==owner) return
      const result=await get(`/${key}`,{per_page:100,page:currentPage})
      records.push(...result.data);lastPage=result.last_page;currentPage++
    } while(currentPage<=lastPage)
    if(session.value===owner) lookups[key]=records
  })
  // People who can be assigned as collaborators (corporate banking does not read the personal directory).
  if(can('colaboradores.write')) tasks.push(get('/colaboradores/candidatos').then(rows=>{ if(session.value===owner) lookups.candidatos=rows }))
  const results=await Promise.allSettled(tasks)
  if(session.value===owner && results.some(r=>r.status==='rejected')) lookupError.value='No fue posible cargar algunos catálogos. Actualiza para volver a intentar.'
}
async function load() {
 if(!session.value || module.value==='reportes') { updated.value=new Date(); return }
 const request=++generation, target=module.value
 busy.value=true; error.value=''
 try {
  const params=Object.fromEntries(Object.entries(query).filter(([,v])=>v!=='' && v!==null))
  const data=await get(target==='dashboard'?'/dashboard':target==='integraciones'?'/integraciones':`/${target}`,target==='dashboard'||target==='integraciones'?{}:params)
  if(request!==generation) return
  if(target==='dashboard') dashboard.value=data
  else if(target==='integraciones') integrations.value=data
  else {rows.value=data.data;pagination.value=data}
  updated.value=new Date()
 } catch(e) { if(request===generation) error.value=message(e) }
 finally { if(request===generation) busy.value=false }
}
const retrying=ref(false)
const eventLabel=type=>({'banca.abono':'Abono a BancaVirtual','banca.cuenta':'Cuenta en BancaVirtual','cuenta.movimiento':'Aviso de movimiento','solicitud.actualizada':'Gestión actualizada','riesgo.solicitado':'Evaluación de riesgo','conexion.prueba':'Prueba de conexión'}[type] || type)
const eventDetail=e=>e.type==='banca.cuenta' ? `Cuenta ${label(e.payload?.cuenta_tipo).toLowerCase()} ${e.payload?.numero}` : e.type==='banca.abono' ? `${money(e.payload?.monto_centavos)} · ${e.payload?.concepto || ''}` : e.payload?.concepto || e.payload?.estado || e.payload?.folio || '—'
async function retryEvents(){ retrying.value=true; try { const r=(await api.post('/integraciones/eventos/reintentar')).data.data; toast.value=`${r.reintentados} evento(s) reintentado(s); ${r.enviados_ahora} entregado(s) ahora.`; clearTimeout(toastTimer); toastTimer=setTimeout(()=>toast.value='',6000); await load() } catch(e){ error.value=message(e) } finally { retrying.value=false } }
function navigate(key) { module.value=key;sidebar.value=false;resetQuery();detail.value=null;load() }
function changeQuery(patch) { Object.assign(query,{page:1},patch);load() }
async function refresh() { await loadLookups();await load() }
function action(kind,item={}) { if(kind==='comprobante') return showReceipt(identifier(item)); if(kind==='eliminar-cliente') return confirmDelete(item); if(kind==='eliminar-empresa') return confirmDeleteCompany(item); if(kind==='ingreso-concentradora') return openVault(); if(lookupError.value) return;spec.value=formSpec(kind,item,lookups);detail.value=null }
// Opening receipt: the account exactly as stored, with its holder's file, ready to download as PDF.
const receipt=ref(null)
// Deleting a customer is never blocked: the summary only decides whether the warning is shown.
async function confirmDelete(item) { error.value=''; try { const resumen=await get(`/clientes/${identifier(item)}/eliminacion`); detail.value=null; spec.value=formSpec('eliminar-cliente',{...item,resumen},lookups) } catch(e) { error.value=message(e) } }
async function confirmDeleteCompany(item) { error.value=''; try { const resumen=await get(`/empresas/${identifier(item)}/eliminacion`); detail.value=null; spec.value=formSpec('eliminar-empresa',{...item,resumen},lookups) } catch(e) { error.value=message(e) } }
async function openVault() { error.value=''; try { spec.value=formSpec('ingreso-concentradora',await get('/concentradora'),lookups) } catch(e) { error.value=message(e) } }
async function showReceipt(accountId) { error.value=''; try { receipt.value=await get(`/cuentas/${accountId}/comprobante`); detail.value=null } catch(e) { error.value=message(e) } }
async function openAlert(alert) {
 if(!alert.recurso) return
 const key=alert.recurso
 if(!can(`${key}.read`)) return
 navigate(key)
 if(key==='solicitudes') detail.value={module:key,item:{id:alert.recurso_id}}
 else { await loadLookups(); const item=lookups[key]?.find(x=>identifier(x)===alert.recurso_id); if(item && module.value===key) detail.value={module:key,item} }
}
function open(item) { detail.value={module:module.value==='dashboard'?'solicitudes':module.value,item} }
async function saved(result, done) {
 spec.value=null
 if(done?.receipt && identifier(result)) { await showReceipt(identifier(result)); await refresh(); return }
 toast.value=result?.limpieza ? 'Datos reiniciados: puedes registrar clientes desde cero.' : result?.eliminada ? `Empresa ${result.empresa?.nombre || ''} eliminada.` : result?.concepto && result?.saldo_anterior_centavos !== undefined ? `Fondos ingresados: ${money(result.monto_centavos)}. Saldo actual de la concentradora: ${money(result.saldo_centavos)}.` : result?.eliminado ? `Cliente ${result.cliente?.nombre || ''} eliminado.` : result?.codigo==='APROBADA' ? `Compra aprobada en ${result.comercio}: ${money(result.monto_centavos)}. Disponible ${money(result.disponible_centavos)}.` : done?.path==='/clientes' ? `Cliente ${result?.nombre || ''} registrado. Ahora créale su cuenta en Cuentas.` : result?.cuenta?.numero ? `Cliente registrado con su cuenta ${label(result.cuenta.tipo).toLowerCase()} ${result.cuenta.numero}${result.acceso_app ? ' y acceso a Banca Virtual' : ''}.` : result?.estado==='pagada' && result?.pagos ? `Planilla pagada: ${money(result.total_centavos)} acreditados a ${result.pagos.length} colaboradores.` : 'La operación se guardó correctamente.'
 clearTimeout(toastTimer);toastTimer=setTimeout(()=>toast.value='',7000);await refresh()
}
async function logout() { try {await api.post('/auth/logout')} catch {} finally {session.value=null;setSession(null);detail.value=null;spec.value=null;clearData()} }
function sessionExpired(){session.value=null;expired.value=true;detail.value=null;spec.value=null;clearData()}
onMounted(()=>{ window.addEventListener('session-expired',sessionExpired); window.addEventListener('keydown',closeOnEscape); if(restoring.value) restoreSession() })
onUnmounted(()=>{window.removeEventListener('session-expired',sessionExpired);window.removeEventListener('keydown',closeOnEscape);document.body.style.overflow='';clearTimeout(toastTimer)})
</script>
<template><main v-if="restoring" class="restore-screen" aria-live="polite"><img class="brand-logo" src="/logo-cp.png" alt="CP Gestiones" width="40" height="40"><template v-if="!restoreError"><RefreshCw :size="18" class="spinning"/><p>Restableciendo tu sesión…</p><small>Si el servidor estaba inactivo puede tardar hasta un minuto.</small></template><template v-else><div class="error-box" role="alert">{{ restoreError }}</div><div class="restore-actions"><button class="primary" @click="restoreSession">Reintentar</button><button class="secondary" @click="abandonRestore">Ingresar de nuevo</button></div></template></main><LoginView v-else-if="!session" :expired="expired" @authenticated="authenticated"/><div v-else class="app-shell">
 <button v-if="sidebar" class="sidebar-backdrop" aria-label="Cerrar menú" @click="sidebar=false"></button><nav class="bottom-nav" aria-label="Navegación principal"><button v-for="item in bottomNav" :key="item.key" :class="{active:module===item.key}" :aria-current="module===item.key ? 'page' : undefined" @click="navigate(item.key)"><component :is="item.icon" :size="21"/><span>{{ shortName[item.key] || item.name }}</span></button><button :class="{active:sidebar}" aria-label="Abrir menú completo" @click="sidebar=true"><Menu :size="21"/><span>Menú</span></button></nav>
 <aside class="sidebar" :class="{shown:sidebar}"><a class="brand" href="#" @click.prevent="navigate(allowed[0].key)"><img class="brand-logo" src="/logo-cp.png" alt="CP Gestiones" width="40" height="40"><span>CP GESTIONES<small>PORTAL DE OPERACIONES</small></span></a><div class="workspace-label"><span class="workspace-icon"><Building2 :size="17"/></span><span>{{ session.empresa?.nombre || 'CreditPulse' }}<small>{{ session.empresa ? 'Banca corporativa' : 'Banca y operaciones' }}</small></span><span class="environment-pill">{{ isLocalHost ? 'LOCAL' : 'EN LÍNEA' }}</span></div><nav aria-label="Navegación principal"><div v-for="group in navGroups" :key="group" class="nav-group"><p>{{ group }}</p><button v-for="item in allowed.filter(n=>n.group===group)" :key="item.key" :class="{active:module===item.key}" :aria-current="module===item.key?'page':undefined" @click="navigate(item.key)"><component :is="item.icon" :size="18"/><span>{{ item.name }}</span><span v-if="item.key==='solicitudes' && dashboard?.pendientes" class="nav-count">{{ dashboard.pendientes }}</span></button></div></nav><div class="sidebar-footer"><ShieldCheck :size="18"/><span>Acceso según tu rol<small>{{ label(session.user.role) }}</small></span></div></aside>
 <div class="main-shell"><header class="topbar"><div class="breadcrumb"><button class="icon-button menu-toggle" aria-label="Abrir menú" @click="sidebar=true"><Menu :size="22"/></button><span>Portal de operaciones</span><span class="breadcrumb-slash">/</span><b>{{ current?.name }}</b></div><div class="topbar-right"><NotificationBell v-if="can(['clientes.read','cuentas.read','solicitudes.read'])" :key="identifier(session.user)" @open="openAlert" @received="refresh"/><span class="connection-status"><span class="live-dot"></span>Sesión activa</span><span class="topbar-divider"></span><span class="user-avatar">{{ initials }}</span><span class="user-info"><b>{{ session.user.name }}</b><small>{{ roleLabel }}</small></span><button class="icon-button" aria-label="Cerrar sesión" title="Cerrar sesión" @click="logout"><LogOut :size="19"/></button></div></header>
 <main class="content"><div class="page-topline"><p><span class="live-dot"></span> CONTROL OPERATIVO</p><button class="refresh-button" :disabled="busy" @click="refresh"><RefreshCw :size="14" :class="{spinning:busy}"/>{{ busy ? 'Actualizando…' : 'Actualizar' }}</button></div><div v-if="error || lookupError" class="error-box" role="alert">{{ error || lookupError }}<button class="text-button" @click="refresh">Reintentar</button></div><div v-if="busy" class="loading-line" aria-label="Cargando"></div>
 <DashboardView v-if="module==='dashboard'" :data="dashboard" :clients="lookups.clientes" :can="can" @navigate="navigate" @action="action" @open="open" @refresh="refresh"/>
 <ReportsView v-else-if="module==='reportes'" :can="can" :user="session.user"/>
 <section v-else-if="module==='integraciones'"><div class="section-heading"><div><h2>Un banco. Tres sistemas conectados.</h2><p>Supervisa los puntos de conexión y la entrega de eventos.</p></div></div><div class="integration-grid"><article class="panel integration-card"><span class="metric-icon blue"><Wallet :size="24"/></span><p class="eyebrow">EXPERIENCIA DEL CLIENTE</p><h3>CP Banca Virtual</h3><p>Cuentas, solicitudes, transferencias, préstamos y seguimiento de gestiones.</p><StatusBadge :value="integrations?.banca.configurada?'configurada':'por_configurar'"/><small>{{ integrations?.banca.modo }}</small></article><article class="panel integration-card"><span class="metric-icon teal"><ShieldCheck :size="24"/></span><p class="eyebrow">DECISIONES DE CRÉDITO</p><h3>CP Analítica</h3><p>Evaluación de solicitudes y determinación del límite autorizado.</p><StatusBadge :value="integrations?.riesgos.configurada?'configurada':'por_configurar'"/><small>{{ integrations?.riesgos.modo }}</small></article><article class="panel integration-card"><span class="metric-icon amber"><Activity :size="24"/></span><p class="eyebrow">API EXTERNA</p><h3>Tipo de cambio</h3><p>Referencia oficial del Banco de Guatemala (servicio SOAP), con respaldo en ExchangeRate-API.</p><StatusBadge value="configurada"/><small>Se usa en el resumen y en los reportes PDF</small></article></div><IntegrationConnections v-if="can('integraciones.write')"/><IntegrationTokens v-if="can('integraciones.write')"/><section class="panel"><div class="panel-heading"><div><h3>Entrega de eventos</h3><p>{{ integrations?.eventos_pendientes || 0 }} pendientes · {{ integrations?.eventos_fallidos || 0 }} requieren revisión. Se envían cada minuto, con reintentos automáticos.</p></div><button v-if="can('integraciones.write') && integrations?.eventos_fallidos" class="secondary" :disabled="retrying" @click="retryEvents"><RefreshCw :size="15" :class="{spinning:retrying}"/>{{ retrying ? 'Reintentando…' : 'Reintentar fallidos' }}</button></div><div class="table-wrap"><table class="responsive-table"><thead><tr><th>EVENTO</th><th>DETALLE</th><th>ESTADO</th><th>INTENTOS</th><th>REGISTRO</th></tr></thead><tbody><tr v-for="event in integrations?.eventos" :key="event.id"><td>{{ eventLabel(event.type) }}</td><td data-label="DETALLE"><span class="stack-cell"><b>{{ eventDetail(event) }}</b><small v-if="event.last_error" class="error-text">{{ event.last_error }}</small><small v-else-if="event.respuesta && event.type==='banca.cuenta'">{{ event.respuesta.creada ? 'Registrada en BancaVirtual con el mismo número' : 'BancaVirtual ya la tenía con el mismo número' }}</small><small v-else-if="event.respuesta">{{ event.respuesta.duplicado ? 'Ya estaba acreditado (no se duplicó)' : `Acreditado · saldo en Banca Q ${event.respuesta.saldoDespues ?? '—'}` }}</small></span></td><td data-label="ESTADO"><StatusBadge :value="event.status"/></td><td data-label="INTENTOS">{{ event.attempts }}</td><td data-label="REGISTRO">{{ date(event.created_at) }}</td></tr></tbody></table><p v-if="!integrations?.eventos.length" class="empty-inline">Aún no hay eventos de integración.</p></div></section><section v-if="session.user.protegido" class="panel maintenance-panel"><div class="panel-heading"><div><h3>Mantenimiento</h3><p>Reinicia los datos de clientes y banca para comenzar de nuevo. Solo el administrador principal puede hacerlo.</p></div><button class="danger-outline" @click="action('limpieza-total')">Reiniciar datos</button></div></section></section>
 <RecordsView v-else :module="module" :rows="rows" :pagination="pagination" :query="query" :clients="lookups.clientes" :companies="lookups.empresas" :can="can" @action="action" @open="open" @query="changeQuery"/>
 <footer class="content-footer"><span>CP Gestiones <span>·</span> Información centralizada y actividad registrada</span><span v-if="updated">Actualizado {{ new Intl.DateTimeFormat('es-GT',{hour:'2-digit',minute:'2-digit',timeZone:'America/Guatemala'}).format(updated) }}</span></footer></main></div>
 <div v-if="toast" class="toast" role="status"><CheckCircle2 :size="20"/>{{ toast }}</div><OperationModal v-if="spec" :spec="spec" @close="spec=null" @saved="saved"/><ReceiptModal v-if="receipt" :receipt="receipt" @close="receipt=null"/><DetailDrawer v-if="detail" :module="detail.module" :item="detail.item" :can="can" :current-user="session.user" :lookups="lookups" @close="detail=null" @action="action" @changed="refresh"/>
</div></template>
