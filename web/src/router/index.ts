// 路由配置：登录页公开；业务路由挂守卫（登录校验 + 权限校验）
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('../views/LoginView.vue'),
      meta: { public: true },
    },
    {
      path: '/',
      component: () => import('../layouts/MainLayout.vue'),
      children: [
        { path: '', redirect: '/dashboard' },
        {
          path: 'dashboard',
          name: 'dashboard',
          component: () => import('../views/DashboardView.vue'),
        },
        {
          path: 'system/users',
          name: 'system-users',
          component: () => import('../views/system/UsersView.vue'),
          meta: { permission: 'user.list' },
        },
        {
          path: 'system/roles',
          name: 'system-roles',
          component: () => import('../views/system/RolesView.vue'),
          meta: { permission: 'role.list' },
        },
        {
          path: 'system/dictionaries',
          name: 'system-dictionaries',
          component: () => import('../views/system/DictionariesView.vue'),
          meta: { permission: 'dictionary.list' },
        },
        {
          path: 'system/numbering',
          name: 'system-numbering',
          component: () => import('../views/system/NumberingConfigsView.vue'),
          meta: { permission: 'system.setting.list' },
        },
        {
          path: 'master/categories',
          name: 'master-categories',
          component: () => import('../views/master/CategoriesView.vue'),
          meta: { permission: 'category.list' },
        },
        {
          path: 'master/units',
          name: 'master-units',
          component: () => import('../views/master/UnitsView.vue'),
          meta: { permission: 'unit.list' },
        },
        {
          path: 'master/warehouses',
          name: 'master-warehouses',
          component: () => import('../views/master/WarehousesView.vue'),
          meta: { permission: 'warehouse.list' },
        },
        {
          path: 'master/suppliers',
          name: 'master-suppliers',
          component: () => import('../views/master/SuppliersView.vue'),
          meta: { permission: 'supplier.list' },
        },
        {
          path: 'master/customers',
          name: 'master-customers',
          component: () => import('../views/master/CustomersView.vue'),
          meta: { permission: 'customer.list' },
        },
        {
          path: 'master/processes',
          name: 'master-processes',
          component: () => import('../views/master/ProcessesView.vue'),
          meta: { permission: 'process.list' },
        },
        {
          path: 'master/products',
          name: 'master-products',
          component: () => import('../views/master/ProductsView.vue'),
          meta: { permission: 'product.list' },
        },
        {
          path: 'inventory/balances',
          name: 'inventory-balances',
          component: () => import('../views/inventory/BalancesView.vue'),
          meta: { permission: 'inventory.list' },
        },
        {
          path: 'inventory/movements',
          name: 'inventory-movements',
          component: () => import('../views/inventory/MovementsView.vue'),
          meta: { permission: 'inventory.list' },
        },
        {
          path: 'inventory/checks/:id?',
          name: 'inventory-checks',
          component: () => import('../views/inventory/ChecksView.vue'),
          meta: { permission: 'check.list' },
        },
        {
          path: 'inventory/alerts',
          name: 'inventory-alerts',
          component: () => import('../views/inventory/AlertsView.vue'),
          meta: { permission: 'inventory.list' },
        },
        {
          path: 'purchase/orders',
          name: 'purchase-orders',
          component: () => import('../views/purchase/OrdersView.vue'),
          meta: { permission: 'purchase.order.list' },
        },
        {
          path: 'purchase/inbounds/:id?',
          name: 'purchase-inbounds',
          component: () => import('../views/purchase/InboundsView.vue'),
          meta: { permission: 'purchase.inbound.list' },
        },
        {
          path: 'sales/orders',
          name: 'sales-orders',
          component: () => import('../views/sales/OrdersView.vue'),
          meta: { permission: 'sales.order.list' },
        },
        {
          path: 'sales/outbounds/:id?',
          name: 'sales-outbounds',
          component: () => import('../views/sales/OutboundsView.vue'),
          meta: { permission: 'sales.outbound.list' },
        },
        {
          path: 'production/orders',
          name: 'production-orders',
          component: () => import('../views/production/OrdersView.vue'),
          meta: { permission: 'production.order.list' },
        },
        {
          path: 'production/reports',
          name: 'production-reports',
          component: () => import('../views/production/ReportsView.vue'),
          meta: { permission: 'production.report.list' },
        },
        {
          path: 'production/picks',
          name: 'production-picks',
          component: () => import('../views/production/PicksView.vue'),
          meta: { permission: 'production.pick.list' },
        },
        {
          path: 'production/returns',
          name: 'production-returns',
          component: () => import('../views/production/ReturnsView.vue'),
          meta: { permission: 'production.return.list' },
        },
        {
          path: 'production/outsourcings',
          name: 'production-outsourcings',
          component: () => import('../views/production/OutsourcingsView.vue'),
          meta: { permission: 'production.outsource.list' },
        },
        {
          path: 'production/finished-inbounds',
          name: 'production-finished-inbounds',
          component: () => import('../views/production/FinishedInboundsView.vue'),
          meta: { permission: 'production.finished.list' },
        },
        {
          path: 'master/boms',
          name: 'master-boms',
          component: () => import('../views/master/BomsView.vue'),
          meta: { permission: 'bom.list' },
        },
        {
          path: 'master/routings',
          name: 'master-routings',
          component: () => import('../views/master/RoutingsView.vue'),
          meta: { permission: 'routing.list' },
        },
        {
          path: 'master/routings/canvas',
          name: 'master-routings-canvas',
          component: () => import('../views/master/RoutingCanvasView.vue'),
          meta: { permission: 'routing.list' },
        },
        {
          path: 'reports/inventory',
          name: 'reports-inventory',
          component: () => import('../views/reports/InventoryReportView.vue'),
          meta: { permission: 'report.inventory' },
        },
        {
          path: 'reports/movements',
          name: 'reports-movements',
          component: () => import('../views/reports/MovementsReportView.vue'),
          meta: { permission: 'report.movements' },
        },
        {
          path: 'reports/production',
          name: 'reports-production',
          component: () => import('../views/reports/ProductionReportView.vue'),
          meta: { permission: 'report.production' },
        },
        {
          path: 'reports/purchase-sales',
          name: 'reports-purchase-sales',
          component: () => import('../views/reports/PurchaseSalesReportView.vue'),
          meta: { permission: 'report.purchase_sales' },
        },
        { path: '403', name: 'forbidden', component: () => import('../views/ForbiddenView.vue') },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
  ],
})

// 全局前置守卫：登录校验 + 权限校验（会话模式 R4-3：登录态以「/me 拉取成功」为准，守卫首屏探测会话）
router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (to.meta.public) {
    // 登录页：已有登录态（本应用会话内已拉取过用户）直接回仪表盘；未拉取过则静默探测一次会话
    // （会话有效说明已登录，回仪表盘；401 时 http 拦截器已豁免登录页跳转，表单正常展示）
    if (to.name === 'login' && !auth.user) {
      const ok = await auth.fetchMe()
      if (ok) return { name: 'dashboard' }
    }
    return true
  }

  // 无用户信息：先探测会话（拉 /auth/me 成功即已登录；失败时 store 已清空登录态）
  if (!auth.user) {
    const ok = await auth.fetchMe()
    if (!ok) return { name: 'login' }
  }

  // 页面要求权限但用户不具备：跳 403
  if (to.meta.permission && !auth.has(to.meta.permission as string)) {
    return { name: 'forbidden' }
  }
  return true
})

export default router
