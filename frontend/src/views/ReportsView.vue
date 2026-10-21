<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { FileDown, Printer, Building2, UserRound, ArrowLeftRight, Eye } from 'lucide-vue-next'
import { get, money, label, message } from '../services/api'
import { payrollPdf, statementPdf, transactionsPdf, deliver } from '../services/pdf'
const props = defineProps({ can: Function, user: Object })
const zone = 'America/Guatemala'
const today = new Intl.DateTimeFormat('en-CA', { timeZone: zone }).format(new Date())
const monthStart = `${today.slice(0, 8)}01`
const options = ref({ empresas: [], clientes: [], tipos_movimiento: [] }), optionsError = ref('')
const filters = reactive({
  planillas: { empresa_id: '', estado: '', desde: '', hasta: '' },
  cuentas: { cliente_id: '', cuenta_id: '', desde: monthStart, hasta: today },
  transacciones: { desde: monthStart, hasta: today, tipo: '' },
})
const state = reactive({ planillas: {}, cuentas: {}, transacciones: {} })
const reports = [
  { key: 'planillas', permission: 'reportes.planillas', path: '/reportes/planillas', icon: Building2, title: 'Pago de planillas por empresa', text: 'Planillas cargadas y pagadas de una empresa, con el detalle de cada colaborador.', build: payrollPdf },
  { key: 'cuentas', permission: 'reportes.cuentas', path: '/reportes/estado-cuenta', icon: UserRound, title: 'Estado de cuenta de un cliente', text: 'Saldo inicial, movimientos y saldo final de sus cuentas, más sus préstamos y tarjetas.', build: statementPdf },
  { key: 'transacciones', permission: 'reportes.transacciones', path: '/reportes/transacciones', icon: ArrowLeftRight, title: 'Transacciones globales', text: 'Todos los movimientos de los clientes consolidados, con totales por tipo.', build: transactionsPdf },
]
const visible = computed(() => reports.filter(r => props.can(r.permission)))
const accounts = computed(() => options.value.clientes.find(c => c.id === filters.cuentas.cliente_id)?.cuentas || [])
onMounted(async () => {
  try {
    options.value = await get('/reportes/opciones')
    if (options.value.empresas.length === 1) filters.planillas.empresa_id = options.value.empresas[0].id
  } catch (e) { optionsError.value = message(e) }
})
async function fetchReport(report) {
  const params = Object.fromEntries(Object.entries(filters[report.key]).filter(([, v]) => v))
  return get(report.path, params)
}
async function run(report, mode) {
  const s = state[report.key]
  s.busy = mode; s.error = ''
  try {
    const data = await fetchReport(report)
    if (mode === 'preview') s.data = data
    else deliver(await report.build(data), mode)
    s.data = data
  } catch (e) { s.error = message(e) } finally { s.busy = '' }
}
const summary = (key, data) => {
  if (!data) return []
  if (key === 'planillas') return [['Planillas', `${data.totales.planillas} (${data.totales.pagadas} pagadas)`], ['Total pagado', money(data.totales.pagado_centavos)], ['Pendiente', money(data.totales.pendiente_centavos)], ['Pagos a colaboradores', data.totales.beneficiarios]]
  if (key === 'cuentas') return [['Cliente', data.cliente.nombre], ...data.cuentas.map(c => [`Cuenta ${c.cuenta.numero}`, `${c.movimientos.length} movimientos · saldo final ${money(c.saldo_final_centavos)}`]), ['Préstamos', data.prestamos.length], ['Tarjetas', data.tarjetas.length]]
  return [['Movimientos', data.totales.movimientos], ['Clientes', data.totales.clientes], ['Ingresos', money(data.totales.ingresos_centavos)], ['Salidas', money(data.totales.egresos_centavos)]]
}
</script>
<template>
  <section>
    <div class="section-heading"><div><h2>Reportes</h2><p>Elige los filtros y descarga el reporte en PDF o imprímelo. Cada reporte incluye el tipo de cambio de referencia del Banco de Guatemala.</p></div></div>
    <div v-if="optionsError" class="error-box" role="alert">{{ optionsError }}</div>
    <div class="reports-grid">
      <article v-for="report in visible" :key="report.key" class="panel report-card">
        <div class="report-head"><span class="metric-icon blue"><component :is="report.icon" :size="20"/></span><div><h3>{{ report.title }}</h3><p>{{ report.text }}</p></div></div>
        <form class="report-filters" @submit.prevent="run(report, 'pdf')">
          <template v-if="report.key === 'planillas'">
            <label class="full">Empresa<select v-model="filters.planillas.empresa_id" required><option disabled value="">Selecciona una empresa</option><option v-for="c in options.empresas" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></label>
            <label>Estado<select v-model="filters.planillas.estado"><option value="">Todos</option><option value="pagada">Pagadas</option><option value="pendiente_autorizacion">Por autorizar</option></select></label>
          </template>
          <template v-if="report.key === 'cuentas'">
            <label class="full">Cliente<select v-model="filters.cuentas.cliente_id" required @change="filters.cuentas.cuenta_id = ''"><option disabled value="">Selecciona un cliente</option><option v-for="c in options.clientes" :key="c.id" :value="c.id">{{ c.nombre }} · {{ c.dpi_enmascarado }}</option></select></label>
            <label>Cuenta<select v-model="filters.cuentas.cuenta_id" :disabled="!accounts.length"><option value="">Todas sus cuentas</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.numero }} · {{ label(a.tipo) }}</option></select></label>
          </template>
          <template v-if="report.key === 'transacciones'">
            <label>Tipo<select v-model="filters.transacciones.tipo"><option value="">Todos los tipos</option><option v-for="t in options.tipos_movimiento" :key="t" :value="t">{{ label(t) }}</option></select></label>
          </template>
          <label>Desde<input v-model="filters[report.key].desde" type="date" :max="today"></label>
          <label>Hasta<input v-model="filters[report.key].hasta" type="date" :min="filters[report.key].desde || undefined" :max="today"></label>
          <div v-if="state[report.key].error" class="error-box full" role="alert">{{ state[report.key].error }}</div>
          <dl v-if="state[report.key].data" class="report-summary full"><template v-for="[k, v] in summary(report.key, state[report.key].data)" :key="k"><dt>{{ k }}</dt><dd>{{ v }}</dd></template></dl>
          <div class="report-actions full">
            <button type="button" class="secondary" :disabled="Boolean(state[report.key].busy)" @click="$event.target.closest('form').reportValidity() && run(report, 'preview')"><Eye :size="16"/>{{ state[report.key].busy === 'preview' ? 'Consultando…' : 'Ver resumen' }}</button>
            <button type="button" class="secondary" :disabled="Boolean(state[report.key].busy)" @click="$event.target.closest('form').reportValidity() && run(report, 'print')"><Printer :size="16"/>{{ state[report.key].busy === 'print' ? 'Preparando…' : 'Imprimir' }}</button>
            <button class="primary" :disabled="Boolean(state[report.key].busy)"><FileDown :size="16"/>{{ state[report.key].busy === 'pdf' ? 'Generando…' : 'Descargar PDF' }}</button>
          </div>
        </form>
      </article>
    </div>
  </section>
</template>
