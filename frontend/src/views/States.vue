<template>
  <div class="states">
    <div class="page-header">
      <div><h2>⭕ Estados / Stories</h2><p>Estados programados de WhatsApp</p></div>
      <button class="btn-new" @click="openModal">+ Nuevo Estado</button>
    </div>

    <div class="tabs">
      <button :class="['tab',{active:tab==='calendario'}]" @click="tab='calendario'">📅 Calendario</button>
      <button :class="['tab',{active:tab==='lista'}]" @click="tab='lista'">
        📋 Lista <span v-if="pendienteCount" class="tab-badge">{{ pendienteCount }}</span>
      </button>
    </div>

    <!-- Calendario -->
    <div v-if="tab==='calendario'" class="calendar-wrap">
      <div class="month-nav">
        <button class="nav-btn" @click="prevMonth">‹</button>
        <span class="month-title">{{ monthLabel }}</span>
        <button class="nav-btn" @click="nextMonth">›</button>
      </div>
      <div class="cal-grid">
        <div class="cal-dow" v-for="d in ['Lu','Ma','Mi','Ju','Vi','Sá','Do']" :key="d">{{ d }}</div>
        <div class="cal-cell empty" v-for="n in firstDayOffset" :key="'e'+n"></div>
        <div v-for="day in daysInMonth" :key="day"
             :class="['cal-cell','cal-day',{
               'has-events': eventsOnDay(day)>0,
               'today': isToday(day),
               'selected': selectedDay===day
             }]"
             @click="selectDay(day)">
          <span class="day-num">{{ day }}</span>
          <span v-if="eventsOnDay(day)>0" class="day-badge">{{ eventsOnDay(day) }}</span>
        </div>
      </div>
      <div v-if="selectedDay!==null" class="day-panel">
        <div class="day-panel-hdr">
          <h4>{{ selectedDay }}/{{ month+1 }}/{{ year }}</h4>
          <button @click="selectedDay=null" class="close-btn">✕</button>
        </div>
        <div v-if="!dayEvents.length" class="empty-panel">Sin estados para este día</div>
        <div v-else class="day-events">
          <div v-for="ev in dayEvents" :key="ev.id" class="day-ev-item">
            <div class="ev-row">
              <span class="ev-inst">{{ ev.instancia_nombre }}</span>
              <span class="ev-time">{{ fmtTime(ev.fecha_hora) }}</span>
              <span :class="['badge',badgeClass(ev.estado)]">{{ ev.estado }}</span>
            </div>
            <div class="ev-tipo">{{ ev.tipo==='text'?'📝 Texto':'🖼️ Imagen' }}</div>
            <div class="ev-content">{{ truncate(ev.contenido,50) }}</div>
            <div v-if="isAdmin" class="ev-user">👤 {{ ev.usuario_nombre }}</div>
            <div class="ev-actions">
              <button v-if="ev.estado==='pendiente'" @click="cancelScheduled(ev.id)" class="btn-xs btn-cancel">Cancelar</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Lista -->
    <div v-if="tab==='lista'" class="list-wrap">
      <div class="list-filters">
        <select v-model="fEstado" class="flt-select">
          <option value="">Todos los estados</option>
          <option value="pendiente">Pendiente</option>
          <option value="publicado">Publicado</option>
          <option value="error">Error</option>
          <option value="cancelado">Cancelado</option>
        </select>
        <select v-model="fInstancia" class="flt-select">
          <option value="">Todas las instancias</option>
          <option v-for="i in instances" :key="i.id" :value="String(i.id)">
            {{ i.descripcion || i.nombre }}
          </option>
        </select>
        <input type="date" v-model="fDesde" class="flt-input" placeholder="Desde" />
        <input type="date" v-model="fHasta" class="flt-input" placeholder="Hasta" />
        <button @click="clearFilters" class="btn-sm">Limpiar</button>
      </div>
      <div v-if="loading" class="loading">Cargando...</div>
      <div v-else-if="!filteredScheduled.length" class="empty">No hay estados programados</div>
      <div v-else class="table-container">
        <table>
          <thead>
            <tr>
              <th>Instancia</th><th>Tipo</th><th>Contenido</th>
              <th>Fecha/Hora</th><th>Estado</th>
              <th v-if="isAdmin">Usuario</th><th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="ev in filteredScheduled" :key="ev.id">
              <td>{{ ev.instancia_nombre }}</td>
              <td><span class="tipo-badge">{{ ev.tipo==='text'?'📝 Texto':'🖼️ Imagen' }}</span></td>
              <td>{{ truncate(ev.contenido,50) }}</td>
              <td>{{ fmtFull(ev.fecha_hora) }}</td>
              <td><span :class="['badge',badgeClass(ev.estado)]">{{ ev.estado }}</span></td>
              <td v-if="isAdmin">{{ ev.usuario_nombre }}</td>
              <td>
                <button v-if="ev.estado==='pendiente'" @click="cancelScheduled(ev.id)" class="btn-xs btn-cancel">Cancelar</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal nuevo estado -->
    <div v-if="showModal" class="modal-overlay" @click.self="showModal=false">
      <div class="modal">
        <div class="modal-hdr">
          <h3>📅 Nuevo Estado Programado</h3>
          <button @click="showModal=false" class="close-btn">✕</button>
        </div>
        <div class="modal-body">
          <!-- Instancias -->
          <div class="form-group">
            <label>Instancia(s)</label>
            <div v-if="isAdmin" class="all-check">
              <label class="check-label">
                <input type="checkbox" v-model="mForm.todasInstancias" @change="onAllInstancias" />
                <span>Enviar a todas las instancias ({{ instances.length }})</span>
              </label>
            </div>
            <div v-if="mForm.todasInstancias" class="all-selected-info">
              ✅ Todas las instancias seleccionadas ({{ instances.length }})
            </div>
            <div v-else class="instance-grid">
              <label v-for="i in instances" :key="i.id" class="inst-check">
                <input type="checkbox" :value="i.id" v-model="mForm.instancias_ids" />
                <span class="inst-label">
                  {{ i.descripcion || i.nombre }}
                  <small v-if="i.numero"> (+{{ i.numero }})</small>
                </span>
              </label>
            </div>
            <div class="inst-footer">
              <button v-if="!mForm.todasInstancias && isAdmin"
                      @click="mForm.instancias_ids = instances.map(i=>i.id)"
                      class="btn-sm">Seleccionar todas</button>
              <span v-if="!mForm.todasInstancias && mForm.instancias_ids.length" class="inst-count">
                {{ mForm.instancias_ids.length }} seleccionada(s)
              </span>
            </div>
          </div>

          <!-- Tipo -->
          <div class="form-group">
            <label>Tipo de estado</label>
            <div class="tipo-row">
              <label><input type="radio" v-model="mForm.tipo" value="text" /> 📝 Texto</label>
              <label><input type="radio" v-model="mForm.tipo" value="image" /> 🖼️ Imagen</label>
            </div>
          </div>

          <!-- Contenido texto -->
          <div v-if="mForm.tipo==='text'" class="form-group">
            <label>Texto del estado</label>
            <textarea v-model="mForm.contenido" rows="3" placeholder="Escribí el texto..."></textarea>
          </div>

          <!-- Contenido imagen -->
          <div v-if="mForm.tipo==='image'" class="form-group">
            <label>Imagen</label>
            <input type="file" @change="onImgFile" accept="image/*" />
            <small>— o pegá una URL:</small>
            <input v-model="mForm.imgUrl" type="text" placeholder="https://..." @blur="onImgUrl" />
            <div v-if="mForm.imgPreview" class="img-preview">
              <img :src="mForm.imgPreview" style="max-width:200px;max-height:150px" />
            </div>
            <div class="form-group">
              <label>Caption (opcional)</label>
              <input v-model="mForm.caption" type="text" placeholder="Texto debajo de la imagen..." />
            </div>
          </div>

          <!-- Fecha/hora -->
          <div class="form-group">
            <label>Fecha y hora de publicación</label>
            <input type="datetime-local" v-model="mForm.fecha_hora" :min="minDate" class="modal-input" />
          </div>
        </div>
        <div class="modal-footer">
          <button @click="showModal=false" class="btn-secondary">Cancelar</button>
          <button @click="saveScheduled" class="btn-primary" :disabled="modalSaving">
            {{ modalSaving ? 'Programando...' : '📅 Programar' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import store from '../store'
import api from '../api'

const tab        = ref('calendario')
const instances  = ref([])
const scheduled  = ref([])
const loading    = ref(false)
const showModal  = ref(false)
const modalSaving = ref(false)
const isAdmin    = computed(() => store.user?.rol === 'admin')

// Calendario
const today    = new Date()
const year     = ref(today.getFullYear())
const month    = ref(today.getMonth())
const selectedDay = ref(null)

const MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre']
const monthLabel     = computed(() => `${MONTHS[month.value]} ${year.value}`)
const daysInMonth    = computed(() => new Date(year.value, month.value+1, 0).getDate())
const firstDayOffset = computed(() => { const d=new Date(year.value,month.value,1).getDay(); return d===0?6:d-1 })

const dayEvents = computed(() =>
  selectedDay.value === null ? [] :
  scheduled.value.filter(ev => {
    const d = new Date(ev.fecha_hora)
    return d.getFullYear()===year.value && d.getMonth()===month.value && d.getDate()===selectedDay.value
  }).sort((a,b)=>new Date(a.fecha_hora)-new Date(b.fecha_hora))
)

const pendienteCount = computed(() => scheduled.value.filter(e=>e.estado==='pendiente').length)

function eventsOnDay(d) {
  return scheduled.value.filter(ev => {
    const dd=new Date(ev.fecha_hora)
    return dd.getFullYear()===year.value && dd.getMonth()===month.value && dd.getDate()===d
  }).length
}
function isToday(d) {
  return today.getFullYear()===year.value && today.getMonth()===month.value && today.getDate()===d
}
function selectDay(d) { selectedDay.value = selectedDay.value===d ? null : d }

function prevMonth() { if(month.value===0){month.value=11;year.value--}else month.value--; selectedDay.value=null; loadScheduled() }
function nextMonth() { if(month.value===11){month.value=0;year.value++}else month.value++; selectedDay.value=null; loadScheduled() }

// Filtros lista
const fEstado   = ref(''), fInstancia = ref(''), fDesde = ref(''), fHasta = ref('')
const filteredScheduled = computed(() =>
  scheduled.value.filter(ev =>
    (!fEstado.value || ev.estado===fEstado.value) &&
    (!fInstancia.value || String(ev.instancia_id)===fInstancia.value) &&
    (!fDesde.value || ev.fecha_hora >= fDesde.value) &&
    (!fHasta.value || ev.fecha_hora <= fHasta.value+' 23:59:59')
  )
)
function clearFilters() { fEstado.value=''; fInstancia.value=''; fDesde.value=''; fHasta.value='' }

// Modal form
function freshForm() {
  return { todasInstancias:false, instancias_ids:[], tipo:'text',
           contenido:'', caption:'', imgUrl:'', imgPreview:'', fecha_hora:'' }
}
const mForm = ref(freshForm())
const minDate = computed(() => {
  const d = new Date(Date.now()+61000)
  return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')
    +'T'+String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0')
})

function openModal() { mForm.value=freshForm(); mForm.value.fecha_hora=minDate.value; showModal.value=true }
function onAllInstancias() { if(mForm.value.todasInstancias) mForm.value.instancias_ids=[] }
function onImgFile(e) {
  const f=e.target.files[0]; if(!f) return
  const r=new FileReader(); r.onload=ev=>{mForm.value.contenido=ev.target.result;mForm.value.imgPreview=ev.target.result;mForm.value.imgUrl=''}; r.readAsDataURL(f)
}
function onImgUrl() { if(mForm.value.imgUrl){mForm.value.contenido=mForm.value.imgUrl;mForm.value.imgPreview=mForm.value.imgUrl} }

async function saveScheduled() {
  const f=mForm.value
  const ids = f.todasInstancias ? instances.value.map(i=>i.id) : f.instancias_ids
  if(!ids.length){alert('Seleccioná al menos una instancia');return}
  if(!f.contenido.trim()){alert('El contenido es requerido');return}
  if(!f.fecha_hora){alert('La fecha y hora son requeridas');return}
  modalSaving.value=true
  try {
    const {data}=await api.post('/states/schedule',{instancias_ids:ids,tipo:f.tipo,contenido:f.contenido,caption:f.caption||'',fecha_hora:f.fecha_hora})
    alert(`✅ Estado programado en ${data.total||ids.length} instancia(s)`)
    showModal.value=false; await loadScheduled()
  } catch(e){alert(e.response?.data?.error||'Error al programar')} finally{modalSaving.value=false}
}

async function cancelScheduled(id) {
  if(!confirm('¿Cancelar este estado programado?')) return
  try{await api.delete(`/states/scheduled/${id}`);await loadScheduled()}
  catch(e){alert(e.response?.data?.error||'Error al cancelar')}
}

// Helpers
const badgeClass = e=>({pendiente:'badge-orange',publicado:'badge-green',error:'badge-red',cancelado:'badge-gray',fallido:'badge-red'}[e]||'badge-gray')
const fmtTime = d => d?new Date(d).toLocaleTimeString('es-AR',{hour:'2-digit',minute:'2-digit'}):''
const fmtFull = d => d?new Date(d).toLocaleDateString('es-AR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}):''
const truncate = (s,n) => s?(s.length>n?s.slice(0,n)+'...':s):''

async function loadScheduled() {
  loading.value=true
  try {
    const mes=`${year.value}-${String(month.value+1).padStart(2,'0')}`
    const {data}=await api.get('/states/scheduled',{params:{mes}})
    scheduled.value=data.scheduled||[]
  } catch(e){console.error(e)} finally{loading.value=false}
}

onMounted(async()=>{
  try{const{data}=await api.get('/campaigns/instances');instances.value=data.instances||[]}catch{}
  await loadScheduled()
})
</script>
