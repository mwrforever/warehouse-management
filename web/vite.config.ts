// Vite 配置：Vue 插件 + 开发代理（/api → 后端 :8000）+ vitest 环境
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  // 开发代理：/api/v1 → 后端 :8000
  server: {
    port: 5173,
    proxy: { '/api': { target: 'http://localhost:8000', changeOrigin: true } },
  },
  test: { environment: 'jsdom' }, // vitest 配置
})
