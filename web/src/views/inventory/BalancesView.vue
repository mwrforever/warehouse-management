<!-- 库存余额页：筛选 + 预警标签 + 行点击跳流水 + CSV 导出 -->
<template>
  <div class="page-card">
    <ListFilterBar
      v-model:keyword="query.keyword"
      title="库存余额"
      keyword-placeholder="商品编码/名称/条码"
      @keyword-change="() => load()"
      @search="search"
      @reset="reset"
      @refresh="refresh"
    >
      <el-select
        v-model="query.warehouse_id"
        placeholder="仓库"
        clearable
        style="width: 160px"
        @change="() => load()"
      >
        <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
      </el-select>
      <el-select
        v-model="query.type"
        placeholder="类型"
        clearable
        style="width: 130px"
        @change="() => load()"
      >
        <el-option label="原料" value="raw_material" />
        <el-option label="半成品" value="semi_finished" />
        <el-option label="成品" value="finished" />
      </el-select>
      <el-switch
        v-model="query.alert"
        :active-value="1"
        :inactive-value="0"
        active-text="仅看预警"
        @change="() => load()"
      />
      <template #actions>
        <el-button class="btn-secondary" :disabled="loading" @click="doExport">导 出</el-button>
      </template>
    </ListFilterBar>

    <el-table v-loading="loading" :data="list" @row-click="gotoMovements">
      <el-table-column prop="product_code" label="商品编码" width="130" class-name="font-code" />
      <el-table-column prop="product_name" label="商品名称" min-width="140" />
      <el-table-column label="类型" width="90">
        <template #default="{ row }">
          <el-tag :type="typeTagType(row.type)" size="small">{{ typeLabel(row.type) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="warehouse_name" label="仓库" width="100" />
      <el-table-column prop="location_name" label="库位" width="90" />
      <el-table-column label="数量" width="110" align="right">
        <template #default="{ row }">
          <span class="qty-cell">{{ row.quantity }}</span>
        </template>
      </el-table-column>
      <el-table-column label="下限" width="90" align="right">
        <template #default="{ row }">{{ row.safety_min }}</template>
      </el-table-column>
      <el-table-column label="上限" width="90" align="right">
        <template #default="{ row }">{{ row.safety_max }}</template>
      </el-table-column>
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag v-if="row.alert_level === 1" type="danger" size="small">低库存</el-tag>
          <el-tag v-else-if="row.alert_level === 2" type="warning" size="small">超上限</el-tag>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-model:current-page="query.page"
      :total="total"
      :page-size="query.per_page"
      layout="total, prev, pager, next"
      @current-change="refresh"
    />
  </div>
</template>

<script setup lang="ts">
// 库存余额页：列表/筛选/预警标签；点击行跳流水页并预填筛选；导出 CSV
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { inventoryApi, type BalanceItem, type ProductType } from '../../api/inventory'
import { warehouseApi } from '../../api/warehouse'
import ListFilterBar from '../../components/ListFilterBar.vue'
import { useListQuery } from '../../composables/useListQuery'

const router = useRouter()
const warehouses = ref<{ id: number; name: string }[]>([])

// 列表查询状态（统一组合式：防抖加载/查询/重置/刷新，请求序号守卫并发）
const { query, list, total, loading, load, search, reset, refresh } = useListQuery({
  defaultQuery: {
    keyword: '',
    warehouse_id: undefined as number | undefined,
    type: undefined as ProductType | undefined,
    alert: 0,
  },
  fetch: (q) => inventoryApi.balances({ ...q, keyword: q.keyword || undefined }),
  onError: (e) => ElMessage.error(e.message),
})

// 类型标签语义色（原料蓝/半成品琥珀/成品绿）
function typeLabel(type: ProductType): string {
  return type === 'raw_material' ? '原料' : type === 'semi_finished' ? '半成品' : '成品'
}
function typeTagType(type: ProductType): 'primary' | 'warning' | 'success' {
  return type === 'raw_material' ? 'primary' : type === 'semi_finished' ? 'warning' : 'success'
}

// 点击行：跳流水页并预填商品×仓库筛选
function gotoMovements(row: BalanceItem) {
  router.push({
    path: '/inventory/movements',
    query: { product_id: String(row.product_id), warehouse_id: String(row.warehouse_id) },
  })
}

// 导出 CSV：blob 下载（中文文件名）
async function doExport() {
  try {
    const blob = await inventoryApi.exportBalances({
      keyword: query.keyword || undefined,
      warehouse_id: query.warehouse_id,
      type: query.type,
      alert: query.alert,
    })
    // globalThis 前缀规避 eslint no-undef（flat config 未声明浏览器全局）
    const url = globalThis.URL.createObjectURL(blob)
    const a = globalThis.document.createElement('a')
    a.href = url
    a.download = `balances_${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    globalThis.URL.revokeObjectURL(url)
    ElMessage.success('导出成功')
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(async () => {
  search()
  // 仓库下拉（全量）
  try {
    const res = await warehouseApi.list({ per_page: 100 })
    warehouses.value = res.items.map((w) => ({ id: w.id, name: w.name }))
  } catch {
    // 下拉加载失败不阻断列表
  }
})
</script>

<style scoped>
/* 页面骨架：卡片容器（筛选栏样式由 ListFilterBar 提供） */
.page-card {
  background: #fff;
  border-radius: 8px;
  box-shadow: var(--shadow-sm);
  padding: var(--space-2xl);
}
/* 次按钮：透明底 + 石板灰描边（设计系统 MASTER.md Buttons） */
.btn-secondary {
  background: transparent;
  border-color: var(--color-primary);
  color: var(--color-primary);
  cursor: pointer;
}
/* 数量列强调：Fira Code 加粗（设计系统 inventory.md §2） */
.qty-cell {
  font-family: 'Fira Code', monospace;
  font-weight: 700;
}
.el-table :deep(.el-table__row) {
  cursor: pointer;
}
.el-table :deep(.el-table__row:hover td) {
  background: #f1f5f9;
}
</style>
