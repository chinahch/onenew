# onenew 部署与换机

## 服务器

Debian + Docker Compose V2；用户当前 8 GiB 内存可采用默认 balanced 配置。源码构建需要联网拉取 Node 依赖及固定上游镜像。公开源码不包含实际邮箱、密码、节点令牌或应用密钥。

## 首次部署（独立目录）

在服务器执行，每条命令完成后再继续。安装命令无需输入密码，原生安装器会生成管理员密码；请自己保存在密码管理器中，不要把完整安装输出发到聊天或提交仓库。

```bash
git clone https://github.com/chinahch/onenew.git /opt/onenew
cd /opt/onenew
cp compose.onenew.sample.yaml compose.yaml
umask 077
touch .env
mkdir -p data/database data/redis storage/logs storage/theme plugins
export ONENEW_REVISION="$(git rev-parse HEAD)"
docker compose build
```

把下方邮箱改成你的管理员邮箱，然后单独执行安装命令。仅首次安装使用，不要清空已安装实例的 `.env`。

```bash
docker compose run --rm -T -e ADMIN_ACCOUNT='you@example.com' onenew php artisan onenew:install --no-interaction
docker compose up -d --wait
docker compose ps
```

默认仅绑定 `127.0.0.1:7001`，避免抢占现有 Luma 的 8088 端口。在你自己的电脑另开终端执行 `ssh -L 7001:127.0.0.1:7001 root@服务器IP`，随后浏览器打开 `http://127.0.0.1:7001`。管理入口是安装器显示的安全路径，根路径为用户中心。

对外使用应配置已有 HTTPS 反向代理到 `127.0.0.1:7001`。如确实要临时公开 7001，可在 `.env` 中新增 `ONENEW_BIND_IP=0.0.0.0` 后重建容器，并配置防火墙；无需修改数据库或重新安装。不要在公网 HTTP 页面输入生产凭据。

## WebSocket 转发

面板端口已同时提供 HTTP 和 `/ws`。Nginx 现有 HTTPS server 的转发段应保留 WebSocket 升级和原始主机/协议：

```nginx
location / {
    proxy_pass http://127.0.0.1:7001;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 75s;
}
```

## 节点与检测

节点仍用原生二进制，不需要 Docker。现有 caddy 的 luma-v2/v3 包含节点机真实代理测试 worker，可以消费本项目的兼容任务。直接安装原版 xbnode 不代表已有该检测扩展；没有真实结果时显示待检测。测试使用满足节点权限组、配额、有效期条件的实际账号，从归属节点机经节点入站及当前路由/落地访问固定测试目标。心跳和真实代理检测分别处理。

先用新节点端口和独立测试机器验证绑定；不要直接覆盖正在承载旧 Luma 节点的配置/凭据。不同系统的 Machine ID、令牌及节点 ID 不能直接互换。`caddyctl` 更新凭据有身份校验，不能把换面板简化为复制旧令牌。实际切换需同时核对节点入站、证书、UUID、路由和订阅。

标准 Xray 落地字段无法承载的 TLS/REALITY/ENC 参数会拒绝导入；使用 caddy 扩展模式前须确认节点是兼容定制版本。健康检测的协议与本机 TLS/CDN 终结边界见 health/README.md。CF Tunnel、MTProxy 的旧 Luma 连接器接口不能视为原版 XB 原生兼容；本次不能自动迁移这些连接器及其计费队列。

## 更新

用户管理 → TA 的流量记录 → 点击日期，可切换“节点流量”和“访问域名”。节点明细从本次升级后归档，原总量没有节点归属的日期会标注缺失。域名页的开关控制新采集，默认保留七天；域名元数据需配套的新节点程序，旧程序不会因为面板升级自动开始采集。原生候选包构建和能力边界见 [节点扩展](node-audit/README.md)。

“订阅防共享”在插件管理中预装为停用状态。先配置 IP 数量和时间窗口，再启用；默认仅告警，需要自动重置或封禁时在插件配置中选择。更新不会覆盖已安装插件目录或原有配置。保留原始插件作者声明，详情见 [插件说明](plugins/README.md)。

先备份，再固定仓库新版本重建。运行中容器不执行上游 git reset/pull，以免覆盖定制。

```bash
cd /opt/onenew
sudo sh customizations/onenew/backup.sh
git pull --ff-only
export ONENEW_REVISION="$(git rev-parse HEAD)"
docker compose up -d --build --wait
```

## 备份与恢复

以能读取容器所有者文件的宿主机账号执行 `sudo sh customizations/onenew/backup.sh`（root 可省略 sudo）。脚本短暂停止 onenew，并在完成/失败后恢复先前运行状态；快照包含 SQLite 及其 WAL、Redis 持久数据、`.env` 应用密钥、插件和主题。备份默认放在 `/opt/onenew-backups`，必须在部署目录外。不能只复制 SQLite 主文件而遗漏活跃 WAL，也不能只备份数据库而丢失 `.env` 密钥。

换机时把私有备份安全传到新机，在一个空目录解压，核对 Compose/源码版本后重新构建并启动。先用 SSH 隧道检查用户登录、套餐、用量、订阅、续期请求、节点归属和真实代理检测，再切换域名。恢复旧版本时，应用和数据库/Redis/密钥快照一起回退，不承诺任意数据库逆向迁移。归属节点机的独立证书和连接器队列仍需另行保管。

## Luma 数据迁移

两套数据库结构不同。本项目没有自动导入旧 Luma 数据的命令，不能把它的 PostgreSQL 备份直接恢复到这里的 SQLite。保留旧服务，先清点用户/UUID/套餐/用量/有效期/订阅令牌和节点，再做专用数据映射与验收。更换用户 UUID 会影响客户端，必须显式安排；现有订阅路径也不能未经验证直接复用。
