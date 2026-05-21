<template>
  <div class="rec-page">
    <div class="rec-header">
      <h2>🔔 Recordatorios</h2>
      <div class="rec-toolbar">
        <select v-model="filterEstado" @change="load" class="filter-select">
          <option value="">Todos</option>
          <option value="pendiente">🕐 Pendientes</option>
          <option value="enviado">✅ Enviados</option>
          <option value="error">❌ Errores</option>
          <option value="cancelado">🚫 Cancelados</option>
        </select>
        <button @click="openModal" class="btn-new">+ Nuevo Recordatorio</button>
      </div>
    </div>

    <div v-if="loading" class="loading">Cargando...</div>
    <div v-else-if="!items.length" class="empty">
      No hay recordatorios{{ filterEstado ? ' con ese estado' : '' }}.
    </div>
    <div v-else class="table-wrap">
      <table class="rec-table">
        <thead>
          <tr>
            <th>Lead</th><th>Número</th><th>Mensaje</th>
            <th>Fecha/Hora</th><th>Estado</th><th>Acción</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in items" :key="r.id">
            <td>{{ r.lead_nombre || '—' }}</td>
            <td>{{ r.numero }}</td>
            <td class="msg-cell" :title="r.mensaje">{{ truncate(r.mensaje,60) }}</td>
            <td>{{ fmtDate(r.fecha_hora) }}</td>
            <td><span :class="['badge', r.estado]">{{ estadoLabel(r.estado) }}</span></td>
            <td>
              <button v-if="r.estado==='pendiente'" @click="cancel(r.id)"
                      class="btn-cancel-rec" title="Cancelar">🚫 Cancelar</button>
              <span v-else class="na">—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal nuevo recordatorio -->
    <div v-if="showModal" class="modal-overlay" @click.self="showModal=false">
      <div class="modal-box">
        <div class="modal-header">
          <strong>🔔 Nuevo Recordatorio</strong>
          <button @click="showModal=false" class="modal-close">✕</button>
        </div>
        <div class="modal-body">
          <label>Buscar Lead</label>
          <input v-model="searchLead" @input="debouncedSearch"
                 type="text" placeholder="Nombre o número..." />
          <div v-if="searchResults.length" class="lead-results">
            <div v-for="lead in searchResults" :key="lead.id"
                 class="lead-result-item" @click="selectLead(lead)">
              <strong>{{ lead.nombre || lead.numero }}</strong>
              <small>{{ lead.numero }}</small>
            </div>
          </div>
          <div v-if="selectedLead" class="selected-lead-info">
            ✅ Lead: <strong>{{ selectedLead }}</strong>
          </div>
          <label>Fecha y hora</label>
          <input type="datetime-local" v-model="formDate" :min="minDate" class="modal-input" />
          <label>Mensaje</label>
          <textarea v-model="formMsg" rows="4" class="modal-input"
                    placeholder="Escribí el mensaje a enviar..."></textarea>
          <p v-if="formError" class="modal-error">{{ formError }}</p>
        </div>
        <div class="modal-footer">
          <button @click="showModal=false" class="btn-secondary">Cancelar</button>
          <button @click="saveReminder" class="btn-primary" :disabled="saving">
            {{ saving ? 'Guardando...' : 'Crear Recordatorio' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import api from '../api'

const items        = ref([])
const loading      = ref(true)
const filterEstado = ref('')
const showModal    = ref(false)
const saving       = ref(false)
const searchLead   = ref('')
const searchResults = ref([])
const selectedLeadId = ref(null)
const selectedLead = ref('')
const formDate     = ref('')
const formMsg      = ref('')
const formError    = ref('')

const minDate = computed(() => new Date(Date.now()+120000).toISOString().slice(0,16))
const truncate = (s,n) => s?(s.length>n?s.substring(0,n)+'...':s)||'':''
const fmtDate  = d => {
  if(!d) return '—'
  const dt=new Date(d)
  return dt.toLocaleDateString('es-AR')+' '+dt.toLocaleTimeString('es-AR',{hour:'2-digit',minute:'2-digit'})
}
const estadoLabel = e => ({'pendiente':'🕐 Pendiente','enviado':'✅ Enviado','error':'❌ Error','cancelado':'🚫 Cancelado'}[e]||e)

let searchTimer=null
function debouncedSearch() {
  clearTimeout(searchTimer)
  if(searchLead.value.trim().length<2){searchResults.value=[];return}
  searchTimer=setTimeout(async()=>{
    try{const{data}=await api.get('/contacts',{params:{search:searchLead.value.trim()}});searchResults.value=(data.contacts||[]).slice(0,8)}
    catch{searchResults.value=[]}
  },300)
}
function selectLead(lead) { selectedLeadId.value=lead.id; selectedLead.value=lead.nombre||lead.numero; searchLead.value=lead.nombre||lead.numero; searchResults.value=[] }

function openModal() { showModal.value=true; searchLead.value=''; searchResults.value=[]; selectedLeadId.value=null; selectedLead.value=''; formDate.value=minDate.value; formMsg.value=''; formError.value='' }

async function saveReminder() {
  formError.value=''
  if(!selectedLeadId.value){formError.value='Seleccioná un lead';return}
  if(!formDate.value){formError.value='Elegí una fecha y hora';return}
  if(!formMsg.value.trim()){formError.value='Escribí el mensaje';return}
  saving.value=true
  try{await api.post('/recordatorios',{lead_id:selectedLeadId.value,fecha_hora:formDate.value,mensaje:formMsg.value.trim()});showModal.value=false;await load()}
  catch(e){formError.value=e?.response?.data?.error||'Error al crear recordatorio'} finally{saving.value=false}
}

async function cancel(id) {
  if(!confirm('¿Cancelar este recordatorio?'))return
  try{await api.delete(`/recordatorios/${id}`);await load()}
  catch(e){alert(e?.response?.data?.error||'Error al cancelar')}
}

async function load() {
  loading.value=true
  try{const params={}; if(filterEstado.value) params.estado=filterEstado.value; const{data}=await api.get('/recordatorios',{params}); items.value=data.data||[]}
  catch(e){console.error(e)} finally{loading.value=false}
}

let interval=null
onMounted(()=>{load(); interval=setInterval(load,30000)})
onUnmounted(()=>{if(interval)clearInterval(interval)})
</script>
