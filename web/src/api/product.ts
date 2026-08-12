// 商品 API 封装：分页筛选 + CRUD + 扫码查询
import { http } from './http'

export type ProductType = 'raw_material' | 'semi_finished' | 'finished'

export interface ProductItem {
  id: number
  name: string
  code: string
  type: ProductType
  type_label: string
  category_id: number
  category_name: string | null
  unit_id: number
  unit_name: string | null
  spec: string | null
  barcode: string | null
  safety_min: number
  safety_max: number
  status: number
  remark: string | null
}

export const productApi = {
  // 分页列表（编码/名称/条码模糊 + 类型/分类/状态过滤；per_page 缺省 10，与分页器页容量一致）
  async list(params: {
    page?: number
    per_page?: number
    keyword?: string
    type?: ProductType
    category_id?: number
    status?: number
  }) {
    const { data } = await http.get('/products', { params: { per_page: 10, ...params } })
    return data.data as { items: ProductItem[]; total: number; page: number; per_page: number }
  },
  // 新建商品
  async create(payload: {
    name: string
    code: string
    type: ProductType
    category_id: number
    unit_id: number
    spec?: string
    barcode?: string
    safety_min?: number
    safety_max?: number
    status?: number
    remark?: string
  }) {
    await http.post('/products', payload)
  },
  // 更新商品
  async update(
    id: number,
    payload: {
      name: string
      code: string
      type: ProductType
      category_id: number
      unit_id: number
      spec?: string
      barcode?: string
      safety_min?: number
      safety_max?: number
      status?: number
      remark?: string
    },
  ) {
    await http.put(`/products/${id}`, payload)
  },
  // 删除商品
  async remove(id: number) {
    await http.delete(`/products/${id}`)
  },
  // 扫码查询（扫枪场景）
  async byBarcode(barcode: string) {
    const { data } = await http.get(`/products/barcode/${barcode}`)
    return data.data as {
      id: number
      name: string
      code: string
      type: ProductType
      spec: string | null
      unit_name: string | null
    }
  },
}
