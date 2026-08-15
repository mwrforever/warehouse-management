<!-- 登录页：左右分屏（左品牌氛围区 + 右登录表单），全系统唯一免登录页面
     对照 docs/design/ui-redesign-mockup.html 视图一；占位符/按钮文案与 E2E 选择器保持一致 -->
<template>
  <div class="login-page">
    <!-- 左：品牌氛围区（深色工业风，网格纹理 + 光晕） -->
    <aside class="login-hero">
      <div class="brand-logo">
        <span class="brand-mark"
          ><el-icon><Box /></el-icon
        ></span>
        <span class="brand-txt">
          <b>衡序 <em class="brand-en">HENGXU</em></b>
          <span>智能仓储与生产管理平台</span>
        </span>
      </div>

      <div class="hero-main">
        <span class="hero-kicker">智能制造 · 精细管控</span>
        <h1 class="hero-title">让每一件物料<br />都有<em>迹</em>可循</h1>
        <p class="hero-desc">
          进销存与生产一体化的智能管理平台，覆盖采购、销售、生产、仓储全链路，以数据驱动工厂运营决策。
        </p>
        <div class="hero-feats">
          <div class="hero-feat">
            <el-icon><Box /></el-icon>
            <div><b>库存实时</b><span>多仓余额、批次效期、低库存预警联动</span></div>
          </div>
          <div class="hero-feat">
            <el-icon><SetUp /></el-icon>
            <div><b>生产协同</b><span>BOM、工单、领退料、委外加工全流程</span></div>
          </div>
          <div class="hero-feat">
            <el-icon><TrendCharts /></el-icon>
            <div><b>数据报表</b><span>出入库汇总与产销统计一屏尽览</span></div>
          </div>
        </div>
      </div>

      <div class="hero-foot">
        <span>© 2026 衡序智造 HENGXU</span>
        <span class="num">v1.0.0 · build 2026.08.14</span>
      </div>
    </aside>

    <!-- 右：登录表单区 -->
    <div class="login-form">
      <div class="login-panel">
        <div class="login-head">
          <h1>欢迎回来</h1>
          <p>请使用管理员分配的账号登录系统</p>
        </div>

        <div class="login-form-card">
          <el-form ref="formRef" :model="form" :rules="rules" @keyup.enter="submit">
            <el-form-item prop="username">
              <label class="field-label" for="login-username">用户名</label>
              <el-input
                id="login-username"
                v-model="form.username"
                placeholder="请输入用户名"
                autocomplete="username"
              >
                <template #prefix
                  ><el-icon><User /></el-icon
                ></template>
              </el-input>
            </el-form-item>
            <el-form-item prop="password">
              <label class="field-label" for="login-password">密码</label>
              <el-input
                id="login-password"
                v-model="form.password"
                type="password"
                placeholder="请输入密码"
                autocomplete="current-password"
                show-password
              >
                <template #prefix
                  ><el-icon><Lock /></el-icon
                ></template>
              </el-input>
            </el-form-item>
            <el-button type="primary" class="login-btn" :loading="loading" @click="submit">
              登 录
            </el-button>
          </el-form>

          <div class="login-divider">演示环境</div>
          <div class="demo-hint">
            <el-icon class="hint-ic"><MagicStick /></el-icon>
            <span
              >演示账号：<code>admin / admin123</code
              >，生产环境请使用<code>管理员</code>分配的专属账号，密码策略见安全规范。</span
            >
          </div>
        </div>

        <p class="login-foot">遇到问题请联系系统管理员 · 支持 IE 11 以外的现代浏览器</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
// 登录页：表单校验 + 调 auth store 登录 + 成功跳仪表盘（逻辑与旧版一致，仅视觉重设计）
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Box, Lock, MagicStick, SetUp, TrendCharts, User } from '@element-plus/icons-vue'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()
const formRef = ref<FormInstance>()
const loading = ref(false)

const form = reactive({ username: '', password: '' })

// 表单校验规则：必填（空表单点击登录被拦截，不发请求）
const rules: FormRules = {
  username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
  password: [{ required: true, message: '请输入密码', trigger: 'blur' }],
}

// 登录：校验通过 → auth.login → 跳仪表盘；失败显示后端 message
async function submit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return
  loading.value = true
  try {
    await auth.login(form.username, form.password)
    router.push('/dashboard')
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
/* 左右分屏布局：左 46% 深色氛围区 + 右表单区 */
.login-page {
  display: flex;
  min-height: 100vh;
  background: var(--surface);
}

/* ===== 左：品牌氛围区 ===== */
.login-hero {
  position: relative;
  flex: 0 0 46%;
  overflow: hidden;
  background: linear-gradient(160deg, #0b1220 0%, var(--p-800) 55%, #16283f 100%);
  color: #fff;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 56px 56px 40px;
}

/* 细网格纹理 + 光晕：营造"精密车间"氛围 */
.login-hero::before {
  content: '';
  position: absolute;
  inset: 0;
  pointer-events: none;
  background-image:
    linear-gradient(rgba(255, 255, 255, 0.035) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255, 255, 255, 0.035) 1px, transparent 1px);
  background-size: 44px 44px;
  mask-image: radial-gradient(120% 90% at 30% 20%, #000 55%, transparent 100%);
}
.login-hero::after {
  content: '';
  position: absolute;
  width: 560px;
  height: 560px;
  right: -180px;
  top: -160px;
  background: radial-gradient(circle, rgba(16, 185, 129, 0.12) 0%, transparent 62%);
  animation: hero-glow 14s ease-in-out infinite alternate;
}
@keyframes hero-glow {
  from {
    transform: translate(0, 0) scale(1);
  }
  to {
    transform: translate(-46px, 34px) scale(1.14);
  }
}

.brand-logo {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  position: relative;
  z-index: 1;
}
.brand-mark {
  width: 40px;
  height: 40px;
  border-radius: 11px;
  display: grid;
  place-items: center;
  background: linear-gradient(135deg, var(--a-500), var(--a-700));
  color: #fff;
  box-shadow: 0 8px 20px rgba(5, 150, 105, 0.35);
}
.brand-mark .el-icon {
  font-size: 22px;
}
.brand-txt b {
  display: block;
  font-size: 19px;
  font-weight: 700;
  letter-spacing: 0.3px;
}
.brand-en {
  font-family: var(--font-mono);
  font-style: normal;
  font-weight: 600;
  letter-spacing: 1px;
}
.brand-txt span {
  display: block;
  margin-top: 2px;
  font-size: 11.5px;
  color: rgba(255, 255, 255, 0.55);
  letter-spacing: 1.6px;
  text-transform: uppercase;
}

.hero-main {
  position: relative;
  z-index: 1;
  max-width: 520px;
}
.hero-kicker {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: var(--a-300);
  background: rgba(16, 185, 129, 0.12);
  border: 1px solid rgba(16, 185, 129, 0.28);
  padding: 6px 14px;
  border-radius: var(--r-full);
  margin-bottom: 22px;
  letter-spacing: 0.5px;
}
.hero-kicker::before {
  content: '';
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--a-400);
  box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25);
}
.hero-title {
  font-size: 38px;
  font-weight: 800;
  line-height: 1.25;
  letter-spacing: 0.5px;
}
.hero-title em {
  font-style: normal;
  color: var(--a-400);
}
.hero-desc {
  margin-top: 14px;
  font-size: 15px;
  line-height: 1.8;
  color: rgba(255, 255, 255, 0.62);
}
.hero-feats {
  margin-top: 36px;
  max-width: 480px;
}
.hero-feat {
  display: flex;
  gap: 16px;
  align-items: flex-start;
  padding: 15px 4px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  transition:
    background 0.25s,
    padding-left 0.25s;
}
.hero-feat:last-child {
  border-bottom: none;
}
.hero-feat:hover {
  background: rgba(255, 255, 255, 0.045);
  padding-left: 12px;
  border-radius: 10px;
}
.hero-feat .el-icon {
  font-size: 20px;
  color: var(--a-400);
  margin-top: 2px;
}
.hero-feat b {
  display: block;
  font-size: 14px;
  font-weight: 600;
  letter-spacing: 0.3px;
}
.hero-feat span {
  display: block;
  margin-top: 4px;
  font-size: 12.5px;
  line-height: 1.65;
  color: rgba(255, 255, 255, 0.55);
}
.hero-foot {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 12px;
  color: rgba(255, 255, 255, 0.5);
}
.hero-foot .num {
  color: rgba(255, 255, 255, 0.65);
}

/* ===== 右：登录表单区 ===== */
.login-form {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 48px;
  background: radial-gradient(60% 50% at 85% 8%, rgba(5, 150, 105, 0.05), transparent 60%);
}
.login-panel {
  width: 100%;
  max-width: 400px;
}
.login-head h1 {
  font-size: 26px;
  font-weight: 700;
  letter-spacing: 0.2px;
}
.login-head p {
  margin-top: 8px;
  font-size: 14px;
  color: var(--t2);
}
.login-form-card {
  margin-top: 32px;
}
.field-label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 8px;
  color: var(--t1);
}
/* 输入框高度按设计稿 44px，聚焦绿环已在 main.css 统一 */
.login-form-card .el-input__wrapper {
  padding: 6px 14px;
}
.login-form-card .el-input__prefix {
  color: var(--t3);
  font-size: 17px;
}
.login-btn {
  width: 100%;
  height: 44px;
  font-size: 15px;
  letter-spacing: 2px;
}
.login-divider {
  display: flex;
  align-items: center;
  gap: 14px;
  margin: 26px 0 18px;
  color: var(--t3);
  font-size: 12px;
}
.login-divider::before,
.login-divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--border);
}
.demo-hint {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  font-size: 12.5px;
  line-height: 1.7;
  color: var(--t2);
  background: var(--p-100);
  border: 1px dashed var(--border-strong);
  border-radius: var(--r-md);
  padding: 12px 14px;
}
.hint-ic {
  font-size: 15px;
  color: var(--a-600);
  margin-top: 2px;
}
.demo-hint code {
  font-family: var(--font-mono);
  font-size: 12px;
  color: var(--p-700);
  background: var(--surface);
  padding: 1px 6px;
  border-radius: 4px;
}
.login-foot {
  margin-top: 34px;
  font-size: 12px;
  color: var(--t3);
  text-align: center;
}

/* ===== 入场动效：左右区级联（MOTION 6/10，reduced-motion 由 main.css 全局门控） ===== */
.login-hero .brand-logo {
  animation: hx-fade-up 0.55s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.login-hero .hero-kicker {
  animation: hx-fade-up 0.5s 0.1s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.login-hero .hero-title {
  animation: hx-fade-up 0.6s 0.18s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.login-hero .hero-desc {
  animation: hx-fade-up 0.6s 0.26s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.login-hero .hero-feats {
  animation: hx-fade-up 0.6s 0.36s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.login-hero .hero-foot {
  animation: hx-fade-up 0.5s 0.52s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.login-head {
  animation: hx-fade-up 0.6s 0.16s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.login-form-card {
  animation: hx-fade-up 0.65s 0.28s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.login-divider,
.demo-hint {
  animation: hx-fade-up 0.6s 0.42s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.login-foot {
  animation: hx-fade-up 0.5s 0.54s cubic-bezier(0.16, 1, 0.3, 1) both;
}

/* 窄屏（<960px）：氛围区收起，仅保留表单 */
@media (max-width: 959px) {
  .login-hero {
    display: none;
  }
}
</style>
