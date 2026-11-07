import '__i18n__';
var Nt=Object.create;var U=Object.defineProperty;var Tt=Object.getOwnPropertyDescriptor;var zt=Object.getOwnPropertyNames;var At=Object.getPrototypeOf,Bt=Object.prototype.hasOwnProperty;var l=(t,a)=>()=>(a||t((a={exports:{}}).exports,a),a.exports);var Vt=(t,a,r,o)=>{if(a&&typeof a=="object"||typeof a=="function")for(let f of zt(a))!Bt.call(t,f)&&f!==r&&U(t,f,{get:()=>a[f],enumerable:!(o=Tt(a,f))||o.enumerable});return t};var e=(t,a,r)=>(r=t!=null?Nt(At(t)):{},Vt(a||!t||!t.__esModule?U(r,"default",{value:t,enumerable:!0}):r,t));var b=l(($t,G)=>{"use strict";G.exports=window.wp.i18n});var _=l((Kt,W)=>{"use strict";W.exports=window.wp.element});var k=l((Xt,J)=>{"use strict";J.exports=window.wp.primitives});var d=l((Yt,$)=>{"use strict";$.exports=window.ReactJSXRuntime});var X=l((oa,K)=>{"use strict";K.exports=window.React});var ot=l((pa,et)=>{"use strict";et.exports=window.wp.blocks});var M=l((na,rt)=>{"use strict";rt.exports=window.wp.blockEditor});var st=l((ca,ft)=>{"use strict";ft.exports=window.wp.editor});var O=l((ba,nt)=>{"use strict";nt.exports=window.wp.components});var F=l((Ca,ht)=>{"use strict";ht.exports=window.wp.data});var L=l((Ra,gt)=>{"use strict";gt.exports=window.wp.coreData});function H(t){var a,r,o="";if(typeof t=="string"||typeof t=="number")o+=t;else if(typeof t=="object")if(Array.isArray(t)){var f=t.length;for(a=0;a<f;a++)t[a]&&(r=H(t[a]))&&(o&&(o+=" "),o+=r)}else for(r in t)t[r]&&(o&&(o+=" "),o+=r);return o}function Mt(){for(var t,a,r=0,o="",f=arguments.length;r<f;r++)(t=arguments[r])&&(a=H(t))&&(o&&(o+=" "),o+=a);return o}var q=Mt;var tt=e(b());var P=e(k(),1),T=e(d(),1),z=(0,T.jsx)(P.SVG,{xmlns:"http://www.w3.org/2000/svg",viewBox:"0 0 24 24",fill:"currentColor",children:(0,T.jsx)(P.Path,{d:"M18 4H6c-1.1 0-2 .9-2 2v12.9c0 .6.5 1.1 1.1 1.1.3 0 .5-.1.8-.3L8.5 17H18c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm.5 11c0 .3-.2.5-.5.5H7.9l-2.4 2.4V6c0-.3.2-.5.5-.5h12c.3 0 .5.2.5.5v9z"})});var C=e(k(),1),A=e(d(),1),B=(0,A.jsx)(C.SVG,{xmlns:"http://www.w3.org/2000/svg",viewBox:"0 0 24 24",fill:"currentColor",children:(0,A.jsx)(C.Path,{d:"M5 11.25h14v1.5H5z"})});var Y=e(_(),1),Q=e(k(),1),Z=e(d(),1),V=(0,Y.forwardRef)(function({icon:a,size:r=24,...o},f){return(0,Z.jsx)(Q.SVG,{ref:f,...a.props,...o,width:r,height:r})});if(!document.head.querySelector("style[data-wp-hash='20baea7265']")){let t=document.createElement("style");t.setAttribute("data-wp-hash","20baea7265"),t.appendChild(document.createTextNode(`.comments-field {
  align-items: center;
  gap: 4px;
  display: flex;
}

.comments-field__icon {
  flex-shrink: 0;
  width: 24px;
  height: 24px;
}

.comments-field__count {
  color: inherit;
  font-size: inherit;
}

.comments-field--no-comments .comments-field__icon {
  display: none;
}
`)),document.head.appendChild(t)}var y=e(d()),It=({item:t})=>{let a=t.comment_count||0;return(0,y.jsxs)("div",{className:q("comments-field",{"comments-field--no-comments":a===0}),children:[(0,y.jsx)(V,{icon:z,className:"comments-field__icon"},"comments-icon"),(0,y.jsx)("span",{className:"comments-field__count",children:a===0?(0,tt.__)("\u2014","zero-admin"):a},"comments-count")]})},at={render:It};var dt=e(b()),lt=e(_()),mt=e(ot()),I=e(M()),it=e(st());import{withEditor as jt,withStylesId as Ot}from"@zero-admin/lazy-editor";if(!document.head.querySelector("style[data-wp-hash='36830438a4']")){let t=document.createElement("style");t.setAttribute("data-wp-hash","36830438a4"),t.appendChild(document.createTextNode(`.fields-content-preview {
  flex-direction: column;
  justify-content: center;
  align-items: center;
  width: 100%;
  height: 100%;
  display: flex;
  position: relative;
}

.dataviews-view-grid .fields-content-preview .block-editor-block-preview__container {
  height: 100%;
}

.dataviews-view-table .fields-content-preview {
  text-wrap: balance;
  text-wrap: pretty;
  width: 120px;
  max-height: 160px;
}
`)),document.head.appendChild(t)}var p=e(d());function Ut({item:t,settings:a}){let r=(0,lt.useMemo)(()=>{if(t.blocks)return t.blocks;let f="";return typeof t.content=="string"?f=t.content:t.content&&typeof t.content=="object"&&(f=t.content.raw||t.content.rendered||""),(0,mt.parse)(f)},[t.content,t.blocks]),o=!r?.length;return(0,p.jsx)(it.EditorProvider,{post:t,settings:a,children:(0,p.jsxs)("div",{className:"fields-content-preview",children:[o&&(0,dt.__)("Empty content","zero-admin"),!o&&(0,p.jsx)(I.BlockPreview.Async,{children:(0,p.jsx)(I.BlockPreview,{blocks:r})})]})})}var Ht=Ot(jt(Ut)),ut={render:({item:t,...a})=>(0,p.jsx)(Ht,{item:t,postId:t.id.toString(),postType:t.type,...a})};function R(t){if(!t)return"";let a=new DOMParser().parseFromString(t,"text/html");for(let r of a.querySelectorAll("script, style, template"))r.remove();return(a.body.textContent??"").replace(/\s+/g," ").trim()}var j=e(d());function qt({item:t}){let a=R(typeof t.excerpt=="string"?t.excerpt:t.excerpt?.rendered??"");return a?(0,j.jsx)("div",{className:"excerpt-field__content",children:a}):(0,j.jsx)("span",{className:"excerpt-field__empty",children:"\u2014"})}var pt={render:qt};var h=e(O(),1);if(!document.head.querySelector("style[data-wp-hash='fd022c9450']")){let t=document.createElement("style");t.setAttribute("data-wp-hash","fd022c9450"),t.appendChild(document.createTextNode(`.zero-admin-media-loading {
  justify-content: center;
  align-items: center;
  width: 100%;
  height: 100%;
  display: flex;
}

.zero-admin-media-loading .components-spinner {
  margin: 0;
}
`)),document.head.appendChild(t)}var c=e(d(),1);function g({resource:t}){return t.kind==="error"?(0,c.jsxs)(h.Notice,{status:"error",isDismissible:!1,children:[t.message," ",(0,c.jsx)(h.Button,{variant:"secondary",onClick:t.retry,children:"Retry media"})]}):(0,c.jsx)("div",{className:"zero-admin-media-loading",children:(0,c.jsx)(h.Spinner,{})})}var ct=e(X(),1);function w(t,a,r){let o=(0,ct.useCallback)(()=>{},[]);return{kind:"ready",url:a,retry:o}}var wt=e(F()),yt=e(L()),S=e(d()),xt=({item:t,config:a})=>{let r=t.featured_media,o=(0,wt.useSelect)(i=>{let{getEntityRecord:n}=i(yt.store);return r?n("postType","attachment",r):null},[r]),f=w(o?.id,o?.source_url,o?.modified),m=f.kind==="ready"?f.url:void 0;return f.kind!=="ready"?(0,S.jsx)(g,{resource:f}):m?(0,S.jsx)("img",{className:"fields-controls__featured-image-image",src:m,alt:"",srcSet:o?.media_details?.sizes?Object.values(o.media_details.sizes).map(i=>`${i.source_url} ${i.width}w`).join(", "):void 0,sizes:a?.sizes||"100vw"}):(0,S.jsx)("span",{className:"fields-controls__featured-image-placeholder"})};var E=e(O()),vt=e(F()),D=e(_()),bt=e(M());var _t=e(L()),kt=e(b()),s=e(d()),Pt=({data:t,field:a,onChange:r})=>{let{id:o}=a,f=a.getValue({item:t}),m=(0,vt.useSelect)(u=>{let{getEntityRecord:N}=u(_t.store);return N("postType","attachment",f)},[f]),i=(0,D.useCallback)(u=>r({[o]:u}),[o,r]),n=w(m?.id,m?.source_url,m?.modified),v=n.kind==="ready"?n.url:void 0,Et=m?.title?.rendered,Dt=(0,D.useRef)(null);return n.kind!=="ready"?(0,s.jsx)(g,{resource:n}):(0,s.jsx)("fieldset",{className:"fields-controls__featured-image",children:(0,s.jsx)("div",{className:"fields-controls__featured-image-container",children:(0,s.jsx)(bt.MediaUpload,{onSelect:u=>{i(u.id)},allowedTypes:["image"],render:({open:u})=>(0,s.jsx)("div",{ref:Dt,role:"button",tabIndex:-1,onClick:()=>{u()},onKeyDown:u,children:(0,s.jsxs)(E.__experimentalGrid,{rowGap:0,columnGap:8,templateColumns:"24px 1fr 24px",children:[v&&(0,s.jsxs)(s.Fragment,{children:[(0,s.jsx)("img",{className:"fields-controls__featured-image-image",alt:"",width:24,height:24,src:v}),(0,s.jsx)("span",{className:"fields-controls__featured-image-title",children:Et})]}),!v&&(0,s.jsxs)(s.Fragment,{children:[(0,s.jsx)("span",{className:"fields-controls__featured-image-placeholder",style:{width:"24px",height:"24px"}}),(0,s.jsx)("span",{className:"fields-controls__featured-image-title",children:(0,kt.__)("Choose an image\u2026","zero-admin")})]}),v&&(0,s.jsx)(s.Fragment,{children:(0,s.jsx)(E.Button,{size:"small",className:"fields-controls__featured-image-remove-button",icon:B,onClick:N=>{N.stopPropagation(),i(0)}})})]})})})})})};var Ct={Edit:Pt,render:xt};var Rt=e(F()),Ft=e(L());if(!document.head.querySelector("style[data-wp-hash='c49d710ef4']")){let t=document.createElement("style");t.setAttribute("data-wp-hash","c49d710ef4"),t.appendChild(document.createTextNode(`.dataviews-view-grid__media--clickable {
  color: inherit;
  text-decoration: none;
}

.dataviews-media-field__featured-mixed {
  height: calc(100% - 60px);
  margin: 30px;
  display: flex;
  overflow: hidden;
}

.dataviews-media-field__featured-mixed p {
  margin: 0;
  font-size: 1.5vw;
  position: relative;
  mask-image: linear-gradient(#000 0% 60%, #0003 80%, #0000 100%);
}
`)),document.head.appendChild(t)}var x=e(d());function Gt({item:t}){let a=t.featured_media,r=(0,Rt.useSelect)(m=>{let{getEntityRecord:i}=m(Ft.store);return a?i("postType","attachment",a):null},[a]),o=w(r?.id,r?.source_url,r?.modified),f=o.kind==="ready"?o.url:void 0;return o.kind!=="ready"?(0,x.jsx)(g,{resource:o}):f?(0,x.jsx)("img",{className:"fields-controls__featured-image-image",src:f,alt:""}):(0,x.jsx)("div",{className:"dataviews-media-field__featured-mixed",children:(0,x.jsx)("p",{children:R(typeof t.excerpt=="string"?t.excerpt:t.excerpt?.rendered??"")})})}var Lt={render:Gt};var St={getValue:t=>t.item.date===null?"no set":new Date(t.item.date).getFullYear().toString()};var Ka=[Object.assign({id:"comment_count"},at),Object.assign({id:"content-preview"},ut),Object.assign({id:"excerpt"},pt),Object.assign({id:"featured_media"},Ct),Object.assign({id:"featured_mixed"},Lt),Object.assign({id:"year"},St)];export{Ka as default};
