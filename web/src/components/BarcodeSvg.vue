<!-- 条码图形组件：将条码值渲染为 CODE128 一维码 SVG（商品列表条码列可视化） -->
<template>
  <svg v-if="!renderFailed" ref="svgRef" class="barcode-svg" />
  <!-- 渲染失败兜底：非 ASCII 条码（CODE128 仅支持 ASCII）或 jsdom 无 canvas 环境显示原文，避免同步抛错炸掉整行 -->
  <span v-else class="barcode-svg barcode-fallback" :title="value">{{ value }}</span>
</template>

<script setup lang="ts">
// 条码渲染：jsbarcode 生成矢量 SVG（缩放不失真）；空值由父级 v-if 控制不挂载
import { nextTick, onMounted, ref, watch } from 'vue'
import JsBarcode from 'jsbarcode'

const props = defineProps<{ value: string }>()
// 与 ProductsView 的 barcodeRef 同风格：不做 DOM 类型标注，避免 ESLint no-undef
const svgRef = ref()
// 渲染失败标记：try/catch 捕获后回退显示条码原文（B4 兜底）
const renderFailed = ref(false)

// 重绘条码：值变更或首次挂载后执行；CODE128 支持全 ASCII 字符集，非法字符/无 canvas 环境同步抛错必须兜住
function render() {
  if (!props.value || !svgRef.value) return
  try {
    JsBarcode(svgRef.value, props.value, {
      format: 'CODE128',
      width: 1.4,
      height: 32,
      displayValue: true,
      fontSize: 11,
      margin: 0,
      fontOptions: 'bold',
    })
    renderFailed.value = false
  } catch {
    renderFailed.value = true
  }
}

onMounted(async () => {
  await nextTick()
  render()
})
watch(() => props.value, render)
</script>

<style scoped>
/* 列宽约束：等比缩放保持矢量清晰，不撑破表格列 */
.barcode-svg {
  display: block;
  max-width: 108px;
  height: auto;
}

/* 兜底文本：等宽小字显示原始条码值，样式与 SVG 高度近似 */
.barcode-fallback {
  font-family: var(--font-code, monospace);
  font-size: 11px;
  line-height: 32px;
  color: var(--el-text-color-secondary, var(--p-400));
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
