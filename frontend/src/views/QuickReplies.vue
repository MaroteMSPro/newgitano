<template>
  <div class="quick-replies">
    <div class="page-header">
      <div><h2>⚡ Respuestas Rápidas</h2><p>Atajos de texto para el chat</p></div>
      <button @click="showForm=true" class="btn-primary">+ Nuevo atajo</button>
    </div>

    <div v-if="showForm" class="form-section">
      <h3>{{ editId ? 'Editar' : 'Nuevo' }} atajo</h3>
      <div class="form-group">
        <label>Nombre</label>
        <input v-model="form.nombre" placeholder="Nombre del atajo" />
      </div>
      <div class="form-group">
        <label>Comando (ej: /saludo)</label>
        <input v-model="form.atajo" placeholder="/comando" />
      </div>
      <div class="form-group">
        <label>Contenido</label>
        <textarea v-model="form.contenido" rows="4" placeholder="Texto del atajo..."></textarea>
      </div>
      <div class="form-actions">
        <button @click="showForm=false; editId=null" class="btn-secondary">Cancelar</button>
        <button @click="save" class="btn-primary">Guardar</button>
      </div>
    </div>

    <div class="table-container">
      <table>
        <thead>
          <tr>
            <th>Nombre</th><th>Comando</th><th>Contenido</th>
            <th>Usuario</th><th>Instancia</th><th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in items" :key="s.id">
            <td><strong>{{ s.nombre }}</strong></td>
            <td><code>{{ s.atajo || '—' }}</code></td>
            <td class="msg-cell">{{ truncate(s.contenido,60) }}</td>
            <td>{{ s.usuario_nombre || 'Global' }}</td>
            <td>{{ s.instancia_nombre || 'Todas' }}</td>
            <td class="actions">
              <button @click="openEdit(s)" class="btn-xs">✏️</button>
              <button @click="remove(s.id)" class="btn-xs red">🗑️</button>
            </td>
          </tr>
          <tr v-if="!items.length"><td colspan="6" class="empty">No hay atajos</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const items    = ref([])
const showForm = ref(false)
const editId   = ref(null)
const form     = ref({ nombre:'', atajo:'', contenido:'' })
const truncate = (s,n) => s?(s.length>n?s.substring(0,n)+'...':s):''

async function load() { const{data}=await api.get('/shortcuts'); items.value=data.shortcuts||[] }
function openEdit(s) { form.value={nombre:s.nombre,atajo:s.atajo,contenido:s.contenido}; editId.value=s.id; showForm.value=true }
async function save() {
  editId.value ? await api.put(`/shortcuts/${editId.value}`,form.value) : await api.post('/shortcuts',form.value)
  showForm.value=false; editId.value=null; form.value={nombre:'',atajo:'',contenido:''}; load()
}
async function remove(id) { if(!confirm('¿Eliminar?'))return; await api.delete(`/shortcuts/${id}`); load() }
onMounted(load)
</script>
