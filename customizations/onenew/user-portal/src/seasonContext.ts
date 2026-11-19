import {inject,type InjectionKey,type Ref} from 'vue'
import type {Season} from './season'
export const seasonContext:InjectionKey<{season:Ref<Season>;selectSeason:(value:Season)=>void}>=Symbol('onenew-season')
export function useSeason(){
 const context=inject(seasonContext)
 if(!context)throw new Error('Season appearance provider is missing')
 return context
}
