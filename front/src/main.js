import { createApp } from 'vue'
import { createPinia } from 'pinia'
import persistedState from 'pinia-plugin-persistedstate'
import App from './App.vue'
import router from './router'
import '@fontsource-variable/inter'
import '@fontsource-variable/bricolage-grotesque'
import './styles.css'

const pinia = createPinia()
pinia.use(persistedState)
createApp(App).use(pinia).use(router).mount('#app')
