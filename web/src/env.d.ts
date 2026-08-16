// 构建期环境变量类型声明（与 vite.config.ts 的 define 注入配套）：
// VITE_APP_VERSION 在构建/测试时注入 package.json 版本号，登录页角标使用，发版后自动同步
interface ImportMetaEnv {
  readonly VITE_APP_VERSION: string
}
