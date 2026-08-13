// 仪表盘 API 封装：4 个只读聚合接口（无参数；金额/数量/进度均为后端输出的字符串，前端仅格式化）
import { http } from './http'

export interface DashboardSummary {
  inventory_total_qty: string
  inventory_value: string | null
  today_inbound_qty: string
  today_outbound_qty: string
  pending_approvals: number
  work_order_running: number
  alert_count: number
}

export interface PendingApprovalItem {
  module: string
  type: string
  no: string
  created_at: string
  url: string
}

export interface WorkOrderProgressItem {
  no: string
  product_name: string
  quantity: string
  completed_qty: string
  progress: string
  status: number
  status_label: string
}

export interface DashboardAlertItem {
  product_name: string
  product_code: string
  warehouse_name: string
  quantity: string
  safety_min: string
}

export const dashboardApi = {
  // KPI 汇总：库存总量/总值/今日出入库/待审核数/生产中工单数/预警数
  async summary() {
    const { data } = await http.get('/dashboard/summary')
    return data.data as DashboardSummary
  },
  // 待审核单据列表：按当前用户审核权限过滤（最多 20 条，创建时间倒序）
  async pendingApprovals() {
    const { data } = await http.get('/dashboard/pending-approvals')
    return data.data as { items: PendingApprovalItem[] }
  },
  // 工单进度列表：生产中/已完成工单（最多 10 条，更新时间倒序）
  async workOrderProgress() {
    const { data } = await http.get('/dashboard/work-order-progress')
    return data.data as { items: WorkOrderProgressItem[] }
  },
  // 库存预警列表：低库存 level=1 前 10 条（与库存预警页同口径）
  async alerts() {
    const { data } = await http.get('/dashboard/alerts')
    return data.data as { items: DashboardAlertItem[] }
  },
}
