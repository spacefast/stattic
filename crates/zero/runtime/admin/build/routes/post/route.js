import '__i18n__';
import{redirect as e}from"@wordpress/route";var o={beforeLoad:({params:t})=>{throw e({to:"/types/$type/list/$slug",params:{type:t.type,slug:"all"}})}};export{o as route};
