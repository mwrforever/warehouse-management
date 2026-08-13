<!-- 库存报表页：维度切换（radio 即刷新）+ KPI 卡（种类/总量/预警）+ 分组表格（数量占比横向条） -->
<template>
  <div class="page-card">
    <h2 class="page-title">库存报表</h2>
    <div class="toolbar">
      <el-radio-group v-model="groupBy" @change="load">
        <el-radio-button value="category">按分类</el-radio-button>
        <el-radio-button value="warehouse">按仓库</el-radio-button>
        <el-radio-button value="type">按类型</el-radio-button>
      </el-radio-group>
    </div>

    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-label">商品种类数</div>
        <div class="kpi-value font-code">{{ total.product_count }}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">库存总量</div>
        <div class="kpi-value font-code">{{ formatThousand(total.quantity_total) }}</div>
      </div>
      <div class="kpi-card">
        <div class="kpi-label">预警商品数</div>
        <div class="kpi-value font-code">{{ alertCount }}</div>
      </div>
    </div>

    <div class="table-wrap">
      <el-table v-loading="loading" :data="items" empty-text="暂无数据">
        <el-table-column prop="group_name" label="维度" min-width="140" />
        <el-table-column prop="product_count" label="商品种类" width="110" align="right" />
        <el-table-column label="总数量" min-width="140" align="right">
          <template #default="{ row }">
            <span class="font-code">{{ formatThousand(row.quantity_total) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="数量占比" min-width="220">
          <template #default="{ row }">
            <!-- 横向条形：灰底轨道 + 绿填充（宽度=占比）；数量为 0 时占比 0 防除零 -->
            <div class="bar-track">
              <div class="bar-fill" :style="{ width: percent(row) }" />
              <span class="bar-text">{{ percent(row) }}</span>
            </div>
          </template>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>

<script setup lang="ts">
// 库存报表：维度切换即时重新请求；预警商品数由预警接口实时计算（与库存预警页同口径）
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  reportApi,
  type InventorySummaryItem,
  type InventorySummaryTotal,
  type ReportGroupBy,
} from '../../api/report'
import { inventoryApi } from '../../api/inventory'
import { formatThousand } from '../../utils/format'

const groupBy = ref<ReportGroupBy>('category')
const items = ref<InventorySummaryItem[]>([])
const total = ref<InventorySummaryTotal>({
  quantity_total: '0',
  product_count: 0,
  amount_total: null,
})
const alertCount = ref(0)
const loading = ref(false)

// 数量占比：行数量/总量（总量 0 时占比 0，防除零）
function percent(row: InventorySummaryItem): string {
  const t = Number(total.value.quantity_total)
  const v = Number(row.quantity_total)
  if (t <= 0) return '0.00%'
  return `${((v / t) * 100).toFixed(2)}%`
}

async function load() {
  loading.value = true
  try {
    const res = await reportApi.inventorySummary({ group_by: groupBy.value })
    items.value = res.items
    total.value = res.total
    // 预警商品数：库存预警接口按商品去重（低库存或超上限任一行命中即计入）
    const alerts = await inventoryApi.alerts()
    alertCount.value = new Set(alerts.items.map((a) => a.product_code)).size
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
/* 样式遵循 design-system/nexus-factory/pages/report.md（KPI 卡/表格/条形） */
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
.table-wrap {
  overflow-x: auto;
}
.bar-track {
  position: relative;
  background: #f2f3f4;
  border-radius: 4px;
  height: 20px;
  overflow: hidden;
}
.bar-fill {
  height: 100%;
  background: #059669;
  transition: width 300ms ease;
}
.bar-text {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  color: var(--color-foreground);
}
</style>
