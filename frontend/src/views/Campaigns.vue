<template>
  <div class="campaigns">
    <div class="page-header">
      <div><h2>📢 Campañas</h2><p>Envío masivo a contactos de tus instancias</p></div>
    </div>

    <div class="tabs">
      <button :class="['tab',{active:tab==='create'}]" @click="tab='create'">Crear Campaña</button>
      <button :class="['tab',{active:tab==='history'}]" @click="tab='history';loadHistory()">Historial</button>
    </div>

    <div v-if="tab==='create'" class="create-form">
      <div class="form-section">
        <h3>Mensaje</h3>
        <div class="form-group">
          <label>Nombre de la campaña</label>
          <input v-model="form.nombre" type="text" placeholder="Ej: Promo Marzo 2026" />
        </div>
        <div class="form-group">
          <label>Mensaje</label>
          <textarea v-model="form.mensaje" rows="5"
            placeholder="Escribí el mensaje que se enviará a todos los contactos..."></textarea>
          <small>El mismo mensaje se envía desde todas las instancias seleccionadas.</small>
        </div>
        <div class="form-group">
          <label>Adjuntar archivo (opcional)</label>
          <div class="file-upload">
            <input type="file" @change="onFileChange" accept="image/*,video/*,.pdf,.doc,.docx" />
            <small>Imagen, video o documento.</small>
          </div>
        </div>
      </div>

      <div class="form-section">
        <h3>Configuración Anti-Ban</h3>
        <div class="config-grid">
          <div class="form-group">
            <label>Contactos por tanda</label>
            <input v-model.number="form.contactos_por_tanda" type="number" min="1" max="50" />
          </div>
          <div class="form-group">
            <label>Delay mín (seg)</label>
            <input v-model.number="form.delay_min" type="number" min="1" />
          </div>
          <div class="form-group">
            <label>Delay máx (seg)</label>
            <input v-model.number="form.delay_max" type="number" min="1" />
          </div>
          <div class="form-group">
            <label>Pausa entre tandas (seg)</label>
            <input v-model.number="form.delay_entre_tandas" type="number" min="30" />
          </div>
        </div>
      </div>

      <div class="form-section">
        <h3>Seleccionar Instancias y Contactos</h3>
        <div class="instances-grid">
          <label v-for="inst in instances" :key="inst.id" class="instance-check">
            <input type="checkbox" :value="inst.id" v-model="form.instancias" />
            <span class="instance-name">{{ inst.nombre }}</span>
            <span class="instance-count">{{ inst.contactos }} contactos</span>
            <button @click.prevent="alert(`${inst.nombre}: ${inst.contactos} contactos`)" class="link-btn">
              ver contactos
            </button>
          </label>
        </div>
        <div v-if="form.instancias.length" class="selected-summary">
          ✅ {{ form.instancias.length }} instancia(s) — {{ totalContactos }} contactos totales
        </div>
      </div>

      <div class="form-actions">
        <button @click="submit('borrador')" class="btn-secondary" :disabled="saving">
          {{ saving ? 'Guardando...' : 'Guardar como borrador' }}
        </button>
        <button @click="submit('activa')" class="btn-primary" :disabled="saving">
          {{ saving ? 'Creando...' : 'Crear y enviar ahora' }}
        </button>
      </div>
    </div>

    <div v-if="tab==='history'" class="history">
      <div v-if="histLoading" class="loading">Cargando historial...</div>
      <div v-else-if="!campaigns.length" class="empty">No hay campañas creadas</div>
      <div v-else class="campaigns-table">
        <table>
          <thead>
            <tr>
              <th>Nombre</th><th>Instancia</th><th>Estado</th><th>Contactos</th>
              <th>Enviados</th><th>Fallidos</th><th>Creada</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in campaigns" :key="c.id">
              <td><strong>{{ c.nombre }}</strong></td>
              <td>{{ c.instancia_nombre || '—' }}</td>
              <td><span :class="['badge', badgeClass(c.estado)]">{{ c.estado }}</span></td>
              <td>{{ c.total_contactos }}</td>
              <td class="green-text">{{ c.enviados }}</td>
              <td class="red-text">{{ c.fallidos }}</td>
              <td>{{ fmtDate(c.created_at) }}</td>
              <td class="actions">
                <button v-if="c.estado==='borrador'" @click="setStatus(c,'activa')" class="btn-xs green">▶</button>
                <button v-if="c.estado==='activa'||c.estado==='enviando'" @click="setStatus(c,'pausada')" class="btn-xs orange">⏸</button>
                <button v-if="c.estado==='pausada'" @click="setStatus(c,'activa')" class="btn-xs green">▶</button>
                <button @click="deleteCampaign(c)" class="btn-xs red">🗑️</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api'

const tab = ref('create'), instances = ref([]), campaigns = ref([])
const saving = ref(false), histLoading = ref(false)
const form = ref({ nombre:'', mensaje:'', instancias:[],
  contactos_por_tanda:10, delay_min:10, delay_max:25, delay_entre_tandas:180,
  media_url:null, media_type:null })

const totalContactos = computed(() =>
  instances.value.filter(i => form.value.instancias.includes(i.id))
    .reduce((s,i) => s+(parseInt(i.contactos)||0), 0))

const badgeClass = e => ({activa:'badge-green',enviando:'badge-green',pausada:'badge-orange',
  completada:'badge-blue',cancelada:'badge-red',borrador:'badge-gray'}[e]||'badge-gray')
const fmtDate = d => d ? new Date(d).toLocaleDateString('es-AR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}) : ''

function onFileChange(e) { const f=e.target.files[0]; if(f) form.value.media_type=f.type }
function resetForm() { form.value={nombre:'',mensaje:'',instancias:[],contactos_por_tanda:10,delay_min:10,delay_max:25,delay_entre_tandas:180,media_url:null,media_type:null} }

async function submit(estado) {
  if(!form.value.nombre||!form.value.mensaje||!form.value.instancias.length){alert('Completá nombre, mensaje e instancias');return}
  saving.value=true
  try {
    const {data}=await api.post('/campaigns',{...form.value,estado})
    alert(estado==='borrador'?'Campaña guardada como borrador':`Campaña creada! (${data.ids?.length||1} instancias)`)
    resetForm(); tab.value='history'; loadHistory()
  } catch(e){alert(e.response?.data?.error||'Error')} finally{saving.value=false}
}

async function loadHistory() {
  histLoading.value=true
  try{const{data}=await api.get('/campaigns');campaigns.value=data.campaigns||[]}
  catch(e){console.error(e)}finally{histLoading.value=false}
}

async function setStatus(c,estado){await api.post(`/campaigns/${c.id}/status`,{estado});c.estado=estado}
async function deleteCampaign(c){
  if(!confirm(`¿Eliminar "${c.nombre}"?`))return
  await api.delete(`/campaigns/${c.id}`)
  campaigns.value=campaigns.value.filter(x=>x.id!==c.id)
}

onMounted(async()=>{try{const{data}=await api.get('/campaigns/instances');instances.value=data.instances||[]}catch(e){console.error(e)}})
</script>
