<template>
  <div class="users-view">
    <div class="page-header">
      <div><h2>👤 Usuarios</h2><p>Gestión de usuarios del CRM</p></div>
      <button @click="openCreate" class="btn-primary">+ Nuevo usuario</button>
    </div>

    <!-- Formulario crear/editar -->
    <div v-if="showForm" class="user-form-card">
      <h3>{{ editId ? 'Editar' : 'Nuevo' }} usuario</h3>
      <div class="form-grid">
        <div class="form-group">
          <label>Usuario</label>
          <input v-model="form.usuario" placeholder="usuario" />
        </div>
        <div class="form-group">
          <label>Nombre</label>
          <input v-model="form.nombre" placeholder="Nombre completo" />
        </div>
        <div class="form-group">
          <label>Responsable</label>
          <input v-model="form.responsable" placeholder="Responsable" />
        </div>
        <div class="form-group">
          <label>Rol</label>
          <select v-model="form.rol" class="form-select">
            <option value="admin">Admin</option>
            <option value="usuario">Usuario</option>
          </select>
        </div>
        <div class="form-group">
          <label>Contraseña {{ editId ? '(dejar vacío para no cambiar)' : '' }}</label>
          <input v-model="form.password" type="password" placeholder="••••••" />
        </div>
      </div>
      <div class="instancias-section">
        <label>Instancias asignadas</label>
        <div class="inst-checks">
          <label v-for="inst in instances" :key="inst.id" class="inst-check">
            <input type="checkbox" :value="inst.id" v-model="form.instancias" />
            {{ inst.nombre }} ({{ inst.numero }})
          </label>
        </div>
      </div>
      <div class="form-actions">
        <button @click="showForm=false" class="btn-secondary">Cancelar</button>
        <button @click="saveUser" class="btn-primary">Guardar</button>
      </div>
    </div>

    <!-- Tabla de usuarios -->
    <div class="users-table">
      <table>
        <thead>
          <tr>
            <th>Usuario</th><th>Nombre</th><th>Rol</th><th>Online</th>
            <th>Activo</th><th>Instancias</th><th>Último login</th><th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in users" :key="u.id">
            <td><strong>{{ u.usuario }}</strong></td>
            <td>{{ u.nombre }}</td>
            <td>
              <span :class="['badge', u.rol==='admin'?'badge-purple':'badge-blue']">{{ u.rol }}</span>
            </td>
            <td><span :class="u.crm_online?'dot-on':'dot-off'">●</span></td>
            <td><span :class="u.activo?'dot-on':'dot-off'">●</span></td>
            <td>{{ u.instancias || '—' }}</td>
            <td>{{ fmtDate(u.ultimo_login) }}</td>
            <td>
              <button @click="openEdit(u)" class="btn-xs">✏️</button>
              <button @click="deactivate(u.id)" class="btn-xs red">🗑️</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const users     = ref([])
const instances = ref([])
const showForm  = ref(false)
const editId    = ref(null)
const form      = ref({ usuario:'', nombre:'', responsable:'', rol:'usuario', password:'', instancias:[] })

const fmtDate = d => d ? new Date(d).toLocaleDateString('es-AR') : '—'

function openCreate() {
  form.value={usuario:'',nombre:'',responsable:'',rol:'usuario',password:'',instancias:[]}
  editId.value=null; showForm.value=true
}
function openEdit(u) {
  form.value={usuario:u.usuario,nombre:u.nombre,responsable:u.responsable||'',rol:u.rol,password:'',instancias:[]}
  editId.value=u.id; showForm.value=true
}
async function saveUser() {
  try {
    editId.value ? await api.put(`/users/${editId.value}`,form.value) : await api.post('/users',form.value)
    showForm.value=false; editId.value=null; loadUsers()
  } catch(e){alert(e.response?.data?.error||'Error al guardar')}
}
async function deactivate(id) {
  if(!confirm('¿Desactivar usuario?')) return
  await api.delete(`/users/${id}`); loadUsers()
}
async function loadUsers() {
  const{data}=await api.get('/users'); users.value=data.users||[]
}

onMounted(async()=>{
  loadUsers()
  const{data}=await api.get('/campaigns/instances'); instances.value=data.instances||[]
})
</script>
