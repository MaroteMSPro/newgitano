<template>
  <div class="app-root">
    <div v-if="store.isLoggedIn" class="crm-layout">
      <aside class="sidebar">
        <div class="sidebar-header">
          <div class="brand-icon"><span class="wa-fallback">💬</span></div>
          <div class="brand-text">
            <strong>WhatsApp Marketing</strong>
            <small>LUXOM</small>
          </div>
        </div>

        <div class="sidebar-instance">
          <div class="instance-badge">
            {{ isAdmin ? '👤 ADMIN - Todas las instancias' : '👤 Mis instancias' }}
          </div>
          <select class="instance-select" v-model="selectedInstanceId" @change="onInstanceChange">
            <option v-if="isAdmin" value="">Todas las instancias</option>
            <option v-for="inst in visibleInstances" :key="inst.id" :value="inst.id">
              {{ inst.descripcion || inst.nombre_perfil || inst.nombre }}
            </option>
          </select>
          <div v-if="selectedInstance" class="instance-phone">
            ● +{{ selectedInstance.numero }}
          </div>
        </div>

        <nav class="sidebar-nav">
          <RouterLink v-if="isAdmin" to="/" class="nav-item">
            <span class="nav-icon">📊</span> Dashboard
          </RouterLink>
          <RouterLink to="/contacts" class="nav-item">
            <span class="nav-icon">👥</span> Contactos
          </RouterLink>
          <RouterLink to="/crm" class="nav-item">
            <span class="nav-icon">💬</span> CRM
          </RouterLink>
          <RouterLink to="/states" class="nav-item">
            <span class="nav-icon">⭕</span> Estados
          </RouterLink>
          <RouterLink to="/broadcasts" class="nav-item">
            <span class="nav-icon">📡</span> Difusiones
          </RouterLink>
          <RouterLink to="/auto-reply" class="nav-item">
            <span class="nav-icon">🤖</span> Auto-Respuesta
          </RouterLink>
          <RouterLink to="/quick-replies" class="nav-item">
            <span class="nav-icon">⚡</span> Respuestas Rápidas
          </RouterLink>
          <RouterLink to="/tracking" class="nav-item">
            <span class="nav-icon">📈</span> Seguimiento
          </RouterLink>
          <RouterLink to="/recordatorios" class="nav-item">
            <span class="nav-icon">🔔</span> Recordatorios
          </RouterLink>

          <template v-if="isAdmin">
            <div class="nav-section">ADMINISTRACIÓN</div>
            <RouterLink to="/instances" class="nav-item">
              <span class="nav-icon">📱</span> Instancias
            </RouterLink>
            <RouterLink to="/users" class="nav-item">
              <span class="nav-icon">👤</span> Usuarios
            </RouterLink>
            <RouterLink to="/biblioteca" class="nav-item">
              <span class="nav-icon">📁</span> Biblioteca
            </RouterLink>
            <RouterLink to="/monitor" class="nav-item">
              <span class="nav-icon">📡</span> Monitor Respuestas
            </RouterLink>
            <RouterLink to="/sin-contestar" class="nav-item">
              <span class="nav-icon">📵</span> Sin Contestar
            </RouterLink>
            <RouterLink to="/estadisticas" class="nav-item">
              <span class="nav-icon">📊</span> Estadísticas
            </RouterLink>
            <RouterLink to="/config-crm" class="nav-item">
              <span class="nav-icon">🔧</span> Config. CRM
            </RouterLink>
            <RouterLink to="/license" class="nav-item">
              <span class="nav-icon">🔑</span> Mi Licencia
            </RouterLink>
          </template>
        </nav>

        <a :href="helpUrl" target="_blank" class="help-link">
          <span>❓</span> Ayuda
        </a>

        <div class="sidebar-user">
          <div class="user-avatar">{{ store.user?.nombre?.charAt(0) || 'A' }}</div>
          <div class="user-info">
            <strong>{{ store.user?.nombre }}</strong>
            <small>{{ store.user?.rol }}</small>
          </div>
          <button @click="logout" class="btn-icon" title="Salir">🚪</button>
        </div>
      </aside>

      <main class="main-content">
        <RouterView />
      </main>
    </div>

    <RouterView v-else />
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import store from './store'
import api from './api'

const router = useRouter()
const route  = useRoute()

const helpAnchors = {
  '/': '#dashboard', '/contacts': '#contactos', '/crm': '#crm',
  '/campaigns': '#campanas', '/states': '#estados', '/broadcasts': '#difusiones',
  '/auto-reply': '#autorespuesta', '/quick-replies': '#rapidas',
  '/tracking': '#seguimiento', '/bulk-send': '#campanas',
  '/multi-campaigns': '#campanas', '/instances': '#instancias',
  '/users': '#usuarios', '/biblioteca': '#biblioteca', '/monitor': '#monitor',
  '/sin-contestar': '#sincontestar', '/estadisticas': '#estadisticas',
  '/config-crm': '#config', '/license': '#licencia'
}

const helpUrl = computed(() =>
  `https://guias.luxom.com.ar/${helpAnchors[route.path] || ''}`
)
const isAdmin = computed(() => store.user?.rol === 'admin')

const instances          = ref([])
const selectedInstanceId = ref('')

const visibleInstances = computed(() =>
  isAdmin.value ? instances.value : instances.value.filter(i => i._assigned)
)
const selectedInstance = computed(() =>
  selectedInstanceId.value
    ? instances.value.find(i => i.id == selectedInstanceId.value)
    : null
)

function onInstanceChange() {
  store.selectedInstance = selectedInstanceId.value
    ? Number(selectedInstanceId.value) : null
}

async function loadInstances() {
  if (!store.token) return
  try {
    const { data } = await api.get('/instances/user-available')
    instances.value = data.instances || []
    if (!isAdmin.value && instances.value.length === 1) {
      selectedInstanceId.value = instances.value[0].id
      onInstanceChange()
    }
  } catch {
    try {
      const { data } = await api.get('/instances')
      instances.value = data.instances || []
    } catch {}
  }
}

function checkDomainChange() {
  const domain     = window.location.hostname
  const prevDomain = localStorage.getItem('crm_domain')
  if (prevDomain && prevDomain !== domain) {
    selectedInstanceId.value = ''
    store.selectedInstance   = null
  }
  localStorage.setItem('crm_domain', domain)
}

onMounted(checkDomainChange)

watch(() => store.token, val => { if (val) loadInstances() }, { immediate: true })

function logout() {
  store.logout()
  router.push('/login')
}
</script>
