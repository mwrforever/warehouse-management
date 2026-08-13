<!-- 主布局：深色侧边栏(220px) + 浅色内容区；菜单按权限过滤 -->
<template>
  <div class="layout">
    <aside class="sidebar">
      <div class="brand font-code">Nexus Factory</div>
      <nav>
        <RouterLink to="/dashboard" class="menu-item">仪表盘</RouterLink>
        <div class="menu-group">库存管理</div>
        <RouterLink v-if="auth.has('inventory.list')" to="/inventory/balances" class="menu-item"
          >库存余额</RouterLink
        >
        <RouterLink v-if="auth.has('inventory.list')" to="/inventory/movements" class="menu-item"
          >库存流水</RouterLink
        >
        <RouterLink v-if="auth.has('check.list')" to="/inventory/checks" class="menu-item"
          >库存盘点</RouterLink
        >
        <RouterLink v-if="auth.has('inventory.list')" to="/inventory/alerts" class="menu-item"
          >库存预警</RouterLink
        >
        <div class="menu-group">系统管理</div>
        <RouterLink v-if="auth.has('user.list')" to="/system/users" class="menu-item"
          >用户管理</RouterLink
        >
        <RouterLink v-if="auth.has('role.list')" to="/system/roles" class="menu-item"
          >角色管理</RouterLink
        >
        <RouterLink v-if="auth.has('dictionary.list')" to="/system/dictionaries" class="menu-item"
          >字典管理</RouterLink
        >
        <div class="menu-group">基础资料</div>
        <RouterLink v-if="auth.has('product.list')" to="/master/products" class="menu-item"
          >商品管理</RouterLink
        >
        <RouterLink v-if="auth.has('category.list')" to="/master/categories" class="menu-item"
          >分类管理</RouterLink
        >
        <RouterLink v-if="auth.has('unit.list')" to="/master/units" class="menu-item"
          >单位管理</RouterLink
        >
        <RouterLink v-if="auth.has('warehouse.list')" to="/master/warehouses" class="menu-item"
          >仓库管理</RouterLink
        >
        <RouterLink v-if="auth.has('supplier.list')" to="/master/suppliers" class="menu-item"
          >供应商管理</RouterLink
        >
        <RouterLink v-if="auth.has('customer.list')" to="/master/customers" class="menu-item"
          >客户管理</RouterLink
        >
        <RouterLink v-if="auth.has('bom.list')" to="/master/boms" class="menu-item"
          >BOM 管理</RouterLink
        >
        <RouterLink v-if="auth.has('process.list')" to="/master/processes" class="menu-item"
          >工序管理</RouterLink
        >
        <div class="menu-group">采购管理</div>
        <RouterLink v-if="auth.has('purchase.order.list')" to="/purchase/orders" class="menu-item"
          >采购订单</RouterLink
        >
        <RouterLink
          v-if="auth.has('purchase.inbound.list')"
          to="/purchase/inbounds"
          class="menu-item"
          >采购入库</RouterLink
        >
        <div class="menu-group">销售管理</div>
        <RouterLink v-if="auth.has('sales.order.list')" to="/sales/orders" class="menu-item"
          >销售订单</RouterLink
        >
        <RouterLink v-if="auth.has('sales.outbound.list')" to="/sales/outbounds" class="menu-item"
          >销售出库</RouterLink
        >
        <div class="menu-group">生产管理</div>
        <RouterLink
          v-if="auth.has('production.order.list')"
          to="/production/orders"
          class="menu-item"
          >生产工单</RouterLink
        >
        <RouterLink v-if="auth.has('production.pick.list')" to="/production/picks" class="menu-item"
          >领料单</RouterLink
        >
        <RouterLink
          v-if="auth.has('production.return.list')"
          to="/production/returns"
          class="menu-item"
          >退料单</RouterLink
        >
        <RouterLink
          v-if="auth.has('production.report.list')"
          to="/production/reports"
          class="menu-item"
          >工序报工</RouterLink
        >
        <RouterLink
          v-if="auth.has('production.outsource.list')"
          to="/production/outsourcings"
          class="menu-item"
          >委外加工</RouterLink
        >
        <RouterLink
          v-if="auth.has('production.finished.list')"
          to="/production/finished-inbounds"
          class="menu-item"
          >成品入库</RouterLink
        >
        <div class="menu-group">统计报表</div>
        <RouterLink v-if="auth.has('report.inventory')" to="/reports/inventory" class="menu-item"
          >库存报表</RouterLink
        >
        <RouterLink v-if="auth.has('report.movements')" to="/reports/movements" class="menu-item"
          >出入库汇总</RouterLink
        >
        <RouterLink v-if="auth.has('report.production')" to="/reports/production" class="menu-item"
          >生产统计</RouterLink
        >
        <RouterLink
          v-if="auth.has('report.purchase_sales')"
          to="/reports/purchase-sales"
          class="menu-item"
          >采购销售汇总</RouterLink
        >
      </nav>
    </aside>
    <div class="main">
      <header class="topbar">
        <el-dropdown @command="onCommand">
          <span class="user-name">{{ auth.user?.name }}</span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item command="logout">退出登录</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </header>
      <main class="content"><RouterView /></main>
    </div>
  </div>
</template>

<script setup lang="ts">
// 主布局：登录态展示 + 登出入口
import { useAuthStore } from '../stores/auth'
import { useRouter } from 'vue-router'

const auth = useAuthStore()
const router = useRouter()

// 登出：调后端撤销 token 后回登录页
async function onCommand(cmd: string) {
  if (cmd === 'logout') {
    await auth.logout()
    router.push('/login')
  }
}
</script>

<style scoped>
/* 布局样式：遵循设计令牌（深色侧边栏 + 浅色内容区） */
.layout {
  display: flex;
  min-height: 100vh;
}
.sidebar {
  width: 220px;
  background: var(--color-foreground);
  color: #fff;
  padding: var(--space-2xl) var(--space-lg);
}
.brand {
  font-size: 18px;
  font-weight: 700;
  margin-bottom: var(--space-2xl);
}
.menu-group {
  margin: var(--space-xl) 0 var(--space-md);
  color: #94a3b8;
  font-size: 12px;
}
.menu-item {
  display: block;
  padding: var(--space-md) var(--space-lg);
  color: #cbd5e1;
  text-decoration: none;
  border-radius: 6px;
  cursor: pointer;
}
.menu-item:hover {
  background: #1e293b;
  color: #fff;
}
.menu-item.router-link-active {
  background: var(--color-primary);
  color: var(--color-on-primary);
}
.main {
  flex: 1;
  background: var(--color-background);
  display: flex;
  flex-direction: column;
}
.topbar {
  height: 56px;
  background: #fff;
  border-bottom: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  justify-content: flex-end;
  padding: 0 var(--space-2xl);
}
.user-name {
  cursor: pointer;
}
.content {
  padding: var(--space-2xl);
}
</style>
