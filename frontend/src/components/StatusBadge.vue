<script setup>
import { label } from '../services/api'
defineProps({ value: [String, Boolean] })
</script>
<template><span class="badge" :class="String(value)"><span class="status-dot"></span>{{ typeof value === 'boolean' ? (value ? 'Activo' : 'Inactivo') : label(value) }}</span></template>
