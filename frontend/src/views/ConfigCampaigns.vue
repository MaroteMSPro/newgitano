<template>
  <div class="config-campaigns-view">
    <h2>⚙️ Configuración de Campañas</h2>
    <div v-if="loading" class="loading">Cargando...</div>
    <div v-else class="config-form">
      <div class="section-card">
        <h3>Valores por defecto</h3>
        <div class="form-group">
          <label>Contactos por tanda</label>
          <input v-model.number="config.contactos_por_tanda" type="number" min="1" max="50" />
        </div>
        <div class="form-group">
          <label>Delay mínimo (seg)</label>
          <input v-model.number="config.delay_min" type="number" min="1" />
        </div>
        <div class="form-group">
          <label>Delay máximo (seg)</label>
          <input v-model.number="config.delay_max" type="number" min="1" />
        </div>
        <div class="form-group">
          <label>Pausa entre tandas (seg)</label>
          <input v-model.number="config.delay_entre_tandas" type="number" min="30" />
        </div>
        <button @click="save" class="btn-save" :disabled="saving">
          {{ saving ? 'Guardando...' : '✔ Guardar' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const config  = ref({ contactos_por_tanda:10, delay_min:10, delay_max:25, delay_entre_tandas:180 })
const loading = ref(true)
const saving  = ref(false)

onMounted(async () => {
  try { const{data}=await api.get('/config/campaigns'); if(data.config) config.value={...config.value,...data.config} }
  catch(e){console.error(e)} finally{loading.value=false}
})

async function save() {
  saving.value=true
  try{await api.post('/config/campaigns',config.value);alert('Guardado ✅')}
  catch{alert('Error')} finally{saving.value=false}
}
</script>
