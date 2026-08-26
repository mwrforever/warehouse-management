// 销售 API 封装：销售订单 + 销售出库单（草稿 CRUD/审核/关闭/预填/出库记录/当日汇总）
import { http } from './http'

export interface SalesOrderItem {
  id: number
  no: string
  customer_id: number
  customer_name: string
  order_date: string
  expected_date: string | null
  total_amount: number
  status: number
  status_label: string
  created_by: number | null
  created_by_name: string | null
  approved_at: string | null
}

export interface SalesOrderDetailItem {
  id: number
  product_id: number
  product_name: string
  product_code: string
  quantity: number
  shipped_qty: number
  price: number
  amount: number
}

export interface SalesOrderDetail {
  id: number
  no: string
  customer_id: number
  customer_name: string
  order_date: string
  expected_date: string | null
  status: number
  status_label: string
  total_amount: number
  remark: string | null
  approved_at: string | null
  closed_at: string | null
  items: SalesOrderDetailItem[]
}

export interface AvailableOrder {
  id: number
  no: string
  customer_name: string
  order_date: string
}

export interface OrderOutboundItem {
  id: number
  no: string
  status: number
  status_label: string
  outbound_at: string | null
  operator: string | null
  total_amount: number
}

export interface SalesOutboundItem {
  id: number
  no: string
  customer_id: number
  customer_name: string
  warehouse_id: number
  warehouse_name: string
  location_id: number
  location_name: string
  order_id: number | null
  order_no: string | null
  status: number
  status_label: string
  total_amount: number
  outbound_at: string | null
  operator: string | null
  created_at: string
}

export interface SalesOutboundDetailItem {
  id: number
  product_id: number
  product_name: string
  product_code: string
  quantity: number
  price: number
  amount: number
  order_item_id: number | null
}

export interface SalesOutboundDetail {
  id: number
  no: string
  customer_id: number
  customer_name: string
  warehouse_id: number
  warehouse_name: string
  location_id: number
  location_name: string
  order_id: number | null
  order_no: string | null
  status: number
  status_label: string
  total_amount: number
  outbound_at: string | null
  operator: string | null
  remark: string | null
  items: SalesOutboundDetailItem[]
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
  customer_id: number
  customer_name: string
  order_date: string
  items: FromOrderItem[]
}

export interface TodaySummaryItem {
  product_id: number
  product_code: string
  product_name: string
  quantity: number
}

// 销售订单草稿载荷（新建/更新共用；price 分单位）
export interface SalesOrderPayload {
  customer_id: number
  order_date: string
  expected_date?: string
  remark?: string
  items: { product_id: number; quantity: number; price: number }[]
}

// 销售出库单草稿载荷（新建/更新共用；独立出库不含 order_id）
export interface SalesOutboundPayload {
  customer_id: number
  warehouse_id: number
  location_id: number
  order_id?: number
  remark?: string
  items: { product_id: number; quantity: number; price: number; order_item_id?: number }[]
}

export const salesApi = {
  // 销售订单分页列表（单号/客户/状态/日期筛选）
  async orders(params: {
    page?: number
    per_page?: number
    keyword?: string
    customer_id?: number
    status?: number
    date_from?: string
    date_to?: string
  }) {
    const { data } = await http.get('/sales/orders', { params: { per_page: 10, ...params } })
    return data.data as {
      items: SalesOrderItem[]
      total: number
      page: number
      per_page: number
    }
  },
  // 销售订单详情（含明细）
  async orderDetail(id: number) {
    const { data } = await http.get(`/sales/orders/${id}`)
    return data.data as SalesOrderDetail
  },
  // 新建草稿（响应单号）
  async createOrder(payload: SalesOrderPayload) {
    const { data } = await http.post('/sales/orders', payload)
    return data.data.no
  },
  // 更新草稿（items 全量替换）
  async updateOrder(id: number, payload: SalesOrderPayload) {
    await http.put(`/sales/orders/${id}`, payload)
  },
  // 删除草稿
  async deleteOrder(id: number) {
    await http.delete(`/sales/orders/${id}`)
  },
  // 审核（响应单号）
  async approveOrder(id: number) {
    const { data } = await http.post(`/sales/orders/${id}/approve`)
    return data.data.no
  },
  // 关闭
  async closeOrder(id: number) {
    await http.post(`/sales/orders/${id}/close`)
  },
  // 可出库订单列表（从订单生成下拉数据源；BF-3 后端支持单号 keyword 模糊搜索与 per_page 分页钳制 100）
  async availableOrders(params?: { keyword?: string; per_page?: number }) {
    const { data } = await http.get('/sales/orders/available', { params })
    return data.data as {
      items: AvailableOrder[]
      total: number
      page: number
      per_page: number
    }
  },
  // 该订单的出库记录（详情页 tab）
  async orderOutbounds(id: number) {
    const { data } = await http.get(`/sales/orders/${id}/outbounds`)
    return data.data as { items: OrderOutboundItem[] }
  },
  // 销售出库单分页列表（单号/仓库/状态/日期筛选）
  async outbounds(params: {
    page?: number
    per_page?: number
    keyword?: string
    warehouse_id?: number
    status?: number
    date_from?: string
    date_to?: string
  }) {
    const { data } = await http.get('/sales/outbounds', { params: { per_page: 10, ...params } })
    return data.data as {
      items: SalesOutboundItem[]
      total: number
      page: number
      per_page: number
    }
  },
  // 出库单详情（含明细）
  async outboundDetail(id: number) {
    const { data } = await http.get(`/sales/outbounds/${id}`)
    return data.data as SalesOutboundDetail
  },
  // 新建草稿（独立出库不含 order_id）
  async createOutbound(payload: SalesOutboundPayload) {
    const { data } = await http.post('/sales/outbounds', payload)
    return data.data.no
  },
  // 更新草稿
  async updateOutbound(id: number, payload: SalesOutboundPayload) {
    await http.put(`/sales/outbounds/${id}`, payload)
  },
  // 删除草稿
  async deleteOutbound(id: number) {
    await http.delete(`/sales/outbounds/${id}`)
  },
  // 审核（响应单号）
  async approveOutbound(id: number) {
    const { data } = await http.post(`/sales/outbounds/${id}/approve`)
    return data.data.no
  },
  // 从订单生成预填（剩余量）
  async fromOrder(orderId: number) {
    const { data } = await http.get(`/sales/outbounds/from-order/${orderId}`)
    return data.data as FromOrderData
  },
  // 当日已审核出库量按商品汇总（列表页汇总行数据源）
  async todaySummary() {
    const { data } = await http.get('/sales/outbounds/today-summary')
    return data.data as { items: TodaySummaryItem[] }
  },
}
