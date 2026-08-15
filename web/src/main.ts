// 应用入口：挂载 Pinia、Router、Element Plus（中文 locale）与全局样式（设计令牌 + 基础样式）
// 字体自托管（内网离线可用）：Space Grotesk 西文/数字（衡序品牌字体），Fira Code 等宽（单据号/金额）
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import ElementPlus from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import '@fontsource-variable/space-grotesk'
import '@fontsource/fira-code/400.css'
import '@fontsource/fira-code/500.css'
import '@fontsource/fira-code/600.css'
import '@fontsource/fira-code/700.css'
import 'element-plus/dist/index.css'
import './styles/tokens.css'
import './styles/main.css'
import App from './App.vue'
import router from './router'

createApp(App).use(createPinia()).use(router).use(ElementPlus, { locale: zhCn }).mount('#app')
