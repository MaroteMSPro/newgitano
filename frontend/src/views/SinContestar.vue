<template>
  <div class="sc-page">
    <div v-if="toast" :class="['toast', toast.type]">{{ toast.msg }}</div>

    <div class="page-header">
      <div><h2>📵 Sin Contestar</h2><p>Leads con mensajes pendientes de respuesta</p></div>
      <button @click="load" class="btn-outline">🔄 Actualizar</button>
    </div>

    <!-- Filtros -->
    <div class="filtros">
      <select v-model="filters.modo" @change="load" class="select-input">
        <option value="todos">Todos (nunca + pendientes)</option>
        <option value="nunca">Nunca contestados</option>
        <option value="pendiente">Pendientes (último msg entrante)</option>
      </select>
      <select v-model="filters.vendedor_id" @change="load" class="select-input">
        <option value="">Todos los vendedores</option>
        <option v-for="v in vendedores" :key="v.id" :value="v.id">{{ v.nombre }}</option>
      </select>
      <div class="horas-filter">
        <label>Más de</label>
        <input type="number" v-model.number="filters.horas" min="0" max="720" @change="load" class="input-horas" />
        <label>hs sin respuesta</label>
      </div>
      <span v-if="total !== null" class="total-badge">{{ total }} leads</span>
    </div>

    <div v-if="loading" class="loading-state">⏳ Cargando...</div>
    <div v-else-if="!grupos.length" class="empty-state">
      ✅ ¡Todo contestado! No hay leads pendientes con esos filtros.
    </div>

    <div v-else>
      <div v-for="grupo in grupos" :key="grupo.nombre" class="grupo-vendedor">
        <div class="grupo-header">
          <span class="vendedor-name">👤 {{ grupo.nombre }}</span>
          <span class="vendedor-count">{{ grupo.total }} leads</span>
        </div>
        <div class="tabla-wrapper">
          <table class="tabla">
            <thead>
              <tr>
                <th>Nombre</th><th>Número</th><th>Instancia</th>
                <th>Hs sin respuesta</th><th>Msgs</th><th>Etiquetas</th><th>Acción</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="lead in grupo.leads" :key="lead.id">
                <td class="td-nombre">{{ lead.nombre || '—' }}</td>
                <td class="td-numero">{{ lead.numero }}</td>
                <td class="td-inst">{{ lead.instancia_nombre || '—' }}</td>
                <td>
                  <span :class="['horas-badge', urgency(lead.horas_sin_respuesta)]">
                    {{ lead.horas_sin_respuesta }}h
                  </span>
                </td>
                <td class="td-msgs">
                  <span class="msg-in" title="entrantes">⬇{{ lead.msgs_entrantes }}</span>
                  <span class="msg-out" title="salientes">⬆{{ lead.msgs_salientes }}</span>
                </td>
                <td>
                  <span v-for="tag in lead.etiquetas" :key="tag.nombre"
                        class="tag-badge"
                        :style="{ background: tag.color+'22', color: tag.color }">
                    {{ tag.nombre }}
                  </span>
                </td>
                <td>
                  <button @click="openSend(lead)" class="btn-enviar">💬 Enviar</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modal envío -->
    <div v-if="showModal" class="modal-overlay" @click.self="showModal=false">
      <div class="modal">
        <div class="modal-header">
          <h3>Enviar mensaje a {{ activeLead?.nombre || activeLead?.numero }}</h3>
          <button @click="showModal=false" class="modal-close">✕</button>
        </div>
        <div class="modal-body">
          <div class="lead-preview">
            <span>📞 {{ activeLead?.numero }}</span>
            <span>📱 {{ activeLead?.instancia_nombre }}</span>
          </div>
          <div class="form-group">
            <label>Mensaje *</label>
            <textarea v-model="sendMsg" rows="4" placeholder="Escribí tu mensaje aquí..." class="textarea"></textarea>
          </div>
          <div v-if="sendError" class="alert-error">{{ sendError }}</div>
        </div>
        <div class="modal-footer">
          <button @click="showModal=false" class="btn-secondary">Cancelar</button>
          <button @click="doSend" class="btn-primary" :disabled="!sendMsg || sending">
            <span v-if="sending">⏳ Enviando...</span>
            <span v-else>📤 Enviar</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const grupos    = ref([])
const vendedores = ref([])
const total     = ref(null)
const loading   = ref(false)
const toast     = ref(null)
const showModal = ref(false)
const activeLead = ref(null)
const sendMsg   = ref('')
const sendError = ref('')
const sending   = ref(false)
const filters   = ref({ modo:'todos', vendedor_id:'', horas:0 })

function showToast(msg, type='success') {
  toast.value = { msg, type }
  setTimeout(() => toast.value = null, 4000)
}
function urgency(h) { return h >= 48 ? 'rojo' : h >= 24 ? 'naranja' : h >= 8 ? 'amarillo' : 'verde' }

async function load() {
  loading.value = true
  try {
    const params = { modo: filters.value.modo }
    if (filters.value.vendedor_id) params.vendedor_id = filters.value.vendedor_id
    if (filters.value.horas) params.horas = filters.value.horas
    const { data } = await api.get('/sin-contestar', { params })
    grupos.value    = data.por_vendedor    || []
    vendedores.value = data.vendedores_lista || []
    total.value     = data.total ?? 0
  } catch (e) { showToast(e?.response?.data?.error || 'Error al cargar', 'error') }
  finally { loading.value = false }
}

function openSend(lead) {
  activeLead.value = lead; sendMsg.value = ''; sendError.value = ''; showing = true
  showModal.value = true
}

async function doSend() {
  if (!sendMsg.value || !activeLead.value) return
  sending.value = true; sendError.value = ''
  try {
    await api.post('/sin-contestar/enviar', {
      lead_id: activeLead.value.id,
      mensaje: sendMsg.value,
      instancia_id: activeLead.value.instancia_id
    })
    showModal.value = false
    showToast(`Mensaje enviado a ${activeLead.value.nombre || activeLead.value.numero}`)
    load()
  } catch (e) { sendError.value = e?.response?.data?.error || 'Error al enviar' }
  finally { sending.value = false }
}

let showing = false
onMounted(load)
</script>
