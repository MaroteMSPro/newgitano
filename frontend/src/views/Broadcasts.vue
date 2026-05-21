<template>
  <div class="broadcasts-view">
    <div class="page-header">
      <div><h2>📡 Difusiones</h2><p>Listas de envío masivo a grupos de contactos</p></div>
      <button @click="openNewList" class="btn-primary">+ Nueva Lista</button>
    </div>

    <div class="tabs">
      <button :class="['tab',{active:tab==='listas'}]" @click="tab='listas'">Listas</button>
      <button :class="['tab',{active:tab==='programados'}]" @click="tab='programados';loadScheduled()">Programados</button>
    </div>

    <!-- Listas -->
    <div v-if="tab==='listas'">
      <div v-if="loading" class="loading">Cargando...</div>
      <div v-else class="lists-grid">
        <div v-for="list in lists" :key="list.id" class="list-card"
             :class="{active: activeList?.id===list.id}"
             @click="selectList(list)">
          <div class="list-header">
            <strong>{{ list.nombre }}</strong>
            <span class="list-count">{{ list.contactos_count||0 }} contactos</span>
          </div>
          <div class="list-actions" @click.stop>
            <button @click="openSendModal(list)" class="btn-sm green">📤 Enviar</button>
            <button @click="deleteList(list.id)" class="btn-sm red">🗑️</button>
          </div>
        </div>
        <div v-if="!lists.length" class="empty">No hay listas de difusión</div>
      </div>

      <!-- Detalle lista seleccionada -->
      <div v-if="activeList" class="list-detail">
        <h3>Contactos de "{{ activeList.nombre }}"</h3>
        <div class="add-contact-row">
          <input v-model="newNumber" placeholder="Agregar número (549XXXXXXXXXX)..." @keyup.enter="addToList" />
          <button @click="addToList" class="btn-sm">+ Agregar</button>
        </div>
        <div v-if="listContactsLoading" class="loading">Cargando...</div>
        <div v-else class="contacts-list">
          <div v-for="c in listContacts" :key="c.id" class="contact-row">
            <span>{{ c.nombre || c.numero }}</span>
            <span class="mono">{{ c.numero }}</span>
          </div>
          <div v-if="!listContacts.length" class="empty">Lista vacía</div>
        </div>
      </div>
    </div>

    <!-- Programados -->
    <div v-if="tab==='programados'">
      <div v-if="schedLoading" class="loading">Cargando...</div>
      <div v-else class="table-container">
        <table>
          <thead><tr><th>Lista</th><th>Mensaje</th><th>Programado</th><th>Estado</th><th>Acción</th></tr></thead>
          <tbody>
            <tr v-for="s in scheduled" :key="s.id">
              <td>{{ s.lista_nombre || '—' }}</td>
              <td>{{ truncate(s.mensaje,50) }}</td>
              <td>{{ fmtDate(s.fecha_hora) }}</td>
              <td><span :class="['badge', s.estado]">{{ s.estado }}</span></td>
              <td><button v-if="s.estado==='pendiente'" @click="cancelScheduled(s.id)" class="btn-xs red">Cancelar</button></td>
            </tr>
            <tr v-if="!scheduled.length"><td colspan="5" class="empty">No hay envíos programados</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal nueva lista -->
    <div v-if="showNewList" class="modal-overlay" @click.self="showNewList=false">
      <div class="modal-card">
        <h3>Nueva Lista de Difusión</h3>
        <div class="form-group">
          <label>Nombre</label>
          <input v-model="newListName" placeholder="Ej: Clientes VIP" />
        </div>
        <div class="modal-actions">
          <button @click="showNewList=false" class="btn-secondary">Cancelar</button>
          <button @click="createList" class="btn-primary">Crear</button>
        </div>
      </div>
    </div>

    <!-- Modal envío -->
    <div v-if="showSend" class="modal-overlay" @click.self="showSend=false">
      <div class="modal-card">
        <h3>📤 Enviar a "{{ sendList?.nombre }}"</h3>
        <div class="form-group">
          <label>Mensaje</label>
          <textarea v-model="sendMsg" rows="5" placeholder="Escribí el mensaje..."></textarea>
        </div>
        <div class="form-group">
          <label>Programar (opcional)</label>
          <input type="datetime-local" v-model="sendDate" />
        </div>
        <div class="modal-actions">
          <button @click="showSend=false" class="btn-secondary">Cancelar</button>
          <button @click="doSend" class="btn-primary" :disabled="!sendMsg.trim()||sending">
            {{ sending ? 'Enviando...' : (sendDate ? '📅 Programar' : '📤 Enviar ahora') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const tab      = ref('listas')
const lists    = ref([])
const loading  = ref(false)
const activeList = ref(null)
const listContacts = ref([])
const listContactsLoading = ref(false)
const newNumber  = ref('')
const scheduled  = ref([])
const schedLoading = ref(false)
const showNewList = ref(false)
const newListName = ref('')
const showSend    = ref(false)
const sendList    = ref(null)
const sendMsg     = ref('')
const sendDate    = ref('')
const sending     = ref(false)

const truncate = (s,n) => s?(s.length>n?s.substring(0,n)+'...':s):''
const fmtDate  = d => d?new Date(d).toLocaleDateString('es-AR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}):''

async function loadLists() {
  loading.value=true
  try{const{data}=await api.get('/broadcasts/lists');lists.value=data.lists||[]}
  catch(e){console.error(e)} finally{loading.value=false}
}

async function selectList(list) {
  activeList.value=list; listContactsLoading.value=true
  try{const{data}=await api.get(`/broadcasts/lists/${list.id}`);listContacts.value=data.contacts||[]}
  catch{listContacts.value=[]} finally{listContactsLoading.value=false}
}

async function addToList() {
  if(!newNumber.value.trim()||!activeList.value)return
  try{await api.post(`/broadcasts/lists/${activeList.value.id}/contacts`,{numero:newNumber.value});newNumber.value='';selectList(activeList.value)}
  catch(e){alert(e.response?.data?.error||'Error')}
}

function openNewList() { newListName.value=''; showNewList.value=true }
async function createList() {
  if(!newListName.value.trim())return
  await api.post('/broadcasts/lists',{nombre:newListName.value})
  showNewList.value=false; loadLists()
}
async function deleteList(id) {
  if(!confirm('¿Eliminar esta lista?'))return
  await api.delete(`/broadcasts/lists/${id}`)
  if(activeList.value?.id===id)activeList.value=null
  loadLists()
}

function openSendModal(list) { sendList.value=list; sendMsg.value=''; sendDate.value=''; showSend.value=true }
async function doSend() {
  if(!sendMsg.value.trim()||!sendList.value)return; sending.value=true
  try {
    await api.post(`/broadcasts/lists/${sendList.value.id}/send`,{mensaje:sendMsg.value,fecha_hora:sendDate.value||null})
    showSend.value=false; alert('Envío programado ✅')
  } catch(e){alert(e.response?.data?.error||'Error')} finally{sending.value=false}
}

async function loadScheduled() {
  schedLoading.value=true
  try{const{data}=await api.get('/broadcasts/scheduled');scheduled.value=data.scheduled||[]}
  catch{scheduled.value=[]} finally{schedLoading.value=false}
}
async function cancelScheduled(id) {
  if(!confirm('¿Cancelar este envío?'))return
  await api.delete(`/broadcasts/scheduled/${id}`); loadScheduled()
}

onMounted(loadLists)
</script>
