# OpenCode 执行后端

面板 AI 设置可选择 Chat Completions 或 OpenCode，两份连接配置分别保存。OpenCode 模式把聊天任务交给独立服务中的项目 Agent，真实读取文件、修改代码和执行命令；不会将回复 JSON 当成面板操作卡片。

## 支持的版本

- 默认：**OpenCode 2.3.2**，npm 包 `@opencode/cli`，使用 `/api/info`、`/api/session`、`/api/session/{id}/prompt` 和会话消息接口。
- 兼容：OpenCode 1.19.6，旧包 `opencode-ai`，在面板明确选择 1.x 协议。
- 这两条发布线不能用 `npm view opencode-ai version` 相互判断新旧。协议不兼容会报错，不会静默换接口或重复发送任务。

## 部署独立服务

在你要让助手处理项目的 Linux 服务器上安装 Node.js 22 或更新版本，然后：

```bash
npm install -g @opencode/cli@2.3.2
opencode --version
```

准备一个可读写的项目目录，例如 `/srv/opencode/onenew`。推荐使用独立源码检出；只有把生产配置、数据库、Docker socket 或系统权限明确交给这个服务，它才有对应能力。面板不会自动传送管理员登录 Token、节点 Token、数据库密码或 API Key，也不会自动赋予宿主机 root 权限。

在此项目的 `opencode.jsonc` 中按 OpenCode 2.x 格式配置模型服务；现有面板的模型 API Key 不会被转交。例子：

```json
{
  "$schema": "https://opencode.ai/config.json",
  "model": "company/coder",
  "providers": {
    "company": {
      "package": "@opencode/ai/providers/openai-compatible",
      "settings": {
        "baseURL": "https://你的模型服务/v1",
        "apiKey": "{env:AI_PROVIDER_KEY}"
      },
      "models": { "coder": { "name": "coder" } }
    }
  }
}
```

工具和命令权限由 OpenCode 服务的 `permissions` 设置决定，面板不会自动批准权限请求。需要允许读写和执行的项目，可在核对目录及运行账号后按实际需要添加规则，例如：

```json
"permissions": [
  { "action": "read", "resource": "*", "effect": "allow" },
  { "action": "edit", "resource": "*", "effect": "allow" },
  { "action": "shell", "resource": "*", "effect": "allow" }
]
```

将模型密钥和 `OPENCODE_SERVER_PASSWORD` 放入服务账号可读的私有 EnvironmentFile（权限 600），不要贴到聊天中。服务执行示例：

```ini
[Unit]
Description=Onenew OpenCode Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=onenew-ai
WorkingDirectory=/srv/opencode/onenew
EnvironmentFile=/etc/onenew-opencode.env
ExecStart=/usr/bin/env opencode serve --hostname 127.0.0.1 --port 4096
Restart=on-failure
RestartSec=5
UMask=0077

[Install]
WantedBy=multi-user.target
```

该账号、目录、配置文件和权限需先准备好；`opencode` 必须在服务的 PATH 中。以上服务在宿主机执行，不是面板容器里的节点 Caddy。

## HTTPS 入口与后台配置

用现有 OpenResty 将一个独立 HTTPS 域名反代到 `http://127.0.0.1:4096`。保留 Authorization 请求头，不要把这个带命令执行能力的服务配置成无认证入口。面板校验 HTTPS、公网域名、证书并固定 DNS 地址，不支持直接填 `http://127.0.0.1:4096`。

在“系统设置 → AI运维助手设置”中填写：

1. 执行后端：OpenCode；协议：OpenCode 2.x。
2. 服务地址：反代后的 HTTPS 根地址，不加 `/v1/chat/completions`。
3. 服务器项目目录：OpenCode 所在机器上的绝对 Linux 路径。
4. HTTP Basic 用户名：默认 `opencode`；密码：服务的 `OPENCODE_SERVER_PASSWORD`。
5. Agent、模型：可留空使用服务默认值；模型覆盖采用 `provider/model`。
6. 保存，再点击“测试已保存配置”。此测试仅验证服务可连接，不创建会话、不调用模型、不执行命令。

随后从悬浮聊天发送任务。OpenCode 模式按服务权限直接执行，无面板确认卡片；请先在独立项目中验证。若服务等待权限或提问，面板会明确提示，不能宣称任务已完成。网络超时或执行中断也不代表已经回滚：先检查对应服务会话，避免重复提出相同修改。

## 聊天记录与窗口

悬浮按钮和聊天窗口标题栏可拖动，页面原有功能仍可点击。位置与聊天记录按面板部署及管理员账号保存在当前浏览器；刷新后继续查询原任务，不会自动重新提交修改。清空会删除本机该账号的聊天记录，但不会撤销服务端已执行的操作。

提供 Markdown 和文本导出，仅导出对话；确认卡片及其中的初始密码不持久化、不导出。浏览器清理站点数据、无痕模式或更换设备不会保留这些本机记录。

## 验证

`tests/opencode-contract.py` 启动真实版本固定的 OpenCode 服务，使用一次性本地假模型返回工具调用，验证认证、会话、实际命令写文件和结束消息；不连接付费模型。PHP 测试覆盖配置加密、任务归属、重复提交、远程失败和权限等待。实际生产模型、服务器权限和项目数据仍需在你部署后验证。

参考：[OpenCode API](https://opencode.ai/docs/api)、[配置](https://opencode.ai/docs/config/)、[权限](https://opencode.ai/docs/permissions/)。
