// ESLint 配置：Vue 3 + TypeScript + Prettier 兼容（flat config）
// 规则分层：JS 推荐 → TS 推荐 → Vue 推荐 → Prettier 冲突关闭
import js from '@eslint/js'
import eslintConfigPrettier from 'eslint-config-prettier'
import pluginVue from 'eslint-plugin-vue'
import tseslint from 'typescript-eslint'

export default tseslint.config(
  // 构建产物与依赖不检查
  { ignores: ['dist/**', 'node_modules/**', 'playwright-report/**', 'test-results/**'] },

  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...pluginVue.configs['flat/recommended'],

  // Vue 单文件组件使用 TS 解析器
  {
    files: ['**/*.vue'],
    languageOptions: {
      parserOptions: { parser: tseslint.parser },
    },
  },

  // 关闭与 Prettier 冲突的格式类规则（格式统一交给 Prettier）
  eslintConfigPrettier,
)
