<!-- 出入库汇总页：日期范围 + 粒度切换 + KPI（入库/出库/净变动）+ 双线图 + 表格（行点击下钻流水） -->
<template>
  <div class="page-card">
    <h2 class="page-title">出入库汇总</h2>
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
        <div class="kpi-label">总入库量</div>
        <div class="kpi-value font-code">{{ formatThousand(totals.inbound_qty) }}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">总出库量</div>
        <div class="kpi-value font-code">{{ formatThousand(totals.outbound_qty) }}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">净变动（入-出）</div>
        <div class="kpi-value font-code" :class="{ negative: Number(netChange) < 0 }">
          {{ formatThousand(netChange) }}
        </div>
      </div>
    </div>

    <!-- 图表：>=4 个数据点才渲染（chart 域规则）；空区间整体 el-empty -->
    <div v-if="items.length >= 4" class="chart-wrap">
      <ReportChart :option="chartOption" />
    </div>
    <el-empty v-else-if="!loading" description="暂无数据" :image-size="80" />

    <div class="table-wrap">
      <el-table
        v-loading="loading"
        :data="items"
        empty-text="暂无数据"
        row-class-name="drill-row"
        @row-click="drillDown"
      >
        <el-table-column prop="period" label="周期" min-width="140" />
        <el-table-column label="入库量" min-width="140" align="right">
          <template #default="{ row }">
            <span class="font-code">{{ formatThousand(row.inbound_qty) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="出库量" min-width="140" align="right">
          <template #default="{ row }">
            <span class="font-code">{{ formatThousand(row.outbound_qty) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="inbound_count" label="入库笔数" width="100" align="right" />
        <el-table-column prop="outbound_count" label="出库笔数" width="100" align="right" />
      </el-table>
    </div>
  </div>
</template>

<script setup lang="ts">
// 出入库汇总：闭区间日期筛选；净变动=入-出（可负红色）；表格行点击下钻库存流水页（携带该周期日期预填）
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { useRouter } from 'vue-router'
import type { EChartsOption } from 'echarts'
import {
  reportApi,
  type MovementsSummaryItem,
  type MovementsSummaryTotal,
  type ReportGranularity,
} from '../../api/report'
import ReportChart from '../../components/ReportChart.vue'
import { formatThousand, toLocalDateString } from '../../utils/format'

const router = useRouter()

// 默认近 30 天（本地时区拼接，toISOString 为 UTC 会偏移一天）
const dateRange = ref<[string, string]>([
  toLocalDateString(new Date(Date.now() - 29 * 86400000)),
  toLocalDateString(new Date()),
])
const granularity = ref<ReportGranularity>('day')
const items = ref<MovementsSummaryItem[]>([])
const totals = ref<MovementsSummaryTotal>({
  inbound_qty: '0',
  outbound_qty: '0',
  inbound_count: 0,
  outbound_count: 0,
})
const loading = ref(false)

const dateShortcuts = [
  { text: '今天', value: () => [new Date(), new Date()] },
  { text: '近 7 天', value: () => [new Date(Date.now() - 6 * 86400000), new Date()] },
  { text: '近 30 天', value: () => [new Date(Date.now() - 29 * 86400000), new Date()] },
]

// 净变动 = 总入库 - 总出库（后端字符串转数值；展示格式化在模板统一走 formatThousand，本地不再预格式化——D-16）
const netChange = computed(
  () => Number(totals.value.inbound_qty) - Number(totals.value.outbound_qty),
)

// 双线图：入库绿实线 / 出库红虚线（颜色+线型双编码，色盲友好；axis tooltip）
const chartOption = computed<EChartsOption>(() => ({
  tooltip: { trigger: 'axis' },
  legend: { data: ['入库量', '出库量'], top: 0 },
  grid: { left: 48, right: 16, top: 40, bottom: 32 },
  xAxis: { type: 'category', data: items.value.map((i) => i.period) },
  yAxis: { type: 'value' },
  series: [
    {
      name: '入库量',
      type: 'line',
      data: items.value.map((i) => Number(i.inbound_qty)),
      lineStyle: { color: '#059669', width: 2 },
      itemStyle: { color: '#059669' },
      smooth: true,
    },
    {
      name: '出库量',
      type: 'line',
      data: items.value.map((i) => Number(i.outbound_qty)),
      lineStyle: { color: '#DC2626', width: 2, type: 'dashed' },
      itemStyle: { color: '#DC2626' },
      smooth: true,
    },
  ],
}))

// 请求序号守卫：快速切换日期/粒度时旧响应不得覆盖新结果（bug #4 回归）
let requestSeq = 0

async function load() {
  const seq = ++requestSeq
  loading.value = true
  try {
    const res = await reportApi.movementsSummary({
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

// 下钻：日粒度用当日；月粒度展开为当月首日~末日（new Date(y, m, 0) 取末日技巧）
function drillDown(row: MovementsSummaryItem) {
  let from = row.period
  let to = row.period
  if (granularity.value === 'month') {
    const [y, m] = row.period.split('-').map(Number)
    from = `${row.period}-01`
    to = `${row.period}-${String(new Date(y, m, 0).getDate()).padStart(2, '0')}`
  }
  router.push({ path: '/inventory/movements', query: { date_from: from, date_to: to } })
}

onMounted(load)
</script>

<style scoped>
/* 样式遵循 design-system/nexus-factory/pages/report.md（KPI 卡/图表/下钻行） */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--space-xl);
  margin-bottom: var(--space-2xl);
}
.kpi-card {
  background: #fff;
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
  color: #64748b;
  margin-bottom: var(--space-md);
}
.kpi-value {
  font-size: 24px;
  font-weight: 700;
  color: var(--color-foreground);
}
.kpi-value.negative {
  color: #dc2626;
}
.chart-wrap {
  margin-bottom: var(--space-2xl);
}
.table-wrap {
  overflow-x: auto;
}
:deep(.drill-row) {
  cursor: pointer;
  transition: background-color 200ms ease;
}
:deep(.drill-row:hover) {
  background: #f8fafc;
}
</style>
