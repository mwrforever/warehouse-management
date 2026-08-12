// 登录流程 E2E：错误凭证拦截、正确登录跳转、登出回登录页
import { expect, test } from '@playwright/test'
import { loginByUI } from './helpers'

test.describe('登录流程', () => {
  test('错误密码提示失败并停留在登录页', async ({ page }) => {
    await page.goto('/login')
    await page.getByPlaceholder('请输入用户名').fill('admin')
    await page.getByPlaceholder('请输入密码').fill('wrong-password')
    await page.getByRole('button', { name: /登\s*录/ }).click()

    // 后端统一文案「用户名或密码错误」经 ElMessage 展示
    await expect(page.locator('.el-message--error')).toContainText('用户名或密码错误')
    await expect(page).toHaveURL(/\/login/)
  })

  test('正确凭证登录成功并跳转仪表盘', async ({ page }) => {
    await loginByUI(page, 'admin', 'admin123')

    // 品牌与侧边栏菜单渲染（admin 全权限）
    await expect(page.locator('.brand')).toHaveText('Nexus Factory')
    await expect(page.getByRole('link', { name: '用户管理' })).toBeVisible()
    await expect(page.getByRole('link', { name: '商品管理' })).toBeVisible()
  })

  test('登出回到登录页', async ({ page }) => {
    await loginByUI(page, 'admin', 'admin123')

    // 顶栏用户名下拉 → 退出登录 → 回登录页
    await page.locator('.user-name').click()
    await page.getByText('退出登录').click()
    await expect(page).toHaveURL(/\/login/)
  })
})
