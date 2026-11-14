# onenew

基于 [cedar2025/Xboard](https://github.com/cedar2025/Xboard) 的定制项目。保留原项目 MIT 许可证及版权声明；上游说明见 [README.upstream.md](README.upstream.md)。

- 管理端保留原版布局，合并落地链接解析，增加真实代理检测状态列，移除知识库、优惠券、礼品卡和工单菜单入口。
- 用户端沿用原 Luma 的 Vue 界面，统一使用本项目账号、套餐、订单、订阅和用量数据。
- 运行中的容器、服务和进程标签使用 onenew。数据库原表名、内部兼容协议及第三方版权信息保留。
- 管理端定制由独立源码和固定版本校验生成；Docker 使用固定上游镜像摘要叠加定制，不在构建时重新克隆移动分支。
- 用户管理的每日流量记录可点击，分别查看真实节点用量和已上报的域名元数据；不会补造历史明细。域名采集默认关闭，在详情页启用，需搭配本项目新增的节点采集扩展。
- 插件管理预装“订阅防共享”，可配置和启停；默认停用，启用后默认仅告警，可另选重置订阅或封禁。

完整部署、备份和换机步骤见 [部署说明](customizations/onenew/DEPLOY.md)。功能与验证边界见 [实施计划](customizations/onenew/PLAN.md)、[管理端说明](customizations/onenew/admin/README.md)、[检测后端说明](customizations/onenew/health/README.md)。

原 Luma 服务及数据库不会由本项目自动覆盖。先在独立目录/域名或 SSH 隧道验证，再安排切换；不应直接让两套面板操作同一个数据库。
