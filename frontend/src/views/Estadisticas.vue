<template>
  <div class="est-page">
    <div class="page-header">
      <div><h2>📊 Estadísticas</h2><p>Métricas y rendimiento del equipo</p></div>
      <div class="header-actions">
        <div class="date-range">
          <input type="date" v-model="desde" :max="today" @change="load" class="date-input" />
          <span>→</span>
          <input type="date" v-model="hasta" :max="today" @change="load" class="date-input" />
        </div>
        <button @click="load" class="btn-primary">📊 Consultar</button>
      </div>
    </div>

    <div v-if="loading" class="loading-state">⏳ Cargando estadísticas...</div>

    <template v-else-if="kpi">
      <!-- KPIs -->
      <div class="kpi-grid">
        <div class="kpi-card kpi-blue">
          <div class="kpi-num">{{ kpi.total_leads }}</div>
          <div class="kpi-label">Leads nuevos</div>
        </div>
        <div class="kpi-card kpi-green">
          <div class="kpi-num">{{ kpi.cerrados_pos }}</div>
          <div class="kpi-label">Cerrados ✅</div>
        </div>
        <div class="kpi-card kpi-red">
          <div class="kpi-num">{{ kpi.cerrados_neg }}</div>
          <div class="kpi-label">Cerrados ❌</div>
        </div>
        <div class="kpi-card kpi-purple">
          <div class="kpi-num">{{ kpi.msgs_salientes }}</div>
          <div class="kpi-label">Msgs enviados</div>
        </div>
        <div class="kpi-card kpi-orange">
          <div class="kpi-num">{{ fmtSec(kpi.prom_resp_seg) }}</div>
          <div class="kpi-label">Tpo. prom. respuesta</div>
        </div>
        <div class="kpi-card kpi-teal">
          <div class="kpi-num">{{ kpi.msgs_entrantes }}</div>
          <div class="kpi-label">Msgs recibidos</div>
        </div>
      </div>

      <!-- Tabla vendedores -->
      <div class="section">
        <h3>👥 Rendimiento por vendedor</h3>
        <div class="tabla-wrapper">
          <table class="tabla">
            <thead>
              <tr>
                <th>Vendedor</th><th>Leads</th><th>Cerrados ✅</th><th>Cerrados ❌</th>
                <th>Tasa cierre</th><th>Msgs enviados</th><th>Prom. resp.</th><th>Activos</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="v in vendedores" :key="v.id">
                <td class="td-vendedor">{{ v.nombre }}</td>
                <td><strong>{{ v.leads_periodo }}</strong></td>
                <td class="td-pos">{{ v.cerrados_pos }}</td>
                <td class="td-neg">{{ v.cerrados_neg }}</td>
                <td>
                  <div class="bar-wrap">
                    <div class="bar-fill" :style="{ width: Math.min(v.tasa_cierre, 100) + '%' }"></div>
                  </div>
                  <span class="bar-label">{{ v.tasa_cierre }}%</span>
                </td>
                <td>{{ v.msgs_enviados }}</td>
                <td>{{ v.prom_resp_seg ? fmtSec(v.prom_resp_seg) : '—' }}</td>
                <td>{{ v.activos }}</td>
              </tr>
              <tr v-if="!vendedores.length">
                <td colspan="8" class="empty-row">Sin datos en el período</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Top instancias -->
      <div v-if="topInstancias.length" class="section">
        <h3>📱 Top instancias</h3>
        <div class="tabla-wrapper">
          <table class="tabla">
            <thead><tr><th>Instancia</th><th>Leads</th><th>Msgs enviados</th></tr></thead>
            <tbody>
              <tr v-for="i in topInstancias" :key="i.nombre">
                <td>{{ i.nombre }}</td>
                <td>{{ i.leads }}</td>
                <td>{{ i.msgs_sal }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const today       = new Date().toISOString().split('T')[0]
const desde       = ref(today)
const hasta       = ref(today)
const loading     = ref(false)
const kpi         = ref(null)
const vendedores  = ref([])
const topInstancias = ref([])

function fmtSec(u) {
  if (!u) return '—'
  const d = parseInt(u)
  return d < 60 ? `${d}s` : d < 3600 ? `${Math.round(d/60)}m` : `${Math.round(d/3600)}h`
}

async function load() {
  loading.value = true; kpi.value = null
  try {
    const { data } = await api.get('/estadisticas', { params: { desde: desde.value, hasta: hasta.value } })
    kpi.value          = data.kpi         || {}
    vendedores.value   = data.vendedores  || []
    topInstancias.value = data.top_instancias || []
  } catch (e) { console.error(e) }
  finally { loading.value = false }
}

onMounted(load)
</script>
