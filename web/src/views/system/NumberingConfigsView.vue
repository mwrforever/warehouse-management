<!-- 编号规则页（Spec 2）：13 类单据/商品编码的 prefix/date_format/seq_length 配置；编辑弹窗带实时预览；
     修改 seq_length/date_format 时弹确认（仅影响新生成单号，位宽一致性需评审） -->
<template>
  <div class="page">
    <div class="page-head">
      <h2>编号规则</h2>
      <p class="sub">配置单据号与商品编码的生成格式；仅影响新生成的编号，存量单号保持不变</p>
    </div>
    <el-table v-loading="loading" :data="rows">
      <el-table-column prop="type_label" label="类型" width="130" />
      <el-table-column prop="type" label="类型键" width="100" class-name="font-code" />
      <el-table-column prop="prefix" label="前缀" width="90" class-name="font-code" />
      <el-table-column label="日期格式" width="130">
        <template #default="{ row }">{{ dateFormatLabel(row.date_format) }}</template>
      </el-table-column>
      <el-table-column prop="seq_length" label="序列长度" width="90" />
      <el-table-column label="状态" width="90">
        <template #default="{ row }">
          <el-tag :type="row.enabled ? 'success' : 'info'">{{
            row.enabled ? '启用' : '停用'
          }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="90">
        <template #default="{ row }">
          <el-button
            v-if="auth.has('system.setting.update')"
            link
            type="primary"
            @click="openEdit(row)"
            >编 辑</el-button
          >
        </template>
      </el-table-column>
    </el-table>

    <!-- 编辑弹窗：字段变更即触发预览；位宽相关变更保存前确认 -->
    <el-dialog v-model="dialogVisible" title="编辑编号规则" width="520px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="110px">
        <el-form-item label="类型">{{ form.type_label }}（{{ form.type }}）</el-form-item>
        <el-form-item label="前缀" prop="prefix">
          <el-input
            v-model="form.prefix"
            maxlength="4"
            style="width: 160px"
            placeholder="大写字母 2~4 位"
          />
        </el-form-item>
        <el-form-item label="日期格式">
          <el-select v-model="form.date_format" style="width: 160px">
            <el-option label="无（全局自增）" value="" />
            <el-option label="年月日 Ymd" value="Ymd" />
            <el-option label="年月日时 YmdHi" value="YmdHi" />
            <el-option label="年月日时分秒 YmdHis" value="YmdHis" />
          </el-select>
        </el-form-item>
        <el-form-item label="序列长度" required>
          <el-input-number v-model="form.seq_length" :min="1" :max="10" />
        </el-form-item>
        <el-form-item label="状态"
          ><el-switch v-model="form.enabled" :active-value="true" :inactive-value="false"
        /></el-form-item>
        <el-form-item label="备注" prop="remark"
          ><el-input v-model="form.remark" type="textarea" :rows="2" maxlength="255"
        /></el-form-item>
        <el-form-item v-if="previewNo" label="规则预览">
          <span class="font-code">{{ previewNo }}</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取 消</el-button>
        <el-button type="primary" class="btn-primary" :loading="saving" @click="save"
          >保 存</el-button
        >
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
// 编号规则管理：列表 + 编辑弹窗 + 实时预览；seq_length/date_format 变更前确认位宽一致性
import { onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import { useAuthStore } from '../../stores/auth'
import { systemSettingApi, type NumberConfigItem } from '../../api/systemSetting'
import { debounce } from '../../utils/async'

const auth = useAuthStore()
const rows = ref<NumberConfigItem[]>([])
const loading = ref(false)
const dialogVisible = ref(false)
const saving = ref(false)
const previewNo = ref('')
// 弹窗表单引用：保存前统一触发 el-form 校验
const formRef = ref<FormInstance>()
// 编辑表单（enabled 布尔；原始值快照用于变更确认）
const form = reactive({
  id: 0,
  type: '',
  type_label: '',
  prefix: '',
  date_format: '',
  seq_length: 3,
  enabled: true,
  remark: '',
})
const origin = reactive({ date_format: '', seq_length: 0 })

// 表单校验（对齐后端 422 规则，把可预期的失败拦截在提交前）：prefix 允许编辑中暂空
// （空值跳过正则，提交后由后端 required 兜底），非空时必须 2~4 位大写字母；备注上限 255 字
const rules: FormRules = {
  prefix: [{ pattern: /^[A-Z]{2,4}$/, message: '前缀须为 2~4 位大写字母', trigger: 'blur' }],
  remark: [{ max: 255, message: '备注不能超过 255 个字符', trigger: 'blur' }],
}

const DATE_FORMAT_LABELS: Record<string, string> = {
  '': '无',
  Ymd: '年月日',
  YmdHi: '年月日时',
  YmdHis: '年月日时分秒',
}

function dateFormatLabel(v: string) {
  return DATE_FORMAT_LABELS[v] ?? v
}

async function load() {
  loading.value = true
  try {
    const res = await systemSettingApi.list()
    rows.value = res.items
  } catch (e) {
    // 首屏加载失败必须反馈，避免 rejection 无人接住导致页面静默空白（BF-5）
    ElMessage.error((e as Error).message)
  } finally {
    loading.value = false
  }
}

function openEdit(row: NumberConfigItem) {
  Object.assign(form, {
    id: row.id,
    type: row.type,
    type_label: row.type_label,
    prefix: row.prefix,
    date_format: row.date_format,
    seq_length: row.seq_length,
    enabled: row.enabled,
    remark: row.remark,
  })
  Object.assign(origin, { date_format: row.date_format, seq_length: row.seq_length })
  previewNo.value = ''
  dialogVisible.value = true
}

// 编辑弹窗内实时预览：值变化即调预览接口；序号守卫保证并发乱序响应不覆盖新值（对齐 useListQuery 模式）
let previewSeq = 0
async function refreshPreview() {
  if (!form.prefix) return
  const seq = ++previewSeq
  try {
    const res = await systemSettingApi.preview({
      prefix: form.prefix,
      date_format: form.date_format,
      seq_length: form.seq_length,
    })
    if (seq !== previewSeq) return // 过期响应：已有更新的预览请求，丢弃防止回写旧示例号
    previewNo.value = res.no
  } catch {
    if (seq !== previewSeq) return
    previewNo.value = ''
  }
}

// 300ms 防抖：弹窗内逐字符输入/连续调整只发最后一次请求（项目 debounce 工具，ListFilterBar 同款间隔）
const refreshPreviewDebounced = debounce(refreshPreview, 300)

// 弹窗打开/字段变化均刷新预览（watch 触发对 el-input fill/el-input-number 步进等交互最稳，如 E2E fill）
watch([() => form.prefix, () => form.date_format, () => form.seq_length], () =>
  refreshPreviewDebounced(),
)

// 卸载清理：取消挂起的防抖并作废在途预览请求，防止卸载后回写（对齐 useListQuery.cancel 模式）
onUnmounted(() => {
  refreshPreviewDebounced.cancel()
  previewSeq++
})

async function save() {
  // 提交前校验：非法 prefix/超长 remark 拦在前端，避免发出可预期的 422 请求
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return
  // 位宽相关变更（seq_length/date_format）影响长度一致性：确认仅作用于新生成单号（spec §5/§7）
  const changed = form.seq_length !== origin.seq_length || form.date_format !== origin.date_format
  if (changed) {
    try {
      await ElMessageBox.confirm(
        '修改序列长度/日期格式仅影响新生成的编号。请确认与存量单号位宽一致后继续。',
        '位宽一致性提示',
        { type: 'warning' },
      )
    } catch {
      return
    }
  }
  saving.value = true
  try {
    await systemSettingApi.update(form.id, {
      prefix: form.prefix,
      date_format: form.date_format,
      seq_length: form.seq_length,
      enabled: form.enabled,
      remark: form.remark,
    })
    ElMessage.success('已保存')
    dialogVisible.value = false
    await load()
  } catch (e) {
    // 保存失败必须反馈：http.ts 只 reject 不弹错，由页面展示后端 message（对齐项目其它写页面）；
    // 非 Error 值（mock/中间层 reject 字符串等）兜底统一文案，避免提示 undefined
    ElMessage.error(e instanceof Error ? e.message : '保存失败')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
