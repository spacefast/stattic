import '__i18n__';
var Ae=Object.create;var Q=Object.defineProperty;var Re=Object.getOwnPropertyDescriptor;var Te=Object.getOwnPropertyNames;var Ie=Object.getPrototypeOf,Le=Object.prototype.hasOwnProperty;var x=(t,e)=>()=>(e||t((e={exports:{}}).exports,e),e.exports);var Me=(t,e,o,r)=>{if(e&&typeof e=="object"||typeof e=="function")for(let s of Te(e))!Le.call(t,s)&&s!==o&&Q(t,s,{get:()=>e[s],enumerable:!(r=Re(e,s))||r.enumerable});return t};var i=(t,e,o)=>(o=t!=null?Ae(Ie(t)):{},Me(e||!t||!t.__esModule?Q(o,"default",{value:t,enumerable:!0}):o,t));var D=x((ct,ee)=>{"use strict";ee.exports=window.wp.data});var V=x((dt,te)=>{"use strict";te.exports=window.wp.coreData});var R=x((ut,se)=>{"use strict";se.exports=window.wp.components});var T=x((gt,re)=>{"use strict";re.exports=window.wp.i18n});var $=x((yt,ne)=>{"use strict";ne.exports=window.wp.blockEditor});var H=x((ft,le)=>{"use strict";le.exports=window.wp.element});var K=x((xt,pe)=>{"use strict";pe.exports=window.wp.blocks});var h=x((kt,ce)=>{"use strict";ce.exports=window.ReactJSXRuntime});var ye=x((Bt,ge)=>{"use strict";ge.exports=window.wp.editor});var be=x((Ft,fe)=>{"use strict";fe.exports=window.wp.mediaUtils});var we=x((Tt,Ee)=>{"use strict";Ee.exports=function t(e,o){if(e===o)return!0;if(e&&o&&typeof e=="object"&&typeof o=="object"){if(e.constructor!==o.constructor)return!1;var r,s,l;if(Array.isArray(e)){if(r=e.length,r!=o.length)return!1;for(s=r;s--!==0;)if(!t(e[s],o[s]))return!1;return!0}if(e instanceof Map&&o instanceof Map){if(e.size!==o.size)return!1;for(s of e.entries())if(!o.has(s[0]))return!1;for(s of e.entries())if(!t(s[1],o.get(s[0])))return!1;return!0}if(e instanceof Set&&o instanceof Set){if(e.size!==o.size)return!1;for(s of e.entries())if(!o.has(s[0]))return!1;return!0}if(ArrayBuffer.isView(e)&&ArrayBuffer.isView(o)){if(r=e.length,r!=o.length)return!1;for(s=r;s--!==0;)if(e[s]!==o[s])return!1;return!0}if(e.constructor===RegExp)return e.source===o.source&&e.flags===o.flags;if(e.valueOf!==Object.prototype.valueOf)return e.valueOf()===o.valueOf();if(e.toString!==Object.prototype.toString)return e.toString()===o.toString();if(l=Object.keys(e),r=l.length,r!==Object.keys(o).length)return!1;for(s=r;s--!==0;)if(!Object.prototype.hasOwnProperty.call(o,l[s]))return!1;for(s=r;s--!==0;){var n=l[s];if(!t(e[n],o[n]))return!1}return!0}return e!==e&&o!==o}});var X=i(D()),Z=i(V());function oe(t){var e,o,r="";if(typeof t=="string"||typeof t=="number")r+=t;else if(typeof t=="object")if(Array.isArray(t)){var s=t.length;for(e=0;e<s;e++)t[e]&&(o=oe(t[e]))&&(r&&(r+=" "),r+=o)}else for(o in t)t[o]&&(r&&(r+=" "),r+=o);return r}function Ne(){for(var t,e,o=0,r="",s=arguments.length;o<s;o++)(t=arguments[o])&&(e=oe(t))&&(r&&(r+=" "),r+=e);return r}var A=Ne;var G=i(R()),O=i(T()),_=i($()),m=i(H());import{getStyle as Ce,getPalettes as Ze,generateGlobalStyles as Je}from"@typedpress-admin/global-styles-engine";var a=i(T()),I=[{slug:"theme-colors",title:(0,a.__)("Theme Colors","typedpress-admin"),origin:"theme",type:"colors"},{slug:"theme-gradients",title:(0,a.__)("Theme Gradients","typedpress-admin"),origin:"theme",type:"gradients"},{slug:"custom-colors",title:(0,a.__)("Custom Colors","typedpress-admin"),origin:"custom",type:"colors"},{slug:"custom-gradients",title:(0,a.__)("Custom Gradients","typedpress-admin"),origin:"custom",type:"gradients"},{slug:"duotones",title:(0,a.__)("Duotones","typedpress-admin"),origin:"theme",type:"duotones"},{slug:"default-colors",title:(0,a.__)("Default Colors","typedpress-admin"),origin:"default",type:"colors"},{slug:"default-gradients",title:(0,a.__)("Default Gradients","typedpress-admin"),origin:"default",type:"gradients"}],Y=[{slug:"site-identity",title:(0,a.__)("Site Identity","typedpress-admin"),blocks:["core/site-logo","core/site-title","core/site-tagline"]},{slug:"design",title:(0,a.__)("Design","typedpress-admin"),blocks:["core/navigation","core/avatar","core/post-time-to-read"],exclude:["core/home-link","core/navigation-link"]},{slug:"posts",title:(0,a.__)("Posts","typedpress-admin"),blocks:["core/post-title","core/post-excerpt","core/post-author","core/post-author-name","core/post-author-biography","core/post-date","core/post-terms","core/term-description","core/query-title","core/query-no-results","core/query-pagination","core/query-numbers"]},{slug:"comments",title:(0,a.__)("Comments","typedpress-admin"),blocks:["core/comments-title","core/comments-pagination","core/comments-pagination-numbers","core/comments","core/comments-author-name","core/comment-content","core/comment-date","core/comment-edit-link","core/comment-reply-link","core/comment-template","core/post-comments-count","core/post-comments-link"]}],Ue=[{slug:"overview",title:(0,a.__)("Overview","typedpress-admin"),blocks:[]},{slug:"text",title:(0,a.__)("Text","typedpress-admin"),blocks:["core/post-content","core/home-link","core/navigation-link"]},{slug:"colors",title:(0,a.__)("Colors","typedpress-admin"),blocks:[]},{slug:"theme",title:(0,a.__)("Theme","typedpress-admin"),subcategories:Y},{slug:"media",title:(0,a.__)("Media","typedpress-admin"),blocks:["core/post-featured-image"]},{slug:"widgets",title:(0,a.__)("Widgets","typedpress-admin"),blocks:[]},{slug:"embed",title:(0,a.__)("Embeds","typedpress-admin"),include:[]}],We=[...Y,{slug:"media",title:(0,a.__)("Media","typedpress-admin"),blocks:["core/post-featured-image"]},{slug:"widgets",title:(0,a.__)("Widgets","typedpress-admin"),blocks:[]},{slug:"embed",title:(0,a.__)("Embeds","typedpress-admin"),include:[]}],ie=[{slug:"overview",title:(0,a.__)("Overview","typedpress-admin"),blocks:[]},{slug:"text",title:(0,a.__)("Text","typedpress-admin"),blocks:["core/post-content","core/home-link","core/navigation-link"]},{slug:"colors",title:(0,a.__)("Colors","typedpress-admin"),blocks:[]},{slug:"blocks",title:(0,a.__)("All Blocks","typedpress-admin"),blocks:[],subcategories:We}],De=`
	.is-root-container {
		display: flow-root;
	}
`,ae=`
	body {
		position: relative;
		padding: 32px !important;
	}

	${De}

	.typedpress-admin-style-book__examples {
		max-width: 1200px;
		margin: 0 auto;
	}

	.typedpress-admin-style-book__example {
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

	.typedpress-admin-style-book__example.is-selected {
		box-shadow: 0 0 0 1px var(--wpds-color-stroke-focus);
	}

	.typedpress-admin-style-book__example.is-disabled-example {
		pointer-events: none;
	}

	.typedpress-admin-style-book__example:focus:not(:disabled) {
		box-shadow: 0 0 0 var(--wpds-border-width-focus) var(--wpds-color-stroke-focus);
		outline: 3px solid transparent;
	}

	.typedpress-admin-style-book__duotone-example > div:first-child {
		display: flex;
		aspect-ratio: 16 / 9;
		grid-row: span 1;
		grid-column: span 2;
	}
	.typedpress-admin-style-book__duotone-example img {
		width: 100%;
		height: 100%;
		object-fit: cover;
	}
	.typedpress-admin-style-book__duotone-example > div:not(:first-child) {
		height: 20px;
		border: 1px solid color-mix( in srgb, currentColor 10%, transparent );
	}

	.typedpress-admin-style-book__color-example {
		border: 1px solid color-mix( in srgb, currentColor 10%, transparent );
	}

	.typedpress-admin-style-book__subcategory-title,
	.typedpress-admin-style-book__example-title {
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

	.typedpress-admin-style-book__subcategory-title {
		font-size: 16px;
		margin-bottom: 40px;
    	padding-bottom: 8px;
	}

	.typedpress-admin-style-book__example-preview {
		width: 100%;
	}

	.typedpress-admin-style-book__example-preview .block-editor-block-list__insertion-point,
	.typedpress-admin-style-book__example-preview .block-list-appender {
		display: none;
	}
	:where(.is-root-container > .wp-block:first-child) {
		margin-top: 0;
	}
	:where(.is-root-container > .wp-block:last-child) {
		margin-bottom: 0;
	}
`;var Ve=i(K());function L(t,e){if(!t?.slug||!e?.length)return;let o=t?.subcategories??[];if(o.length){let n=o.map(c=>L(c,e)).filter(c=>c!==void 0);return{title:t.title,slug:t.slug,subcategories:n.length>0?n:void 0}}let r=t?.blocks||[],s=t?.exclude||[],l=e.filter(n=>!s.includes(n.name)&&(n.category===t.slug||r.includes(n.name)));if(l.length)return{title:t.title,slug:t.slug,examples:l}}var k=i(T()),y=i(K());var de=i(R()),M=i($()),z=i(h()),$e=({colors:t,type:e,templateColumns:o="1fr 1fr",itemHeight:r="52px"})=>t?(0,z.jsx)(de.__experimentalGrid,{templateColumns:o,rowGap:8,columnGap:16,children:t.map(s=>{let l=e==="gradients"?(0,M.__experimentalGetGradientClass)(s.slug):(0,M.getColorClassName)("background-color",s.slug),n=A("typedpress-admin-style-book__color-example",l);return(0,z.jsx)("div",{className:n,style:{height:r}},s.slug)})}):null,q=$e;var j=i(R()),C=i(h()),He=({duotones:t})=>t?(0,C.jsx)(j.__experimentalGrid,{columns:2,rowGap:16,columnGap:16,children:t.map(e=>(0,C.jsxs)(j.__experimentalGrid,{className:"typedpress-admin-style-book__duotone-example",columns:2,rowGap:8,columnGap:8,children:[(0,C.jsx)("div",{children:(0,C.jsx)("img",{alt:`Duotone example: ${e.slug}`,src:"https://s.w.org/images/core/5.3/MtBlanc1.jpg",style:{filter:`url(#wp-duotone-${e.slug})`}})}),e.colors.map(o=>(0,C.jsx)("div",{className:"typedpress-admin-style-book__color-example",style:{backgroundColor:o}},o))]},e.slug))}):null,me=He;var N=i(h());function Ye(t){if(!t)return[];let e=[];return I.forEach(o=>{let r=t[o.type],s=Array.isArray(r)?r.find(l=>l.slug===o.origin):void 0;if(s?.[o.type]){let l={name:o.slug,title:o.title,category:"colors",blocks:null};o.type==="duotones"?(l.content=(0,N.jsx)(me,{duotones:s[o.type]}),e.push(l)):(l.content=(0,N.jsx)(q,{colors:s[o.type],type:o.type}),e.push(l))}}),e}function Ke(t){let e=[],o=Array.isArray(t?.colors)?t.colors.find(l=>l.slug==="theme")?.colors:void 0;if(o){let l={name:"theme-colors",title:(0,k.__)("Colors","typedpress-admin"),category:"overview",blocks:null,content:(0,N.jsx)(q,{colors:o,type:"colors",templateColumns:"repeat(auto-fill, minmax( 200px, 1fr ))",itemHeight:"32px"})};e.push(l)}let r=[];if((0,y.getBlockType)("core/heading")){let l=(0,y.createBlock)("core/heading",{content:(0,k.__)("AaBbCcDdEeFfGgHhIiJjKkLlMmNnOoPpQqRrSsTtUuVvWwXxYyZz0123456789X{(\u2026)},.-<>?!*&:/A@HELFO\u2122\xA9","typedpress-admin"),level:1});r.push(l)}if((0,y.getBlockType)("core/paragraph")){let l=(0,y.createBlock)("core/paragraph",{content:(0,k.__)("A paragraph in a website refers to a distinct block of text that is used to present and organize information. It is a fundamental unit of content in web design and is typically composed of a group of related sentences or thoughts focused on a particular topic or idea. Paragraphs play a crucial role in improving the readability and user experience of a website. They break down the text into smaller, manageable chunks, allowing readers to scan the content more easily.","typedpress-admin")}),n=(0,y.createBlock)("core/paragraph",{content:(0,k.__)("Additionally, paragraphs help structure the flow of information and provide logical breaks between different concepts or pieces of information. In terms of formatting, paragraphs in websites are commonly denoted by a vertical gap or indentation between each block of text. This visual separation helps visually distinguish one paragraph from another, creating a clear and organized layout that guides the reader through the content smoothly.","typedpress-admin")});if((0,y.getBlockType)("core/group")){let c=(0,y.createBlock)("core/group",{layout:{type:"grid",columnCount:2,minimumColumnWidth:"12rem"},style:{spacing:{blockGap:"1.5rem"}}},[l,n]);r.push(c)}else r.push(l)}return r.length&&e.push({name:"typography",title:(0,k.__)("Typography","typedpress-admin"),category:"overview",blocks:r}),["core/image","core/separator","core/buttons","core/pullquote","core/search"].forEach(l=>{let n=(0,y.getBlockType)(l);if(n&&n.example){let c={name:l,title:n.title,category:"overview",blocks:(0,y.getBlockFromExample)(l,{...n.example,attributes:{...n.example?.attributes,style:void 0}})};e.push(c)}}),e}function ue(t){let e=(0,y.getBlockTypes)().filter(n=>{let{name:c,example:u,supports:E}=n;return c!=="core/heading"&&!!u&&E?.inserter!==!1}).map(n=>({name:n.name,title:n.title,category:n.category,blocks:(0,y.getBlockFromExample)(n.name,{...n.example,attributes:{...n.example?.attributes,style:void 0}})}));if(!!!(0,y.getBlockType)("core/heading"))return e;let r={name:"core/heading",title:(0,k.__)("Headings","typedpress-admin"),category:"text",blocks:[1,2,3,4,5,6].map(n=>(0,y.createBlock)("core/heading",{content:(0,k.sprintf)((0,k.__)("Heading %d","typedpress-admin"),n),level:n}))},s=Ye(t),l=Ke(t);return[r,...s,...e,...l]}if(!document.head.querySelector("style[data-wp-hash='0c364a8999']")){let t=document.createElement("style");t.setAttribute("data-wp-hash","0c364a8999"),t.appendChild(document.createTextNode(`.typedpress-admin-style-book {
  width: 100%;
  height: 100%;
}

.typedpress-admin-style-book.is-button {
  border-radius: 8px;
}

.typedpress-admin-style-book {
  flex-direction: column;
  align-items: stretch;
  display: flex;
}

.typedpress-admin-style-book__iframe {
  width: 100%;
  height: 100%;
  display: block;
}

.typedpress-admin-style-book__iframe.is-button {
  border-radius: 8px;
}

.typedpress-admin-style-book__iframe.is-focused {
  outline: calc(2 * var(--wpds-border-width-focus)) solid var(--wpds-color-stroke-focus);
  outline-offset: calc(-2 * var(--wpds-border-width-focus));
}
`)),document.head.appendChild(t)}var U=i(V()),W=i(D()),P=i(H()),xe=i(ye()),he=i(be());import{useNavigate as ze,useSearch as qe}from"@wordpress/route";import{mergeGlobalStyles as je}from"@typedpress-admin/global-styles-engine";function Se(t){let{userGlobalStyles:e}=(0,W.useSelect)(r=>{let{getEntityRecord:s,getEditedEntityRecord:l,canUser:n}=r(U.store),c=n("update",{kind:"root",name:"globalStyles",id:t}),u;return typeof c=="boolean"&&(c?u=l("root","globalStyles",t):u=s("root","globalStyles",t,{context:"view"})),{userGlobalStyles:u}},[t]),o=(0,W.useSelect)(r=>r(U.store).__experimentalGetCurrentThemeBaseGlobalStyles(),[]);return(0,P.useMemo)(()=>{if(!e||!o)return{user:void 0,base:void 0,merged:void 0};let r=e,s=o,l=je(s,r);return{user:r,base:s,merged:l}},[e,o])}function ke(){let{styles:t,__unstableResolvedAssets:e,colors:o,gradients:r,__experimentalDiscussionSettings:s,mediaUpload:l}=(0,W.useSelect)(u=>{let{getEditorSettings:E}=u(xe.store),{canUser:w}=u(U.store),f=E(),b=w("create",{kind:"root",name:"media"});return{styles:f.styles,__unstableResolvedAssets:f.__unstableResolvedAssets,colors:f.colors,gradients:f.gradients,__experimentalDiscussionSettings:f.__experimentalDiscussionSettings,mediaUpload:b?he.uploadMedia:void 0}},[]),n=(0,P.useMemo)(()=>t?Object.values(t??[]).filter(u=>!u.isGlobalStyles):[],[t]),c=(0,P.useMemo)(()=>({__unstableResolvedAssets:e,settings:{color:{palette:{theme:o},gradients:{theme:r},duotone:{theme:[]}}},__experimentalDiscussionSettings:s,mediaUpload:l}),[o,r,e,s,l]);return[n,c]}function _e(){let t=ze(),e=qe({from:"/design/styles"});return(0,P.useMemo)(()=>[e.section,s=>{t({search:{...e,section:s}})}],[t,e])}var Xe=i(we());function ve(t){return t?`is-style-${t}`:""}var p=i(h()),Qe=t=>t&&typeof t=="string"&&(t==="/"||t.startsWith("/typography")||t.startsWith("/colors")||t.startsWith("/blocks"))?{top:!0}:null;function et(t,e){return e?t.map(o=>({...o,variation:e,blocks:{...o.blocks,attributes:{...o.blocks.attributes,style:void 0,className:ve(e)}}})):t}function tt(t){let e=[],o=L({slug:"overview",title:"Overview"},t);o?.examples&&e.push(...o.examples);let r=t.filter(s=>s.category!=="overview"&&!o?.examples?.find(l=>l.name===s.name));return e.push(...r),e}var ot=(t,e)=>{if(!t||!e||!e?.contentDocument)return;let o=t==="top"?e.contentDocument.body:e.contentDocument.getElementById(t);o&&o.scrollIntoView({behavior:"smooth"})},st=({id:t})=>{let{merged:e}=Se(t),[o,r]=ke(),[s,l]=_e(),n=d=>!!(s===`/blocks/${encodeURIComponent(d)}`||s?.startsWith(`/blocks/${encodeURIComponent(d)}/`)),c=(d,v=!1)=>{if(I.find(Pe=>Pe.slug===d)){l("/colors/palette");return}if(d==="typography"){l("/typography");return}v||l(`/blocks/${encodeURIComponent(d)}`)},u=(0,m.useMemo)(()=>Ce(e,"color.text"),[e]),E=(0,m.useMemo)(()=>Ce(e,"color.background"),[e]),w=(0,m.useMemo)(()=>e||{settings:r?.settings},[e,r]),f=(0,m.useMemo)(()=>Ze(w),[w]),b=(0,m.useMemo)(()=>f?ue(f):[],[f]),g=null,B=null;if(s?.includes("/colors"))g="colors";else if(s?.includes("/typography"))g="text";else if(s?.includes("/blocks")){g="blocks";let d=decodeURIComponent(s).split("/blocks/")[1];d?.includes("/variations")&&([d,B]=d.split("/variations/")),d&&b.find(v=>v.name===d)&&(g=d)}else t&&(g="overview");let S=ie.find(d=>d.slug===g),F=(0,m.useMemo)(()=>{if(!S){let d=b.find(v=>v.name===g);return{slug:g||"unknown",title:g||"Unknown",examples:d?[d]:[]}}return L(S,b)},[S,b,g]),J=(0,m.useMemo)(()=>{if(!g)return{slug:"all",title:"All Examples",examples:tt(b)};if(B){let d=(F?.examples||[]).filter(v=>v!==void 0);return{slug:g,title:S?.title||g,examples:et(d,B)}}return F||{slug:"",title:"",examples:[]}},[g,b,B,F,S]),Fe=(0,m.useMemo)(()=>Qe(s),[s]);return!w||!J?null:(0,p.jsx)("div",{className:"typedpress-admin-style-book",style:{color:u,background:E},children:(0,p.jsx)(rt,{category:S,examples:J,isSelected:t?n:void 0,onSelect:t?c:void 0,globalStyles:e,serverCSS:o,serverSettings:r,goTo:Fe})})},rt=({category:t,examples:e,isSelected:o,onSelect:r,globalStyles:s,title:l="",serverCSS:n,serverSettings:c,goTo:u})=>{let[E,w]=Je(s),f=(0,m.useMemo)(()=>[...n??[],...E??[]],[n,E]),b=(0,m.useMemo)(()=>({...c,__experimentalFeatures:w,styles:f,isPreviewMode:!0}),[w,c,f]),[g,B]=(0,m.useState)(!1),S=(0,m.useRef)(null),F=()=>B(!0);return(0,m.useLayoutEffect)(()=>{g&&S?.current&&u?.top&&ot("top",S?.current)},[u,g]),(0,p.jsx)(_.BlockEditorProvider,{settings:b,children:(0,p.jsxs)(_.__unstableIframe,{className:"typedpress-admin-style-book__iframe",name:"style-book-canvas",tabIndex:0,onLoad:F,ref:S,children:[(0,p.jsx)(_.__unstableEditorStyles,{styles:f}),(0,p.jsx)("style",{children:ae}),(0,p.jsx)(nt,{className:"typedpress-admin-style-book__examples",category:t,examples:e,label:l?(0,O.sprintf)((0,O.__)("Examples of blocks in the %s category","typedpress-admin"),l):(0,O.__)("Examples of blocks","typedpress-admin"),isSelected:o,onSelect:r,settings:b},l)]})})},nt=(0,m.memo)(({className:t,examples:e,label:o,isSelected:r,onSelect:s,settings:l})=>(0,p.jsxs)(G.Composite,{orientation:"vertical",className:t,"aria-label":o,role:"grid",children:[e.examples?.length&&e.examples.map(n=>(0,p.jsx)(Ge,{id:`example-${n.name}`,title:n.title,content:n.content,blocks:n.blocks,isSelected:r?.(n.name),onClick:s?()=>s(n.name,!!n.variation):void 0,settings:l},n.name)),e.subcategories?.length&&e.subcategories?.map(n=>(0,p.jsxs)(G.Composite.Group,{className:"typedpress-admin-style-book__subcategory",children:[(0,p.jsx)(G.Composite.GroupLabel,{children:(0,p.jsx)("h2",{className:"typedpress-admin-style-book__subcategory-title",children:n.title})}),(0,p.jsx)(lt,{examples:n,isSelected:r,onSelect:s,settings:l})]},`subcategory-${n.slug}`))]})),lt=({examples:t,isSelected:e,onSelect:o,settings:r})=>!!t?.examples?.length&&t.examples.map(s=>(0,p.jsx)(Ge,{id:`example-${s.name}`,title:s.title,content:s.content,blocks:s.blocks,isSelected:e?.(s.name),onClick:o?()=>o(s.name):void 0,settings:r},s.name)),it=["example-duotones"],Ge=({id:t,title:e,blocks:o,isSelected:r,onClick:s,content:l,settings:n})=>{let c=(0,m.useMemo)(()=>Array.isArray(o)?o:[o],[o]),u=it.includes(t)||!s?{disabled:!0,accessibleWhenDisabled:!!s}:{};return(0,p.jsx)("div",{role:"row",children:(0,p.jsx)("div",{role:"gridcell",children:(0,p.jsxs)(G.Composite.Item,{className:A("typedpress-admin-style-book__example",{"is-selected":r,"is-disabled-example":!!u?.disabled}),id:t,"aria-label":s?(0,O.sprintf)((0,O.__)("Open %s styles in Styles panel","typedpress-admin"),e):void 0,render:(0,p.jsx)("div",{}),role:s?"button":void 0,onClick:s,...u,children:[(0,p.jsx)("span",{className:"typedpress-admin-style-book__example-title",children:e}),(0,p.jsx)("div",{className:"typedpress-admin-style-book__example-preview","aria-hidden":!0,children:(0,p.jsx)(G.Disabled,{className:"typedpress-admin-style-book__example-preview__content",children:l||(0,p.jsx)(_.BlockEditorProvider,{value:c,settings:n,children:(0,p.jsx)(_.BlockList,{renderAppender:!1})})})})]})})})},Oe=st;var Be=i(h());function at({postId:t}){return(0,Be.jsx)(Oe,{id:t})}var Kt={canvas:async({search:t})=>{let e=await(0,X.resolveSelect)(Z.store).__experimentalGetCurrentGlobalStylesId();return t.preview==="stylebook"||!e?{postType:"wp_global_styles",postId:e,component:at,isPreview:!0}:{isPreview:!0}},inspector:async()=>!!await(0,X.resolveSelect)(Z.store).__experimentalGetCurrentGlobalStylesId()};export{Kt as route};
