<template>
  <div class="login-page">
    <div class="login-card">
      <div class="login-header">
        <span class="login-logo">💬</span>
        <h1>CRM Luxom</h1>
        <p>Iniciá sesión para continuar</p>
      </div>

      <form @submit.prevent="doLogin">
        <div class="form-group">
          <label>Usuario</label>
          <input
            v-model="usuario"
            type="text"
            placeholder="Tu usuario"
            required
            autofocus
          />
        </div>

        <div class="form-group">
          <label>Contraseña</label>
          <input
            v-model="password"
            type="password"
            placeholder="Tu contraseña"
            required
          />
        </div>

        <p v-if="error" class="error">{{ error }}</p>

        <button type="submit" :disabled="loading" class="btn-login">
          {{ loading ? 'Ingresando...' : 'Ingresar' }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import store from '../store'

const router  = useRouter()
const usuario = ref('')
const password = ref('')
const error   = ref('')
const loading = ref(false)

async function doLogin() {
  error.value   = ''
  loading.value = true
  try {
    await store.login(usuario.value, password.value)
    router.push('/')
  } catch (e) {
    error.value = e.response?.data?.error || 'Error de conexión'
  } finally {
    loading.value = false
  }
}
</script>
