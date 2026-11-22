import '__i18n__';
var nt=Object.create;var $=Object.defineProperty;var at=Object.getOwnPropertyDescriptor;var st=Object.getOwnPropertyNames;var rt=Object.getPrototypeOf,it=Object.prototype.hasOwnProperty;var k=(n,s)=>()=>(s||n((s={exports:{}}).exports,s),s.exports);var dt=(n,s,o,y)=>{if(s&&typeof s=="object"||typeof s=="function")for(let g of st(s))!it.call(n,g)&&g!==o&&$(n,g,{get:()=>s[g],enumerable:!(y=at(s,g))||y.enumerable});return n};var b=(n,s,o)=>(o=n!=null?nt(rt(n)):{},dt(s||!n||!n.__esModule?$(o,"default",{value:n,enumerable:!0}):o,n));var F=k((yt,M)=>{"use strict";M.exports=window.React});var q=k((mt,U)=>{"use strict";U.exports=window.wp.apiFetch});var G=k((ut,L)=>{"use strict";L.exports=window.wp.components});var W=k((gt,J)=>{"use strict";J.exports=window.wp.i18n});var T=k((bt,X)=>{"use strict";X.exports=window.ReactJSXRuntime});var l=b(F()),Q=b(q()),c=b(G()),e=b(W());import{Page as ot}from"@typedpress-admin/admin-toolkit";if(!document.head.querySelector("style[data-wp-hash='e97d0d2ef8']")){let n=document.createElement("style");n.setAttribute("data-wp-hash","e97d0d2ef8"),n.appendChild(document.createTextNode(`.tp-identity-tabs {
  border-bottom: 1px solid var(--wpds-color-stroke-surface-neutral-weak);
  align-items: center;
  gap: 4px;
  padding: 0 24px;
  display: flex;
  overflow-x: auto;
}

.tp-identity-tab {
  appearance: none;
  white-space: nowrap;
  color: var(--wpds-color-foreground-content-neutral-weak);
  cursor: pointer;
  background: none;
  border: none;
  border-bottom: 2px solid #0000;
  padding: 10px 12px;
  font-size: 13px;
  font-weight: 500;
}

.tp-identity-tab:hover {
  color: var(--wpds-color-foreground-content-neutral);
}

.tp-identity-tab.is-active {
  color: var(--wpds-color-foreground-content-neutral);
  border-bottom-color: var(--wpds-color-foreground-interactive-brand);
}

.tp-identity-body {
  flex-direction: column;
  gap: 16px;
  display: flex;
}

.tp-identity-toolbar {
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  display: flex;
}

.tp-identity-card {
  border: 1px solid var(--wpds-color-stroke-surface-neutral);
  border-radius: var(--wpds-border-radius-lg);
  background: var(--wpds-color-background-surface-neutral-strong);
  flex-direction: column;
  gap: 12px;
  padding: 16px 20px;
  display: flex;
}

.tp-identity-card h3 {
  margin: 0;
  font-size: 15px;
}

.tp-identity-table-wrap {
  border: 1px solid var(--wpds-color-stroke-surface-neutral);
  border-radius: var(--wpds-border-radius-lg);
  background: var(--wpds-color-background-surface-neutral-strong);
  overflow-x: auto;
}

.tp-identity-table {
  border-collapse: collapse;
  width: 100%;
  font-size: 13px;
}

.tp-identity-table th, .tp-identity-table td {
  text-align: left;
  border-bottom: 1px solid var(--wpds-color-stroke-surface-neutral-weak);
  vertical-align: middle;
  padding: 10px 14px;
}

.tp-identity-table th {
  color: var(--wpds-color-foreground-content-neutral-weak);
  background: var(--wpds-color-background-surface-neutral-weak);
  font-size: 12px;
  font-weight: 600;
}

.tp-identity-table tr:last-child td {
  border-bottom: none;
}

.tp-identity-badge {
  border-radius: var(--wpds-border-radius-sm);
  background: var(--wpds-color-background-surface-neutral-weak);
  color: var(--wpds-color-foreground-content-neutral);
  align-items: center;
  padding: 2px 8px;
  font-size: 12px;
  font-weight: 500;
  display: inline-flex;
}

.tp-identity-badge.is-success {
  background: var(--wpds-color-background-surface-success-weak);
  color: var(--wpds-color-foreground-content-success);
}

.tp-identity-badge.is-warning {
  background: var(--wpds-color-background-surface-warning-weak);
  color: var(--wpds-color-foreground-content-warning);
}

.tp-identity-badge.is-error {
  background: var(--wpds-color-background-surface-error-weak);
  color: var(--wpds-color-foreground-content-error);
}

.tp-identity-form-grid {
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  align-items: end;
  gap: 12px;
  display: grid;
}

.tp-identity-muted {
  color: var(--wpds-color-foreground-content-neutral-weak);
}

.tp-identity-loading {
  color: var(--wpds-color-foreground-content-neutral-weak);
  align-items: center;
  gap: 8px;
  padding: 24px 0;
  display: flex;
}

.tp-identity-code {
  word-break: break-all;
  font-family: monospace;
  font-size: 12px;
}
`)),document.head.appendChild(n)}var t=b(T()),pt=[{key:"people",label:(0,e.__)("People","typedpress-admin")},{key:"organizations",label:(0,e.__)("Organizations","typedpress-admin")},{key:"applications",label:(0,e.__)("Applications","typedpress-admin")},{key:"sessions",label:(0,e.__)("Sessions","typedpress-admin")},{key:"authentication",label:(0,e.__)("Authentication","typedpress-admin")},{key:"security-events",label:(0,e.__)("Security Events","typedpress-admin")}];function H(n){if(n&&typeof n=="object"){let s=n;if(s.error&&typeof s.error=="object"&&"message"in s.error)return String(s.error.message);if("message"in s)return String(s.message)}return(0,e.__)("Unable to complete request.","typedpress-admin")}function A(n){if(n==null||n==="")return"\u2014";let s=typeof n=="number"?n:Number(n),o=!Number.isNaN(s)&&s>1e9?new Date(s>1e12?s:s*1e3):new Date(String(n));return Number.isNaN(o.getTime())?String(n):new Intl.DateTimeFormat(void 0,{dateStyle:"medium",timeStyle:"short"}).format(o)}async function h(n,s="GET",o){let y=await(0,Q.default)({path:`/typedpress-identity/v1/admin/${n}`,method:s,data:o});return y&&typeof y=="object"&&"data"in y?y.data:y}function ct(){let n="ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-",s="";for(let o=0;o<24;o++)s+=n[Math.floor(Math.random()*n.length)];return s}function C(){let[n,s]=(0,l.useState)("people"),[o,y]=(0,l.useState)(null),[g,R]=(0,l.useState)(!0),[f,O]=(0,l.useState)(!1),[z,w]=(0,l.useState)(""),[D,_]=(0,l.useState)(""),[V,Y]=(0,l.useState)(0),[N,E]=(0,l.useState)(""),[S,P]=(0,l.useState)(""),[x,B]=(0,l.useState)(""),[I,K]=(0,l.useState)(null),j=(0,l.useCallback)(()=>Y(i=>i+1),[]);(0,l.useEffect)(()=>{let i=!0;return R(!0),w(""),y(m=>m?.tab===n?m:null),h(n).then(m=>{i&&(y({tab:n,value:m}),R(!1))}).catch(m=>{i&&(y(null),w(H(m)),R(!1))}),()=>{i=!1}},[n,V]);let v=async(i,m)=>{O(!0),w(""),_("");try{await i(),_(m),j()}catch(r){w(H(r))}finally{O(!1)}},Z=()=>{if(g)return(0,t.jsxs)("div",{className:"tp-identity-loading",children:[(0,t.jsx)(c.Spinner,{}),(0,t.jsx)("span",{children:(0,e.__)("Loading\u2026","typedpress-admin")})]});if(!o||o.tab!==n)return null;let i=o.value;if(n==="people"){let r=Array.isArray(i)?i:[];return(0,t.jsx)("div",{className:"tp-identity-table-wrap",children:(0,t.jsxs)("table",{className:"tp-identity-table",children:[(0,t.jsx)("thead",{children:(0,t.jsxs)("tr",{children:[(0,t.jsx)("th",{children:(0,e.__)("Person","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Email","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Status","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("MFA","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Actions","typedpress-admin")})]})}),(0,t.jsx)("tbody",{children:r.length===0?(0,t.jsx)("tr",{children:(0,t.jsx)("td",{colSpan:5,className:"tp-identity-muted",children:(0,e.__)("No accounts found.","typedpress-admin")})}):r.map(a=>{let d=String(a.id??a.wp_user_id??""),p=a.status==="suspended"||a.suspended===!0;return(0,t.jsxs)("tr",{children:[(0,t.jsx)("td",{children:(0,t.jsx)("strong",{children:String(a.display_name??a.name??`#${d}`)})}),(0,t.jsx)("td",{children:String(a.email??"\u2014")}),(0,t.jsx)("td",{children:(0,t.jsx)("span",{className:`tp-identity-badge ${p?"is-error":"is-success"}`,children:String(a.status??(p?"suspended":"active"))})}),(0,t.jsx)("td",{children:(0,t.jsx)("span",{className:`tp-identity-badge ${a.mfa_enabled?"is-success":""}`,children:a.mfa_enabled?(0,e.__)("Enabled","typedpress-admin"):(0,e.__)("Off","typedpress-admin")})}),(0,t.jsx)("td",{children:(0,t.jsx)(c.Button,{variant:"secondary",size:"small",disabled:f,onClick:()=>void v(()=>h("people/suspend","POST",{id:d,suspended:!p}),p?(0,e.__)("Account reactivated.","typedpress-admin"):(0,e.__)("Account suspended.","typedpress-admin")),children:p?(0,e.__)("Reactivate","typedpress-admin"):(0,e.__)("Suspend","typedpress-admin")})})]},d)})})]})})}if(n==="organizations"){let r=Array.isArray(i)?i:[];return(0,t.jsx)("div",{className:"tp-identity-table-wrap",children:(0,t.jsxs)("table",{className:"tp-identity-table",children:[(0,t.jsx)("thead",{children:(0,t.jsxs)("tr",{children:[(0,t.jsx)("th",{children:(0,e.__)("Name","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Slug","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Created","typedpress-admin")})]})}),(0,t.jsx)("tbody",{children:r.length===0?(0,t.jsx)("tr",{children:(0,t.jsx)("td",{colSpan:3,className:"tp-identity-muted",children:(0,e.__)("No organizations yet.","typedpress-admin")})}):r.map((a,d)=>(0,t.jsxs)("tr",{children:[(0,t.jsx)("td",{children:(0,t.jsx)("strong",{children:String(a.name??"\u2014")})}),(0,t.jsx)("td",{className:"tp-identity-code",children:String(a.slug??a.id??"\u2014")}),(0,t.jsx)("td",{children:A(a.created_at)})]},String(a.id??d)))})]})})}if(n==="applications"){let r=Array.isArray(i)?i:[];return(0,t.jsxs)(t.Fragment,{children:[(0,t.jsxs)("form",{className:"tp-identity-card",onSubmit:a=>{a.preventDefault(),!(!N.trim()||!S.trim()||!x.trim())&&v(async()=>{let d=await h("applications","POST",{name:N.trim(),redirect_uri:S.trim(),origin:x.trim(),idempotency_key:ct()});d&&typeof d.client_secret=="string"&&K(d.client_secret),E(""),P(""),B("")},(0,e.__)("OAuth application registered.","typedpress-admin"))},children:[(0,t.jsx)("h3",{children:(0,e.__)("Register OAuth Application","typedpress-admin")}),(0,t.jsxs)("div",{className:"tp-identity-form-grid",children:[(0,t.jsx)(c.TextControl,{label:(0,e.__)("Name","typedpress-admin"),value:N,onChange:E,placeholder:"My Client App"}),(0,t.jsx)(c.TextControl,{label:(0,e.__)("Redirect URI","typedpress-admin"),value:S,onChange:P,placeholder:"https://app.example.com/callback"}),(0,t.jsx)(c.TextControl,{label:(0,e.__)("Allowed Origin","typedpress-admin"),value:x,onChange:B,placeholder:"https://app.example.com"}),(0,t.jsx)(c.Button,{type:"submit",variant:"primary",disabled:f||!N.trim()||!S.trim()||!x.trim(),children:(0,e.__)("Add Application","typedpress-admin")})]}),I&&(0,t.jsxs)(c.Notice,{status:"warning",isDismissible:!0,onRemove:()=>K(null),children:[(0,t.jsx)("strong",{children:(0,e.__)("Client secret (shown once):","typedpress-admin")})," ",(0,t.jsx)("code",{className:"tp-identity-code",children:I})]})]}),(0,t.jsx)("div",{className:"tp-identity-table-wrap",children:(0,t.jsxs)("table",{className:"tp-identity-table",children:[(0,t.jsx)("thead",{children:(0,t.jsxs)("tr",{children:[(0,t.jsx)("th",{children:(0,e.__)("Name","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Client ID","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Origin","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Status","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Actions","typedpress-admin")})]})}),(0,t.jsx)("tbody",{children:r.length===0?(0,t.jsx)("tr",{children:(0,t.jsx)("td",{colSpan:5,className:"tp-identity-muted",children:(0,e.__)("No OAuth applications registered.","typedpress-admin")})}):r.map(a=>{let d=String(a.id??""),p=a.enabled===!0||a.enabled===1||a.enabled==="1";return(0,t.jsxs)("tr",{children:[(0,t.jsx)("td",{children:(0,t.jsx)("strong",{children:String(a.name??"\u2014")})}),(0,t.jsx)("td",{className:"tp-identity-code",children:d}),(0,t.jsx)("td",{className:"tp-identity-code",children:String(a.origin??"\u2014")}),(0,t.jsx)("td",{children:(0,t.jsx)("span",{className:`tp-identity-badge ${p?"is-success":"is-warning"}`,children:p?(0,e.__)("Enabled","typedpress-admin"):(0,e.__)("Disabled","typedpress-admin")})}),(0,t.jsx)("td",{children:(0,t.jsx)(c.Button,{variant:"secondary",size:"small",disabled:f,onClick:()=>void v(()=>h("applications/status","POST",{id:d,enabled:!p}),p?(0,e.__)("Application disabled.","typedpress-admin"):(0,e.__)("Application enabled.","typedpress-admin")),children:p?(0,e.__)("Disable","typedpress-admin"):(0,e.__)("Enable","typedpress-admin")})})]},d)})})]})})]})}if(n==="sessions"){let r=Array.isArray(i)?i:[];return(0,t.jsx)("div",{className:"tp-identity-table-wrap",children:(0,t.jsxs)("table",{className:"tp-identity-table",children:[(0,t.jsx)("thead",{children:(0,t.jsxs)("tr",{children:[(0,t.jsx)("th",{children:(0,e.__)("Session","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("User ID","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Created","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Expires","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Status","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Actions","typedpress-admin")})]})}),(0,t.jsx)("tbody",{children:r.length===0?(0,t.jsx)("tr",{children:(0,t.jsx)("td",{colSpan:6,className:"tp-identity-muted",children:(0,e.__)("No sessions recorded.","typedpress-admin")})}):r.map(a=>{let d=String(a.id??""),p=String(a.wp_user_id??""),u=!!a.revoked_at;return(0,t.jsxs)("tr",{children:[(0,t.jsx)("td",{children:(0,t.jsx)("strong",{children:String(a.label||d.slice(0,12))})}),(0,t.jsxs)("td",{children:["#",p]}),(0,t.jsx)("td",{children:A(a.created_at)}),(0,t.jsx)("td",{children:A(a.expires_at)}),(0,t.jsx)("td",{children:(0,t.jsx)("span",{className:`tp-identity-badge ${u?"is-error":"is-success"}`,children:u?(0,e.__)("Revoked","typedpress-admin"):(0,e.__)("Active","typedpress-admin")})}),(0,t.jsx)("td",{children:!u&&(0,t.jsx)(c.Button,{variant:"secondary",size:"small",isDestructive:!0,disabled:f,onClick:()=>void v(()=>h("sessions/revoke","POST",{user_id:p,id:d}),(0,e.__)("Session revoked.","typedpress-admin")),children:(0,e.__)("Revoke","typedpress-admin")})})]},d)})})]})})}if(n==="authentication"){let r=i&&typeof i=="object"?i:{},a=Array.isArray(r.providers)?r.providers.map(String):[],d=r.keys&&typeof r.keys=="object"?r.keys:{},p=Array.isArray(d.keys)?d.keys:[];return(0,t.jsxs)(t.Fragment,{children:[(0,t.jsxs)("div",{className:"tp-identity-card",children:[(0,t.jsx)("h3",{children:(0,e.__)("Sign-in Providers","typedpress-admin")}),a.length===0?(0,t.jsx)("p",{className:"tp-identity-muted",children:(0,e.__)("Email & password sign-in active. No external OAuth providers configured.","typedpress-admin")}):(0,t.jsx)("div",{children:a.map(u=>(0,t.jsx)("span",{className:"tp-identity-badge is-success",style:{marginInlineEnd:6},children:u},u))})]}),(0,t.jsxs)("div",{className:"tp-identity-card",children:[(0,t.jsxs)("div",{className:"tp-identity-toolbar",children:[(0,t.jsx)("h3",{children:(0,e.__)("Signing Keys (JWKS)","typedpress-admin")}),(0,t.jsx)(c.Button,{variant:"secondary",disabled:f,onClick:()=>void v(()=>h("keys/rotate","POST"),(0,e.__)("Signing keys rotated.","typedpress-admin")),children:(0,e.__)("Rotate Keys","typedpress-admin")})]}),(0,t.jsxs)("p",{className:"tp-identity-muted",children:[p.length," ",(0,e.__)("active signing key(s).","typedpress-admin")]}),p.map((u,et)=>(0,t.jsxs)("div",{className:"tp-identity-code",children:["kid: ",String(u.kid??"\u2014")," (",String(u.alg??u.kty??""),")"]},String(u.kid??et)))]})]})}let m=Array.isArray(i)?i:[];return(0,t.jsx)("div",{className:"tp-identity-table-wrap",children:(0,t.jsxs)("table",{className:"tp-identity-table",children:[(0,t.jsx)("thead",{children:(0,t.jsxs)("tr",{children:[(0,t.jsx)("th",{children:(0,e.__)("Event","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("User ID","typedpress-admin")}),(0,t.jsx)("th",{children:(0,e.__)("Time","typedpress-admin")})]})}),(0,t.jsx)("tbody",{children:m.length===0?(0,t.jsx)("tr",{children:(0,t.jsx)("td",{colSpan:3,className:"tp-identity-muted",children:(0,e.__)("No security events recorded.","typedpress-admin")})}):m.map((r,a)=>(0,t.jsxs)("tr",{children:[(0,t.jsx)("td",{className:"tp-identity-code",children:String(r.kind??r.event??r.type??"\u2014")}),(0,t.jsx)("td",{children:r.wp_user_id?`#${String(r.wp_user_id)}`:"\u2014"}),(0,t.jsx)("td",{children:A(r.created_at??r.occurred_at)})]},String(r.id??a)))})]})})},tt=(0,t.jsx)("div",{className:"tp-identity-tabs",role:"tablist",children:pt.map(i=>(0,t.jsx)("button",{type:"button",role:"tab","aria-selected":n===i.key,className:`tp-identity-tab${n===i.key?" is-active":""}`,onClick:()=>s(i.key),children:i.label},i.key))});return(0,t.jsx)(ot,{title:(0,e.__)("Identity & Auth","typedpress-admin"),subTitle:(0,e.__)("Accounts, organizations, OAuth applications, sessions, and security logs.","typedpress-admin"),tabs:tt,actions:(0,t.jsx)(c.Button,{variant:"secondary",size:"compact",onClick:j,children:(0,e.__)("Refresh","typedpress-admin")}),children:(0,t.jsxs)("div",{className:"tp-identity-body",children:[z&&(0,t.jsx)(c.Notice,{status:"error",isDismissible:!0,onRemove:()=>w(""),children:z}),D&&(0,t.jsx)(c.Notice,{status:"success",isDismissible:!0,onRemove:()=>_(""),children:D}),Z()]})})}var kt=C,Nt=void 0,St="/identity";export{Nt as inspector,St as path,kt as stage};
