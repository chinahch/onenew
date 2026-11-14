# Onenew 定制实施计划（2026-11-14）

用户已确认复用 cedar2025/Xboard，已 Fork 到 chinahch/onenew，要求运行时品牌/容器/服务/进程显示 onenew。保留原版权许可文本。上游基线 d826a3b32f73bde24acce2a3ab033c6538ce121d，官方管理端编译仓库固定 ef5f43da335092cbff8fdf0ad7ff9b4d92d7d0d7；当前镜像来源与基线匹配。

范围：
1. 官方管理端保持布局，移除知识库/优惠券/礼品卡/工单菜单；高级协议弹窗将 Outbounds/Routes 合并为落地链接解析，参考用户脚本13的服务分类、全局/分流和覆盖/保留行为。链接本地解析，不执行附件脚本，不外传凭据。按目标内核生成原有 custom_outbounds/custom_routes，并明确拒绝不能完整表达的协议。
2. 管理端节点表格 ID 后增加节点状态列，真实代理自测成功显示连通在线，失败显示故障，无结果/过期/不支持显示待检测。不能以心跳假充连通。
3. 用户端保留现有 Luma Vue 界面/布局，品牌改 onenew；认证/用户/订阅/续期/邀请/用量改接 XB 数据，不维护第二套账号和账本。
4. 真检测复用现有 caddy luma-v2/v3 的 /api/v3/server/probes 协议，在归属节点机执行。后台按5分钟节奏返回租约任务，真实有效账号和节点入站经路由/落地访问固定测试目标；Redis有界任务、租约验证、结果时效、权限隔离，不向用户返回凭据。保留旧节点兼容，不宣称原版 xbnode 自带该定制测试能力。
5. 自有源码定制层可重复构建官方管理端补丁，校验固定上游字节，不直接重写面板。用户端源码独立构建，PHP provider注册兼容接口及检测后端；Docker基于已验证官方镜像摘要叠加定制，避免上游Dockerfile重新clone上游丢失定制。

任务边界和协作契约：
- admin/：前端生成器、组件、编译产物补丁、菜单和状态列。节点状态字段 proxy_health={state:succeeded|failed|queued|running|pending|unsupported|expired,finished_at,created_at,error_code,source}，过期阈值10分钟。
- health/：PHP检测任务、lease/result端点、模型NodeHealthAttribute trait；用户节点健康接口 GET /api/onenew/user/nodes 返回 Luma NodeHealth 所需安全字段。注册独立 routes.php 由根provider载入。复用XB Machine鉴权和可用用户选择。
- user-portal/：Luma用户UI和XB API适配，不引入旧Luma Go后端；需要兼容后端时在 user-portal/backend/App/Onenew/ 下增加PHP类和 routes.php，由根provider载入。不要修改health同名接口。普通用户不显示Luma管理员功能，管理员入口跳XB自己的安全路径。
- 根任务：上游源码分支、品牌和运行时命令、Docker/Compose/恢复说明、providers.php注册、模型trait注入、构建验证、公开PR和CI。保留旧Luma未完成即时下发分支，不混入本次发布。

验证：解析/合并坏输入和不兼容协议回归；固定管理端补丁前后语法验证；真实本地浏览器视觉检查；后端鉴权/lease/过期和不暴露凭据测试；完整容器CI覆盖初始化、登录、userportal、管理页、健康任务、备份恢复。生产切换仍需用户服务验证。
