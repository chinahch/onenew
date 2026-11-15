# Onenew 原生节点

节点机不需要 Docker。服务/进程继续叫 `caddy`，命令为 `caddyctl`，配置保留 `/etc/caddy`；这是代理节点二进制，不是 Caddy Web Server。

服务器管理生成的命令固定使用本仓库 `node-v0.1.2` 标签下的安装器，并下载同标签 Release 的包。安装器核对 SHA256、架构、包成员和 `caddy onenew-v0.1.2` 版本后才生成配置和启动服务。请先在系统配置中填写 HTTPS 面板地址。root 可直接运行生成命令，无需安装 sudo。发行包发布成功前该标签入口不可用。

参考用户原有 [php-fpm-core](https://github.com/chinahch/php-fpm-core/tree/c8058a2f14060d8d2d12adaaef218ff558a1df98) 的机器模式、systemd/OpenRC 部署和历史二进制名称。固定节点上游 `0a29338e1f102a462363ce3527417029f89bab28`，固定自有 caddy 定制 `1d1565f03960760902379e4e7e25daa3c236684f`，包括双内核、双 IP、落地、证书、自测和 Onenew 域名元数据扩展。保留第三方版权及 Go 模块标识，不将内部包重命名等同于运行时品牌修改。

## 新服务器

安装 `curl`、`tar`、`sha256sum`、`install`（Debian 通常已有后三者），并使用 systemd 或带 supervise-daemon 的 OpenRC。复制服务器详情中的安装命令执行。机器令牌由命令传入并保存在权限受限的凭据文件中，不必手工编辑。

在命令末尾加 `--check-only` 可只下载、校验并核对版本，不写入配置或启动服务。使用公开可访问的 HTTPS 面板地址，不能使用 127.0.0.1 的反向代理监听地址。

```bash
systemctl status caddy --no-pager
/usr/local/bin/caddy -v
journalctl -u caddy -n 80 --no-pager
```

Alpine 使用 `rc-service caddy status`，日志在 `/var/log/caddy.log`、`/var/log/caddy.err`。

## 已有节点

当前安装器发现已有 caddy 配置目录、同名 systemd/OpenRC 服务、PATH 中的 caddy 程序或 php-fpm/xboard-node 配置时会停止，保留现有服务和凭据。对于使用本项目 v0.1.0 的 Debian/systemd 节点，复制新安装命令并在末尾加 `--upgrade`：升级器核对原生服务、面板 URL 和机器 ID，私有备份后替换二进制及对应凭据，保留配置、证书和服务单元；健康检查失败会恢复原二进制和凭据。不要删除旧配置绕过检查。网页 Caddy、自定义 systemd 覆盖或 OpenRC 升级会明确拒绝，需单独安排迁移。旧包只有心跳和流量上报时，不能提供本次新增的域名元数据。

## 构建与发布

原生 CI 在全新固定源码目录测试两个内核及机器代理测试，再构建 amd64/arm64 包。主分支成功后自动发布预览 Release `node-v0.1.2`，已有发布不会被覆盖。每个新版本必须同时更新 build.py 的 VERSION、安装器 VERSION/RELEASE 和 PHP 命令标签。amd64 CI 会执行版本和配置生成检查；ARM64 当前仅交叉构建，未承诺 128 MiB 等低内存服务器可稳定运行。

安装器测试全部使用离线模拟下载，不操作宿主服务：

```bash
python3 customizations/onenew/node/test_installer.py
python3 customizations/onenew/node/test_build.py
```

## v0.1.1

修复 Sing-box 出站热更新：落地或路由改变后关闭当前节点旧 TCP/UDP 会话，同机其他节点保持运行；WS 可用时立即推送，断线时走定时 REST 同步。传输切换为 XHTTP 时重新选择 Xray，启动失败不再缓存为已应用。运行内核每次随指标上报，落地编辑器自动识别并恢复已有配置。

新增落地草稿测试，在所属节点机经代理访问固定出口识别目标，返回出口 IP 和国家；不是面板服务器的 IP。H3 模板要求常规 TLS、UDP/QUIC 可达及客户端支持；不能把 REALITY 随意改成 H3。XHTTP 对象字段保持 `{}`，合法数组保留 `[]`。

## v0.1.2

新增面板服务器全局 IPv4/IPv6 出口优先设置及按逻辑节点覆盖。未设置的机器保留本地 YAML 策略；优先模式保留另一地址族作为候选。修改后按节点重新应用并关闭旧的 TCP/UDP 会话，不改系统网络。已有 Onenew systemd 节点需复制新安装命令并添加 `--upgrade`，版本确认应为 `caddy onenew-v0.1.2`。

## node-v0.1.5: Argo TLS offload compatibility
VLESS/VMess with subscription TLS and effective certificate mode `none` retain plaintext origins without a new switch. Configured certificate failures never downgrade to plaintext. Matching loopback self-probes use actual listener security; remote exit probes and REALITY retain their security. Upgrade an existing native Onenew node using `install.sh --upgrade --mode machine --panel ... --token ... --machine-id ...`; configuration and credentials are retained with binary backups for rollback.

## node-v0.1.5: 代理检测目标协议修复

面板和原生 Worker 的固定代理检测目标统一为 `http://cp.cloudflare.com/generate_204`；旧 v0.1.4 Worker 接收到该目标时会返回 `configuration_unavailable`，表现为节点实际可用但面板持续“待检测”。新包必须通过探测目标回归测试及两个平台架构的原生构建后发布。已存在的 `node-v0.1.4` Release 保持不可变。

注意：`install.sh --upgrade` 当前仅支持符合原生安装约束的 systemd 节点，**Alpine/OpenRC 节点会被安全拒绝自动升级**。需先在一台测试节点备份配置及二进制，核验新包 SHA256 后按 OpenRC 专用方案升级与回滚，不能直接对其运行系统d升级命令。节点升级后验证代理与自动检测两条链路。
