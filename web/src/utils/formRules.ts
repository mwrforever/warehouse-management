// 单据表单行级字段共用校验器（D-17 购销域引入，产盘退域扩展 optionalQuantityRule）：数量/单价输入框口径为「数量 / 元」展示值，
// el-input-number 的 :precision 已在输入侧钳制，此处做提交前兜底——空值、非数字、超范围、超精度
// 在 el-form validate 阶段拦截，避免发出可预期的 422 请求；校验针对输入框现值，
// 与提交侧 yuanToFen（元→分）单位换算解耦，rules 不感知分
import type { FormItemRule } from 'element-plus'

// 数字值是否最多 max 位小数：String(number) 为 JS 最短精确表达（1.005 → '1.005'、
// 0.1+0.2 → '0.30000000000000004'），按此判定展示精度，避免 toFixed 舍入掩盖超精度值
function hasMaxDecimals(value: number, max: number): boolean {
  const text = String(value)
  const dot = text.indexOf('.')
  return dot < 0 || text.length - dot - 1 <= max
}

// 数量规则：allowZero=true 允许 0（从订单生成场景：0 = 本次不收货，提交时剔除），
// 否则必须 > 0（防空数量单据）；0.5 等小数合法，精度最多 2 位（与后端 decimal(12,2) 同口径）；
// rangeMessage 为范围不满足时的提示文案（按单据模式区分）
export function quantityRule(allowZero: boolean, rangeMessage: string): FormItemRule {
  return {
    validator: (_rule, value, callback) => {
      // 清空输入框后 el-input-number 置 null，按未填拦截
      if (value == null) {
        callback(new Error('请输入数量'))
        return
      }
      const n = Number(value)
      if (Number.isNaN(n)) {
        callback(new Error('请输入有效的数字'))
        return
      }
      if (allowZero ? n < 0 : n <= 0) {
        callback(new Error(rangeMessage))
        return
      }
      if (!hasMaxDecimals(n, 2)) {
        callback(new Error('数量最多 2 位小数'))
        return
      }
      callback()
    },
    trigger: 'blur',
  }
}

// 单价（元）规则：≥ 0 且最多 2 位小数——金额输入口径为「元」（R2 后展示/输入单位），
// 保存时再经 yuanToFen 精确转分提交，此处只校验输入框现值格式
export const priceRule: FormItemRule = {
  validator: (_rule, value, callback) => {
    if (value == null) {
      callback(new Error('请输入单价'))
      return
    }
    const n = Number(value)
    if (Number.isNaN(n)) {
      callback(new Error('请输入有效的数字'))
      return
    }
    if (n < 0) {
      callback(new Error('价格不能为负数'))
      return
    }
    if (!hasMaxDecimals(n, 2)) {
      callback(new Error('单价最多 2 位小数'))
      return
    }
    callback()
  },
  trigger: 'blur',
}

// 可空数量规则（D-17 产盘退域引入）：用于「填了才生效」的可选字段（报工工时/不良数），
// 空值视为不填写直接放行（提交时置 undefined 不上报）；填写时须 ≥ 0 且最多 2 位小数，
// 负值/超精度在 el-form validate 阶段拦截，口径与 quantityRule 一致
export function optionalQuantityRule(rangeMessage: string): FormItemRule {
  return {
    validator: (_rule, value, callback) => {
      if (value == null) {
        callback()
        return
      }
      const n = Number(value)
      if (Number.isNaN(n)) {
        callback(new Error('请输入有效的数字'))
        return
      }
      if (n < 0) {
        callback(new Error(rangeMessage))
        return
      }
      if (!hasMaxDecimals(n, 2)) {
        callback(new Error('数量最多 2 位小数'))
        return
      }
      callback()
    },
    trigger: 'blur',
  }
}
