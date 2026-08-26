# DEPLOY.md —— 生产环境部署操作指南

> 适用环境：**Ubuntu 22.04/24.04 LTS + Docker（Compose v2）+ 自建 nginx 反向代理 + HTTPS（Let's Encrypt）**
> 应用运行时：**PHP-FPM 8.3**（Laravel 13 的标准生产形态，对应 Java 生态中 Tomcat 的角色；本系统不需要 Swoole/Octane 等替代运行时）
> 文档约定：`erp.example.com` 为业务域名占位，全文全局替换为你的真实域名；`/opt/warehouse` 为部署目录占位
> 配套代码基线：本指南对应 dev 分支（含 R4-3 Sanctum 会话认证、P2 修复波），部署前请先合入最新代码

---

## 一、架构总览

```
客户端浏览器
    │  https://erp.example.com
    ▼
┌────────────────────────────── nginx 容器（:80/:443）──────────────────────────────┐
│  静态资源：/usr/share/nginx/html（前端构建产物 dist）                              │
│  /.well-known/acme-challenge/ → 证书签发 webroot（certbot 容器写入）               │
│  /api/v1、/sanctum/* → fastcgi 反代 app 容器 :9000（PHP-FPM = 应用服务器）          │
└──────────────┬────────────────────────────────────────────────────────────────────┘
               │ fastcgi（同一 compose 网络内）
┌──────────────▼───────────────┐    ┌───────────────────────────────┐
│ app 容器（php:8.3-fpm）       │    │ mysql 容器（mysql:8.4）          │
│ Laravel 应用 + vendor         │───▶│ 数据卷 mysql-data 持久化         │
│ storage 卷持久化日志           │    │ 仅内网，不暴露宿主端口            │
└──────────────────────────────┘    └───────────────────────────────┘
┌──────────────────────────────┐
│ certbot 容器（按需执行）       │  证书卷 certbot-certs / certbot-www 与 nginx 共享
│ 每晚 3 点宿主机 systemd timer  │  （见 §六 证书自动轮换）
└──────────────────────────────┘
```

**会话与认证链路（R4-3 会话模式，部署前必读）**：

- 前端登录态由**会话 cookie** 决定（`laravel_session` + `XSRF-TOKEN`），不落 localStorage token；登录前先 `GET /sanctum/csrf-cookie` 握手，写请求携带 `X-XSRF-TOKEN` 头。
- `SANCTUM_STATEFUL_DOMAINS` 是会话鉴权的**开关**：请求的 Origin/Referer 命中该列表才走 cookie 会话链路。**生产必须覆盖为前端域名（不含协议、不含端口）**，否则登录后所有业务请求鉴权失败（P2-5 登记项，见 §4.5）。
- token 兼容通道（第三方 API 客户端用）默认永不过期，生产必须设置 `SANCTUM_EXPIRATION` 限定有效期（见 §4.5）。
- HTTPS 下必须 `SESSION_SECURE_COOKIE=true`，否则会话 cookie 走明文传输被截获。

---

## 二、服务器准备

### 2.1 最低配置与前置条件

| 项 | 要求 |
| --- | --- |
| 系统 | Ubuntu 22.04 LTS 或 24.04 LTS（x86_64） |
| 资源 | 2 核 4G 起（含 MySQL）；磁盘 40G 起（数据卷 + 备份） |
| 域名 | `erp.example.com` 的 A 记录已指向本机公网 IP（DNS 生效后再签发证书） |
| 端口 | 公网开放 80/443（Let's Encrypt 校验必须能访问 80）；3306 **不对外** |

### 2.2 安装 Docker Engine 与 Compose 插件

```bash
# 官方源安装 docker-ce 全家桶（含 compose 插件）
sudo apt-get update
sudo apt-get install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
# 验证
sudo docker version --format '{{.Server.Version}}'
sudo docker compose version
```

### 2.3 时区与防火墙

```bash
# 时区统一 Asia/Shanghai（与容器内 TZ 对齐）
sudo timedatectl set-timezone Asia/Shanghai

# 防火墙：仅开放 SSH/80/443（按需换成你的 SSH 端口）
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status
```

---

## 三、部署目录规划

```bash
sudo mkdir -p /opt/warehouse/{nginx/conf.d,server,web,certbot,backups}
sudo chown -R "$USER" /opt/warehouse
```

| 路径 | 内容 | 来源 |
| --- | --- | --- |
| `/opt/warehouse/docker-compose.prod.yml` | 生产编排（nginx/app/mysql/certbot） | §4.1 |
| `/opt/warehouse/nginx/conf.d/app.conf` | nginx 站点配置（HTTPS + 反代） | §4.4 |
| `/opt/warehouse/server/` | 后端源码（含 Dockerfile、生产 .env） | §4.2 / §4.5 |
| `/opt/warehouse/web/` | 前端源码（含 Dockerfile） | §4.3 |
| `/opt/warehouse/certbot/check-cert.sh` | 证书检查与轮换脚本 | §4.6 |
| `/opt/warehouse/backups/` | 每日数据库备份（§7.2） | 运维生成 |

---

## 四、配置文件（复制即用）

> 先完成 §二/§三，再把本节的每个文件按说明放到对应路径。全部文件 UTF-8 无 BOM、LF 行尾（服务器上 `sed -i 's/\r$//' 文件` 可去除 Windows 换行残留）。

### 4.1 `docker-compose.prod.yml`（/opt/warehouse/docker-compose.prod.yml）

```yaml
# 生产编排：nginx（静态+反代）→ app（php-fpm 8.3）→ mysql 8.4；certbot 按需执行
# 说明：compose 默认网络内服务名互解析（app=php-fpm 地址，mysql=数据库地址），
# 均不暴露宿主端口（mysql 仅内网，nginx 独占 80/443）
# 固定项目名：保证卷名为 warehouse_certbot-certs 等（§5.4 占位证书步骤依赖）
name: warehouse

services:
  nginx:
    build:
      context: ./web
      dockerfile: Dockerfile
    container_name: warehouse-nginx
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./nginx/conf.d:/etc/nginx/conf.d:ro
      - certbot-www:/var/www/certbot:ro
      - certbot-certs:/etc/letsencrypt:ro
    depends_on:
      - app

  app:
    build:
      context: ./server
      dockerfile: Dockerfile
    container_name: warehouse-app
    restart: unless-stopped
    # 生产环境变量全部来自 server/.env（不落镜像、不入 git）
    env_file: ./server/.env
    volumes:
      - app-storage:/var/www/html/storage
    depends_on:
      mysql:
        condition: service_healthy

  mysql:
    image: mysql:8.4
    container_name: warehouse-mysql
    restart: unless-stopped
    # MYSQL_ROOT_PASSWORD / MYSQL_DATABASE / MYSQL_USER / MYSQL_PASSWORD 取自 server/.env
    env_file: ./server/.env
    volumes:
      - mysql-data:/var/lib/mysql
    command: --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci
    healthcheck:
      # 就绪探针：app 容器等待 mysql 健康后才启动（防迁移/请求打到未就绪库）
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-uroot", "-p$$MYSQL_ROOT_PASSWORD"]
      interval: 10s
      timeout: 5s
      retries: 12

  # 证书签发/续期：不常驻，仅 docker compose run --rm certbot ... 按需执行（§5.6 / §6）
  certbot:
    image: certbot/certbot:latest
    container_name: warehouse-certbot
    volumes:
      - certbot-www:/var/www/certbot
      - certbot-certs:/etc/letsencrypt

volumes:
  mysql-data:
  app-storage:
  certbot-www:
  certbot-certs:
```

### 4.2 `server/Dockerfile`（/opt/warehouse/server/Dockerfile）

```dockerfile
# 后端生产镜像（PHP-FPM 8.3 运行时）：多阶段——composer 依赖构建 → 运行镜像
# 运行扩展：pdo_mysql（数据库）、bcmath（金额分单位运算铁律）、opcache（生产加速）
FROM composer:2 AS vendor
WORKDIR /app
COPY . .
# 仅装生产依赖并优化 autoload（classmap 基于真实源码）
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

FROM php:8.3-fpm
RUN docker-php-ext-install pdo_mysql bcmath opcache
# 生产 opcache：镜像内代码不可变，禁用文件时间戳校验省 stat 开销
COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini
WORKDIR /var/www/html
COPY --from=vendor /app ./
# storage（日志/上传）与 bootstrap/cache（config/route 缓存）需可写
RUN chown -R www-data:www-data storage bootstrap/cache
EXPOSE 9000
# 启动：先建生产缓存（读取注入的环境变量），再起 php-fpm
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && php artisan view:cache && php-fpm"]
```

`server/opcache.ini`（与 Dockerfile 同目录）：

```ini
opcache.enable=1
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0
opcache.enable_cli=0
```

> 说明：`config:cache` 在容器每次启动时重建，读取的是 compose `env_file` 注入的环境变量，因此生产密钥等敏感项只存在于宿主机 `server/.env`，不进入镜像层。

### 4.3 `web/Dockerfile`（/opt/warehouse/web/Dockerfile）

```dockerfile
# 前端生产镜像：多阶段——Node 构建 dist → nginx 静态服务
FROM node:24-alpine AS build
WORKDIR /app
# VITE_APP_NAME：构建期注入前端应用名（对应 vite 读取的 import.meta.env.VITE_APP_NAME）
ENV VITE_APP_NAME="进销存生产管理系统"
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# 构建含 vue-tsc 类型检查（与本地门禁同口径），失败即构建失败
RUN npm run build

FROM nginx:1.27-alpine
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 80
```

### 4.4 `nginx/conf.d/app.conf`（/opt/warehouse/nginx/conf.d/app.conf）

```nginx
# 生产站点：HTTPS 强制跳转 + SPA 静态资源 + /api /sanctum 反代 php-fpm
# 首次引导：先用占位自签证书启动（§5.6 步骤 1），certbot 签发真实证书后 reload 生效
# 证书轮换后由脚本 docker exec nginx -s reload 自动重载

# HTTP：acme-challenge 目录供 Let's Encrypt webroot 校验，其余 301 跳 HTTPS
server {
    listen 80;
    server_name erp.example.com;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

# HTTPS 站点
server {
    listen 443 ssl;
    http2 on;
    server_name erp.example.com;

    # 证书来自 certbot 容器（共享卷 certbot-certs）；首次为占位自签，签发后自动轮换
    ssl_certificate     /etc/letsencrypt/live/erp.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/erp.example.com/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384;
    ssl_session_cache shared:SSL:10m;

    # 安全响应头
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    # HSTS：全站 HTTPS 稳定运行一周后建议打开（先确认无 http 访问需求，防止被浏览器强制 https）
    # add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # 前端静态资源（SPA history 路由回退 index.html）
    root /usr/share/nginx/html;
    index index.html;
    location / {
        try_files $uri $uri/ /index.html;
    }
    # vite 产物文件名含内容哈希，可长缓存
    location /assets/ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # API 与会话握手：反代到 app 容器 php-fpm（Laravel 应用服务器）
    location ~ ^/(api|sanctum)/ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/html/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        # 透传协议与客户端 IP（Laravel 13 默认信任本机反代，据此生成 https URL）
        fastcgi_param HTTP_X-Forwarded-Proto $scheme;
        fastcgi_param HTTP_X-Forwarded-For $remote_addr;
        fastcgi_pass app:9000;
    }
}
```

> 代理信任说明：Laravel 11+ 默认信任所有代理（trusted proxies = `*`），本部署的反代是自建同机 nginx，符合默认信任前提；若未来引入外部代理/负载均衡，需在 `server/config/trustedproxy.php`（或 bootstrap 配置）显式收紧为代理 IP 段。

### 4.5 `server/.env` 生产模板（/opt/warehouse/server/.env）

> 复制自 `server/.env.example` 后按下表逐项修改。**每个生产必改项都已标注**，改完删除本表。

```bash
APP_NAME=进销存生产管理系统
APP_ENV=production                 # 必改：生产环境
APP_DEBUG=false                    # 必改：false，否则错误堆栈/敏感信息暴露给用户
APP_KEY=                           # 必改：部署时 php artisan key:generate 生成后填入
APP_URL=https://erp.example.com    # 必改：真实业务域名

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

# 日志：生产 warning 以上（减少磁盘占用与敏感信息落盘）
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning

# 数据库（DB_HOST=mysql 为 compose 网络内服务名）
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=php_design
DB_USERNAME=php
DB_PASSWORD=改强口令              # 必改：业务库账号口令

# 以下 MYSQL_* 仅 mysql 容器读取（env_file 注入），与 Laravel 无关但必须与上一致
MYSQL_ROOT_PASSWORD=改强口令      # 必改：root 口令（首次启动生效，改后需重建数据卷）
MYSQL_DATABASE=php_design
MYSQL_USER=php
MYSQL_PASSWORD=改强口令

# 会话：database 驱动（sessions 表，多实例共享、重启不丢）
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true        # 必改：HTTPS 下会话 cookie 仅走 TLS
SESSION_DOMAIN=null               # 同域部署保持 null；前后端分域时才设父域

# Sanctum 会话鉴权（R4-3）——生产必改两项：
# 1) SANCTUM_STATEFUL_DOMAINS = 前端域名（不含协议/端口），漏配则会话鉴权完全不生效（P2-5）
# 2) SANCTUM_EXPIRATION = token 兼容通道有效期（分钟），生产必须设置（默认永不过期是隐患）
SANCTUM_STATEFUL_DOMAINS=erp.example.com
SANCTUM_EXPIRATION=10080          # 10080 分钟 = 7 天；第三方客户端 token 到期自动失效

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log

# 内置管理员 admin 口令（RbacSeeder 建号/轮换读取）：生产必须强口令，弱口令即系统后门
ADMIN_PASSWORD=改强口令           # 必改
```

> 部署时生成 APP_KEY：
> ```bash
> cd /opt/warehouse && docker compose -f docker-compose.prod.yml run --rm --no-deps app php artisan key:generate --show
> # 把输出填回 server/.env 的 APP_KEY= 行
> ```

### 4.6 `certbot/check-cert.sh`（/opt/warehouse/certbot/check-cert.sh）

```bash
#!/usr/bin/env bash
# 每晚 3 点由 systemd timer 触发：检查 Let's Encrypt 证书有效期，
# 剩余 ≤3 天强制轮换（certbot renew --force-renewal）并重载 nginx；
# 剩余 4~30 天执行标准续期检查（certbot 按自身规则尝试，提前续期兜底）。
set -euo pipefail

DOMAIN="erp.example.com"          # 与 nginx 配置的域名一致
COMPOSE_FILE="/opt/warehouse/docker-compose.prod.yml"
NGINX_CONTAINER="warehouse-nginx"
CERT_PATH="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
LOG_FILE="/var/log/warehouse/cert-check.log"

mkdir -p "$(dirname "$LOG_FILE")"
log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG_FILE"; }

# 证书缺失：首次签发未完成，直接告警退出（不阻塞其余检查）
if [ ! -f "$CERT_PATH" ]; then
  log "ERROR 证书文件缺失: ${CERT_PATH}（请先完成首次签发，见 DEPLOY.md §5.6）"
  exit 1
fi

# 剩余天数 = 证书过期时刻 - 当前时刻（向下取整到天）
expiry_epoch=$(date -d "$(openssl x509 -enddate -noout -in "$CERT_PATH" | cut -d= -f2-)" +%s)
now_epoch=$(date +%s)
days_left=$(( (expiry_epoch - now_epoch) / 86400 ))
log "检查证书: ${DOMAIN} 剩余 ${days_left} 天"

if [ "$days_left" -le 3 ]; then
    log "剩余 ≤3 天，触发强制轮换（certbot renew --force-renewal）"
    if docker compose -f "$COMPOSE_FILE" run --rm certbot renew --force-renewal; then
        docker exec "$NGINX_CONTAINER" nginx -s reload
        log "证书轮换成功，nginx 已重载"
    else
        log "ERROR 证书轮换失败（certbot 非零退出）——请检查 DNS/80 端口可达性/certbot 日志"
        exit 1
    fi
elif [ "$days_left" -le 30 ]; then
    log "剩余 4~30 天，执行标准续期检查（certbot renew 按自身规则尝试）"
    if docker compose -f "$COMPOSE_FILE" run --rm certbot renew; then
        docker exec "$NGINX_CONTAINER" nginx -s reload
        log "标准续期检查完成，nginx 已重载"
    else
        log "WARN 标准续期未触发或重载失败（剩余天数仍充足，次日再查）"
    fi
fi
log "本次检查结束"
```

```bash
chmod +x /opt/warehouse/certbot/check-cert.sh
```

### 4.7 systemd 定时任务（每晚 3 点）

`/etc/systemd/system/cert-check.service`：

```ini
[Unit]
Description=检查 Let's Encrypt 证书有效期并在 3 天内到期时轮换（warehouse）
After=docker.service
Requires=docker.service

[Service]
Type=oneshot
ExecStart=/opt/warehouse/certbot/check-cert.sh
```

`/etc/systemd/system/cert-check.timer`：

```ini
[Unit]
Description=每晚 3 点执行证书检查与轮换（warehouse）

[Timer]
OnCalendar=*-*-* 03:00:00
# 错过执行时间（如关机）下次开机补跑
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now cert-check.timer
systemctl list-timers cert-check.timer   # 确认下次触发时间为次日 03:00
```

---

## 五、首次部署步骤

> 前置：§二（服务器准备）、§三（目录规划）、§四（配置文件全部就位）完成。

### 5.1 拉取代码

```bash
cd /opt/warehouse
git clone <仓库地址> server   # server 工程（server/ 内容放 server/ 目录）
git clone <仓库地址> web      # web 工程（web/ 内容放 web/ 目录）
# 若仓库内 server/web 为子目录，也可直接拷贝：
#   cp -r server/* server/ 与 cp -r web/* web/ 后按需调整
# 检出目标分支并固定版本（部署前合入最新 dev 或发布 tag）
cd server && git checkout dev && cd ..
cd web && git checkout dev && cd ..
```

### 5.2 准备生产 .env 与 APP_KEY

```bash
cd /opt/warehouse
cp server/.env.example server/.env
# 按 §4.5 模板逐项修改 server/.env（APP_ENV/APP_DEBUG/SANCTUM_*/SESSION_SECURE_COOKIE/口令等）
# 生成 APP_KEY 并填回
docker compose -f docker-compose.prod.yml run --rm --no-deps app php artisan key:generate --show
```

### 5.3 启动 MySQL 并等待就绪

```bash
cd /opt/warehouse
docker compose -f docker-compose.prod.yml up -d mysql
docker compose -f docker-compose.prod.yml ps   # mysql 状态 healthy 后继续
```

### 5.4 首次证书签发（先占位后真实，避免 443 起不来）

> nginx 的 443 server 块引用了 `/etc/letsencrypt/live/...`（certbot-certs 卷），
> 真实证书签发前该路径必须存在，否则 nginx 启动失败。故先生成 1 天有效期的占位自签证书放进卷里，
> 让 nginx 能启动、webroot 校验可达；签发真实证书后 reload 切换（步骤 5.6）。

```bash
cd /opt/warehouse
# 1) 生成占位自签证书（1 天有效，仅供 nginx 启动占位）
mkdir -p /tmp/dummy-certs/live/erp.example.com
openssl req -x509 -nodes -newkey rsa:2048 -days 1 \
  -keyout /tmp/dummy-certs/live/erp.example.com/privkey.pem \
  -out /tmp/dummy-certs/live/erp.example.com/fullchain.pem \
  -subj "/CN=erp.example.com"

# 2) 拷入 certbot-certs 卷（compose 项目名 warehouse → 卷名 warehouse_certbot-certs）
docker run --rm \
  -v warehouse_certbot-certs:/etc/letsencrypt \
  -v /tmp/dummy-certs:/dummy \
  alpine sh -c 'mkdir -p /etc/letsencrypt/live/erp.example.com && cp -r /dummy/live/erp.example.com/* /etc/letsencrypt/live/erp.example.com/'
rm -rf /tmp/dummy-certs

# 3) 启动全部容器（app 等待 mysql healthy；nginx 此时用占位证书跑 443）
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml ps   # 三个容器 running/healthy

# 4) 确认 webroot 校验路径可达（Let's Encrypt 将经此验证域名归属）
curl -I http://erp.example.com/.well-known/acme-challenge/test   # 404 属正常（路由存在即说明可达）
```

### 5.5 数据初始化

> **历史数据决策（P2-3 裁决）**：旧库仅测试数据，**不做数据迁移**；上线用全新库，若从开发库带数据则先清空重建（步骤 2）。

```bash
cd /opt/warehouse
# 1) 全新库：直接迁移 + 种子（RbacSeeder 读取 ADMIN_PASSWORD 创建 admin）
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force --seed

# 2) 若从开发库带数据上线（旧数据仅测试数据，直接清空重建）：
#    口令经容器内环境变量读取，避免 .env 特殊字符被 shell 误解析
docker compose -f docker-compose.prod.yml exec mysql \
  sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS php_design; CREATE DATABASE php_design CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"'
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force --seed

# 3) 验证种子：admin 账号可登录（口令 = server/.env 的 ADMIN_PASSWORD）
```

### 5.6 签发真实证书并启用 HTTPS

```bash
cd /opt/warehouse
# 1) 经 webroot 模式签发（nginx 的 /.well-known/acme-challenge/ 已指向 certbot-www 卷）
docker compose -f docker-compose.prod.yml run --rm certbot certonly \
  --webroot -w /var/www/certbot \
  -d erp.example.com \
  --email 你的邮箱@example.com \
  --agree-tos --no-eff-email

# 2) 验证证书已写入共享卷
docker compose -f docker-compose.prod.yml run --rm --no-deps certbot \
  sh -c 'ls -l /etc/letsencrypt/live/erp.example.com/'

# 3) 重载 nginx 让真实证书生效（从占位切换）
docker exec warehouse-nginx nginx -s reload

# 4) 验证
curl -I https://erp.example.com                 # 200 且 TLS 证书为 Let's Encrypt
curl -s https://erp.example.com/api/v1/auth/me  # 未认证返回 401（业务信封正常）
openssl s_client -connect erp.example.com:443 -servername erp.example.com </dev/null 2>/dev/null | openssl x509 -noout -enddate
```

### 5.7 部署验证清单

```bash
# ① 页面与静态资源
curl -sI https://erp.example.com | head -1                     # 200
# ② 会话握手端点（R4-3 前置）
curl -sI https://erp.example.com/sanctum/csrf-cookie | grep -i set-cookie   # 含 laravel_session / XSRF-TOKEN
# ③ 未认证访问业务接口
curl -s https://erp.example.com/api/v1/auth/me                 # {"code":401,...}
# ④ 浏览器登录全链路：admin 登录 → 建单 → 审核 → 查询（人工冒烟，剧本见 docs/flow/业务流程说明.md）
# ⑤ 证书有效期
openssl s_client -connect erp.example.com:443 </dev/null 2>/dev/null | openssl x509 -noout -enddate
# ⑥ 定时任务就绪
systemctl list-timers cert-check.timer
```

---

## 六、证书自动轮换机制（每晚 3 点）

**机制说明**（对应 §4.6/§4.7）：

1. **每晚 03:00** systemd timer 触发 `check-cert.sh`。
2. 脚本用 `openssl x509 -enddate` 解析当前证书过期时刻，计算剩余天数。
3. 剩余 **≤3 天**：`certbot renew --force-renewal` **强制轮换** → `docker exec warehouse-nginx nginx -s reload`（nginx 重新读取共享卷中的新证书）→ 成功/失败均写日志 `/var/log/warehouse/cert-check.log`。
4. 剩余 **4~30 天**：执行标准 `certbot renew`（Let's Encrypt 默认提前 30 天续期，作为常规兜底，通常在此分支已完成续期）。
5. 剩余 **>30 天**：无事，仅记一行日志。

**为什么这样设计**：Let's Encrypt 证书有效期 90 天，`certbot renew` 默认在剩余 <30 天时尝试续期；你要求的「3 天内即将失效则轮换」作为**最终防线**（前 30 天分支失败时的兜底），确保证书永不过期。

**手动操作**：

```bash
# 手动跑一次检查/轮换
sudo systemctl start cert-check.service
tail -20 /var/log/warehouse/cert-check.log

# 手动强制续期（不等待 3 天阈值）
cd /opt/warehouse
docker compose -f docker-compose.prod.yml run --rm certbot renew --force-renewal
docker exec warehouse-nginx nginx -s reload

# 查看证书当前剩余天数
openssl x509 -enddate -noout -in <(docker compose -f docker-compose.prod.yml run --rm --no-deps certbot cat /etc/letsencrypt/live/erp.example.com/fullchain.pem)
```

**告警**：轮换失败会写 `ERROR` 日志并退出码 1。如需邮件/钉钉通知，在 `check-cert.sh` 的 ERROR 分支追加 webhook 调用即可（默认仅日志）。

---

## 七、运维手册

### 7.1 日常检查

```bash
docker compose -f /opt/warehouse/docker-compose.prod.yml ps          # 三容器状态
docker logs --tail 100 warehouse-app                                  # 应用日志
tail -f /opt/warehouse/server/storage/logs/laravel.log                # Laravel 日志（storage 卷持久化）
tail -f /var/log/warehouse/cert-check.log                             # 证书轮换日志
systemctl list-timers cert-check.timer                                # 定时任务状态
```

### 7.2 数据库备份（强烈建议，部署后立即配置）

`/opt/warehouse/certbot/backup-db.sh`：

```bash
#!/usr/bin/env bash
# 每日数据库备份：mysqldump 全库 → /opt/warehouse/backups/，保留 14 天
set -euo pipefail
ENV_FILE="/opt/warehouse/server/.env"
BACKUP_DIR="/opt/warehouse/backups"
KEEP_DAYS=14

DB_HOST=$(grep '^DB_HOST=' "$ENV_FILE" | cut -d= -f2)
DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2)
DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2)
DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2)
STAMP=$(date '+%Y%m%d-%H%M%S')

mkdir -p "$BACKUP_DIR"
docker compose -f /opt/warehouse/docker-compose.prod.yml exec -T mysql \
  mysqldump -u"$DB_USER" -p"$DB_PASS" --single-transaction --routines "$DB_NAME" \
  | gzip > "$BACKUP_DIR/${DB_NAME}-${STAMP}.sql.gz"
find "$BACKUP_DIR" -name '*.sql.gz' -mtime +"$KEEP_DAYS" -delete
echo "备份完成: ${BACKUP_DIR}/${DB_NAME}-${STAMP}.sql.gz"
```

```bash
chmod +x /opt/warehouse/certbot/backup-db.sh
# 复用同一 timer 机制：加一个 03:20 的 timer（或直接 cron）
(crontab -l 2>/dev/null; echo "20 3 * * * /opt/warehouse/certbot/backup-db.sh") | crontab -
```

### 7.3 发布更新

```bash
cd /opt/warehouse
# 1) 更新代码
cd server && git pull && cd ..
cd web && git pull && cd ..
# 2) 重建镜像并滚动重启（构建失败不影响旧容器）
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d
# 3) 如有数据库迁移
docker compose -f docker-compose.prod.yml run --rm app php artisan migrate --force
# 4) 验证（§5.7 清单抽查 + 人工冒烟）
```

### 7.4 回滚

```bash
# 镜像按 tag 管理后回滚：把 compose 的 image 指回上一 tag 并 up -d；
# 未打 tag 时用 git 回退后重新 build：
cd /opt/warehouse/server && git checkout <上一提交> && cd ..
docker compose -f docker-compose.prod.yml build app && docker compose -f docker-compose.prod.yml up -d app
# 数据库已执行的迁移不做自动回滚（历史单据只增不改原则），有数据问题用备份恢复：
gunzip -c backups/php_design-<时间戳>.sql.gz | docker compose -f docker-compose.prod.yml exec -T mysql mysql -u"$DB_USER" -p"$DB_PASS" "$DB_NAME"
```

### 7.5 常见故障排查

| 症状 | 原因与处置 |
| --- | --- |
| 登录后任意写操作 419 跳登录 | ① `SANCTUM_STATEFUL_DOMAINS` 未覆盖为前端域名（P2-5，最常见）；② 会话过期属正常，重新登录 |
| 登录接口 401「未认证或登录已过期」 | 会话 cookie 未带上：检查 `SESSION_SECURE_COOKIE=true` 且浏览器仅 HTTPS 访问；检查 nginx `location ~ ^/(api\|sanctum)/` 是否命中了 /api/v1 |
| 502 Bad Gateway | app 容器未就绪：`docker compose ps` 看 mysql 是否 healthy、app 是否 running；`docker logs warehouse-app` 看 php-fpm 是否启动、config:cache 是否报错 |
| 页面能开但接口 404 | nginx fastcgi 配置未生效：确认 app.conf 已挂载（`docker exec warehouse-nginx nginx -t`）|
| 证书轮换脚本 ERROR | 80 端口公网不可达（Let's Encrypt 校验失败）、域名 DNS 变更、certbot 日志 `/etc/letsencrypt/log/letsencrypt.log` |
| 登录后页面反复刷新 | 前端 http.ts 401/419 跳转循环：检查浏览器是否同时开着 http 与 https 访问（cookie 不一致） |

---

## 八、安全基线清单（部署后逐项核对）

- [ ] `server/.env`：`APP_DEBUG=false`、`APP_ENV=production`、`APP_KEY` 为独立生成值、`ADMIN_PASSWORD` 为强口令、`DB_PASSWORD`/`MYSQL_ROOT_PASSWORD` 均非默认值
- [ ] `SANCTUM_STATEFUL_DOMAINS` = 生产前端域名；`SANCTUM_EXPIRATION` 已设（7 天）
- [ ] `SESSION_SECURE_COOKIE=true`；HTTPS 强制跳转已生效；`curl http://...` 返回 301
- [ ] 防火墙仅开放 80/443/SSH；3306 未映射宿主端口
- [ ] 证书轮换 timer 已启用（`systemctl list-timers`）；备份 cron 已配置并手工跑通一次
- [ ] 定时任务清单（§4.7 timer + §7.2 cron）与系统时区 Asia/Shanghai 一致
- [ ] 日志轮转：`/var/log/warehouse/` 与 Laravel storage 日志建议配置 logrotate（`/etc/logrotate.d/warehouse`）防止磁盘写满
- [ ] 生产验证：`composer audit`（依赖漏洞）在发布镜像构建时执行，有高危应升级后再上线

---

## 附：本指南与代码基线的对应关系

| 部署关注点 | 代码/配置依据 |
| --- | --- |
| 会话认证（R4-3） | `server/config/sanctum.php`、`server/app/Services/AuthService.php`（双通道：会话主 + token 兼容） |
| token 过期可配置 | `SANCTUM_EXPIRATION`（本次 P2 修复，Guard 请求时校验） |
| 419 跳登录 | `web/src/api/http.ts`（本次 P2 修复） |
| 状态/明细类型契约 | `web/src/api/production.ts`（OperationReportRecord 三字段 string） |
| 库位/详情竞态守卫 | 三处入库/退料视图 + PicksView（本次 P2 修复） |
| 历史数据口径 | P2-3 裁决：不迁移，部署时清库重建（§5.5） |
