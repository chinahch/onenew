export function RankingStatus({react:R,query}){
  const h=R.createElement;
  const loading=query.isPending??query.isLoading??false;
  if(loading)return h('div',{className:'flex h-[400px] items-center justify-center',role:'status'},
    h('span',{className:'animate-pulse'},'加载中…'));
  if(query.isError)return h('div',{className:'flex h-[400px] flex-col items-center justify-center gap-3 text-sm',role:'alert'},
    h('p',null,'流量排行加载失败，请重试。'),
    h('button',{type:'button',className:'rounded-md border px-3 py-1.5 disabled:opacity-50',disabled:!!query.isFetching,
      onClick:()=>query.refetch()},query.isFetching?'正在重试…':'重新加载'));
  return h('div',{className:'flex h-[400px] flex-col items-center justify-center gap-2 text-sm',role:'status'},
    h('p',null,'暂无流量数据'),
    h('p',{className:'text-muted-foreground'},'所选时段暂无统计记录，可切换时间范围查看。'));
}
