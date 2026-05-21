<template>
  <div class="contacts">
    <!-- Tabs principales -->
    <div class="main-tabs">
      <button :class="['main-tab',{active:mainTab==='individuales'}]"
              @click="mainTab='individuales';loadContacts(1)">
        👤 Individuales <span class="tab-badge">{{ pagination.total }}</span>
      </button>
      <button :class="['main-tab',{active:mainTab==='grupos'}]"
              @click="mainTab='grupos';loadGroups()">
        👥 Grupos <span class="tab-badge">{{ groups.length }}</span>
      </button>
    </div>

    <!-- ===== INDIVIDUALES ===== -->
    <template v-if="mainTab==='individuales'">
      <div class="contacts-tabs">
        <button :class="['tab',{active:subTab==='todos'}]" @click="subTab='todos';loadContacts(1)">
          Todos <span class="tab-badge">{{ pagination.total }}</span>
        </button>
        <button :class="['tab',{active:subTab==='agendados'}]" @click="subTab='agendados';loadContacts(1)">
          Agendados <span class="tab-badge green">{{ agendados }}</span>
        </button>
        <button :class="['tab',{active:subTab==='no_agendados'}]" @click="subTab='no_agendados';loadContacts(1)">
          No Agendados <span class="tab-badge orange">{{ noAgendados }}</span>
        </button>
      </div>

      <div class="search-bar">
        <input v-model="search" @input="debouncedLoad" type="text"
               placeholder="Buscar por nombre o número..." />
        <button @click="showAddForm=true" class="btn-primary">+ Agregar</button>
      </div>

      <div class="table-header">
        <div class="select-all">
          <input type="checkbox" v-model="selectAll" @change="toggleAll" />
          <span>{{ selected.length ? `${selected.length} seleccionados` : 'Seleccionar todos' }}</span>
        </div>
        <span class="page-info">Pág. {{ pagination.page }}/{{ pagination.pages }} — {{ pagination.total }} contactos</span>
      </div>

      <div v-if="loading" class="loading">Cargando...</div>
      <div v-else class="table-container">
        <table>
          <thead>
            <tr><th></th><th>Nombre</th><th>Número</th><th>Origen</th><th>Instancia</th><th>Acciones</th></tr>
          </thead>
          <tbody>
            <tr v-for="c in contacts" :key="c.id">
              <td><input type="checkbox" :value="c.id" v-model="selected" /></td>
              <td>{{ c.nombre || '—' }}</td>
              <td class="mono">{{ c.numero }}</td>
              <td><span :class="['badge','badge-'+origenClass(c.origen)]">{{ c.origen||'—' }}</span></td>
              <td>{{ c.instancia_nombre || '—' }}</td>
              <td class="actions-cell">
                <button @click="goToCRM(c)" class="btn-xs green" title="Ver en CRM">💬</button>
                <button @click="deleteContact(c.id)" class="btn-xs red" title="Eliminar">🗑️</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Paginación -->
      <div class="pagination">
        <button :disabled="pagination.page<=1" @click="loadContacts(1)" class="btn-xs">«</button>
        <button :disabled="pagination.page<=1" @click="loadContacts(pagination.page-1)" class="btn-xs">‹</button>
        <span class="page-num">{{ pagination.page }} / {{ pagination.pages }}</span>
        <button :disabled="pagination.page>=pagination.pages" @click="loadContacts(pagination.page+1)" class="btn-xs">›</button>
        <button :disabled="pagination.page>=pagination.pages" @click="loadContacts(pagination.pages)" class="btn-xs">»</button>
      </div>
    </template>

    <!-- ===== GRUPOS ===== -->
    <template v-if="mainTab==='grupos'">
      <div class="search-bar">
        <input v-model="groupSearch" type="text" placeholder="Buscar grupo..." />
      </div>
      <div v-if="groupsLoading" class="loading">Cargando grupos...</div>
      <div v-else class="table-container">
        <table>
          <thead>
            <tr><th>Nombre</th><th>Número</th><th>Participantes</th><th>Instancia</th><th>Acciones</th></tr>
          </thead>
          <tbody>
            <tr v-for="g in filteredGroups" :key="g.id">
              <td>{{ g.nombre || '—' }}</td>
              <td class="mono">{{ g.numero }}</td>
              <td>{{ g.participants_count || '—' }}</td>
              <td>{{ g.instancia_nombre || '—' }}</td>
              <td class="actions-cell">
                <button @click="syncGroup(g)" :disabled="g._syncing" class="btn-xs blue" title="Sincronizar participantes">
                  {{ g._syncing ? '⏳' : '🔄' }}
                </button>
                <button @click="openParticipants(g)" class="btn-xs" title="Ver participantes">👥</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <!-- Modal agregar contacto -->
    <div v-if="showAddForm" class="modal-overlay" @click.self="showAddForm=false">
      <div class="modal-card">
        <h3>Nuevo Contacto</h3>
        <div class="form-group">
          <label>Nombre</label>
          <input v-model="newContact.nombre" placeholder="Nombre" />
        </div>
        <div class="form-group">
          <label>Número (con código de país)</label>
          <input v-model="newContact.numero" placeholder="549XXXXXXXXXX" />
        </div>
        <div class="modal-actions">
          <button @click="showAddForm=false" class="btn-secondary">Cancelar</button>
          <button @click="addContact" class="btn-primary">Agregar</button>
        </div>
      </div>
    </div>

    <!-- Modal participantes -->
    <div v-if="showParticipants" class="modal-overlay" @click.self="showParticipants=false">
      <div class="modal-card modal-wide">
        <div class="modal-header">
          <h3>👥 {{ activeGroup?.nombre }}</h3>
          <span class="participant-count">{{ participants.length }} participantes</span>
        </div>
        <div class="participants-list">
          <div v-for="p in participants" :key="p.id" class="participant-row">
            <span class="p-name">{{ p.nombre || '—' }}</span>
            <span class="p-number mono">{{ p.numero }}</span>
            <span v-if="p.es_admin" class="admin-badge">Admin</span>
          </div>
          <div v-if="!participants.length" class="empty">No hay participantes cargados</div>
        </div>
        <div class="modal-actions">
          <button @click="copyNumbers" class="btn-secondary">📋 Copiar números</button>
          <button @click="downloadNumbers" class="btn-secondary">⬇️ Descargar .txt</button>
          <button @click="showParticipants=false" class="btn-primary">Cerrar</button>
        </div>
        <div v-if="copyMsg" class="copy-msg">{{ copyMsg }}</div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import store from '../store'
import api from '../api'

const router = useRouter()
const mainTab   = ref('individuales')
const subTab    = ref('todos')
const contacts  = ref([])
const groups    = ref([])
const pagination = ref({ page:1, pages:1, total:0 })
const loading    = ref(true)
const groupsLoading = ref(false)
const search     = ref('')
const groupSearch = ref('')
const selected   = ref([])
const selectAll  = ref(false)
const showAddForm = ref(false)
const newContact = ref({ nombre:'', numero:'' })
const showParticipants = ref(false)
const activeGroup = ref(null)
const participants = ref([])
const copyMsg    = ref('')

const agendados   = computed(() => Math.round((pagination.value.total||0)*0.65))
const noAgendados = computed(() => (pagination.value.total||0) - agendados.value)
const filteredGroups = computed(() => {
  if (!groupSearch.value) return groups.value
  const q = groupSearch.value.toLowerCase()
  return groups.value.filter(g => (g.nombre||'').toLowerCase().includes(q))
})

const origenClass = o => ({ chat:'green', sync:'blue', manual:'gray', importado:'orange' }[o]||'gray')

function toggleAll() { selectAll.value ? selected.value=contacts.value.map(c=>c.id) : selected.value=[] }

let debTimer = null
function debouncedLoad() { clearTimeout(debTimer); debTimer=setTimeout(()=>loadContacts(1),300) }

async function loadContacts(page=1) {
  loading.value=true
  try {
    const params = { page, limit:30 }
    if (search.value) params.search=search.value
    if (store.selectedInstance) params.instancia_id=store.selectedInstance
    const{data}=await api.get('/contacts',{params})
    contacts.value=data.contacts; pagination.value=data.pagination
    selected.value=[]; selectAll.value=false
  } catch(e){console.error(e)} finally{loading.value=false}
}

async function loadGroups() {
  groupsLoading.value=true
  try {
    const params={tipo:'grupo',limit:500}
    if(store.selectedInstance) params.instancia_id=store.selectedInstance
    const{data}=await api.get('/contacts',{params})
    groups.value=data.contacts||[]
  } catch(e){console.error(e)} finally{groupsLoading.value=false}
}

async function addContact() {
  try {
    await api.post('/contacts',newContact.value)
    showAddForm.value=false; newContact.value={nombre:'',numero:''}; loadContacts(pagination.value.page)
  } catch(e){alert(e.response?.data?.error||'Error')}
}

async function deleteContact(id) {
  if(!confirm('¿Eliminar contacto?'))return
  await api.delete(`/contacts/${id}`); loadContacts(pagination.value.page)
}

function goToCRM() { router.push('/crm') }

async function syncGroup(g) {
  g._syncing=true
  try {
    const{data}=await api.post('/sync/group-participants',{contacto_id:g.id})
    g.participants_count=data.total_db
    alert(`✅ ${data.grupo}: ${data.importados} sincronizados, ${data.total_db} total`)
  } catch(e){alert('❌ Error: '+(e.response?.data?.error||e.message))}
  finally{g._syncing=false}
}

async function openParticipants(g) {
  activeGroup.value=g; showParticipants.value=true; copyMsg.value=''
  try {
    const{data}=await api.get('/groups/participants',{params:{id:g.id}})
    participants.value=data.participantes||[]
  } catch(e){console.error(e);participants.value=[]}
}

function copyNumbers() {
  const nums=participants.value.map(p=>p.numero).join('\n')
  navigator.clipboard.writeText(nums).then(()=>{
    copyMsg.value=`${participants.value.length} números copiados`
    setTimeout(()=>copyMsg.value='',3000)
  })
}

function downloadNumbers() {
  const nums=participants.value.map(p=>p.numero).join('\n')
  const blob=new Blob([nums],{type:'text/plain'})
  const url=URL.createObjectURL(blob), a=document.createElement('a')
  a.href=url; a.download=`${activeGroup.value?.nombre||'grupo'}_numeros.txt`; a.click(); URL.revokeObjectURL(url)
}

onMounted(loadContacts)
watch(()=>store.selectedInstance, ()=>{ mainTab.value==='grupos' ? loadGroups() : loadContacts(1) })
</script>
