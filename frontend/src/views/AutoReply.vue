<template>
  <div class="auto-reply-view">
    <div class="page-header">
      <div><h2>🤖 Auto-Respuesta</h2><p>Mensaje automático para leads nuevos</p></div>
      <button @click="showForm=true" class="btn-primary">+ Nueva auto-respuesta</button>
    </div>

    <div v-if="showForm" class="form-section">
      <h3>{{ editId ? 'Editar' : 'Nueva' }} auto-respuesta</h3>
      <div class="form-group">
        <label>Nombre</label>
        <input v-model="form.nombre" placeholder="Mi auto-respuesta" />
      </div>
      <div class="form-group">
        <label>Mensaje</label>
        <textarea v-model="form.mensaje" rows="6" placeholder="Mensaje automático..."></textarea>
      </div>
      <div class="form-group">
        <label><input type="checkbox" v-model="form.solo_nuevos" /> Solo para leads nuevos</label>
      </div>
      <div class="form-group">
        <label><input type="checkbox" v-model="form.activa" /> Activa</label>
      </div>
      <div class="form-actions">
        <button @click="showForm=false; editId=null" class="btn-secondary">Cancelar</button>
        <button @click="save" class="btn-primary">Guardar</button>
      </div>
    </div>

    <div class="ar-list">
      <div v-for="ar in items" :key="ar.id" class="ar-card">
        <div class="ar-head">
          <strong>{{ ar.nombre }}</strong>
          <div class="ar-badges">
            <span v-if="ar.activa" class="badge badge-green">Activa</span>
            <span v-else class="badge badge-gray">Inactiva</span>
            <span v-if="ar.solo_nuevos" class="badge badge-blue">Solo nuevos</span>
          </div>
        </div>
        <div class="ar-user">👤 {{ ar.usuario_nombre }}</div>
        <p class="ar-msg">{{ ar.mensaje }}</p>
        <div class="ar-actions">
          <button @click="openEdit(ar)" class="btn-sm">✏️ Editar</button>
          <button @click="toggleActive(ar)" class="btn-sm">{{ ar.activa ? '⏸ Desactivar' : '▶ Activar' }}</button>
          <button @click="remove(ar.id)" class="btn-sm danger">🗑️ Eliminar</button>
        </div>
      </div>
      <div v-if="!items.length" class="empty">No hay auto-respuestas configuradas</div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const items    = ref([])
const showForm = ref(false)
const editId   = ref(null)
const form     = ref({ nombre:'', mensaje:'', solo_nuevos:true, activa:true })

async function load() { const{data}=await api.get('/auto-reply'); items.value=data.auto_replies||[] }

function openEdit(ar) {
  form.value={nombre:ar.nombre,mensaje:ar.mensaje,solo_nuevos:!!ar.solo_nuevos,activa:!!ar.activa}
  editId.value=ar.id; showForm.value=true
}

async function save() {
  editId.value ? await api.put(`/auto-reply/${editId.value}`,form.value) : await api.post('/auto-reply',form.value)
  showForm.value=false; editId.value=null; form.value={nombre:'',mensaje:'',solo_nuevos:true,activa:true}; load()
}

async function toggleActive(ar) { await api.put(`/auto-reply/${ar.id}`,{...ar,activa:ar.activa?0:1}); load() }
async function remove(id) { if(!confirm('¿Eliminar?'))return; await api.delete(`/auto-reply/${id}`); load() }

onMounted(load)
</script>
