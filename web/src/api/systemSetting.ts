// 系统管理-编号规则 API 封装（Spec 2）：列表/编辑/预览，契约与后端 document-number-configs 同步
import { http } from './http'

export interface NumberConfigItem {
  id: number
  type: string
  type_label: string
  prefix: string
  date_format: string
  seq_length: number
  enabled: boolean
  remark: string | null
}

export const systemSettingApi = {
  // 分页列表（13 类规则，per_page 缺省 20）
  async list(params: { page?: number; per_page?: number } = {}) {
    const { data } = await http.get('/document-number-configs', {
      params: { per_page: 50, ...params },
    })
    return data.data as { items: NumberConfigItem[]; total: number; page: number; per_page: number }
  },
  // 编辑规则（prefix/date_format/seq_length/enabled/remark；type 由种子固定不可改）
  async update(id: number, payload: Omit<NumberConfigItem, 'id' | 'type' | 'type_label'>) {
    await http.put(`/document-number-configs/${id}`, payload)
  },
  // 规则预览（按临时值出示例单号，不落库）
  async preview(payload: { prefix: string; date_format: string; seq_length: number }) {
    const { data } = await http.post('/document-number-configs/preview', payload)
    return data.data as { no: string }
  },
}
