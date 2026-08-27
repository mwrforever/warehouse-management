// 四级地址工具：供应商/客户表单 region 模型与后端四列字段的转换、列表地址列拼接。
// 与 AreaCascader 的 modelValue 契约一致（各级为区划名称，空串=未选该级）。

/** 四级地址对象（与 AreaCascader modelValue 契约一致：各级为区划名称，空串=未选该级） */
export interface RegionAddress {
  province: string
  city: string
  district: string
  town: string
}

/** 空 region（新建表单初始值，与级联清空后的回写保持一致） */
export function emptyRegion(): RegionAddress {
  return { province: '', city: '', district: '', town: '' }
}

/**
 * 表单 region → 提交载荷：空串字段不进载荷（axios 序列化丢弃 undefined 键，
 * 后端四列落库 null 而非空串——列建表可空，与后端测试口径一致）
 */
export function regionToPayload(region: RegionAddress): {
  province?: string
  city?: string
  district?: string
  town?: string
} {
  const payload: { province?: string; city?: string; district?: string; town?: string } = {}
  if (region.province) payload.province = region.province
  if (region.city) payload.city = region.city
  if (region.district) payload.district = region.district
  if (region.town) payload.town = region.town
  return payload
}

/**
 * 列表地址列展示：省市区镇拼接 + 详细地址（任一级为空跳过；中文地址习惯无分隔符；
 * null 与空串统一按未选处理）
 */
export function formatFullAddress(region: {
  province?: string | null
  city?: string | null
  district?: string | null
  town?: string | null
  address?: string | null
}): string {
  const regionText = [region.province, region.city, region.district, region.town]
    .filter((v): v is string => Boolean(v))
    .join('')
  return [regionText, region.address].filter(Boolean).join('')
}
