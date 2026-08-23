// 编号规则页组件测试：保存失败必须弹错（BUG-01 空 catch 回归）+ 非法 prefix/超长 remark 前端拦截 + 合法保存正常路径；
// 预览请求防抖与乱序守卫（BUG-03：并发乱序回写使预览短暂显示与表单不符的示例号）
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
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
    // 字段级错误提示经 100ms 防抖后渲染（element-plus validateStateDebounced），
    // 轮询等待断言，消除对防抖内部时序的实现耦合（评审 Minor-2）
    await vi.waitFor(() => {
      const err = wrapper.find('.el-form-item__error')
      expect(err.exists(), '应显示字段级校验错误').toBe(true)
      expect(err.text()).toContain('大写字母')
    })
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
    // 字段级错误提示经 100ms 防抖后渲染（同上一用例，轮询等待）
    await vi.waitFor(() => {
      expect(wrapper.find('.el-form-item__error').text()).toContain('255')
    })
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

describe('编号规则页预览防抖与乱序守卫', () => {
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    vi.clearAllMocks()
    // 防抖/序号守卫用例使用 fake timers 精确推进 300ms 防抖窗口
    vi.useFakeTimers()
    pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().permissions = ['system.setting.update']
  })
  afterEach(() => vi.useRealTimers())

  function mountView() {
    return mount(NumberingConfigsView, {
      attachTo: document.body,
      global: { plugins: [ElementPlus, pinia] },
    })
  }

  // 打开「采购订单」行的编辑弹窗
  async function openEditDialog(wrapper: VueWrapper) {
    const btn = wrapper.findAll('button').find((b) => b.text().includes('编'))
    expect(btn, '编辑按钮应存在').toBeTruthy()
    await btn!.trigger('click')
    await flushPromises()
  }

  // 修改弹窗内前缀输入框
  async function fillPrefix(wrapper: VueWrapper, val: string) {
    const item = wrapper
      .findAll('.el-dialog .el-form-item')
      .find((fi) => fi.find('.el-form-item__label').text().trim() === '前缀')
    expect(item, '前缀输入框应存在').toBeTruthy()
    await item!.find('input').setValue(val)
    await flushPromises()
  }

  // 预览示例号显示在弹窗「规则预览」行（v-if=previewNo 控制渲染）
  function previewText(wrapper: VueWrapper) {
    const item = wrapper
      .findAll('.el-dialog .el-form-item')
      .find((fi) => fi.text().includes('规则预览'))
    return item?.find('.font-code').text() ?? ''
  }

  it('打开弹窗并连续修改前缀，300ms 防抖内只发一次预览请求且取最后输入值', async () => {
    const wrapper = mountView()
    await flushPromises()
    await openEditDialog(wrapper)
    await fillPrefix(wrapper, 'POX')

    // 防抖期内（打开弹窗 + 修改前缀的多次触发合并）不应发任何预览请求
    expect(systemSettingApi.preview).not.toHaveBeenCalled()

    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(systemSettingApi.preview).toHaveBeenCalledTimes(1)
    expect(systemSettingApi.preview).toHaveBeenCalledWith({
      prefix: 'POX',
      date_format: 'YmdHi',
      seq_length: 3,
    })
    wrapper.unmount()
  })

  it('预览响应乱序到达时旧响应被丢弃，不覆盖新示例号', async () => {
    // 第一发请求挂起（慢响应），第二发立即返回：旧响应后到必须被序号守卫丢弃
    let resolveOld!: (v: { no: string }) => void
    const oldSlow = new Promise<{ no: string }>((r) => {
      resolveOld = r
    })
    vi.mocked(systemSettingApi.preview)
      .mockImplementationOnce(() => oldSlow)
      .mockImplementationOnce(async () => ({ no: 'PO20260823001X' }))
    const wrapper = mountView()
    await flushPromises()
    await openEditDialog(wrapper)
    // 第一发：弹窗打开时的表单值（挂起在途）
    vi.advanceTimersByTime(300)
    await flushPromises()
    expect(systemSettingApi.preview).toHaveBeenCalledTimes(1)

    // 第二发：修改前缀后的新表单值（立即返回并回写）
    await fillPrefix(wrapper, 'POX')
    vi.advanceTimersByTime(300)
    await flushPromises()
    expect(systemSettingApi.preview).toHaveBeenCalledTimes(2)
    expect(previewText(wrapper)).toBe('PO20260823001X')

    // 旧响应迟到回包：守卫丢弃，预览仍显示新值（修复前被旧值覆盖）
    resolveOld({ no: 'PO20260823001' })
    await flushPromises()
    expect(previewText(wrapper)).toBe('PO20260823001X')
    wrapper.unmount()
  })

  it('组件卸载时取消挂起的防抖，预览请求不再发出', async () => {
    const wrapper = mountView()
    await flushPromises()
    await openEditDialog(wrapper)
    // 弹窗打开触发防抖（尚未到期）即卸载：挂起任务应被取消
    wrapper.unmount()

    vi.advanceTimersByTime(600)
    await flushPromises()
    expect(systemSettingApi.preview).not.toHaveBeenCalled()
  })
})
