<!-- 登录页：全系统唯一免登录页面，居中卡片 -->
<template>
  <div class="login-page">
    <el-card class="login-card">
      <h1 class="font-code">Nexus Factory</h1>
      <el-form :model="form" :rules="rules" ref="formRef" @keyup.enter="submit">
        <el-form-item prop="username">
          <el-input v-model="form.username" placeholder="请输入用户名" />
        </el-form-item>
        <el-form-item prop="password">
          <el-input v-model="form.password" type="password" placeholder="请输入密码" show-password />
        </el-form-item>
        <el-button type="primary" class="login-btn" :loading="loading" @click="submit">登 录</el-button>
      </el-form>
    </el-card>
  </div>
</template>

<script setup lang="ts">
// 登录页：表单校验 + 调 auth store 登录 + 成功跳仪表盘
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
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
/* 居中卡片 + 库存绿主按钮（btn-primary 语义） */
.login-page { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--color-background); }
.login-card { width: 400px; padding: var(--space-3xl); }
.login-btn { width: 100%; background: var(--color-accent); cursor: pointer; }
</style>
