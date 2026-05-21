<template>
  <div class="config-crm">
    <h2>Configuración CRM</h2>

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card"><div class="stat-num blue">{{ stats.total||0 }}</div><div class="stat-label">Total Leads</div></div>
      <div class="stat-card"><div class="stat-num">{{ stats.sin_asignar||0 }}</div><div class="stat-label">Sin asignar</div></div>
      <div class="stat-card"><div class="stat-num green">{{ stats.asignados||0 }}</div><div class="stat-label">Asignados</div></div>
      <div class="stat-card"><div class="stat-num">{{ stats.cerrado_positivo||0 }}</div><div class="stat-label">Cerrado +</div></div>
      <div class="stat-card"><div class="stat-num">{{ stats.cerrado_negativo||0 }}</div><div class="stat-label">Cerrado -</div></div>
    </div>

    <div class="two-cols">
      <!-- Col izquierda: config distribución -->
      <div class="left-col">
        <div class="section-card">
          <h3>⚙️ Distribución de Leads</h3>
          <div class="form-group">
            <label>Modo de distribución</label>
            <select v-model="config.modo_distribucion" class="form-select">
              <option value="round_robin">Round Robin (1 y 1, por orden)</option>
              <option value="least_chats">Menor cantidad (asigna al que tiene menos chats activos)</option>
            </select>
            <small>{{ config.modo_distribucion==='round_robin'
              ? 'Round Robin: asigna en orden secuencial.'
              : 'Menor cantidad: asigna al que tiene menos chats activos.' }}</small>
          </div>
          <div class="toggle-row">
            <label class="toggle">
              <input type="checkbox" v-model="config.auto_asignar" />
              <span class="toggle-slider"></span>
            </label>
            <div>
              <strong>Auto-asignar leads nuevos</strong>
              <small>Si está desactivado, los leads quedan como "Nuevo" hasta asignación manual</small>
            </div>
          </div>
          <div class="form-group">
            <label>Máximo chats activos por usuario</label>
            <input v-model.number="config.max_chats_por_usuario" type="number" />
          </div>
          <button @click="saveConfig" class="btn-save" :disabled="saving">
            {{ saving ? 'Guardando...' : '✔ Guardar' }}
          </button>
        </div>

        <!-- CAMBIO 1: Etiquetas — cualquier user puede crear, no solo admin -->
        <div class="section-card">
          <h3>🏷️ Etiquetas</h3>
          <div class="tags-list">
            <span v-for="tag in tags" :key="tag.id"
                  class="tag-pill"
                  :style="{background:tag.color,color:'white'}">
              {{ tag.nombre }}
              <button @click="deleteTag(tag.id)" class="tag-del">✕</button>
            </span>
          </div>
          <div class="tag-form">
            <input v-model="newTag.nombre" placeholder="Nueva etiqueta..." @keyup.enter="createTag" />
            <input v-model="newTag.color" type="color" />
            <button @click="createTag" class="btn-sm" :disabled="!newTag.nombre.trim()">+ Agregar</button>
          </div>
        </div>
      </div>

      <!-- Col derecha: stats usuarios -->
      <div class="right-col">
        <div class="section-card">
          <h3>👥 Actividad de Usuarios</h3>
          <div class="users-table">
            <table>
              <thead>
                <tr><th>Usuario</th><th>Online</th><th>Activos</th><th>+ Cerrados</th><th>- Cerrados</th></tr>
              </thead>
              <tbody>
                <tr v-for="u in usersStats" :key="u.id">
                  <td>{{ u.nombre }}</td>
                  <td><span :class="u.crm_online?'dot-on':'dot-off'">●</span></td>
                  <td><span class="count-badge teal">{{ u.activos||0 }}</span></td>
                  <td><span class="count-badge green">{{ u.cerrado_positivo||0 }}</span></td>
                  <td><span class="count-badge red">{{ u.cerrado_negativo||0 }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const config     = ref({ modo_distribucion:'round_robin', auto_asignar:true, max_chats_por_usuario:300 })
const tags       = ref([])
const usersStats = ref([])
const stats      = ref({})
const saving     = ref(false)
const newTag     = ref({ nombre:'', color:'#25D366' })

async function saveConfig() {
  saving.value=true
  try{await api.put('/config/crm',config.value);alert('Configuración guardada ✅')}
  catch{alert('Error')} finally{saving.value=false}
}

// CAMBIO 1: createTag sin verificación de admin (el backend también debe permitirlo)
async function createTag() {
  if(!newTag.value.nombre.trim()) return
  await api.post('/crm/tags', newTag.value)
  newTag.value={nombre:'',color:'#25D366'}
  loadTags()
}

async function deleteTag(id) {
  if(!confirm('¿Eliminar etiqueta?')) return
  await api.delete(`/crm/tags/${id}`); loadTags()
}

async function loadTags() {
  const{data}=await api.get('/crm/tags'); tags.value=(data.tags||[]).filter(t=>t.activa!=0)
}

onMounted(async()=>{
  try {
    const[cfg,tgs,us]=await Promise.all([api.get('/config/crm'),api.get('/crm/tags'),api.get('/config/crm/users-stats')])
    if(cfg.data.config){config.value={...config.value,...cfg.data.config};config.value.auto_asignar=!!config.value.auto_asignar}
    stats.value=cfg.data.stats||{}
    tags.value=(tgs.data.tags||[]).filter(t=>t.activa!=0)
    usersStats.value=us.data.users||[]
  } catch(e){console.error(e)}
})
</script>
