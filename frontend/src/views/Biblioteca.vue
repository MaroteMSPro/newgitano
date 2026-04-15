<template>
  <div class="biblioteca">
    <div class="page-header">
      <div><h2>📁 Biblioteca de Archivos</h2><p>Archivos compartidos accesibles desde el CRM</p></div>
      <button @click="openCatModal()" class="btn-primary">+ Nueva Categoría</button>
    </div>

    <div class="bib-layout">
      <!-- Sidebar categorías -->
      <div class="bib-sidebar">
        <div v-for="cat in cats" :key="cat.id"
             :class="['cat-item', { active: activeCat?.id === cat.id }]"
             @click="selectCat(cat)">
          <span class="cat-icon">{{ cat.icono || '📁' }}</span>
          <div class="cat-info">
            <strong>{{ cat.nombre }}</strong>
            <small>{{ cat.archivos_count }} archivos</small>
          </div>
          <div class="cat-actions" @click.stop>
            <button @click="openCatModal(cat)" title="Editar">✏️</button>
            <button @click="deleteCat(cat.id)" title="Eliminar">🗑️</button>
          </div>
        </div>
        <div v-if="!cats.length" class="empty-cats">Sin categorías</div>
      </div>

      <!-- Archivos -->
      <div class="bib-files">
        <template v-if="activeCat">
          <div class="files-header">
            <h3>{{ activeCat.icono || '📁' }} {{ activeCat.nombre }}</h3>
            <button @click="showUpload=true" class="btn-upload">📤 Subir archivo</button>
          </div>

          <!-- Formulario upload -->
          <div v-if="showUpload" class="upload-panel">
            <div class="form-row">
              <div class="form-group">
                <label>Nombre</label>
                <input v-model="uploadForm.nombre" placeholder="Nombre del archivo" />
              </div>
              <div class="form-group">
                <label>Tipo</label>
                <select v-model="uploadForm.tipo" class="form-select">
                  <option value="document">📄 Documento</option>
                  <option value="image">🖼️ Imagen</option>
                  <option value="video">🎬 Video</option>
                  <option value="audio">🎵 Audio</option>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label>Archivo</label>
              <input type="file" @change="onFileSelect" ref="fileInput" class="file-input" />
            </div>
            <div class="form-group">
              <label>Descripción (opcional)</label>
              <input v-model="uploadForm.descripcion" placeholder="Descripción breve..." />
            </div>
            <div class="upload-actions">
              <button @click="showUpload=false" class="btn-secondary">Cancelar</button>
              <button @click="uploadFile" class="btn-primary"
                      :disabled="uploading || !uploadForm.nombre || !selectedFile">
                {{ uploading ? 'Subiendo...' : 'Subir archivo' }}
              </button>
            </div>
          </div>

          <!-- Grid de archivos -->
          <div class="files-grid">
            <div v-for="f in files" :key="f.id" class="file-card">
              <div class="file-icon-big">{{ fileIcon(f.tipo) }}</div>
              <div class="file-details">
                <strong>{{ f.nombre }}</strong>
                <small>{{ f.archivo_nombre }}</small>
                <small v-if="f.descripcion" class="file-desc">{{ f.descripcion }}</small>
              </div>
              <div class="file-actions">
                <a :href="fileUrl(f)" target="_blank" class="btn-file">👁️</a>
                <button @click="deleteFile(f.id)" class="btn-file red">🗑️</button>
              </div>
            </div>
            <div v-if="!files.length && !showUpload" class="empty-files">
              No hay archivos en esta categoría
            </div>
          </div>
        </template>
        <div v-else class="empty-select">← Seleccioná una categoría</div>
      </div>
    </div>

    <!-- Modal categoría -->
    <div v-if="showCatModal" class="modal-overlay" @click.self="showCatModal=false">
      <div class="modal">
        <div class="modal-header">
          <h3>{{ editCat ? 'Editar Categoría' : 'Nueva Categoría' }}</h3>
          <button @click="showCatModal=false" class="modal-close">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Nombre *</label>
            <input v-model="catForm.nombre" placeholder="Ej: LUXOM, EPUYEN, Presupuestos" />
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Ícono</label>
              <input v-model="catForm.icono" placeholder="📁" maxlength="4" />
            </div>
            <div class="form-group">
              <label>Instancia</label>
              <select v-model="catForm.instancia_id" class="form-select">
                <option v-for="inst in instances" :key="inst.id" :value="inst.id">{{ inst.nombre }}</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button @click="showCatModal=false" class="btn-secondary">Cancelar</button>
          <button @click="saveCat" class="btn-primary" :disabled="!catForm.nombre">
            {{ editCat ? 'Guardar' : 'Crear' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const cats       = ref([])
const files      = ref([])
const instances  = ref([])
const activeCat  = ref(null)
const showUpload = ref(false)
const uploading  = ref(false)
const selectedFile = ref(null)
const fileInput  = ref(null)
const showCatModal = ref(false)
const editCat    = ref(null)

const uploadForm = ref({ nombre:'', tipo:'document', descripcion:'' })
const catForm    = ref({ nombre:'', icono:'📁', instancia_id:1 })

const fileIcon = t => ({ image:'🖼️', audio:'🎵', video:'🎬' }[t] || '📄')
const fileUrl  = f => `https://crm.luxom.com.ar/${f.archivo_path}`

function onFileSelect(e) {
  selectedFile.value = e.target.files[0]
  if (selectedFile.value && !uploadForm.value.nombre)
    uploadForm.value.nombre = selectedFile.value.name.replace(/\.[^.]+$/, '')
}

async function selectCat(cat) {
  activeCat.value = cat; showUpload.value = false
  const { data } = await api.get('/biblioteca/files', { params: { categoria_id: cat.id } })
  files.value = data.files || []
}

function openCatModal(cat = null) {
  editCat.value = cat
  catForm.value = cat
    ? { nombre: cat.nombre, icono: cat.icono||'📁', instancia_id: cat.instancia_id }
    : { nombre:'', icono:'📁', instancia_id: instances.value[0]?.id || 1 }
  showCatModal.value = true
}

async function saveCat() {
  editCat.value
    ? await api.put(`/biblioteca/categories/${editCat.value.id}`, catForm.value)
    : await api.post('/biblioteca/categories', catForm.value)
  showCatModal.value = false; loadCats()
}

async function deleteCat(id) {
  if (!confirm('¿Eliminar categoría y todos sus archivos?')) return
  await api.delete(`/biblioteca/categories/${id}`)
  if (activeCat.value?.id === id) { activeCat.value=null; files.value=[] }
  loadCats()
}

async function uploadFile() {
  if (!selectedFile.value || !uploadForm.value.nombre) return
  uploading.value = true
  try {
    const fd = new FormData()
    fd.append('archivo', selectedFile.value)
    fd.append('nombre', uploadForm.value.nombre)
    fd.append('tipo', uploadForm.value.tipo)
    fd.append('descripcion', uploadForm.value.descripcion)
    fd.append('categoria_id', activeCat.value.id)
    fd.append('instancia_id', activeCat.value.instancia_id)
    await api.post('/biblioteca/files', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
    uploadForm.value = { nombre:'', tipo:'document', descripcion:'' }
    selectedFile.value = null; showUpload.value = false
    selectCat(activeCat.value); loadCats()
  } catch (e) { alert(e.response?.data?.error || 'Error al subir') }
  finally { uploading.value = false }
}

async function deleteFile(id) {
  if (!confirm('¿Eliminar archivo?')) return
  await api.delete(`/biblioteca/files/${id}`)
  selectCat(activeCat.value); loadCats()
}

async function loadCats() {
  const { data } = await api.get('/biblioteca/categories')
  cats.value = data.categories || []
}

onMounted(async () => {
  loadCats()
  const { data } = await api.get('/instances')
  instances.value = data.instances || []
})
</script>
