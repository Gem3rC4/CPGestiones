<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { X, Plus, Trash2, ArrowRight, ShieldCheck, Users, Calculator } from 'lucide-vue-next'
import { mutate, message, identifier, money } from '../services/api'
const props = defineProps({ spec: Object })
const emit = defineEmits(['close', 'saved'])
const dialog = ref(), busy = ref(false), error = ref(''), reviewing = ref(false)
const data = reactive(Object.fromEntries(props.spec.fields.map(f => [f.key, f.value ?? (f.type === 'multiple' ? [] : '')])))
const payments = ref([{ nombre: '', cuenta_numero: '', monto: '' }])
let key = crypto.randomUUID(), submittedFingerprint = null
// Fields can depend on others: shown only for a role, or options that follow the selected customer.
const visibleFields = computed(() => props.spec.fields.filter(f => !f.showIf || f.showIf(data)))
const optionsOf = f => f.optionsFrom ? f.optionsFrom(data) : f.options
for (const f of props.spec.fields.filter(f => f.optionsFrom)) {
  watch(() => optionsOf(f), options => { if (!options.some(o => String(o.value) === String(data[f.key]))) data[f.key] = options.length === 1 ? options[0].value : '' }, { immediate: true })
}
const preview = computed(() => props.spec.preview?.(data) || '')
// Payroll: pick the selected company's collaborators, optionally filtered by position.
const position = ref('')
const companyCollaborators = computed(() => (props.spec.collaborators || []).filter(c => c.empresa_id === data.empresa_id && c.activo !== false))
const positions = computed(() => [...new Set(companyCollaborators.value.map(c => c.puesto))].sort())
const visibleCollaborators = computed(() => companyCollaborators.value.filter(c => !position.value || c.puesto === position.value))
const pendingCollaborators = computed(() => visibleCollaborators.value.filter(c => !payments.value.some(p => p.colaborador_id === identifier(c))))
const payrollTotal = computed(() => payments.value.reduce((sum, p) => sum + Math.round((Number(p.monto) || 0) * 100), 0))
function addCollaborators() {
  const rows = pendingCollaborators.value.map(c => ({ nombre: c.nombre, cuenta_numero: c.cuenta_numero, monto: (c.salario_centavos / 100).toFixed(2), puesto: c.puesto, colaborador_id: identifier(c) }))
  payments.value = [...payments.value.filter(p => p.nombre || p.cuenta_numero || p.monto), ...rows]
}
onMounted(() => { dialog.value.showModal(); dialog.value.querySelector('input,select,textarea')?.focus() })
async function submit() {
  error.value = ''
  if (props.spec.review && !reviewing.value) { reviewing.value = true; return }
  busy.value = true
  try {
    const payload = { ...(props.spec.extra || {}) }
    for (const f of visibleFields.value) {
      let value = data[f.key]
      if (f.type === 'number' && value !== '') value = Number(value)
      if (f.type === 'boolean') value = value === true || value === 'true'
      if (f.optional && value === '') continue
      payload[f.key] = value
    }
    if (props.spec.payroll) payload.pagos = payments.value.map(p => ({ ...p }))
    const fingerprint = JSON.stringify(payload)
    if (submittedFingerprint && submittedFingerprint !== fingerprint) key = crypto.randomUUID()
    submittedFingerprint = fingerprint
    const result = await mutate(props.spec.method || 'POST', props.spec.path, payload, key)
    emit('saved', result, props.spec)
  } catch (e) { error.value = message(e); reviewing.value = false }
  finally { busy.value = false }
}
const shownValue = f => optionsOf(f)?.find(x => String(x.value) === String(data[f.key]))?.label || (f.type === 'password' && data[f.key] ? '••••••••' : data[f.key])
</script>
<template><dialog ref="dialog" class="operation-dialog" @cancel.prevent="!busy && $emit('close')"><div class="dialog-heading"><div><p class="eyebrow">CP GESTIONES</p><h2>{{ reviewing ? 'Confirma la operación' : spec.title }}</h2><p>{{ spec.description || 'Completa la información para continuar.' }}</p></div><button class="icon-button" aria-label="Cerrar" :disabled="busy" @click="$emit('close')"><X :size="21"/></button></div><form @submit.prevent="submit"><div class="dialog-body"><div v-if="error" class="error-box" role="alert">{{ error }}</div><div v-if="reviewing" class="review-summary"><div v-for="f in visibleFields" :key="f.key"><span>{{ f.label }}</span><b>{{ shownValue(f) || '—' }}</b></div><div v-if="spec.payroll"><span>Colaboradores</span><b>{{ payments.length }} · {{ money(payrollTotal) }}</b></div><p v-if="preview" class="preview-line"><Calculator :size="17"/>{{ preview }}</p><p><ShieldCheck :size="18"/> Revisa los datos antes de confirmar.</p></div><div v-show="!reviewing" class="form-grid"><label v-for="f in visibleFields" :key="f.key" :class="{full: f.full}">{{ f.label }}<span v-if="f.optional" class="optional">Opcional</span><select v-if="['select','boolean','multiple'].includes(f.type)" v-model="data[f.key]" :multiple="f.type === 'multiple'" :required="!f.optional"><option v-if="f.type !== 'multiple'" :disabled="!f.optional" value="">{{ f.optional ? 'Ninguna' : 'Selecciona una opción' }}</option><option v-for="option in optionsOf(f)" :key="option.value" :value="option.value">{{ option.label }}</option></select><textarea v-else-if="f.type === 'textarea'" v-model="data[f.key]" :required="!f.optional" :maxlength="f.max || 500" :placeholder="f.placeholder"></textarea><input v-else v-model="data[f.key]" :type="f.type === 'money' ? 'text' : (f.type || 'text')" :pattern="f.type === 'money' ? '(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?' : f.pattern" :required="!f.optional" :min="f.min" :max="f.max" :minlength="f.minLength" :maxlength="f.maxLength || 180" :placeholder="f.placeholder" :inputmode="f.inputmode || (f.type === 'money' ? 'decimal' : undefined)" :autocomplete="f.type === 'password' ? 'new-password' : 'off'"><small v-if="f.help">{{ f.help }}</small></label><p v-if="preview" class="preview-line full"><Calculator :size="17"/>{{ preview }}</p></div><div v-if="spec.payroll && !reviewing" class="payroll-editor"><div v-if="companyCollaborators.length" class="collaborator-picker"><h3><Users :size="16"/> Colaboradores de la empresa</h3><div class="picker-row"><label>Filtrar por puesto<select v-model="position"><option value="">Todos los puestos ({{ companyCollaborators.length }})</option><option v-for="p in positions" :key="p" :value="p">{{ p }} ({{ companyCollaborators.filter(c => c.puesto === p).length }})</option></select></label><button type="button" class="secondary" :disabled="!pendingCollaborators.length" @click="addCollaborators"><Plus :size="16"/>Agregar {{ pendingCollaborators.length }} a la planilla</button></div><p class="picker-list">{{ visibleCollaborators.map(c => `${c.nombre} (${c.puesto})`).join(' · ') }}</p></div><p v-else-if="data.empresa_id" class="muted">Esta empresa aún no tiene colaboradores. Asígnalos en Colaboradores o agrega los pagos con el número de cuenta.</p><h3>Pagos de la planilla · {{ money(payrollTotal) }}</h3><p>Agrega colaboradores arriba o escribe el número de cuenta (10 dígitos) de cada beneficiario.</p><div v-for="(payment, index) in payments" :key="index" class="payroll-row"><label>Nombre<input v-model="payment.nombre" required maxlength="150" placeholder="Nombre del empleado"><small v-if="payment.puesto">{{ payment.puesto }}</small></label><label>Cuenta de destino<input v-model="payment.cuenta_numero" :required="!payment.colaborador_id" :readonly="Boolean(payment.colaborador_id)" maxlength="30" placeholder="2000000001"></label><label>Monto (Q)<input v-model="payment.monto" required inputmode="decimal" pattern="(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?" placeholder="1500.00"></label><button type="button" class="icon-button" aria-label="Quitar beneficiario" :disabled="payments.length === 1" @click="payments.splice(index,1)"><Trash2 :size="17"/></button></div><button type="button" class="secondary" :disabled="payments.length >= 500" @click="payments.push({nombre:'',cuenta_numero:'',monto:''})"><Plus :size="16"/> Agregar beneficiario</button></div></div><div class="dialog-footer"><button type="button" class="secondary" :disabled="busy" @click="reviewing ? reviewing = false : $emit('close')">{{ reviewing ? 'Volver a editar' : 'Cancelar' }}</button><button class="primary" :disabled="busy">{{ busy ? 'Guardando…' : reviewing ? 'Confirmar operación' : spec.review ? 'Revisar operación' : (spec.submitLabel || 'Guardar') }}<ArrowRight :size="16"/></button></div></form></dialog></template>
