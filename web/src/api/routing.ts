// 工艺路线 API：列表/保存（单头+DAG）/删除/启停/完整图（画布回显）；字段 snake_case 与后端契约一致
import { http } from './http'

export interface RoutingListItem {
  id: number
  code: string
  product_id: number
  product_name: string
  version: string
  quantity: number
  status: number
  status_label: string
  remark: string | null
}

export interface RoutingNodeMaterialPayload {
  material_id: number
  qty_per_unit: number
  unit_id: number
}

export interface RoutingNodePayload {
  node_no: string
  process_id: number
  name: string
  output_product_id: number
  output_qty: number
  is_outsourced: 0 | 1
  remark: string | null
  materials: RoutingNodeMaterialPayload[]
}

export interface RoutingPayload {
  product_id: number
  version: string
  quantity: number
  status: 0 | 1
  remark: string | null
  nodes: RoutingNodePayload[]
  edges: { from_node_no: string; to_node_no: string }[]
}

export interface RoutingGraphMaterial {
  id: number
  material_id: number
  material_name: string
  qty_per_unit: number
  unit_id: number
  unit_name: string
}

export interface RoutingGraphNode {
  id: number
  node_no: string
  process_id: number
  process_name: string
  name: string
  output_product_id: number
  output_product_name: string
  output_qty: number
  is_outsourced: number
  remark: string | null
  materials: RoutingGraphMaterial[]
}

export interface RoutingGraphPayload {
  routing: {
    id: number
    code: string
    product_id: number
    product_name: string
    version: string
    quantity: number
    status: number
    remark: string | null
  }
  nodes: RoutingGraphNode[]
  edges: { id: number; from_node_no: string; to_node_no: string }[]
}

interface PageResult<T> {
  items: T[]
  total: number
  page: number
  per_page: number
}

export const routingApi = {
  async list(params: Record<string, unknown>) {
    const { data } = await http.get('/routings', { params })
    return data.data as PageResult<RoutingListItem>
  },
  async create(payload: RoutingPayload) {
    const { data } = await http.post('/routings', payload)
    return data.data as { id: number; code: string }
  },
  async update(id: number, payload: RoutingPayload) {
    const { data } = await http.put(`/routings/${id}`, payload)
    return data.data
  },
  async remove(id: number) {
    const { data } = await http.delete(`/routings/${id}`)
    return data.data
  },
  async toggle(id: number, status: 0 | 1) {
    const { data } = await http.put(`/routings/${id}/toggle`, { status })
    return data.data
  },
  async graph(id: number) {
    const { data } = await http.get(`/routings/${id}/graph`)
    return data.data as RoutingGraphPayload
  },
}
