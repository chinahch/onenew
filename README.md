# onenew

基于 [cedar2025/Xboard](https://github.com/cedar2025/Xboard) 的定制项目。保留原项目 MIT 许可证及版权声明；上游说明见 [README.upstream.md](README.upstream.md)。

- 管理端保留原版布局，合并落地链接解析，增加真实代理检测状态列，移除知识库、优惠券、礼品卡和工单菜单入口。
- 用户端沿用原 Luma 的 Vue 界面，统一使用本项目账号、套餐、订单、订阅和用量数据。
- 运行中的容器、服务和进程标签使用 onenew。数据库原表名、内部兼容协议及第三方版权信息保留。
- 管理端定制由独立源码和固定版本校验生成；Docker 使用固定上游镜像摘要叠加定制，不在构建时重新克隆移动分支。

完整部署、备份和换机步骤见 [部署说明](customizations/onenew/DEPLOY.md)。功能与验证边界见 [实施计划](customizations/onenew/PLAN.md)、[管理端说明](customizations/onenew/admin/README.md)、[检测后端说明](customizations/onenew/health/README.md)。

原 Luma 服务及数据库不会由本项目自动覆盖。先在独立目录/域名或 SSH 隧道验证，再安排切换；不应直接让两套面板操作同一个数据库。
