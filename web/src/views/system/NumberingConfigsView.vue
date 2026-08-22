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
      <el-form :model="form" label-width="110px">
        <el-form-item label="类型">{{ form.type_label }}（{{ form.type }}）</el-form-item>
        <el-form-item label="前缀" required>
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
        <el-form-item label="备注"
          ><el-input v-model="form.remark" type="textarea" :rows="2"
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
import { onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useAuthStore } from '../../stores/auth'
import { systemSettingApi, type NumberConfigItem } from '../../api/systemSetting'

const auth = useAuthStore()
const rows = ref<NumberConfigItem[]>([])
const loading = ref(false)
const dialogVisible = ref(false)
const saving = ref(false)
const previewNo = ref('')
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

// 编辑弹窗内实时预览：值变化即调预览接口
async function refreshPreview() {
  if (!form.prefix) return
  try {
    const res = await systemSettingApi.preview({
      prefix: form.prefix,
      date_format: form.date_format,
      seq_length: form.seq_length,
    })
    previewNo.value = res.no
  } catch {
    previewNo.value = ''
  }
}

// 弹窗打开/字段变化均刷新预览（watch 触发对 el-input fill/el-input-number 步进等交互最稳，如 E2E fill）
watch([() => form.prefix, () => form.date_format, () => form.seq_length], () => refreshPreview())

async function save() {
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
  } catch {
    /* 错误提示由 http.ts 统一弹出后端 message */
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
