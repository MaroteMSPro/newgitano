<template>
  <div class="gestion-panel">
    <div v-if="loading" class="loading">Cargando gestión...</div>
    <template v-else>
      <!-- Estado de distribución -->
      <div class="gestion-config">
        <h3>⚙️ Estado de distribución</h3>
        <div class="config-info">
          <div><strong>Modo:</strong> 🔄 Round Robin (1 y 1)</div>
          <div><strong>Auto-asignar:</strong> {{ data.config?.auto_asignar ? '✅ Sí' : '❌ No' }}</div>
          <div><strong>Máx. chats/usuario:</strong> {{ data.config?.max_chats_por_usuario || 300 }}</div>
          <div v-if="data.round_robin">
            <strong>Round Robin:</strong> Último asignado:
            <strong>{{ data.round_robin.ultimo_usuario }}</strong>
            (ID: {{ data.round_robin.ultimo_usuario_id }})
          </div>
        </div>
      </div>

      <!-- Acciones en lote -->
      <div v-if="selected.length" class="bulk-actions">
        <span>{{ selected.length }} seleccionados</span>
        <select class="bulk-select" @change="bulkTransfer($event.target.value); $event.target.value = ''">
          <option value="">Transferir todos a...</option>
          <option v-for="u in onlineUsers" :key="u.id" :value="u.id">
            {{ u.nombre }} 🟢
          </option>
        </select>
        <button @click="bulkClose('cerrado_positivo')" class="bulk-btn green">✅ Cerrar Positivo</button>
        <button @click="bulkClose('cerrado_negativo')" class="bulk-btn red">❌ Cerrar Negativo</button>
        <button @click="selected = []" class="bulk-btn gray">Deseleccionar</button>
      </div>

      <!-- Filtros de fecha -->
      <div class="date-filters">
        <label>
          <span>Desde:</span>
          <input type="date" v-model="filterDesde" @change="loadGestion" />
        </label>
        <label>
          <span>Hasta:</span>
          <input type="date" v-model="filterHasta" @change="loadGestion" />
        </label>
        <button @click="filterDesde = ''; filterHasta = ''" class="filter-clear">Limpiar filtros</button>
      </div>

      <!-- Sin asignar -->
      <div class="section-card">
        <div class="section-header">
          <span>⏳ En espera (sin asignar)</span>
          <span class="count-badge">{{ sinAsignar.length }}</span>
        </div>
        <div v-if="sinAsignar.length" class="leads-table">
          <div v-for="lead in sinAsignar" :key="lead.id" class="lead-row">
            <input type="checkbox" :value="lead.id" v-model="selected" class="lead-check" />
            <span class="lead-name">{{ lead.nombre || lead.numero }}</span>
            <span class="lead-date">{{ fmtDate(lead.ultimo_mensaje_at) }}</span>
            <span class="lead-preview">{{ truncate(lead.ultimo_mensaje, 50) }}</span>
            <div class="lead-actions">
              <select @change="transferOne(lead.id, $event.target.value); $event.target.value = ''">
                <option value="">Asignar...</option>
                <option v-for="u in onlineUsers" :key="u.id" :value="u.id">{{ u.nombre }} 🟢</option>
              </select>
            </div>
          </div>
        </div>
        <div v-else class="empty-row">No hay leads en espera</div>
      </div>

      <!-- Por usuario -->
      <div v-for="user in data.users" :key="user.id" class="section-card">
        <div :class="['section-header', { online: user.crm_online }]">
          <div class="header-left">
            <span :class="user.crm_online ? 'dot-on' : 'dot-off'">●</span>
            {{ user.nombre }}
            <button v-if="userLeads(user.id).length"
                    @click="toggleSelectAll(user.id)"
                    class="select-all-btn">
              {{ isAllSelected(user.id) ? '☑ Deseleccionar' : '☐ Seleccionar todos' }}
            </button>
          </div>
          <div class="header-stats">
            <span>💬 {{ user.activos || 0 }} activos</span>
            <span>✅ {{ user.positivos || 0 }} positivos</span>
            <span>❌ {{ user.negativos || 0 }} negativos</span>
            <span class="total-badge">{{ user.total || 0 }} chats</span>
          </div>
        </div>
        <div v-if="userLeads(user.id).length" class="leads-table">
          <div v-for="lead in userLeads(user.id)" :key="lead.id" class="lead-row">
            <input type="checkbox" :value="lead.id" v-model="selected" class="lead-check" />
            <span class="lead-name">{{ lead.nombre || lead.numero }}</span>
            <span class="lead-date">{{ fmtDate(lead.ultimo_mensaje_at) }}</span>
            <div class="lead-tags">
              <span v-for="(tag, i) in parseTags(lead)" :key="i"
                    class="tag-pill" :style="{ background: tag.color, color: 'white' }">
                {{ tag.nombre }}
              </span>
            </div>
            <span class="lead-preview">{{ truncate(lead.ultimo_mensaje, 40) }}</span>
            <div class="lead-actions">
              <select @change="transferOne(lead.id, $event.target.value); $event.target.value = ''">
                <option value="">Transferir...</option>
                <option v-for="u in onlineUsers" :key="u.id" :value="u.id">{{ u.nombre }} 🟢</option>
              </select>
            </div>
          </div>
        </div>
        <div v-else class="empty-row">Sin leads activos</div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api'

const data       = ref({})
const loading    = ref(true)
const selected   = ref([])
const filterDesde = ref('')
const filterHasta = ref('')

const onlineUsers = computed(() => (data.value.users || []).filter(u => u.crm_online))

function filterByDate(arr) {
  if (!filterDesde.value && !filterHasta.value) return arr
  return arr.filter(l => {
    if (!l.ultimo_mensaje_at) return false
    const d = l.ultimo_mensaje_at.substring(0, 10)
    return !(filterDesde.value && d < filterDesde.value || filterHasta.value && d > filterHasta.value)
  })
}

const sinAsignar = computed(() => filterByDate(data.value.sin_asignar || []))

function userLeads(uid) {
  return filterByDate(data.value.user_leads?.[uid] || [])
}
function isAllSelected(uid) {
  const ul = userLeads(uid)
  return ul.length > 0 && ul.every(l => selected.value.includes(l.id))
}
function toggleSelectAll(uid) {
  const ul = userLeads(uid)
  if (isAllSelected(uid)) {
    const ids = ul.map(l => l.id)
    selected.value = selected.value.filter(id => !ids.includes(id))
  } else {
    const s = new Set(selected.value)
    ul.forEach(l => s.add(l.id))
    selected.value = [...s]
  }
}

function parseTags(lead) {
  if (!lead.etiquetas) return []
  const names  = lead.etiquetas.split(',')
  const colors = (lead.etiqueta_colores || '').split(',')
  return names.map((n, i) => ({ nombre: n, color: colors[i] || '#999' }))
}
function fmtDate(dt) {
  if (!dt) return ''
  const d = new Date(dt)
  return d.toLocaleDateString('es-AR', { day: 'numeric', month: 'numeric' }) + ', ' +
         d.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })
}
function truncate(s, n) {
  return s ? (s.length > n ? s.substring(0, n) + '...' : s) : ''
}

async function loadGestion() {
  try {
    const { data: d } = await api.get('/crm/gestion')
    data.value = d
  } catch (e) { console.error(e) }
  finally { loading.value = false }
}

async function transferOne(leadId, userId) {
  if (!userId) return
  try {
    await api.post(`/crm/leads/${leadId}/transfer`, { usuario_id: parseInt(userId) })
    selected.value = selected.value.filter(id => id !== leadId)
    await loadGestion()
  } catch (e) { alert(e.response?.data?.error || 'Error al transferir') }
}

async function bulkTransfer(userId) {
  if (!userId || !selected.value.length) return
  if (!confirm(`¿Transferir ${selected.value.length} leads?`)) return
  for (const id of [...selected.value]) {
    try { await api.post(`/crm/leads/${id}/transfer`, { usuario_id: parseInt(userId) }) }
    catch (e) { console.error(e) }
  }
  selected.value = []; await loadGestion()
}

async function bulkClose(estado) {
  if (!confirm(`¿Cerrar ${selected.value.length} leads como ${estado}?`)) return
  for (const id of [...selected.value]) {
    try { await api.post(`/crm/leads/${id}/status`, { estado }) }
    catch (e) { console.error(e) }
  }
  selected.value = []; await loadGestion()
}

onMounted(loadGestion)
</script>
