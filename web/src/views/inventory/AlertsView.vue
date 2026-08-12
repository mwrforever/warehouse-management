<!-- 库存预警页：顶部汇总 + KPI 卡片（level=1 红 / level=2 琥珀） -->
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">库存预警</span>
      <el-button class="btn-secondary" @click="load">刷 新</el-button>
    </div>
    <div class="summary-bar">
      低于下限 <span class="num-danger">{{ lowCount }}</span> 项 / 高于上限
      <span class="num-warn">{{ highCount }}</span> 项
    </div>
    <div v-loading="loading" class="alert-grid">
      <div
        v-for="a in items"
        :key="`${a.product_code}-${a.warehouse_name}`"
        class="alert-card"
        :class="a.level === 1 ? 'card-low' : 'card-high'"
      >
        <div class="card-title">
          <span class="product-name">{{ a.product_name }}</span>
          <span class="font-code product-code">{{ a.product_code }}</span>
        </div>
        <div class="card-wh">{{ a.warehouse_name }}</div>
        <div class="card-qty">
          <span class="font-code">{{ a.quantity }}</span>
          <span class="qty-unit">当前量</span>
        </div>
        <div class="card-limits">
          <span v-if="a.safety_min > 0">下限 {{ a.safety_min }}</span>
          <span v-if="a.safety_max > 0">上限 {{ a.safety_max }}</span>
        </div>
        <div class="card-gap" :class="a.level === 1 ? 'gap-low' : 'gap-high'">
          {{
            a.level === 1
              ? `低于下限 ${formatGap(a.safety_min, a.quantity)}`
              : `高于上限 ${formatGap(a.quantity, a.safety_max)}`
          }}
        </div>
      </div>
      <el-empty v-if="!loading && items.length === 0" description="暂无预警" />
    </div>
  </div>
</template>

<script setup lang="ts">
// 库存预警页：查询时计算的预警列表（上下限修改后立即生效）
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { inventoryApi, type AlertItem } from '../../api/inventory'

const items = ref<AlertItem[]>([])
const loading = ref(false)

// 汇总数：level=1 低于下限 / level=2 高于上限
const lowCount = ref(0)
const highCount = ref(0)

// 超额幅度（保留两位）
function formatGap(limit: number, qty: number): string {
  return Math.abs(Number(limit) - Number(qty)).toFixed(2)
}

async function load() {
  loading.value = true
  try {
    const res = await inventoryApi.alerts()
    items.value = res.items
    lowCount.value = res.items.filter((a) => a.level === 1).length
    highCount.value = res.items.filter((a) => a.level === 2).length
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
/* 汇总条 + 卡片网格（设计系统 inventory.md §5） */
.summary-bar {
  background: #f8fafc;
  border-radius: 8px;
  padding: var(--space-lg) var(--space-xl);
  margin-bottom: var(--space-xl);
  font-size: 14px;
}
.num-danger {
  color: #dc2626;
  font-weight: 700;
}
.num-warn {
  color: #d97706;
  font-weight: 700;
}
.alert-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: var(--space-lg);
  min-height: 120px;
}
.alert-card {
  background: #fff;
  border-radius: 8px;
  padding: var(--space-xl);
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
  border-left: 4px solid #cbd5e1;
}
.card-low {
  border-left-color: #dc2626;
}
.card-high {
  border-left-color: #d97706;
}
.card-title {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
}
.product-name {
  font-weight: 600;
}
.product-code {
  color: #64748b;
  font-size: 12px;
}
.card-wh {
  color: #64748b;
  font-size: 13px;
  margin: var(--space-sm) 0 var(--space-md);
}
.card-qty {
  font-size: 20px;
  font-weight: 700;
  display: flex;
  align-items: baseline;
  gap: var(--space-sm);
}
.qty-unit {
  font-size: 12px;
  color: #94a3b8;
  font-weight: 400;
}
.card-limits {
  display: flex;
  gap: var(--space-xl);
  color: #64748b;
  font-size: 13px;
  margin-top: var(--space-md);
}
.card-gap {
  margin-top: var(--space-sm);
  font-size: 13px;
  font-weight: 600;
}
.gap-low {
  color: #dc2626;
}
.gap-high {
  color: #d97706;
}
</style>
