<!-- 工艺路线画布编辑/查看子页面（原弹窗改版）：顶栏返回列表 + 画布编辑体；
     路由参数经 query 传递：routing_id（缺省=新建）、mode=view（只读详情）。
     保存/取消后返回列表，列表页 onMounted 重新拉取即得最新数据 -->
<template>
  <div class="page-card">
    <div class="view-bar">
      <el-button link class="back-btn" @click="goList">
        <el-icon><ArrowLeft /></el-icon>
        返回列表
      </el-button>
    </div>
    <RoutingCanvasEditor
      :routing-id="routingId"
      :readonly="readonly"
      @saved="goList"
      @cancel="goList"
    />
  </div>
</template>

<script setup lang="ts">
// 画布子页面：只解析路由参数并编排画布编辑体；页内是否可编辑按 mode + 权限码二次收紧
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft } from '@element-plus/icons-vue'
import RoutingCanvasEditor from '../../components/routing/RoutingCanvasEditor.vue'
import { useAuthStore } from '../../stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

// routing_id 缺失或非法时按新建处理（新建路径不依赖 id）
const routingId = computed<number | null>(() => {
  const id = Number(route.query.routing_id)
  return Number.isFinite(id) && id > 0 ? id : null
})
// 仅 mode=view 为只读；其余（缺省/其他值）走编辑
const readonly = computed(() => route.query.mode === 'view')

// 越权防守：路由 meta 只保证 routing.list 可进页，读详情无需 update/create，
// 但新建/编辑需对应权限——URL 直捣画布页时在此拦截跳 403（后端 update/create 接口仍有权限中间件兜底）
if (!readonly.value) {
  const need = routingId.value ? 'routing.update' : 'routing.create'
  if (!auth.has(need)) {
    void router.replace('/403')
  }
}

function goList() {
  void router.push('/master/routings')
}
</script>

<style scoped>
.page-card {
  background: var(--surface);
  border-radius: 8px;
  box-shadow: var(--shadow-sm);
  padding: var(--space-2xl);
}
.view-bar {
  display: flex;
  align-items: center;
  margin-bottom: var(--space-lg);
}
.back-btn {
  color: var(--color-foreground);
  font-size: 14px;
  gap: 4px;
}
</style>
