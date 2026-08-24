<!-- 主布局：可折叠侧边栏（分组+图标+权限过滤）+ 顶栏（面包屑/刷新/用户下拉）
     对照 docs/design/ui-redesign-mockup.html 视图二；菜单 IA 与权限过滤（auth.has）保持原状，
     仅视觉重设计；E2E 依赖类名 .sidebar / .brand / .user-name 保留 -->
<template>
  <div class="layout">
    <aside class="sidebar" :class="{ collapsed }" @mouseover="onTipMove" @mouseout="onTipOut">
      <!-- 品牌区（.brand 为 E2E 选择器，勿改类名） -->
      <div class="brand side-brand" data-tip="衡序 HENGXU" data-desc="智能仓储与生产管理平台">
        <span class="brand-mark"
          ><el-icon><Box /></el-icon
        ></span>
        <span class="txt"><b>衡序 HENGXU</b><span>Factory OS</span></span>
      </div>

      <nav class="side-nav">
        <div
          v-for="group in menu"
          :key="group.title ?? 'home'"
          class="nav-group"
          :class="{
            // 限权角色下整组无可见菜单项时隐藏，避免留下空档与多余分隔线
            'nav-group--empty': visibleItems(group).length === 0,
            closed: group.title ? closedGroups[group.title] : false,
          }"
        >
          <div
            v-if="group.title && visibleItems(group).length > 0"
            class="nav-group-title"
            :class="{ 'group-active': groupActive(group) }"
            :data-tip="group.title"
            :data-desc="`${visibleItems(group).length} 个功能`"
            @click="toggleGroup(group.title)"
          >
            <el-icon class="g-icon"><component :is="group.icon" /></el-icon>
            <span>{{ group.title }}</span>
            <span class="g-count">{{ visibleItems(group).length }}</span>
            <el-icon class="chev"><ArrowDown /></el-icon>
          </div>
          <div class="nav-items">
            <RouterLink
              v-for="item in visibleItems(group)"
              :key="item.to"
              :to="item.to"
              class="nav-item"
              :data-tip="item.tip"
              :data-desc="item.desc"
            >
              <el-icon class="n-icon"><component :is="item.icon" /></el-icon>
              <span>{{ item.label }}</span>
            </RouterLink>
          </div>
        </div>
      </nav>

      <div
        class="side-foot"
        :data-tip="`${auth.user?.name ?? '用户'} · ${roleLabel}`"
        data-desc="在线 · 退出登录请在顶栏头像菜单操作"
      >
        <span class="ava">{{ avatarChar }}</span>
        <span class="meta"
          ><b>{{ auth.user?.name }}</b
          ><span>{{ roleLabel }} · 在线</span></span
        >
      </div>
    </aside>

    <div class="main">
      <header class="topbar">
        <button
          class="icon-btn"
          :title="collapsed ? '展开菜单' : '折叠菜单'"
          aria-label="折叠或展开菜单"
          @click="collapsed = !collapsed"
        >
          <el-icon><component :is="collapsed ? Expand : Fold" /></el-icon>
        </button>
        <nav class="crumbs">
          <el-icon class="home-ic"><HomeFilled /></el-icon>
          <span class="sep">/</span>
          <b>{{ pageTitle }}</b>
        </nav>
        <button class="icon-btn" title="刷新当前页" aria-label="刷新当前页" @click="reload">
          <el-icon><Refresh /></el-icon>
        </button>

        <div class="top-right">
          <el-dropdown trigger="click" @command="onCommand">
            <span class="user-chip" tabindex="0">
              <span class="ava">{{ avatarChar }}</span>
              <span class="meta">
                <b class="user-name">{{ auth.user?.name }}</b>
                <span>{{ roleLabel }}</span>
              </span>
              <el-icon class="chev"><ArrowDown /></el-icon>
            </span>
            <template #dropdown>
              <el-dropdown-menu>
                <!-- 用户信息头为纯展示：用 disabled item 保持 ul>li 合法结构（裸 div 破坏 HTML 语义且不参与菜单键盘导航） -->
                <el-dropdown-item :disabled="true" class="um-head">
                  <span class="ava">{{ avatarChar }}</span>
                  <span class="meta">
                    <b>{{ auth.user?.name }}</b>
                    <span>{{ auth.user?.email ?? roleLabel }}</span>
                  </span>
                </el-dropdown-item>
                <el-dropdown-item command="logout" class="um-logout">
                  <el-icon><SwitchButton /></el-icon>
                  退出登录
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </header>

      <main class="content">
        <RouterView v-slot="{ Component }">
          <transition name="page" mode="out-in">
            <component :is="Component" />
          </transition>
        </RouterView>
      </main>
    </div>

    <!-- 折叠态悬浮提示卡（仅折叠时展示菜单名与功能描述） -->
    <div ref="tipRef" class="side-tip" :class="{ show: tipVisible }">
      <b>{{ tipName }}</b
      ><i>{{ tipDesc }}</i>
    </div>
  </div>
</template>

<script setup lang="ts">
/* global HTMLElement, MouseEvent */
// 主布局：登录态展示 + 权限菜单渲染 + 登出入口（逻辑沿用旧版，仅视觉重设计）
import { computed, reactive, ref, type Component } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ArrowDown,
  Back,
  Box,
  Connection,
  DataLine,
  Document,
  DocumentChecked,
  Download,
  Expand,
  Files,
  Fold,
  Folder,
  FolderOpened,
  Goods,
  Histogram,
  HomeFilled,
  House,
  Key,
  Notebook,
  Odometer,
  Operation,
  PieChart,
  Refresh,
  RefreshLeft,
  ScaleToOriginal,
  Sell,
  Setting,
  SetUp,
  SwitchButton,
  Tickets,
  Timer,
  TrendCharts,
  Upload,
  User,
  UserFilled,
  Van,
  Warning,
} from '@element-plus/icons-vue'
import { useAuthStore } from '../stores/auth'

interface MenuItem {
  to: string
  label: string
  icon: Component
  perm: string | null
  tip: string
  desc: string
}
interface MenuGroup {
  title: string | null
  icon?: Component
  items: MenuItem[]
}

// 菜单 IA（8 组 31 项；权限码与旧版一致，工艺路线为路由 DAG 需求新增）
const menu: MenuGroup[] = [
  {
    title: null,
    items: [
      {
        to: '/dashboard',
        label: '仪表盘',
        icon: Odometer,
        perm: null,
        tip: '仪表盘',
        desc: '今日经营概况总览',
      },
    ],
  },
  {
    title: '库存管理',
    icon: Box,
    items: [
      {
        to: '/inventory/balances',
        label: '库存余额',
        icon: Box,
        perm: 'inventory.list',
        tip: '库存余额',
        desc: '多仓库存余额与可用量',
      },
      {
        to: '/inventory/movements',
        label: '库存流水',
        icon: RefreshLeft,
        perm: 'inventory.list',
        tip: '库存流水',
        desc: '出入库明细追溯',
      },
      {
        to: '/inventory/checks',
        label: '库存盘点',
        icon: DocumentChecked,
        perm: 'check.list',
        tip: '库存盘点',
        desc: '盘点单开单与处理',
      },
      {
        to: '/inventory/alerts',
        label: '库存预警',
        icon: Warning,
        perm: 'inventory.list',
        tip: '库存预警',
        desc: '低库存与效期预警',
      },
    ],
  },
  {
    title: '采购管理',
    icon: Document,
    items: [
      {
        to: '/purchase/orders',
        label: '采购订单',
        icon: Document,
        perm: 'purchase.order.list',
        tip: '采购订单',
        desc: '采购下单与审核',
      },
      {
        to: '/purchase/inbounds',
        label: '采购入库',
        icon: Download,
        perm: 'purchase.inbound.list',
        tip: '采购入库',
        desc: '到货验收与入库',
      },
    ],
  },
  {
    title: '销售管理',
    icon: Sell,
    items: [
      {
        to: '/sales/orders',
        label: '销售订单',
        icon: Tickets,
        perm: 'sales.order.list',
        tip: '销售订单',
        desc: '销售下单与审核',
      },
      {
        to: '/sales/outbounds',
        label: '销售出库',
        icon: Upload,
        perm: 'sales.outbound.list',
        tip: '销售出库',
        desc: '发货与出库',
      },
    ],
  },
  {
    title: '生产管理',
    icon: SetUp,
    items: [
      {
        to: '/production/orders',
        label: '生产工单',
        icon: SetUp,
        perm: 'production.order.list',
        tip: '生产工单',
        desc: '生产任务下达与跟踪',
      },
      {
        to: '/production/picks',
        label: '领料单',
        icon: Goods,
        perm: 'production.pick.list',
        tip: '领料单',
        desc: '生产领料出库',
      },
      {
        to: '/production/returns',
        label: '退料单',
        icon: Back,
        perm: 'production.return.list',
        tip: '退料单',
        desc: '生产退料入库',
      },
      {
        to: '/production/reports',
        label: '工序报工',
        icon: Timer,
        perm: 'production.report.list',
        tip: '工序报工',
        desc: '工序产量与工时上报',
      },
      {
        to: '/production/outsourcings',
        label: '委外加工',
        icon: Connection,
        perm: 'production.outsource.list',
        tip: '委外加工',
        desc: '外协加工管理',
      },
      {
        to: '/production/finished-inbounds',
        label: '成品入库',
        icon: Box,
        perm: 'production.finished.list',
        tip: '成品入库',
        desc: '完工成品入库',
      },
    ],
  },
  {
    title: '基础资料',
    icon: FolderOpened,
    items: [
      {
        to: '/master/products',
        label: '商品管理',
        icon: Goods,
        perm: 'product.list',
        tip: '商品管理',
        desc: '商品档案维护',
      },
      {
        to: '/master/categories',
        label: '分类管理',
        icon: Folder,
        perm: 'category.list',
        tip: '分类管理',
        desc: '商品分类维护',
      },
      {
        to: '/master/units',
        label: '单位管理',
        icon: ScaleToOriginal,
        perm: 'unit.list',
        tip: '单位管理',
        desc: '计量单位维护',
      },
      {
        to: '/master/warehouses',
        label: '仓库管理',
        icon: House,
        perm: 'warehouse.list',
        tip: '仓库管理',
        desc: '仓库档案维护',
      },
      {
        to: '/master/suppliers',
        label: '供应商管理',
        icon: Van,
        perm: 'supplier.list',
        tip: '供应商管理',
        desc: '供应商档案维护',
      },
      {
        to: '/master/customers',
        label: '客户管理',
        icon: User,
        perm: 'customer.list',
        tip: '客户管理',
        desc: '客户档案维护',
      },
      {
        to: '/master/boms',
        label: 'BOM 管理',
        icon: Files,
        perm: 'bom.list',
        tip: 'BOM 管理',
        desc: '物料清单维护',
      },
      {
        to: '/master/routings',
        label: '工艺路线',
        icon: Connection,
        perm: 'routing.list',
        tip: '工序 DAG 网络',
        desc: '成品工艺路线与工序编排',
      },
      {
        to: '/master/processes',
        label: '工序管理',
        icon: Operation,
        perm: 'process.list',
        tip: '工序管理',
        desc: '工艺路线维护',
      },
    ],
  },
  {
    title: '统计报表',
    icon: TrendCharts,
    items: [
      {
        to: '/reports/inventory',
        label: '库存报表',
        icon: Histogram,
        perm: 'report.inventory',
        tip: '库存报表',
        desc: '收发存与余额报表',
      },
      {
        to: '/reports/movements',
        label: '出入库汇总',
        icon: TrendCharts,
        perm: 'report.movements',
        tip: '出入库汇总',
        desc: '区间出入库汇总',
      },
      {
        to: '/reports/production',
        label: '生产统计',
        icon: PieChart,
        perm: 'report.production',
        tip: '生产统计',
        desc: '产量与工时统计',
      },
      {
        to: '/reports/purchase-sales',
        label: '采购销售汇总',
        icon: DataLine,
        perm: 'report.purchase_sales',
        tip: '采购销售汇总',
        desc: '购销金额对比分析',
      },
    ],
  },
  {
    title: '系统管理',
    icon: Setting,
    items: [
      {
        to: '/system/users',
        label: '用户管理',
        icon: UserFilled,
        perm: 'user.list',
        tip: '用户管理',
        desc: '系统账号维护',
      },
      {
        to: '/system/roles',
        label: '角色管理',
        icon: Key,
        perm: 'role.list',
        tip: '角色管理',
        desc: '角色与权限分配',
      },
      {
        to: '/system/dictionaries',
        label: '字典管理',
        icon: Notebook,
        perm: 'dictionary.list',
        tip: '字典管理',
        desc: '数据字典维护',
      },
      {
        to: '/system/numbering',
        label: '编号规则',
        icon: Tickets,
        perm: 'system.setting.list',
        tip: '编号规则',
        desc: '单据号与商品编码格式配置',
      },
    ],
  },
]

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const collapsed = ref(false)
// 菜单分组折叠态（默认全部展开）
const closedGroups = reactive<Record<string, boolean>>({})

// 权限过滤：与旧版 v-if="auth.has('xxx')" 完全同口径
function visibleItems(group: MenuGroup): MenuItem[] {
  return group.items.filter((i) => !i.perm || auth.has(i.perm))
}

// 分组高亮：当前路由落在该组任一菜单项下时，分组标题切换为"所在分区"强调态
function groupActive(group: MenuGroup): boolean {
  return group.items.some((i) => route.path === i.to || route.path.startsWith(i.to + '/'))
}

// 分组折叠切换
function toggleGroup(title: string) {
  closedGroups[title] = !closedGroups[title]
}

// 顶栏面包屑：当前路由对应的菜单名；详情路由（路径带 id，如 /inventory/checks/5）按前缀归属到列表菜单，
// 无匹配才兜底仪表盘（菜单 30 项路径互不为前缀，先精确后前缀匹配安全）
const pageTitle = computed(() => {
  for (const group of menu) {
    const item = group.items.find((i) => i.to === route.path || route.path.startsWith(i.to + '/'))
    if (item) return item.label
  }
  return '仪表盘'
})

const roleLabel = computed(() => auth.user?.roles.map((r) => r.name).join(' / ') || '系统用户')
const avatarChar = computed(() => auth.user?.name?.charAt(0) ?? '衡')

// 刷新当前页
function reload() {
  router.go(0)
}

// 登出：调后端撤销 token 后回登录页
async function onCommand(cmd: string) {
  if (cmd === 'logout') {
    await auth.logout()
    router.push('/login')
  }
}

// 折叠态悬浮提示卡：hover 菜单项/分组/品牌/用户区时展示名称与功能描述
const tipVisible = ref(false)
const tipName = ref('')
const tipDesc = ref('')
const tipRef = ref<HTMLElement | null>(null)
let currentTipEl: HTMLElement | null = null

function onTipMove(e: MouseEvent) {
  if (!collapsed.value) return
  const target = (e.target as HTMLElement).closest('[data-tip]') as HTMLElement | null
  if (!target || target === currentTipEl) return
  currentTipEl = target
  tipName.value = target.dataset.tip ?? ''
  tipDesc.value = target.dataset.desc ?? ''
  const r = target.getBoundingClientRect()
  const el = tipRef.value
  if (!el) return
  el.style.top = `${Math.round(r.top + r.height / 2)}px`
  el.style.left = `${Math.round(r.right + 12)}px`
  el.style.transform = 'translateY(-50%)'
  tipVisible.value = true
}

function onTipOut(e: MouseEvent) {
  const target = (e.target as HTMLElement).closest('[data-tip]') as HTMLElement | null
  if (target) {
    currentTipEl = null
    tipVisible.value = false
  }
}
</script>

<style scoped>
/* 布局样式：衡序设计系统（深色侧边栏 + 浅色内容区），对应设计稿视图二 */
.layout {
  display: flex;
  height: 100vh;
  overflow: hidden;
}

/* ===== 侧边栏 ===== */
.sidebar {
  width: 248px;
  flex: none;
  background: var(--p-900);
  color: var(--p-300);
  display: flex;
  flex-direction: column;
  transition: width 0.28s cubic-bezier(0.4, 0, 0.2, 1);
}
.sidebar.collapsed {
  width: 68px;
}

.side-brand {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 18px 18px 16px;
  cursor: pointer;
}
.brand-mark {
  width: 34px;
  height: 34px;
  border-radius: 9px;
  display: grid;
  place-items: center;
  background: linear-gradient(135deg, var(--a-500), var(--a-700));
  color: #fff;
  box-shadow: 0 8px 20px rgba(5, 150, 105, 0.3);
  flex: none;
}
.brand-mark .el-icon {
  font-size: 19px;
}
.side-brand .txt b {
  display: block;
  font-size: 15px;
  color: #fff;
  font-weight: 700;
  letter-spacing: 0.3px;
  white-space: nowrap;
}
.side-brand .txt span {
  display: block;
  font-size: 10.5px;
  color: var(--p-400);
  letter-spacing: 2px;
  text-transform: uppercase;
  white-space: nowrap;
}

.side-nav {
  flex: 1;
  overflow-y: auto;
  padding: 6px 12px 16px;
  scrollbar-width: thin;
  scrollbar-color: var(--p-700) transparent;
}
.nav-group {
  margin-top: 12px;
}
.nav-group:first-child {
  margin-top: 4px;
}
/* 整组无可见菜单项时隐藏（限权角色下不留空档） */
.nav-group--empty {
  display: none;
}
/* 分组间细分隔线：把"分组"从连续的菜单行中切分出来，形成分区节奏 */
.nav-group + .nav-group {
  margin-top: 14px;
  border-top: 1px solid rgba(255, 255, 255, 0.05);
  padding-top: 12px;
}
/* 分组标题 = 分区标签：小号加宽字距 + 弱化图标，与菜单项（亮色可点行）在字号、明度上强区分；
   右侧数量徽标与折叠箭头是"可展开分组"的专属暗示，菜单项没有 */
.nav-group-title {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  font-size: 11px;
  letter-spacing: 1.4px;
  color: var(--p-400);
  font-weight: 600;
  cursor: pointer;
  user-select: none;
  border-radius: 6px;
  white-space: nowrap;
  transition:
    background 0.15s,
    color 0.15s;
}
.nav-group-title .g-icon {
  font-size: 13px;
  color: var(--p-600);
}
/* 功能数量徽标：圆角描边数字，一眼可辨"这是一组功能"而非单个页面 */
.g-count {
  margin-left: auto;
  font-size: 10px;
  line-height: 14px;
  padding: 0 5px;
  border-radius: 99px;
  color: var(--p-500);
  border: 1px solid rgba(255, 255, 255, 0.09);
  font-variant-numeric: tabular-nums;
  transition:
    color 0.15s,
    border-color 0.15s;
}
.nav-group-title .chev {
  transition:
    transform 0.2s,
    color 0.2s;
  color: var(--p-600);
  font-size: 12px;
}
/* 展开中的分组：箭头用强调色，直观提示"当前处于展开状态" */
.nav-group:not(.closed) .nav-group-title .chev {
  color: var(--a-500);
}
.nav-group.closed .nav-group-title .chev {
  transform: rotate(-90deg);
}
.nav-group.closed .nav-items {
  display: none;
}
/* 分组标题悬停：淡底 + 提亮，反馈"可点"但保持标签质感，与菜单项的圆角药丸悬停区分 */
.nav-group-title:hover {
  background: rgba(255, 255, 255, 0.04);
  color: var(--p-200);
}
.nav-group-title:hover .g-icon {
  color: var(--p-400);
}
.nav-group-title:hover .g-count {
  color: var(--a-400);
  border-color: rgba(52, 211, 153, 0.35);
}
.nav-group-title:hover .chev {
  color: var(--a-400);
}
/* 当前路由所在分区的标题高亮（"所在分区"强调态） */
.nav-group-title.group-active {
  color: var(--p-100);
}
.nav-group-title.group-active .g-icon {
  color: var(--a-400);
}

.nav-items {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-top: 6px;
  /* 菜单项统一缩进，空间上直观呈现"分组 → 页面"的父子层级 */
  margin-left: 12px;
}
.nav-item {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 8.5px 10px;
  border-radius: 8px;
  /* 菜单项是亮色可点行：文字近白 vs 分组标题的灰暗标签，拉开明度对比 */
  color: var(--p-200);
  font-size: 13.5px;
  position: relative;
  transition:
    background 0.15s,
    color 0.15s;
  white-space: nowrap;
}
.nav-item .n-icon {
  font-size: 17px;
  /* 图标弱于文字，阅读重心放在菜单名上；hover 时再随行提亮 */
  color: var(--p-400);
  flex: none;
  transition: color 0.15s;
}
.nav-item:hover {
  background: rgba(255, 255, 255, 0.06);
  color: #fff;
}
.nav-item:hover .n-icon {
  color: var(--p-200);
}
/* hover 底部指示条（不与激活左条冲突） */
.nav-item::after {
  content: '';
  position: absolute;
  left: 10px;
  right: 10px;
  bottom: 4px;
  height: 2px;
  border-radius: 2px;
  background: var(--a-400);
  transform: scaleX(0);
  transform-origin: left;
  transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1);
}
.nav-item:hover::after {
  transform: scaleX(1);
}
.nav-item.router-link-active {
  background: rgba(16, 185, 129, 0.13);
  color: #fff;
  font-weight: 600;
}
.nav-item.router-link-active::before {
  content: '';
  position: absolute;
  left: -12px;
  top: 50%;
  transform: translateY(-50%);
  width: 3px;
  height: 20px;
  border-radius: 0 3px 3px 0;
  background: var(--a-500);
}
.nav-item.router-link-active::after {
  display: none;
}

/* 折叠态：文字隐藏、图标居中 */
.sidebar.collapsed .side-brand {
  justify-content: center;
  padding: 18px 0 16px;
}
.sidebar.collapsed .side-brand .txt,
.sidebar.collapsed .nav-group-title span,
.sidebar.collapsed .nav-item span {
  display: none;
}
.sidebar.collapsed .nav-group-title {
  justify-content: center;
  padding: 8px 0;
}
/* 折叠态取消菜单项缩进，图标水平居中 */
.sidebar.collapsed .nav-items {
  margin-left: 0;
}
.sidebar.collapsed .nav-item {
  justify-content: center;
  padding: 10px 0;
}
.sidebar.collapsed .nav-item.router-link-active::before {
  left: -12px;
}
.sidebar.collapsed .nav-group.closed .nav-items {
  display: flex;
}

.side-foot {
  padding: 14px 18px;
  border-top: 1px solid rgba(255, 255, 255, 0.07);
  display: flex;
  align-items: center;
  gap: 10px;
}
.ava {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--p-600), var(--p-800));
  color: #fff;
  display: grid;
  place-items: center;
  font-size: 12px;
  font-weight: 700;
  flex: none;
}
.side-foot .meta {
  flex: 1;
  min-width: 0;
}
.side-foot .meta b {
  display: block;
  font-size: 12.5px;
  color: var(--p-200);
  white-space: nowrap;
}
.side-foot .meta span {
  display: block;
  font-size: 11px;
  color: var(--p-400);
  white-space: nowrap;
}
.sidebar.collapsed .side-foot {
  justify-content: center;
  padding: 14px 0;
}
.sidebar.collapsed .side-foot .meta {
  display: none;
}

/* ===== 主区域 ===== */
.main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.topbar {
  height: 60px;
  flex: none;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 0 24px;
}
.icon-btn {
  width: 36px;
  height: 36px;
  border-radius: 9px;
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--t2);
  display: grid;
  place-items: center;
  cursor: pointer;
  transition:
    background 0.15s,
    color 0.15s,
    border-color 0.15s;
}
.icon-btn:hover {
  background: var(--p-100);
  color: var(--t1);
  border-color: var(--border-strong);
}
.icon-btn .el-icon {
  font-size: 17px;
}
.crumbs {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 13px;
  color: var(--t3);
}
.crumbs .home-ic {
  font-size: 15px;
  color: var(--p-400);
}
.crumbs .sep {
  color: var(--p-300);
}
.crumbs b {
  color: var(--t1);
  font-weight: 600;
}
.top-right {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 10px;
}
.top-right .el-dropdown {
  display: flex;
}
.user-chip {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 5px 6px 5px 5px;
  border-radius: 10px;
  border: 1px solid transparent;
  transition:
    background 0.15s,
    border-color 0.15s;
  cursor: pointer;
  outline: none;
}
.user-chip:hover,
.user-chip:focus-visible {
  background: var(--p-100);
  border-color: var(--border);
}
.user-chip .ava {
  background: linear-gradient(135deg, var(--a-600), var(--a-700));
}
.user-chip .meta {
  text-align: left;
  line-height: 1.25;
}
.user-chip .meta b {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: var(--t1);
}
.user-chip .meta span {
  display: block;
  font-size: 11px;
  color: var(--t3);
}
.user-chip .chev {
  font-size: 14px;
  color: var(--t3);
  transition: transform 0.2s;
}
.el-dropdown:focus-within .user-chip .chev {
  transform: rotate(180deg);
}

/* 用户下拉头部：头像 + 姓名 + 邮箱 */
.um-head {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px 12px;
  border-bottom: 1px solid var(--p-100);
}
.um-head .ava {
  width: 38px;
  height: 38px;
  font-size: 14px;
  background: linear-gradient(135deg, var(--a-600), var(--a-700));
}
.um-head .meta b {
  display: block;
  font-size: 14px;
  font-weight: 600;
  color: var(--t1);
}
.um-head .meta span {
  display: block;
  margin-top: 2px;
  font-size: 11.5px;
  color: var(--t3);
}
.um-logout {
  color: var(--err);
}
.um-logout .el-icon {
  font-size: 15px;
}

.content {
  flex: 1;
  overflow-y: auto;
  padding: 24px;
}

/* ===== 折叠态悬浮提示卡 ===== */
.side-tip {
  position: fixed;
  z-index: 500;
  pointer-events: none;
  background: var(--p-800);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 10px;
  box-shadow: var(--sh-lg);
  padding: 9px 13px;
  min-width: 158px;
  opacity: 0;
  transform: translateX(-6px);
  transition:
    opacity 0.15s,
    transform 0.15s;
}
.side-tip.show {
  opacity: 1;
  transform: none;
}
.side-tip b {
  display: block;
  font-size: 12.5px;
  font-weight: 600;
  color: #fff;
  letter-spacing: 0.2px;
}
.side-tip i {
  display: block;
  margin-top: 3px;
  font-size: 11.5px;
  font-style: normal;
  color: var(--p-400);
  line-height: 1.5;
}
</style>
