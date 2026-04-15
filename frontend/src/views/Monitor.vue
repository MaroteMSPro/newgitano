<template>
  <div class="monitor-view">
    <div class="page-header">
      <h2>📡 Monitor de Respuestas</h2>
      <div class="toolbar">
        <button @click="load" class="btn-refresh">🔄 Actualizar</button>
        <label class="auto-label">
          <input type="checkbox" v-model="autoRefresh" /> Auto (30s)
        </label>
      </div>
    </div>

    <!-- Stats globales -->
    <div class="stats-row">
      <div class="stat-card"><div class="stat-num red">{{ stats.total_pendientes||0 }}</div><div class="stat-label">Esperando respuesta</div></div>
      <div class="stat-card"><div class="stat-num">{{ stats.sin_asignar||0 }}</div><div class="stat-label">Sin asignar</div></div>
      <div class="stat-card"><div class="stat-num blue">{{ fmtTime(stats.avg_global_seg) }}</div><div class="stat-label">Tiempo promedio resp.</div></div>
    </div>

    <div class="monitor-grid">
      <!-- Tabla usuarios -->
      <div class="monitor-panel">
        <h3>👥 Rendimiento por Usuario</h3>
        <table class="monitor-table">
          <thead><tr><th>Usuario</th><th>Estado</th><th>Pendientes</th><th>Activos</th><th>Prom. Resp.</th><th>Cerrado +/-</th></tr></thead>
          <tbody>
            <tr v-for="u in users" :key="u.id" :class="{row_alert:u.pendientes>5}">
              <td><strong>{{ u.nombre }}</strong></td>
              <td><span :class="['badge-on', u.crm_online?'on':'off']">{{ u.crm_online?'🟢':'🔴' }}</span></td>
              <td><span :class="['count-pill', u.pendientes>5?'red':u.pendientes>0?'orange':'green']">{{ u.pendientes }}</span></td>
              <td>{{ u.activos }}</td>
              <td>{{ fmtTime(u.avg_respuesta_seg) }}</td>
              <td><span class="pos">{{ u.cerrado_pos }}</span> / <span class="neg">{{ u.cerrado_neg }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mayor espera -->
      <div class="monitor-panel">
        <h3>⏰ Mayor Tiempo de Espera</h3>
        <div class="wait-list">
          <div v-for="w in waiting" :key="w.id"
               :class="['wait-item', urgency(w.minutos_espera)]">
            <div class="wait-info">
              <strong>{{ w.nombre || w.numero }}</strong>
              <small>👤 {{ w.asignado_nombre || 'Sin asignar' }}</small>
            </div>
            <div class="wait-stats">
              <span class="wait-time">{{ fmtMinutes(w.minutos_espera) }}</span>
              <span class="wait-msgs">{{ w.mensajes_sin_leer }} msg</span>
            </div>
          </div>
          <div v-if="!waiting.length" class="empty">Todo al día 🎉</div>
        </div>
      </div>
    </div>

    <!-- Últimos mensajes -->
    <div class="monitor-panel">
      <h3>📩 Últimos Mensajes Entrantes</h3>
      <div class="recent-msgs">
        <div v-for="m in recent" :key="m.id" class="msg-item">
          <div class="msg-head">
            <strong>{{ m.lead_nombre || m.lead_numero }}</strong>
            <span class="msg-ago">hace {{ fmtMinutes(m.minutos_ago) }}</span>
          </div>
          <p class="msg-body">{{ truncate(m.contenido,80) || (m.tipo!=='text'?`[${m.tipo}]`:'') }}</p>
          <small class="msg-assigned">→ {{ m.asignado_nombre || 'Sin asignar' }}</small>
        </div>
        <div v-if="!recent.length" class="empty">Sin mensajes recientes</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue'
import api from '../api'

const users       = ref([])
const waiting     = ref([])
const recent      = ref([])
const stats       = ref({})
const autoRefresh = ref(false)
let interval = null

function fmtTime(s) { if(!s||s<=0) return '—'; s=Math.round(s); return s<60?`${s}s`:s<3600?`${Math.round(s/60)}min`:`${(s/3600).toFixed(1)}h` }
function fmtMinutes(p) { if(!p||p<1) return '<1min'; return p<60?`${Math.round(p)}min`:p<1440?`${(p/60).toFixed(1)}h`:`${Math.round(p/1440)}d` }
function urgency(m) { return m>120?'critical':m>30?'warning':'ok' }
const truncate = (s,n) => s?(s.length>n?s.substring(0,n)+'...':s):''

async function load() {
  try {
    const{data}=await api.get('/monitor')
    users.value=data.users||[]; waiting.value=data.waiting||[]; recent.value=data.recent||[]; stats.value=data.stats||{}
  } catch(e){console.error(e)}
}

watch(autoRefresh, v => {
  if(interval) clearInterval(interval)
  if(v) interval=setInterval(load,30000)
})

onMounted(load)
onUnmounted(()=>{ if(interval) clearInterval(interval) })
</script>
