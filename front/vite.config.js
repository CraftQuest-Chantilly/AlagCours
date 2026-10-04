import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

// Publié sous alaginfo.net/alcours/ ; l'API PHP est servie sous /alcours/api/ (même origine).
// En développement, /alcours/api est relayé vers un serveur PHP local (VITE_API_PROXY).
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    base: '/alcours/',
    plugins: [vue()],
    resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
    server: {
      port: 5175,
      proxy: { '/alcours/api': { target: env.VITE_API_PROXY || 'http://127.0.0.1:8080', changeOrigin: true } }
    },
    build: { outDir: 'dist' }
  }
})
