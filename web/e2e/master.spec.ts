// 基础资料模块 E2E：分类/单位新建主流程 + 商品/BOM 页面可访问
import { expect, test } from '@playwright/test'
import { loginByAPI } from './helpers'

test.describe('基础资料模块', () => {
  test('分类管理：新建顶级分类出现在树中', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/master/categories')
    await expect(page.getByRole('button', { name: /新\s*建/ })).toBeVisible()

    const name = `E2E分类${Date.now()}`
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    // 上级分类默认「无（顶级分类）」，仅填名称
    await dialog.locator('.el-form-item', { hasText: '名称' }).locator('input').fill(name)
    await dialog.getByRole('button', { name: /保\s*存/ }).click()

    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    // 分类页是 el-tree 树形结构：新顶级节点出现在树中
    await expect(page.getByRole('treeitem', { name })).toBeVisible()
  })

  test('单位管理：新建单位（名称+编码）出现在列表', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')
    await page.goto('/master/units')

    const name = `E2E单位${Date.now()}`
    const code = `E2E${Date.now()}`
    await page.getByRole('button', { name: /新\s*建/ }).click()
    const dialog = page.locator('.el-dialog')
    await dialog.locator('.el-form-item', { hasText: '名称' }).locator('input').fill(name)
    await dialog.locator('.el-form-item', { hasText: '编码' }).locator('input').fill(code)
    await dialog.getByRole('button', { name: /保\s*存/ }).click()

    await expect(page.locator('.el-message--success')).toContainText('保存成功')
    await expect(page.locator('.el-table__row', { hasText: code })).toBeVisible()
  })

  test('商品管理、BOM 管理页面可正常访问', async ({ page }) => {
    await loginByAPI(page, 'admin', 'admin123')

    await page.goto('/master/products')
    await expect(page.locator('.el-table')).toBeVisible()

    await page.goto('/master/boms')
    await expect(page.locator('.el-table')).toBeVisible()
  })
})
