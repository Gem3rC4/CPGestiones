<script setup>
import { money, day, label } from '../services/api'
import StatusBadge from './StatusBadge.vue'
defineProps({ loan: Object })
</script>
<template>
  <section class="loan-block">
    <div class="detail-stats"><div><small>Monto desembolsado</small><b>{{ money(loan.desembolsado_centavos ?? loan.limite_centavos) }}</b></div><div><small>Saldo de capital</small><b>{{ money(loan.saldo_centavos) }}</b></div><div><small>Pendiente total</small><b>{{ money(loan.pendiente_total_centavos) }}</b></div></div>
    <dl class="detail-list compact"><dt>Estado</dt><dd><StatusBadge :value="loan.estado"/></dd><dt>Cuotas</dt><dd>{{ loan.cuotas_pagadas }} de {{ loan.cuotas_total }} pagadas<span v-if="loan.cuotas_vencidas" class="error-text"> · {{ loan.cuotas_vencidas }} vencida(s)</span></dd><dt>Cuota mensual</dt><dd>{{ money(loan.cuota_centavos) }}</dd><dt>Tasa anual</dt><dd>{{ loan.tasa_anual != null ? `${loan.tasa_anual} %` : '—' }}</dd><dt>Día de pago</dt><dd>{{ loan.dia_pago ? `Día ${loan.dia_pago} de cada mes (débito automático)` : '—' }}</dd><dt>Cuenta del préstamo</dt><dd>{{ loan.cuenta_numero || '—' }}</dd><template v-if="loan.proxima_cuota"><dt>Próximo pago</dt><dd>Cuota {{ loan.proxima_cuota.numero }} · {{ money(loan.proxima_cuota.monto_centavos) }} · {{ day(loan.proxima_cuota.fecha_vencimiento) }}</dd></template><template v-if="loan.ultimo_pago"><dt>Último pago</dt><dd>{{ money(loan.ultimo_pago.monto_centavos) }} · {{ loan.ultimo_pago.actor === 'service:banca' ? 'desde Banca Virtual' : loan.ultimo_pago.actor === 'sistema:cobros' ? 'débito automático' : 'en agencia' }}</dd></template></dl>
    <div v-if="loan.cuotas?.length" class="table-wrap schedule-wrap"><table class="schedule-table"><thead><tr><th>#</th><th>VENCE</th><th>CUOTA</th><th>CAPITAL</th><th>INTERÉS</th><th>PAGADO</th><th>ESTADO</th></tr></thead><tbody><tr v-for="row in loan.cuotas" :key="row.numero" :class="{overdue: row.vencida}"><td>{{ row.numero }}</td><td>{{ day(row.fecha_vencimiento) }}</td><td>{{ money(row.monto_centavos) }}</td><td>{{ money(row.capital_centavos) }}</td><td>{{ money(row.interes_centavos) }}</td><td>{{ money(row.pagado_centavos) }}</td><td>{{ row.vencida ? 'Vencida' : label(row.estado) }}</td></tr></tbody></table></div>
  </section>
</template>
