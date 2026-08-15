#!/usr/bin/env bash
# ============================================================
# warehouse 一键启动脚本（开发环境：MySQL + 后端 :8000 + 前端 :5173 + 自动打开浏览器）
#
# 用法：
#   Git Bash 下执行  ./start-dev.sh
#   或 Windows 双击  start-dev.bat
#
# 前置条件：
#   1. Docker Desktop 已启动（提供 MySQL 8.4 开发库，见 docker-compose.yml）
#   2. PHP / Composer / Node 已加入 PATH
#   3. server/.env 已就绪（首次克隆：cp server/.env.example server/.env
#      && cd server && php artisan key:generate）
#
# 停止：Ctrl+C（自动清理后端/前端后台进程）
# ============================================================

set -u

# 脚本所在目录（项目根）
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

BACK_PID=""
FRONT_PID=""

# 退出清理：Ctrl+C 或脚本结束时杀掉后台服务进程
cleanup() {
    [ -n "$BACK_PID" ] && kill "$BACK_PID" 2>/dev/null
    [ -n "$FRONT_PID" ] && kill "$FRONT_PID" 2>/dev/null
    echo
    echo "服务已停止，再见！"
}
trap cleanup EXIT

echo "==> [1/5] 启动 MySQL（Docker 容器 php-design-mysql）..."
if ! docker compose up -d mysql; then
    echo "✗ Docker 不可用：请先启动 Docker Desktop，再重新执行本脚本"
    exit 1
fi

echo "==> [2/5] 等待 MySQL 就绪..."
# root 口令默认与 docker-compose.yml 一致，可用 MYSQL_ROOT_PASSWORD 环境变量覆盖（加固口令后无需改脚本）
MYSQL_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-root}"
MYSQL_READY=0
for i in $(seq 1 30); do
    if docker exec php-design-mysql mysqladmin ping -u root -p"$MYSQL_ROOT_PASSWORD" --silent >/dev/null 2>&1; then
        MYSQL_READY=1
        echo "    MySQL 就绪（等待 ${i}s）"
        break
    fi
    sleep 1
done
if [ "$MYSQL_READY" -ne 1 ]; then
    echo "✗ MySQL 30 秒内未就绪：请检查 Docker 容器状态（docker logs php-design-mysql）；"
    echo "  若已加固过 root 口令，请用 MYSQL_ROOT_PASSWORD 环境变量传入与 docker-compose.yml 一致的密码"
    exit 1
fi

echo "==> [3/5] 初始化数据库（迁移 + 种子数据，幂等可重复执行）..."
if ! (cd server && php artisan migrate --seed); then
    echo "✗ 数据库初始化失败，请检查 server/.env 数据库配置"
    exit 1
fi

# 端口占用预检：8000（后端）/ 5173（前端）任一被占用则退出，避免起服务失败后留下半启动状态
# 注意 netstat 输出中端口列在状态列之前，故端口号须出现在 LISTENING 之前；[^0-9] 防 :51733 之类子串误命中
if netstat -ano 2>/dev/null | grep -qE "(:8000|:5173)[^0-9].*(LISTENING|LISTEN)"; then
    echo "✗ 端口 8000 或 5173 已被占用（服务可能已在运行），请先停止后重试"
    exit 1
fi

echo "==> [4/5] 启动后端 :8000 与前端 :5173（首次启动前端需编译，约 10-30s）..."
(cd server && php artisan serve --host=127.0.0.1 --port=8000) &
BACK_PID=$!
(cd web && npm run dev) &
FRONT_PID=$!

echo "==> [5/5] 等待服务就绪并打开浏览器..."
# 就绪探测：超时未就绪（端口被占、npm 编译失败、后端启动报错）则报错退出，不打开浏览器、不打印成功横幅
BACK_READY=0
for i in $(seq 1 60); do
    if curl -sf http://127.0.0.1:8000 >/dev/null 2>&1; then
        BACK_READY=1
        break
    fi
    sleep 1
done
FRONT_READY=0
for i in $(seq 1 90); do
    if curl -sf http://localhost:5173 >/dev/null 2>&1; then
        FRONT_READY=1
        break
    fi
    sleep 1
done
if [ "$BACK_READY" -ne 1 ] || [ "$FRONT_READY" -ne 1 ]; then
    echo "✗ 服务未在预期时间内就绪，不打开浏览器："
    [ "$BACK_READY" -ne 1 ] && echo "  后端 :8000 60 秒内未就绪（查看上方 php artisan serve 输出，检查端口占用）"
    [ "$FRONT_READY" -ne 1 ] && echo "  前端 :5173 90 秒内未就绪（查看上方 npm run dev 输出，检查端口占用/编译错误）"
    exit 1
fi

# 打开默认浏览器（Windows 用 explorer.exe；macOS/Linux 用 open/xdg-open）
if command -v explorer.exe >/dev/null 2>&1; then
    explorer.exe "http://localhost:5173" >/dev/null 2>&1 &
elif command -v open >/dev/null 2>&1; then
    open http://localhost:5173 >/dev/null 2>&1 &
else
    xdg-open http://localhost:5173 >/dev/null 2>&1 &
fi

echo
echo "=============================================="
echo "  前端界面 : http://localhost:5173"
echo "  后端 API  : http://127.0.0.1:8000"
echo "  登录账号  : admin / admin123"
echo "  停止服务  : Ctrl+C"
echo "=============================================="
echo

# 前台等待后端/前端进程（Ctrl+C 触发 cleanup 清理）
wait "$BACK_PID" "$FRONT_PID"
