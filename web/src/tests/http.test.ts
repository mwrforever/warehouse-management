// http.ts 拦截器测试：会话失效（401/419）跳登录、业务信封解包与错误透传（P2-1 修复回归保护）
import { AxiosError } from 'axios'
import { beforeEach, describe, expect, it } from 'vitest'
import { http } from '../api/http'

// 模拟 location：jsdom 下直接改 href 会触发未实现的导航，故用可写普通对象替换
// （拦截器只读 pathname 判断当前页、写 href 触发跳转，均可在该对象上断言）
const locationMock = { href: 'http://localhost:4000/dashboard', pathname: '/dashboard' }

/**
 * 自定义 adapter：绕过真实网络按指定状态返回（axios 1.x 的状态码校验在各 adapter 内部，
 * 故此处模拟 xhr adapter 行为——非 2xx 抛 AxiosError，与浏览器真实链路一致）
 */
function mockResponse(status: number, body: unknown) {
  http.defaults.adapter = async (config) => {
    const response = { data: body, status, statusText: '', headers: {}, config, request: {} }
    // 与 xhr adapter 同判据：validateStatus 未通过即 reject，拦截器错误分支才会被触发
    if (config.validateStatus && !config.validateStatus(status)) {
      throw new AxiosError(
        `Request failed with status code ${status}`,
        status >= 400 && status < 500 ? AxiosError.ERR_BAD_REQUEST : AxiosError.ERR_BAD_RESPONSE,
        config,
        response.request,
        response,
      )
    }
    return response
  }
}

beforeEach(() => {
  Object.defineProperty(window, 'location', {
    value: { ...locationMock },
    writable: true,
  })
})

describe('http 响应拦截器', () => {
  it('业务成功信封 code=0 正常返回', async () => {
    // 正常路径：code=0 不解包，原样返回供调用方取 data
    mockResponse(200, { code: 0, data: { id: 1 } })
    const res = await http.get('/demo')
    expect(res.data.data).toEqual({ id: 1 })
  })

  it('419 CSRF mismatch 时跳登录页并抛出后端 message', async () => {
    // 异常路径（P2-1）：会话过期后写请求 CSRF 校验失败，与 401 同等处理——跳登录页、透传 message
    mockResponse(419, { message: 'CSRF token mismatch.' })
    await expect(http.post('/demo')).rejects.toThrow('CSRF token mismatch.')
    expect(window.location.href).toBe('/login')
  })

  it('401 未认证跳登录页且不抛出原始 axios 错误', async () => {
    // 异常路径：401 跳登录页，message 来自后端统一信封
    mockResponse(401, { code: 401, message: '未认证或登录已过期', data: null })
    await expect(http.get('/demo')).rejects.toThrow('未认证或登录已过期')
    expect(window.location.href).toBe('/login')
  })

  it('登录页自身的 401/419 不跳转（防重载循环）', async () => {
    // 边界路径：登录页探测会话产生的 401/419 不得触发跳转，否则页面无限重载
    Object.defineProperty(window, 'location', {
      value: { href: 'http://localhost:4000/login', pathname: '/login' },
      writable: true,
    })
    mockResponse(419, { message: 'CSRF token mismatch.' })
    await expect(http.post('/auth/login')).rejects.toThrow('CSRF token mismatch.')
    expect(window.location.href).toBe('http://localhost:4000/login')
  })

  it('业务失败 code!=0 时在成功分支抛出 message', async () => {
    // 异常路径：HTTP 200 但业务 code 非 0（如 1002 重复用户名），在响应成功分支解包抛错
    mockResponse(200, { code: 1002, message: '用户名已存在', data: null })
    await expect(http.post('/demo')).rejects.toThrow('用户名已存在')
  })
})
