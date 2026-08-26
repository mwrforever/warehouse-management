<!-- 采购销售汇总页：日期范围 + 粒度切换 + KPI（采购/销售金额/差额）+ 分组柱状图 + 表格 -->
<template>
  <div class="page-card">
    <h2 class="page-title">采购销售汇总</h2>
    <div class="toolbar">
      <el-date-picker
        v-model="dateRange"
        type="daterange"
        value-format="YYYY-MM-DD"
        :shortcuts="dateShortcuts"
        :clearable="false"
        @change="load"
      />
      <el-radio-group v-model="granularity" @change="load">
        <el-radio-button value="day">按日</el-radio-button>
        <el-radio-button value="month">按月</el-radio-button>
      </el-radio-group>
    </div>

    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-label">采购金额（元）</div>
        <div class="kpi-value font-code">{{ formatYuan(totals.purchase_amount) }}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">销售金额（元）</div>
        <div class="kpi-value font-code">{{ formatYuan(totals.sales_amount) }}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">差额（销售-采购）</div>
        <div class="kpi-value font-code" :class="{ negative: diff < 0 }">
          {{ formatYuan(diff) }}
        </div>
      </div>
    </div>

    <!-- 分组柱状图：>=4 个数据点才渲染；空区间整体 el-empty -->
    <div v-if="items.length >= 4" class="chart-wrap">
      <ReportChart :option="chartOption" />
    </div>
    <el-empty v-else-if="!loading" description="暂无数据" :image-size="80" />

    <div class="table-wrap">
      <el-table v-loading="loading" :data="items" empty-text="暂无数据">
        <el-table-column prop="period" label="周期" min-width="140" />
        <el-table-column label="采购金额（元）" min-width="150" align="right">
          <template #default="{ row }">
            <span class="font-code">{{ formatYuan(row.purchase_amount) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="销售金额（元）" min-width="150" align="right">
          <template #default="{ row }">
            <span class="font-code">{{ formatYuan(row.sales_amount) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="采购数量" min-width="120" align="right">
          <template #default="{ row }">
            <span class="font-code">{{ formatThousand(row.purchase_qty) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="销售数量" min-width="120" align="right">
          <template #default="{ row }">
            <span class="font-code">{{ formatThousand(row.sales_qty) }}</span>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>

<script setup lang="ts">
// 采购销售汇总：金额为后端整数分（R2 契约），展示统一 formatYuan 分转元；差额=销售-采购（分整数相减可负红色）；分组柱状图双系列（采购蓝/销售绿，值标签可见）
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { EChartsOption } from 'echarts'
import {
  reportApi,
  type PurchaseSalesItem,
  type PurchaseSalesTotal,
  type ReportGranularity,
} from '../../api/report'
import ReportChart from '../../components/ReportChart.vue'
import { fenToYuan, formatThousand, formatYuan, toLocalDateString } from '../../utils/format'

const dateRange = ref<[string, string]>([
  toLocalDateString(new Date(Date.now() - 29 * 86400000)),
  toLocalDateString(new Date()),
])
const granularity = ref<ReportGranularity>('day')
const items = ref<PurchaseSalesItem[]>([])
const totals = ref<PurchaseSalesTotal>({
  purchase_amount: 0,
  sales_amount: 0,
  purchase_qty: '0',
  sales_qty: '0',
})
const loading = ref(false)

const dateShortcuts = [
  { text: '今天', value: () => [new Date(), new Date()] },
  { text: '近 7 天', value: () => [new Date(Date.now() - 6 * 86400000), new Date()] },
  { text: '近 30 天', value: () => [new Date(Date.now() - 29 * 86400000), new Date()] },
]

// 差额 = 销售金额 - 采购金额（分整数相减精确无误差；可负红色；展示统一走 formatYuan——D-16）
const diff = computed(() => totals.value.sales_amount - totals.value.purchase_amount)

// 分组柱状图：采购蓝 / 销售绿，值标签默认可见（chart 域 AAA 可访问性要求）
const chartOption = computed<EChartsOption>(() => ({
  tooltip: { trigger: 'axis' },
  legend: { data: ['采购金额', '销售金额'], top: 0 },
  grid: { left: 56, right: 16, top: 40, bottom: 32 },
  xAxis: { type: 'category', data: items.value.map((i) => i.period) },
  yAxis: { type: 'value' },
  series: [
    {
      name: '采购金额',
      type: 'bar',
      // 图表按元口径取值（与坐标轴/值标签的金额（元）语义一致）：分 → 元精确换算
      data: items.value.map((i) => fenToYuan(i.purchase_amount)),
      itemStyle: { color: '#3B82F6' },
      label: { show: true, fontSize: 10 },
    },
    {
      name: '销售金额',
      type: 'bar',
      data: items.value.map((i) => fenToYuan(i.sales_amount)),
      itemStyle: { color: '#059669' },
      label: { show: true, fontSize: 10 },
    },
  ],
}))

// 请求序号守卫：快速切换日期/粒度时旧响应不得覆盖新结果（bug #4 回归）
let requestSeq = 0

async function load() {
  const seq = ++requestSeq
  loading.value = true
  try {
    const res = await reportApi.purchaseSales({
      date_from: dateRange.value[0],
      date_to: dateRange.value[1],
      granularity: granularity.value,
    })
    if (seq !== requestSeq) return // 已有更新的请求在途：丢弃本次过期响应
    items.value = res.items
    totals.value = res.totals
  } catch (e) {
    if (seq !== requestSeq) return
    ElMessage.error((e as Error).message)
  } finally {
    // 仅最新请求复位 loading（过期响应不干扰进行中的请求态）
    if (seq === requestSeq) loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
/* 样式遵循 design-system/nexus-factory/pages/report.md（KPI 卡/图表/差额负数） */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--space-xl);
  margin-bottom: var(--space-2xl);
}
.kpi-card {
  background: var(--surface);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: var(--space-xl);
  box-shadow: var(--shadow-sm);
  transition: box-shadow 200ms ease;
}
.kpi-card:hover {
  box-shadow: var(--shadow-md);
}
.kpi-label {
  font-size: 12px;
  color: var(--t3);
  margin-bottom: var(--space-md);
}
.kpi-value {
  font-size: 24px;
  font-weight: 700;
  color: var(--color-foreground);
}
.kpi-value.negative {
  color: var(--err);
}
.chart-wrap {
  margin-bottom: var(--space-2xl);
}
.table-wrap {
  overflow-x: auto;
}
</style>
