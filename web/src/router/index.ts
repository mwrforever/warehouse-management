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
          path: 'master/boms',
          name: 'master-boms',
          component: () => import('../views/master/BomsView.vue'),
          meta: { permission: 'bom.list' },
        },
        { path: '403', name: 'forbidden', component: () => import('../views/ForbiddenView.vue') },
      ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
  ],
})

// 全局前置守卫：登录校验 + 权限校验（permissions 来自 auth store）
router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (to.meta.public) {
    // 已登录用户访问登录页：直接回仪表盘
    if (to.name === 'login' && auth.token) return { name: 'dashboard' }
    return true
  }

  // 无 token：跳登录页
  if (!auth.token) return { name: 'login' }

  // 有 token 未拉用户：首屏拉取 /auth/me（失败时 store 已清空登录态）
  if (!auth.user) {
    await auth.fetchMe()
    if (!auth.user) return { name: 'login' }
  }

  // 页面要求权限但用户不具备：跳 403
  if (to.meta.permission && !auth.has(to.meta.permission as string)) {
    return { name: 'forbidden' }
  }
  return true
})

export default router
