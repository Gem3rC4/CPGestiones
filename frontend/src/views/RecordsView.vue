<script setup>
import { computed, ref, watch } from 'vue'
import { Search, Plus, ArrowUpRight, ChevronLeft, ChevronRight, FileSearch, Download } from 'lucide-vue-next'
import StatusBadge from '../components/StatusBadge.vue'
import { money, label, date, identifier } from '../services/api'
const props = defineProps({ module: String, rows: Array, pagination: Object, clients: Array, can: Function })
defineEmits(['action','open','page','filter'])
const search = ref(''), state = ref('')
watch(() => props.module, () => { search.value = ''; state.value = '' })
const customer = id => props.clients.find(x => identifier(x) === id)?.nombre || 'Cliente registrado'
const configurations = {
  solicitudes: { title:'Gestiones',subtitle:'Revisa los trámites, asigna el límite aprobado y emite el producto.',create:'Nueva gestión',permission:'solicitudes.write',columns:['GESTIÓN / CLIENTE','PRODUCTO','MONTO SOLICITADO','ESTADO','RECIBIDA'],states:['en_tramite','emitida','rechazada'] },
  creditos: { title:'Créditos autorizados',subtitle:'Créditos emitidos por el banco. La autorización no realiza un desembolso.',columns:['REFERENCIA / TITULAR','PRODUCTO','MONTO AUTORIZADO','SALDO','ESTADO'] },
  cuentas: { title:'Cuentas bancarias',subtitle:'Consulta saldos y movimientos de cuentas de ahorro y monetarias.',create:'Abrir cuenta',permission:'cuentas.write',columns:['CUENTA / TITULAR','TIPO','SALDO DISPONIBLE','ESTADO','APERTURA'],states:['activa','bloqueada'] },
  tarjetas: { title:'Tarjetas emitidas',subtitle:'Controla límites, consumos y pagos de tarjetas.',columns:['REFERENCIA / TITULAR','PRODUCTO','LÍMITE','DEUDA ACTUAL','ESTADO'] },
  clientes: { title:'Directorio de clientes',subtitle:'Clientes de Banca Virtual y registros asistidos del banco.',create:'Registrar cliente',permission:'clientes.write',columns:['CLIENTE','IDENTIFICACIÓN','CORREO','INGRESO MENSUAL','REGISTRO'] },
  productos: { title:'Productos y políticas',subtitle:'Catálogo de cuentas y tarjetas. Las tasas son políticas académicas configurables.',columns:['PRODUCTO','TIPO','TASA ANUAL','LÍMITE MÁXIMO','ESTADO'] },
  usuarios: { title:'Usuarios internos y roles',subtitle:'Administra el acceso del personal y los perfiles corporativos.',create:'Crear usuario interno',permission:'usuarios.write',columns:['USUARIO','CORREO','ROL','ESTADO','REGISTRO'] },
  empresas: { title:'Banca corporativa',subtitle:'Empresas, cuentas de dispersión y equipos autorizados.',create:'Registrar empresa',permission:'empresas.write',columns:['EMPRESA','NIT','PERSONAS AUTORIZADAS','REGISTRO'] },
  planillas: { title:'Planillas y autorizaciones',subtitle:'Carga, revisión por una segunda persona y dispersión de pagos.',create:'Cargar planilla',permission:'planillas.write',columns:['PLANILLA','BENEFICIARIOS','TOTAL A PAGAR','ESTADO','REGISTRO'],states:['pendiente_autorizacion','en_cola','pagada'] },
  bitacora: { title:'Bitácora de gestiones',subtitle:'Historial de solicitudes de tarjetas y aprobaciones. Solo lectura.',columns:['ACCIÓN','SOLICITUD','ESTADO','RESPONSABLE','FECHA'] },
  seguridad: { title:'Actividad y seguridad',subtitle:'Accesos, cambios de permisos y operaciones registradas.',columns:['ACCIÓN','RESPONSABLE','ORIGEN','FECHA'] },
}
const config = computed(() => configurations[props.module])
const filtered = computed(() => props.rows.filter(r => JSON.stringify(r).toLowerCase().includes(search.value.toLowerCase()) || customer(r.cliente_id).toLowerCase().includes(search.value.toLowerCase())))
function cells(r) {
 switch(props.module) {
  case 'solicitudes': return [[r.folio,customer(r.cliente_id)],label(r.producto),money(r.limite_solicitado_centavos),{badge:r.estado},date(r.created_at)]
  case 'creditos': return [[r.referencia,customer(r.cliente_id)],label(r.producto),money(r.limite_centavos),money(r.saldo_centavos),{badge:r.estado}]
  case 'cuentas': return [[r.numero,customer(r.cliente_id)],label(r.tipo),money(r.saldo_centavos),{badge:r.estado},date(r.created_at)]
  case 'tarjetas': return [[r.referencia,customer(r.cliente_id)],label(r.producto),money(r.limite_centavos),money(r.principal_centavos+r.interes_centavos),{badge:r.estado}]
  case 'clientes': return [[r.nombre,r.external_ref],r.dpi_enmascarado,r.email,money(r.ingreso_centavos),date(r.created_at)]
  case 'productos': return [[r.name,r.code],label(r.type),r.annual_bps == null ? 'Por definir' : `${r.annual_bps/100}%`,r.max_limit_cents == null ? 'Según evaluación' : money(r.max_limit_cents),{badge:r.active}]
  case 'usuarios': return [r.name,r.email,label(r.role),{badge:r.active},date(r.created_at)]
  case 'empresas': return [r.nombre,r.nit,r.miembros?.length,date(r.created_at)]
  case 'planillas': return [[r.nombre,r.ultimo_error || 'Doble autorización'],r.pagos?.length,money(r.total_centavos),{badge:r.estado},date(r.created_at)]
  case 'bitacora': return [label(r.action),r.solicitud_id,{badge:r.estado},r.actor,date(r.created_at)]
  case 'seguridad': return [label(r.action),r.actor,r.ip,date(r.created_at)]
 }
}
</script>
<template><section><div class="section-heading"><div><h2>{{ config.title }}</h2><p>{{ config.subtitle }}</p></div><button v-if="config.create && can(config.permission)" class="primary" @click="$emit('action', module)"><Plus :size="17"/>{{ config.create }}</button></div><div v-if="module === 'cuentas'" class="action-strip"><button v-if="can('dinero.write')" class="secondary" @click="$emit('action','transferencia')">Transferir entre cuentas <ArrowUpRight :size="16"/></button><button v-if="can('caja.write')" class="secondary" @click="$emit('action','caja')">Depósito o retiro <ArrowUpRight :size="16"/></button></div><section class="panel records-panel"><div class="table-toolbar"><label class="search-box"><Search :size="17"/><input v-model="search" placeholder="Buscar en esta página…" aria-label="Buscar en los registros de esta página"></label><select v-if="config.states" v-model="state" aria-label="Filtrar por estado" @change="$emit('filter',state)"><option value="">Todos los estados</option><option v-for="s in config.states" :key="s" :value="s">{{ label(s) }}</option></select><span class="record-count">{{ pagination?.total || 0 }} registros</span></div><div class="table-wrap"><table><thead><tr><th v-for="column in config.columns" :key="column">{{ column }}</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody><tr v-for="row in filtered" :key="identifier(row)"><td v-for="(cell,index) in cells(row)" :key="index"><span v-if="Array.isArray(cell)" class="stack-cell"><b>{{ cell[0] }}</b><small>{{ cell[1] }}</small></span><StatusBadge v-else-if="cell !== null && typeof cell === 'object'" :value="cell.badge"/><span v-else>{{ cell }}</span></td><td><button class="icon-button" aria-label="Ver detalles" @click="$emit('open',row)"><ArrowUpRight :size="18"/></button></td></tr></tbody></table><div v-if="!filtered.length" class="empty-state"><FileSearch :size="32"/><h3>{{ search || state ? 'Sin coincidencias' : 'Todavía no hay registros' }}</h3><p>{{ search || state ? 'Prueba con otro término o cambia el filtro.' : 'Los nuevos registros aparecerán en esta sección.' }}</p></div></div><div class="pagination"><span>Página {{ pagination?.current_page || 1 }} de {{ pagination?.last_page || 1 }}</span><div><button class="secondary compact" :disabled="!pagination || pagination.current_page <= 1" aria-label="Página anterior" @click="$emit('page',pagination.current_page - 1)"><ChevronLeft :size="17"/></button><button class="secondary compact" :disabled="!pagination || pagination.current_page >= pagination.last_page" aria-label="Página siguiente" @click="$emit('page',pagination.current_page + 1)"><ChevronRight :size="17"/></button></div></div></section></section></template>
