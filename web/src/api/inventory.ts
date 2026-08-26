// 库存 API 封装：余额/流水/预警查询 + 盘点单 CRUD/账面预填/审核
import { http } from './http'

export type ProductType = 'raw_material' | 'semi_finished' | 'finished'

export interface BalanceItem {
  id: number
  product_id: number
  product_name: string
  product_code: string
  type: ProductType
  warehouse_id: number
  warehouse_name: string
  location_id: number
  location_name: string
  quantity: number
  safety_min: number
  safety_max: number
  alert_level: number
}

export interface MovementItem {
  id: number
  product_name: string
  product_code: string
  warehouse_name: string
  location_name: string
  direction: number
  quantity: number
  balance_after: number
  source_type: string
  source_type_label: string
  source_id: number
  source_no: string
  operator_name: string | null
  created_at: string
}

export interface CheckItem {
  id: number
  no: string
  warehouse_name: string
  status: number
  checker: string | null
  checked_at: string | null
  remark: string | null
  created_at: string
}

export interface CheckDetailItem {
  id: number
  product_id: number
  location_id: number
  product_name: string
  product_code: string
  location_name: string
  book_qty: number
  actual_qty: number
  diff_qty: number
}

export interface AlertItem {
  product_name: string
  product_code: string
  warehouse_name: string
  quantity: number
  safety_min: number
  safety_max: number
  level: number
}

export interface AutoBookItem {
  product_id: number
  product_name: string
  product_code: string
  location_id: number
  location_name: string
  book_qty: number
}

export const inventoryApi = {
  // 余额分页列表（关键字/仓库/类型/仅预警筛选）
  async balances(params: {
    page?: number
    per_page?: number
    keyword?: string
    warehouse_id?: number
    type?: ProductType
    alert?: number
  }) {
    const { data } = await http.get('/inventory/balances', { params: { per_page: 10, ...params } })
    return data.data as { items: BalanceItem[]; total: number; page: number; per_page: number }
  },
  // 余额导出（blob 供前端下载）
  async exportBalances(params: {
    keyword?: string
    warehouse_id?: number
    type?: ProductType
    alert?: number
  }) {
    const { data } = await http.get('/inventory/balances/export', { params, responseType: 'blob' })
    return data as Blob
  },
  // 流水分页列表（商品/仓库/类型/方向/日期范围筛选）
  async movements(params: {
    page?: number
    per_page?: number
    product_id?: number
    warehouse_id?: number
    source_type?: string
    direction?: number
    date_from?: string
    date_to?: string
  }) {
    const { data } = await http.get('/inventory/movements', { params })
    return data.data as { items: MovementItem[]; total: number; page: number; per_page: number }
  },
  // 预警列表（level=1 低于下限 / 2 高于上限）
  async alerts() {
    const { data } = await http.get('/inventory/alerts')
    return data.data as { items: AlertItem[] }
  },
  // 盘点单分页列表（单号/状态/仓库筛选）
  async checks(params: {
    page?: number
    per_page?: number
    keyword?: string
    status?: number
    warehouse_id?: number
  }) {
    const { data } = await http.get('/checks', { params: { per_page: 10, ...params } })
    return data.data as { items: CheckItem[]; total: number; page: number; per_page: number }
  },
  // 盘点单详情（含明细差异）
  async checkDetail(id: number) {
    const { data } = await http.get(`/checks/${id}`)
    return data.data as {
      id: number
      no: string
      warehouse_id: number
      warehouse_name: string
      status: number
      checker: string | null
      checked_at: string | null
      remark: string | null
      created_at: string
      items: CheckDetailItem[]
    }
  },
  // 新建草稿（响应单号）
  async createCheck(payload: {
    warehouse_id: number
    remark?: string
    items: { product_id: number; location_id: number; actual_qty: number }[]
  }) {
    const { data } = await http.post('/checks', payload)
    return data.data.no as string
  },
  // 更新草稿（items 全量替换）
  async updateCheck(
    id: number,
    payload: {
      warehouse_id: number
      remark?: string
      items: { product_id: number; location_id: number; actual_qty: number }[]
    },
  ) {
    await http.put(`/checks/${id}`, payload)
  },
  // 删除草稿
  async deleteCheck(id: number) {
    await http.delete(`/checks/${id}`)
  },
  // 审核（响应盘盈/盘亏汇总：changed_items 差异行数，increased/decreased 数量，*_items 行数）
  async approveCheck(id: number) {
    const { data } = await http.post(`/checks/${id}/approve`)
    return data.data as {
      changed_items: number
      increased: number
      decreased: number
      increased_items: number
      decreased_items: number
    }
  },
  // 账面预填：某仓库全部有余额的商品×库位
  async autoBooks(warehouseId: number) {
    const { data } = await http.get('/checks/auto-books', { params: { warehouse_id: warehouseId } })
    return data.data as { items: AutoBookItem[] }
  },
}
