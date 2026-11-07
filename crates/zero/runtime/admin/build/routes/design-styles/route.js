import '__i18n__';
var Pe=Object.create;var Q=Object.defineProperty;var Ae=Object.getOwnPropertyDescriptor;var Re=Object.getOwnPropertyNames;var Te=Object.getPrototypeOf,Ie=Object.prototype.hasOwnProperty;var x=(t,e)=>()=>(e||t((e={exports:{}}).exports,e),e.exports);var Le=(t,e,o,n)=>{if(e&&typeof e=="object"||typeof e=="function")for(let r of Re(e))!Ie.call(t,r)&&r!==o&&Q(t,r,{get:()=>e[r],enumerable:!(n=Ae(e,r))||n.enumerable});return t};var i=(t,e,o)=>(o=t!=null?Pe(Te(t)):{},Le(e||!t||!t.__esModule?Q(o,"default",{value:t,enumerable:!0}):o,t));var W=x((mt,ee)=>{"use strict";ee.exports=window.wp.data});var D=x((pt,te)=>{"use strict";te.exports=window.wp.coreData});var A=x((ut,re)=>{"use strict";re.exports=window.wp.components});var R=x((gt,ne)=>{"use strict";ne.exports=window.wp.i18n});var V=x((ft,se)=>{"use strict";se.exports=window.wp.blockEditor});var $=x((yt,le)=>{"use strict";le.exports=window.wp.element});var Y=x((xt,ce)=>{"use strict";ce.exports=window.wp.blocks});var h=x((kt,me)=>{"use strict";me.exports=window.ReactJSXRuntime});var fe=x((Ot,ge)=>{"use strict";ge.exports=window.wp.editor});var be=x((Bt,ye)=>{"use strict";ye.exports=window.wp.mediaUtils});var we=x((Rt,Ee)=>{"use strict";Ee.exports=function t(e,o){if(e===o)return!0;if(e&&o&&typeof e=="object"&&typeof o=="object"){if(e.constructor!==o.constructor)return!1;var n,r,l;if(Array.isArray(e)){if(n=e.length,n!=o.length)return!1;for(r=n;r--!==0;)if(!t(e[r],o[r]))return!1;return!0}if(e instanceof Map&&o instanceof Map){if(e.size!==o.size)return!1;for(r of e.entries())if(!o.has(r[0]))return!1;for(r of e.entries())if(!t(r[1],o.get(r[0])))return!1;return!0}if(e instanceof Set&&o instanceof Set){if(e.size!==o.size)return!1;for(r of e.entries())if(!o.has(r[0]))return!1;return!0}if(ArrayBuffer.isView(e)&&ArrayBuffer.isView(o)){if(n=e.length,n!=o.length)return!1;for(r=n;r--!==0;)if(e[r]!==o[r])return!1;return!0}if(e.constructor===RegExp)return e.source===o.source&&e.flags===o.flags;if(e.valueOf!==Object.prototype.valueOf)return e.valueOf()===o.valueOf();if(e.toString!==Object.prototype.toString)return e.toString()===o.toString();if(l=Object.keys(e),n=l.length,n!==Object.keys(o).length)return!1;for(r=n;r--!==0;)if(!Object.prototype.hasOwnProperty.call(o,l[r]))return!1;for(r=n;r--!==0;){var s=l[r];if(!t(e[s],o[s]))return!1}return!0}return e!==e&&o!==o}});var X=i(W()),Z=i(D());function oe(t){var e,o,n="";if(typeof t=="string"||typeof t=="number")n+=t;else if(typeof t=="object")if(Array.isArray(t)){var r=t.length;for(e=0;e<r;e++)t[e]&&(o=oe(t[e]))&&(n&&(n+=" "),n+=o)}else for(o in t)t[o]&&(n&&(n+=" "),n+=o);return n}function Me(){for(var t,e,o=0,n="",r=arguments.length;o<r;o++)(t=arguments[o])&&(e=oe(t))&&(n&&(n+=" "),n+=e);return n}var P=Me;var z=i(A()),G=i(R()),_=i(V()),d=i($());import{getStyle as Ce,getPalettes as Ze,generateGlobalStyles as Je}from"@zero-admin/global-styles-engine";var a=i(R()),T=[{slug:"theme-colors",title:(0,a.__)("Theme Colors","zero-admin"),origin:"theme",type:"colors"},{slug:"theme-gradients",title:(0,a.__)("Theme Gradients","zero-admin"),origin:"theme",type:"gradients"},{slug:"custom-colors",title:(0,a.__)("Custom Colors","zero-admin"),origin:"custom",type:"colors"},{slug:"custom-gradients",title:(0,a.__)("Custom Gradients","zero-admin"),origin:"custom",type:"gradients"},{slug:"duotones",title:(0,a.__)("Duotones","zero-admin"),origin:"theme",type:"duotones"},{slug:"default-colors",title:(0,a.__)("Default Colors","zero-admin"),origin:"default",type:"colors"},{slug:"default-gradients",title:(0,a.__)("Default Gradients","zero-admin"),origin:"default",type:"gradients"}],H=[{slug:"site-identity",title:(0,a.__)("Site Identity","zero-admin"),blocks:["core/site-logo","core/site-title","core/site-tagline"]},{slug:"design",title:(0,a.__)("Design","zero-admin"),blocks:["core/navigation","core/avatar","core/post-time-to-read"],exclude:["core/home-link","core/navigation-link"]},{slug:"posts",title:(0,a.__)("Posts","zero-admin"),blocks:["core/post-title","core/post-excerpt","core/post-author","core/post-author-name","core/post-author-biography","core/post-date","core/post-terms","core/term-description","core/query-title","core/query-no-results","core/query-pagination","core/query-numbers"]},{slug:"comments",title:(0,a.__)("Comments","zero-admin"),blocks:["core/comments-title","core/comments-pagination","core/comments-pagination-numbers","core/comments","core/comments-author-name","core/comment-content","core/comment-date","core/comment-edit-link","core/comment-reply-link","core/comment-template","core/post-comments-count","core/post-comments-link"]}],Ne=[{slug:"overview",title:(0,a.__)("Overview","zero-admin"),blocks:[]},{slug:"text",title:(0,a.__)("Text","zero-admin"),blocks:["core/post-content","core/home-link","core/navigation-link"]},{slug:"colors",title:(0,a.__)("Colors","zero-admin"),blocks:[]},{slug:"theme",title:(0,a.__)("Theme","zero-admin"),subcategories:H},{slug:"media",title:(0,a.__)("Media","zero-admin"),blocks:["core/post-featured-image"]},{slug:"widgets",title:(0,a.__)("Widgets","zero-admin"),blocks:[]},{slug:"embed",title:(0,a.__)("Embeds","zero-admin"),include:[]}],Ue=[...H,{slug:"media",title:(0,a.__)("Media","zero-admin"),blocks:["core/post-featured-image"]},{slug:"widgets",title:(0,a.__)("Widgets","zero-admin"),blocks:[]},{slug:"embed",title:(0,a.__)("Embeds","zero-admin"),include:[]}],ie=[{slug:"overview",title:(0,a.__)("Overview","zero-admin"),blocks:[]},{slug:"text",title:(0,a.__)("Text","zero-admin"),blocks:["core/post-content","core/home-link","core/navigation-link"]},{slug:"colors",title:(0,a.__)("Colors","zero-admin"),blocks:[]},{slug:"blocks",title:(0,a.__)("All Blocks","zero-admin"),blocks:[],subcategories:Ue}],We=`
	.is-root-container {
		display: flow-root;
	}
`,ae=`
	body {
		position: relative;
		padding: 32px !important;
	}

	${We}

	.zero-admin-style-book__examples {
		max-width: 1200px;
		margin: 0 auto;
	}

	.zero-admin-style-book__example {
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

	.zero-admin-style-book__example.is-selected {
		box-shadow: 0 0 0 1px var(--wpds-color-stroke-focus-brand);
	}

	.zero-admin-style-book__example.is-disabled-example {
		pointer-events: none;
	}

	.zero-admin-style-book__example:focus:not(:disabled) {
		box-shadow: 0 0 0 var(--wpds-border-width-focus) var(--wpds-color-stroke-focus-brand);
		outline: 3px solid transparent;
	}

	.zero-admin-style-book__duotone-example > div:first-child {
		display: flex;
		aspect-ratio: 16 / 9;
		grid-row: span 1;
		grid-column: span 2;
	}
	.zero-admin-style-book__duotone-example img {
		width: 100%;
		height: 100%;
		object-fit: cover;
	}
	.zero-admin-style-book__duotone-example > div:not(:first-child) {
		height: 20px;
		border: 1px solid color-mix( in srgb, currentColor 10%, transparent );
	}

	.zero-admin-style-book__color-example {
		border: 1px solid color-mix( in srgb, currentColor 10%, transparent );
	}

	.zero-admin-style-book__subcategory-title,
	.zero-admin-style-book__example-title {
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

	.zero-admin-style-book__subcategory-title {
		font-size: 16px;
		margin-bottom: 40px;
    	padding-bottom: 8px;
	}

	.zero-admin-style-book__example-preview {
		width: 100%;
	}

	.zero-admin-style-book__example-preview .block-editor-block-list__insertion-point,
	.zero-admin-style-book__example-preview .block-list-appender {
		display: none;
	}
	:where(.is-root-container > .wp-block:first-child) {
		margin-top: 0;
	}
	:where(.is-root-container > .wp-block:last-child) {
		margin-bottom: 0;
	}
`;var De=i(Y());function I(t,e){if(!t?.slug||!e?.length)return;let o=t?.subcategories??[];if(o.length){let s=o.map(m=>I(m,e)).filter(m=>m!==void 0);return{title:t.title,slug:t.slug,subcategories:s.length>0?s:void 0}}let n=t?.blocks||[],r=t?.exclude||[],l=e.filter(s=>!r.includes(s.name)&&(s.category===t.slug||n.includes(s.name)));if(l.length)return{title:t.title,slug:t.slug,examples:l}}var k=i(R()),f=i(Y());var pe=i(A()),L=i(V()),K=i(h()),Ve=({colors:t,type:e,templateColumns:o="1fr 1fr",itemHeight:n="52px"})=>t?(0,K.jsx)(pe.__experimentalGrid,{templateColumns:o,rowGap:8,columnGap:16,children:t.map(r=>{let l=e==="gradients"?(0,L.__experimentalGetGradientClass)(r.slug):(0,L.getColorClassName)("background-color",r.slug),s=P("zero-admin-style-book__color-example",l);return(0,K.jsx)("div",{className:s,style:{height:n}},r.slug)})}):null,q=Ve;var j=i(A()),C=i(h()),$e=({duotones:t})=>t?(0,C.jsx)(j.__experimentalGrid,{columns:2,rowGap:16,columnGap:16,children:t.map(e=>(0,C.jsxs)(j.__experimentalGrid,{className:"zero-admin-style-book__duotone-example",columns:2,rowGap:8,columnGap:8,children:[(0,C.jsx)("div",{children:(0,C.jsx)("img",{alt:`Duotone example: ${e.slug}`,src:"https://s.w.org/images/core/5.3/MtBlanc1.jpg",style:{filter:`url(#wp-duotone-${e.slug})`}})}),e.colors.map(o=>(0,C.jsx)("div",{className:"zero-admin-style-book__color-example",style:{backgroundColor:o}},o))]},e.slug))}):null,de=$e;var M=i(h());function He(t){if(!t)return[];let e=[];return T.forEach(o=>{let n=t[o.type],r=Array.isArray(n)?n.find(l=>l.slug===o.origin):void 0;if(r?.[o.type]){let l={name:o.slug,title:o.title,category:"colors",blocks:null};o.type==="duotones"?(l.content=(0,M.jsx)(de,{duotones:r[o.type]}),e.push(l)):(l.content=(0,M.jsx)(q,{colors:r[o.type],type:o.type}),e.push(l))}}),e}function Ye(t){let e=[],o=Array.isArray(t?.colors)?t.colors.find(l=>l.slug==="theme")?.colors:void 0;if(o){let l={name:"theme-colors",title:(0,k.__)("Colors","zero-admin"),category:"overview",blocks:null,content:(0,M.jsx)(q,{colors:o,type:"colors",templateColumns:"repeat(auto-fill, minmax( 200px, 1fr ))",itemHeight:"32px"})};e.push(l)}let n=[];if((0,f.getBlockType)("core/heading")){let l=(0,f.createBlock)("core/heading",{content:(0,k.__)("AaBbCcDdEeFfGgHhIiJjKkLlMmNnOoPpQqRrSsTtUuVvWwXxYyZz0123456789X{(\u2026)},.-<>?!*&:/A@HELFO\u2122\xA9","zero-admin"),level:1});n.push(l)}if((0,f.getBlockType)("core/paragraph")){let l=(0,f.createBlock)("core/paragraph",{content:(0,k.__)("A paragraph in a website refers to a distinct block of text that is used to present and organize information. It is a fundamental unit of content in web design and is typically composed of a group of related sentences or thoughts focused on a particular topic or idea. Paragraphs play a crucial role in improving the readability and user experience of a website. They break down the text into smaller, manageable chunks, allowing readers to scan the content more easily.","zero-admin")}),s=(0,f.createBlock)("core/paragraph",{content:(0,k.__)("Additionally, paragraphs help structure the flow of information and provide logical breaks between different concepts or pieces of information. In terms of formatting, paragraphs in websites are commonly denoted by a vertical gap or indentation between each block of text. This visual separation helps visually distinguish one paragraph from another, creating a clear and organized layout that guides the reader through the content smoothly.","zero-admin")});if((0,f.getBlockType)("core/group")){let m=(0,f.createBlock)("core/group",{layout:{type:"grid",columnCount:2,minimumColumnWidth:"12rem"},style:{spacing:{blockGap:"1.5rem"}}},[l,s]);n.push(m)}else n.push(l)}return n.length&&e.push({name:"typography",title:(0,k.__)("Typography","zero-admin"),category:"overview",blocks:n}),["core/image","core/separator","core/buttons","core/pullquote","core/search"].forEach(l=>{let s=(0,f.getBlockType)(l);if(s&&s.example){let m={name:l,title:s.title,category:"overview",blocks:(0,f.getBlockFromExample)(l,{...s.example,attributes:{...s.example?.attributes,style:void 0}})};e.push(m)}}),e}function ue(t){let e=(0,f.getBlockTypes)().filter(s=>{let{name:m,example:u,supports:E}=s;return m!=="core/heading"&&!!u&&E?.inserter!==!1}).map(s=>({name:s.name,title:s.title,category:s.category,blocks:(0,f.getBlockFromExample)(s.name,{...s.example,attributes:{...s.example?.attributes,style:void 0}})}));if(!!!(0,f.getBlockType)("core/heading"))return e;let n={name:"core/heading",title:(0,k.__)("Headings","zero-admin"),category:"text",blocks:[1,2,3,4,5,6].map(s=>(0,f.createBlock)("core/heading",{content:(0,k.sprintf)((0,k.__)("Heading %d","zero-admin"),s),level:s}))},r=He(t),l=Ye(t);return[n,...r,...e,...l]}if(!document.head.querySelector("style[data-wp-hash='e203e8b8c1']")){let t=document.createElement("style");t.setAttribute("data-wp-hash","e203e8b8c1"),t.appendChild(document.createTextNode(`.zero-admin-style-book {
  width: 100%;
  height: 100%;
}

.zero-admin-style-book.is-button {
  border-radius: 8px;
}

.zero-admin-style-book {
  flex-direction: column;
  align-items: stretch;
  display: flex;
}

.zero-admin-style-book__iframe {
  width: 100%;
  height: 100%;
  display: block;
}

.zero-admin-style-book__iframe.is-button {
  border-radius: 8px;
}

.zero-admin-style-book__iframe.is-focused {
  outline: calc(2 * var(--wpds-border-width-focus)) solid var(--wpds-color-stroke-focus-brand);
  outline-offset: calc(-2 * var(--wpds-border-width-focus));
}
`)),document.head.appendChild(t)}var N=i(D()),U=i(W()),F=i($()),xe=i(fe()),he=i(be());import{useNavigate as Ke,useSearch as qe}from"@wordpress/route";import{mergeGlobalStyles as je}from"@zero-admin/global-styles-engine";function Se(t){let{userGlobalStyles:e}=(0,U.useSelect)(n=>{let{getEntityRecord:r,getEditedEntityRecord:l,canUser:s}=n(N.store),m=s("update",{kind:"root",name:"globalStyles",id:t}),u;return typeof m=="boolean"&&(m?u=l("root","globalStyles",t):u=r("root","globalStyles",t,{context:"view"})),{userGlobalStyles:u}},[t]),o=(0,U.useSelect)(n=>n(N.store).__experimentalGetCurrentThemeBaseGlobalStyles(),[]);return(0,F.useMemo)(()=>{if(!e||!o)return{user:void 0,base:void 0,merged:void 0};let n=e,r=o,l=je(r,n);return{user:n,base:r,merged:l}},[e,o])}function ke(){let{styles:t,__unstableResolvedAssets:e,colors:o,gradients:n,__experimentalDiscussionSettings:r,mediaUpload:l}=(0,U.useSelect)(u=>{let{getEditorSettings:E}=u(xe.store),{canUser:w}=u(N.store),y=E(),b=w("create",{kind:"root",name:"media"});return{styles:y.styles,__unstableResolvedAssets:y.__unstableResolvedAssets,colors:y.colors,gradients:y.gradients,__experimentalDiscussionSettings:y.__experimentalDiscussionSettings,mediaUpload:b?he.uploadMedia:void 0}},[]),s=(0,F.useMemo)(()=>t?Object.values(t??[]).filter(u=>!u.isGlobalStyles):[],[t]),m=(0,F.useMemo)(()=>({__unstableResolvedAssets:e,settings:{color:{palette:{theme:o},gradients:{theme:n},duotone:{theme:[]}}},__experimentalDiscussionSettings:r,mediaUpload:l}),[o,n,e,r,l]);return[s,m]}function _e(){let t=Ke(),e=qe({from:"/design/styles"});return(0,F.useMemo)(()=>[e.section,r=>{t({search:{...e,section:r}})}],[t,e])}var Xe=i(we());function ve(t){return t?`is-style-${t}`:""}var c=i(h()),Qe=t=>t&&typeof t=="string"&&(t==="/"||t.startsWith("/typography")||t.startsWith("/colors")||t.startsWith("/blocks"))?{top:!0}:null;function et(t,e){return e?t.map(o=>({...o,variation:e,blocks:{...o.blocks,attributes:{...o.blocks.attributes,style:void 0,className:ve(e)}}})):t}function tt(t){let e=[],o=I({slug:"overview",title:"Overview"},t);o?.examples&&e.push(...o.examples);let n=t.filter(r=>r.category!=="overview"&&!o?.examples?.find(l=>l.name===r.name));return e.push(...n),e}var ot=(t,e)=>{if(!t||!e||!e?.contentDocument)return;let o=t==="top"?e.contentDocument.body:e.contentDocument.getElementById(t);o&&o.scrollIntoView({behavior:"smooth"})},rt=({id:t})=>{let{merged:e}=Se(t),[o,n]=ke(),[r,l]=_e(),s=p=>!!(r===`/blocks/${encodeURIComponent(p)}`||r?.startsWith(`/blocks/${encodeURIComponent(p)}/`)),m=(p,v=!1)=>{if(T.find(Fe=>Fe.slug===p)){l("/colors/palette");return}if(p==="typography"){l("/typography");return}v||l(`/blocks/${encodeURIComponent(p)}`)},u=(0,d.useMemo)(()=>Ce(e,"color.text"),[e]),E=(0,d.useMemo)(()=>Ce(e,"color.background"),[e]),w=(0,d.useMemo)(()=>e||{settings:n?.settings},[e,n]),y=(0,d.useMemo)(()=>Ze(w),[w]),b=(0,d.useMemo)(()=>y?ue(y):[],[y]),g=null,O=null;if(r?.includes("/colors"))g="colors";else if(r?.includes("/typography"))g="text";else if(r?.includes("/blocks")){g="blocks";let p=decodeURIComponent(r).split("/blocks/")[1];p?.includes("/variations")&&([p,O]=p.split("/variations/")),p&&b.find(v=>v.name===p)&&(g=p)}else t&&(g="overview");let S=ie.find(p=>p.slug===g),B=(0,d.useMemo)(()=>{if(!S){let p=b.find(v=>v.name===g);return{slug:g||"unknown",title:g||"Unknown",examples:p?[p]:[]}}return I(S,b)},[S,b,g]),J=(0,d.useMemo)(()=>{if(!g)return{slug:"all",title:"All Examples",examples:tt(b)};if(O){let p=(B?.examples||[]).filter(v=>v!==void 0);return{slug:g,title:S?.title||g,examples:et(p,O)}}return B||{slug:"",title:"",examples:[]}},[g,b,O,B,S]),Be=(0,d.useMemo)(()=>Qe(r),[r]);return!w||!J?null:(0,c.jsx)("div",{className:"zero-admin-style-book",style:{color:u,background:E},children:(0,c.jsx)(nt,{category:S,examples:J,isSelected:t?s:void 0,onSelect:t?m:void 0,globalStyles:e,serverCSS:o,serverSettings:n,goTo:Be})})},nt=({category:t,examples:e,isSelected:o,onSelect:n,globalStyles:r,title:l="",serverCSS:s,serverSettings:m,goTo:u})=>{let[E,w]=Je(r),y=(0,d.useMemo)(()=>[...s??[],...E??[]],[s,E]),b=(0,d.useMemo)(()=>({...m,__experimentalFeatures:w,styles:y,isPreviewMode:!0}),[w,m,y]),[g,O]=(0,d.useState)(!1),S=(0,d.useRef)(null),B=()=>O(!0);return(0,d.useLayoutEffect)(()=>{g&&S?.current&&u?.top&&ot("top",S?.current)},[u,g]),(0,c.jsx)(_.BlockEditorProvider,{settings:b,children:(0,c.jsxs)(_.__unstableIframe,{className:"zero-admin-style-book__iframe",name:"style-book-canvas",tabIndex:0,onLoad:B,ref:S,children:[(0,c.jsx)(_.__unstableEditorStyles,{styles:y}),(0,c.jsx)("style",{children:ae}),(0,c.jsx)(st,{className:"zero-admin-style-book__examples",category:t,examples:e,label:l?(0,G.sprintf)((0,G.__)("Examples of blocks in the %s category","zero-admin"),l):(0,G.__)("Examples of blocks","zero-admin"),isSelected:o,onSelect:n,settings:b},l)]})})},st=(0,d.memo)(({className:t,examples:e,label:o,isSelected:n,onSelect:r,settings:l})=>(0,c.jsxs)(z.Composite,{orientation:"vertical",className:t,"aria-label":o,role:"grid",children:[e.examples?.length&&e.examples.map(s=>(0,c.jsx)(ze,{id:`example-${s.name}`,title:s.title,content:s.content,blocks:s.blocks,isSelected:n?.(s.name),onClick:r?()=>r(s.name,!!s.variation):void 0,settings:l},s.name)),e.subcategories?.length&&e.subcategories?.map(s=>(0,c.jsxs)(z.Composite.Group,{className:"zero-admin-style-book__subcategory",children:[(0,c.jsx)(z.Composite.GroupLabel,{children:(0,c.jsx)("h2",{className:"zero-admin-style-book__subcategory-title",children:s.title})}),(0,c.jsx)(lt,{examples:s,isSelected:n,onSelect:r,settings:l})]},`subcategory-${s.slug}`))]})),lt=({examples:t,isSelected:e,onSelect:o,settings:n})=>!!t?.examples?.length&&t.examples.map(r=>(0,c.jsx)(ze,{id:`example-${r.name}`,title:r.title,content:r.content,blocks:r.blocks,isSelected:e?.(r.name),onClick:o?()=>o(r.name):void 0,settings:n},r.name)),it=["example-duotones"],ze=({id:t,title:e,blocks:o,isSelected:n,onClick:r,content:l,settings:s})=>{let m=(0,d.useMemo)(()=>Array.isArray(o)?o:[o],[o]),u=it.includes(t)||!r?{disabled:!0,accessibleWhenDisabled:!!r}:{};return(0,c.jsx)("div",{role:"row",children:(0,c.jsx)("div",{role:"gridcell",children:(0,c.jsxs)(z.Composite.Item,{className:P("zero-admin-style-book__example",{"is-selected":n,"is-disabled-example":!!u?.disabled}),id:t,"aria-label":r?(0,G.sprintf)((0,G.__)("Open %s styles in Styles panel","zero-admin"),e):void 0,render:(0,c.jsx)("div",{}),role:r?"button":void 0,onClick:r,...u,children:[(0,c.jsx)("span",{className:"zero-admin-style-book__example-title",children:e}),(0,c.jsx)("div",{className:"zero-admin-style-book__example-preview","aria-hidden":!0,children:(0,c.jsx)(z.Disabled,{className:"zero-admin-style-book__example-preview__content",children:l||(0,c.jsx)(_.BlockEditorProvider,{value:m,settings:s,children:(0,c.jsx)(_.BlockList,{renderAppender:!1})})})})]})})})},Ge=rt;var Oe=i(h());function at({postId:t}){return(0,Oe.jsx)(Ge,{id:t})}var Yt={canvas:async({search:t})=>{let e=await(0,X.resolveSelect)(Z.store).__experimentalGetCurrentGlobalStylesId();return t.preview==="stylebook"||!e?{postType:"wp_global_styles",postId:e,component:at,isPreview:!0}:{isPreview:!0}},inspector:async()=>!!await(0,X.resolveSelect)(Z.store).__experimentalGetCurrentGlobalStylesId()};export{Yt as route};
