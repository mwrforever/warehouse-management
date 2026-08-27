<!-- 生产统计页：日期范围 + 成品筛选 + KPI（工单/计划/平均达成/平均良率）+ 表格（达成率分级标签 + 物料耗用展开行） -->
<template>
  <div class="page-card">
    <h2 class="page-title">生产统计</h2>
    <div class="toolbar">
      <el-date-picker
        v-model="dateRange"
        type="daterange"
        value-format="YYYY-MM-DD"
        :shortcuts="dateShortcuts"
        :clearable="false"
        @change="load"
      />
      <el-select
        v-model="productId"
        placeholder="全部成品"
        clearable
        filterable
        style="width: 200px"
        @change="load"
      >
        <el-option v-for="p in products" :key="p.id" :label="p.name" :value="p.id" />
      </el-select>
    </div>

    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-label">工单数</div>
        <div class="kpi-value font-code">{{ totals.order_count }}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">总计划数</div>
        <div class="kpi-value font-code">{{ formatThousand(totalPlan) }}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">平均达成率</div>
        <div class="kpi-value font-code">{{ avgAchievement }}%</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">平均良率</div>
        <div class="kpi-value font-code">{{ avgYield }}%</div>
      </div>
    </div>

    <div class="table-wrap">
      <el-table v-loading="loading" :data="items" empty-text="暂无数据">
        <el-table-column type="expand">
          <template #default="{ row }">
            <!-- 物料耗用展开明细：编码/名称/耗用/单位 -->
            <el-table :data="row.material_used" size="small" empty-text="无物料耗用">
              <el-table-column prop="material_code" label="物料编码" width="140" />
              <el-table-column prop="material_name" label="物料名称" min-width="140" />
              <el-table-column label="耗用数量" min-width="120" align="right">
                <template #default="{ row: m }">
                  <span class="font-code">{{ formatThousand(m.used_qty) }}</span>
                </template>
              </el-table-column>
              <el-table-column prop="unit" label="单位" width="80" />
            </el-table>
          </template>
        </el-table-column>
        <el-table-column prop="order_no" label="工单号" min-width="150" />
        <el-table-column prop="product_name" label="成品" min-width="120" />
        <el-table-column label="计划" width="100" align="right">
          <template #default="{ row }">
            <span class="font-code">{{ formatThousand(row.quantity) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="完工" width="100" align="right">
          <template #default="{ row }">
            <span class="font-code">{{ formatThousand(row.completed_qty) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="达成率" width="110" align="center">
          <template #default="{ row }">
            <!-- 分级：≥100 深绿 / ≥80 琥珀 / <80 红（spec RPT-03 颜色分级） -->
            <el-tag :class="rateTagClass(Number(row.achievement_rate))">
              {{ row.achievement_rate }}%
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="合格" width="90" align="right">
          <template #default="{ row }">
            <span class="font-code">{{ formatThousand(row.qualified_qty) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="不良" width="90" align="right">
          <template #default="{ row }">
            <span class="font-code">{{ formatThousand(row.defective_qty) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="良率" width="110" align="center">
          <template #default="{ row }">
            <el-tag :class="rateTagClass(Number(row.yield_rate))"> {{ row.yield_rate }}% </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="总工时" width="100" align="right">
          <template #default="{ row }">
            <span class="font-code">{{ formatThousand(row.total_hours) }}</span>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>

<script setup lang="ts">
// 生产统计：计划日期窗口 + 成品筛选；KPI 平均达成率=Σ完工/Σ计划（加权）、平均良率=Σ合格/(Σ合格+Σ不良)
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { reportApi, type ProductionStatItem, type ProductionTotal } from '../../api/report'
import { productApi } from '../../api/product'
import { formatPercent, formatThousand, toLocalDateString } from '../../utils/format'

const dateRange = ref<[string, string]>([
  toLocalDateString(new Date(Date.now() - 29 * 86400000)),
  toLocalDateString(new Date()),
])
const productId = ref<number | undefined>(undefined)
const products = ref<{ id: number; name: string }[]>([])
const items = ref<ProductionStatItem[]>([])
// KPI 数据源=后端全区间 totals（>500 工单截断时 items 失真，totals 先于截断计算）
const totals = ref<ProductionTotal>({
  order_count: 0,
  total_plan: '0',
  total_completed: '0',
  total_qualified: '0',
  total_defective: '0',
})
const loading = ref(false)

const dateShortcuts = [
  { text: '今天', value: () => [new Date(), new Date()] },
  { text: '近 7 天', value: () => [new Date(Date.now() - 6 * 86400000), new Date()] },
  { text: '近 30 天', value: () => [new Date(Date.now() - 29 * 86400000), new Date()] },
]

// 总计划数（KPI；取后端全区间 totals，截断安全）
const totalPlan = computed(() => totals.value.total_plan)
// 平均达成率（加权口径：Σ完工/Σ计划；计划 0 防御 0.00；百分比格式化统一走 utils/format——D-16）
const avgAchievement = computed(() => {
  const plan = Number(totals.value.total_plan)
  const done = Number(totals.value.total_completed)
  if (plan <= 0) return '0.00'
  return formatPercent(done / plan)
})
// 平均良率（Σ合格/(Σ合格+Σ不良)；无不良→100.00）
const avgYield = computed(() => {
  const q = Number(totals.value.total_qualified)
  const d = Number(totals.value.total_defective)
  if (q + d <= 0) return '100.00'
  return formatPercent(q / (q + d))
})

// 达成率/良率分级样式（与 report.md 页覆盖一致）
function rateTagClass(rate: number): string {
  if (rate >= 100) return 'tag-done'
  if (rate >= 80) return 'tag-warn'
  return 'tag-danger'
}

// 请求序号守卫：快速切换日期/成品筛选时旧响应不得覆盖新结果（bug #4 回归）
let requestSeq = 0

async function load() {
  const seq = ++requestSeq
  loading.value = true
  try {
    const res = await reportApi.production({
      date_from: dateRange.value[0],
      date_to: dateRange.value[1],
      product_id: productId.value,
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

onMounted(async () => {
  load()
  // 成品下拉（可清空筛选）
  try {
    const p = await productApi.list({ type: 'finished', per_page: 100 })
    products.value = p.items.map((i) => ({ id: i.id, name: i.name }))
  } catch {
    // 下拉失败不阻断报表
  }
})
</script>

<style scoped>
/* 样式遵循 design-system/nexus-factory/pages/report.md（KPI 卡/分级标签/展开行） */
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
.table-wrap {
  overflow-x: auto;
}
/* 达成率/良率分级：深绿/琥珀/红（深绿 tag-done 本地定义：既有 tag-done 为订单页 scoped 样式跨组件不生效，warn/danger 同款本地定义） */
:deep(.tag-done) {
  --el-tag-bg-color: var(--a-50);
  --el-tag-border-color: var(--a-700);
  --el-tag-text-color: var(--a-700);
}
:deep(.tag-warn) {
  --el-tag-bg-color: var(--warn-100);
  --el-tag-border-color: var(--warn-400);
  --el-tag-text-color: var(--warn);
}
:deep(.tag-danger) {
  --el-tag-bg-color: var(--err-100);
  --el-tag-border-color: var(--err-400);
  --el-tag-text-color: var(--err);
}
</style>
