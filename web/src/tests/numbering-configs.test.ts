// 编号规则页组件测试：保存失败必须弹错（BUG-01 空 catch 回归）+ 非法 prefix/超长 remark 前端拦截 + 合法保存正常路径
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import NumberingConfigsView from '../views/system/NumberingConfigsView.vue'
import { useAuthStore } from '../stores/auth'

// mock 编号规则 API：列表提供一条采购订单规则（PO/YmdHi/3 位序号），预览返回固定示例号
vi.mock('../api/systemSetting', () => ({
  systemSettingApi: {
    list: vi.fn().mockResolvedValue({
      items: [
        {
          id: 1,
          type: 'po',
          type_label: '采购订单',
          prefix: 'PO',
          date_format: 'YmdHi',
          seq_length: 3,
          enabled: true,
          remark: '',
        },
      ],
      total: 1,
      page: 1,
      per_page: 50,
    }),
    update: vi.fn().mockResolvedValue(undefined),
    preview: vi.fn().mockResolvedValue({ no: 'PO20260823000001' }),
  },
}))
import { systemSettingApi } from '../api/systemSetting'

describe('编号规则页保存反馈与表单校验', () => {
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    vi.clearAllMocks()
    pinia = createPinia()
    setActivePinia(pinia)
    // 编辑按钮渲染依赖 system.setting.update 权限（路由/按钮双层权限的前端一侧）
    useAuthStore().permissions = ['system.setting.update']
  })

  function mountView() {
    return mount(NumberingConfigsView, {
      attachTo: document.body,
      global: { plugins: [ElementPlus, pinia] },
    })
  }

  // 打开「采购订单」行的编辑弹窗（表格行内编辑按钮）
  async function openEditDialog(wrapper: VueWrapper) {
    const btn = wrapper.findAll('button').find((b) => b.text().includes('编'))
    expect(btn, '编辑按钮应存在').toBeTruthy()
    await btn!.trigger('click')
    await flushPromises()
    expect(wrapper.find('.el-dialog').exists()).toBe(true)
  }

  // 按表单项 label 填充输入框（prefix 为 input、备注为 textarea）
  async function fillByLabel(wrapper: VueWrapper, label: string, val: string) {
    const item = wrapper
      .findAll('.el-dialog .el-form-item')
      .find((fi) => fi.find('.el-form-item__label').text().trim() === label)
    expect(item, `表单项「${label}」应存在`).toBeTruthy()
    await item!.find('input,textarea').setValue(val)
    await flushPromises()
  }

  // 点击弹窗底部「保 存」按钮
  async function clickSave(wrapper: VueWrapper) {
    const btn = wrapper.findAll('button').find((b) => b.text().trim() === '保 存')
    expect(btn, '保存按钮应存在').toBeTruthy()
    await btn!.trigger('click')
    await flushPromises()
  }

  it('保存被后端拒绝时弹出错误提示且弹窗保持打开', async () => {
    // 异常场景：422 等后端拒绝必须让用户感知（修复前空 catch 完全静默），弹窗留着供修改重试
    const wrapper = mountView()
    await flushPromises()
    await openEditDialog(wrapper)
    vi.mocked(systemSettingApi.update).mockRejectedValueOnce(new Error('前缀格式：大写字母2~4位'))

    await clickSave(wrapper)

    const msg = document.querySelector('.el-message--error')
    expect(msg, '保存失败应弹出错误提示').toBeTruthy()
    expect((msg as HTMLElement).textContent).toContain('前缀格式：大写字母2~4位')
    // 保存失败不关弹窗：用户可直接修正输入后重试
    expect(wrapper.find('.el-dialog').isVisible()).toBe(true)
    wrapper.unmount()
  })

  it('prefix 含小写字母时保存被前端校验拦截，不发更新请求并提示原因', async () => {
    // 边界条件：小写/数字前缀在后端必 422，前端 el-form 校验在提交前拦截
    const wrapper = mountView()
    await flushPromises()
    await openEditDialog(wrapper)

    await fillByLabel(wrapper, '前缀', 'po1')
    await clickSave(wrapper)

    expect(systemSettingApi.update).not.toHaveBeenCalled()
    // 字段级错误提示经 100ms 防抖后渲染（element-plus validateStateDebounced），等待真实定时器
    await new Promise((r) => setTimeout(r, 150))
    const err = wrapper.find('.el-form-item__error')
    expect(err.exists(), '应显示字段级校验错误').toBe(true)
    expect(err.text()).toContain('大写字母')
    wrapper.unmount()
  })

  it('备注超过 255 字时保存被前端校验拦截，不发更新请求', async () => {
    // 边界条件：后端 remark max:255，超长在前端拦截
    const wrapper = mountView()
    await flushPromises()
    await openEditDialog(wrapper)

    await fillByLabel(wrapper, '备注', 'a'.repeat(256))
    await clickSave(wrapper)

    expect(systemSettingApi.update).not.toHaveBeenCalled()
    // 字段级错误提示经 100ms 防抖后渲染（同上一用例）
    await new Promise((r) => setTimeout(r, 150))
    expect(wrapper.find('.el-form-item__error').text()).toContain('255')
    wrapper.unmount()
  })

  it('prefix 清空时前端放行提交（必填性由后端 required 兜底）', async () => {
    // 边界条件：校验规则为"可空但非空时须 2~4 位大写字母"，空值不拦、交后端判定
    const wrapper = mountView()
    await flushPromises()
    await openEditDialog(wrapper)

    await fillByLabel(wrapper, '前缀', '')
    await clickSave(wrapper)

    expect(systemSettingApi.update).toHaveBeenCalledWith(1, expect.objectContaining({ prefix: '' }))
    wrapper.unmount()
  })

  it('合法输入保存成功弹出成功提示并关闭弹窗', async () => {
    // 正常路径：校验不得误拦合法规则（打开弹窗直接保存，字段为种子默认值）
    const wrapper = mountView()
    await flushPromises()
    await openEditDialog(wrapper)

    await clickSave(wrapper)

    expect(systemSettingApi.update).toHaveBeenCalledWith(1, {
      prefix: 'PO',
      date_format: 'YmdHi',
      seq_length: 3,
      enabled: true,
      remark: '',
    })
    const msg = document.querySelector('.el-message--success')
    expect(msg, '保存成功应弹出提示').toBeTruthy()
    expect((msg as HTMLElement).textContent).toContain('已保存')
    wrapper.unmount()
  })
})
