<!-- 四级地址级联选择器（省/市/区县/乡镇街道 + 详细地址在宿主表单单独输入）：
     数据源 china-division（民政部/统计局区划数据），直接导入 dist/pcas.json——
     包入口为 UMD 形态（运行时依赖 __dirname），经 rolldown-vite 预构建后在浏览器抛
     ReferenceError，绕过入口直取数据 JSON 可避免（resolveJsonModule 已开启）。
     modelValue 为 {province,city,district,town} 名称对象（后端四列字段直接落库名称，
     展示无需再映射编码），各级允许留空（部分数据无镇级时下级为 [] 不可选）。
     仅数据变换无业务逻辑，宿主（仓库/供应商/客户）表单复用同一契约 -->
<script setup lang="ts">
import { computed } from 'vue'
import pcasData from 'china-division/dist/pcas.json'

// TS 对 JSON 模块推断字面量类型，此处窄化为应用需要的形状（省 → 市 → 区县 → 街道名数组）
const pcas = pcasData as Record<string, Record<string, Record<string, string[]>>>

/** 四级地区地址：各级为区划名称，空串表示未选该级 */
export interface AreaRegion {
  province: string
  city: string
  district: string
  town: string
}

const props = withDefaults(
  defineProps<{
    modelValue: AreaRegion
    disabled?: boolean
    clearable?: boolean
    placeholder?: string
  }>(),
  {
    disabled: false,
    clearable: true,
    placeholder: '省 / 市 / 区县 / 街道',
  },
)
const emit = defineEmits<{
  'update:modelValue': [value: AreaRegion]
}>()

// 模块级构建一次：pcas 按名称嵌套（省 → 市 → 区县 → 街道名数组），转 el-cascader options 树
const areaOptions = Object.entries(pcas).map(([provinceName, cities]) => ({
  value: provinceName,
  label: provinceName,
  children: (Object.entries(cities) as [string, Record<string, string[]>][]).map(
    ([cityName, counties]) => ({
      value: cityName,
      label: cityName,
      children: Object.entries(counties).map(([countyName, towns]) => ({
        value: countyName,
        label: countyName,
        children: (towns as string[]).map((townName) => ({
          value: townName,
          label: townName,
        })),
      })),
    }),
  ),
}))

// 对象 ↔ 级联数组转换：选中任一节点即截断其后的空级（选择市级后区县空时自动清空）
const regionKeys = ['province', 'city', 'district', 'town'] as const

const cascadeValue = computed<string[]>(() =>
  regionKeys.map((k) => props.modelValue[k] ?? '').filter(Boolean),
)

function onCascaderChange(value: unknown) {
  const names = (Array.isArray(value) ? value : []) as string[]
  const next: AreaRegion = { province: '', city: '', district: '', town: '' }
  names.forEach((name, idx) => {
    if (idx < regionKeys.length) next[regionKeys[idx]] = name
  })
  emit('update:modelValue', next)
}
</script>

<template>
  <el-cascader
    :model-value="cascadeValue"
    :options="areaOptions"
    :disabled="disabled"
    :clearable="clearable"
    :placeholder="placeholder"
    :props="{ expandTrigger: 'click' }"
    class="area-cascader"
    @change="onCascaderChange"
    @clear="emit('update:modelValue', { province: '', city: '', district: '', town: '' })"
  />
</template>

<style scoped>
.area-cascader {
  width: 100%;
}
</style>
