// 统计报表 API 封装：4 类只读聚合接口（金额/数量/比率均为后端输出的字符串，前端仅千分位格式化）
import { http } from './http'

export type ReportGroupBy = 'category' | 'warehouse' | 'type'
export type ReportGranularity = 'day' | 'month'

export interface InventorySummaryItem {
  group_name: string
  quantity_total: string
  product_count: number
  amount_total: string | null
}

export interface InventorySummaryTotal {
  quantity_total: string
  product_count: number
  amount_total: string | null
}

export interface MovementsSummaryItem {
  period: string
  inbound_qty: string
  outbound_qty: string
  inbound_count: number
  outbound_count: number
}

export interface MovementsSummaryTotal {
  inbound_qty: string
  outbound_qty: string
  inbound_count: number
  outbound_count: number
}

export interface ProductionMaterialUsed {
  material_id: number
  material_name: string
  material_code: string
  used_qty: string
  unit: string
}

export interface ProductionStatItem {
  order_id: number
  order_no: string
  product_name: string
  product_code: string
  quantity: string
  completed_qty: string
  achievement_rate: string
  qualified_qty: string
  defective_qty: string
  yield_rate: string
  total_hours: string
  material_used: ProductionMaterialUsed[]
}

// 生产统计全区间合计（后端对窗口内全部工单求和，先于 items 截断——KPI 截断安全）
export interface ProductionTotal {
  order_count: number
  total_plan: string
  total_completed: string
  total_qualified: string
  total_defective: string
}

export interface PurchaseSalesItem {
  period: string
  purchase_amount: string
  sales_amount: string
  purchase_qty: string
  sales_qty: string
}

export interface PurchaseSalesTotal {
  purchase_amount: string
  sales_amount: string
  purchase_qty: string
  sales_qty: string
}

export const reportApi = {
  // 库存报表：按维度汇总当前余额（group_by=category|warehouse|type）
  async inventorySummary(params: { group_by: ReportGroupBy }) {
    const { data } = await http.get('/reports/inventory-summary', { params })
    return data.data as {
      items: InventorySummaryItem[]
      total: InventorySummaryTotal
      truncated: boolean
    }
  },
  // 出入库汇总：按日/月粒度聚合流水方向（闭区间；不补零，无流水周期不出现）
  async movementsSummary(params: {
    date_from: string
    date_to: string
    granularity: ReportGranularity
    source_type?: string
  }) {
    const { data } = await http.get('/reports/movements-summary', { params })
    return data.data as {
      items: MovementsSummaryItem[]
      totals: MovementsSummaryTotal
      truncated: boolean
    }
  },
  // 生产统计：计划日期窗口内工单达成率/良率/工时/物料耗用
  async production(params: { date_from: string; date_to: string; product_id?: number }) {
    const { data } = await http.get('/reports/production', { params })
    return data.data as { items: ProductionStatItem[]; totals: ProductionTotal; truncated: boolean }
  },
  // 采购销售汇总：已审核单据金额/数量按审核时间分桶（金额已转元）
  async purchaseSales(params: {
    date_from: string
    date_to: string
    granularity: ReportGranularity
  }) {
    const { data } = await http.get('/reports/purchase-sales', { params })
    return data.data as {
      items: PurchaseSalesItem[]
      totals: PurchaseSalesTotal
      truncated: boolean
    }
  },
}
