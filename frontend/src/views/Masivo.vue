<template>
  <div class="masivo">
    <div class="page-header">
      <div><h2>⚡ Envío Masivo</h2><p>Enviá un mensaje a TODOS los contactos de las instancias seleccionadas</p></div>
    </div>

    <div class="tabs">
      <button :class="['tab',{active:tab==='send'}]" @click="tab='send'">Nuevo Envío</button>
      <button :class="['tab',{active:tab==='status'}]" @click="tab='status';loadHistory()">Historial</button>
    </div>

    <!-- Panel de envío -->
    <div v-if="tab==='send'" class="send-panel">
      <div class="form-section">
        <h3>📱 Seleccionar instancias</h3>
        <div v-if="instLoading" class="loading-sm">Cargando instancias...</div>
        <div v-else class="instances-grid">
          <label v-for="inst in instances" :key="inst.id" class="inst-check">
            <input type="checkbox" :value="inst.id" v-model="form.instancias_ids" />
            <span class="inst-label">
              <strong>{{ inst.descripcion || inst.nombre }}</strong>
              <small>{{ inst.nombre }}<span v-if="inst.total_contactos"> — {{ inst.total_contactos }} contactos</span></small>
            </span>
          </label>
        </div>
        <p v-if="!instances.length && !instLoading" class="hint">No hay instancias disponibles.</p>
      </div>

      <div class="form-section">
        <h3>💬 Mensaje</h3>
        <div class="form-group">
          <textarea v-model="form.mensaje" rows="6"
                    placeholder="Escribí el mensaje que se enviará a todos los contactos..."></textarea>
          <small>{{ form.mensaje.length }} caracteres</small>
        </div>
      </div>

      <div class="form-section">
        <h3>⚙️ Configuración</h3>
        <div class="config-grid">
          <div class="form-group">
            <label>Delay mínimo (seg)</label>
            <input v-model.number="form.delay_min" type="number" min="5" max="60" />
            <small>Mínimo recomendado: 8s</small>
          </div>
          <div class="form-group">
            <label>Delay máximo (seg)</label>
            <input v-model.number="form.delay_max" type="number" min="5" max="120" />
          </div>
          <div class="form-group">
            <label>Límite de contactos</label>
            <input v-model.number="form.limit" type="number" min="1" max="500" />
            <small>Por instancia. Máx. 500</small>
          </div>
        </div>
      </div>

      <!-- Estimación -->
      <div v-if="form.instancias_ids.length && form.delay_min" class="estimate-box">
        <span class="estimate-icon">⏳</span>
        <div>
          <strong>Estimación:</strong>
          Con {{ form.instancias_ids.length }} instancia(s) y hasta {{ form.limit }} contactos c/u,
          puede tardar entre <strong>{{ estimMin }}</strong> y <strong>{{ estimMax }}</strong> en completarse.
        </div>
      </div>

      <div class="form-actions">
        <button class="btn-primary btn-big" @click="confirmSend" :disabled="sending">
          {{ sending ? '⏳ Encolando...' : '⚡ Enviar Masivo' }}
        </button>
      </div>
      <div v-if="sendError" class="alert-error">{{ sendError }}</div>
      <div v-if="sendSuccess" class="alert-success">
        <strong>✅ {{ sendSuccess }}</strong>
        <p>El envío se procesa en segundo plano. Revisá el historial para ver el estado.</p>
      </div>
    </div>

    <!-- Historial -->
    <div v-if="tab==='status'">
      <div v-if="histLoading" class="loading">Cargando historial...</div>
      <div v-else-if="!histItems.length" class="empty-state">
        <div class="empty-icon">📋</div>
        <p>No hay envíos masivos registrados todavía.</p>
      </div>
      <div v-else class="envios-list">
        <div v-for="e in histItems" :key="e.id" class="envio-card">
          <div class="envio-header">
            <div>
              <span class="envio-name">{{ e.nombre }}</span>
              <small>por {{ e.usuario_nombre || 'Admin' }}</small>
            </div>
            <span :class="['status-badge', 'status-'+e.estado]">{{ e.estado }}</span>
          </div>
          <div class="envio-meta">
            <span>📱 {{ e.total_instancias }} instancia(s)</span>
            <span>✅ {{ e.completadas || 0 }} completadas</span>
            <span>🕐 {{ fmtDate(e.created_at) }}</span>
          </div>
          <div class="envio-msg"><em>{{ truncate(e.mensaje, 120) }}</em></div>
        </div>
      </div>
    </div>

    <!-- Modal confirmación -->
    <div v-if="showConfirm" class="modal-overlay" @click.self="showConfirm=false">
      <div class="modal confirm-modal">
        <h3>⚠️ Confirmar Envío Masivo</h3>
        <p>Estás a punto de enviar a <strong>todos los contactos</strong> de <strong>{{ form.instancias_ids.length }}</strong> instancia(s).</p>
        <p>Límite: <strong>{{ form.limit }}</strong> contactos por instancia.</p>
        <p>Mensaje: <em>{{ truncate(form.mensaje, 100) }}</em></p>
        <div class="confirm-actions">
          <button class="btn-secondary" @click="showConfirm=false">Cancelar</button>
          <button class="btn-danger" @click="doSend">Sí, Enviar</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api'

const tab         = ref('send')
const instances   = ref([])
const histItems   = ref([])
const instLoading = ref(false)
const histLoading = ref(false)
const sending     = ref(false)
const showConfirm = ref(false)
const sendError   = ref('')
const sendSuccess = ref('')

const form = ref({ instancias_ids:[], mensaje:'', delay_min:8, delay_max:20, limit:100 })

function fmtDur(s) { return s<60?s+'s':s<3600?Math.round(s/60)+' min':(s/3600).toFixed(1)+'h' }
const estimMin = computed(() => fmtDur(form.value.instancias_ids.length * form.value.limit * form.value.delay_min))
const estimMax = computed(() => fmtDur(form.value.instancias_ids.length * form.value.limit * form.value.delay_max))
const fmtDate  = d => d ? new Date(d).toLocaleDateString('es-AR',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}) : '-'
const truncate  = (s,n) => s?(s.length>n?s.slice(0,n)+'…':s):''

function confirmSend() {
  sendError.value=''; sendSuccess.value=''
  if(!form.value.instancias_ids.length){sendError.value='Seleccioná al menos una instancia';return}
  if(!form.value.mensaje.trim()){sendError.value='Escribí un mensaje';return}
  showConfirm.value=true
}

async function doSend() {
  showConfirm.value=false; sending.value=true; sendError.value=''; sendSuccess.value=''
  try {
    const{data}=await api.post('/masivo/send',form.value)
    sendSuccess.value=`Envío encolado a ~${data.total} contactos (ID: ${data.envio_id})`
    form.value.instancias_ids=[]; form.value.mensaje=''
  } catch(e){sendError.value=e.response?.data?.error||'Error al enviar'}
  finally{sending.value=false}
}

async function loadHistory() {
  histLoading.value=true
  try{const{data}=await api.get('/masivo/status');histItems.value=data.envios||[]}
  catch{histItems.value=[]} finally{histLoading.value=false}
}

onMounted(async()=>{
  instLoading.value=true
  try{const{data}=await api.get('/campaigns/instances');instances.value=data.instances||data.instancias||[]}
  catch{instances.value=[]} finally{instLoading.value=false}
})
</script>
