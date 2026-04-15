<template>
  <div class="dashboard">
    <div class="welcome">Bienvenido, {{ store.user?.nombre }}</div>

    <div v-if="loading" class="loading">Cargando...</div>

    <template v-else>
      <!-- Stats row -->
      <div class="stats-row">
        <div class="stat-card pink">
          <div class="stat-icon-circle pink-bg">👥</div>
          <div class="stat-data">
            <span class="stat-value">{{ fmt(stats.contacts_total) }}</span>
            <span class="stat-label">Contactos</span>
          </div>
        </div>
        <div class="stat-card red">
          <div class="stat-icon-circle red-bg">📢</div>
          <div class="stat-data">
            <span class="stat-value">{{ stats.campaigns_active || 0 }}</span>
            <span class="stat-label">Campañas</span>
          </div>
        </div>
        <div class="stat-card purple">
          <div class="stat-icon-circle purple-bg">💬</div>
          <div class="stat-data">
            <span class="stat-value">{{ stats.messages_today?.total || 0 }}</span>
            <span class="stat-label">Mensajes Hoy</span>
          </div>
        </div>
        <div class="stat-card green">
          <div class="stat-icon-circle green-bg">⭕</div>
          <div class="stat-data">
            <span class="stat-value">0</span>
            <span class="stat-label">Estados Pendientes</span>
          </div>
        </div>
      </div>

      <!-- Acciones rápidas -->
      <h3 class="section-title">Acciones Rápidas</h3>
      <div class="actions-row">
        <RouterLink to="/send" class="action-card green-light">
          <div class="action-icon">✉️</div>
          <strong>Enviar Mensaje</strong>
          <small>Envío rápido individual</small>
        </RouterLink>
        <RouterLink to="/states" class="action-card blue-light">
          <div class="action-icon">⭕</div>
          <strong>Publicar Estado</strong>
          <small>Historia/Story</small>
        </RouterLink>
        <RouterLink to="/campaigns" class="action-card orange-light">
          <div class="action-icon">📢</div>
          <strong>Nueva Campaña</strong>
          <small>Envío masivo con delay</small>
        </RouterLink>
        <RouterLink to="/contacts" class="action-card teal-light">
          <div class="action-icon">🔄</div>
          <strong>Sincronizar</strong>
          <small>Importar contactos</small>
        </RouterLink>
      </div>

      <!-- Bottom panels -->
      <div class="bottom-panels">
        <!-- Campañas recientes -->
        <div class="panel">
          <h3 class="panel-title">📢 Últimas Campañas</h3>
          <div v-if="campaigns.length" class="panel-list">
            <div v-for="c in campaigns" :key="c.id" class="panel-item">
              <div class="panel-item-info">
                <strong>{{ c.nombre }}</strong>
                <small>{{ c.enviados }}/{{ c.total_contactos }} enviados</small>
              </div>
              <span :class="['badge', badgeClass(c.estado)]">{{ c.estado }}</span>
            </div>
          </div>
          <div v-else class="panel-empty">No hay campañas recientes</div>
        </div>

        <!-- Mensajes recientes -->
        <div class="panel">
          <h3 class="panel-title">💬 Últimos Mensajes</h3>
          <div v-if="messages.length" class="panel-list">
            <div v-for="m in messages" :key="m.id" class="panel-item">
              <div class="panel-item-info">
                <strong>{{ m.numero }}</strong>
                <small>{{ truncate(m.contenido, 40) }}</small>
              </div>
              <span class="msg-time">{{ fmtTime(m.created_at) }}</span>
            </div>
          </div>
          <div v-else class="panel-empty">No hay mensajes recientes</div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import store from '../store'
import api from '../api'

const stats     = ref({})
const campaigns = ref([])
const messages  = ref([])
const loading   = ref(true)

function fmt(v) {
  return v ? Number(v).toLocaleString('es-AR') : '0'
}
function fmtTime(v) {
  return v ? new Date(v).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' }) : ''
}
function truncate(s, n) {
  return s ? (s.length > n ? s.substring(0, n) + '...' : s) : ''
}
function badgeClass(estado) {
  return { activa: 'badge-green', enviando: 'badge-green', pausada: 'badge-orange',
           completada: 'badge-blue', cancelada: 'badge-red', borrador: 'badge-gray' }[estado] || 'badge-gray'
}

onMounted(async () => {
  try {
    const [s, c, m] = await Promise.all([
      api.get('/dashboard'),
      api.get('/campaigns/recent').catch(() => ({ data: { campaigns: [] } })),
      api.get('/messages/recent').catch(() => ({ data: { messages: [] } }))
    ])
    stats.value     = s.data
    campaigns.value = c.data.campaigns || []
    messages.value  = m.data.messages  || []
  } catch (e) {
    console.error('Dashboard error:', e)
  } finally {
    loading.value = false
  }
})
</script>
