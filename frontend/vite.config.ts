import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'node:path'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, '.', '')
  return {
  plugins: [react()],
  resolve: { alias: { '@': path.resolve(__dirname, './src') } },
  server: { port: 5173, host: true, proxy: { '/api': { target: env.VITE_BACKEND_URL ?? 'http://127.0.0.1:8000', changeOrigin: true }, '/broadcasting': { target: env.VITE_BACKEND_URL ?? 'http://127.0.0.1:8000', changeOrigin: true } } },
  }
})
