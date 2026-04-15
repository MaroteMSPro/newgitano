<template>
  <div class="camp-multi">
    <div class="page-header">
      <div><h2>🚀 Campañas Multi-Instancia</h2><p>Enviá el mismo mensaje desde múltiples instancias simultáneamente</p></div>
      <button class="btn-primary" @click="tab='create'">+ Nueva Campaña Multi</button>
    </div>

    <div class="tabs">
      <button :class="['tab',{active:tab==='list'}]" @click="tab='list';loadList()">Historial</button>
      <button :class="['tab',{active:tab==='create'}]" @click="tab='create'">Crear</button>
    </div>

    <!-- Listado -->
    <div v-if="tab==='list'">
      <div v-if="listLoading" class="loading">Cargando campañas...</div>
      <div v-else-if="campaigns.length" class="campaigns-grid">
        <div v-for="c in campaigns" :key="c.grupo_id" class="campaign-card">
          <div class="card-header">
            <span class="camp-name">{{ c.nombre }}</span>
            <span :class="['status-badge','status-'+c.estado_grupo]">{{ c.estado_grupo }}</span>
          </div>
          <div class="card-stats">
            <div class="stat"><span class="stat-val">{{ c.total_instancias }}</span><small>instancias</small></div>
            <div class="stat"><span class="stat-val">{{ c.total_contactos||0 }}</span><small>contactos</small></div>
            <div class="stat"><span class="stat-val text-green">{{ c.enviados||0 }}</span><small>enviados</small></div>
            <div class="stat"><span class="stat-val text-red">{{ c.fallidos||0 }}</span><small>fallidos</small></div>
          </div>
          <div class="card-date">{{ fmtDate(c.created_at) }}</div>
          <button class="btn-sm btn-outline" @click="loadProgress(c.grupo_id)">Ver progreso</button>
        </div>
      </div>
      <div v-else class="empty-state">
        <div class="empty-icon">🚀</div>
        <p>No hay campañas multi-instancia todavía.</p>
        <button class="btn-primary" @click="tab='create'">Crear primera campaña</button>
      </div>
    </div>

    <!-- Crear -->
    <div v-if="tab==='create'" class="create-form">
      <div class="form-section">
        <h3>📝 Datos de la campaña</h3>
        <div class="form-group">
          <label>Nombre de la campaña *</label>
          <input v-model="form.nombre" type="text" placeholder="Ej: Promo Abril Multi" />
        </div>
        <div class="form-group">
          <label>Mensaje *</label>
          <textarea v-model="form.mensaje" rows="5"
                    placeholder="El mensaje que se enviará a todos los contactos de las instancias seleccionadas..."></textarea>
        </div>
      </div>

      <div class="form-section">
        <h3>📱 Seleccionar instancias</h3>
        <div v-if="instLoading" class="loading-sm">Cargando instancias...</div>
        <div v-else class="instances-grid">
          <label v-for="inst in instances" :key="inst.id" class="inst-check">
            <input type="checkbox" :value="inst.id" v-model="form.instancias_ids" />
            <span class="inst-label">
              <strong>{{ inst.descripcion || inst.nombre }}</strong>
              <small>{{ inst.nombre }} — {{ inst.total_contactos||0 }} contactos</small>
            </span>
          </label>
        </div>
        <p v-if="!instances.length && !instLoading" class="hint">No hay instancias disponibles.</p>
      </div>

      <div class="form-section">
        <h3>⏱ Configuración de delay (anti-ban)</h3>
        <div class="delay-grid">
          <div class="form-group">
            <label>Delay mínimo (segundos)</label>
            <input v-model.number="form.delay_min" type="number" min="5" max="60" />
          </div>
          <div class="form-group">
            <label>Delay máximo (segundos)</label>
            <input v-model.number="form.delay_max" type="number" min="5" max="120" />
          </div>
        </div>
      </div>

      <div class="form-actions">
        <button class="btn-secondary" @click="tab='list'">Cancelar</button>
        <button class="btn-primary" @click="createCampaign" :disabled="creating">
          {{ creating ? 'Creando...' : '🚀 Crear Campaña Multi' }}
        </button>
      </div>
      <div v-if="createError"   class="alert-error">{{ createError }}</div>
      <div v-if="createSuccess" class="alert-success">{{ createSuccess }}</div>
    </div>

    <!-- Modal progreso -->
    <div v-if="progress" class="modal-overlay" @click.self="progress=null">
      <div class="modal">
        <div class="modal-header">
          <h3>📊 Progreso de la campaña</h3>
          <button @click="progress=null" class="btn-close">✕</button>
        </div>
        <div class="modal-body">
          <div class="progress-summary">
            <div class="prog-stat"><span class="big">{{ progress.resumen.enviados }}</span><small>enviados</small></div>
            <div class="prog-stat"><span class="big text-red">{{ progress.resumen.fallidos }}</span><small>fallidos</small></div>
            <div class="prog-stat"><span class="big">{{ progress.resumen.total }}</span><small>total</small></div>
            <div class="prog-stat"><span class="big text-green">{{ progress.resumen.porcentaje }}%</span><small>completado</small></div>
          </div>
          <div class="progress-bar-wrap">
            <div class="progress-bar" :style="{ width: progress.resumen.porcentaje+'%' }"></div>
          </div>
          <table class="prog-table">
            <thead><tr><th>Instancia</th><th>Estado</th><th>Total</th><th>Enviados</th><th>Fallidos</th></tr></thead>
            <tbody>
              <tr v-for="i in progress.instancias" :key="i.id">
                <td>{{ i.instancia_nombre }}</td>
                <td><span :class="['status-badge','status-'+i.estado]">{{ i.estado }}</span></td>
                <td>{{ i.total_contactos }}</td>
                <td>{{ i.enviados }}</td>
                <td>{{ i.fallidos }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const tab         = ref('list')
const campaigns   = ref([])
const instances   = ref([])
const progress    = ref(null)
const listLoading = ref(false)
const instLoading = ref(false)
const creating    = ref(false)
const createError   = ref('')
const createSuccess = ref('')

const form = ref({ nombre:'', mensaje:'', instancias_ids:[], delay_min:8, delay_max:20 })
const fmtDate = d => d ? new Date(d).toLocaleDateString('es-AR',{day:'2-digit',month:'2-digit',year:'2-digit',hour:'2-digit',minute:'2-digit'}) : '-'

async function loadList() {
  listLoading.value=true
  try{const{data}=await api.get('/campaigns-multi');campaigns.value=data.campaigns_multi||[]}
  catch(e){if(e.response?.status===403)campaigns.value=[];else console.error(e)}
  finally{listLoading.value=false}
}

async function createCampaign() {
  createError.value=''; createSuccess.value=''
  if(!form.value.nombre||!form.value.mensaje){createError.value='Nombre y mensaje son requeridos';return}
  if(!form.value.instancias_ids.length){createError.value='Seleccioná al menos una instancia';return}
  creating.value=true
  try {
    const{data}=await api.post('/campaigns-multi/create',form.value)
    createSuccess.value=`✅ Campaña creada en ${data.instancias} instancias (grupo: ${data.grupo_id})`
    form.value={nombre:'',mensaje:'',instancias_ids:[],delay_min:8,delay_max:20}
    await loadList()
    setTimeout(()=>tab.value='list',1500)
  } catch(e){createError.value=e.response?.data?.error||'Error al crear campaña'}
  finally{creating.value=false}
}

async function loadProgress(grupoId) {
  try{const{data}=await api.get(`/campaigns-multi/${grupoId}/progress`);progress.value=data}
  catch{alert('Error al cargar progreso')}
}

onMounted(async()=>{
  loadList()
  instLoading.value=true
  try{const{data}=await api.get('/campaigns/instances');instances.value=data.instances||data.instancias||[]}
  catch{instances.value=[]} finally{instLoading.value=false}
})
</script>
