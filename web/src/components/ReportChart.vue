<!-- 报表图表封装：echarts 初始化/销毁/resize 生命周期收敛（视图测试 mock 本组件隔离 echarts） -->
<template>
  <div ref="chartEl" class="report-chart" :style="{ height: `${height}px` }" />
</template>

<script setup lang="ts">
/* global window, HTMLElement */
// echarts 生命周期：onMounted 初始化、onBeforeUnmount 销毁（防内存泄漏）、窗口 resize 自适应
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import * as echarts from 'echarts'
import type { EChartsOption } from 'echarts'

const props = withDefaults(defineProps<{ option: EChartsOption; height?: number }>(), {
  height: 320,
})

const chartEl = ref<HTMLElement | null>(null)
let chart: echarts.ECharts | null = null

// 窗口尺寸变化时图表自适应（防布局错位）
function onResize() {
  chart?.resize()
}

onMounted(() => {
  if (!chartEl.value) return
  chart = echarts.init(chartEl.value)
  chart.setOption(props.option)
  window.addEventListener('resize', onResize)
})

// 筛选/粒度切换导致数据变化：全量重设（notMerge=true 清旧系列）
watch(
  () => props.option,
  (opt) => {
    chart?.setOption(opt, true)
  },
)

onBeforeUnmount(() => {
  window.removeEventListener('resize', onResize)
  chart?.dispose()
  chart = null
})
</script>

<style scoped>
/* 图表容器固定宽度 100%（高度由 props 控制，固定高度防 CLS） */
.report-chart {
  width: 100%;
}
</style>
