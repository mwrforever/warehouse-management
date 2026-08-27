// 仓库页组件测试：编码改为后端自动生成（新建无编码输入+提示、编辑只读）、
// 地址改四级地址级联（AreaCascader）+ 详细地址、负责人改 UserSelect 用户选择，
// 保存载荷含 region 四字段 + address + manager（与后端契约一致，payload 不含 code）
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import ElementPlus from 'element-plus'
import WarehousesView from '../views/master/WarehousesView.vue'
import AreaCascader from '../components/AreaCascader.vue'
import UserSelect, { __resetUserSelectCache } from '../components/UserSelect.vue'
import { useAuthStore } from '../stores/auth'

const listMock = vi.fn()
const createMock = vi.fn()
const updateMock = vi.fn()
vi.mock('../api/warehouse', () => ({
  warehouseApi: {
    list: (...args: unknown[]) => listMock(...args),
    create: (...args: unknown[]) => createMock(...args),
    update: (...args: unknown[]) => updateMock(...args),
    remove: vi.fn(),
    locations: vi.fn().mockResolvedValue({ items: [] }),
    createLocation: vi.fn(),
    updateLocation: vi.fn(),
    removeLocation: vi.fn(),
  },
}))
vi.mock('../api/user', () => ({
  userApi: { list: vi.fn().mockResolvedValue({ items: mockUsers(10), total: 10 }) },
}))

// 用户列表数据：10 人 ≤50 → UserSelect 渲染 el-select 直选模式（真实组件）
function mockUsers(n: number) {
  return Array.from({ length: n }, (_, i) => ({
    id: i + 1,
    name: `用户${i + 1}`,
    username: `user${i + 1}`,
    email: null,
    status: 1,
    last_login_at: null,
    roles: [],
  }))
}

describe('仓库页编码自动生成 + 四级地址 + 负责人选择', () => {
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    vi.clearAllMocks()
    __resetUserSelectCache() // 清 UserSelect 模块级缓存，避免用例间串扰
    listMock.mockResolvedValue({ items: [], total: 0 })
    createMock.mockResolvedValue({ code: 0 })
    updateMock.mockResolvedValue({ code: 0 })
    pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().permissions = ['warehouse.create', 'warehouse.list', 'warehouse.update']
  })

  function mountView() {
    return mount(WarehousesView, {
      attachTo: document.body,
      global: { plugins: [ElementPlus, pinia] },
    })
  }

  it('新建弹窗无编码输入框，显示「编码自动生成」提示', async () => {
    // 正常路径：编码不再由前端填写，由后端按号段自动生成
    const wrapper = mountView()
    await flushPromises()
    const newBtn = wrapper.findAll('button').find((b) => b.text().trim() === '新 建')
    expect(newBtn).toBeTruthy()
    await newBtn!.trigger('click')
    await flushPromises()

    const codeItem = wrapper
      .findAll('.el-form-item')
      .find((fi) => fi.find('.el-form-item__label').text() === '编码')
    expect(codeItem, '编码表单项应存在').toBeTruthy()
    expect(codeItem!.find('input').exists(), '新建态编码行不得有输入框').toBe(false)
    expect(wrapper.find('.code-auto-tip').text().trim()).toBe('编码自动生成')
    wrapper.unmount()
  })

  it('保存载荷含 region 四字段 + address + manager，且不含 code', async () => {
    // 正常路径：四级地址级联 → 四列字段、详细地址、负责人姓名一并提交
    const wrapper = mountView()
    await flushPromises()
    const newBtn = wrapper.findAll('button').find((b) => b.text().trim() === '新 建')
    await newBtn!.trigger('click')
    await flushPromises()

    await wrapper.find('.el-dialog input').setValue('测试仓') // 名称（弹窗第一个输入框）
    // 区域级联：直接对 AreaCascader 触发更新（级联面板交互复杂，组件契约已由 use-remote 类测试覆盖）
    const area = wrapper.findComponent(AreaCascader)
    area.vm.$emit('update:modelValue', {
      province: '广东省',
      city: '深圳市',
      district: '南山区',
      town: '粤海街道',
    })
    await wrapper.find('input[placeholder="详细地址"]').setValue('科技园路1号')
    // 负责人：UserSelect 真实组件渲染 el-select，直接触发其选中事件（与 user-select.test 同法）
    const userSelect = wrapper.findComponent(UserSelect)
    expect(userSelect.exists(), '负责人控件应为 UserSelect').toBe(true)
    userSelect.vm.$emit('update:modelValue', '李四')
    await flushPromises()

    const saveBtn = wrapper.findAll('button').find((b) => b.text().trim() === '保 存')
    await saveBtn!.trigger('click')
    await flushPromises()
    expect(createMock).toHaveBeenCalledWith({
      name: '测试仓',
      province: '广东省',
      city: '深圳市',
      district: '南山区',
      town: '粤海街道',
      address: '科技园路1号',
      manager: '李四',
      status: 1,
    })
    expect(createMock).toHaveBeenCalledWith(
      expect.not.objectContaining({ code: expect.anything() }),
    )
    wrapper.unmount()
  })

  it('编辑弹窗编码只读展示，列表地址列拼接省市区镇+详细地址', async () => {
    // 正常路径：编辑时编码只读（显示原 code 不输入）；列表地址列两段拼接
    listMock.mockResolvedValue({
      items: [
        {
          id: 1,
          name: '主仓',
          code: 'WH01',
          address: '厂区A',
          province: '广东省',
          city: '深圳市',
          district: '南山区',
          town: '粤海街道',
          manager: '张三',
          status: 1,
        },
      ],
      total: 1,
    })
    const wrapper = mountView()
    await flushPromises()

    // 列表地址列：省市区镇 + 详细地址拼接（任一级为空跳过）
    expect(wrapper.find('.el-table__row').text()).toContain('广东省深圳市南山区粤海街道厂区A')

    const editBtn = wrapper.findAll('button').find((b) => b.text().trim() === '编 辑')
    await editBtn!.trigger('click')
    await flushPromises()

    const codeItem = wrapper
      .findAll('.el-form-item')
      .find((fi) => fi.find('.el-form-item__label').text() === '编码')
    expect(codeItem!.find('input').exists(), '编辑态编码列不得为输入框').toBe(false)
    expect(codeItem!.text()).toContain('WH01')
    // 四级地址回填 region 对象（与 AreaCascader modelValue 契约一致）
    expect(wrapper.findComponent(AreaCascader).props('modelValue')).toEqual({
      province: '广东省',
      city: '深圳市',
      district: '南山区',
      town: '粤海街道',
    })
    // 编辑保存：载荷不含 code，编码不被改写
    await wrapper.find('input[placeholder="详细地址"]').setValue('厂区B')
    const saveBtn = wrapper.findAll('button').find((b) => b.text().trim() === '保 存')
    await saveBtn!.trigger('click')
    await flushPromises()
    expect(updateMock).toHaveBeenCalledWith(
      1,
      expect.not.objectContaining({ code: expect.anything() }),
    )
    expect(updateMock).toHaveBeenCalledWith(
      1,
      expect.objectContaining({ name: '主仓', address: '厂区B', manager: '张三' }),
    )
    wrapper.unmount()
  })
})
