<!-- 工艺路线管理页：列表（筛选：关键字+成品）+ 画布编辑/详情弹窗 + 启停/删除 -->
<template>
  <div class="page-card">
    <ListFilterBar
      v-model:keyword="query.keyword"
      title="工艺路线"
      keyword-placeholder="路线编码"
      @keyword-change="() => load()"
      @search="search"
      @reset="reset"
      @refresh="refresh"
    >
      <el-select
        v-model="query.product_id"
        clearable
        filterable
        remote
        :remote-method="searchFinished"
        :loading="finishedLoading"
        placeholder="输入编码/名称筛选成品"
        style="width: 200px"
        @change="onProductFilterChange"
      >
        <el-option
          v-for="p in finishedProducts"
          :key="p.id"
          :label="`${p.code} ${p.name}`"
          :value="p.id"
        />
      </el-select>
      <template #actions>
        <el-button v-if="auth.has('routing.create')" class="btn-primary" @click="openCreate"
          >新 建</el-button
        >
      </template>
    </ListFilterBar>
    <el-table v-loading="loading" :data="list">
      <el-table-column prop="code" label="路线编码" width="170" class-name="font-code" />
      <el-table-column prop="product_name" label="成品名称" min-width="140" />
      <el-table-column prop="version" label="版本" width="80" class-name="font-code" />
      <el-table-column label="基准数量" width="90" align="right">
        <template #default="{ row }">{{ formatThousand(row.quantity) }}</template>
      </el-table-column>
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.status === 1 ? 'success' : 'info'">{{
            row.status_label || (row.status === 1 ? '启用' : '停用')
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip />
      <el-table-column label="操作" width="300" fixed="right">
        <template #default="{ row }">
          <el-button v-if="auth.has('routing.update')" link type="primary" @click="openCanvas(row)"
            >画布编辑</el-button
          >
          <el-button v-if="auth.has('routing.list')" link type="primary" @click="openDetail(row)"
            >详 情</el-button
          >
          <el-button
            v-if="auth.has('routing.update')"
            link
            :type="row.status === 1 ? 'warning' : 'success'"
            @click="toggle(row)"
            >{{ row.status === 1 ? '停 用' : '启 用' }}</el-button
          >
          <el-button v-if="auth.has('routing.delete')" link type="danger" @click="remove(row)"
            >删 除</el-button
          >
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

    <!-- 画布编辑/详情为子页面（/master/routings/canvas），列表仅提供入口跳转 -->
  </div>
</template>

<script setup lang="ts">
// 工艺路线列表页：仅做筛选/编排与启停删；DAG 编辑在画布子页面（RoutingCanvasView）内完成
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { routingApi, type RoutingListItem } from '../../api/routing'
import { productApi } from '../../api/product'
import { useAuthStore } from '../../stores/auth'
import ListFilterBar from '../../components/ListFilterBar.vue'
import { useListQuery } from '../../composables/useListQuery'
import { useRemoteOptions } from '../../composables/useRemoteOptions'
import { formatThousand } from '../../utils/format'

const auth = useAuthStore()
// 列表查询状态（成品筛选为可空下拉，空值不参与过滤）
const { query, list, total, loading, load, search, reset, refresh } = useListQuery({
  defaultQuery: { keyword: '', product_id: null as number | null },
  fetch: (q) => routingApi.list(q),
  onError: (e) => ElMessage.error(e.message),
})

// 筛选成品下拉选项（BF-3 remote）：label 编码+名称；画布内商品下拉由 RoutingCanvasEditor 自管
interface ProductOption {
  id: number
  name: string
  code: string
}

// 筛选成品下拉（BF-3）：成品档案超 100 条后以编码/名称/条码关键字服务端搜索，初载保留前 100 条
const {
  options: finishedProducts,
  loading: finishedLoading,
  load: loadFinished,
  search: searchFinished,
  pin: pinFinished,
} = useRemoteOptions<ProductOption>({
  fetch: (kw) =>
    productApi.list({ page: 1, per_page: 100, type: 'finished', keyword: kw }).then((r) => r.items),
  keyOf: (p) => p.id,
  onError: (e) => ElMessage.error(e.message),
})

// 选中成品后 pin：后续关键字搜索替换选项时已选值仍显示名称（防裸 id），再触发列表查询
function onProductFilterChange(productId: number | null | undefined) {
  if (productId != null) {
    const hit = finishedProducts.value.find((p) => p.id === productId)
    if (hit) pinFinished(hit)
  }
  load()
}
// 画布子页面入口：routing_id/mode 经 query 传递（新建不带 id；详情 mode=view）
const router = useRouter()

function openCreate() {
  void router.push('/master/routings/canvas')
}
function openCanvas(row: RoutingListItem) {
  void router.push({ path: '/master/routings/canvas', query: { routing_id: String(row.id) } })
}
function openDetail(row: RoutingListItem) {
  void router.push({
    path: '/master/routings/canvas',
    query: { routing_id: String(row.id), mode: 'view' },
  })
}

// 启用/停用：启用前提示将自动停用同成品其他版本（后端保证同成品启用唯一）
async function toggle(row: RoutingListItem) {
  const enabling = row.status !== 1
  const tip = enabling
    ? `启用将自动停用「${row.product_name}」的其他启用版本，确定启用？`
    : `确定停用工艺路线「${row.code}」？`
  try {
    await ElMessageBox.confirm(tip, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await routingApi.toggle(row.id, enabling ? 1 : 0)
    ElMessage.success(enabling ? '已启用' : '已停用')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

// 删除：二次确认；被工单引用时后端提示
async function remove(row: RoutingListItem) {
  try {
    await ElMessageBox.confirm(`确定删除工艺路线「${row.code}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await routingApi.remove(row.id)
    ElMessage.success('删除成功')
    refresh()
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
}

onMounted(() => {
  search()
  // 筛选成品下拉初载（BF-3 remote 模式保留前 100 条）；画布弹窗内部自拉编辑所需数据
  void loadFinished()
})
</script>

<style scoped>
/* 页面骨架与 BOM 管理页一致 */
.page-card {
  background: var(--surface);
  border-radius: 8px;
  box-shadow: var(--shadow-sm);
  padding: var(--space-2xl);
}
.btn-primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  cursor: pointer;
}
</style>
