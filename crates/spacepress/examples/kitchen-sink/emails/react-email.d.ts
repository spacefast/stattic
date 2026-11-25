// The react-email components SpacePress compiles (docs/config-api.md, "Email templates"), typed for
// this example's type check, which runs without node_modules. An app that previews its templates
// with `email dev` installs react-email and uses that package's own types instead.
declare module "react-email" {
  import type { JSX } from "spacepress/jsx-runtime";
  export function Html(props: { lang?: string; children?: JSX.Children }): JSX.Element;
  export function Body(props: { children?: JSX.Children }): JSX.Element;
  export function Text(props: { children?: JSX.Children }): JSX.Element;
  export function Button(props: { href: string; children?: JSX.Children }): JSX.Element;
}
