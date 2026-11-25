import '__i18n__';
var ne=Object.create;var $=Object.defineProperty;var se=Object.getOwnPropertyDescriptor;var ae=Object.getOwnPropertyNames;var re=Object.getPrototypeOf,ie=Object.prototype.hasOwnProperty;var k=(n,a)=>()=>(a||n((a={exports:{}}).exports,a),a.exports);var de=(n,a,o,m)=>{if(a&&typeof a=="object"||typeof a=="function")for(let g of ae(a))!ie.call(n,g)&&g!==o&&$(n,g,{get:()=>a[g],enumerable:!(m=se(a,g))||m.enumerable});return n};var b=(n,a,o)=>(o=n!=null?ne(re(n)):{},de(a||!n||!n.__esModule?$(o,"default",{value:n,enumerable:!0}):o,n));var F=k((me,M)=>{"use strict";M.exports=window.React});var q=k((ue,U)=>{"use strict";U.exports=window.wp.apiFetch});var G=k((ye,L)=>{"use strict";L.exports=window.wp.components});var W=k((ge,J)=>{"use strict";J.exports=window.wp.i18n});var T=k((be,X)=>{"use strict";X.exports=window.ReactJSXRuntime});var l=b(F()),Q=b(q()),c=b(G()),t=b(W());import{Page as oe}from"@spacepress-admin/admin-toolkit";if(!document.head.querySelector("style[data-wp-hash='03d9f169e0']")){let n=document.createElement("style");n.setAttribute("data-wp-hash","03d9f169e0"),n.appendChild(document.createTextNode(`.sp-identity-tabs {
  border-bottom: 1px solid var(--wpds-color-stroke-surface-neutral-weak);
  align-items: center;
  gap: 4px;
  padding: 0 24px;
  display: flex;
  overflow-x: auto;
}

.sp-identity-tab {
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

.sp-identity-tab:hover {
  color: var(--wpds-color-foreground-content-neutral);
}

.sp-identity-tab.is-active {
  color: var(--wpds-color-foreground-content-neutral);
  border-bottom-color: var(--wpds-color-foreground-interactive-brand);
}

.sp-identity-body {
  flex-direction: column;
  gap: 16px;
  display: flex;
}

.sp-identity-toolbar {
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  display: flex;
}

.sp-identity-card {
  border: 1px solid var(--wpds-color-stroke-surface-neutral);
  border-radius: var(--wpds-border-radius-lg);
  background: var(--wpds-color-background-surface-neutral-strong);
  flex-direction: column;
  gap: 12px;
  padding: 16px 20px;
  display: flex;
}

.sp-identity-card h3 {
  margin: 0;
  font-size: 15px;
}

.sp-identity-table-wrap {
  border: 1px solid var(--wpds-color-stroke-surface-neutral);
  border-radius: var(--wpds-border-radius-lg);
  background: var(--wpds-color-background-surface-neutral-strong);
  overflow-x: auto;
}

.sp-identity-table {
  border-collapse: collapse;
  width: 100%;
  font-size: 13px;
}

.sp-identity-table th, .sp-identity-table td {
  text-align: left;
  border-bottom: 1px solid var(--wpds-color-stroke-surface-neutral-weak);
  vertical-align: middle;
  padding: 10px 14px;
}

.sp-identity-table th {
  color: var(--wpds-color-foreground-content-neutral-weak);
  background: var(--wpds-color-background-surface-neutral-weak);
  font-size: 12px;
  font-weight: 600;
}

.sp-identity-table tr:last-child td {
  border-bottom: none;
}

.sp-identity-badge {
  border-radius: var(--wpds-border-radius-sm);
  background: var(--wpds-color-background-surface-neutral-weak);
  color: var(--wpds-color-foreground-content-neutral);
  align-items: center;
  padding: 2px 8px;
  font-size: 12px;
  font-weight: 500;
  display: inline-flex;
}

.sp-identity-badge.is-success {
  background: var(--wpds-color-background-surface-success-weak);
  color: var(--wpds-color-foreground-content-success);
}

.sp-identity-badge.is-warning {
  background: var(--wpds-color-background-surface-warning-weak);
  color: var(--wpds-color-foreground-content-warning);
}

.sp-identity-badge.is-error {
  background: var(--wpds-color-background-surface-error-weak);
  color: var(--wpds-color-foreground-content-error);
}

.sp-identity-form-grid {
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  align-items: end;
  gap: 12px;
  display: grid;
}

.sp-identity-muted {
  color: var(--wpds-color-foreground-content-neutral-weak);
}

.sp-identity-loading {
  color: var(--wpds-color-foreground-content-neutral-weak);
  align-items: center;
  gap: 8px;
  padding: 24px 0;
  display: flex;
}

.sp-identity-code {
  word-break: break-all;
  font-family: monospace;
  font-size: 12px;
}
`)),document.head.appendChild(n)}var e=b(T()),pe=[{key:"people",label:(0,t.__)("People","spacepress-admin")},{key:"organizations",label:(0,t.__)("Organizations","spacepress-admin")},{key:"applications",label:(0,t.__)("Applications","spacepress-admin")},{key:"sessions",label:(0,t.__)("Sessions","spacepress-admin")},{key:"authentication",label:(0,t.__)("Authentication","spacepress-admin")},{key:"security-events",label:(0,t.__)("Security Events","spacepress-admin")}];function H(n){if(n&&typeof n=="object"){let a=n;if(a.error&&typeof a.error=="object"&&"message"in a.error)return String(a.error.message);if("message"in a)return String(a.message)}return(0,t.__)("Unable to complete request.","spacepress-admin")}function A(n){if(n==null||n==="")return"\u2014";let a=typeof n=="number"?n:Number(n),o=!Number.isNaN(a)&&a>1e9?new Date(a>1e12?a:a*1e3):new Date(String(n));return Number.isNaN(o.getTime())?String(n):new Intl.DateTimeFormat(void 0,{dateStyle:"medium",timeStyle:"short"}).format(o)}async function h(n,a="GET",o){let m=await(0,Q.default)({path:`/spacepress-identity/v1/admin/${n}`,method:a,data:o});return m&&typeof m=="object"&&"data"in m?m.data:m}function ce(){let n="ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-",a="";for(let o=0;o<24;o++)a+=n[Math.floor(Math.random()*n.length)];return a}function C(){let[n,a]=(0,l.useState)("people"),[o,m]=(0,l.useState)(null),[g,R]=(0,l.useState)(!0),[f,O]=(0,l.useState)(!1),[z,w]=(0,l.useState)(""),[D,_]=(0,l.useState)(""),[V,Y]=(0,l.useState)(0),[N,E]=(0,l.useState)(""),[S,P]=(0,l.useState)(""),[x,B]=(0,l.useState)(""),[I,K]=(0,l.useState)(null),j=(0,l.useCallback)(()=>Y(i=>i+1),[]);(0,l.useEffect)(()=>{let i=!0;return R(!0),w(""),m(u=>u?.tab===n?u:null),h(n).then(u=>{i&&(m({tab:n,value:u}),R(!1))}).catch(u=>{i&&(m(null),w(H(u)),R(!1))}),()=>{i=!1}},[n,V]);let v=async(i,u)=>{O(!0),w(""),_("");try{await i(),_(u),j()}catch(r){w(H(r))}finally{O(!1)}},Z=()=>{if(g)return(0,e.jsxs)("div",{className:"sp-identity-loading",children:[(0,e.jsx)(c.Spinner,{}),(0,e.jsx)("span",{children:(0,t.__)("Loading\u2026","spacepress-admin")})]});if(!o||o.tab!==n)return null;let i=o.value;if(n==="people"){let r=Array.isArray(i)?i:[];return(0,e.jsx)("div",{className:"sp-identity-table-wrap",children:(0,e.jsxs)("table",{className:"sp-identity-table",children:[(0,e.jsx)("thead",{children:(0,e.jsxs)("tr",{children:[(0,e.jsx)("th",{children:(0,t.__)("Person","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Email","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Status","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("MFA","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Actions","spacepress-admin")})]})}),(0,e.jsx)("tbody",{children:r.length===0?(0,e.jsx)("tr",{children:(0,e.jsx)("td",{colSpan:5,className:"sp-identity-muted",children:(0,t.__)("No accounts found.","spacepress-admin")})}):r.map(s=>{let d=String(s.id??s.wp_user_id??""),p=s.status==="suspended"||s.suspended===!0;return(0,e.jsxs)("tr",{children:[(0,e.jsx)("td",{children:(0,e.jsx)("strong",{children:String(s.display_name??s.name??`#${d}`)})}),(0,e.jsx)("td",{children:String(s.email??"\u2014")}),(0,e.jsx)("td",{children:(0,e.jsx)("span",{className:`sp-identity-badge ${p?"is-error":"is-success"}`,children:String(s.status??(p?"suspended":"active"))})}),(0,e.jsx)("td",{children:(0,e.jsx)("span",{className:`sp-identity-badge ${s.mfa_enabled?"is-success":""}`,children:s.mfa_enabled?(0,t.__)("Enabled","spacepress-admin"):(0,t.__)("Off","spacepress-admin")})}),(0,e.jsx)("td",{children:(0,e.jsx)(c.Button,{variant:"secondary",size:"small",disabled:f,onClick:()=>void v(()=>h("people/suspend","POST",{id:d,suspended:!p}),p?(0,t.__)("Account reactivated.","spacepress-admin"):(0,t.__)("Account suspended.","spacepress-admin")),children:p?(0,t.__)("Reactivate","spacepress-admin"):(0,t.__)("Suspend","spacepress-admin")})})]},d)})})]})})}if(n==="organizations"){let r=Array.isArray(i)?i:[];return(0,e.jsx)("div",{className:"sp-identity-table-wrap",children:(0,e.jsxs)("table",{className:"sp-identity-table",children:[(0,e.jsx)("thead",{children:(0,e.jsxs)("tr",{children:[(0,e.jsx)("th",{children:(0,t.__)("Name","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Slug","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Created","spacepress-admin")})]})}),(0,e.jsx)("tbody",{children:r.length===0?(0,e.jsx)("tr",{children:(0,e.jsx)("td",{colSpan:3,className:"sp-identity-muted",children:(0,t.__)("No organizations yet.","spacepress-admin")})}):r.map((s,d)=>(0,e.jsxs)("tr",{children:[(0,e.jsx)("td",{children:(0,e.jsx)("strong",{children:String(s.name??"\u2014")})}),(0,e.jsx)("td",{className:"sp-identity-code",children:String(s.slug??s.id??"\u2014")}),(0,e.jsx)("td",{children:A(s.created_at)})]},String(s.id??d)))})]})})}if(n==="applications"){let r=Array.isArray(i)?i:[];return(0,e.jsxs)(e.Fragment,{children:[(0,e.jsxs)("form",{className:"sp-identity-card",onSubmit:s=>{s.preventDefault(),!(!N.trim()||!S.trim()||!x.trim())&&v(async()=>{let d=await h("applications","POST",{name:N.trim(),redirect_uri:S.trim(),origin:x.trim(),idempotency_key:ce()});d&&typeof d.client_secret=="string"&&K(d.client_secret),E(""),P(""),B("")},(0,t.__)("OAuth application registered.","spacepress-admin"))},children:[(0,e.jsx)("h3",{children:(0,t.__)("Register OAuth Application","spacepress-admin")}),(0,e.jsxs)("div",{className:"sp-identity-form-grid",children:[(0,e.jsx)(c.TextControl,{label:(0,t.__)("Name","spacepress-admin"),value:N,onChange:E,placeholder:"My Client App"}),(0,e.jsx)(c.TextControl,{label:(0,t.__)("Redirect URI","spacepress-admin"),value:S,onChange:P,placeholder:"https://app.example.com/callback"}),(0,e.jsx)(c.TextControl,{label:(0,t.__)("Allowed Origin","spacepress-admin"),value:x,onChange:B,placeholder:"https://app.example.com"}),(0,e.jsx)(c.Button,{type:"submit",variant:"primary",disabled:f||!N.trim()||!S.trim()||!x.trim(),children:(0,t.__)("Add Application","spacepress-admin")})]}),I&&(0,e.jsxs)(c.Notice,{status:"warning",isDismissible:!0,onRemove:()=>K(null),children:[(0,e.jsx)("strong",{children:(0,t.__)("Client secret (shown once):","spacepress-admin")})," ",(0,e.jsx)("code",{className:"sp-identity-code",children:I})]})]}),(0,e.jsx)("div",{className:"sp-identity-table-wrap",children:(0,e.jsxs)("table",{className:"sp-identity-table",children:[(0,e.jsx)("thead",{children:(0,e.jsxs)("tr",{children:[(0,e.jsx)("th",{children:(0,t.__)("Name","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Client ID","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Origin","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Status","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Actions","spacepress-admin")})]})}),(0,e.jsx)("tbody",{children:r.length===0?(0,e.jsx)("tr",{children:(0,e.jsx)("td",{colSpan:5,className:"sp-identity-muted",children:(0,t.__)("No OAuth applications registered.","spacepress-admin")})}):r.map(s=>{let d=String(s.id??""),p=s.enabled===!0||s.enabled===1||s.enabled==="1";return(0,e.jsxs)("tr",{children:[(0,e.jsx)("td",{children:(0,e.jsx)("strong",{children:String(s.name??"\u2014")})}),(0,e.jsx)("td",{className:"sp-identity-code",children:d}),(0,e.jsx)("td",{className:"sp-identity-code",children:String(s.origin??"\u2014")}),(0,e.jsx)("td",{children:(0,e.jsx)("span",{className:`sp-identity-badge ${p?"is-success":"is-warning"}`,children:p?(0,t.__)("Enabled","spacepress-admin"):(0,t.__)("Disabled","spacepress-admin")})}),(0,e.jsx)("td",{children:(0,e.jsx)(c.Button,{variant:"secondary",size:"small",disabled:f,onClick:()=>void v(()=>h("applications/status","POST",{id:d,enabled:!p}),p?(0,t.__)("Application disabled.","spacepress-admin"):(0,t.__)("Application enabled.","spacepress-admin")),children:p?(0,t.__)("Disable","spacepress-admin"):(0,t.__)("Enable","spacepress-admin")})})]},d)})})]})})]})}if(n==="sessions"){let r=Array.isArray(i)?i:[];return(0,e.jsx)("div",{className:"sp-identity-table-wrap",children:(0,e.jsxs)("table",{className:"sp-identity-table",children:[(0,e.jsx)("thead",{children:(0,e.jsxs)("tr",{children:[(0,e.jsx)("th",{children:(0,t.__)("Session","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("User ID","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Created","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Expires","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Status","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Actions","spacepress-admin")})]})}),(0,e.jsx)("tbody",{children:r.length===0?(0,e.jsx)("tr",{children:(0,e.jsx)("td",{colSpan:6,className:"sp-identity-muted",children:(0,t.__)("No sessions recorded.","spacepress-admin")})}):r.map(s=>{let d=String(s.id??""),p=String(s.wp_user_id??""),y=!!s.revoked_at;return(0,e.jsxs)("tr",{children:[(0,e.jsx)("td",{children:(0,e.jsx)("strong",{children:String(s.label||d.slice(0,12))})}),(0,e.jsxs)("td",{children:["#",p]}),(0,e.jsx)("td",{children:A(s.created_at)}),(0,e.jsx)("td",{children:A(s.expires_at)}),(0,e.jsx)("td",{children:(0,e.jsx)("span",{className:`sp-identity-badge ${y?"is-error":"is-success"}`,children:y?(0,t.__)("Revoked","spacepress-admin"):(0,t.__)("Active","spacepress-admin")})}),(0,e.jsx)("td",{children:!y&&(0,e.jsx)(c.Button,{variant:"secondary",size:"small",isDestructive:!0,disabled:f,onClick:()=>void v(()=>h("sessions/revoke","POST",{user_id:p,id:d}),(0,t.__)("Session revoked.","spacepress-admin")),children:(0,t.__)("Revoke","spacepress-admin")})})]},d)})})]})})}if(n==="authentication"){let r=i&&typeof i=="object"?i:{},s=Array.isArray(r.providers)?r.providers.map(String):[],d=r.keys&&typeof r.keys=="object"?r.keys:{},p=Array.isArray(d.keys)?d.keys:[];return(0,e.jsxs)(e.Fragment,{children:[(0,e.jsxs)("div",{className:"sp-identity-card",children:[(0,e.jsx)("h3",{children:(0,t.__)("Sign-in Providers","spacepress-admin")}),s.length===0?(0,e.jsx)("p",{className:"sp-identity-muted",children:(0,t.__)("Email & password sign-in active. No external OAuth providers configured.","spacepress-admin")}):(0,e.jsx)("div",{children:s.map(y=>(0,e.jsx)("span",{className:"sp-identity-badge is-success",style:{marginInlineEnd:6},children:y},y))})]}),(0,e.jsxs)("div",{className:"sp-identity-card",children:[(0,e.jsxs)("div",{className:"sp-identity-toolbar",children:[(0,e.jsx)("h3",{children:(0,t.__)("Signing Keys (JWKS)","spacepress-admin")}),(0,e.jsx)(c.Button,{variant:"secondary",disabled:f,onClick:()=>void v(()=>h("keys/rotate","POST"),(0,t.__)("Signing keys rotated.","spacepress-admin")),children:(0,t.__)("Rotate Keys","spacepress-admin")})]}),(0,e.jsxs)("p",{className:"sp-identity-muted",children:[p.length," ",(0,t.__)("active signing key(s).","spacepress-admin")]}),p.map((y,te)=>(0,e.jsxs)("div",{className:"sp-identity-code",children:["kid: ",String(y.kid??"\u2014")," (",String(y.alg??y.kty??""),")"]},String(y.kid??te)))]})]})}let u=Array.isArray(i)?i:[];return(0,e.jsx)("div",{className:"sp-identity-table-wrap",children:(0,e.jsxs)("table",{className:"sp-identity-table",children:[(0,e.jsx)("thead",{children:(0,e.jsxs)("tr",{children:[(0,e.jsx)("th",{children:(0,t.__)("Event","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("User ID","spacepress-admin")}),(0,e.jsx)("th",{children:(0,t.__)("Time","spacepress-admin")})]})}),(0,e.jsx)("tbody",{children:u.length===0?(0,e.jsx)("tr",{children:(0,e.jsx)("td",{colSpan:3,className:"sp-identity-muted",children:(0,t.__)("No security events recorded.","spacepress-admin")})}):u.map((r,s)=>(0,e.jsxs)("tr",{children:[(0,e.jsx)("td",{className:"sp-identity-code",children:String(r.kind??r.event??r.type??"\u2014")}),(0,e.jsx)("td",{children:r.wp_user_id?`#${String(r.wp_user_id)}`:"\u2014"}),(0,e.jsx)("td",{children:A(r.created_at??r.occurred_at)})]},String(r.id??s)))})]})})},ee=(0,e.jsx)("div",{className:"sp-identity-tabs",role:"tablist",children:pe.map(i=>(0,e.jsx)("button",{type:"button",role:"tab","aria-selected":n===i.key,className:`sp-identity-tab${n===i.key?" is-active":""}`,onClick:()=>a(i.key),children:i.label},i.key))});return(0,e.jsx)(oe,{title:(0,t.__)("Identity & Auth","spacepress-admin"),subTitle:(0,t.__)("Accounts, organizations, OAuth applications, sessions, and security logs.","spacepress-admin"),tabs:ee,actions:(0,e.jsx)(c.Button,{variant:"secondary",size:"compact",onClick:j,children:(0,t.__)("Refresh","spacepress-admin")}),children:(0,e.jsxs)("div",{className:"sp-identity-body",children:[z&&(0,e.jsx)(c.Notice,{status:"error",isDismissible:!0,onRemove:()=>w(""),children:z}),D&&(0,e.jsx)(c.Notice,{status:"success",isDismissible:!0,onRemove:()=>_(""),children:D}),Z()]})})}var ke=C,Ne=void 0,Se="/identity";export{Ne as inspector,Se as path,ke as stage};
