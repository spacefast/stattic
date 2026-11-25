import '__i18n__';
import{redirect as o}from"@wordpress/route";import{preloadFieldCollections as r}from"@spacepress-admin/core-data-next";var l={beforeLoad:()=>{throw o({to:"/users/list/$slug",params:{slug:"all"}})},loader:async()=>{await r("root","user")}};export{l as route};
