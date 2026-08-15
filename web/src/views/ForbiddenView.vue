<!-- 403 无权限页：权限不足时由路由守卫跳转至此 -->
<template>
  <div class="forbidden">
    <h1>403</h1>
    <p>抱歉，您没有访问该页面的权限</p>
    <el-button type="primary" @click="router.push('/dashboard')">返回仪表盘</el-button>
  </div>
</template>

<script setup lang="ts">
// 无权限页：提供返回仪表盘入口
import { useRouter } from 'vue-router'

const router = useRouter()
</script>

<style scoped>
.forbidden {
  text-align: center;
  padding-top: 20vh;
}
.forbidden h1 {
  font-family: var(--font-mono);
  font-size: 56px;
  font-weight: 700;
  letter-spacing: 2px;
  color: var(--p-700);
  margin-bottom: var(--space-lg);
}
.forbidden p {
  color: var(--t2);
  margin-bottom: var(--space-2xl);
}
</style>
