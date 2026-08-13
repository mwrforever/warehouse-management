<!-- 仪表盘页：登录默认落地页——4 KPI 卡 + 待审核列表 + 工单进度 + 库存预警
     4 接口并行独立加载：单区失败只影响该区（骨架屏换重 试），其余照常渲染（spec §7 并行容错） -->
<template>
  <div class="page-card dashboard">
    <!-- KPI 卡区：一行 4 张（待审核卡仅对持有审核权限者显示，TC-DSH-07） -->
    <div class="kpi-grid">
      <template v-if="summary.loading">
        <el-skeleton v-for="i in 4" :key="i" class="kpi-card" :rows="2" animated />
      </template>
      <div v-else-if="summary.error" class="kpi-card">
        <div class="section-error">
          <span class="section-error-text">KPI 数据加载失败</span>
          <el-button size="small" @click="loadSummary">重 试</el-button>
        </div>
      </div>
      <template v-else-if="summary.data">
        <div class="kpi-card">
          <div class="kpi-label">库存总量</div>
          <div class="kpi-value font-code">
            {{ formatThousand(summary.data.inventory_total_qty) }}
          </div>
          <div v-if="summary.data.inventory_value === null" class="kpi-sub">未启用成本核算</div>
          <div v-else class="kpi-sub">
            库存总值 ¥{{ formatThousand(summary.data.inventory_value) }}
          </div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">今日入库</div>
          <div class="kpi-value kpi-in font-code">
            +{{ formatThousand(summary.data.today_inbound_qty) }}
          </div>
          <div class="kpi-sub">出库 Σ{{ formatThousand(summary.data.today_outbound_qty) }}</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-label">今日出库</div>
          <div class="kpi-value kpi-out font-code">
            -{{ formatThousand(summary.data.today_outbound_qty) }}
          </div>
        </div>
        <div v-if="canApprove" class="kpi-card kpi-clickable" @click="scrollToPending">
          <div class="kpi-label">待审核单据</div>
          <div class="kpi-value kpi-warn font-code">{{ summary.data.pending_approvals }}</div>
        </div>
      </template>
    </div>

    <!-- 中部双栏：左 2/3 待审核列表、右 1/3 工单进度 -->
    <div class="dash-grid">
      <section v-if="canApprove" id="pending-panel" ref="pendingPanel" class="panel">
        <h3 class="panel-title">待审核单据</h3>
        <el-skeleton v-if="pending.loading" :rows="4" animated />
        <div v-else-if="pending.error" class="section-error">
          <span class="section-error-text">待审核单据加载失败</span>
          <el-button size="small" @click="loadPending">重 试</el-button>
        </div>
        <template v-else-if="pending.data">
          <div v-if="pending.data.items.length === 0" class="empty-ok">
            <el-icon class="ok-icon" color="#059669"><Check /></el-icon>
            <span>全部单据已审核 ✓</span>
          </div>
          <template v-else>
            <div v-for="group in pendingGroups" :key="group.module" class="pending-group">
              <div class="pending-tag">{{ group.module }}</div>
              <div
                v-for="row in group.items"
                :key="row.no"
                class="pending-row"
                @click="go(row.url)"
              >
                <span class="type-tag">{{ row.type }}</span>
                <span class="font-code pending-no">{{ row.no }}</span>
                <span class="pending-time">{{ row.created_at }}</span>
                <el-icon class="row-arrow"><ArrowRight /></el-icon>
              </div>
            </div>
          </template>
        </template>
      </section>

      <section class="panel">
        <h3 class="panel-title">
          工单进度
          <el-tag v-if="summary.data" size="small" type="warning" class="title-badge"
            >生产中 {{ summary.data.work_order_running }}</el-tag
          >
        </h3>
        <el-skeleton v-if="progress.loading" :rows="3" animated />
        <div v-else-if="progress.error" class="section-error">
          <span class="section-error-text">工单进度加载失败</span>
          <el-button size="small" @click="loadProgress">重 试</el-button>
        </div>
        <template v-else-if="progress.data">
          <el-empty
            v-if="progress.data.items.length === 0"
            description="暂无进行中工单"
            :image-size="60"
          />
          <div
            v-for="row in progress.data.items"
            v-else
            :key="row.no"
            class="order-row"
            @click="go('/production/orders')"
          >
            <div class="order-head">
              <span class="font-code">{{ row.no }}</span>
              <el-tag size="small" :type="row.status === 3 ? 'success' : 'warning'">{{
                row.status_label
              }}</el-tag>
            </div>
            <div class="order-name">{{ row.product_name }}</div>
            <div class="order-progress">
              <el-progress
                :percentage="Number(row.progress)"
                :stroke-width="8"
                :show-text="false"
              />
              <span class="font-code progress-text">{{ row.progress }}%</span>
            </div>
          </div>
        </template>
      </section>
    </div>

    <!-- 底部：库存预警（低库存前 10，与库存预警页同口径） -->
    <section class="panel">
      <h3 class="panel-title">
        库存预警
        <el-tag v-if="summary.data" size="small" type="danger" class="title-badge">{{
          summary.data.alert_count
        }}</el-tag>
      </h3>
      <el-skeleton v-if="alerts.loading" :rows="2" animated />
      <div v-else-if="alerts.error" class="section-error">
        <span class="section-error-text">库存预警加载失败</span>
        <el-button size="small" @click="loadAlerts">重 试</el-button>
      </div>
      <template v-else-if="alerts.data">
        <div v-if="alerts.data.items.length === 0" class="empty-ok"><span>库存状态正常</span></div>
        <div v-else class="alert-grid">
          <div
            v-for="(row, idx) in alerts.data.items"
            :key="idx"
            class="alert-card"
            @click="go('/inventory/alerts')"
          >
            <div class="alert-name">
              {{ row.product_name }}
              <span class="font-code alert-code">{{ row.product_code }}</span>
            </div>
            <div class="alert-wh">{{ row.warehouse_name }}</div>
            <div class="alert-nums font-code">
              当前 {{ formatThousand(row.quantity) }} / 下限 {{ formatThousand(row.safety_min) }}
            </div>
          </div>
        </div>
      </template>
    </section>
  </div>
</template>

<script setup lang="ts">
/* global HTMLElement */
// 仪表盘：4 区独立加载状态（loading/error/data），挂载并行请求 4 接口；无手动刷新按钮（V1）
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowRight, Check } from '@element-plus/icons-vue'
import {
  dashboardApi,
  type DashboardAlertItem,
  type DashboardSummary,
  type PendingApprovalItem,
  type WorkOrderProgressItem,
} from '../api/dashboard'
import { useAuthStore } from '../stores/auth'
import { formatThousand } from '../utils/format'

const auth = useAuthStore()
const router = useRouter()

// 各区独立状态：loading 骨架 / error 失败重试 / data 数据——单区失败不影响其余（spec §7）
const summary = reactive<{ loading: boolean; error: boolean; data: DashboardSummary | null }>({
  loading: true,
  error: false,
  data: null,
})
const pending = reactive<{
  loading: boolean
  error: boolean
  data: { items: PendingApprovalItem[] } | null
}>({
  loading: true,
  error: false,
  data: null,
})
const progress = reactive<{
  loading: boolean
  error: boolean
  data: { items: WorkOrderProgressItem[] } | null
}>({
  loading: true,
  error: false,
  data: null,
})
const alerts = reactive<{
  loading: boolean
  error: boolean
  data: { items: DashboardAlertItem[] } | null
}>({
  loading: true,
  error: false,
  data: null,
})

// 各模块审核权限码（审核复用 update；无任一审核权限 → 隐藏待审核卡与区块，TC-DSH-07）
const APPROVE_PERMISSIONS = [
  'purchase.order.update',
  'purchase.inbound.update',
  'sales.order.update',
  'sales.outbound.update',
  'check.update',
  'production.pick.update',
  'production.return.update',
  'production.outsource.update',
  'production.finished.update',
]
const canApprove = computed(() => APPROVE_PERMISSIONS.some((p) => auth.has(p)))

// 路由白名单：仅放行后端下发的已知模块路径（spec §5 白名单契约）
const ALLOWED_PATHS = [
  '/purchase/orders',
  '/purchase/inbounds',
  '/sales/orders',
  '/sales/outbounds',
  '/inventory/checks',
  '/inventory/alerts',
  '/production/orders',
  '/production/picks',
  '/production/returns',
  '/production/outsourcings',
  '/production/finished-inbounds',
]

const pendingPanel = ref<HTMLElement | null>(null)

async function loadSummary() {
  summary.loading = true
  summary.error = false
  try {
    summary.data = await dashboardApi.summary()
  } catch {
    // 单区失败：仅本区转错误态（骨架换重试按钮），其余区不受影响
    summary.error = true
  } finally {
    summary.loading = false
  }
}

async function loadPending() {
  pending.loading = true
  pending.error = false
  try {
    pending.data = await dashboardApi.pendingApprovals()
  } catch {
    pending.error = true
  } finally {
    pending.loading = false
  }
}

async function loadProgress() {
  progress.loading = true
  progress.error = false
  try {
    progress.data = await dashboardApi.workOrderProgress()
  } catch {
    progress.error = true
  } finally {
    progress.loading = false
  }
}

async function loadAlerts() {
  alerts.loading = true
  alerts.error = false
  try {
    alerts.data = await dashboardApi.alerts()
  } catch {
    alerts.error = true
  } finally {
    alerts.loading = false
  }
}

// 待审核列表按模块分组（Map 保持模块首次出现序；组内行保持全局倒序）
const pendingGroups = computed(() => {
  const items = pending.data?.items ?? []
  const map = new Map<string, PendingApprovalItem[]>()
  for (const row of items) {
    const list = map.get(row.module)
    if (list) {
      list.push(row)
    } else {
      map.set(row.module, [row])
    }
  }
  return [...map.entries()].map(([module, items]) => ({ module, items }))
})

// 白名单跳转：后端下发的路由仅允许已知模块路径
function go(url: string) {
  if (!ALLOWED_PATHS.includes(url)) return
  router.push(url)
}

// 待审核 KPI 卡点击 → 平滑滚动到待审核区（spec §4 卡片联动）
function scrollToPending() {
  pendingPanel.value?.scrollIntoView({ behavior: 'smooth' })
}

// 挂载即并行发起 4 区请求（各自独立 catch，互不影响）
onMounted(() => {
  loadSummary()
  loadPending()
  loadProgress()
  loadAlerts()
})
</script>

<style scoped>
/* 样式遵循 design-system/nexus-factory/pages/dashboard.md（KPI 卡/双栏/预警卡/空态） */
.dashboard {
  display: flex;
  flex-direction: column;
  gap: var(--space-2xl);
}
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: var(--space-xl);
}
.kpi-card {
  background: #fff;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: var(--space-xl);
  box-shadow: var(--shadow-sm);
  transition: box-shadow 200ms ease;
}
.kpi-clickable {
  cursor: pointer;
}
.kpi-clickable:hover {
  box-shadow: var(--shadow-md);
}
.kpi-label {
  font-size: 12px;
  color: #64748b;
  margin-bottom: var(--space-md);
}
.kpi-value {
  font-size: 24px;
  font-weight: 700;
  color: var(--color-foreground);
}
.kpi-in {
  color: #059669;
}
.kpi-out {
  color: #dc2626;
}
.kpi-warn {
  color: #d97706;
}
.kpi-sub {
  font-size: 12px;
  color: #64748b;
  margin-top: var(--space-md);
}
.dash-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: var(--space-2xl);
}
.panel {
  background: #fff;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: var(--space-xl);
}
.panel-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--color-foreground);
  margin: 0 0 var(--space-xl);
}
.title-badge {
  margin-left: var(--space-md);
}
.section-error {
  display: flex;
  align-items: center;
  gap: var(--space-lg);
  padding: var(--space-md) 0;
}
.section-error-text {
  font-size: 13px;
  color: #64748b;
}
.empty-ok {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  color: #059669;
  padding: var(--space-lg) 0;
}
.ok-icon {
  font-size: 18px;
}
.pending-group {
  margin-bottom: var(--space-lg);
}
.pending-tag {
  display: inline-block;
  font-size: 12px;
  color: #475569;
  background: var(--color-muted);
  border-radius: 4px;
  padding: 2px 8px;
  margin-bottom: var(--space-md);
}
.pending-row {
  display: flex;
  align-items: center;
  gap: var(--space-lg);
  padding: var(--space-md) var(--space-sm);
  border-radius: 6px;
  cursor: pointer;
  transition: background 150ms ease;
}
.pending-row:hover {
  background: #f8fafc;
}
.type-tag {
  font-size: 12px;
  color: #334155;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  padding: 1px 6px;
  white-space: nowrap;
}
.pending-no {
  font-size: 13px;
  color: var(--color-foreground);
}
.pending-time {
  flex: 1;
  text-align: right;
  font-size: 12px;
  color: #94a3b8;
}
.row-arrow {
  color: #94a3b8;
}
.order-row {
  border-top: 1px solid var(--color-border);
  padding: var(--space-lg) 0;
  cursor: pointer;
  transition: background 150ms ease;
}
.order-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--space-sm);
}
.order-name {
  font-size: 13px;
  color: #475569;
  margin-bottom: var(--space-md);
}
.order-progress {
  display: flex;
  align-items: center;
  gap: var(--space-md);
}
.order-progress .el-progress {
  flex: 1;
}
.progress-text {
  font-size: 12px;
  color: var(--color-foreground);
  min-width: 56px;
  text-align: right;
}
.alert-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: var(--space-lg);
}
.alert-card {
  border: 1px solid #fecaca;
  border-left: 4px solid #dc2626;
  background: #fef2f2;
  border-radius: 8px;
  padding: var(--space-lg);
  cursor: pointer;
  transition: box-shadow 150ms ease;
}
.alert-card:hover {
  box-shadow: var(--shadow-md);
}
.alert-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--color-foreground);
}
.alert-code {
  color: #64748b;
  font-size: 12px;
}
.alert-wh {
  font-size: 12px;
  color: #64748b;
  margin: var(--space-sm) 0;
}
.alert-nums {
  font-size: 12px;
  color: #dc2626;
}
</style>
