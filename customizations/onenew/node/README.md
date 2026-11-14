# Onenew 原生节点

节点机不需要 Docker。服务/进程继续叫 `caddy`，命令为 `caddyctl`，配置保留 `/etc/caddy`；这是代理节点二进制，不是 Caddy Web Server。

服务器管理生成的命令固定使用本仓库 `node-v0.1.0` 标签下的安装器，并下载同标签 Release 的包。安装器核对 SHA256、架构、包成员和 `caddy onenew-v0.1.0` 版本后才生成配置和启动服务。请先在系统配置中填写 HTTPS 面板地址。root 可直接运行生成命令，无需安装 sudo。发行包发布成功前该标签入口不可用。

参考用户原有 [php-fpm-core](https://github.com/chinahch/php-fpm-core/tree/c8058a2f14060d8d2d12adaaef218ff558a1df98) 的机器模式、systemd/OpenRC 部署和历史二进制名称。固定节点上游 `0a29338e1f102a462363ce3527417029f89bab28`，固定自有 caddy 定制 `11f025992aa163204c5d4d2d8fb0934a0ab2e893`，包括双内核、双 IP、落地、证书、自测和 Onenew 域名元数据扩展。保留第三方版权及 Go 模块标识，不将内部包重命名等同于运行时品牌修改。

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

当前安装器发现已有 caddy 配置目录、同名 systemd/OpenRC 服务、PATH 中的 caddy 程序或 php-fpm/xboard-node 配置时会停止，保留现有服务和凭据。它不是重绑定或无损升级命令；不要先删除旧配置绕过检查。已有节点需另行备份配置、证书及二进制，核对面板地址/机器 ID/凭据和服务单元后安排迁移。旧包只有心跳和流量上报时，不能提供本次新增的域名元数据。

## 构建与发布

原生 CI 在全新固定源码目录测试两个内核及机器代理测试，再构建 amd64/arm64 包。主分支成功后自动发布预览 Release `node-v0.1.0`，已有发布不会被覆盖。每个新版本必须同时更新 build.py 的 VERSION、安装器 VERSION/RELEASE 和 PHP 命令标签。amd64 CI 会执行版本和配置生成检查；ARM64 当前仅交叉构建，未承诺 128 MiB 等低内存服务器可稳定运行。

安装器测试全部使用离线模拟下载，不操作宿主服务：

```bash
python3 customizations/onenew/node/test_installer.py
python3 customizations/onenew/node/test_build.py
```
