<!-- 工序报工页：选工单 → 工序步骤条（三态联动）→ 当前进行中工序报工卡片（on-blur 校验 + 提交防重复） -->
<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import {
  productionApi,
  type ProductionOperation,
  type ProductionOrderDetail,
  type ProductionOrderItem,
} from '../../api/production'
import { useAuthStore } from '../../stores/auth'

const auth = useAuthStore()
const route = useRoute()
const loading = ref(false)
const saving = ref(false)
// 生产中工单下拉（label 单号+成品）
const orders = ref<ProductionOrderItem[]>([])
const selectedOrder = ref<number | null>(null)
const detail = ref<ProductionOrderDetail | null>(null)
const operations = ref<ProductionOperation[]>([])
const reportForm = reactive({
  qualified_qty: null as number | null,
  defective_qty: 0 as number,
  hours: null as number | null,
  operator: '',
  remark: '',
})

// 当前进行中工序（无进行中 = 全部已完成）
const currentOp = computed(() => operations.value.find((o) => o.status === 1) ?? null)
// 工单计划数（合格数上限校验数据源）
const orderQuantity = computed(() => Number(detail.value?.quantity ?? 0))
// 步骤条 active：进行中工序下标；全部完成时指向最后一步
const activeStep = computed(() => {
  const idx = operations.value.findIndex((o) => o.status === 1)
  return idx >= 0 ? idx : Math.max(0, operations.value.length - 1)
})

// 工序状态 → el-steps 三态映射（待开工 wait / 进行中 process / 已完成 finish）
function stepStatus(status: number) {
  if (status === 1) return 'process'
  if (status === 2) return 'finish'
  return 'wait'
}

// 加载工序（选单/报工成功后调用，步骤条随工序状态自动推进）
async function loadOperations() {
  const orderId = selectedOrder.value
  if (!orderId) return
  loading.value = true
  try {
    detail.value = await productionApi.orderDetail(orderId)
    operations.value = detail.value.operations
    // 切换工单后重置报工表单（新工序从零报工）
    Object.assign(reportForm, {
      qualified_qty: null,
      defective_qty: 0,
      hours: null,
      operator: '',
      remark: '',
    })
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}

// 合格数 on-blur 校验：≥0 且 ≤ 计划数（超计划 1510 文案）
function validateQualified() {
  if (reportForm.qualified_qty == null) return
  if (Number(reportForm.qualified_qty) < 0) {
    ElMessage.warning('合格数不能为负数')
    reportForm.qualified_qty = null
    return
  }
  if (Number(reportForm.qualified_qty) > orderQuantity.value) {
    ElMessage.warning('合格数不能超过工单计划数量')
    reportForm.qualified_qty = null
  }
}

// 工时 on-blur 校验：≥0（1512 文案）
function validateHours() {
  if (reportForm.hours == null) return
  if (Number(reportForm.hours) < 0) {
    ElMessage.warning('工时不能为负数')
    reportForm.hours = null
  }
}

// 提交报工：校验链（合格数必填且 ≤ 计划数 → 工时 ≥0）→ report → 成功提示 → 重新加载工序（步骤条推进）
async function submitReport() {
  if (!currentOp.value) return
  if (reportForm.qualified_qty == null || Number(reportForm.qualified_qty) < 0) {
    ElMessage.warning('请填写合格的合格数')
    return
  }
  if (Number(reportForm.qualified_qty) > orderQuantity.value) {
    ElMessage.warning('合格数不能超过工单计划数量')
    return
  }
  if (reportForm.hours != null && Number(reportForm.hours) < 0) {
    ElMessage.warning('工时不能为负数')
    return
  }
  saving.value = true
  try {
    await productionApi.report(currentOp.value.id, {
      qualified_qty: Number(reportForm.qualified_qty),
      defective_qty: Number(reportForm.defective_qty) || 0,
      hours: reportForm.hours == null ? undefined : Number(reportForm.hours),
      operator: reportForm.operator.trim() || undefined,
      remark: reportForm.remark.trim() || undefined,
    })
    ElMessage.success('报工成功')
    Object.assign(reportForm, {
      qualified_qty: null,
      defective_qty: 0,
      hours: null,
      operator: '',
      remark: '',
    })
    // 重新加载工序：本工序完成/下一工序进行中，步骤条自动推进
    await loadOperations()
  } catch (e) {
    ElMessage.error((e as Error).message)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    // 仅生产中工单可报工（status=2，per_page 100 覆盖全量）
    orders.value = (await productionApi.orders({ status: 2, per_page: 100 })).items
  } catch (e) {
    ElMessage.error((e as Error).message)
  }
  // 路由直达：工单列表「报 工」跳转携带 order_id
  const orderId = Number(route.query.order_id)
  if (orderId) {
    selectedOrder.value = orderId
    await loadOperations()
  }
})
</script>
<template>
  <div class="page-card">
    <div class="toolbar">
      <span class="page-title">工序报工</span>
      <el-select
        v-model="selectedOrder"
        placeholder="选择工单"
        filterable
        clearable
        style="width: 340px"
        @change="loadOperations"
      >
        <el-option
          v-for="o in orders"
          :key="o.id"
          :label="`${o.no}（${o.product_name}）`"
          :value="o.id"
        />
      </el-select>
      <span v-if="selectedOrder && detail" class="order-meta">
        计划 {{ orderQuantity }} ｜ 已入库 {{ Number(detail.completed_qty) }}
      </span>
    </div>

    <!-- 工序步骤条：三态着色联动（待开工/进行中/已完成） -->
    <el-steps v-if="operations.length" :active="activeStep" align-center class="steps-bar">
      <el-step
        v-for="op in operations"
        :key="op.id"
        :title="`${op.seq}. ${op.process_name}`"
        :status="stepStatus(op.status)"
        :description="op.status_label"
      />
    </el-steps>

    <!-- 当前进行中工序报工卡片 -->
    <div v-if="currentOp" v-loading="loading" class="report-card">
      <div class="card-title">
        当前工序：{{ currentOp.process_name }}（已报合格 {{ Number(currentOp.qualified_qty) }} /
        计划 {{ orderQuantity }}）
      </div>
      <el-form :model="reportForm" label-width="90px" class="form-grid">
        <el-form-item label="合格数" required>
          <el-input-number
            v-model="reportForm.qualified_qty"
            :min="0"
            :precision="2"
            :controls="false"
            placeholder="≥0"
            style="width: 100%"
            @blur="validateQualified"
          />
        </el-form-item>
        <el-form-item label="不良数">
          <el-input-number
            v-model="reportForm.defective_qty"
            :min="0"
            :precision="2"
            :controls="false"
            style="width: 100%"
          />
          <div class="hint">不良数仅记录与统计，返修/报废流程后续版本提供</div>
        </el-form-item>
        <el-form-item label="工时">
          <el-input-number
            v-model="reportForm.hours"
            :min="0"
            :precision="2"
            :controls="false"
            placeholder="小时"
            style="width: 100%"
            @blur="validateHours"
          />
        </el-form-item>
        <el-form-item label="操作人">
          <el-input v-model="reportForm.operator" maxlength="50" placeholder="默认当前登录用户" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="reportForm.remark" maxlength="200" />
        </el-form-item>
      </el-form>
      <div class="footer-bar">
        <!-- 无报工提交权限则不展示提交入口（页面只读） -->
        <el-button
          v-if="auth.has('production.report.create')"
          class="btn-primary"
          :loading="saving"
          @click="submitReport"
          >提 交报工</el-button
        >
      </div>
    </div>
    <el-empty v-else-if="operations.length" description="工序已全部完成" />
    <el-empty v-else-if="!selectedOrder" description="请选择生产中工单" />
  </div>
</template>
<style scoped>
/* 工序报工页样式（nexus-factory）：步骤条三态联动 + 报工卡片表单，见 pages/production.md §3 */
.page-card {
  background: #fff;
  border-radius: 8px;
  box-shadow: var(--shadow-sm);
  padding: var(--space-2xl);
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--space-md);
  margin-bottom: var(--space-lg);
}
.page-title {
  font-size: 18px;
  font-weight: 600;
  color: var(--color-foreground);
  margin-right: var(--space-lg);
}
.btn-primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: #fff;
}
.btn-primary:hover {
  opacity: 0.9;
}
/* 顶部工单元信息（计划数/已入库数，Fira Code） */
.order-meta {
  font-family: 'Fira Code', monospace;
  font-size: 13px;
  color: var(--color-secondary);
}
/* 工序步骤条：上下留白与卡片分隔 */
.steps-bar {
  margin: var(--space-lg) 0 var(--space-2xl);
}
/* 报工卡片：浅灰底描边容器，突出当前进行中工序 */
.report-card {
  background: var(--color-muted);
  border: 1px solid var(--color-border);
  border-radius: 8px;
  padding: var(--space-2xl);
}
.card-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-foreground);
  margin-bottom: var(--space-xl);
}
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 var(--space-lg);
  max-width: 760px;
}
/* 不良数旁注：返修/报废后续版本提供 */
.hint {
  font-size: 12px;
  color: #94a3b8;
  margin-top: 4px;
  line-height: 1.5;
}
.footer-bar {
  margin-top: var(--space-xl);
}
</style>
