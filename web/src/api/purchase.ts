// 采购 API 封装：采购订单 + 采购入库单（草稿 CRUD/审核/关闭/预填/入库记录）
import { http } from './http'

export interface PurchaseOrderItem {
  id: number
  no: string
  supplier_id: number
  supplier_name: string
  order_date: string
  expected_date: string | null
  total_amount: number
  status: number
  status_label: string
  created_by: number | null
  created_by_name: string | null
  approved_at: string | null
}

export interface PurchaseOrderDetailItem {
  id: number
  product_id: number
  product_name: string
  product_code: string
  quantity: number
  received_qty: number
  price: number
  amount: number
}

export interface PurchaseOrderDetail {
  id: number
  no: string
  supplier_id: number
  supplier_name: string
  order_date: string
  expected_date: string | null
  status: number
  status_label: string
  total_amount: number
  remark: string | null
  approved_at: string | null
  closed_at: string | null
  items: PurchaseOrderDetailItem[]
}

export interface AvailableOrder {
  id: number
  no: string
  supplier_name: string
  order_date: string
}

export interface OrderInboundItem {
  id: number
  no: string
  status: number
  status_label: string
  inbound_at: string | null
  operator: string | null
  total_amount: number
}

export interface PurchaseInboundItem {
  id: number
  no: string
  supplier_id: number
  supplier_name: string
  warehouse_id: number
  warehouse_name: string
  location_id: number
  location_name: string
  order_id: number | null
  order_no: string | null
  status: number
  status_label: string
  total_amount: number
  inbound_at: string | null
  operator: string | null
  created_at: string
}

export interface PurchaseInboundDetailItem {
  id: number
  product_id: number
  product_name: string
  product_code: string
  quantity: number
  price: number
  amount: number
  order_item_id: number | null
}

export interface PurchaseInboundDetail {
  id: number
  no: string
  supplier_id: number
  supplier_name: string
  warehouse_id: number
  warehouse_name: string
  location_id: number
  location_name: string
  order_id: number | null
  order_no: string | null
  status: number
  status_label: string
  total_amount: number
  inbound_at: string | null
  operator: string | null
  remark: string | null
  items: PurchaseInboundDetailItem[]
}

export interface FromOrderItem {
  product_id: number
  product_name: string
  product_code: string
  quantity: number
  remaining_qty: number
  price: number
  order_item_id: number
}

export interface FromOrderData {
  order_id: number
  order_no: string
  supplier_id: number
  supplier_name: string
  order_date: string
  items: FromOrderItem[]
}

// 采购订单草稿载荷（新建/更新共用；price 分单位）
export interface PurchaseOrderPayload {
  supplier_id: number
  order_date: string
  expected_date?: string
  remark?: string
  items: { product_id: number; quantity: number; price: number }[]
}

// 采购入库单草稿载荷（新建/更新共用；独立入库不含 order_id）
export interface PurchaseInboundPayload {
  supplier_id: number
  warehouse_id: number
  location_id: number
  order_id?: number
  remark?: string
  items: { product_id: number; quantity: number; price: number; order_item_id?: number }[]
}

export const purchaseApi = {
  // 采购订单分页列表（单号/供应商/状态/日期筛选）
  async orders(params: {
    page?: number
    per_page?: number
    keyword?: string
    supplier_id?: number
    status?: number
    date_from?: string
    date_to?: string
  }) {
    const { data } = await http.get('/purchase/orders', { params: { per_page: 10, ...params } })
    return data.data as {
      items: PurchaseOrderItem[]
      total: number
      page: number
      per_page: number
    }
  },
  // 采购订单详情（含明细）
  async orderDetail(id: number) {
    const { data } = await http.get(`/purchase/orders/${id}`)
    return data.data as PurchaseOrderDetail
  },
  // 新建草稿（响应单号）
  async createOrder(payload: PurchaseOrderPayload) {
    const { data } = await http.post('/purchase/orders', payload)
    return data.data.no
  },
  // 更新草稿（items 全量替换）
  async updateOrder(id: number, payload: PurchaseOrderPayload) {
    await http.put(`/purchase/orders/${id}`, payload)
  },
  // 删除草稿
  async deleteOrder(id: number) {
    await http.delete(`/purchase/orders/${id}`)
  },
  // 审核（响应单号）
  async approveOrder(id: number) {
    const { data } = await http.post(`/purchase/orders/${id}/approve`)
    return data.data.no
  },
  // 关闭
  async closeOrder(id: number) {
    await http.post(`/purchase/orders/${id}/close`)
  },
  // 可入库订单列表（从订单生成下拉数据源；BF-3 后端支持单号 keyword 模糊搜索与 per_page 分页钳制 100）
  async availableOrders(params?: { keyword?: string; per_page?: number }) {
    const { data } = await http.get('/purchase/orders/available', { params })
    return data.data as {
      items: AvailableOrder[]
      total: number
      page: number
      per_page: number
    }
  },
  // 该订单的入库记录（详情页 tab）
  async orderInbounds(id: number) {
    const { data } = await http.get(`/purchase/orders/${id}/inbounds`)
    return data.data as { items: OrderInboundItem[] }
  },
  // 采购入库单分页列表（单号/仓库/状态/日期筛选）
  async inbounds(params: {
    page?: number
    per_page?: number
    keyword?: string
    warehouse_id?: number
    status?: number
    date_from?: string
    date_to?: string
  }) {
    const { data } = await http.get('/purchase/inbounds', { params: { per_page: 10, ...params } })
    return data.data as {
      items: PurchaseInboundItem[]
      total: number
      page: number
      per_page: number
    }
  },
  // 入库单详情（含明细）
  async inboundDetail(id: number) {
    const { data } = await http.get(`/purchase/inbounds/${id}`)
    return data.data as PurchaseInboundDetail
  },
  // 新建草稿（独立入库不含 order_id）
  async createInbound(payload: PurchaseInboundPayload) {
    const { data } = await http.post('/purchase/inbounds', payload)
    return data.data.no
  },
  // 更新草稿
  async updateInbound(id: number, payload: PurchaseInboundPayload) {
    await http.put(`/purchase/inbounds/${id}`, payload)
  },
  // 删除草稿
  async deleteInbound(id: number) {
    await http.delete(`/purchase/inbounds/${id}`)
  },
  // 审核（响应单号）
  async approveInbound(id: number) {
    const { data } = await http.post(`/purchase/inbounds/${id}/approve`)
    return data.data.no
  },
  // 从订单生成预填（剩余量）
  async fromOrder(orderId: number) {
    const { data } = await http.get(`/purchase/inbounds/from-order/${orderId}`)
    return data.data as FromOrderData
  },
}
