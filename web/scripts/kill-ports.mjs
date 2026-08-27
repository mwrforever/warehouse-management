/**
 * E2E 残留端口清理脚本（宪法 §9.5：跑 Playwright 前必须清理残留的 7000/4000 进程）
 *
 * 业务意图：playwright.config.ts 配置了 reuseExistingServer: !CI，
 * 本地若残留 7000（后端 php artisan serve）/4000（前端 vite dev）监听进程，
 * Playwright 会复用旧 server、跳过 migrate:fresh --seed，脏库导致用例假失败。
 * 本脚本经 package.json 的 pretest:e2e 钩子在每次 e2e 前自动执行，从流程上杜绝脏库。
 *
 * 用法：node scripts/kill-ports.mjs <端口1> <端口2> ...
 * 约束：零依赖、纯 ESM；退出码恒为 0——清理属防御性动作，不得阻断 npm 钩子流程，
 * 失败场景仅输出 warn 由开发者人工排查（如 taskkill 权限不足），不视为脚本错误。
 */
import { execFileSync } from 'node:child_process'
// 显式导入而非使用全局 console/process：eslint flat config 未声明 Node 全局，避免 no-undef
import console from 'node:console'
import process from 'node:process'

/** 待清理端口列表（来源：pretest:e2e 钩子固定传入 7000 4000；仅接受纯数字参数防误用） */
const ports = process.argv.slice(2).filter((arg) => /^\d+$/.test(arg))

/**
 * Windows：解析 netstat -ano 输出，返回监听指定端口的去重 PID 列表
 *
 * LISTENING 行格式（空白分隔 5 列）：协议 本地地址 远程地址 状态 PID，
 * 本地地址形如 127.0.0.1:7000 或 [::]:7000，以 `:端口` 结尾判定归属，
 * 避免 7000 误匹配 17000/7001；IPv4/IPv6 双栈监听会输出两行，故用 Set 去重。
 */
function findPidsOnWindows(port) {
  const lines = execFileSync('netstat', ['-ano'], { encoding: 'utf8' }).split(/\r?\n/)
  const pids = new Set()
  for (const line of lines) {
    const cols = line.trim().split(/\s+/)
    if (cols.length === 5 && cols[3] === 'LISTENING' && cols[1].endsWith(`:${port}`)) {
      pids.add(cols[4])
    }
  }
  return [...pids]
}

/** Windows：强制终止指定 PID（taskkill /F），失败仅 warn 不抛错 */
function killPidOnWindows(pid, port) {
  try {
    execFileSync('taskkill', ['/F', '/PID', pid], { stdio: 'ignore' })
    console.log(`已终止端口 ${port} 的残留进程 PID ${pid}`)
  } catch {
    // 常见原因：进程在 netstat 与 taskkill 之间已自行退出，或权限不足——不阻断 e2e 流程
    console.warn(`警告：终止端口 ${port} 的 PID ${pid} 失败（可能已退出或权限不足），请人工确认`)
  }
}

/** 非 Windows：经 lsof 查端口监听 PID；返回 null 表示环境无 lsof（静默跳过），[] 表示无监听 */
function findPidsOnUnix(port) {
  try {
    return execFileSync('lsof', ['-t', `-i:${port}`], { encoding: 'utf8' })
      .split(/\s+/)
      .filter(Boolean)
  } catch (err) {
    if (err.code === 'ENOENT') return null
    // lsof 未找到监听进程时退出码为 1，视为端口已释放，无需清理
    return []
  }
}

/** 非 Windows：发送 SIGTERM 终止 PID，失败仅 warn 不抛错 */
function killPidOnUnix(pid, port) {
  try {
    process.kill(Number(pid), 'SIGTERM')
    console.log(`已终止端口 ${port} 的残留进程 PID ${pid}`)
  } catch {
    console.warn(`警告：终止端口 ${port} 的 PID ${pid} 失败（可能已退出或权限不足），请人工确认`)
  }
}

/** 单个端口的清理流程：查 PID → 逐个终止；无监听进程时仅提示，不报错 */
function cleanPort(port) {
  if (process.platform === 'win32') {
    const pids = findPidsOnWindows(port)
    if (pids.length === 0) {
      console.log(`端口 ${port} 无监听进程，无需清理`)
      return
    }
    for (const pid of pids) killPidOnWindows(pid, port)
    return
  }

  const pids = findPidsOnUnix(port)
  if (pids === null) {
    console.log(`当前环境无 lsof 命令，跳过端口 ${port} 清理`)
    return
  }
  if (pids.length === 0) {
    console.log(`端口 ${port} 无监听进程，无需清理`)
    return
  }
  for (const pid of pids) killPidOnUnix(pid, port)
}

// 主流程整体兜底：任何未预期异常（如 netstat 自身异常）只 warn，保证退出码恒 0 不阻断钩子
try {
  if (ports.length === 0) {
    console.log('未指定端口参数，跳过清理')
  } else {
    for (const port of ports) cleanPort(port)
  }
} catch (err) {
  console.warn(`警告：端口清理过程出现未预期异常（忽略继续）：${err.message}`)
}
process.exit(0)
