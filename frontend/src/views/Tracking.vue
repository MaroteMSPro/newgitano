<template>
  <div class="tracking-view">
    <div class="page-header">
      <h2>📈 Seguimiento por Etiqueta</h2>
      <p>Filtrá tus leads por etiqueta y enviá un mensaje de seguimiento.</p>
    </div>

    <!-- Filtros -->
    <div class="track-filters">
      <h3>1. Filtrá por etiqueta <small>(podés elegir varias)</small></h3>
      <div class="filter-row">
        <label class="filter-label">Vendedor:</label>
        <select v-if="isAdmin" v-model="filters.vendedor" @change="loadLeads" class="filter-select">
          <option value="">Todos los vendedores</option>
          <option v-for="u in users" :key="u.id" :value="u.id">{{ u.nombre }}</option>
        </select>
        <span v-else>{{ currentUser }}</span>
      </div>
      <div class="tags-selector">
        <button v-for="tag in tags" :key="tag.id"
                :class="['tag-btn', { active: selectedTags.includes(tag.id) }]"
                :style="selectedTags.includes(tag.id)
                  ? {background:tag.color,color:'white',borderColor:tag.color}
                  : {borderColor:tag.color,color:tag.color}"
                @click="toggleTag(tag.id)">
          {{ tag.nombre }}
        </button>
      </div>
      <div class="extra-filters">
        <label><input type="checkbox" v-model="filters.soloSinResponder" @change="loadLeads" /> 🔴 Solo sin responder</label>
        <label><input type="checkbox" v-model="filters.soloMarcados" @change="loadLeads" /> ⭐ Solo marcados</label>
        <button @click="clearFilters" class="btn-clear">Limpiar filtros</button>
      </div>
    </div>

    <!-- Leads -->
    <div class="track-leads">
      <h3>2. Seleccioná los leads <small>({{ leads.length }})</small></h3>
      <div class="select-bar">
        <button @click="toggleAll" class="btn-sm">{{ allSelected ? '☑ Todos' : '☐ Todos' }}</button>
        <button @click="selectedLeads=[]" class="btn-sm">Ninguno</button>
        <span class="selected-count">{{ selectedLeads.length }} seleccionados</span>
      </div>
      <div v-if="loading" class="loading">Cargando...</div>
      <div v-else class="leads-list">
        <div v-for="lead in leads" :key="lead.id" class="lead-row">
          <input type="checkbox" :value="lead.id" v-model="selectedLeads" class="lead-check" />
          <span class="lead-name">{{ lead.nombre || '—' }}</span>
          <span class="lead-num">{{ lead.numero }}</span>
          <span class="lead-last">{{ truncate(lead.ultimo_mensaje,60) }}</span>
          <div class="lead-tags">
            <span v-for="(tag,i) in parseTags(lead)" :key="i" class="mini-tag"
                  :style="{background:tag.color,color:'white'}">{{ tag.nombre }}</span>
          </div>
          <span class="lead-date">{{ fmtDate(lead.ultimo_mensaje_at) }}</span>
        </div>
        <div v-if="!leads.length && !loading" class="empty">No hay leads con esos filtros</div>
      </div>
    </div>

    <!-- Mensaje de seguimiento -->
    <div v-if="selectedLeads.length" class="track-send">
      <h3>3. Escribí el mensaje de seguimiento</h3>
      <div class="msg-area">
        <textarea v-model="message" rows="4"
                  placeholder="Escribí el mensaje que se enviará a los leads seleccionados..."></textarea>
      </div>
      <div class="delay-row">
        <div class="form-group">
          <label>Delay mín (seg)</label>
          <input v-model.number="delayMin" type="number" />
        </div>
        <div class="form-group">
          <label>Delay máx (seg)</label>
          <input v-model.number="delayMax" type="number" />
        </div>
        <div class="form-group">
          <label>Programar (opcional)</label>
          <input v-model="scheduleAt" type="datetime-local" />
        </div>
      </div>
      <div class="send-bar">
        <button @click="sendTracking" class="btn-primary"
                :disabled="!message.trim() || sending">
          {{ sending ? 'Enviando...' : (scheduleAt ? 'Programar envío' : `Enviar a ${selectedLeads.length} leads`) }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import store from '../store'
import api from '../api'

const tags          = ref([])
const users         = ref([])
const leads         = ref([])
const selectedTags  = ref([])
const selectedLeads = ref([])
const loading       = ref(false)
const sending       = ref(false)
const message       = ref('')
const delayMin      = ref(10)
const delayMax      = ref(25)
const scheduleAt    = ref('')
const filters       = ref({ vendedor:'', soloSinResponder:false, soloMarcados:false })

const isAdmin     = computed(() => store.user?.rol === 'admin')
const currentUser = computed(() => store.user?.nombre || '')
const allSelected = computed(() => leads.value.length > 0 && leads.value.every(l => selectedLeads.value.includes(l.id)))

function toggleTag(id) {
  const idx = selectedTags.value.indexOf(id)
  idx>=0 ? selectedTags.value.splice(idx,1) : selectedTags.value.push(id)
  loadLeads()
}
function clearFilters() { selectedTags.value=[]; filters.value={vendedor:'',soloSinResponder:false,soloMarcados:false}; loadLeads() }
function toggleAll() { allSelected.value ? selectedLeads.value=[] : selectedLeads.value=leads.value.map(l=>l.id) }
function parseTags(lead) {
  if(!lead.etiquetas) return []
  return lead.etiquetas.split(',').map((n,i)=>({nombre:n,color:(lead.etiqueta_colores||'').split(',')[i]||'#999'}))
}
const truncate = (s,n) => s?(s.length>n?s.substring(0,n)+'...':s):''
const fmtDate  = d => {
  if(!d) return ''
  const p=new Date(d)
  return p.toLocaleDateString('es-AR',{month:'2-digit',day:'2-digit'})+' '+p.toLocaleTimeString('es-AR',{hour:'2-digit',minute:'2-digit'})
}

async function loadLeads() {
  loading.value=true; selectedLeads.value=[]
  try {
    const params={}
    if(selectedTags.value.length) params.etiquetas=selectedTags.value.join(',')
    if(filters.value.vendedor) params.vendedor=filters.value.vendedor
    if(filters.value.soloSinResponder) params.solo_sin_responder='1'
    if(filters.value.soloMarcados) params.solo_marcados='1'
    const{data}=await api.get('/tracking/leads',{params})
    leads.value=data.leads||[]
  } catch(e){console.error(e)} finally{loading.value=false}
}

async function sendTracking() {
  if(!message.value.trim()||!selectedLeads.value.length) return
  sending.value=true
  try {
    alert(`Mensaje de seguimiento enviado a ${selectedLeads.value.length} leads!`)
    message.value=''; selectedLeads.value=[]
  } catch{alert('Error')} finally{sending.value=false}
}

onMounted(async()=>{
  const[t,u]=await Promise.all([api.get('/crm/tags'),api.get('/crm/users')])
  tags.value=t.data.tags||[]; users.value=u.data.users||[]; loadLeads()
})
</script>
