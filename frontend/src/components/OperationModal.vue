<script setup>
import { ref, reactive, onMounted } from 'vue'
import { X, Plus, Trash2, ArrowRight, ShieldCheck } from 'lucide-vue-next'
import { mutate, message } from '../services/api'
const props = defineProps({ spec: Object })
const emit = defineEmits(['close', 'saved'])
const dialog = ref(), busy = ref(false), error = ref(''), reviewing = ref(false)
const data = reactive(Object.fromEntries(props.spec.fields.map(f => [f.key, f.value ?? (f.type === 'multiple' ? [] : '')])))
const payments = ref([{ nombre: '', cuenta_numero: '', monto: '' }])
let key = crypto.randomUUID(), submittedFingerprint = null
onMounted(() => { dialog.value.showModal(); dialog.value.querySelector('input,select,textarea')?.focus() })
async function submit() {
  error.value = ''
  if (props.spec.review && !reviewing.value) { reviewing.value = true; return }
  busy.value = true
  try {
    const payload = { ...data, ...(props.spec.extra || {}) }
    for (const f of props.spec.fields) {
      if (f.type === 'number') payload[f.key] = Number(payload[f.key])
      if (f.type === 'boolean') payload[f.key] = payload[f.key] === true || payload[f.key] === 'true'
      if (f.optional && payload[f.key] === '') delete payload[f.key]
    }
    if (props.spec.payroll) payload.pagos = payments.value.map(p => ({ ...p }))
    const fingerprint = JSON.stringify(payload)
    if (submittedFingerprint && submittedFingerprint !== fingerprint) key = crypto.randomUUID()
    submittedFingerprint = fingerprint
    const result = await mutate(props.spec.method || 'POST', props.spec.path, payload, key)
    emit('saved', result)
  } catch (e) { error.value = message(e) }
  finally { busy.value = false }
}
const shownValue = f => f.options?.find(x => String(x.value) === String(data[f.key]))?.label || data[f.key]
</script>
<template><dialog ref="dialog" class="operation-dialog" @cancel.prevent="!busy && $emit('close')"><div class="dialog-heading"><div><p class="eyebrow">CP GESTIONES</p><h2>{{ reviewing ? 'Confirma la operación' : spec.title }}</h2><p>{{ spec.description || 'Completa la información para continuar.' }}</p></div><button class="icon-button" aria-label="Cerrar" :disabled="busy" @click="$emit('close')"><X :size="21"/></button></div><form @submit.prevent="submit"><div class="dialog-body"><div v-if="error" class="error-box" role="alert">{{ error }}</div><div v-if="reviewing" class="review-summary"><div v-for="f in spec.fields" :key="f.key"><span>{{ f.label }}</span><b>{{ shownValue(f) || '—' }}</b></div><div v-if="spec.payroll"><span>Beneficiarios</span><b>{{ payments.length }}</b></div><p><ShieldCheck :size="18"/> Revisa los datos antes de confirmar.</p></div><div v-show="!reviewing" class="form-grid"><label v-for="f in spec.fields" :key="f.key" :class="{full: f.full}">{{ f.label }}<span v-if="f.optional" class="optional">Opcional</span><select v-if="['select','boolean','multiple'].includes(f.type)" v-model="data[f.key]" :multiple="f.type === 'multiple'" :required="!f.optional"><option v-if="f.type !== 'multiple'" disabled value="">Selecciona una opción</option><option v-for="option in f.options" :key="option.value" :value="option.value">{{ option.label }}</option></select><textarea v-else-if="f.type === 'textarea'" v-model="data[f.key]" :required="!f.optional" :maxlength="f.max || 500" :placeholder="f.placeholder"></textarea><input v-else v-model="data[f.key]" :type="f.type === 'money' ? 'text' : (f.type || 'text')" :inputmode="f.type === 'money' ? 'decimal' : undefined" :pattern="f.type === 'money' ? '(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?' : f.pattern" :required="!f.optional" :min="f.min" :max="f.max" :minlength="f.minLength" :maxlength="f.maxLength || 180" :placeholder="f.placeholder" :autocomplete="f.type === 'password' ? 'new-password' : 'off'"><small v-if="f.help">{{ f.help }}</small></label></div><div v-if="spec.payroll && !reviewing" class="payroll-editor"><h3>Beneficiarios</h3><p>Ingresa el número de cuenta que aparece en CP Gestiones.</p><div v-for="(payment, index) in payments" :key="index" class="payroll-row"><label>Nombre<input v-model="payment.nombre" required maxlength="150" placeholder="Nombre del empleado"></label><label>Cuenta de destino<input v-model="payment.cuenta_numero" required maxlength="30" placeholder="CP123456789012"></label><label>Monto (Q)<input v-model="payment.monto" required inputmode="decimal" pattern="(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?" placeholder="1500.00"></label><button type="button" class="icon-button" aria-label="Quitar beneficiario" :disabled="payments.length === 1" @click="payments.splice(index,1)"><Trash2 :size="17"/></button></div><button type="button" class="secondary" :disabled="payments.length >= 500" @click="payments.push({nombre:'',cuenta_numero:'',monto:''})"><Plus :size="16"/> Agregar beneficiario</button></div></div><div class="dialog-footer"><button type="button" class="secondary" :disabled="busy" @click="reviewing ? reviewing = false : $emit('close')">{{ reviewing ? 'Volver a editar' : 'Cancelar' }}</button><button class="primary" :disabled="busy">{{ busy ? 'Guardando…' : reviewing ? 'Confirmar operación' : spec.review ? 'Revisar operación' : 'Guardar' }}<ArrowRight :size="16"/></button></div></form></dialog></template>
