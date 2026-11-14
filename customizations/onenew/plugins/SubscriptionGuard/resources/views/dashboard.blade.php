<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer">
<title>订阅防护 · Onenew</title>
<style nonce="{{ $nonce }}">
body{font:14px system-ui,sans-serif;background:#f5f7fa;color:#172033;margin:0;padding:24px}main{max-width:1200px;margin:auto}h1{font-size:24px}p{color:#526071;line-height:1.6}.toolbar{display:flex;gap:10px;flex-wrap:wrap;margin:20px 0}button,select,input{font:inherit;background:white;border:1px solid #cbd5e1;border-radius:6px;padding:8px}button{cursor:pointer}button:disabled{opacity:.4;cursor:default}.table{overflow:auto;background:white;border:1px solid #e2e8f0;border-radius:8px}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px;border-bottom:1px solid #e2e8f0;max-width:360px;overflow-wrap:anywhere}th{font-size:12px;color:#526071}#status{min-height:24px}.danger{color:#b91c1c}footer{margin-top:24px;font-size:12px;color:#64748b}
</style>
<script defer src="/plugins/subscription_guard/dashboard.js"></script>
</head>
<body>
<main id="app" data-api="{{ '/api/v2/' . $secure_path . '/subscription-guard' }}">
<h1>订阅防护</h1><p>使用当前后台管理员身份读取审计记录。插件启停与策略配置在原生插件管理中完成；新安装默认禁用，默认策略仅告警。</p>
<p id="summary">订阅令牌不会存入审计日志。多 IP 仅表示拉取来源变化，不是共享行为的充分证明。</p>
<div class="toolbar"><select id="view" aria-label="记录类型"><option value="logs">拉取日志</option><option value="abuse">防护事件</option><option value="stats">多 IP 统计</option></select><input id="search" aria-label="邮箱或 IP 搜索" placeholder="邮箱或 IP"><button id="refresh">刷新</button><button id="previous">上一页</button><button id="next">下一页</button></div>
<p id="status" role="status">请先登录管理后台。</p>
<div class="table"><table><thead><tr><th>用户</th><th>IP / 数量</th><th>客户端 / 处理</th><th>时间</th><th>人工操作</th></tr></thead><tbody id="rows"></tbody></table></div>
<footer>SubscriptionGuard 原作者：XBoard Plugin · Onenew 安全适配。日志包含个人信息，请仅向授权管理员开放。</footer>
</main>
</body></html>
