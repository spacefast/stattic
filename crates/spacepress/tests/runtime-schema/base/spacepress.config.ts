// The project every case of ../corpus.json is checked against: by the compiler with the case
// written as the runtime schema lock, and by the runtime on a site that runs this build.
import { defineConfig } from "spacepress";
export default defineConfig({ name: "rtschema", runtimeSchema: true });
