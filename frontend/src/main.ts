import { createApp } from 'vue'
import { createPinia } from 'pinia'
import piniaPersist from 'pinia-plugin-persistedstate'
import App from './App.vue'
import router from './router'
import '@tabler/core/dist/css/tabler.min.css'
import '@tabler/icons-webfont/dist/tabler-icons.min.css'
import '@/assets/css/app.css'
import './assets/theme.css'
import '@tabler/core/dist/js/tabler.min.js'
import './composables/useTheme' // Auto-applies saved theme on load
import VueApexCharts from 'vue3-apexcharts'

const app = createApp(App)
const pinia = createPinia()
pinia.use(piniaPersist)

app.use(pinia)
app.use(router)
app.use(VueApexCharts)
app.mount('#app')
