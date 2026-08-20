<!-- 应用根组件：路由出口（登录页/主布局由路由分发）；
     过渡 mode="out-in"：旧组件先退场再进场，避免双渲染争抢焦点（spec §4.5） -->
<template>
  <RouterView v-slot="{ Component }">
    <transition name="page" mode="out-in">
      <component :is="Component" />
    </transition>
  </RouterView>
</template>
