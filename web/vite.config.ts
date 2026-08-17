// Vite 配置：Vue 插件 + 开发代理（/api → 后端 :7000）+ vitest 环境
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import pkg from './package.json' with { type: 'json' }

export default defineConfig({
  plugins: [vue()],
  // 构建期注入版本号：登录页角标与 package.json 保持同步（类型见 src/env.d.ts），避免发版后展示失真
  define: {
    'import.meta.env.VITE_APP_VERSION': JSON.stringify(pkg.version),
  },
  // 开发代理：/api/v1 → 后端 :7000（127.0.0.1 与后端绑定地址一致，避免 localhost 优先解析 IPv6 ::1 导致连接拒绝）
  server: {
    port: 4000,
    // 端口被占用时直接失败，避免静默漂移（如自动跳到 4001）造成代理/访问错乱
    strictPort: true,
    proxy: { '/api': { target: 'http://127.0.0.1:7000', changeOrigin: true } },
  },
  // vitest 配置：仅收集 src 下单测；e2e/ 下的 playwright 用例由 playwright.config.ts 驱动，避免被默认 include 误收集
  test: {
    environment: 'jsdom',
    include: ['src/**/*.test.ts'],
  },
})
