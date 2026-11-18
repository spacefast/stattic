// Written by `typedpress add api-reference`; yours to change. One list of an operation's parameters,
// body or response fields: each field's name, type, where it goes, its constraints and description,
// indented by how deeply it is nested.
import { Html } from "typedpress/app";
import type { ApiField } from "typedpress/content";

export function ApiFields({ fields }: { fields: ApiField[] }) {
  return (
    <ul className="api-fields">
      {fields.map((f, i) => (
        <li key={`${i}-${f.name}`} className={`api-field api-depth-${f.depth}`}>
          <code className="api-field-name">{f.name}</code> <span className="api-field-type">{f.type}</span>
          {f.required && <span className="api-field-required"> required</span>}
          {f.in !== "" && <span className="api-field-in"> in {f.in}</span>}
          {f.constraints.length > 0 && <span className="api-field-constraints"> {f.constraints.join(" · ")}</span>}
          <Html value={f.description} />
        </li>
      ))}
    </ul>
  );
}
