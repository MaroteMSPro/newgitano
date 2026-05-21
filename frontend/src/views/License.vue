<template>
  <div class="license-view">
    <div class="page-header">
      <h1>🔑 Mi Licencia</h1>
      <p class="subtitle">Estado del plan y consumo actual</p>
    </div>

    <div v-if="loading" class="loading">Cargando...</div>

    <template v-else-if="data">
      <!-- Banner de estado -->
      <div :class="['status-banner', bannerClass]">
        <div class="banner-icon">{{ bannerIcon }}</div>
        <div class="banner-text">
          <strong>{{ bannerTitle }}</strong>
          <span>{{ bannerSub }}</span>
        </div>
      </div>

      <!-- Métricas -->
      <div class="metrics-grid">
        <div class="metric-card">
          <div class="metric-label">Plan</div>
          <div class="metric-value">{{ data.plan }}</div>
        </div>

        <div class="metric-card">
          <div class="metric-label">Vencimiento</div>
          <div :class="['metric-value', { expired: data.expired, warning: data.days_left <= 7 && !data.expired }]">
            {{ fmtDate(data.expires_at) }}
          </div>
          <div v-if="data.days_left !== null" class="days-left">
            <span v-if="data.expired" class="badge badge-red">Vencida</span>
            <span v-else-if="data.days_left <= 7" class="badge badge-yellow">⚠️ {{ data.days_left }} días restantes</span>
            <span v-else class="badge badge-green">{{ data.days_left }} días restantes</span>
          </div>
        </div>

        <!-- Usuarios -->
        <div class="metric-card">
          <div class="metric-label">Usuarios activos</div>
          <div class="metric-fraction">
            <span :class="{ 'text-red': data.users.used >= data.users.max }">{{ data.users.used }}</span>
            <span class="divider">/</span>
            <span class="metric-max">{{ data.users.max }}</span>
          </div>
          <div class="progress-bar-wrap">
            <div :class="['progress-fill', usersBarClass]"
                 :style="{ width: usersPct + '%' }"></div>
          </div>
          <div class="pct-label">{{ usersPct }}% utilizado</div>
        </div>

        <!-- Instancias -->
        <div class="metric-card">
          <div class="metric-label">Instancias WhatsApp</div>
          <div class="metric-fraction">
            <span :class="{ 'text-red': data.instances.used >= data.instances.max }">{{ data.instances.used }}</span>
            <span class="divider">/</span>
            <span class="metric-max">{{ data.instances.max }}</span>
          </div>
          <div class="progress-bar-wrap">
            <div :class="['progress-fill', instancesBarClass]"
                 :style="{ width: instancesPct + '%' }"></div>
          </div>
          <div class="pct-label">{{ instancesPct }}% utilizado</div>
        </div>
      </div>

      <!-- Info extra -->
      <div v-if="data.tenant" class="tenant-info">
        <div class="info-row">
          <span class="info-label">Empresa</span>
          <span class="info-val">{{ data.tenant }}</span>
        </div>
        <div class="info-row">
          <span class="info-label">Estado</span>
          <span :class="['badge', data.expired ? 'badge-red' : 'badge-green']">
            {{ data.expired ? 'Vencida' : 'Activa' }}
          </span>
        </div>
      </div>
    </template>

    <div v-else class="error-msg">
      No se pudo cargar la información de licencia.
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import store from '../store'
import api from '../api'

const data    = ref(null)
const loading = ref(true)

const usersPct     = computed(() => data.value ? Math.min(100, Math.round(data.value.users.used / data.value.users.max * 100)) : 0)
const instancesPct = computed(() => data.value ? Math.min(100, Math.round(data.value.instances.used / data.value.instances.max * 100)) : 0)
const usersBarClass     = computed(() => usersPct.value >= 100 ? 'bar-red' : usersPct.value >= 80 ? 'bar-yellow' : 'bar-green')
const instancesBarClass = computed(() => instancesPct.value >= 100 ? 'bar-red' : instancesPct.value >= 80 ? 'bar-yellow' : 'bar-green')
const bannerClass = computed(() => !data.value ? '' : data.value.expired ? 'banner-red' : data.value.days_left <= 7 ? 'banner-yellow' : 'banner-green')
const bannerIcon  = computed(() => !data.value ? '' : data.value.expired ? '🔴' : data.value.days_left <= 7 ? '⚠️' : '✅')
const bannerTitle = computed(() => !data.value ? '' : data.value.expired ? 'Licencia vencida' : data.value.days_left <= 7 ? 'Licencia próxima a vencer' : 'Licencia activa')
const bannerSub   = computed(() => {
  if (!data.value) return ''
  if (data.value.expired) return 'Contactá al administrador para renovar.'
  if (data.value.days_left <= 7) return `Vence el ${fmtDate(data.value.expires_at)}`
  return `Plan ${data.value.plan} — válida hasta ${fmtDate(data.value.expires_at)}`
})

function fmtDate(d) {
  return d ? new Date(d).toLocaleDateString('es-AR', { day:'2-digit', month:'long', year:'numeric' }) : '—'
}

onMounted(async () => {
  try {
    const res = await fetch('/api/my-license', {
      headers: { Authorization: `Bearer ${store.token}` }
    })
    data.value = await res.json()
  } catch (e) { console.error(e) }
  finally { loading.value = false }
})
</script>
