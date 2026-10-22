import { money, label } from './api'
// The PDF libraries load only when a report is generated, so they do not slow down the panel.
async function libraries() {
  const [{ jsPDF }, { autoTable }] = await Promise.all([import('jspdf'), import('jspdf-autotable')])
  return { jsPDF, autoTable }
}
const fileName = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^\w-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '').toLowerCase()
const when = value => value ? new Intl.DateTimeFormat('es-GT', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Guatemala' }).format(new Date(value)) : '—'
const onDay = value => value ? new Intl.DateTimeFormat('es-GT', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`)) : '—'
const usd = (cents, rate) => rate?.quetzales_por_dolar ? ` (US$ ${((Number(cents) || 0) / 100 / rate.quetzales_por_dolar).toLocaleString('es-GT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })})` : ''
const BLUE = [37, 99, 235], INK = [15, 23, 42], MUTED = [100, 116, 139]

function header(doc, report, subtitle, lines) {
  const width = doc.internal.pageSize.getWidth()
  doc.setFillColor(...BLUE); doc.rect(0, 0, width, 64, 'F')
  doc.setTextColor(255, 255, 255); doc.setFont('helvetica', 'bold'); doc.setFontSize(15); doc.text('CreditPulse · CP Gestiones', 40, 30)
  doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.text('Portal de operaciones · Información centralizada', 40, 46)
  doc.setFontSize(9); doc.text(`Generado: ${when(report.generado_el)}${report.generado_por ? ` · ${report.generado_por}` : ''}`, width - 40, 30, { align: 'right' })
  doc.setTextColor(...INK); doc.setFont('helvetica', 'bold'); doc.setFontSize(17); doc.text(report.reporte, 40, 96)
  doc.setFont('helvetica', 'normal'); doc.setFontSize(10); doc.setTextColor(...MUTED)
  let y = 114
  for (const line of [subtitle, ...lines].filter(Boolean)) { doc.text(line, 40, y); y += 14 }
  const rate = report.tipo_cambio
  if (rate?.quetzales_por_dolar) { doc.text(`Tipo de cambio de referencia: 1 Dólar (USD) = Q ${Number(rate.quetzales_por_dolar).toFixed(5)} Quetzales (GTQ) · ${rate.fuente}, ${rate.fecha}${rate.desactualizado ? ' (último conocido)' : ''}`, 40, y); y += 14 }
  doc.setTextColor(...INK)
  return y + 8
}
function footer(doc) {
  const pages = doc.getNumberOfPages(), width = doc.internal.pageSize.getWidth(), height = doc.internal.pageSize.getHeight()
  for (let page = 1; page <= pages; page++) {
    doc.setPage(page); doc.setFontSize(8); doc.setTextColor(...MUTED)
    doc.text('Documento generado por CP Gestiones. Uso académico.', 40, height - 24)
    doc.text(`Página ${page} de ${pages}`, width - 40, height - 24, { align: 'right' })
  }
}
const tableStyle = { styles: { fontSize: 8.5, cellPadding: 5, textColor: INK }, headStyles: { fillColor: [226, 232, 240], textColor: INK, fontStyle: 'bold' }, alternateRowStyles: { fillColor: [248, 250, 252] }, margin: { left: 40, right: 40, bottom: 44 } }
function totals(doc, autoTable, y, rows) {
  autoTable(doc, { startY: y, body: rows, theme: 'plain', styles: { fontSize: 10, cellPadding: 4 }, columnStyles: { 0: { fontStyle: 'bold', cellWidth: 200 } }, margin: { left: 40, right: 40 } })
  return doc.lastAutoTable.finalY + 14
}
const range = f => f?.desde || f?.hasta ? `Período: ${onDay(f.desde) || 'inicio'} al ${onDay(f.hasta)}` : 'Período: todo el historial'

export async function payrollPdf(report) {
  const { jsPDF, autoTable } = await libraries()
  const doc = new jsPDF({ unit: 'pt', format: 'letter' })
  let y = header(doc, report, `${report.empresa.nombre}${report.empresa.razon_social && report.empresa.razon_social !== report.empresa.nombre ? ` (${report.empresa.razon_social})` : ''} · NIT ${report.empresa.nit} · Cuenta de planillas ${report.empresa.cuenta_numero || '—'}`, [range(report.filtros), report.filtros?.estado ? `Estado: ${label(report.filtros.estado)}` : ''])
  const t = report.totales, rate = report.tipo_cambio
  y = totals(doc, autoTable, y, [['Planillas', `${t.planillas} (${t.pagadas} pagadas)`], ['Total pagado', money(t.pagado_centavos) + usd(t.pagado_centavos, rate)], ['Pendiente de autorizar', money(t.pendiente_centavos)], ['Pagos a colaboradores', String(t.beneficiarios)]])
  for (const payroll of report.planillas) {
    if (y > doc.internal.pageSize.getHeight() - 120) { doc.addPage(); y = 50 }
    doc.setFont('helvetica', 'bold'); doc.setFontSize(11); doc.text(`${payroll.nombre} · ${label(payroll.estado)} · ${money(payroll.total_centavos)}`, 40, y)
    doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(...MUTED); doc.text(`Cargada ${when(payroll.creada)}${payroll.pagada_el ? ` · pagada ${when(payroll.pagada_el)}` : ''}`, 40, y + 13); doc.setTextColor(...INK)
    autoTable(doc, { ...tableStyle, startY: y + 20, head: [['Colaborador', 'Puesto', 'Cuenta', 'Monto']], body: payroll.pagos.map(p => [p.nombre, p.puesto || '—', p.cuenta_numero || '—', money(p.centavos)]), columnStyles: { 3: { halign: 'right' } } })
    y = doc.lastAutoTable.finalY + 22
  }
  if (!report.planillas.length) doc.text('No hay planillas con estos filtros.', 40, y)
  footer(doc)
  return { doc, name: fileName(`planillas-${report.empresa.nombre}`) }
}

export async function statementPdf(report) {
  const { jsPDF, autoTable } = await libraries()
  const doc = new jsPDF({ unit: 'pt', format: 'letter' })
  const c = report.cliente
  let y = header(doc, report, `${c.nombre} · DPI ${c.dpi_enmascarado || '—'} · ${c.correo || ''}`, [range(report.filtros)])
  for (const item of report.cuentas) {
    if (y > doc.internal.pageSize.getHeight() - 160) { doc.addPage(); y = 50 }
    doc.setFont('helvetica', 'bold'); doc.setFontSize(12); doc.text(`Cuenta ${label(item.cuenta.tipo).toLowerCase()} ${item.cuenta.numero}`, 40, y); doc.setFont('helvetica', 'normal')
    y = totals(doc, autoTable, y + 6, [['Saldo inicial', money(item.saldo_inicial_centavos)], ['Créditos (+)', money(item.creditos_centavos)], ['Débitos (-)', money(item.debitos_centavos)], ['Saldo final del período', money(item.saldo_final_centavos) + usd(item.saldo_final_centavos, report.tipo_cambio)]])
    autoTable(doc, { ...tableStyle, startY: y, head: [['Fecha', 'Tipo', 'Concepto', 'Importe', 'Saldo']], body: item.movimientos.length ? item.movimientos.map(m => [when(m.fecha), label(m.tipo), m.concepto || '—', money(m.importe_centavos), m.saldo_centavos == null ? '—' : money(m.saldo_centavos)]) : [['', '', 'Sin movimientos en el período', '', '']], columnStyles: { 0: { cellWidth: 98 }, 3: { halign: 'right' }, 4: { halign: 'right' } } })
    y = doc.lastAutoTable.finalY + 26
  }
  if (!report.cuentas.length) { doc.text('El cliente no tiene cuentas.', 40, y); y += 20 }
  if (report.prestamos.length) {
    autoTable(doc, { ...tableStyle, startY: y, head: [['Préstamo', 'Producto', 'Monto', 'Saldo de capital', 'Cuotas', 'Próximo pago']], body: report.prestamos.map(p => [p.referencia, label(p.producto), money(p.monto_centavos ?? p.limite_centavos), money(p.saldo_centavos), `${p.cuotas_pagadas}/${p.cuotas_total}`, p.proxima_cuota ? `${money(p.proxima_cuota.monto_centavos)} · ${onDay(p.proxima_cuota.fecha_vencimiento)}` : '—']) })
    y = doc.lastAutoTable.finalY + 20
  }
  if (report.tarjetas.length) autoTable(doc, { ...tableStyle, startY: y, head: [['Tarjeta', 'Producto', 'Límite', 'Deuda', 'Estado']], body: report.tarjetas.map(t => [t.referencia, label(t.producto), t.tipo === 'debito' ? 'Débito' : money(t.limite_centavos), money(t.deuda_centavos), label(t.estado)]) })
  footer(doc)
  return { doc, name: fileName(`estado-de-cuenta-${c.nombre}`) }
}

export async function transactionsPdf(report) {
  const { jsPDF, autoTable } = await libraries()
  const doc = new jsPDF({ unit: 'pt', format: 'letter', orientation: 'landscape' })
  const t = report.totales
  let y = header(doc, report, 'Movimientos de todas las cuentas de clientes, consolidados', [range(report.filtros), report.filtros?.tipo ? `Tipo: ${label(report.filtros.tipo)}` : '', report.truncado ? `Se muestran los primeros ${t.movimientos} de ${report.total_encontrado} movimientos: acota las fechas para verlos todos.` : ''])
  y = totals(doc, autoTable, y, [['Movimientos', String(t.movimientos)], ['Clientes', String(t.clientes)], ['Ingresos a cuentas', money(t.ingresos_centavos) + usd(t.ingresos_centavos, report.tipo_cambio)], ['Salidas de cuentas', money(t.egresos_centavos) + usd(t.egresos_centavos, report.tipo_cambio)]])
  autoTable(doc, { ...tableStyle, startY: y, head: [['Tipo', 'Cantidad', 'Ingresos', 'Salidas']], body: report.por_tipo.map(r => [label(r.tipo), String(r.cantidad), money(r.ingresos_centavos), money(r.egresos_centavos)]), columnStyles: { 1: { halign: 'right' }, 2: { halign: 'right' }, 3: { halign: 'right' } } })
  autoTable(doc, { ...tableStyle, startY: doc.lastAutoTable.finalY + 20, head: [['Fecha', 'Cuenta', 'Titular', 'Tipo', 'Concepto', 'Importe']], body: report.movimientos.map(m => [when(m.fecha), m.cuenta_numero || '—', m.titular, label(m.tipo), m.concepto || '—', money(m.importe_centavos)]), columnStyles: { 0: { cellWidth: 98 }, 5: { halign: 'right' } } })
  footer(doc)
  return { doc, name: fileName(`transacciones-${report.filtros?.desde || ''}-${report.filtros?.hasta || ''}`) }
}

// Same rows on screen and on paper, so the receipt always shows what is stored.
export function receiptRows(r) {
  const c = r.cliente
  return [['Número de cuenta', r.numero_cuenta], ['Tipo de cuenta', r.tipo === 'ahorro' ? 'Cuenta de ahorro' : 'Cuenta monetaria'], ['Monto de apertura', money(r.monto_apertura_centavos)],
    ['Nombre', c.nombres || '—'], ['Apellido', c.apellidos || '—'], ['DPI', c.dpi || '—'], ['NIT', c.nit || '—'], ['Correo electrónico', c.correo || '—'], ['Teléfono', c.telefono || '—'],
    ['Fecha de nacimiento', onDay(c.fecha_nacimiento)], ['Ingreso mensual', c.ingreso_mensual == null ? '—' : money(Math.round(Number(c.ingreso_mensual) * 100))], ['Meses laborando', c.antiguedad_laboral_meses ?? '—']]
}

export async function receiptPdf(r) {
  const { jsPDF, autoTable } = await libraries()
  const doc = new jsPDF({ unit: 'pt', format: 'letter' })
  const y = header(doc, { reporte: 'Comprobante de apertura de cuenta', generado_el: r.fecha_apertura || new Date().toISOString() }, `Cuenta ${r.tipo === 'ahorro' ? 'de ahorro' : 'monetaria'} N.º ${r.numero_cuenta} · Fecha de apertura ${when(r.fecha_apertura)}`, [])
  autoTable(doc, { ...tableStyle, startY: y, head: [['Dato', 'Valor']], body: receiptRows(r).map(([k, v]) => [k, String(v)]), columnStyles: { 0: { fontStyle: 'bold', cellWidth: 170 } } })
  let next = doc.lastAutoTable.finalY + 24
  doc.setFontSize(10); doc.setTextColor(...INK)
  for (const line of ['Para usar Banca Virtual, elige «Registrarse» e ingresa tu DPI, este número de cuenta y los datos de este', 'comprobante; ahí crearás tu contraseña. El número de cuenta no cambia y es el mismo en ambos sistemas.']) { doc.text(line, 40, next); next += 14 }
  footer(doc)
  return { doc, name: fileName(`comprobante-cuenta-${r.numero_cuenta}`) }
}

export function deliver({ doc, name }, mode) {
  if (mode === 'print') {
    doc.autoPrint()
    const url = doc.output('bloburl')
    const opened = window.open(url, '_blank')
    if (!opened) doc.save(`${name}.pdf`)
    return
  }
  doc.save(`${name}.pdf`)
}
