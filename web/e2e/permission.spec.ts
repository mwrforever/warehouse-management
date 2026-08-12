// 越权防护 E2E：只读角色（operator）无增删改按钮；直接调 API 被 403 拦截
import { expect, test } from '@playwright/test'
import { loginByAPI, loginByUI } from './helpers'

test.describe('越权防护', () => {
  test('operator 用户页面无新增按钮，越权 API 调用返回 403', async ({ page }) => {
    // 1. admin 创建 operator 用户（挂「操作员」只读角色）
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/system/users')
    const opName = `op_${Date.now()}`
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-form-item', { hasText: '用户名' }).locator('input').fill(opName)
    await dialog.locator('.el-form-item', { hasText: '姓名' }).locator('input').fill('只读操作员')
    await dialog.locator('.el-form-item', { hasText: '密码' }).locator('input').fill('Operator123')
    // 角色多选：选择「操作员」（只读角色）
    await dialog.locator('.el-form-item', { hasText: '角色' }).locator('.el-select').click()
    await page.getByRole('option', { name: '操作员' }).click()
    // 收起下拉：避免 popper 残留层拦截后续点击
    await page.keyboard.press('Escape')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-message--success')).toContainText('保存成功')

    // 2. 登出（清空 token），operator 登录
    await page.locator('.user-name').click()
    await page.getByText('退出登录').click()
    await loginByUI(page, opName, 'Operator123')

    // 3. 页面可访问（有 user.list）但无增删改按钮（无 user.create/user.delete）
    await page.goto('/system/users')
    await expect(page.locator('.el-table')).toBeVisible()
    await expect(page.getByRole('button', { name: /新\s*建/ })).toHaveCount(0)
    await expect(page.getByRole('button', { name: /删\s*除/ })).toHaveCount(0)

    // 4. 越权 API：operator token 直接调用创建用户接口 → 403
    const token = await page.evaluate(() => localStorage.getItem('token'))
    const res = await page.request.post('/api/v1/users', {
      headers: { Authorization: `Bearer ${token}` },
      data: {
        name: '越权尝试',
        username: `hack_${Date.now()}`,
        password: 'Hackpass123',
        role_ids: [],
      },
    })
    expect(res.status()).toBe(403)
  })
})
