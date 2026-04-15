import { createRouter, createWebHashHistory } from 'vue-router'
import store from './store'

// Lazy imports para code-splitting
const Login          = () => import('./views/Login.vue')
const Dashboard      = () => import('./views/Dashboard.vue')
const Contacts       = () => import('./views/Contacts.vue')
const CRM            = () => import('./views/CRM.vue')
const Instances      = () => import('./views/Instances.vue')
const Campaigns      = () => import('./views/Campaigns.vue')
const States         = () => import('./views/States.vue')
const Broadcasts     = () => import('./views/Broadcasts.vue')
const AutoReply      = () => import('./views/AutoReply.vue')
const QuickReplies   = () => import('./views/QuickReplies.vue')
const Users          = () => import('./views/Users.vue')
const ConfigCRM      = () => import('./views/ConfigCRM.vue')
const ConfigCampaigns = () => import('./views/ConfigCampaigns.vue')
const Tracking       = () => import('./views/Tracking.vue')
const Recordatorios  = () => import('./views/Recordatorios.vue')
const Monitor        = () => import('./views/Monitor.vue')
const Biblioteca     = () => import('./views/Biblioteca.vue')
const License        = () => import('./views/License.vue')
const SinContestar   = () => import('./views/SinContestar.vue')
const Estadisticas   = () => import('./views/Estadisticas.vue')
const Masivo         = () => import('./views/Masivo.vue')
const CampaignMulti  = () => import('./views/CampaignMulti.vue')
const ComingSoon     = () => import('./views/ComingSoon.vue')

const routes = [
  { path: '/login',            name: 'Login',           component: Login,           meta: { guest: true } },
  { path: '/',                 name: 'Dashboard',        component: Dashboard,        meta: { auth: true } },
  { path: '/contacts',         name: 'Contacts',         component: Contacts,         meta: { auth: true } },
  { path: '/crm',              name: 'CRM',              component: CRM,              meta: { auth: true } },
  { path: '/instances',        name: 'Instances',        component: Instances,        meta: { auth: true, requiresAdmin: true } },
  { path: '/campaigns',        name: 'Campaigns',        component: Campaigns,        meta: { auth: true } },
  { path: '/states',           name: 'States',           component: States,           meta: { auth: true } },
  { path: '/broadcasts',       name: 'Broadcasts',       component: Broadcasts,       meta: { auth: true } },
  { path: '/auto-reply',       name: 'AutoReply',        component: AutoReply,        meta: { auth: true } },
  { path: '/quick-replies',    name: 'QuickReplies',     component: QuickReplies,     meta: { auth: true } },
  { path: '/users',            name: 'Users',            component: Users,            meta: { auth: true, requiresAdmin: true } },
  { path: '/config-crm',       name: 'ConfigCRM',        component: ConfigCRM,        meta: { auth: true } },
  { path: '/config-campaigns', name: 'ConfigCampaigns',  component: ConfigCampaigns,  meta: { auth: true } },
  { path: '/tracking',         name: 'Tracking',         component: Tracking,         meta: { auth: true } },
  { path: '/recordatorios',    name: 'Recordatorios',    component: Recordatorios,    meta: { auth: true } },
  { path: '/monitor',          name: 'Monitor',          component: Monitor,          meta: { auth: true, requiresAdmin: true } },
  { path: '/biblioteca',       name: 'Biblioteca',       component: Biblioteca,       meta: { auth: true, requiresAdmin: true } },
  { path: '/license',          name: 'License',          component: License,          meta: { auth: true } },
  { path: '/sin-contestar',    name: 'SinContestar',     component: SinContestar,     meta: { auth: true, requiresAdmin: true } },
  { path: '/estadisticas',     name: 'Estadisticas',     component: Estadisticas,     meta: { auth: true, requiresAdmin: true } },
  { path: '/bulk-send',        name: 'BulkSend',         component: Masivo,           meta: { auth: true, requiresAdmin: true } },
  { path: '/multi-campaigns',  name: 'MultiCampaigns',   component: CampaignMulti,    meta: { auth: true, requiresAdmin: true } },
  { path: '/bulk-states',      name: 'BulkStates',       component: ComingSoon,       meta: { auth: true } },
  // Catch-all
  { path: '/:pathMatch(.*)*',  redirect: '/' }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes
})

// Guard de navegación (exacto al bundle)
router.beforeEach((to, from, next) => {
  if (to.meta.auth && !store.isLoggedIn) {
    next('/login')
  } else if ((to.meta.guest && store.isLoggedIn) || (to.meta.requiresAdmin && !store.isAdmin)) {
    next('/')
  } else {
    next()
  }
})

export default router
