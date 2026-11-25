import type { LayoutProps } from "spacepress/app";

export const guard = "editor";

export default function StaffLayout({ children }: LayoutProps) {
  return <section aria-label="Staff tools">{children}</section>;
}
