<!-- 扫码录入独立弹窗（spec §4.4）：不混入新增表单。
     两个开关（逐件扫描默认关 / 自动累加默认开）正交；关闭弹窗将明细行带回宿主页合并。
     宿主页经 props 传入已存在行商品 id（excludedIds）、禁扫类型、数量上限与自定义解析钩子 -->
<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { useScanInbound, type ScanItem } from '../composables/useScanInbound'

interface Props {
  open: boolean
  title?: string
  /** 宿主明细已存在的商品 id（累加关时判重报错） */
  excludedIds?: number[]
  /** 数量上限（如工单/订单剩余量） */
  maxQuantity?: (item: ScanItem) => number
  /** 禁扫商品类型（销售场景 raw_material） */
  blockedType?: string
  /** 自定义商品→行解析（盘点账面校验等） */
  resolveProduct?: (p: {
    id: number
    name: string
    code: string
    type: string
    spec: string | null
    unit_name: string | null
  }) => ScanItem | null
}
const props = withDefaults(defineProps<Props>(), {
  title: '扫码录入',
  excludedIds: undefined,
  maxQuantity: undefined,
  blockedType: undefined,
  resolveProduct: undefined,
})
const emit = defineEmits<{
  'update:open': [value: boolean]
  'add-items': [items: ScanItem[]]
}>()

const scan = useScanInbound({
  excludedIds: () => props.excludedIds ?? [],
  maxQuantity: props.maxQuantity,
  blockedType: props.blockedType,
  resolveProduct: props.resolveProduct,
  onError: (msg) => {
    // 提示用 ElMessage 动态导入（组件内不直接 import 常量，避免与页面重复）
    void import('element-plus').then(({ ElMessage }) => ElMessage.error(msg))
  },
})

// 弹窗打开自动聚焦扫码框（nextTick + ref，spec §4.4 交互流程 1）
watch(
  () => props.open,
  async (open) => {
    if (open) {
      await nextTick()
      scan.inputRef.value?.focus()
    } else {
      // 关闭时取消进行中的防抖/请求并重置，防止卸载后 setState（spec §7）
      scan.reset()
    }
  },
)

function onClose() {
  emit('update:open', false)
  emit('add-items', scan.rows.value)
}

const pendingRef = ref<{ focus: () => void } | null>(null)
// 逐件关：进入待填数量态后聚焦数量框
watch(scan.pending, async (p) => {
  if (p) {
    await nextTick()
    pendingRef.value?.focus()
  }
})
</script>

<template>
  <el-dialog
    :model-value="open"
    :title="title"
    width="640px"
    :close-on-click-modal="false"
    @update:model-value="onClose"
  >
    <!-- 两个开关：逐件扫描默认关 / 自动累加默认开（正交语义见 spec §4.4） -->
    <div class="switches">
      <el-switch v-model="scan.perItem.value" active-text="逐件扫描" inactive-text="逐件扫描" />
      <el-switch
        v-model="scan.autoAccumulate.value"
        active-text="自动累加"
        inactive-text="自动累加"
      />
      <span class="switch-hint">
        {{ scan.perItem.value ? '扫一次数量直接 +1' : '扫一次后填写本次数量' }}｜
        {{ scan.autoAccumulate.value ? '同条码自动合并累加' : '同条码再次扫描将报错' }}
      </span>
    </div>

    <el-input
      ref="scan.inputRef"
      v-model="scan.barcode.value"
      placeholder="扫描条码回车添加商品"
      clearable
      class="barcode-input"
      @keyup.enter="scan.handleScan"
    />

    <!-- 逐件关：待填数量确认区 -->
    <div v-if="scan.pending.value" class="pending-row">
      <span class="pending-label">
        {{ scan.pending.value.name }}（{{ scan.pending.value.code }}）
      </span>
      <el-input-number
        ref="pendingRef"
        v-model="scan.pendingQty.value"
        :min="1"
        :precision="2"
        :controls="false"
        placeholder="数量"
        style="width: 140px"
        @keyup.enter="scan.submitPending"
      />
      <el-button class="btn-primary" @click="scan.submitPending">确 定</el-button>
      <el-button @click="scan.dismissPending">取 消</el-button>
    </div>

    <!-- 本次扫码行预览 -->
    <el-table
      v-if="scan.rows.value.length"
      :data="scan.rows.value"
      size="small"
      max-height="220"
      class="preview-table"
    >
      <el-table-column prop="name" label="商品" min-width="160" />
      <el-table-column prop="code" label="编码" class-name="font-code" min-width="100" />
      <el-table-column prop="quantity" label="数量" align="right" width="100" />
    </el-table>
    <el-empty v-else description="扫描条码添加商品" :image-size="60" />
  </el-dialog>
</template>

<style scoped>
.switches {
  display: flex;
  align-items: center;
  gap: var(--space-xl);
  margin-bottom: var(--space-lg);
}
.switch-hint {
  font-size: 12px;
  color: var(--color-secondary);
}
.barcode-input {
  margin-bottom: var(--space-md);
}
.pending-row {
  display: flex;
  align-items: center;
  gap: var(--space-lg);
  padding: var(--space-md) var(--space-lg);
  background: var(--color-muted);
  border-radius: 8px;
  margin-bottom: var(--space-md);
}
.pending-label {
  font-size: 14px;
  color: var(--color-foreground);
}
.preview-table {
  margin-top: var(--space-md);
}
</style>
