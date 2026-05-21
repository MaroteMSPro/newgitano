<template>
  <div class="instances" @click="dropdownId=null">
    <div v-if="toast" :class="['toast', toast.type]">{{ toast.msg }}</div>

    <div class="page-header">
      <div>
        <h2>Instancias</h2>
        <p>Gestión de dispositivos WhatsApp conectados</p>
      </div>
      <div class="header-actions">
        <span class="inst-count">📱 {{ instances.length }} / {{ maxInstancias }} instancias usadas</span>
        <button class="btn-outline" @click="syncAll">🔄 Sincronizar Todas</button>
        <button class="btn-primary" @click="openNew"
                :disabled="instances.length >= maxInstancias"
                :title="instances.length >= maxInstancias ? 'Límite alcanzado' : ''">
          + Conectar nueva instancia
        </button>
      </div>
    </div>

    <!-- Grid de instancias -->
    <div class="cards-grid">
      <div v-for="inst in instances" :key="inst.id" class="inst-card">
        <div class="card-top">
          <div class="card-icon">📱</div>
          <div class="card-title">
            <strong>{{ inst.descripcion || inst.nombre }}</strong>
            <small>{{ inst.nombre }}</small>
          </div>
          <div class="card-menu" @click.stop="dropdownId = dropdownId===inst.id ? null : inst.id">⋮</div>
          <div v-if="dropdownId===inst.id" class="card-dropdown" @click.stop>
            <button @click="openEdit(inst)">✏️ Editar</button>
            <button @click="openUsers(inst)">👥 Asignar usuarios</button>
            <button v-if="!inst.es_default" @click="setDefault(inst.id)">⭐ Hacer default</button>
            <button class="danger" @click="deleteInst(inst.id)">🗑️ Eliminar</button>
          </div>
        </div>

        <span :class="['status-badge', inst.estado]">
          ● {{ statusLabel(inst.estado) }}
        </span>

        <span v-if="inst.sync_status==='syncing'" class="sync-badge">⏳ Sincronizando contactos...</span>
        <span v-else-if="inst.sync_status==='done'&&inst.estado==='conectado'" class="sync-badge done">✅ Contactos sincronizados</span>
        <span v-else-if="inst.sync_status==='error'" class="sync-badge error">⚠️ Error al sincronizar</span>

        <div class="card-info">
          <div>📞 {{ inst.numero ? '+'+inst.numero : '—' }}</div>
          <div>👤 {{ inst.nombre_perfil || '—' }}</div>
          <div>👥 {{ inst.users_count||0 }} usuarios</div>
          <div>📋 {{ inst.contactos_count||0 }} contactos</div>
          <div>👥 {{ inst.grupos_count||0 }} grupos</div>
        </div>

        <div class="card-footer">
          <div class="inst-actions">
            <button v-if="inst.estado!=='conectado'" @click="openQR(inst)" class="btn-sm btn-info">📲 Conectar QR</button>
            <button v-if="inst.estado==='conectado'" @click="disconnectInst(inst)" class="btn-sm btn-warn">🔌 Desconectar</button>
            <button @click="syncInst(inst.id)" class="btn-sync" :disabled="inst.sync_status==='syncing'">🔄 Sincronizar</button>
          </div>
        </div>
      </div>
    </div>
    <div v-if="showForm" class="modal-overlay" @click.self="closeForm">
      <div class="modal-card">
        <h3>{{ isEdit ? 'Editar' : 'Nueva' }} Instancia</h3>
        <template v-if="!isEdit && formStep==='qr'">
          <div class="qr-panel">
            <div v-if="qrLoading" class="qr-loading">⏳ Obteniendo QR...</div>
            <div v-else-if="qrDone" class="qr-done">✅ ¡Instancia conectada!</div>
            <img v-else-if="qrImg" :src="qrImg" class="qr-img" alt="QR" />
            <p v-else class="qr-empty">Esperando QR...</p>
            <p class="qr-status">{{ qrStatus }}</p>
            <button @click="closeForm" class="btn-secondary">Cerrar</button>
          </div>
        </template>
        <template v-else>
          <div class="form-group">
            <label>Nombre interno</label>
            <input v-model="instForm.nombre" placeholder="Ej: LUXOM-Principal" />
          </div>
          <div class="form-group">
            <label>Descripción</label>
            <input v-model="instForm.descripcion" placeholder="Descripción amigable" />
          </div>
          <div v-if="isEdit" class="form-group">
            <label>Número</label>
            <input v-model="instForm.numero" placeholder="549XXXXXXXXXX" />
          </div>
          <p v-if="formError" class="error">{{ formError }}</p>
          <div class="modal-actions">
            <button @click="closeForm" class="btn-secondary">Cancelar</button>
            <button @click="saveInst" class="btn-primary" :disabled="formSaving">
              {{ formSaving ? 'Procesando...' : (isEdit ? 'Guardar' : 'Crear y obtener QR') }}
            </button>
          </div>
        </template>
      </div>
    </div>

    <!-- Modal QR reconectar -->
    <div v-if="showQR" class="modal-overlay" @click.self="closeQR">
      <div class="modal-card">
        <h3>📲 Conectar {{ qrInst?.descripcion||qrInst?.nombre }}</h3>
        <div v-if="qrLoading" class="qr-loading">⏳ Obteniendo QR...</div>
        <div v-else-if="qrDone" class="qr-done">✅ ¡Conectado!</div>
        <img v-else-if="qrImg" :src="qrImg" class="qr-img" alt="QR" />
        <p v-else class="qr-empty">Esperando QR...</p>
        <div class="qr-actions">
          <button @click="disconnectAndReconnect" class="btn-sm btn-warn">🔌 Desconectar y regenerar</button>
          <button @click="closeQR" class="btn-secondary">Cerrar</button>
        </div>
      </div>
    </div>

    <!-- Modal asignar usuarios -->
    <div v-if="showUsers" class="modal-overlay" @click.self="showUsers=false">
      <div class="modal-card">
        <h3>👥 Usuarios — {{ usersInst?.descripcion||usersInst?.nombre }}</h3>
        <div class="user-checks">
          <label v-for="u in allUsers" :key="u.id" class="user-check">
            <input type="checkbox" :value="u.id" v-model="selectedUsers" />
            {{ u.nombre }}
          </label>
        </div>
        <div class="modal-actions">
          <button @click="showUsers=false" class="btn-secondary">Cancelar</button>
          <button @click="saveUsers" class="btn-primary">Guardar</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import api from '../api'

const instances   = ref([])
const maxInstancias = ref(1)
const allUsers    = ref([])
const dropdownId  = ref(null)
const toast       = ref(null)

// Form nueva/edit
const showForm  = ref(false)
const isEdit    = ref(false)
const editId    = ref(null)
const formStep  = ref('form')
const instForm  = ref({ nombre:'', api_key:'', descripcion:'', numero:'' })
const formError = ref('')
const formSaving = ref(false)
const newInstId = ref(null)

// QR
const showQR   = ref(false)
const qrInst   = ref(null)
const qrImg    = ref('')
const qrLoading = ref(false)
const qrDone   = ref(false)
const qrStatus = ref('Escaneando...')
let qrInterval = null
let qrInterval2 = null

// Users
const showUsers     = ref(false)
const usersInst     = ref(null)
const selectedUsers = ref([])

function showToast(msg, type='success') {
  toast.value={msg,type}; setTimeout(()=>toast.value=null,4000)
}
const statusLabel = s => ({conectado:'Conectado',escaneando:'Escaneando...',desconectado:'Desconectado'}[s]||s)

async function load() {
  try {
    const{data}=await api.get('/instances')
    instances.value=data.instances||[]; maxInstancias.value=data.max_instancias??1
    instances.value.filter(i=>i.estado==='conectado').forEach(i=>api.post(`/instances/${i.id}/setup-webhook`).catch(()=>{}))
  } catch(e){console.error(e)}
}

function openNew() { isEdit.value=false; editId.value=null; formStep.value='form'; instForm.value={nombre:'',api_key:'',descripcion:'',numero:''}; formError.value=''; showForm.value=true }
function openEdit(inst) { isEdit.value=true; editId.value=inst.id; instForm.value={nombre:inst.nombre,api_key:inst.api_key||'',descripcion:inst.descripcion||'',numero:inst.numero||''}; formError.value=''; showForm.value=true }
function closeForm() { clearQRInterval(); showForm.value=false; load() }

async function saveInst() {
  if (isEdit.value) {
    await api.put(`/instances/${editId.value}`, instForm.value); showForm.value=false; load()
  } else {
    if (!instForm.value.nombre) { formError.value='El nombre es requerido'; return }
    formSaving.value=true; formError.value=''
    try {
      const{data}=await api.post('/instances/create',{nombre:instForm.value.nombre,descripcion:instForm.value.descripcion})
      newInstId.value=data.id; formStep.value='qr'; qrDone.value=false; qrImg.value=''; qrLoading.value=true
      await fetchQR(newInstId.value); startQRPoll(newInstId.value)
    } catch(e){formError.value=e?.response?.data?.error||'Error al crear'} finally{formSaving.value=false}
  }
}

async function openQR(inst) { qrInst.value=inst; qrImg.value=''; qrDone.value=false; qrLoading.value=true; showQR.value=true; await fetchQR(inst.id); startQRPoll2(inst) }
function closeQR() { clearQRInterval(); showQR.value=false; load() }

async function fetchQR(id) {
  try {
    const{data}=await api.get(`/instances/${id}/qr`)
    const raw=data.data||data
    const b64=raw?.base64||raw?.qrcode?.base64||raw?.data?.base64||(typeof raw==='string'?raw:'')
    if(b64) qrImg.value=b64.startsWith('data:')?b64:`data:image/png;base64,${b64}`
  } catch{} finally{qrLoading.value=false}
}

function startQRPoll(id) {
  qrInterval=setInterval(async()=>{
    try{const{data}=await api.get(`/instances/${id}/status`); const st=data.instance?.estado||data.evo?.estado||''; qrStatus.value=st
      if(st==='conectado'){clearQRInterval();qrDone.value=true;showToast('¡Instancia conectada! Sincronizando... 🔄')}
      else await fetchQR(id)
    }catch{}
  },3000)
}

function startQRPoll2(inst) {
  qrInterval2=setInterval(async()=>{
    if(!showQR.value){clearInterval(qrInterval2);return}
    try{const{data}=await api.get(`/instances/${inst.id}/qr`); const raw=data.data||data
      if(raw?.connected||raw?.state==='open'){clearInterval(qrInterval2);qrDone.value=true;load()}
      else{const b64=raw?.qr||raw?.base64||raw?.qrcode?.base64;if(b64)qrImg.value=b64.startsWith('data:')?b64:`data:image/png;base64,${b64}`}
    }catch{} finally{qrLoading.value=false}
  },5000)
}

function clearQRInterval() {
  if(qrInterval){clearInterval(qrInterval);qrInterval=null}
  if(qrInterval2){clearInterval(qrInterval2);qrInterval2=null}
}

async function disconnectAndReconnect() {
  if(!qrInst.value)return; qrLoading.value=true; qrDone.value=false; qrImg.value=''
  try{await api.post(`/instances/${qrInst.value.id}/disconnect`)}catch{}
  setTimeout(async()=>{await fetchQR(qrInst.value.id); startQRPoll2(qrInst.value)},2000)
}

async function disconnectInst(inst) {
  if(!confirm(`¿Desconectar "${inst.descripcion||inst.nombre}"?`))return
  try{await api.post(`/instances/${inst.id}/disconnect`);showToast(`${inst.nombre} desconectada`);load()}
  catch(e){showToast(e?.response?.data?.error||'Error','error')}
}

async function deleteInst(id) {
  if(!confirm('¿Eliminar esta instancia?'))return
  await api.delete(`/instances/${id}`); dropdownId.value=null; showToast('Instancia eliminada'); load()
}

async function setDefault(id) {
  await api.post(`/instances/${id}/default`); dropdownId.value=null; load()
}

async function syncInst(id) {
  const inst=instances.value.find(i=>i.id===id)
  if(inst)inst.sync_status='syncing'; showToast('⏳ Sincronizando contactos...')
  try{const r=await api.post('/sync/contacts',{instancia_id:id}); showToast(`✅ ${r.data.importados||0} nuevos, ${r.data.actualizados||0} actualizados`)}
  catch{showToast('⚠️ Error al sincronizar','error')}
  try{const r=await api.post('/sync/groups',{instancia_id:id}); showToast(`✅ Grupos: ${r.data.grupos_importados||0} nuevos`)}
  catch{showToast('⚠️ Error al sincronizar grupos','error')}
  if(inst)inst.sync_status='done'; await load()
}

async function syncAll() {
  const connected=instances.value.filter(i=>i.estado==='conectado')
  if(!connected.length){showToast('No hay instancias conectadas');return}
  showToast(`⏳ Sincronizando ${connected.length} instancias...`)
  for(const i of connected){i.sync_status='syncing';try{await api.post('/sync/contacts',{instancia_id:i.id});i.sync_status='done'}catch{i.sync_status='error'}}
  showToast('✅ Sincronización completada'); await load()
}

async function openUsers(inst) {
  usersInst.value=inst; dropdownId.value=null
  try{const{data}=await api.get(`/instances/${inst.id}/users`);selectedUsers.value=(data.users||[]).map(u=>u.id)}
  catch{selectedUsers.value=[]}
  showUsers.value=true
}

async function saveUsers() {
  await api.post(`/instances/${usersInst.value.id}/users`,{user_ids:selectedUsers.value}); showUsers.value=false; load()
}

onMounted(async()=>{
  load()
  try{const{data}=await api.get('/crm/users');allUsers.value=data.users||[]}catch{}
})
onUnmounted(clearQRInterval)
</script>
