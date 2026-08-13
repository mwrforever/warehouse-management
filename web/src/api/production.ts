// 生产 API 封装：生产工单 + 工序报工 + 领料/退料 + 委外 + 成品入库（草稿 CRUD/审核/状态流转/预填/回收）
import { http } from './http'

export interface ProductionOrderItem {
  id: number
  no: string
  product_id: number
  product_name: string
  product_code: string
  quantity: number
  completed_qty: number
  progress: number
  plan_date: string
  status: number
  status_label: string
  released_at: string | null
  completed_at: string | null
}

export interface ProductionMaterial {
  material_id: number
  material_name: string
  material_code: string
  required_qty: number
  issued_qty: number
  remaining_qty: number
}

export interface ProductionOperation {
  id: number
  seq: number
  process_id: number
  process_name: string
  process_code: string
  status: number
  status_label: string
  qualified_qty: number
  defective_qty: number
  hours: number
}

export interface ProductionOrderDetail {
  id: number
  no: string
  product_id: number
  product_name: string
  product_code: string
  quantity: number
  plan_date: string
  bom_id: number
  bom_code: string
  status: number
  status_label: string
  completed_qty: number
  progress: number
  released_at: string | null
  completed_at: string | null
  closed_at: string | null
  remark: string | null
  materials: ProductionMaterial[]
  operations: ProductionOperation[]
}

export interface ReleaseWarning {
  material_name: string
  material_code: string
  required: number
  stock: number
}

export interface OperationReportRecord {
  id: number
  operator: string | null
  qualified_qty: number
  defective_qty: number
  hours: number
  report_time: string
  remark: string | null
}

export interface PickItem {
  id: number
  no: string
  order_id: number
  order_no: string
  warehouse_id: number
  warehouse_name: string
  status: number
  status_label: string
  issue_status: number
  issue_status_label: string
  approved_at: string | null
  operator: string | null
  created_at: string
}

export interface FromOrderMaterial {
  product_id: number
  material_name: string
  material_code: string
  required_qty: number
  issued_qty: number
  remaining_qty: number
}

export interface FromOrderData {
  order_id: number
  order_no: string
  product_id: number
  product_name: string
  items: FromOrderMaterial[]
}

export interface ReturnItem {
  id: number
  no: string
  order_id: number
  order_no: string
  status: number
  status_label: string
  approved_at: string | null
  operator: string | null
  created_at: string
}

export interface OutsourcingItem {
  id: number
  no: string
  order_id: number
  order_no: string
  operation_id: number
  process_name: string
  supplier_id: number
  supplier_name: string
  quantity: number
  status: number
  status_label: string
  approved_at: string | null
  operator: string | null
  created_at: string
}

export interface OutsourcingDetail {
  id: number
  no: string
  order_id: number
  order_no: string
  operation_id: number
  process_name: string
  supplier_id: number
  supplier_name: string
  status: number
  status_label: string
  quantity: number
  received_qty: number
  approved_at: string | null
  operator: string | null
  remark: string | null
}

export interface OutsourcingReceiptRecord {
  id: number
  no: string
  quantity: number
  warehouse_name: string
  location_name: string
  received_at: string
  operator: string | null
}

export interface FinishedInboundItem {
  id: number
  no: string
  order_id: number
  order_no: string
  product_id: number
  product_name: string
  product_code: string
  quantity: number
  status: number
  status_label: string
  approved_at: string | null
  operator: string | null
  created_at: string
}

export interface ProductionOrderPayload {
  product_id: number
  quantity: number
  plan_date: string
  bom_id?: number
  remark?: string
}

export interface PickPayload {
  order_id: number
  warehouse_id: number
  location_id: number
  remark?: string
  items: { product_id: number; pick_qty: number }[]
}

export interface ReturnPayload {
  order_id: number
  pick_id?: number
  warehouse_id: number
  location_id: number
  remark?: string
  items: { product_id: number; quantity: number }[]
}

export interface OutsourcingPayload {
  order_id: number
  operation_id: number
  supplier_id: number
  warehouse_id: number
  location_id: number
  quantity: number
  remark?: string
}

export interface FinishedInboundPayload {
  order_id: number
  warehouse_id: number
  location_id: number
  remark?: string
  items: { product_id: number; quantity: number }[]
}

// 分页列表响应统一形状
interface PageResult<T> {
  items: T[]
  total: number
  page: number
  per_page: number
}

export const productionApi = {
  // 生产工单分页列表（单号/成品/状态/日期筛选）
  async orders(params: {
    page?: number
    per_page?: number
    keyword?: string
    product_id?: number
    status?: number
    date_from?: string
    date_to?: string
  }) {
    const { data } = await http.get('/production/orders', { params: { per_page: 10, ...params } })
    return data.data as PageResult<ProductionOrderItem>
  },
  // 工单详情（含物料需求 + 工序列表）
  async orderDetail(id: number) {
    const { data } = await http.get(`/production/orders/${id}`)
    return data.data as ProductionOrderDetail
  },
  // 新建草稿（响应单号）
  async createOrder(payload: ProductionOrderPayload) {
    const { data } = await http.post('/production/orders', payload)
    return data.data.no
  },
  // 更新草稿（物料/工序快照重建）
  async updateOrder(id: number, payload: ProductionOrderPayload) {
    await http.put(`/production/orders/${id}`, payload)
  },
  // 删除草稿
  async deleteOrder(id: number) {
    await http.delete(`/production/orders/${id}`)
  },
  // 下达（响应缺料警告列表，允许为空）
  async releaseOrder(id: number) {
    const { data } = await http.post(`/production/orders/${id}/release`)
    return data.data as { warnings: ReleaseWarning[] }
  },
  // 开工
  async startOrder(id: number) {
    await http.post(`/production/orders/${id}/start`)
  },
  // 完工
  async completeOrder(id: number) {
    await http.post(`/production/orders/${id}/complete`)
  },
  // 关闭
  async closeOrder(id: number) {
    await http.post(`/production/orders/${id}/close`)
  },
  // 物料需求（领料单预填数据源）
  async orderMaterials(id: number) {
    const { data } = await http.get(`/production/orders/${id}/materials`)
    return data.data as { items: ProductionMaterial[] }
  },
  // 工序报工
  async report(
    operationId: number,
    payload: {
      qualified_qty: number
      defective_qty?: number
      hours?: number
      operator?: string
      remark?: string
    },
  ) {
    await http.post(`/production/operations/${operationId}/reports`, payload)
  },
  // 工序报工记录
  async operationReports(operationId: number) {
    const { data } = await http.get(`/production/operations/${operationId}/reports`)
    return data.data as PageResult<OperationReportRecord>
  },
  // 领料单分页列表
  async picks(params: { page?: number; per_page?: number; keyword?: string; status?: number }) {
    const { data } = await http.get('/production/picks', { params: { per_page: 10, ...params } })
    return data.data as PageResult<PickItem>
  },
  // 领料单详情
  async pickDetail(id: number) {
    const { data } = await http.get(`/production/picks/${id}`)
    return data.data as {
      id: number
      no: string
      order_id: number
      order_no: string
      status: number
      status_label: string
      issue_status: number
      issue_status_label: string
      warehouse_name: string
      location_name: string
      remark: string | null
      items: {
        id: number
        product_id: number
        product_name: string
        product_code: string
        required_qty: number
        pick_qty: number
        issued_qty: number
      }[]
    }
  },
  // 新建领料单（响应单号）
  async createPick(payload: PickPayload) {
    const { data } = await http.post('/production/picks', payload)
    return data.data.no
  },
  // 更新领料单草稿
  async updatePick(id: number, payload: PickPayload) {
    await http.put(`/production/picks/${id}`, payload)
  },
  // 删除领料单草稿
  async deletePick(id: number) {
    await http.delete(`/production/picks/${id}`)
  },
  // 审核领料单（扣原料库存）
  async approvePick(id: number) {
    const { data } = await http.post(`/production/picks/${id}/approve`)
    return data.data.no
  },
  // 发料（V1 一次发完 → 全部发料）
  async issuePick(id: number) {
    const { data } = await http.post(`/production/picks/${id}/issue`)
    return data.data as { issue_status: string }
  },
  // 从工单生成预填（物料需求剩余量）
  async fromOrderPicks(orderId: number) {
    const { data } = await http.get(`/production/picks/from-order/${orderId}`)
    return data.data as FromOrderData
  },
  // 退料单分页列表
  async returns(params: { page?: number; per_page?: number; keyword?: string; status?: number }) {
    const { data } = await http.get('/production/returns', { params: { per_page: 10, ...params } })
    return data.data as PageResult<ReturnItem>
  },
  // 新建退料单（响应单号）
  async createReturn(payload: ReturnPayload) {
    const { data } = await http.post('/production/returns', payload)
    return data.data.no
  },
  // 更新退料单草稿
  async updateReturn(id: number, payload: ReturnPayload) {
    await http.put(`/production/returns/${id}`, payload)
  },
  // 删除退料单草稿
  async deleteReturn(id: number) {
    await http.delete(`/production/returns/${id}`)
  },
  // 审核退料单（冲销已领）
  async approveReturn(id: number) {
    const { data } = await http.post(`/production/returns/${id}/approve`)
    return data.data.no
  },
  // 委外单分页列表
  async outsourcings(params: {
    page?: number
    per_page?: number
    keyword?: string
    status?: number
  }) {
    const { data } = await http.get('/production/outsourcings', {
      params: { per_page: 10, ...params },
    })
    return data.data as PageResult<OutsourcingItem>
  },
  // 委外单详情（含已回收累计）
  async outsourcingDetail(id: number) {
    const { data } = await http.get(`/production/outsourcings/${id}`)
    return data.data as OutsourcingDetail
  },
  // 新建委外单（响应单号）
  async createOutsourcing(payload: OutsourcingPayload) {
    const { data } = await http.post('/production/outsourcings', payload)
    return data.data.no
  },
  // 更新委外单草稿
  async updateOutsourcing(id: number, payload: OutsourcingPayload) {
    await http.put(`/production/outsourcings/${id}`, payload)
  },
  // 删除委外单草稿
  async deleteOutsourcing(id: number) {
    await http.delete(`/production/outsourcings/${id}`)
  },
  // 发出（审核，扣成品库存）
  async approveOutsourcing(id: number) {
    const { data } = await http.post(`/production/outsourcings/${id}/approve`)
    return data.data.no
  },
  // 回收（创建即审核回收单，加成品库存）
  async receiptOutsourcing(
    id: number,
    payload: { quantity: number; warehouse_id: number; location_id: number; remark?: string },
  ) {
    const { data } = await http.post(`/production/outsourcings/${id}/receipts`, payload)
    return data.data.no
  },
  // 委外回收记录
  async outsourcingReceipts(id: number) {
    const { data } = await http.get(`/production/outsourcings/${id}/receipts`)
    return data.data as PageResult<OutsourcingReceiptRecord>
  },
  // 成品入库单分页列表
  async finishedInbounds(params: {
    page?: number
    per_page?: number
    keyword?: string
    status?: number
  }) {
    const { data } = await http.get('/production/finished-inbounds', {
      params: { per_page: 10, ...params },
    })
    return data.data as PageResult<FinishedInboundItem>
  },
  // 成品入库单详情（含剩余产量）
  async finishedInboundDetail(id: number) {
    const { data } = await http.get(`/production/finished-inbounds/${id}`)
    return data.data as {
      id: number
      no: string
      order_id: number
      order_no: string
      status: number
      status_label: string
      remaining_qty: number
      warehouse_name: string
      location_name: string
      remark: string | null
      items: {
        id: number
        product_id: number
        product_name: string
        product_code: string
        quantity: number
      }[]
    }
  },
  // 新建成品入库单（响应单号）
  async createFinishedInbound(payload: FinishedInboundPayload) {
    const { data } = await http.post('/production/finished-inbounds', payload)
    return data.data.no
  },
  // 更新成品入库单草稿
  async updateFinishedInbound(id: number, payload: FinishedInboundPayload) {
    await http.put(`/production/finished-inbounds/${id}`, payload)
  },
  // 删除成品入库单草稿
  async deleteFinishedInbound(id: number) {
    await http.delete(`/production/finished-inbounds/${id}`)
  },
  // 审核成品入库单（加成品库存 + 工单联动）
  async approveFinishedInbound(id: number) {
    const { data } = await http.post(`/production/finished-inbounds/${id}/approve`)
    return data.data.no
  },
}
