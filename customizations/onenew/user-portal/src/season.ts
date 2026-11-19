export const seasons=['spring','summer','autumn','winter'] as const
export type Season=typeof seasons[number]
export const seasonLabels:Record<Season,string>={spring:'春 · 春芽',summer:'夏 · 晴夏',autumn:'秋 · 暖秋',winter:'冬 · 霜雪'}
export const seasonIdentity:Record<Season,{name:string;english:string;welcome:string;intro:string}>={
 spring:{name:'春芽',english:'FRESH SPRING',welcome:'欢迎回来，开启清新的一天',intro:'少一点繁琐，多一点恰到好处的新意。'},
 summer:{name:'晴夏',english:'CLEAR SUMMER',welcome:'欢迎回来，开启清爽的一天',intro:'少一点繁琐，多一点轻松自在的清爽。'},
 autumn:{name:'暖秋',english:'WARM AUTUMN',welcome:'欢迎回来，开启温暖的一天',intro:'少一点繁琐，多一点刚刚好的温度。'},
 winter:{name:'霜雪',english:'QUIET WINTER',welcome:'欢迎回来，开启宁静的一天',intro:'少一点繁琐，多一点从容安心的宁静。'},
}
export const seasonCopy:Record<Season,{tagline:string;badge:string;footer:string}>={
 spring:{tagline:'让连接，多一点生机。',badge:'春芽 · 生长 · 每一天',footer:'春芽 · 让连接多一点生机'},
 summer:{tagline:'让连接，多一点清爽。',badge:'晴空 · 畅快 · 每一天',footer:'晴夏 · 让连接多一点清爽'},
 autumn:{tagline:'让连接，多一点温度。',badge:'订阅 · 能量 · 每一天',footer:'暖秋 · 让连接多一点温度'},
 winter:{tagline:'让连接，多一点宁静。',badge:'霜雪 · 安心 · 每一天',footer:'霜雪 · 让连接多一点宁静'},
}
export const seasonKey='onenew-season'
export function seasonMetadata(season:Season,base:string){
 const root=base.endsWith('/')?base:base+'/'
 return {title:seasonCopy[season].footer,icon:`${root}icons/${season}.svg`,cursor:`${root}cursors/${season}.svg`,pointer:`${root}cursors/${season}-pointer.svg`}
}
type StorageReader={getItem(key:string):string|null}
type StorageWriter={setItem(key:string,value:string):void}
export function isSeason(value:unknown):value is Season{return seasons.some(season=>season===value)}
export function readSeason(storage:StorageReader|null):Season{
 try{const value=storage?.getItem(seasonKey);return isSeason(value)?value:'autumn'}catch{return 'autumn'}
}
export function saveSeason(storage:StorageWriter|null,value:Season):void{
 try{storage?.setItem(seasonKey,value)}catch{/* The current appearance remains usable when persistence is unavailable. */}
}
const colors={
 spring:{light:['#2e7653','#3c8b64','#20593e'],dark:['#8fceaa','#a7dfbd','#76b88f']},
 summer:{light:['#1b6d93','#2583ac','#125372'],dark:['#87cfe7','#a6dff1','#69b5ce']},
 autumn:{light:['#a6532b','#bd6a3e','#894021'],dark:['#e3a371','#efb889','#d58d58']},
 winter:{light:['#586ca5','#6c80bd','#435486'],dark:['#b3c4ef','#cad7f8','#96aada']},
} as const
export function seasonColors(season:Season,dark:boolean){
 const [primaryColor,primaryColorHover,primaryColorPressed]=colors[season][dark?'dark':'light']
 return {primaryColor,primaryColorHover,primaryColorPressed}
}
