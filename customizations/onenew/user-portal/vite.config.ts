import {defineConfig} from 'vite'
import vue from '@vitejs/plugin-vue'
export default defineConfig({base:'/onenew-user/',plugins:[vue()],server:{host:'127.0.0.1',port:15174,strictPort:true}})
