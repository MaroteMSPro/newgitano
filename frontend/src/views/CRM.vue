<template>
  <div class="crm-view">

    <!-- PANEL IZQUIERDO -->
    <div class="chat-list-panel">
      <div class="chat-tabs">
        <button :class="['tab',{active:tab==='activos'}]" @click="tab='activos';loadLeads()">
          <span class="tab-dot green"></span> Activos
          <span class="tab-count">{{ counts.activos||0 }}</span>
        </button>
        <button :class="['tab',{active:tab==='cerrados'}]" @click="tab='cerrados';loadLeads()">
          <span class="tab-dot red"></span> Cerrados
        </button>
        <button v-if="store.isAdmin" :class="['tab',{active:tab==='gestion'}]" @click="tab='gestion'">
          ⚙️ Gestión
        </button>
      </div>

      <template v-if="tab!=='gestion'">
        <div class="chat-filters">
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

        <div class="chat-filters-row2">
          <select v-if="store.isAdmin" class="filter-select" v-model="filterUsuario" @change="loadLeads()">
            <option value="">Todos los usuarios</option>
            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.nombre }}</option>
          </select>
          <div class="date-filters">
            <input type="date" class="filter-date" v-model="filterDesde" @change="loadLeads()" title="Desde" />
            <input type="date" class="filter-date" v-model="filterHasta" @change="loadLeads()" title="Hasta" />
            <button v-if="filterDesde||filterHasta" @click="filterDesde='';filterHasta='';loadLeads()" class="clear-date">✕</button>
          </div>
        </div>

        <div class="chat-stats">
          <span>{{ visibleLeads.length }} de {{ sortedLeads.length }} chats</span>
          <span v-if="myLimit.max > 0"
                :class="['mi-carga', myLimit.activos >= myLimit.max ? 'full' : '']"
                :title="myLimit.activos >= myLimit.max ? 'Al tope — no vas a recibir leads nuevos hasta cerrar chats' : 'Tu capacidad'"
                style="margin-left:10px;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:#e8f5e9;color:#2e7d32">
            📊 {{ myLimit.activos }}/{{ myLimit.max }} activos
          </span>
          <label class="online-switch" @click.prevent="toggleOnline">
            <span :class="['switch-track',{on:isOnline}]"><span class="switch-thumb"></span></span>
            <span :class="['switch-label',isOnline?'label-on':'label-off']">{{ isOnline?'🟢 Online':'🔴 Offline' }}</span>
          </label>
        </div>

        <div class="chat-search">
          <input v-model="search" @input="debouncedLoad" type="text" placeholder="🔍 Buscar nombre o número..." />
        </div>

        <!-- Selector tipo de conversación: Chats / Todos / Grupos -->
        <div class="tipo-filter" style="display:flex;gap:4px;padding:4px 8px;background:#f5f5f5;border-bottom:1px solid #e0e0e0">
          <button
            v-for="opt in [{v:'chat',l:'💬 Chats'},{v:'all',l:'🌐 Todos'},{v:'grupo',l:'👥 Grupos'}]"
            :key="opt.v"
            @click="tipoFiltro=opt.v;loadLeads()"
            :class="['tipo-btn',{active:tipoFiltro===opt.v}]"
            :style="tipoFiltro===opt.v
              ? 'flex:1;padding:6px 10px;border:none;border-radius:4px;background:#25d366;color:#fff;cursor:pointer;font-size:12px;font-weight:600'
              : 'flex:1;padding:6px 10px;border:none;border-radius:4px;background:#fff;color:#555;cursor:pointer;font-size:12px;font-weight:500'">
            {{ opt.l }}
          </button>
        </div>

        <div class="chat-items">
          <div v-for="lead in visibleLeads" :key="lead.id"
               :class="['chat-item',{selected:activeLead?.id===lead.id,pinned:lead.marcado}]"
               @click="openLead(lead)">
            <div class="chat-avatar" :style="{background:avatarColor(lead.nombre_personalizado||lead.nombre||lead.numero),position:'relative'}">
              {{ initials(lead.nombre_personalizado||lead.nombre||lead.numero) }}
              <span v-if="lead.contacto_tipo==='grupo'"
                    title="Grupo de WhatsApp"
                    style="position:absolute;bottom:-2px;right:-2px;background:#7b1fa2;color:#fff;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;border:2px solid #fff">👥</span>
            </div>
            <div class="chat-info">
              <div class="chat-top">
                <strong class="chat-name" @dblclick.stop="startRename(lead)">
                  <template v-if="renamingLead?.id===lead.id">
                    <input style="border:1px solid #25d366;border-radius:4px;padding:2px 6px;font-size:13px;width:110px;outline:none"
                           v-model="renameValue"
                           @keyup.enter="saveAlias(lead)" @keyup.escape="renamingLead=null"
                           @blur="saveAlias(lead)" @click.stop
                           :ref="el=>{if(el&&renamingLead?.id===lead.id)el.focus()}" />
                  </template>
                  <template v-else>{{ lead.nombre_personalizado||lead.nombre||lead.numero }}</template>
                </strong>
                <span class="chat-time">{{ fmtLeadTime(lead.ultimo_mensaje_at) }}</span>
              </div>
              <div class="chat-bottom">
                <span class="chat-preview">{{ truncate(lead.ultimo_mensaje,35)||'Sin mensajes' }}</span>
                <div class="chat-indicators">
                  <span v-if="lead.marcado" class="indicator-dot pinned-dot">📌</span>
                  <span v-if="lead.mensajes_sin_leer>0" class="indicator-dot unread-dot">{{ lead.mensajes_sin_leer }}</span>
                </div>
              </div>
              <div class="chat-meta">
                <span v-if="lead.asignado_nombre" class="chat-assigned">👤 {{ lead.asignado_nombre }}</span>
                <span v-if="lead.estado==='nuevo'" class="badge-new">NUEVO</span>
              </div>
            </div>
            <div class="chat-quick-actions" @click.stop>
              <button @click.stop="startRename(lead)" class="qa-btn" title="Renombrar">✏️</button>
              <button @click.stop="togglePin(lead)" :class="['qa-btn',{active:lead.marcado}]" title="Fijar">📌</button>
              <button @click.stop="markUnread(lead)" class="qa-btn" title="No leído">📩</button>
            </div>
          </div>
          <div v-if="!sortedLeads.length&&!leadsLoading" class="no-chats">No hay chats</div>
          <div v-if="leadsLoading" class="no-chats">Cargando...</div>
          <button v-if="hasMore && !leadsLoading"
                  class="btn-load-more"
                  @click="loadMore"
                  style="margin:12px auto;display:block;padding:10px 20px;background:#25d366;color:#fff;border:none;border-radius:20px;cursor:pointer;font-size:13px;font-weight:600">
            Ver 50 más ({{ sortedLeads.length - visibleLeads.length }} restantes)
          </button>
        </div>
      </template>
    </div>

    <!-- PANEL DERECHO -->
    <div v-show="tab!=='gestion'" class="chat-view-panel"
         @dragover.prevent
         @dragenter.prevent="onDragEnter"
         @dragleave="onDragLeave"
         @drop.prevent="onDrop">
      <div v-if="!activeLead" class="no-chat-selected">
        <div class="no-chat-icon">💬</div>
        <p>Seleccioná un chat para empezar</p>
        <small>{{ sortedLeads.length }} conversaciones activas</small>
      </div>

      <template v-else>
        <div class="chat-header">
          <div class="chat-avatar-lg" :style="{background:avatarColor(activeLead.nombre_personalizado||activeLead.nombre||activeLead.numero)}">
            {{ initials(activeLead.nombre_personalizado||activeLead.nombre||activeLead.numero) }}
          </div>
          <div class="chat-header-info">
            <strong>{{ activeLead.nombre_personalizado||activeLead.nombre||activeLead.numero }}</strong>
            <small>{{ activeLead.numero }} · {{ activeLead.asignado_nombre||'Sin asignar' }}</small>
          </div>
          <div class="chat-header-actions">
            <button class="header-btn green" @click="closeLead('cerrado_positivo')" title="Cerrar +">✅</button>
            <button class="header-btn red"   @click="closeLead('cerrado_negativo')" title="Cerrar -">❌</button>
            <button class="header-btn blue"  @click="showTransfer=!showTransfer" title="Transferir">🔄</button>
            <span :class="['estado-badge',estadoClass(activeLead.estado)]">{{ estadoLabel(activeLead.estado) }}</span>
            <span class="header-sep">|</span>
            <button class="header-btn" @click="togglePinActive" :title="activeLead.marcado?'Desfijar':'Fijar'">{{ activeLead.marcado?'⭐':'☆' }}</button>
            <button class="header-btn" @click="markUnreadActive" title="No leído">📩</button>
            <button class="header-btn" @click="showBiblioteca=!showBiblioteca" title="Biblioteca">📁</button>
            <button class="header-btn" @click="openReminderModal" title="Recordatorio">🔔</button>
          </div>
        </div>

        <div v-if="showTransfer" class="transfer-panel">
          <strong>Transferir a:</strong>
          <div class="transfer-users">
            <button v-for="u in onlineUsers" :key="u.id" @click="transferLead(u.id)" class="transfer-user-btn">
              {{ u.nombre }} 🟢
            </button>
          </div>
          <div v-if="!onlineUsers.length" style="font-size:12px;color:#999;margin-top:6px">No hay usuarios online</div>
        </div>

        <div class="tags-bar">
          <label v-for="tag in tags" :key="tag.id" class="tag-check" :style="{'--tag-color':tag.color}">
            <input type="checkbox" :checked="activeTagIds.includes(tag.id)" @change="toggleTag(tag.id)" />
            <span class="tag-label" :style="{background:activeTagIds.includes(tag.id)?tag.color:'transparent',color:activeTagIds.includes(tag.id)?'white':tag.color,borderColor:tag.color}">{{ tag.nombre }}</span>
          </label>
        </div>

        <div class="chat-messages" ref="messagesContainer">
          <div v-if="msgsLoading" class="loading-msg">Cargando mensajes...</div>
          <template v-else>
            <div v-for="msg in messages" :key="msg.id" :class="['message',msg.direccion==='saliente'?'sent':'received']">
              <div class="message-bubble">
                <button class="reply-btn" @click="replyTo=msg" title="Responder">↩</button>
                <div v-if="msg.enviado_por" class="msg-sender">{{ msg.enviado_por }}</div>
                <div v-if="msg._replyTo||msg.quoted_content" class="quoted-msg">
                  <strong>{{ msg._replyTo?.nombre||msg.quoted_sender||'' }}</strong>
                  <span>{{ msg._replyTo?.contenido||msg.quoted_content||'' }}</span>
                </div>
                <div v-if="msg.es_recordatorio" class="msg-reminder-badge">🔔 Recordatorio</div>
                <p v-if="!msg.tipo||msg.tipo==='text'">{{ msg.contenido }}</p>
                <div v-else-if="msg.tipo==='image'" class="media-msg">
                  <template v-if="ms(msg.id).url">
                    <img :src="ms(msg.id).url" class="media-img"
                         @click="openMedia(ms(msg.id).url)"
                         style="cursor:zoom-in" />
                    <small v-if="msg.contenido&&msg.contenido!=='📎 '+msg.contenido"
                           style="font-size:11px;color:#888;margin-top:2px">{{ msg.contenido }}</small>
                  </template>
                  <div v-else-if="msg._sending" style="display:flex;align-items:center;gap:8px;padding:6px 0;font-size:13px;color:#888">
                    <span>⏳</span> Enviando imagen...
                  </div>
                  <span v-else-if="ms(msg.id).error"
                        style="font-size:12px;color:#c62828;background:#ffebee;padding:4px 10px;border-radius:6px">
                    ⚠️ No disponible
                  </span>
                  <button v-else class="media-load-btn" @click="loadMedia(msg)" :disabled="ms(msg.id).loading">
                    {{ ms(msg.id).loading?'⏳ Cargando...':'📷 Ver imagen' }}
                  </button>
                </div>
                <div v-else-if="msg.tipo==='video'" class="media-msg">
                  <video v-if="ms(msg.id).url" :src="ms(msg.id).url"
                         class="media-video" controls style="max-width:300px;border-radius:8px"></video>
                  <div v-else-if="msg._sending" style="display:flex;align-items:center;gap:8px;padding:6px 0;font-size:13px;color:#888">
                    <span>⏳</span> Enviando video...
                  </div>
                  <button v-else class="media-load-btn" @click="loadMedia(msg)" :disabled="ms(msg.id).loading">
                    {{ ms(msg.id).loading?'⏳ Cargando...':'🎬 Ver video' }}
                  </button>
                </div>

                <div v-else-if="msg.tipo==='audio'" class="media-msg">
                  <div v-if="ms(msg.id).url" class="audio-custom">
                    <audio :src="ms(msg.id).url" :ref="el=>{ if(el) ms(msg.id).audioEl=el }"
                           @timeupdate="ms(msg.id).progress=(ms(msg.id).audioEl?.currentTime/ms(msg.id).audioEl?.duration*100)||0"
                           @ended="ms(msg.id).playing=false"
                           @loadedmetadata="ms(msg.id).duration=ms(msg.id).audioEl?.duration||0"></audio>
                    <button class="audio-play-btn" @click="toggleAudio(msg)">{{ ms(msg.id).playing?'⏸':'▶️' }}</button>
                    <div class="audio-bar"><div class="audio-progress" :style="{width:ms(msg.id).progress+'%'}"></div></div>
                    <span class="audio-time">
                      {{ fmtDuration(ms(msg.id).audioEl?.currentTime||0) }}
                      <template v-if="ms(msg.id).duration > 0 && isFinite(ms(msg.id).duration)"> / {{ fmtDuration(ms(msg.id).duration) }}</template>
                    </span>
                  </div>
                  <div v-else-if="msg._sending" style="display:flex;align-items:center;gap:8px;padding:6px 0;font-size:13px;color:#888">
                    <span>⏳</span> Enviando audio...
                  </div>
                  <span v-else-if="ms(msg.id).error"
                        style="font-size:12px;color:#c62828;background:#ffebee;padding:4px 10px;border-radius:6px">
                    ⚠️ Audio no disponible
                  </span>
                  <button v-else class="media-load-btn" @click="loadMedia(msg)" :disabled="ms(msg.id).loading">
                    {{ ms(msg.id).loading?'⏳ Cargando...':'▶️ Reproducir audio' }}
                  </button>
                </div>
                <div v-else-if="msg.tipo==='document'" class="media-msg">
                  <a v-if="ms(msg.id).url" :href="ms(msg.id).url"
                     :download="msg.contenido||'archivo'" target="_blank" class="doc-link">
                    📄 {{ msg.contenido||'Descargar archivo' }}
                  </a>
                  <div v-else-if="msg._sending" style="display:flex;align-items:center;gap:8px;padding:6px 0;font-size:13px;color:#888">
                    <span>⏳</span> Enviando archivo...
                  </div>
                  <span v-else-if="ms(msg.id).error"
                        style="font-size:12px;color:#c62828;background:#ffebee;padding:4px 10px;border-radius:6px">
                    ⚠️ No disponible
                  </span>
                  <button v-else class="media-load-btn" @click="loadMedia(msg)" :disabled="ms(msg.id).loading">
                    {{ ms(msg.id).loading?'⏳ Cargando...':'📄 Ver documento' }}
                  </button>
                </div>
                <div class="message-time">{{ fmtMsgTime(msg.created_at) }}</div>
              </div>
            </div>
          </template>
        </div>

        <div v-if="showBiblioteca" class="biblioteca-panel">
          <div class="biblio-header">
            <strong>📁 Biblioteca</strong>
            <button @click="showBiblioteca=false">✕</button>
          </div>
          <div class="biblio-cats">
            <button v-for="cat in biblioCats" :key="cat.id"
                    :class="['biblio-cat',{active:biblioCatSel===cat.id}]"
                    @click="loadBiblioFiles(cat.id)">{{ cat.nombre }}</button>
          </div>
          <div class="biblio-files">
            <div v-for="f in biblioFiles" :key="f.id" class="biblio-file" @click="sendBiblioFile(f)">
              <span class="file-icon">{{ fileIcon(f.tipo) }}</span>
              <div class="file-info"><strong>{{ f.nombre }}</strong><small>{{ f.archivo_nombre }}</small></div>
            </div>
            <div v-if="!biblioFiles.length" class="biblio-empty">No hay archivos</div>
          </div>
        </div>

        <div v-if="isDragging" class="drop-overlay">
          <div class="drop-box">
            <div style="font-size:40px;margin-bottom:12px">📎</div>
            <div>Soltá el archivo acá</div>
            <div style="font-size:13px;opacity:.7;margin-top:8px">Imágenes hasta 16MB · Documentos hasta 100MB</div>
          </div>
        </div>

        <div v-if="attachFile" class="file-preview">
          <!-- Error de tamaño -->
          <div v-if="attachSizeError"
               style="background:#ffebee;color:#c62828;padding:10px 16px;font-size:13px;font-weight:600;display:flex;justify-content:space-between;align-items:center">
            ⚠️ {{ attachSizeError }}
            <button @click="cancelAttach" style="background:none;border:none;cursor:pointer;color:#c62828;font-size:18px">✕</button>
          </div>
          <template v-else>
            <div class="preview-card">
              <!-- Thumbnail imagen -->
              <img v-if="attachFile.type?.startsWith('image/') && attachPreviewUrl"
                   :src="attachPreviewUrl"
                   style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #e0e0e0;flex-shrink:0;cursor:pointer"
                   @click="openMedia(attachPreviewUrl)" />
              <!-- Video preview -->
              <video v-else-if="attachFile.type?.startsWith('video/') && attachPreviewUrl"
                     :src="attachPreviewUrl"
                     style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid #e0e0e0;flex-shrink:0" muted></video>
              <!-- Ícono para doc/audio -->
              <span v-else class="preview-icon" style="font-size:36px">{{ fileIcon(attachFile.type) }}</span>
              <div class="preview-info">
                <strong style="font-size:13px;word-break:break-all">{{ attachFile.name }}</strong>
                <small style="display:flex;gap:8px;align-items:center;margin-top:3px">
                  <span>{{ fmtSize(attachFile.size) }}</span>
                  <span style="background:#e0e0e0;padding:1px 6px;border-radius:4px;font-size:10px;text-transform:uppercase">
                    {{ attachFileExt }}
                  </span>
                </small>
              </div>
              <button @click="cancelAttach" class="preview-x" title="Cancelar">✕</button>
            </div>
            <div class="preview-caption">
              <input v-model="attachCaption" placeholder="Agregar un comentario (opcional)..." @keyup.enter="sendAttach" />
              <button @click="sendAttach" class="btn-send-file" :disabled="sendingAttach">
                {{ sendingAttach ? '⏳ Enviando...' : '➤ Enviar' }}
              </button>
            </div>
          </template>
        </div>

        <div class="chat-input-area">
          <div v-if="isRecording" style="display:flex;align-items:center;gap:10px;padding:8px 16px;background:#ffebee;border-top:1px solid #e0e0e0">
            <span style="color:#e74c3c;font-size:12px;font-weight:700;animation:blink 1s infinite">● {{ fmtDuration(recSeconds) }}</span>
            <button class="btn-icon" @click="pauseResumeRecording" :title="recPaused?'Reanudar':'Pausar'">{{ recPaused?'▶️':'⏸' }}</button>
            <button class="btn-icon" @click="stopRecording" title="Enviar audio">✅</button>
            <button class="btn-icon" @click="cancelRecording" title="Cancelar">🗑️</button>
          </div>

          <div v-if="showShortcuts" class="shortcuts-panel">
            <div class="shortcuts-header">
              <strong>⚡ Atajos rápidos</strong>
              <button @click="showShortcuts=false">✕</button>
            </div>
            <div v-for="s in shortcuts" :key="s.id" class="shortcut-item" @click="useShortcut(s)">
              <span class="shortcut-name">{{ s.nombre }}</span>
              <span v-if="s.atajo" class="shortcut-key">{{ s.atajo }}</span>
              <small>{{ truncate(s.contenido,50) }}</small>
            </div>
          </div>

          <div v-if="replyTo" class="reply-bar">
            <div class="reply-preview">
              <strong>{{ replyTo.direccion==='entrante'?activeLead?.nombre:'Tú' }}</strong>
              <span>{{ (replyTo.contenido||'').substring(0,80) }}</span>
            </div>
            <button class="reply-cancel" @click="replyTo=null">✕</button>
          </div>

          <div class="chat-input">
            <button class="btn-icon" @click="showShortcuts=!showShortcuts" title="Atajos">⚡</button>
            <button class="btn-icon" @click="$refs.fileInputChat.click()" title="Adjuntar">📎</button>
            <button v-if="!isRecording" class="btn-icon" @click="startRecording" title="Grabar audio">🎤</button>
            <input type="file" ref="fileInputChat" @change="onFileSelect" style="display:none" accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar,.csv" />
            <input v-model="msgText" @keyup.enter="sendMessage" @input="onMsgInput" type="text" placeholder="Escribí un mensaje... (/ para atajos)" />
            <button @click="sendMessage" class="btn-send" :disabled="!msgText.trim()">➤</button>
          </div>
        </div>
      </template>
    </div>

    <!-- Gestión panel -->
    <div v-if="tab==='gestion'" class="gestion-fullpanel">
      <GestionPanel />
    </div>

    <!-- Modal recordatorio -->
    <div v-if="showReminderModal" class="modal-overlay" @click.self="showReminderModal=false">
      <div class="modal-box">
        <div class="modal-header">
          <strong>🔔 Nuevo Recordatorio</strong>
          <button @click="showReminderModal=false" class="modal-close">✕</button>
        </div>
        <div class="modal-body">
          <p style="font-size:13px;margin-bottom:10px">Lead: <strong>{{ activeLead?.nombre||activeLead?.numero }}</strong></p>
          <label>Fecha y hora</label>
          <input type="datetime-local" v-model="reminderDate" :min="reminderMinDate" class="modal-input" />
          <label>Mensaje</label>
          <textarea v-model="reminderMsg" rows="4" class="modal-input" placeholder="Escribí el mensaje a enviar..."></textarea>
          <p v-if="reminderError" class="modal-error">{{ reminderError }}</p>
        </div>
        <div class="modal-footer">
          <button @click="showReminderModal=false" class="btn-cancel">Cancelar</button>
          <button @click="saveReminder" class="btn-confirm" :disabled="reminderSaving">{{ reminderSaving?'Guardando...':'Crear Recordatorio' }}</button>
        </div>
      </div>
    </div>

  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { defineAsyncComponent } from 'vue'
import store from '../store'
import api from '../api'

// GestionPanel carga lazy
const GestionPanel = defineAsyncComponent(() => import('./GestionPanel.vue'))

// ─── Estado principal ───────────────────────────────────────────────────────
const tab           = ref('activos')
const tipoFiltro    = ref('chat')  // 'chat' | 'all' | 'grupo' — default: chats individuales
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
// Mapa reactivo para el estado de media (evita problema de reactividad con objetos planos)
const mediaState   = reactive({})  // { [msg.id]: { url, loading, playing, progress, duration, audioEl } }
function ms(id) {
  if (!mediaState[id]) mediaState[id] = { url:null, loading:false, playing:false, progress:0, duration:0, audioEl:null, error:false }
  return mediaState[id]
}
const leadsLoading = ref(true)
const msgsLoading  = ref(false)
const isOnline     = ref(false)

// Capacidad del vendedor (max_chats_activos por usuario-instancia)
const myLimit = ref({ max: 0, activos: 0 })

async function loadMyLimit() {
  if (!store.selectedInstance) { myLimit.value = { max: 0, activos: 0 }; return }
  try {
    const { data } = await api.get('/crm/my-limit', {
      params: { instancia_id: store.selectedInstance }
    })
    myLimit.value = {
      max: data.max_chats_activos || 0,
      activos: data.chats_activos_actuales || 0
    }
  } catch (e) {
    myLimit.value = { max: 0, activos: 0 }
  }
}

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
  renameValue.value  = lead.nombre_personalizado || lead.nombre || lead.numero
  nextTick(() => renameInput.value?.focus?.())
}
async function saveAlias(lead) {
  if (!renamingLead.value) return
  const alias = renameValue.value.trim()
  if (alias && alias !== lead.numero) {
    try {
      await api.put(`/crm/leads/${lead.id}/alias`, { alias })
      lead.nombre_personalizado = alias
      if (activeLead.value?.id === lead.id) activeLead.value.nombre_personalizado = alias
    } catch (e) {
      console.error('Error guardando alias:', e)
    }
  }
  renamingLead.value = null
}

// ─── Drag & drop ─────────────────────────────────────────────────────────────
const isDragging      = ref(false)
const attachFile      = ref(null)
const attachPreviewUrl= ref(null)
const attachSizeError = ref('')
const fileInputChat   = ref(null)
const sendingAttach   = ref(false)
let dragCounter = 0

// Límites WhatsApp
const WA_LIMITS = {
  image:    16 * 1024 * 1024,   // 16 MB
  video:    16 * 1024 * 1024,   // 16 MB
  audio:    16 * 1024 * 1024,   // 16 MB
  document: 100 * 1024 * 1024,  // 100 MB
}
const attachFileExt = computed(() => {
  if (!attachFile.value) return ''
  const parts = attachFile.value.name.split('.')
  return parts.length > 1 ? parts.pop().toLowerCase() : ''
})

function onDragEnter(e) {
  dragCounter++
  // Solo activar si trae archivos
  if (e.dataTransfer?.types?.includes('Files')) isDragging.value = true
}
function onDragLeave(e) {
  dragCounter--
  if (dragCounter <= 0) { dragCounter = 0; isDragging.value = false }
}
const attachCaption = ref('')

function setAttachFile(file) {
  if (!file) return
  attachFile.value = file
  attachCaption.value = ''
  attachPreviewUrl.value = null
  attachSizeError.value = ''

  // Validar tamaño según tipo WhatsApp
  const type = file.type || ''
  let limit = WA_LIMITS.document
  if (type.startsWith('image/'))       limit = WA_LIMITS.image
  else if (type.startsWith('video/'))  limit = WA_LIMITS.video
  else if (type.startsWith('audio/'))  limit = WA_LIMITS.audio

  if (file.size > limit) {
    const limitMB = Math.round(limit / 1024 / 1024)
    attachSizeError.value = `El archivo supera el límite de WhatsApp (${limitMB} MB para ${type.split('/')[0] || 'documento'}s)`
    return
  }

  // Generar preview
  if (type.startsWith('image/')) {
    const reader = new FileReader()
    reader.onload = ev => { attachPreviewUrl.value = ev.target.result }
    reader.readAsDataURL(file)
  } else if (type.startsWith('video/')) {
    attachPreviewUrl.value = URL.createObjectURL(file)
  }
}
function cancelAttach() {
  attachFile.value = null
  attachPreviewUrl.value = null
  attachCaption.value = ''
  attachSizeError.value = ''
  if (fileInputChat.value) fileInputChat.value.value = ''
}
function onDrop(e) {
  isDragging.value = false
  dragCounter = 0
  const file = e.dataTransfer.files[0]
  if (file) setAttachFile(file)
}
function onFileSelect(e) {
  const file = e.target.files[0]
  if (file) setAttachFile(file)
}
async function sendAttach() {
  if (!attachFile.value || !activeLead.value || sendingAttach.value || attachSizeError.value) return
  sendingAttach.value = true

  const file    = attachFile.value
  const caption = attachCaption.value
  const mime    = file.type || ''
  const tipo    = mime.startsWith('image/') ? 'image'
                : mime.startsWith('video/') ? 'video'
                : mime.startsWith('audio/') ? 'audio'
                : 'document'

  // ── URL local inmediata para preview/reproducción sin esperar al server ──
  const localUrl = URL.createObjectURL(file)
  const fakeId   = `local_${Date.now()}`
  const fakeMsg  = {
    id: fakeId,
    contenido: caption || `📎 ${file.name}`,
    direccion: 'saliente',
    tipo,
    created_at: new Date().toISOString(),
    _sending: true
  }
  messages.value.push(fakeMsg)
  ms(fakeId).url = localUrl
  await nextTick(); scrollToBottom()

  // Limpiar el panel de adjunto ya
  attachFile.value       = null
  attachPreviewUrl.value = null
  attachCaption.value    = ''
  attachSizeError.value  = ''
  if (fileInputChat.value) fileInputChat.value.value = ''

  const fd = new FormData()
  fd.append('archivo', file)
  fd.append('lead_id', activeLead.value.id)
  fd.append('caption', caption)
  fd.append('media_type', tipo)           // 'image', 'video', 'document', 'audio'
  fd.append('mime_type', mime)            // ej: 'image/jpeg', 'application/pdf'
  fd.append('file_name', file.name)       // nombre original del archivo

  try {
    const { data } = await api.post(`/crm/leads/${activeLead.value.id}/attach`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' }
    })

    // Si el server devuelve msg_id → reemplazar fake con real
    if (data?.msg_id) {
      const idx = messages.value.findIndex(m => m.id === fakeId)
      if (idx !== -1) {
        ms(data.msg_id).url = localUrl
        messages.value[idx] = { ...fakeMsg, id: data.msg_id, _sending: false }
      }
    } else {
      // Sin msg_id → recargar mensajes tras 2s para obtener el real del server
      const leadId = activeLead.value?.id
      setTimeout(async () => {
        if (!activeLead.value || activeLead.value.id !== leadId) return
        try {
          const { data: d } = await api.get(`/crm/leads/${leadId}/messages`)
          const serverMsgs = d.messages || []
          // Transferir URL local al mensaje más reciente del mismo tipo
          const lastReal = [...serverMsgs].reverse().find(m =>
            m.tipo === tipo && m.direccion === 'saliente' && !ms(m.id).url
          )
          if (lastReal) ms(lastReal.id).url = localUrl
          // Quitar fake y usar los del server
          const idx = messages.value.findIndex(m => m.id === fakeId)
          if (idx !== -1) messages.value.splice(idx, 1)
          messages.value = serverMsgs
          await nextTick(); scrollToBottom()
        } catch {}
      }, 2000)
    }

    // Marcar como enviado
    const idx = messages.value.findIndex(m => m.id === fakeId)
    if (idx !== -1) messages.value[idx]._sending = false

  } catch (e) {
    // Marcar como error, pero el preview local sigue
    const idx = messages.value.findIndex(m => m.id === fakeId)
    if (idx !== -1) {
      messages.value[idx].contenido = `⚠️ Error al enviar: ${file.name}`
      messages.value[idx]._sending  = false
    }
    alert(e.response?.data?.error || 'Error al enviar archivo')
  } finally {
    sendingAttach.value = false
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

    // Detectar MIME soportado (Chrome usa webm, Firefox puede usar ogg)
    const mime =
      MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' :
      MediaRecorder.isTypeSupported('audio/webm')             ? 'audio/webm' :
      MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')  ? 'audio/ogg;codecs=opus' :
      ''

    mediaRecorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream)
    audioChunks   = []
    // timeslice 250ms → chunks frecuentes, más robusto
    mediaRecorder.ondataavailable = e => { if (e.data.size > 0) audioChunks.push(e.data) }
    mediaRecorder.start(250)
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
    // Usar el MIME real que usó el recorder
    const actualMime = mediaRecorder.mimeType || 'audio/webm'
    const ext        = actualMime.includes('ogg') ? 'ogg' : 'webm'
    const blob = new Blob(audioChunks, { type: actualMime })
    const file = new File([blob], `audio_${Date.now()}.${ext}`, { type: actualMime })

    // Crear URL local para reproducción inmediata (independiente del servidor)
    const localUrl    = URL.createObjectURL(blob)
    const fakeId      = `local_${Date.now()}`
    const fakeMsg     = {
      id: fakeId, contenido: `🎤 Audio (${recSeconds.value}s)`,
      direccion: 'saliente', tipo: 'audio',
      created_at: new Date().toISOString(),
      _localBlob: blob
    }

    // Agregar mensaje con URL local ANTES de enviar → usuario puede reproducirlo ya
    messages.value.push(fakeMsg)
    ms(fakeId).url = localUrl
    await nextTick(); scrollToBottom()

    // Enviar al servidor en background
    const fd = new FormData()
    fd.append('archivo', file)
    fd.append('lead_id', activeLead.value?.id)
    fd.append('caption', '')
    fd.append('is_audio', '1')      // backend hint: enviar como audioMessage
    fd.append('mime_type', actualMime)
    try {
      const { data } = await api.post(`/crm/leads/${activeLead.value?.id}/attach`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' }
      })
      // Reemplazar fake con ID real si el server lo devuelve
      if (data?.msg_id) {
        const idx = messages.value.findIndex(m => m.id === fakeId)
        if (idx !== -1) {
          const realId = data.msg_id
          ms(realId).url = localUrl
          messages.value[idx] = { ...fakeMsg, id: realId }
        }
      } else {
        // Si el server no devuelve msg_id, recargar mensajes después de 1s
        // (tiempo para que el backend procese el envío)
        setTimeout(async () => {
          if (activeLead.value) {
            try {
              const { data: d } = await api.get(`/crm/leads/${activeLead.value.id}/messages`)
              // Remover el fake y usar los reales del server
              const idx = messages.value.findIndex(m => m.id === fakeId)
              if (idx !== -1) messages.value.splice(idx, 1)
              messages.value = d.messages || messages.value
              await nextTick(); scrollToBottom()
            } catch {}
          }
        }, 1500)
      }
    } catch (e) {
      // Marcar como error pero audio sigue reproducible localmente
      const idx = messages.value.findIndex(m => m.id === fakeId)
      if (idx !== -1) messages.value[idx].contenido = '⚠️ Error al enviar'
      console.error('Error enviando audio:', e.response?.data?.error || e.message)
    }
    mediaRecorder?.stream?.getTracks().forEach(t => t.stop())
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
  if (!s || isNaN(s) || !isFinite(s)) return '0:00'
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

// ─── Leads + búsqueda fuzzy client-side ──────────────────────────────────────

function normalizePhone(s) {
  if (!s) return ''
  return String(s).replace(/[\s\-\+\(\)]/g, '')
}
function normalizeSearch(s) {
  if (!s) return ''
  return String(s).toLowerCase().replace(/[\s\-\+\(\)]/g, '')
}
function levenshtein(a, b) {
  const m = a.length, n = b.length
  if (m === 0) return n; if (n === 0) return m
  const dp = Array.from({length: m+1}, (_, i) => Array(n+1).fill(0))
  for (let i = 0; i <= m; i++) dp[i][0] = i
  for (let j = 0; j <= n; j++) dp[0][j] = j
  for (let i = 1; i <= m; i++)
    for (let j = 1; j <= n; j++)
      dp[i][j] = a[i-1] === b[j-1] ? dp[i-1][j-1]
        : 1 + Math.min(dp[i-1][j], dp[i][j-1], dp[i-1][j-1])
  return dp[m][n]
}
function fuzzyMatch(query, target) {
  if (!query || !target) return false
  const q = normalizeSearch(query)
  const t = normalizeSearch(target)
  if (!q) return true
  if (t.includes(q)) return true
  if (/^\d+$/.test(q) && t.endsWith(q)) return true
  if (q.length >= 4) {
    const tol = Math.floor(q.length / 4)
    for (let i = 0; i <= Math.max(0, t.length - q.length + tol); i++) {
      const slice = t.substring(i, i + q.length + tol)
      if (levenshtein(q, slice.substring(0, q.length)) <= tol) return true
    }
  }
  return false
}

const sortedLeads = computed(() => {
  let list = [...leads.value]
  const q = search.value?.trim()
  if (q) {
    list = list.filter(lead => {
      const nombre   = lead.nombre_personalizado || lead.nombre || ''
      const mensaje  = lead.ultimo_mensaje || ''
      const asignado = lead.asignado_nombre || ''
      const num      = normalizePhone(lead.numero || '')
      const qn       = normalizeSearch(q)
      if (fuzzyMatch(q, nombre)) return true
      if (fuzzyMatch(q, mensaje)) return true       // busca palabras en último mensaje
      if (fuzzyMatch(q, asignado)) return true      // busca por vendedor asignado
      if (num.includes(qn)) return true
      if (num.endsWith(qn)) return true
      // sin código de país (ej: "549...") — probar últimos dígitos
      if (num.length > 2 && num.substring(1).includes(qn)) return true
      if (num.length > 3 && num.substring(2).includes(qn)) return true
      return false
    })
  }
  return list.sort((a, b) => (a.marcado && !b.marcado ? -1 : !a.marcado && b.marcado ? 1 : 0))
})

// Paginación local — mostrar de a 50 sin recargar del backend
const VISIBLE_STEP = 50
const visibleCount = ref(VISIBLE_STEP)
const visibleLeads = computed(() => sortedLeads.value.slice(0, visibleCount.value))
const hasMore      = computed(() => visibleCount.value < sortedLeads.value.length)
function loadMore() {
  visibleCount.value = Math.min(visibleCount.value + VISIBLE_STEP, sortedLeads.value.length)
}
// Reset al cambiar búsqueda o cuando llegan leads nuevos del backend
watch([search, () => leads.value.length], () => {
  visibleCount.value = VISIBLE_STEP
})

const onlineUsers = computed(() => users.value.filter(u => u.crm_online))

let debounceTimer = null
function debouncedLoad() {
  clearTimeout(debounceTimer)
  // Fuzzy client-side actúa de inmediato; solo recarga servidor tras 600ms
  debounceTimer = setTimeout(() => {
    if (!search.value?.trim() || search.value.trim().length >= 3) loadLeads()
  }, 600)
}

async function loadLeads() {
  leadsLoading.value = true
  try {
    const params = { estado: tab.value, limit: 9999, tipo: tipoFiltro.value }
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
    loadMyLimit()
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
    const rawMsgs = m.data.messages || []
    // Pre-poblar mediaState con URLs que ya vengan en el mensaje
    // (para mensajes enviados con adjunto que el server ya tiene guardados)
    rawMsgs.forEach(msg => {
      if (msg.tipo && msg.tipo !== 'text') {
        const existingUrl = msg.media_url || msg.archivo_url || msg.file_url
                          || msg.url || msg.mediaUrl || msg.archivoUrl
        if (existingUrl && !ms(msg.id).url) {
          ms(msg.id).url = existingUrl
        }
      }
    })
    messages.value   = rawMsgs
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
      ? { nombre: replyTo.value.direccion === 'entrante' ? (activeLead.value.nombre_personalizado || activeLead.value.nombre) : 'Tú',
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
  const m = ms(msg.id)
  if (m.loading || m.url) return
  m.loading = true
  try {
    const { data } = await api.get('/crm/media', { params: { msg_id: msg.id } })
    // Soportar múltiples formatos de respuesta del backend
    if (data.base64 && data.mimetype) {
      m.url = `data:${data.mimetype};base64,${data.base64}`
    } else if (data.url) {
      m.url = data.url
    } else if (data.media_url) {
      m.url = data.media_url
    } else if (data.archivo_url) {
      m.url = data.archivo_url
    } else if (data.file_url) {
      m.url = data.file_url
    } else {
      // Loguear la respuesta real para debug
      console.warn('[loadMedia] Respuesta inesperada del backend:', data)
      m.error = true
    }
  } catch (e) {
    console.error('[loadMedia] Error:', e.response?.data || e.message)
    m.error = true
  }
  finally { m.loading = false }
}
function openMedia(url) { window.open(url, '_blank') }

function toggleAudio(msg) {
  const m = ms(msg.id)
  if (!m.audioEl) return
  if (m.playing) { m.audioEl.pause(); m.playing = false }
  else           { m.audioEl.play();  m.playing = true  }
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
    // Normalizar fecha: datetime-local da "2026-04-15T14:30", 
    // backend puede necesitar "2026-04-15 14:30:00" (MySQL) o con segundos
    const rawDate   = reminderDate.value            // "2026-04-15T14:30"
    const mysqlDate = rawDate.replace('T', ' ') + (rawDate.length === 16 ? ':00' : '')

    await api.post('/recordatorios', {
      lead_id:    activeLead.value.id,
      mensaje:    reminderMsg.value.trim(),
      fecha_hora: mysqlDate,              // "2026-04-15 14:30:00"
      // campos extra por si el backend los necesita:
      numero:         activeLead.value.numero,
      instancia_id:   activeLead.value.instancia_id || store.selectedInstance || null,
      instancia_nombre: activeLead.value.instancia   || null,
    })
    showReminderModal.value = false
    reminderDate.value = ''
    reminderMsg.value  = ''
  } catch (e) {
    console.error('[saveReminder]', e.response?.data || e.message)
    reminderError.value = e?.response?.data?.error
                       || e?.response?.data?.message
                       || `Error ${e?.response?.status || ''}: al crear recordatorio`
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
