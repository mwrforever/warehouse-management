<!-- 库存流水页：筛选 + 方向色 + 单号链接跳转/提示 -->
<template>
  <div class="page-card">
    <ListFilterBar title="库存流水" @search="search" @reset="reset" @refresh="refresh">
      <el-select
        v-model="query.product_id"
        placeholder="商品（可搜索）"
        clearable
        filterable
        style="width: 200px"
        @change="() => load()"
      >
        <el-option v-for="p in products" :key="p.id" :label="`${p.code} ${p.name}`" :value="p.id" />
      </el-select>
      <el-select
        v-model="query.warehouse_id"
        placeholder="仓库"
        clearable
        style="width: 130px"
        @change="() => load()"
      >
        <el-option v-for="w in warehouses" :key="w.id" :label="w.name" :value="w.id" />
      </el-select>
      <el-select
        v-model="query.source_type"
        placeholder="单据类型"
        clearable
        style="width: 140px"
        @change="() => load()"
      >
        <el-option
          v-for="(label, key) in sourceTypeLabels"
          :key="key"
          :label="label"
          :value="key"
        />
      </el-select>
      <el-select
        v-model="query.direction"
        placeholder="方向"
        clearable
        style="width: 110px"
        @change="() => load()"
      >
        <el-option label="入库 +" :value="1" />
        <el-option label="出库 -" :value="-1" />
      </el-select>
      <el-date-picker
        v-model="dateRange"
        type="daterange"
        range-separator="至"
        start-placeholder="开始日期"
        end-placeholder="结束日期"
        :shortcuts="dateShortcuts"
        style="width: 250px"
      />
    </ListFilterBar>

    <el-table v-loading="loading" :data="list">
      <el-table-column label="时间" width="170">
        <template #default="{ row }">{{ row.created_at }}</template>
      </el-table-column>
      <el-table-column label="单号" width="170">
        <template #default="{ row }">
          <a class="source-no" @click.prevent="gotoSource(row)">{{ row.source_no }}</a>
        </template>
      </el-table-column>
      <el-table-column prop="product_name" label="商品" min-width="130" />
      <el-table-column label="仓库/库位" width="140">
        <template #default="{ row }">{{ row.warehouse_name }} / {{ row.location_name }}</template>
      </el-table-column>
      <el-table-column label="方向" width="70">
        <template #default="{ row }">
          <span :class="row.direction === 1 ? 'dir-in' : 'dir-out'">{{
            row.direction === 1 ? '+' : '-'
          }}</span>
        </template>
      </el-table-column>
      <el-table-column label="数量" width="100" align="right">
        <template #default="{ row }"
          ><span class="font-code qty-cell">{{ row.quantity }}</span></template
        >
      </el-table-column>
      <el-table-column label="变动后余额" width="110" align="right">
        <template #default="{ row }"
          ><span class="font-code">{{ row.balance_after }}</span></template
        >
      </el-table-column>
      <el-table-column label="类型" width="100">
        <template #default="{ row }"
          ><el-tag size="small">{{ row.source_type_label }}</el-tag></template
        >
      </el-table-column>
      <el-table-column prop="operator_name" label="操作人" width="100" />
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
// 库存流水页：筛选（商品/仓库/类型/方向/日期）；单号点击跳对应单据（盘点类跳详情，其余提示）
import { nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { inventoryApi, type MovementItem } from '../../api/inventory'
import { productApi } from '../../api/product'
import { warehouseApi } from '../../api/warehouse'
import ListFilterBar from '../../components/ListFilterBar.vue'
import { useListQuery } from '../../composables/useListQuery'
import { toLocalDateString } from '../../utils/format'

const route = useRoute()
const router = useRouter()
const products = ref<{ id: number; code: string; name: string }[]>([])
const warehouses = ref<{ id: number; name: string }[]>([])
// 日期范围独立于 query（不并入重置，watch 变更即防抖查询）
const dateRange = ref<[Date, Date] | null>(null)

// 列表查询状态（统一组合式：防抖加载/查询/重置/刷新，请求序号守卫并发）
const { query, list, total, loading, load, search, reset, refresh } = useListQuery({
  defaultQuery: {
    product_id: undefined as number | undefined,
    warehouse_id: undefined as number | undefined,
    source_type: undefined as string | undefined,
    direction: undefined as number | undefined,
  },
  fetch: (q) =>
    inventoryApi.movements({
      ...q,
      date_from: dateRange.value ? toLocalDateString(dateRange.value[0]) : undefined,
      date_to: dateRange.value ? toLocalDateString(dateRange.value[1]) : undefined,
    }),
  onError: (e) => ElMessage.error(e.message),
})
// 日期范围变更：防抖查询（spec §4.2 日期选择器口径）
watch(dateRange, () => load())

// 单据类型中文标签（与后端枚举一致）
const sourceTypeLabels: Record<string, string> = {
  purchase_inbound: '采购入库',
  sales_outbound: '销售出库',
  pick: '领料出库',
  return: '退料入库',
  finished_inbound: '成品入库',
  outsourcing_out: '委外发出',
  outsourcing_in: '委外回收',
  check_in: '盘盈',
  check_out: '盘亏',
}

// 日期快捷项：今天/近7天/近30天
const dateShortcuts = [
  { text: '今天', value: () => [new Date(), new Date()] },
  { text: '近 7 天', value: () => [new Date(Date.now() - 6 * 86400000), new Date()] },
  { text: '近 30 天', value: () => [new Date(Date.now() - 29 * 86400000), new Date()] },
]

// 本地日期字符串解析（new Date('YYYY-MM-DD') 按 UTC 解析，负时区会偏移一天 → 手动拆解构造）
function parseLocalDate(s: string): Date {
  const [y, m, d] = s.split('-').map(Number)
  return new Date(y, m - 1, d)
}

// 流水单号点击：盘点来源跳盘点详情；采购入库来源跳采购入库单详情；销售出库来源跳销售出库单详情；其余提示模块未开放
function gotoSource(row: MovementItem) {
  if (row.source_type === 'check_in' || row.source_type === 'check_out') {
    router.push(`/inventory/checks/${row.source_id}`)
  } else if (row.source_type === 'purchase_inbound') {
    router.push(`/purchase/inbounds/${row.source_id}`)
  } else if (row.source_type === 'sales_outbound') {
    router.push(`/sales/outbounds/${row.source_id}`)
  } else {
    ElMessage.info(`${row.source_type_label}单据页随对应模块实施后开放`)
  }
}

onMounted(async () => {
  // 从余额页行点击带入的预填筛选
  const q = route.query as Record<string, string>
  if (q.product_id) query.product_id = Number(q.product_id)
  if (q.warehouse_id) query.warehouse_id = Number(q.warehouse_id)
  // 报表下钻带入的日期闭区间预填（出入库汇总行点击跳转链路）
  if (q.date_from && q.date_to) {
    dateRange.value = [parseLocalDate(q.date_from), parseLocalDate(q.date_to)]
  }
  // 先让 watch 消费日期预填（其挂起的防抖 load 会被 search 取消，避免与立即查询重复请求），再立即查询
  await nextTick()
  search()
  // 商品/仓库下拉（全量）
  try {
    const p = await productApi.list({ per_page: 100 })
    products.value = p.items.map((i) => ({ id: i.id, code: i.code, name: i.name }))
  } catch {
    // 下拉失败不阻断列表
  }
  try {
    const w = await warehouseApi.list({ per_page: 100 })
    warehouses.value = w.items.map((i) => ({ id: i.id, name: i.name }))
  } catch {
    // 下拉失败不阻断列表
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
/* 方向色：+绿 / -红（设计系统 inventory.md §3） */
.dir-in {
  color: #059669;
  font-weight: 700;
  font-family: 'Fira Code', monospace;
}
.dir-out {
  color: #dc2626;
  font-weight: 700;
  font-family: 'Fira Code', monospace;
}
.qty-cell {
  font-weight: 600;
}
.source-no {
  color: #334155;
  font-family: 'Fira Code', monospace;
  cursor: pointer;
  text-decoration: underline;
  text-decoration-color: #cbd5e1;
}
.source-no:hover {
  color: #059669;
}
</style>
