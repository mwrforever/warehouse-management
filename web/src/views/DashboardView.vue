<!-- 仪表盘页：登录默认落地页——非对称 KPI 卡 + 待审核列表 + 工单进度 + 库存预警
     对照 docs/design/ui-redesign-mockup.html 视图二（KPI 非对称 2fr/1fr/1fr/1fr + 图标角标）；
     4 接口并行独立加载逻辑与全部 E2E 类名保持不变（TC-DSH-01~08） -->
<template>
  <div class="page-card dashboard">
    <!-- 页面标题：日期副标题（真实系统日期，非占位文案） -->
    <div class="page-head">
      <div>
        <h2>仪表盘</h2>
        <p>{{ todayText }}</p>
      </div>
    </div>

    <!-- KPI 卡区：首卡 2fr 加宽（待审核卡仅对持有审核权限者显示，TC-DSH-07） -->
    <div class="kpi-grid" :class="{ 'kpi-4': canApprove }">
      <template v-if="summary.loading">
        <el-skeleton v-for="i in canApprove ? 4 : 3" :key="i" class="kpi-card" :rows="2" animated />
      </template>
      <div v-else-if="summary.error" class="kpi-card">
        <div class="section-error">
          <span class="section-error-text">KPI 数据加载失败</span>
          <el-button size="small" @click="loadSummary">重 试</el-button>
        </div>
      </div>
      <template v-else-if="summary.data">
        <div class="kpi-card big">
          <div class="kpi-top">
            <span class="kpi-ic green"
              ><el-icon><Box /></el-icon
            ></span>
          </div>
          <div class="kpi-label">库存总量</div>
          <div ref="kpiVal1" class="kpi-value num">0</div>
          <div v-if="summary.data.inventory_value === null" class="kpi-sub">未启用成本核算</div>
          <div v-else class="kpi-sub">
            库存总值 ¥{{ formatThousand(summary.data.inventory_value) }}
          </div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top">
            <span class="kpi-ic blue"
              ><el-icon><Download /></el-icon
            ></span>
          </div>
          <div class="kpi-label">今日入库</div>
          <div ref="kpiVal2" class="kpi-value kpi-in num">0</div>
          <div class="kpi-sub">出库 Σ{{ formatThousand(summary.data.today_outbound_qty) }}</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-top">
            <span class="kpi-ic amber"
              ><el-icon><Upload /></el-icon
            ></span>
          </div>
          <div class="kpi-label">今日出库</div>
          <div ref="kpiVal3" class="kpi-value kpi-out num">0</div>
        </div>
        <div v-if="canApprove" class="kpi-card kpi-clickable" @click="scrollToPending">
          <div class="kpi-top">
            <span class="kpi-ic red"
              ><el-icon><DocumentChecked /></el-icon
            ></span>
          </div>
          <div class="kpi-label">待审核单据</div>
          <div ref="kpiVal4" class="kpi-value kpi-warn num">0</div>
          <div class="kpi-sub">点击查看待审核明细</div>
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
                <span class="pending-time font-code">{{ row.created_at }}</span>
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
/* global HTMLElement, window, performance, requestAnimationFrame */
// 仪表盘：4 区独立加载状态（loading/error/data），挂载并行请求 4 接口；无手动刷新按钮（V1）
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowRight, Box, Check, DocumentChecked, Download, Upload } from '@element-plus/icons-vue'
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

// ===== KPI 数字滚动：一次性 rAF（650ms easeOutCubic），尊重系统减弱动效设置 =====
const kpiVal1 = ref<HTMLElement | null>(null)
const kpiVal2 = ref<HTMLElement | null>(null)
const kpiVal3 = ref<HTMLElement | null>(null)
const kpiVal4 = ref<HTMLElement | null>(null)

function startCount(el: HTMLElement | null, target: number, fmt: (n: number) => string) {
  if (!el) return
  // 环境守卫：jsdom 单元测试无 matchMedia（动画不可测）时直接写终值保证确定性；
  // 浏览器端尊重系统减弱动效设置
  const hasMatchMedia = typeof window.matchMedia === 'function'
  const reduce = hasMatchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches
  if (!hasMatchMedia || reduce || typeof requestAnimationFrame !== 'function') {
    el.textContent = fmt(target)
    return
  }
  const t0 = performance.now()
  const dur = 650
  const tick = (t: number) => {
    const p = Math.min(1, (t - t0) / dur)
    const e = 1 - Math.pow(1 - p, 3)
    el.textContent = fmt(target * e)
    if (p < 1) requestAnimationFrame(tick)
  }
  requestAnimationFrame(tick)
}

// 数据到达后启动 4 个 KPI 滚动（终值与接口数据一致，E2E 文本断言可轮询等待）
watch(
  () => summary.data,
  (d) => {
    if (!d) return
    nextTick(() => {
      startCount(kpiVal1.value, Number(d.inventory_total_qty), formatThousand)
      startCount(kpiVal2.value, Number(d.today_inbound_qty), (n) => `+${formatThousand(n)}`)
      startCount(kpiVal3.value, Number(d.today_outbound_qty), (n) => `-${formatThousand(n)}`)
      startCount(kpiVal4.value, Number(d.pending_approvals), (n) => String(Math.round(n)))
    })
  },
)

// 页面头部日期：真实系统日期与星期
const todayText = computed(() => {
  const d = new Date()
  const week = ['日', '一', '二', '三', '四', '五', '六'][d.getDay()]
  return `今天是 ${d.getFullYear()} 年 ${d.getMonth() + 1} 月 ${d.getDate()} 日，星期${week}`
})

// 挂载即并行发起 4 区请求（各自独立 catch，互不影响）
onMounted(() => {
  loadSummary()
  loadPending()
  loadProgress()
  loadAlerts()
})
</script>

<style scoped>
/* 样式对照设计稿视图二：KPI 非对称 + 面板卡片 + 预警卡 */
.dashboard {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.page-head {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
}
.page-head h2 {
  font-size: 21px;
  font-weight: 700;
  letter-spacing: 0.2px;
}
.page-head p {
  margin-top: 5px;
  font-size: 13px;
  color: var(--t2);
}

/* KPI 卡：非对称布局（首卡 2fr 加宽），hover 微抬升 */
.kpi-grid {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr;
  gap: 16px;
}
.kpi-grid.kpi-4 {
  grid-template-columns: 2fr 1fr 1fr 1fr;
}
.kpi-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  padding: 18px 20px;
  box-shadow: var(--sh-sm);
  transition:
    transform 0.2s ease,
    box-shadow 0.2s ease;
  min-width: 0;
}
.kpi-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--sh-md);
}
.kpi-card.big .kpi-value {
  font-size: 30px;
}
.kpi-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.kpi-ic {
  width: 40px;
  height: 40px;
  border-radius: 11px;
  display: grid;
  place-items: center;
  font-size: 20px;
}
.kpi-ic.green {
  background: var(--a-100);
  color: var(--a-700);
}
.kpi-ic.blue {
  background: var(--info-bg);
  color: var(--info);
}
.kpi-ic.amber {
  background: var(--warn-bg);
  color: var(--warn);
}
.kpi-ic.red {
  background: var(--err-bg);
  color: var(--err);
}
.kpi-label {
  margin-top: 14px;
  font-size: 13px;
  color: var(--t2);
}
.kpi-value {
  margin-top: 5px;
  font-size: 25px;
  font-weight: 700;
  letter-spacing: -0.5px;
}
.kpi-in {
  color: var(--a-600);
}
.kpi-out {
  color: var(--err);
}
.kpi-warn {
  color: var(--warn);
}
.kpi-sub {
  margin-top: 4px;
  font-size: 12px;
  color: var(--t3);
}
.kpi-clickable {
  cursor: pointer;
}

/* 中部双栏：待审核 + 工单进度 */
.dash-grid {
  display: grid;
  grid-template-columns: 1.6fr 1fr;
  gap: 16px;
}
.panel {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--sh-sm);
  padding: 18px 20px;
}
.panel-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--t1);
  margin: 0 0 16px;
}
.title-badge {
  margin-left: 8px;
}

/* 区级错误/空态 */
.section-error {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 0;
}
.section-error-text {
  font-size: 13px;
  color: var(--t2);
}
.empty-ok {
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--a-600);
  padding: 12px 0;
}
.ok-icon {
  font-size: 18px;
}

/* 待审核列表 */
.pending-group {
  margin-bottom: 12px;
}
.pending-tag {
  display: inline-block;
  font-size: 12px;
  font-weight: 500;
  color: var(--t2);
  background: var(--p-100);
  border-radius: var(--r-full);
  padding: 2px 10px;
  margin-bottom: 8px;
}
.pending-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 6px;
  border-radius: 8px;
  cursor: pointer;
  transition: background 150ms ease;
}
.pending-row:hover {
  background: var(--p-50);
}
.type-tag {
  font-size: 12px;
  color: var(--p-700);
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  padding: 1px 6px;
  white-space: nowrap;
}
.pending-no {
  font-size: 13px;
  color: var(--t1);
}
.pending-time {
  flex: 1;
  text-align: right;
  font-size: 12px;
  color: var(--t3);
}
.row-arrow {
  color: var(--t3);
}

/* 工单进度 */
.order-row {
  border-top: 1px solid var(--p-100);
  padding: 12px 0;
  cursor: pointer;
  transition: background 150ms ease;
}
.order-row:hover {
  background: var(--p-50);
}
.order-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 4px;
}
.order-name {
  font-size: 13px;
  color: var(--t2);
  margin-bottom: 8px;
}
.order-progress {
  display: flex;
  align-items: center;
  gap: 8px;
}
.order-progress .el-progress {
  flex: 1;
}
.progress-text {
  font-size: 12px;
  color: var(--t1);
  min-width: 56px;
  text-align: right;
}

/* 库存预警卡：危险红语义（与库存预警页同款） */
.alert-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 12px;
}
.alert-card {
  border: 1px solid #fecaca;
  border-left: 4px solid var(--err);
  background: var(--err-bg);
  border-radius: var(--r-md);
  padding: 12px 14px;
  cursor: pointer;
  transition: box-shadow 150ms ease;
}
.alert-card:hover {
  box-shadow: var(--sh-md);
}
.alert-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--t1);
}
.alert-code {
  color: var(--t3);
  font-size: 12px;
  margin-left: 8px;
}
.alert-wh {
  font-size: 12px;
  color: var(--t3);
  margin: 4px 0;
}
.alert-nums {
  font-size: 12px;
  color: var(--err);
}

/* KPI 卡级联入场（与设计稿一致；reduced-motion 由 main.css 全局门控） */
.kpi-card {
  animation: hx-fade-up 0.55s cubic-bezier(0.16, 1, 0.3, 1) both;
}
.kpi-card:nth-child(2) {
  animation-delay: 0.08s;
}
.kpi-card:nth-child(3) {
  animation-delay: 0.16s;
}
.kpi-card:nth-child(4) {
  animation-delay: 0.24s;
}

/* 窄屏：双栏收为单栏 */
@media (max-width: 1100px) {
  .dash-grid,
  .kpi-grid,
  .kpi-grid.kpi-4 {
    grid-template-columns: 1fr;
  }
}
</style>
