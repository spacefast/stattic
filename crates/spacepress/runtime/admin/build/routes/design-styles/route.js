import '__i18n__';
var Ae=Object.create;var Q=Object.defineProperty;var Re=Object.getOwnPropertyDescriptor;var Te=Object.getOwnPropertyNames;var Ie=Object.getPrototypeOf,Le=Object.prototype.hasOwnProperty;var x=(t,e)=>()=>(e||t((e={exports:{}}).exports,e),e.exports);var Me=(t,e,s,r)=>{if(e&&typeof e=="object"||typeof e=="function")for(let o of Te(e))!Le.call(t,o)&&o!==s&&Q(t,o,{get:()=>e[o],enumerable:!(r=Re(e,o))||r.enumerable});return t};var a=(t,e,s)=>(s=t!=null?Ae(Ie(t)):{},Me(e||!t||!t.__esModule?Q(s,"default",{value:t,enumerable:!0}):s,t));var D=x((pt,ee)=>{"use strict";ee.exports=window.wp.data});var V=x((mt,te)=>{"use strict";te.exports=window.wp.coreData});var R=x((ut,oe)=>{"use strict";oe.exports=window.wp.components});var T=x((gt,re)=>{"use strict";re.exports=window.wp.i18n});var $=x((ft,ne)=>{"use strict";ne.exports=window.wp.blockEditor});var H=x((yt,le)=>{"use strict";le.exports=window.wp.element});var K=x((xt,ce)=>{"use strict";ce.exports=window.wp.blocks});var h=x((kt,pe)=>{"use strict";pe.exports=window.ReactJSXRuntime});var fe=x((Bt,ge)=>{"use strict";ge.exports=window.wp.editor});var be=x((Ft,ye)=>{"use strict";ye.exports=window.wp.mediaUtils});var we=x((Tt,Ee)=>{"use strict";Ee.exports=function t(e,s){if(e===s)return!0;if(e&&s&&typeof e=="object"&&typeof s=="object"){if(e.constructor!==s.constructor)return!1;var r,o,l;if(Array.isArray(e)){if(r=e.length,r!=s.length)return!1;for(o=r;o--!==0;)if(!t(e[o],s[o]))return!1;return!0}if(e instanceof Map&&s instanceof Map){if(e.size!==s.size)return!1;for(o of e.entries())if(!s.has(o[0]))return!1;for(o of e.entries())if(!t(o[1],s.get(o[0])))return!1;return!0}if(e instanceof Set&&s instanceof Set){if(e.size!==s.size)return!1;for(o of e.entries())if(!s.has(o[0]))return!1;return!0}if(ArrayBuffer.isView(e)&&ArrayBuffer.isView(s)){if(r=e.length,r!=s.length)return!1;for(o=r;o--!==0;)if(e[o]!==s[o])return!1;return!0}if(e.constructor===RegExp)return e.source===s.source&&e.flags===s.flags;if(e.valueOf!==Object.prototype.valueOf)return e.valueOf()===s.valueOf();if(e.toString!==Object.prototype.toString)return e.toString()===s.toString();if(l=Object.keys(e),r=l.length,r!==Object.keys(s).length)return!1;for(o=r;o--!==0;)if(!Object.prototype.hasOwnProperty.call(s,l[o]))return!1;for(o=r;o--!==0;){var n=l[o];if(!t(e[n],s[n]))return!1}return!0}return e!==e&&s!==s}});var X=a(D()),Z=a(V());function se(t){var e,s,r="";if(typeof t=="string"||typeof t=="number")r+=t;else if(typeof t=="object")if(Array.isArray(t)){var o=t.length;for(e=0;e<o;e++)t[e]&&(s=se(t[e]))&&(r&&(r+=" "),r+=s)}else for(s in t)t[s]&&(r&&(r+=" "),r+=s);return r}function Ne(){for(var t,e,s=0,r="",o=arguments.length;s<o;s++)(t=arguments[s])&&(e=se(t))&&(r&&(r+=" "),r+=e);return r}var A=Ne;var G=a(R()),O=a(T()),_=a($()),d=a(H());import{getStyle as Ce,getPalettes as Ze,generateGlobalStyles as Je}from"@spacepress-admin/global-styles-engine";var i=a(T()),I=[{slug:"theme-colors",title:(0,i.__)("Theme Colors","spacepress-admin"),origin:"theme",type:"colors"},{slug:"theme-gradients",title:(0,i.__)("Theme Gradients","spacepress-admin"),origin:"theme",type:"gradients"},{slug:"custom-colors",title:(0,i.__)("Custom Colors","spacepress-admin"),origin:"custom",type:"colors"},{slug:"custom-gradients",title:(0,i.__)("Custom Gradients","spacepress-admin"),origin:"custom",type:"gradients"},{slug:"duotones",title:(0,i.__)("Duotones","spacepress-admin"),origin:"theme",type:"duotones"},{slug:"default-colors",title:(0,i.__)("Default Colors","spacepress-admin"),origin:"default",type:"colors"},{slug:"default-gradients",title:(0,i.__)("Default Gradients","spacepress-admin"),origin:"default",type:"gradients"}],Y=[{slug:"site-identity",title:(0,i.__)("Site Identity","spacepress-admin"),blocks:["core/site-logo","core/site-title","core/site-tagline"]},{slug:"design",title:(0,i.__)("Design","spacepress-admin"),blocks:["core/navigation","core/avatar","core/post-time-to-read"],exclude:["core/home-link","core/navigation-link"]},{slug:"posts",title:(0,i.__)("Posts","spacepress-admin"),blocks:["core/post-title","core/post-excerpt","core/post-author","core/post-author-name","core/post-author-biography","core/post-date","core/post-terms","core/term-description","core/query-title","core/query-no-results","core/query-pagination","core/query-numbers"]},{slug:"comments",title:(0,i.__)("Comments","spacepress-admin"),blocks:["core/comments-title","core/comments-pagination","core/comments-pagination-numbers","core/comments","core/comments-author-name","core/comment-content","core/comment-date","core/comment-edit-link","core/comment-reply-link","core/comment-template","core/post-comments-count","core/post-comments-link"]}],Ue=[{slug:"overview",title:(0,i.__)("Overview","spacepress-admin"),blocks:[]},{slug:"text",title:(0,i.__)("Text","spacepress-admin"),blocks:["core/post-content","core/home-link","core/navigation-link"]},{slug:"colors",title:(0,i.__)("Colors","spacepress-admin"),blocks:[]},{slug:"theme",title:(0,i.__)("Theme","spacepress-admin"),subcategories:Y},{slug:"media",title:(0,i.__)("Media","spacepress-admin"),blocks:["core/post-featured-image"]},{slug:"widgets",title:(0,i.__)("Widgets","spacepress-admin"),blocks:[]},{slug:"embed",title:(0,i.__)("Embeds","spacepress-admin"),include:[]}],We=[...Y,{slug:"media",title:(0,i.__)("Media","spacepress-admin"),blocks:["core/post-featured-image"]},{slug:"widgets",title:(0,i.__)("Widgets","spacepress-admin"),blocks:[]},{slug:"embed",title:(0,i.__)("Embeds","spacepress-admin"),include:[]}],ae=[{slug:"overview",title:(0,i.__)("Overview","spacepress-admin"),blocks:[]},{slug:"text",title:(0,i.__)("Text","spacepress-admin"),blocks:["core/post-content","core/home-link","core/navigation-link"]},{slug:"colors",title:(0,i.__)("Colors","spacepress-admin"),blocks:[]},{slug:"blocks",title:(0,i.__)("All Blocks","spacepress-admin"),blocks:[],subcategories:We}],De=`
	.is-root-container {
		display: flow-root;
	}
`,ie=`
	body {
		position: relative;
		padding: 32px !important;
	}

	${De}

	.spacepress-admin-style-book__examples {
		max-width: 1200px;
		margin: 0 auto;
	}

	.spacepress-admin-style-book__example {
	    max-width: 900px;
		border-radius: 2px;
		cursor: pointer;
		display: flex;
		flex-direction: column;
		gap: 40px;
		padding: 16px;
		width: 100%;
		box-sizing: border-box;
		scroll-margin-top: 32px;
		scroll-margin-bottom: 32px;
		margin: 0 auto 40px auto;
	}

	.spacepress-admin-style-book__example.is-selected {
		box-shadow: 0 0 0 1px var(--wpds-color-stroke-focus);
	}

	.spacepress-admin-style-book__example.is-disabled-example {
		pointer-events: none;
	}

	.spacepress-admin-style-book__example:focus:not(:disabled) {
		box-shadow: 0 0 0 var(--wpds-border-width-focus) var(--wpds-color-stroke-focus);
		outline: 3px solid transparent;
	}

	.spacepress-admin-style-book__duotone-example > div:first-child {
		display: flex;
		aspect-ratio: 16 / 9;
		grid-row: span 1;
		grid-column: span 2;
	}
	.spacepress-admin-style-book__duotone-example img {
		width: 100%;
		height: 100%;
		object-fit: cover;
	}
	.spacepress-admin-style-book__duotone-example > div:not(:first-child) {
		height: 20px;
		border: 1px solid color-mix( in srgb, currentColor 10%, transparent );
	}

	.spacepress-admin-style-book__color-example {
		border: 1px solid color-mix( in srgb, currentColor 10%, transparent );
	}

	.spacepress-admin-style-book__subcategory-title,
	.spacepress-admin-style-book__example-title {
		font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
		font-size: 13px;
		font-weight: normal;
		line-height: normal;
		margin: 0;
		text-align: left;
		padding-top: 8px;
		border-top: 1px solid color-mix( in srgb, currentColor 10%, transparent );
		color: color-mix( in srgb, currentColor 60%, transparent );
	}

	.spacepress-admin-style-book__subcategory-title {
		font-size: 16px;
		margin-bottom: 40px;
    	padding-bottom: 8px;
	}

	.spacepress-admin-style-book__example-preview {
		width: 100%;
	}

	.spacepress-admin-style-book__example-preview .block-editor-block-list__insertion-point,
	.spacepress-admin-style-book__example-preview .block-list-appender {
		display: none;
	}
	:where(.is-root-container > .wp-block:first-child) {
		margin-top: 0;
	}
	:where(.is-root-container > .wp-block:last-child) {
		margin-bottom: 0;
	}
`;var Ve=a(K());function L(t,e){if(!t?.slug||!e?.length)return;let s=t?.subcategories??[];if(s.length){let n=s.map(p=>L(p,e)).filter(p=>p!==void 0);return{title:t.title,slug:t.slug,subcategories:n.length>0?n:void 0}}let r=t?.blocks||[],o=t?.exclude||[],l=e.filter(n=>!o.includes(n.name)&&(n.category===t.slug||r.includes(n.name)));if(l.length)return{title:t.title,slug:t.slug,examples:l}}var k=a(T()),f=a(K());var me=a(R()),M=a($()),z=a(h()),$e=({colors:t,type:e,templateColumns:s="1fr 1fr",itemHeight:r="52px"})=>t?(0,z.jsx)(me.__experimentalGrid,{templateColumns:s,rowGap:8,columnGap:16,children:t.map(o=>{let l=e==="gradients"?(0,M.__experimentalGetGradientClass)(o.slug):(0,M.getColorClassName)("background-color",o.slug),n=A("spacepress-admin-style-book__color-example",l);return(0,z.jsx)("div",{className:n,style:{height:r}},o.slug)})}):null,q=$e;var j=a(R()),C=a(h()),He=({duotones:t})=>t?(0,C.jsx)(j.__experimentalGrid,{columns:2,rowGap:16,columnGap:16,children:t.map(e=>(0,C.jsxs)(j.__experimentalGrid,{className:"spacepress-admin-style-book__duotone-example",columns:2,rowGap:8,columnGap:8,children:[(0,C.jsx)("div",{children:(0,C.jsx)("img",{alt:`Duotone example: ${e.slug}`,src:"https://s.w.org/images/core/5.3/MtBlanc1.jpg",style:{filter:`url(#wp-duotone-${e.slug})`}})}),e.colors.map(s=>(0,C.jsx)("div",{className:"spacepress-admin-style-book__color-example",style:{backgroundColor:s}},s))]},e.slug))}):null,de=He;var N=a(h());function Ye(t){if(!t)return[];let e=[];return I.forEach(s=>{let r=t[s.type],o=Array.isArray(r)?r.find(l=>l.slug===s.origin):void 0;if(o?.[s.type]){let l={name:s.slug,title:s.title,category:"colors",blocks:null};s.type==="duotones"?(l.content=(0,N.jsx)(de,{duotones:o[s.type]}),e.push(l)):(l.content=(0,N.jsx)(q,{colors:o[s.type],type:s.type}),e.push(l))}}),e}function Ke(t){let e=[],s=Array.isArray(t?.colors)?t.colors.find(l=>l.slug==="theme")?.colors:void 0;if(s){let l={name:"theme-colors",title:(0,k.__)("Colors","spacepress-admin"),category:"overview",blocks:null,content:(0,N.jsx)(q,{colors:s,type:"colors",templateColumns:"repeat(auto-fill, minmax( 200px, 1fr ))",itemHeight:"32px"})};e.push(l)}let r=[];if((0,f.getBlockType)("core/heading")){let l=(0,f.createBlock)("core/heading",{content:(0,k.__)("AaBbCcDdEeFfGgHhIiJjKkLlMmNnOoPpQqRrSsTtUuVvWwXxYyZz0123456789X{(\u2026)},.-<>?!*&:/A@HELFO\u2122\xA9","spacepress-admin"),level:1});r.push(l)}if((0,f.getBlockType)("core/paragraph")){let l=(0,f.createBlock)("core/paragraph",{content:(0,k.__)("A paragraph in a website refers to a distinct block of text that is used to present and organize information. It is a fundamental unit of content in web design and is typically composed of a group of related sentences or thoughts focused on a particular topic or idea. Paragraphs play a crucial role in improving the readability and user experience of a website. They break down the text into smaller, manageable chunks, allowing readers to scan the content more easily.","spacepress-admin")}),n=(0,f.createBlock)("core/paragraph",{content:(0,k.__)("Additionally, paragraphs help structure the flow of information and provide logical breaks between different concepts or pieces of information. In terms of formatting, paragraphs in websites are commonly denoted by a vertical gap or indentation between each block of text. This visual separation helps visually distinguish one paragraph from another, creating a clear and organized layout that guides the reader through the content smoothly.","spacepress-admin")});if((0,f.getBlockType)("core/group")){let p=(0,f.createBlock)("core/group",{layout:{type:"grid",columnCount:2,minimumColumnWidth:"12rem"},style:{spacing:{blockGap:"1.5rem"}}},[l,n]);r.push(p)}else r.push(l)}return r.length&&e.push({name:"typography",title:(0,k.__)("Typography","spacepress-admin"),category:"overview",blocks:r}),["core/image","core/separator","core/buttons","core/pullquote","core/search"].forEach(l=>{let n=(0,f.getBlockType)(l);if(n&&n.example){let p={name:l,title:n.title,category:"overview",blocks:(0,f.getBlockFromExample)(l,{...n.example,attributes:{...n.example?.attributes,style:void 0}})};e.push(p)}}),e}function ue(t){let e=(0,f.getBlockTypes)().filter(n=>{let{name:p,example:u,supports:E}=n;return p!=="core/heading"&&!!u&&E?.inserter!==!1}).map(n=>({name:n.name,title:n.title,category:n.category,blocks:(0,f.getBlockFromExample)(n.name,{...n.example,attributes:{...n.example?.attributes,style:void 0}})}));if(!!!(0,f.getBlockType)("core/heading"))return e;let r={name:"core/heading",title:(0,k.__)("Headings","spacepress-admin"),category:"text",blocks:[1,2,3,4,5,6].map(n=>(0,f.createBlock)("core/heading",{content:(0,k.sprintf)((0,k.__)("Heading %d","spacepress-admin"),n),level:n}))},o=Ye(t),l=Ke(t);return[r,...o,...e,...l]}if(!document.head.querySelector("style[data-wp-hash='3660ad12cc']")){let t=document.createElement("style");t.setAttribute("data-wp-hash","3660ad12cc"),t.appendChild(document.createTextNode(`.spacepress-admin-style-book {
  width: 100%;
  height: 100%;
}

.spacepress-admin-style-book.is-button {
  border-radius: 8px;
}

.spacepress-admin-style-book {
  flex-direction: column;
  align-items: stretch;
  display: flex;
}

.spacepress-admin-style-book__iframe {
  width: 100%;
  height: 100%;
  display: block;
}

.spacepress-admin-style-book__iframe.is-button {
  border-radius: 8px;
}

.spacepress-admin-style-book__iframe.is-focused {
  outline: calc(2 * var(--wpds-border-width-focus)) solid var(--wpds-color-stroke-focus);
  outline-offset: calc(-2 * var(--wpds-border-width-focus));
}
`)),document.head.appendChild(t)}var U=a(V()),W=a(D()),P=a(H()),xe=a(fe()),he=a(be());import{useNavigate as ze,useSearch as qe}from"@wordpress/route";import{mergeGlobalStyles as je}from"@spacepress-admin/global-styles-engine";function Se(t){let{userGlobalStyles:e}=(0,W.useSelect)(r=>{let{getEntityRecord:o,getEditedEntityRecord:l,canUser:n}=r(U.store),p=n("update",{kind:"root",name:"globalStyles",id:t}),u;return typeof p=="boolean"&&(p?u=l("root","globalStyles",t):u=o("root","globalStyles",t,{context:"view"})),{userGlobalStyles:u}},[t]),s=(0,W.useSelect)(r=>r(U.store).__experimentalGetCurrentThemeBaseGlobalStyles(),[]);return(0,P.useMemo)(()=>{if(!e||!s)return{user:void 0,base:void 0,merged:void 0};let r=e,o=s,l=je(o,r);return{user:r,base:o,merged:l}},[e,s])}function ke(){let{styles:t,__unstableResolvedAssets:e,colors:s,gradients:r,__experimentalDiscussionSettings:o,mediaUpload:l}=(0,W.useSelect)(u=>{let{getEditorSettings:E}=u(xe.store),{canUser:w}=u(U.store),y=E(),b=w("create",{kind:"root",name:"media"});return{styles:y.styles,__unstableResolvedAssets:y.__unstableResolvedAssets,colors:y.colors,gradients:y.gradients,__experimentalDiscussionSettings:y.__experimentalDiscussionSettings,mediaUpload:b?he.uploadMedia:void 0}},[]),n=(0,P.useMemo)(()=>t?Object.values(t??[]).filter(u=>!u.isGlobalStyles):[],[t]),p=(0,P.useMemo)(()=>({__unstableResolvedAssets:e,settings:{color:{palette:{theme:s},gradients:{theme:r},duotone:{theme:[]}}},__experimentalDiscussionSettings:o,mediaUpload:l}),[s,r,e,o,l]);return[n,p]}function _e(){let t=ze(),e=qe({from:"/design/styles"});return(0,P.useMemo)(()=>[e.section,o=>{t({search:{...e,section:o}})}],[t,e])}var Xe=a(we());function ve(t){return t?`is-style-${t}`:""}var c=a(h()),Qe=t=>t&&typeof t=="string"&&(t==="/"||t.startsWith("/typography")||t.startsWith("/colors")||t.startsWith("/blocks"))?{top:!0}:null;function et(t,e){return e?t.map(s=>({...s,variation:e,blocks:{...s.blocks,attributes:{...s.blocks.attributes,style:void 0,className:ve(e)}}})):t}function tt(t){let e=[],s=L({slug:"overview",title:"Overview"},t);s?.examples&&e.push(...s.examples);let r=t.filter(o=>o.category!=="overview"&&!s?.examples?.find(l=>l.name===o.name));return e.push(...r),e}var st=(t,e)=>{if(!t||!e||!e?.contentDocument)return;let s=t==="top"?e.contentDocument.body:e.contentDocument.getElementById(t);s&&s.scrollIntoView({behavior:"smooth"})},ot=({id:t})=>{let{merged:e}=Se(t),[s,r]=ke(),[o,l]=_e(),n=m=>!!(o===`/blocks/${encodeURIComponent(m)}`||o?.startsWith(`/blocks/${encodeURIComponent(m)}/`)),p=(m,v=!1)=>{if(I.find(Pe=>Pe.slug===m)){l("/colors/palette");return}if(m==="typography"){l("/typography");return}v||l(`/blocks/${encodeURIComponent(m)}`)},u=(0,d.useMemo)(()=>Ce(e,"color.text"),[e]),E=(0,d.useMemo)(()=>Ce(e,"color.background"),[e]),w=(0,d.useMemo)(()=>e||{settings:r?.settings},[e,r]),y=(0,d.useMemo)(()=>Ze(w),[w]),b=(0,d.useMemo)(()=>y?ue(y):[],[y]),g=null,B=null;if(o?.includes("/colors"))g="colors";else if(o?.includes("/typography"))g="text";else if(o?.includes("/blocks")){g="blocks";let m=decodeURIComponent(o).split("/blocks/")[1];m?.includes("/variations")&&([m,B]=m.split("/variations/")),m&&b.find(v=>v.name===m)&&(g=m)}else t&&(g="overview");let S=ae.find(m=>m.slug===g),F=(0,d.useMemo)(()=>{if(!S){let m=b.find(v=>v.name===g);return{slug:g||"unknown",title:g||"Unknown",examples:m?[m]:[]}}return L(S,b)},[S,b,g]),J=(0,d.useMemo)(()=>{if(!g)return{slug:"all",title:"All Examples",examples:tt(b)};if(B){let m=(F?.examples||[]).filter(v=>v!==void 0);return{slug:g,title:S?.title||g,examples:et(m,B)}}return F||{slug:"",title:"",examples:[]}},[g,b,B,F,S]),Fe=(0,d.useMemo)(()=>Qe(o),[o]);return!w||!J?null:(0,c.jsx)("div",{className:"spacepress-admin-style-book",style:{color:u,background:E},children:(0,c.jsx)(rt,{category:S,examples:J,isSelected:t?n:void 0,onSelect:t?p:void 0,globalStyles:e,serverCSS:s,serverSettings:r,goTo:Fe})})},rt=({category:t,examples:e,isSelected:s,onSelect:r,globalStyles:o,title:l="",serverCSS:n,serverSettings:p,goTo:u})=>{let[E,w]=Je(o),y=(0,d.useMemo)(()=>[...n??[],...E??[]],[n,E]),b=(0,d.useMemo)(()=>({...p,__experimentalFeatures:w,styles:y,isPreviewMode:!0}),[w,p,y]),[g,B]=(0,d.useState)(!1),S=(0,d.useRef)(null),F=()=>B(!0);return(0,d.useLayoutEffect)(()=>{g&&S?.current&&u?.top&&st("top",S?.current)},[u,g]),(0,c.jsx)(_.BlockEditorProvider,{settings:b,children:(0,c.jsxs)(_.__unstableIframe,{className:"spacepress-admin-style-book__iframe",name:"style-book-canvas",tabIndex:0,onLoad:F,ref:S,children:[(0,c.jsx)(_.__unstableEditorStyles,{styles:y}),(0,c.jsx)("style",{children:ie}),(0,c.jsx)(nt,{className:"spacepress-admin-style-book__examples",category:t,examples:e,label:l?(0,O.sprintf)((0,O.__)("Examples of blocks in the %s category","spacepress-admin"),l):(0,O.__)("Examples of blocks","spacepress-admin"),isSelected:s,onSelect:r,settings:b},l)]})})},nt=(0,d.memo)(({className:t,examples:e,label:s,isSelected:r,onSelect:o,settings:l})=>(0,c.jsxs)(G.Composite,{orientation:"vertical",className:t,"aria-label":s,role:"grid",children:[e.examples?.length&&e.examples.map(n=>(0,c.jsx)(Ge,{id:`example-${n.name}`,title:n.title,content:n.content,blocks:n.blocks,isSelected:r?.(n.name),onClick:o?()=>o(n.name,!!n.variation):void 0,settings:l},n.name)),e.subcategories?.length&&e.subcategories?.map(n=>(0,c.jsxs)(G.Composite.Group,{className:"spacepress-admin-style-book__subcategory",children:[(0,c.jsx)(G.Composite.GroupLabel,{children:(0,c.jsx)("h2",{className:"spacepress-admin-style-book__subcategory-title",children:n.title})}),(0,c.jsx)(lt,{examples:n,isSelected:r,onSelect:o,settings:l})]},`subcategory-${n.slug}`))]})),lt=({examples:t,isSelected:e,onSelect:s,settings:r})=>!!t?.examples?.length&&t.examples.map(o=>(0,c.jsx)(Ge,{id:`example-${o.name}`,title:o.title,content:o.content,blocks:o.blocks,isSelected:e?.(o.name),onClick:s?()=>s(o.name):void 0,settings:r},o.name)),at=["example-duotones"],Ge=({id:t,title:e,blocks:s,isSelected:r,onClick:o,content:l,settings:n})=>{let p=(0,d.useMemo)(()=>Array.isArray(s)?s:[s],[s]),u=at.includes(t)||!o?{disabled:!0,accessibleWhenDisabled:!!o}:{};return(0,c.jsx)("div",{role:"row",children:(0,c.jsx)("div",{role:"gridcell",children:(0,c.jsxs)(G.Composite.Item,{className:A("spacepress-admin-style-book__example",{"is-selected":r,"is-disabled-example":!!u?.disabled}),id:t,"aria-label":o?(0,O.sprintf)((0,O.__)("Open %s styles in Styles panel","spacepress-admin"),e):void 0,render:(0,c.jsx)("div",{}),role:o?"button":void 0,onClick:o,...u,children:[(0,c.jsx)("span",{className:"spacepress-admin-style-book__example-title",children:e}),(0,c.jsx)("div",{className:"spacepress-admin-style-book__example-preview","aria-hidden":!0,children:(0,c.jsx)(G.Disabled,{className:"spacepress-admin-style-book__example-preview__content",children:l||(0,c.jsx)(_.BlockEditorProvider,{value:p,settings:n,children:(0,c.jsx)(_.BlockList,{renderAppender:!1})})})})]})})})},Oe=ot;var Be=a(h());function it({postId:t}){return(0,Be.jsx)(Oe,{id:t})}var Kt={canvas:async({search:t})=>{let e=await(0,X.resolveSelect)(Z.store).__experimentalGetCurrentGlobalStylesId();return t.preview==="stylebook"||!e?{postType:"wp_global_styles",postId:e,component:it,isPreview:!0}:{isPreview:!0}},inspector:async()=>!!await(0,X.resolveSelect)(Z.store).__experimentalGetCurrentGlobalStylesId()};export{Kt as route};
