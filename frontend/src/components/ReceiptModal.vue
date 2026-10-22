<script setup>
import { ref, onMounted } from 'vue'
import { X, Download, CheckCircle2, ShieldCheck } from 'lucide-vue-next'
import { money, message } from '../services/api'
import { receiptPdf, receiptRows, deliver } from '../services/pdf'
const props = defineProps({ receipt: Object })
defineEmits(['close'])
const dialog = ref(), busy = ref(false), error = ref('')
const rows = receiptRows(props.receipt)
onMounted(() => dialog.value.showModal())
async function download() {
  busy.value = true; error.value = ''
  try { deliver(await receiptPdf(props.receipt), 'save') } catch (e) { error.value = message(e) } finally { busy.value = false }
}
</script>
<template><dialog ref="dialog" class="operation-dialog receipt-dialog" @cancel.prevent="$emit('close')"><div class="dialog-heading"><div><p class="eyebrow">CP GESTIONES · COMPROBANTE DE APERTURA</p><h2><CheckCircle2 :size="22" class="positive"/> Cuenta creada</h2><p>Entrega este comprobante al cliente. En Banca Virtual se registra con su DPI y verá esta cuenta con el mismo número y saldo.</p></div><button class="icon-button" aria-label="Cerrar" @click="$emit('close')"><X :size="21"/></button></div><div class="dialog-body"><div v-if="error" class="error-box" role="alert">{{ error }}</div><div class="receipt-number"><small>Número de cuenta</small><strong data-testid="numero-cuenta">{{ receipt.numero_cuenta }}</strong><span>Saldo inicial {{ money(receipt.monto_apertura_centavos) }}</span></div><div class="review-summary"><div v-for="[name, value] in rows" :key="name"><span>{{ name }}</span><b>{{ value }}</b></div><p><ShieldCheck :size="18"/> El número de cuenta no cambia y es el mismo en Banca Virtual.</p></div></div><div class="dialog-footer"><button type="button" class="secondary" :disabled="busy" @click="download"><Download :size="16"/>{{ busy ? 'Generando…' : 'Descargar PDF del comprobante' }}</button><button type="button" class="primary" @click="$emit('close')">Aceptar</button></div></dialog></template>
