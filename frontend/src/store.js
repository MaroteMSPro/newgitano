import { reactive } from 'vue'
import api from './api'

// Store global reactivo (mismo patrón que el bundle original)
const store = reactive({
  user: JSON.parse(localStorage.getItem('user') || 'null'),
  token: localStorage.getItem('token') || null,
  selectedInstance: null,

  get isLoggedIn() {
    return !!this.token
  },

  get isAdmin() {
    return this.user?.rol === 'admin'
  },

  async login(usuario, password) {
    const { data } = await api.post('/auth/login', { usuario, password })
    this.token = data.token
    this.user = data.user
    localStorage.setItem('token', data.token)
    localStorage.setItem('user', JSON.stringify(data.user))
    return data
  },

  logout() {
    this.token = null
    this.user = null
    localStorage.removeItem('token')
    localStorage.removeItem('user')
  }
})

export default store
