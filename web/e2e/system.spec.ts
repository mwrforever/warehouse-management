// 系统管理模块 E2E：用户管理新建→编辑→删除全流程 + 内置 admin 删除保护 + 角色/字典页面可访问
import { expect, test } from '@playwright/test'
import { loginByAPI } from './helpers'

test.describe('系统管理模块', () => {
  test('用户管理：新建 → 编辑 → 删除 全流程', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/system/users')
    await expect(page.getByRole('button', { name: /新\s*建/ })).toBeVisible()

    // 新建用户（唯一用户名避免与种子冲突；密码满足强度要求）
    const username = `e2e_${Date.now()}`
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-form-item', { hasText: '用户名' }).locator('input').fill(username)
    await dialog.locator('.el-form-item', { hasText: '姓名' }).locator('input').fill('E2E测试用户')
    await dialog.locator('.el-form-item', { hasText: '密码' }).locator('input').fill('E2epass123')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()

    // 保存成功提示 + 表格出现新行
    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    await expect(page.locator('.el-table__row', { hasText: username })).toBeVisible()

    // 编辑：改名后保存，表格行内姓名更新
    await page
      .locator('.el-table__row', { hasText: username })
      .getByRole('button', { name: /编\s*辑/ })
      .click()
    await dialog.locator('.el-form-item', { hasText: '姓名' }).locator('input').fill('E2E改名用户')
    await dialog.getByRole('button', { name: /保\s*存/ }).click()
    await expect(page.locator('.el-table__row', { hasText: 'E2E改名用户' })).toBeVisible()

    // 删除：二次确认后行消失
    await page
      .locator('.el-table__row', { hasText: username })
      .getByRole('button', { name: /删\s*除/ })
      .click()
    await page.getByRole('button', { name: '确定' }).click()
    await expect(page.locator('.el-table__row', { hasText: username })).toHaveCount(0)
  })

  test('内置 admin 用户删除被后端拦截并提示', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/system/users')

    // admin 行（用户名 admin）删除 → 确认 → 后端拒绝并展示错误
    const adminRow = page.locator('.el-table__row', { hasText: 'admin' }).first()
    await adminRow.getByRole('button', { name: /删\s*除/ }).click()
    await page.getByRole('button', { name: '确定' }).click()
    await expect(page.locator('.el-message--error')).toContainText('内置管理员不可删除')
    // 行仍然存在
    await expect(adminRow).toBeVisible()
  })

  test('角色管理、字典管理页面可正常访问', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    await page.goto('/system/roles')
    await expect(page.locator('.el-table')).toBeVisible()

    await page.goto('/system/dictionaries')
    await expect(page.locator('.el-table')).toBeVisible()
  })
})
