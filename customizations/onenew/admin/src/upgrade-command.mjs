export function UpgradeCommand({react:React,command,loading}){
 const h=React.createElement,[copied,setCopied]=React.useState(false),[error,setError]=React.useState('');
 async function copy(){if(!command)return;try{await navigator.clipboard.writeText(command);setCopied(true);setError('')}catch{setError('复制失败，请手动复制命令')}}
 return h('section',{className:'rounded-lg border bg-muted/30 p-3','aria-label':'升级 caddy 节点'},
  h('div',{className:'flex flex-wrap items-center justify-between gap-2'},
   h('div',null,h('h3',{className:'text-sm font-medium'},'升级 caddy 节点'),h('p',{className:'mt-1 text-xs text-muted-foreground'},'在对应节点机运行；Token 会在终端输入，不在此页面显示。')),
   h('button',{type:'button',className:'rounded-md border bg-background px-3 py-1.5 text-xs',disabled:loading||!command,onClick:copy},copied?'已复制':'复制升级命令')),
  h('code',{className:'mt-3 block whitespace-pre-wrap break-all rounded-md border bg-background p-3 font-mono text-xs'},loading?'加载中…':command||'暂无升级命令'),
  error&&h('p',{role:'alert',className:'mt-2 text-xs text-red-600'},error));
}

