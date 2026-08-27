// 工序页组件测试：编码自动生成（无编码输入框 + 提示）、分类下拉来自字典（process_category）、
// 保存载荷含 category_id、列表按分类筛选请求携带 category_id
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import ProcessesView from '../views/master/ProcessesView.vue'
import { useAuthStore } from '../stores/auth'
import { processApi } from '../api/process'

// mock API：工序 CRUD + 字典（工序分类 process_category：机械加工/装配/检验）
vi.mock('../api/process', () => ({
  processApi: {
    list: vi.fn().mockResolvedValue({
      items: [
        {
          id: 1,
          name: '下料',
          code: 'PROC0001',
          category_id: null,
          category_label: null,
          sort: 1,
          description: null,
          status: 1,
        },
      ],
    }),
    create: vi.fn().mockResolvedValue({ id: 2, code: 'PROC0002' }),
    update: vi.fn().mockResolvedValue({ data: { code: 0 } }),
    remove: vi.fn(),
  },
}))
vi.mock('../api/dictionary', () => ({
  dictionaryApi: {
    list: vi.fn().mockResolvedValue({
      items: [{ id: 1, name: '工序分类', code: 'process_category', remark: null }],
      total: 1,
      page: 1,
      per_page: 10,
    }),
    items: vi.fn().mockResolvedValue({
      items: [
        { id: 11, label: '机械加工', value: 'machining', sort: 200, status: 1 },
        { id: 12, label: '装配', value: 'assembly', sort: 220, status: 1 },
        { id: 13, label: '检验', value: 'inspection', sort: 230, status: 1 },
      ],
    }),
  },
}))

describe('工序页：编码自动生成 + 标签分类（字典项）', () => {
  let store: ReturnType<typeof useAuthStore>
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    vi.clearAllMocks()
    pinia = createPinia()
    setActivePinia(pinia)
    store = useAuthStore()
    store.permissions = ['process.create', 'process.update', 'process.delete']
  })

  function mountView() {
    return mount(ProcessesView, {
      attachTo: document.body,
      global: { plugins: [ElementPlus, pinia] },
    })
  }

  async function openCreate(wrapper: ReturnType<typeof mountView>) {
    const newBtn = wrapper.findAll('button').find((b) => b.text().trim() === '新 建')
    expect(newBtn, '新建按钮应存在').toBeTruthy()
    await newBtn!.trigger('click')
    await flushPromises()
  }

  // 当前打开的单个下拉的选项：页面筛选栏与弹窗内分类下拉选项相同，
  // 关闭态 popper 也不会从 DOM 移除，须按 display 过滤避免误点隐藏下拉中的同名选项
  function visibleOptions() {
    const open = [...document.querySelectorAll('.el-popper')].find(
      (p) => !((p as HTMLElement).getAttribute('style') ?? '').includes('display: none'),
    )
    return open ? [...open.querySelectorAll('.el-select-dropdown__item')] : []
  }

  it('新建弹窗无编码输入框，展示「编码自动生成」提示', async () => {
    // 正常路径：编码改由后端自动生成，表单不再手填
    const wrapper = mountView()
    await flushPromises()
    await openCreate(wrapper)

    const dialog = wrapper.find('.el-dialog')
    expect(dialog.exists(), '新建弹窗应打开').toBe(true)
    // 「编码」表单项存在但只有提示文案，无输入框（编辑态才回显只读编码）
    const codeItem = dialog.findAll('.el-form-item').find((i) => i.text().includes('编码'))
    expect(codeItem, '编码表单项应存在').toBeTruthy()
    expect(codeItem!.find('input').exists(), '新建弹窗不应有编码输入框').toBe(false)
    expect(dialog.text()).toContain('编码自动生成')
    wrapper.unmount()
  })

  it('分类下拉从字典接口加载 label，保存载荷含 category_id', async () => {
    // 正常路径：选择「机械加工」后提交，create 载荷带 category_id=11
    const wrapper = mountView()
    await flushPromises()
    await openCreate(wrapper)

    // 输入名称（弹窗内第一个 input 即名称框）
    await wrapper.find('.el-dialog input').setValue('车削')
    // 打开标签分类下拉（新建弹窗内唯一 el-select）
    const catSelect = wrapper.find('.el-dialog .el-select__wrapper')
    expect(catSelect, '分类下拉应存在').toBeTruthy()
    await catSelect.trigger('click')
    await flushPromises()
    const opt = visibleOptions().find((o) => o.textContent!.trim() === '机械加工')
    expect(opt, '字典项 机械加工 应作为选项加载').toBeTruthy()
    ;(opt as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    const saveBtn = wrapper.findAll('button').find((b) => b.text().trim() === '保 存')
    await saveBtn!.trigger('click')
    await flushPromises()
    expect(processApi.create).toHaveBeenCalledWith(
      expect.objectContaining({ name: '车削', category_id: 11 }),
    )
    wrapper.unmount()
  })

  it('列表加载后按分类筛选，请求携带 category_id', async () => {
    // 正常路径：筛选栏选择「检验」→ 重新加载列表并携带 category_id=13
    const wrapper = mountView()
    await flushPromises()
    expect(processApi.list).toHaveBeenCalled()

    const filterSelect = wrapper.find('.toolbar .el-select__wrapper')
    expect(filterSelect, '筛选栏分类下拉应存在').toBeTruthy()
    await filterSelect.trigger('click')
    await flushPromises()
    const opt = visibleOptions().find((o) => o.textContent!.trim() === '检验')
    expect(opt, '筛选分类选项 检验 应存在').toBeTruthy()
    ;(opt as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await flushPromises()

    expect(processApi.list).toHaveBeenLastCalledWith({ category_id: 13 })
    wrapper.unmount()
  })
})
