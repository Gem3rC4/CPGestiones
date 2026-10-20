<script setup>
import { ref } from 'vue'
import { ShieldCheck, ArrowRight, LockKeyhole, Layers3, Check } from 'lucide-vue-next'
import { api, message, setToken } from '../services/api'
defineProps({ expired: Boolean })
const emit = defineEmits(['authenticated'])
const email = ref(''), password = ref(''), busy = ref(false), error = ref('')
async function login() {
  busy.value = true; error.value = ''
  try { const data = (await api.post('/auth/login', { email: email.value, password: password.value })).data.data; setToken(data.token); emit('authenticated', data) }
  catch (e) { error.value = message(e) }
  finally { busy.value = false }
}
</script>
<template>
  <main class="login-page">
    <section class="login-story">
      <a class="brand" href="#"><span class="brand-symbol">CP<span>✓</span></span><span>CP GESTIONES<small>PORTAL DE OPERACIONES</small></span></a>
      <div class="story-content"><p class="eyebrow"><span class="live-dot"></span> EL CENTRO DE TU OPERACIÓN</p><h1>Cada gestión.<br>Cada decisión.<br><em>Todo conectado.</em></h1><p>Un espacio para administrar cuentas, coordinar aprobaciones y dar seguimiento a cada solicitud del banco.</p>
      <div class="ecosystem"><div><Layers3 :size="20"/><span>CP Banca Virtual<small>Solicitudes de clientes</small></span></div><span class="connection-line"></span><div class="ecosystem-core"><ShieldCheck :size="20"/><span>CP Gestiones<small>Control y seguimiento</small></span></div><span class="connection-line"></span><div><Check :size="20"/><span>CP Analítica<small>Evaluación de riesgos</small></span></div></div></div>
      <footer>CreditPulse <span>·</span> Ecosistema bancario universitario</footer>
    </section>
    <section class="login-side"><form class="login-card" @submit.prevent="login"><span class="login-lock"><LockKeyhole :size="24"/></span><p class="eyebrow">ACCESO DEL PERSONAL</p><h2>Bienvenido al portal</h2><p>Ingresa con tu cuenta de operaciones.</p><div v-if="expired" class="notice">La sesión venció. Ingresa nuevamente.</div><div v-if="error" class="error-box" role="alert">{{ error }}</div><label>Correo electrónico<input v-model="email" type="email" placeholder="nombre@banco.com" autocomplete="username" required maxlength="180"></label><label>Contraseña<input v-model="password" type="password" placeholder="Tu contraseña" autocomplete="current-password" required maxlength="200"></label><button class="primary large" :disabled="busy">{{ busy ? 'Verificando acceso…' : 'Ingresar al portal' }}<ArrowRight :size="18"/></button><p class="login-help"><ShieldCheck :size="15"/> Acceso según tu rol y actividad registrada</p></form><p class="access-note">¿Necesitas una cuenta? Contacta al administrador del banco.</p></section>
  </main>
</template>
