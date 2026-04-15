<template>
  <div class="crm-view">
    <!-- ===================== PANEL IZQUIERDO: lista de chats ===================== -->
    <div class="crm-left">
      <div class="crm-header">
        <!-- Tabs -->
        <div class="crm-tabs">
          <button :class="['tab', { active: tab === 'activos' }]"
                  @click="tab = 'activos'; loadLeads()">
            <span class="tab-dot green"></span> Activos
            <span class="tab-count">{{ counts.activos || 0 }}</span>
          </button>
          <button :class="['tab', { active: tab === 'cerrados' }]"
                  @click="tab = 'cerrados'; loadLeads()">
            <span class="tab-dot red"></span> Cerrados
          </button>
          <button v-if="store.isAdmin"
                  :class="['tab', { active: tab === 'gestion' }]"
                  @click="tab = 'gestion'">
            ⚙️ Gestión
          </button>
        </div>

        <template v-if="tab !== 'gestion'">
          <!-- Filtros fila 1 -->
          <div class="filters-row">
            <select class="filter-select" v-model="filterEstado" @change="loadLeads()">
              <option value="">Todos activos</option>
              <option value="sin_asignar">👤 Sin asignar ({{ counts.sin_asignar }})</option>
              <option value="asignados">👥 Asignados ({{ counts.asignados }})</option>
              <option value="sin_responder">🔴 Sin responder ({{ counts.sin_responder }})</option>
              <option value="marcados">⭐ Marcados ({{ counts.marcados }})</option>
            </select>
            <select class="filter-select" v-model="filterEtiqueta" @change="loadLeads()">
              <option value="">Todas las etiquetas</option>
              <option v-for="tag in tags" :key="tag.id" :value="tag.id">{{ tag.nombre }}</option>
            </select>
          </div>

          <!-- Filtros fila 2 -->
          <div class="filters-row">
            <select v-if="store.isAdmin" class="filter-select"
                    v-model="filterUsuario" @change="loadLeads()">
              <option value="">Todos los usuarios</option>
              <option v-for="u in users" :key="u.id" :value="u.id">{{ u.nombre }}</option>
            </select>
            <div class="date-range">
              <input type="date" class="filter-date" v-model="filterDesde"
                     @change="loadLeads()" title="Desde" />
              <input type="date" class="filter-date" v-model="filterHasta"
                     @change="loadLeads()" title="Hasta" />
              <button v-if="filterDesde || filterHasta"
                      @click="filterDesde = ''; filterHasta = ''; loadLeads()"
                      class="clear-date" title="Limpiar fechas">✕</button>
            </div>
          </div>

          <!-- Barra info + switch online -->
          <div class="leads-info-bar">
            <span>{{ sortedLeads.length }} chats</span>
            <label class="online-switch" @click.prevent="toggleOnline">
              <span :class="['switch-track', { on: isOnline }]">
                <span class="switch-thumb"></span>
              </span>
              <span :class="['switch-label', isOnline ? 'label-on' : 'label-off']">
                {{ isOnline ? '🟢 Online' : '🔴 Offline' }}
              </span>
            </label>
          </div>

          <!-- Search -->
          <div class="search-bar">
            <input v-model="search" @input="debouncedLoad"
                   type="text" placeholder="🔍 Buscar nombre o número..." />
          </div>

          <!-- Lista de chats -->
          <div class="chat-list">
            <div v-for="lead in sortedLeads" :key="lead.id"
                 :class="['chat-item', { selected: activeLead?.id === lead.id, pinned: lead.marcado }]"
                 @click="openLead(lead)">
              <div class="chat-avatar"
                   :style="{ background: avatarColor(lead.nombre || lead.numero) }">
                {{ initials(lead.nombre || lead.numero) }}
              </div>
              <div class="chat-body">
                <div class="chat-top">
                  <!-- CAMBIO 2: doble-click en nombre → editar alias -->
                  <strong class="chat-name" @dblclick.stop="startRename(lead)">
                    <template v-if="renamingLead?.id === lead.id">
                      <input
                        class="rename-input"
                        v-model="renameValue"
                        @keyup.enter="saveAlias(lead)"
                        @keyup.escape="renamingLead = null"
                        @blur="saveAlias(lead)"
                        @click.stop
                        ref="renameInput"
                      />
                    </template>
                    <template v-else>{{ lead.nombre || lead.numero }}</template>
                  </strong>
                  <span class="chat-time">{{ fmtLeadTime(lead.ultimo_mensaje_at) }}</span>
                </div>
                <div class="chat-preview">
                  <span class="chat-last">{{ truncate(lead.ultimo_mensaje, 35) || 'Sin mensajes' }}</span>
                  <div class="chat-badges">
                    <span v-if="lead.marcado" class="pin-icon">📌</span>
                    <span v-if="lead.mensajes_sin_leer > 0" class="unread-badge">
                      {{ lead.mensajes_sin_leer }}
                    </span>
                  </div>
                </div>
                <div class="chat-meta">
                  <span v-if="lead.asignado_nombre" class="assigned-tag">👤 {{ lead.asignado_nombre }}</span>
                  <span v-if="lead.estado === 'nuevo'" class="new-badge">NUEVO</span>
                </div>
              </div>
              <!-- Quick actions -->
              <div class="chat-quick-actions" @click.stop>
                <button @click.stop="togglePin(lead)"
                        :class="['qa-btn', { active: lead.marcado }]" title="Fijar">📌</button>
                <button @click.stop="markUnread(lead)"
                        class="qa-btn" title="Marcar no leído">📩</button>
              </div>
            </div>

            <div v-if="!sortedLeads.length && !leadsLoading" class="no-chats">No hay chats</div>
            <div v-if="leadsLoading" class="loading-chats">Cargando...</div>
          </div>
        </template>
      </div>
    </div>

    <!-- ===================== PANEL DERECHO: chat activo ===================== -->
    <div v-show="tab !== 'gestion'" class="crm-right">
      <!-- Sin chat seleccionado -->
      <div v-if="!activeLead" class="no-chat-placeholder">
        <div class="no-chat-icon">💬</div>
        <p>Seleccioná un chat para empezar</p>
        <small>{{ sortedLeads.length }} conversaciones activas</small>
      </div>

      <!-- Chat activo -->
      <template v-else>
        <!-- Header del chat -->
        <div class="chat-header">
          <div class="chat-avatar-lg"
               :style="{ background: avatarColor(activeLead.nombre || activeLead.numero) }">
            {{ initials(activeLead.nombre || activeLead.numero) }}
          </div>
          <div class="chat-header-info">
            <strong>{{ activeLead.nombre || activeLead.numero }}</strong>
            <small>{{ activeLead.numero }} · {{ activeLead.asignado_nombre || 'Sin asignar' }}</small>
          </div>
          <div class="chat-header-actions">
            <button class="header-btn green" @click="closeLead('cerrado_positivo')" title="Cerrar Positivo">✅</button>
            <button class="header-btn red"   @click="closeLead('cerrado_negativo')" title="Cerrar Negativo">❌</button>
            <button class="header-btn blue"  @click="showTransfer = !showTransfer"  title="Transferir">🔄</button>
            <span :class="['estado-badge', estadoClass(activeLead.estado)]">
              {{ estadoLabel(activeLead.estado) }}
            </span>
            <span class="header-sep">|</span>
            <button class="header-btn" @click="togglePinActive" :title="activeLead.marcado ? 'Desfijar' : 'Fijar'">
              {{ activeLead.marcado ? '⭐' : '☆' }}
            </button>
            <button class="header-btn" @click="markUnreadActive" title="Marcar no leído">📩</button>
            <button class="header-btn" @click="showBiblioteca = !showBiblioteca" title="Biblioteca">📁</button>
            <button class="header-btn" @click="openReminderModal" title="Crear Recordatorio">🔔</button>
          </div>
        </div>

        <!-- Panel de transferencia -->
        <div v-if="showTransfer" class="transfer-panel">
          <strong>Transferir a:</strong>
          <div class="transfer-users">
            <button v-for="u in onlineUsers" :key="u.id"
                    @click="transferLead(u.id)" class="transfer-user-btn">
              {{ u.nombre }} 🟢
            </button>
          </div>
          <div v-if="!onlineUsers.length" class="no-online">No hay usuarios online</div>
        </div>

        <!-- Etiquetas -->
        <div class="tags-bar">
          <label v-for="tag in tags" :key="tag.id"
                 class="tag-check"
                 :style="{ '--tag-color': tag.color }">
            <input type="checkbox"
                   :checked="activeTagIds.includes(tag.id)"
                   @change="toggleTag(tag.id)" />
            <span class="tag-label"
                  :style="{
                    background: activeTagIds.includes(tag.id) ? tag.color : 'transparent',
                    color: activeTagIds.includes(tag.id) ? 'white' : tag.color,
                    borderColor: tag.color
                  }">{{ tag.nombre }}</span>
          </label>
        </div>

        <!-- Mensajes -->
        <div class="chat-messages" ref="messagesContainer">
          <div v-if="msgsLoading" class="loading-msgs">Cargando mensajes...</div>
          <template v-else>
            <div v-for="msg in messages" :key="msg.id"
                 :class="['message', msg.direccion === 'saliente' ? 'sent' : 'received']">
              <div class="msg-bubble">
                <!-- Botón responder -->
                <button class="reply-btn" @click="replyTo = msg" title="Responder">↩</button>

                <!-- Enviado por -->
                <div v-if="msg.enviado_por" class="msg-sender">{{ msg.enviado_por }}</div>

                <!-- Cita/reply -->
                <div v-if="msg._replyTo || msg.quoted_content" class="msg-quoted">
                  <strong>{{ msg._replyTo?.nombre || msg.quoted_sender || '' }}</strong>
                  <span>{{ msg._replyTo?.contenido || msg.quoted_content || '' }}</span>
                </div>

                <!-- Recordatorio -->
                <div v-if="msg.es_recordatorio" class="msg-reminder-badge">🔔 Recordatorio programado</div>

                <!-- Contenido según tipo -->
                <!-- CAMBIO 4: fecha + hora en todos los mensajes -->
                <p v-if="!msg.tipo || msg.tipo === 'text'" class="msg-text">{{ msg.contenido }}</p>

                <div v-else-if="msg.tipo === 'image'" class="msg-media">
                  <img v-if="msg._mediaUrl" :src="msg._mediaUrl"
                       class="media-img" @click="openMedia(msg._mediaUrl)" />
                  <button v-else class="media-load-btn" @click="loadMedia(msg)">📷 Ver imagen</button>
                  <p v-if="msg.contenido && msg.contenido !== '[Imagen]'" class="media-caption">
                    {{ msg.contenido }}
                  </p>
                </div>

                <div v-else-if="msg.tipo === 'audio'" class="msg-audio">
                  <div v-if="msg._mediaUrl" class="audio-player">
                    <audio
                      :src="msg._mediaUrl"
                      :ref="el => { if (el) msg._audioEl = el }"
                      @timeupdate="msg._progress = (msg._audioEl?.currentTime / msg._audioEl?.duration * 100) || 0"
                      @ended="msg._playing = false"
                      @loadedmetadata="msg._duration = msg._audioEl?.duration || 0"
                    ></audio>
                    <button class="audio-play-btn" @click="toggleAudio(msg)">
                      {{ msg._playing ? '⏸' : '▶️' }}
                    </button>
                    <div class="audio-bar">
                      <div class="audio-progress" :style="{ width: (msg._progress || 0) + '%' }"></div>
                    </div>
                    <span class="audio-time">
                      {{ fmtDuration(msg._audioEl?.currentTime || 0) }} / {{ fmtDuration(msg._duration || 0) }}
                    </span>
                  </div>
                  <button v-else class="media-load-btn"
                          @click="loadMedia(msg)" :disabled="msg._loading">
                    {{ msg._loading ? '⏳ Cargando...' : '▶️ Reproducir audio' }}
                  </button>
                </div>

                <div v-else-if="msg.tipo === 'document'" class="msg-document">
                  <a v-if="msg._mediaUrl" :href="msg._mediaUrl"
                     download class="doc-link">
                    📄 {{ msg.contenido || 'Descargar' }}
                  </a>
                  <button v-else class="media-load-btn" @click="loadMedia(msg)" :disabled="msg._loading">
                    {{ msg._loading ? '⏳ Cargando...' : '📄 Ver documento' }}
                  </button>
                </div>

                <!-- CAMBIO 4: mostrar dd/mm HH:mm -->
                <div class="msg-time">{{ fmtMsgTime(msg.created_at) }}</div>
              </div>
            </div>
          </template>
        </div>

        <!-- Biblioteca lateral -->
        <div v-if="showBiblioteca" class="biblioteca-panel">
          <div class="biblio-header">
            <strong>📁 Biblioteca</strong>
            <button @click="showBiblioteca = false">✕</button>
          </div>
          <div class="biblio-cats">
            <button v-for="cat in biblioCats" :key="cat.id"
                    :class="['biblio-cat-btn', { active: biblioCatSel === cat.id }]"
                    @click="loadBiblioFiles(cat.id)">
              {{ cat.nombre }}
            </button>
          </div>
          <div class="biblio-files">
            <div v-for="f in biblioFiles" :key="f.id"
                 class="biblio-file" @click="sendBiblioFile(f)">
              <span class="biblio-file-icon">{{ fileIcon(f.tipo) }}</span>
              <div class="biblio-file-info">
                <strong>{{ f.nombre }}</strong>
                <small>{{ f.archivo_nombre }}</small>
              </div>
            </div>
            <div v-if="!biblioFiles.length" class="empty-biblio">No hay archivos</div>
          </div>
        </div>

        <!-- Drop overlay - CAMBIO 3: drag & drop -->
        <div v-if="isDragging" class="drop-overlay"
             @drop.prevent="onDrop"
             @dragover.prevent
             @dragleave="isDragging = false">
          <div class="drop-box">📎 Soltá el archivo acá</div>
        </div>

        <!-- Preview archivo adjunto -->
        <div v-if="attachFile" class="attach-preview">
          <div class="attach-info">
            <span class="attach-icon">{{ fileIcon(attachFile.type) }}</span>
            <div class="attach-meta">
              <strong>{{ attachFile.name }}</strong>
              <small>{{ fmtSize(attachFile.size) }}</small>
            </div>
            <button @click="attachFile = null" class="preview-x">✕</button>
          </div>
          <div class="attach-caption">
            <input v-model="attachCaption"
                   placeholder="Agregar un comentario..."
                   @keyup.enter="sendAttach" />
            <button @click="sendAttach" class="btn-send-file">➤ Enviar</button>
          </div>
        </div>

        <!-- Input area -->
        <div class="chat-input-area" @dragenter.prevent="isDragging = true">
          <!-- CAMBIO 5: grabador de audio con pausa/reanudar -->
          <div v-if="isRecording" class="recording-bar">
            <span class="rec-dot">●</span>
            <span class="rec-time">{{ fmtDuration(recSeconds) }}</span>
            <button class="rec-btn" @click="pauseResumeRecording"
                    :title="recPaused ? 'Reanudar' : 'Pausar'">
              {{ recPaused ? '▶️' : '⏸' }}
            </button>
            <button class="rec-btn rec-stop" @click="stopRecording" title="Enviar audio">✅</button>
            <button class="rec-btn rec-cancel" @click="cancelRecording" title="Cancelar">🗑️</button>
          </div>

          <!-- Atajos rápidos -->
          <div v-if="showShortcuts" class="shortcuts-panel">
            <div class="shortcuts-header">
              <strong>⚡ Atajos rápidos</strong>
              <button @click="showShortcuts = false">✕</button>
            </div>
            <div v-for="s in shortcuts" :key="s.id"
                 class="shortcut-item" @click="useShortcut(s)">
              <span class="shortcut-name">{{ s.nombre }}</span>
              <span v-if="s.atajo" class="shortcut-key">{{ s.atajo }}</span>
              <small>{{ truncate(s.contenido, 50) }}</small>
            </div>
          </div>

          <!-- Reply preview -->
          <div v-if="replyTo" class="reply-preview">
            <div class="reply-content">
              <strong>{{ replyTo.direccion === 'entrante' ? activeLead?.nombre : 'Tú' }}</strong>
              <span>{{ (replyTo.contenido || '').substring(0, 80) }}</span>
            </div>
            <button class="reply-cancel" @click="replyTo = null">✕</button>
          </div>

          <!-- Toolbar + input -->
          <div class="input-row">
            <button class="btn-icon" @click="showShortcuts = !showShortcuts" title="Atajos rápidos">⚡</button>
            <button class="btn-icon" @click="$refs.fileInputChat.click()" title="Adjuntar archivo">📎</button>
            <!-- CAMBIO 5: botón micrófono -->
            <button class="btn-icon" @click="startRecording" title="Grabar audio"
                    v-if="!isRecording">🎤</button>
            <input type="file" ref="fileInputChat" @change="onFileSelect" style="display:none" />
            <input
              v-model="msgText"
              @keyup.enter="sendMessage"
              @input="onMsgInput"
              type="text"
              placeholder="Escribí un mensaje... (/ para atajos)"
            />
            <button @click="sendMessage" class="btn-send" :disabled="!msgText.trim()">➤</button>
          </div>
        </div>
      </template>
    </div>

    <!-- Panel gestión (admin) -->
    <div v-if="tab === 'gestion'" class="crm-gestion">
      <GestionPanel />
    </div>

    <!-- Modal recordatorio -->
    <div v-if="showReminderModal" class="modal-overlay" @click.self="showReminderModal = false">
      <div class="modal-card">
        <div class="modal-header-row">
          <strong>🔔 Nuevo Recordatorio</strong>
          <button @click="showReminderModal = false" class="modal-close">✕</button>
        </div>
        <div class="modal-body">
          <p class="modal-lead">Lead: <strong>{{ activeLead?.nombre || activeLead?.numero }}</strong></p>
          <label>Fecha y hora</label>
          <input type="datetime-local" v-model="reminderDate"
                 :min="reminderMinDate" class="modal-input" />
          <label>Mensaje</label>
          <textarea v-model="reminderMsg" rows="4" class="modal-input"
                    placeholder="Escribí el mensaje a enviar..."></textarea>
          <p v-if="reminderError" class="error">{{ reminderError }}</p>
        </div>
        <div class="modal-actions">
          <button @click="showReminderModal = false" class="btn-cancel">Cancelar</button>
          <button @click="saveReminder" class="btn-confirm" :disabled="reminderSaving">
            {{ reminderSaving ? 'Guardando...' : 'Crear Recordatorio' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { defineAsyncComponent } from 'vue'
import store from '../store'
import api from '../api'

// GestionPanel carga lazy
const GestionPanel = defineAsyncComponent(() => import('./GestionPanel.vue'))

// ─── Estado principal ───────────────────────────────────────────────────────
const tab           = ref('activos')
const filterEstado  = ref('')
const filterUsuario = ref('')
const filterEtiqueta = ref('')
const filterDesde   = ref('')
const filterHasta   = ref('')
const search        = ref('')

const counts = ref({ activos: 0, sin_asignar: 0, asignados: 0, sin_responder: 0, marcados: 0 })
const leads  = ref([])
const users  = ref([])
const tags   = ref([])
const shortcuts = ref([])

const activeLead   = ref(null)
const activeTagIds = ref([])
const messages     = ref([])
const leadsLoading = ref(true)
const msgsLoading  = ref(false)
const isOnline     = ref(false)

// UI toggles
const showTransfer    = ref(false)
const showBiblioteca  = ref(false)
const showShortcuts   = ref(false)
const showReminderModal = ref(false)
const replyTo         = ref(null)
const msgText         = ref('')
const messagesContainer = ref(null)

// ─── Alias / rename (CAMBIO 2) ───────────────────────────────────────────────
const renamingLead = ref(null)
const renameValue  = ref('')
const renameInput  = ref(null)

function startRename(lead) {
  renamingLead.value = lead
  renameValue.value  = lead.nombre || lead.numero
  nextTick(() => renameInput.value?.focus?.())
}
async function saveAlias(lead) {
  if (!renamingLead.value) return
  const alias = renameValue.value.trim()
  if (alias && alias !== lead.numero) {
    try {
      await api.put(`/crm/leads/${lead.id}/alias`, { alias })
      lead.nombre = alias
      if (activeLead.value?.id === lead.id) activeLead.value.nombre = alias
    } catch (e) {
      console.error('Error guardando alias:', e)
    }
  }
  renamingLead.value = null
}

// ─── Drag & drop (CAMBIO 3) ──────────────────────────────────────────────────
const isDragging  = ref(false)
const attachFile  = ref(null)
const attachCaption = ref('')

function onDrop(e) {
  isDragging.value = false
  const file = e.dataTransfer.files[0]
  if (file) { attachFile.value = file; attachCaption.value = '' }
}
function onFileSelect(e) {
  const file = e.target.files[0]
  if (file) { attachFile.value = file; attachCaption.value = '' }
}
async function sendAttach() {
  if (!attachFile.value || !activeLead.value) return
  const fd = new FormData()
  fd.append('archivo', attachFile.value)
  fd.append('lead_id', activeLead.value.id)
  fd.append('caption', attachCaption.value)
  try {
    await api.post(`/crm/leads/${activeLead.value.id}/attach`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })
    messages.value.push({
      id: Date.now(),
      contenido: attachCaption.value || `📎 ${attachFile.value.name}`,
      direccion: 'saliente',
      tipo: attachFile.value.type.startsWith('image') ? 'image' : 'document',
      created_at: new Date().toISOString()
    })
    attachFile.value   = null
    attachCaption.value = ''
    await nextTick()
    scrollToBottom()
  } catch (e) {
    alert(e.response?.data?.error || 'Error al enviar archivo')
  }
}

// ─── Grabación de audio (CAMBIO 5) ───────────────────────────────────────────
const isRecording  = ref(false)
const recPaused    = ref(false)
const recSeconds   = ref(0)
let mediaRecorder  = null
let audioChunks    = []
let recTimer       = null

async function startRecording() {
  if (!activeLead.value) return
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    mediaRecorder = new MediaRecorder(stream)
    audioChunks   = []
    mediaRecorder.ondataavailable = e => audioChunks.push(e.data)
    mediaRecorder.start()
    isRecording.value = true
    recPaused.value   = false
    recSeconds.value  = 0
    recTimer = setInterval(() => { if (!recPaused.value) recSeconds.value++ }, 1000)
  } catch {
    alert('No se pudo acceder al micrófono')
  }
}

function pauseResumeRecording() {
  if (!mediaRecorder) return
  if (recPaused.value) {
    mediaRecorder.resume()
    recPaused.value = false
  } else {
    mediaRecorder.pause()
    recPaused.value = true
  }
}

function stopRecording() {
  if (!mediaRecorder) return
  mediaRecorder.onstop = async () => {
    const blob = new Blob(audioChunks, { type: 'audio/ogg; codecs=opus' })
    const file = new File([blob], `audio_${Date.now()}.ogg`, { type: 'audio/ogg' })
    const fd   = new FormData()
    fd.append('archivo', file)
    fd.append('lead_id', activeLead.value.id)
    fd.append('caption', '')
    try {
      await api.post(`/crm/leads/${activeLead.value.id}/attach`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' }
      })
      messages.value.push({
        id: Date.now(), contenido: '🎤 Audio',
        direccion: 'saliente', tipo: 'audio',
        created_at: new Date().toISOString()
      })
      await nextTick(); scrollToBottom()
    } catch (e) {
      alert(e.response?.data?.error || 'Error al enviar audio')
    }
    mediaRecorder.stream.getTracks().forEach(t => t.stop())
  }
  mediaRecorder.stop()
  clearInterval(recTimer)
  isRecording.value = false
  recPaused.value   = false
}

function cancelRecording() {
  if (mediaRecorder) {
    mediaRecorder.stream.getTracks().forEach(t => t.stop())
    mediaRecorder = null
  }
  clearInterval(recTimer)
  isRecording.value = false
  recPaused.value   = false
  audioChunks       = []
}

// ─── Formateo de tiempo (CAMBIO 4: dd/mm HH:mm) ──────────────────────────────
function fmtMsgTime(dt) {
  if (!dt) return ''
  return new Date(dt).toLocaleString('es-AR', {
    day: '2-digit', month: '2-digit',
    hour: '2-digit', minute: '2-digit'
  })
}
function fmtLeadTime(dt) {
  if (!dt) return ''
  const d = new Date(dt), now = new Date()
  return d.toDateString() === now.toDateString()
    ? d.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })
    : d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit' })
}
function fmtDuration(s) {
  if (!s || isNaN(s)) return '0:00'
  const m = Math.floor(s / 60), sec = Math.floor(s % 60)
  return m + ':' + String(sec).padStart(2, '0')
}
function fmtSize(b) {
  return b < 1024 ? b + ' B' : b < 1048576 ? (b / 1024).toFixed(1) + ' KB' : (b / 1048576).toFixed(1) + ' MB'
}

// ─── Helpers visuales ────────────────────────────────────────────────────────
const AVATAR_COLORS = ['#25D366','#128C7E','#075E54','#34B7F1','#00A884','#4FC3F7',
                       '#7986CB','#E57373','#FF8A65','#FFD54F']
function avatarColor(name) {
  if (!name) return AVATAR_COLORS[0]
  let h = 0
  for (let i = 0; i < name.length; i++) h = name.charCodeAt(i) + ((h << 5) - h)
  return AVATAR_COLORS[Math.abs(h) % AVATAR_COLORS.length]
}
function initials(name) {
  return name ? name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase() : '?'
}
function truncate(s, n) {
  return s ? (s.length > n ? s.substring(0, n) + '...' : s) : ''
}
function estadoClass(e) {
  return { nuevo: 'nuevo', asignado: 'asignado',
           cerrado_positivo: 'cerrado-pos', cerrado_negativo: 'cerrado-neg' }[e] || ''
}
function estadoLabel(e) {
  return { nuevo: 'Nuevo', asignado: 'Asignado',
           cerrado_positivo: 'Cerrado ✅', cerrado_negativo: 'Cerrado ❌' }[e] || e
}
function fileIcon(type) {
  if (!type) return '📄'
  if (type.startsWith('image/')) return '🖼️'
  if (type.startsWith('video/')) return '🎬'
  if (type.startsWith('audio/')) return '🎵'
  if (type.includes('pdf'))      return '📕'
  return '📄'
}

// ─── Leads ───────────────────────────────────────────────────────────────────
const sortedLeads = computed(() =>
  [...leads.value].sort((a, b) => (a.marcado && !b.marcado ? -1 : !a.marcado && b.marcado ? 1 : 0))
)
const onlineUsers = computed(() => users.value.filter(u => u.crm_online))

let debounceTimer = null
function debouncedLoad() {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(loadLeads, 300)
}

async function loadLeads() {
  leadsLoading.value = true
  try {
    const params = { estado: tab.value, limit: 50 }
    if (search.value)        params.search       = search.value
    if (filterEstado.value)  params.estado        = filterEstado.value
    if (filterUsuario.value) params.usuario       = filterUsuario.value
    if (filterEtiqueta.value)params.etiqueta      = filterEtiqueta.value
    if (filterDesde.value)   params.fecha_desde   = filterDesde.value
    if (filterHasta.value)   params.fecha_hasta   = filterHasta.value
    if (store.selectedInstance) params.instancia_id = store.selectedInstance

    const { data } = await api.get('/crm/leads', { params })
    leads.value  = data.leads  || []
    if (data.counts) counts.value = data.counts
  } catch (e) {
    console.error(e)
  } finally {
    leadsLoading.value = false
  }
}

async function openLead(lead) {
  activeLead.value   = lead
  showTransfer.value = false
  showBiblioteca.value = false
  msgsLoading.value  = true
  try {
    const [m, t] = await Promise.all([
      api.get(`/crm/leads/${lead.id}/messages`),
      api.get(`/crm/leads/${lead.id}/tags`)
    ])
    messages.value   = m.data.messages || []
    activeTagIds.value = (t.data.tags || []).map(x => x.id)
    if (m.data.lead) activeLead.value = { ...lead, ...m.data.lead }
    await nextTick(); scrollToBottom()
    // Marcar como leído si no es admin
    if (lead.mensajes_sin_leer > 0 && !store.isAdmin) {
      api.post(`/crm/leads/${lead.id}/read`).catch(() => {})
      lead.mensajes_sin_leer = 0
    }
  } catch {
    messages.value = []; activeTagIds.value = []
  } finally {
    msgsLoading.value = false
  }
}

function scrollToBottom() {
  if (messagesContainer.value)
    messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight
}

// ─── Etiquetas (CAMBIO 1: cualquier user puede crear) ─────────────────────────
// La creación se hace en ConfigCRM. Aquí solo toggle en lead activo.
async function toggleTag(tagId) {
  if (!activeLead.value) return
  try {
    await api.post(`/crm/leads/${activeLead.value.id}/tags`, { tag_id: tagId })
    const idx = activeTagIds.value.indexOf(tagId)
    idx >= 0 ? activeTagIds.value.splice(idx, 1) : activeTagIds.value.push(tagId)
  } catch (e) { console.error(e) }
}

// ─── Acciones sobre lead ─────────────────────────────────────────────────────
async function closeLead(estado) {
  if (!activeLead.value || !confirm(`¿Cerrar como ${estado === 'cerrado_positivo' ? 'positivo' : 'negativo'}?`)) return
  await api.post(`/crm/leads/${activeLead.value.id}/status`, { estado })
  activeLead.value.estado = estado
  loadLeads()
}

async function transferLead(usuarioId) {
  if (!activeLead.value) return
  try {
    const { data } = await api.post(`/crm/leads/${activeLead.value.id}/transfer`, { usuario_id: usuarioId })
    activeLead.value.asignado_nombre = data.asignado_a
    showTransfer.value = false
    loadLeads()
  } catch (e) { alert(e.response?.data?.error || 'Error') }
}

async function togglePin(lead) {
  const { data } = await api.post(`/crm/leads/${lead.id}/pin`)
  lead.marcado = data.marcado
  if (activeLead.value?.id === lead.id) activeLead.value.marcado = data.marcado
}

async function togglePinActive() {
  if (!activeLead.value) return
  const { data } = await api.post(`/crm/leads/${activeLead.value.id}/pin`)
  activeLead.value.marcado = data.marcado
  const l = leads.value.find(x => x.id === activeLead.value.id)
  if (l) l.marcado = data.marcado
}

async function markUnread(lead) {
  await api.post(`/crm/leads/${lead.id}/unread`)
  lead.mensajes_sin_leer = Math.max(lead.mensajes_sin_leer || 0, 1)
}

async function markUnreadActive() {
  if (!activeLead.value) return
  await api.post(`/crm/leads/${activeLead.value.id}/unread`)
  const l = leads.value.find(x => x.id === activeLead.value.id)
  if (l) l.mensajes_sin_leer = Math.max(l.mensajes_sin_leer || 0, 1)
  activeLead.value = null
}

// ─── Toggle online ───────────────────────────────────────────────────────────
async function toggleOnline() {
  try {
    const { data } = await api.post('/crm/toggle-online')
    isOnline.value = data.crm_online
  } catch (e) { console.error(e) }
}

// ─── Enviar mensaje ──────────────────────────────────────────────────────────
function onMsgInput() {
  // Auto-expandir atajos con /
  if (msgText.value.startsWith('/')) {
    const key  = msgText.value.toLowerCase()
    const sc   = shortcuts.value.find(s => s.atajo?.toLowerCase() === key)
    if (sc) { msgText.value = sc.contenido || '' }
  }
}

async function sendMessage() {
  if (!msgText.value.trim() || !activeLead.value) return
  const text = msgText.value
  const body = { lead_id: activeLead.value.id, mensaje: text }
  if (replyTo.value?.mensaje_id_wa) body.quoted_msg_id = replyTo.value.mensaje_id_wa

  messages.value.push({
    id: Date.now(), contenido: text, direccion: 'saliente', tipo: 'text',
    created_at: new Date().toISOString(),
    _replyTo: replyTo.value
      ? { nombre: replyTo.value.direccion === 'entrante' ? activeLead.value.nombre : 'Tú',
          contenido: replyTo.value.contenido }
      : null
  })
  msgText.value = ''; replyTo.value = null
  await nextTick(); scrollToBottom()

  try { await api.post('/messages/send', body) }
  catch (e) { console.error('Error enviando mensaje:', e) }
}

// ─── Shortcuts ───────────────────────────────────────────────────────────────
function useShortcut(s) {
  msgText.value   = s.contenido || ''
  showShortcuts.value = false
}

// ─── Media ───────────────────────────────────────────────────────────────────
async function loadMedia(msg) {
  msg._loading = true
  try {
    const { data } = await api.get('/crm/media', { params: { msg_id: msg.id } })
    if (data.base64 && data.mimetype)
      msg._mediaUrl = `data:${data.mimetype};base64,${data.base64}`
  } catch { alert('No se pudo cargar el archivo') }
  finally { msg._loading = false }
}
function openMedia(url) { window.open(url, '_blank') }

function toggleAudio(msg) {
  if (!msg._audioEl) return
  msg._playing ? (msg._audioEl.pause(), msg._playing = false)
               : (msg._audioEl.play(),  msg._playing = true)
}

// ─── Biblioteca ──────────────────────────────────────────────────────────────
const biblioCats   = ref([])
const biblioFiles  = ref([])
const biblioCatSel = ref(null)

async function loadBiblioFiles(catId) {
  biblioCatSel.value = catId
  const { data } = await api.get('/biblioteca/files', { params: { categoria_id: catId } })
  biblioFiles.value = data.files || []
}

function sendBiblioFile(f) {
  messages.value.push({
    id: Date.now(),
    contenido: `📄 ${f.nombre}\n${f.archivo_nombre}`,
    direccion: 'saliente', tipo: 'document',
    created_at: new Date().toISOString()
  })
  nextTick(scrollToBottom)
}

// ─── Recordatorio ────────────────────────────────────────────────────────────
const reminderDate  = ref('')
const reminderMsg   = ref('')
const reminderError = ref('')
const reminderSaving = ref(false)
const reminderMinDate = computed(() => new Date(Date.now() + 120000).toISOString().slice(0, 16))

function openReminderModal() {
  if (!activeLead.value) return
  reminderDate.value  = reminderMinDate.value
  reminderMsg.value   = ''
  reminderError.value = ''
  showReminderModal.value = true
}

async function saveReminder() {
  reminderError.value = ''
  if (!reminderDate.value || !reminderMsg.value.trim()) {
    reminderError.value = 'Completá todos los campos'; return
  }
  reminderSaving.value = true
  try {
    await api.post('/recordatorios', {
      lead_id: activeLead.value.id,
      mensaje: reminderMsg.value.trim(),
      fecha_hora: reminderDate.value
    })
    showReminderModal.value = false
  } catch (e) {
    reminderError.value = e?.response?.data?.error || 'Error al crear recordatorio'
  } finally {
    reminderSaving.value = false
  }
}

// ─── Init + polling ──────────────────────────────────────────────────────────
let pollInterval = null

onMounted(async () => {
  const [, u, , t, sc] = await Promise.all([
    loadLeads(),
    api.get('/crm/users').catch(() => ({ data: { users: [] } })),
    api.get('/crm/stats').catch(() => ({ data: { stats: {} } })),
    api.get('/crm/tags').catch(() => ({ data: { tags: [] } })),
    api.get('/crm/shortcuts').catch(() => ({ data: { shortcuts: [] } }))
  ])
  users.value     = u.data.users     || []
  tags.value      = t.data.tags      || []
  shortcuts.value = sc.data.shortcuts || []

  // Biblioteca categorías
  api.get('/biblioteca/categories').then(r => { biblioCats.value = r.data.categories || [] }).catch(() => {})

  // Estado online
  api.get('/auth/me').then(r => { isOnline.value = !!r.data.user?.crm_online }).catch(() => {})

  // Polling cada 15s
  pollInterval = setInterval(() => { if (!document.hidden) loadLeads() }, 15000)
})

onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval)
  cancelRecording()
})

watch(() => store.selectedInstance, () => loadLeads())
</script>
