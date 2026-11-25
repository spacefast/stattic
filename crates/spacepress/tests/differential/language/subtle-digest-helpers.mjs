// Untyped JavaScript for tests/differential/language/subtle-digest.ts: crypto.subtle.digest through
// SpacePress's checked JavaScript dispatch, as files-sdk 3.1.2 hashes upload bodies (sha256Hex).

const hex = (buffer) => [...new Uint8Array(buffer)].map((b) => b.toString(16).padStart(2, "0")).join("");
export async function digests() {
  const enc = new TextEncoder();
  const out = {};
  for (const name of ["SHA-256", "sha-256", "SHA-1", "SHA-384", "SHA-512"]) {
    out[name] = hex(await crypto.subtle.digest(name, enc.encode("héllo 🙂")));
  }
  out.object = hex(await crypto.subtle.digest({ name: "SHA-256" }, enc.encode("")));
  out.buffer = hex(await crypto.subtle.digest("SHA-256", enc.encode("abc").buffer));
  out.view = hex(await crypto.subtle.digest("SHA-256", new DataView(enc.encode("xabcx").buffer, 1, 3)));
  out.subarray = hex(await crypto.subtle.digest("SHA-256", enc.encode("xabcx").subarray(1, 4)));
  const digest = await crypto.subtle.digest("SHA-256", enc.encode("a"));
  out.isBuffer = [digest instanceof ArrayBuffer, digest.byteLength];
  // files-sdk's sha256Hex, verbatim.
  const sha256Hex = async (bytes) => {
    const d = await crypto.subtle.digest("SHA-256", bytes);
    const v = new Uint8Array(d);
    let h = "";
    for (const byte of v) h += byte.toString(16).padStart(2, "0");
    return h;
  };
  out.filesSdk = await sha256Hex(enc.encode("files-sdk body"));
  try { await crypto.subtle.digest("MD5", enc.encode("x")); out.badName = "resolved"; } catch (e) { out.badName = e.name; }
  try { await crypto.subtle.digest("SHA-256", "text"); out.badData = "resolved"; } catch (e) { out.badData = e.name; }
  return out;
}
