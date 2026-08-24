// In-process fake S3 bucket for exercising shared/s3.php's SigV4 signer and
// client (plan §26/§28, DECISIONS I-12/I-13/I-14). Path-style addressing
// (`/{bucket}/{key...}`); a vhost-style request works too if the caller pins the
// vhost host to this server's loopback address (CURLOPT_RESOLVE, see
// s3.test.ts), since there is no DNS for `{bucket}.127.0.0.1`.
//
// It checks the wire protocol the client emits: the Authorization header has the
// AWS4-HMAC-SHA256 shape with Credential=/SignedHeaders=/Signature=, and a PUT's
// x-amz-content-sha256 matches the received body (plan §18: wrong hash -> 400,
// UNSIGNED-PAYLOAD -> 200). It does not recompute the HMAC chain, which would
// only test the fixture against itself.
import { randomUUID } from "node:crypto";

import type { Server } from "bun";

export type FakeS3Request = {
  method: string;
  path: string;
  headers: Record<string, string>;
};

export type FakeS3Object = {
  body: Buffer;
  sha256: string;
  etag: string;
  contentType: string;
};

export type FakeS3 = {
  url: string;
  bucket: string;
  requests: FakeS3Request[];
  objects: Map<string, FakeS3Object>;
  putObject(key: string, body: Buffer, contentType?: string): FakeS3Object;
  getObject(key: string): FakeS3Object | undefined;
  /** Forces the next `count` requests (any method/path) to fail with `status`. */
  failNext(count: number, status?: number): void;
  /** Forces the next `count` requests for exactly `key` to fail with `status`. */
  failKey(key: string, count: number, status?: number): void;
  stop(): void;
};

const AUTH_HEADER_PATTERN =
  /^AWS4-HMAC-SHA256 Credential=[^,]+,\s*SignedHeaders=[^,]+,\s*Signature=[0-9a-f]{64}$/;

export async function startFakeS3(bucket = "test-bucket"): Promise<FakeS3> {
  const requests: FakeS3Request[] = [];
  const objects = new Map<string, FakeS3Object>();
  const failureQueue: number[] = [];
  const keyFailures = new Map<string, { count: number; status: number }>();

  // Path-style: `/{bucket}/{key...}`. Virtual-host-style: the bucket rides in
  // the Host header (`{bucket}.host[:port]`) and the whole path is the key.
  function keyFor(pathname: string, host: string): string | null {
    const hostname = host.split(":")[0] ?? "";
    if (hostname.startsWith(`${bucket}.`)) {
      return decodeURIComponent(pathname.replace(/^\//, ""));
    }
    const prefix = `/${bucket}/`;
    if (!pathname.startsWith(prefix)) {
      return null;
    }
    return decodeURIComponent(pathname.slice(prefix.length));
  }

  function put(key: string, body: Buffer, contentType: string): FakeS3Object {
    const sha256 = new Bun.CryptoHasher("sha256").update(body).digest("hex");
    const object: FakeS3Object = { body, sha256, etag: `"${sha256}"`, contentType };
    objects.set(key, object);
    return object;
  }

  const server: Server = Bun.serve({
    port: 0,
    hostname: "127.0.0.1",
    async fetch(req) {
      const url = new URL(req.url);
      const headers: Record<string, string> = {};
      req.headers.forEach((value, name) => {
        headers[name.toLowerCase()] = value;
      });
      requests.push({ method: req.method, path: url.pathname, headers });

      if (failureQueue.length > 0) {
        const status = failureQueue.shift()!;
        return new Response("injected failure\n", { status });
      }

      const key = keyFor(url.pathname, headers["host"] ?? "");
      if (key === null) {
        return new Response(errorXml("NoSuchBucket", "The specified bucket does not exist"), {
          status: 404,
        });
      }

      const keyFailure = keyFailures.get(key);
      if (keyFailure !== undefined && keyFailure.count > 0) {
        keyFailure.count -= 1;
        if (keyFailure.count === 0) {
          keyFailures.delete(key);
        }
        return new Response("injected key failure\n", { status: keyFailure.status });
      }

      const authorization = headers["authorization"] ?? "";
      if (!AUTH_HEADER_PATTERN.test(authorization)) {
        return new Response(
          errorXml("InvalidArgument", "Authorization header is not a valid SigV4 header"),
          {
            status: 403,
          },
        );
      }

      // ListObjectsV2: the bucket root with `list-type=2`. Lexicographic
      // ordering like the real thing, so a paged caller that deletes what it
      // lists makes progress instead of re-reading the same window.
      if (req.method === "GET" && key === "" && url.searchParams.get("list-type") === "2") {
        const prefix = url.searchParams.get("prefix") ?? "";
        const maxKeys = Number(url.searchParams.get("max-keys") ?? "1000");
        const matched = [...objects.keys()].filter((name) => name.startsWith(prefix)).toSorted();
        const page = matched.slice(0, maxKeys);
        return new Response(listXml(bucket, prefix, page, matched.length > page.length), {
          status: 200,
          headers: { "content-type": "application/xml" },
        });
      }

      if (req.method === "DELETE") {
        // S3 DELETE is idempotent: a key that is not there answers 204 too.
        objects.delete(key);
        return new Response(null, { status: 204 });
      }

      if (req.method === "PUT") {
        const body = Buffer.from(await req.arrayBuffer());
        const declaredHash = headers["x-amz-content-sha256"] ?? "";
        if (declaredHash === "") {
          return new Response(errorXml("InvalidArgument", "Missing x-amz-content-sha256"), {
            status: 400,
          });
        }
        if (declaredHash !== "UNSIGNED-PAYLOAD") {
          const actualHash = new Bun.CryptoHasher("sha256").update(body).digest("hex");
          if (declaredHash !== actualHash) {
            return new Response(
              errorXml(
                "XAmzContentSHA256Mismatch",
                "The provided 'x-amz-content-sha256' does not match",
              ),
              {
                status: 400,
              },
            );
          }
        }
        const object = put(key, body, headers["content-type"] ?? "application/octet-stream");
        return new Response(null, { status: 200, headers: { etag: object.etag } });
      }

      const object = objects.get(key);
      if (!object) {
        return new Response(errorXml("NoSuchKey", "The specified key does not exist"), {
          status: 404,
        });
      }

      const ifNoneMatch = headers["if-none-match"];
      if (ifNoneMatch !== undefined && ifNoneMatch === object.etag) {
        return new Response(null, { status: 304, headers: { etag: object.etag } });
      }

      const total = object.body.length;
      const range = headers["range"];
      if (range !== undefined) {
        const match = /^bytes=(\d*)-(\d*)$/.exec(range.trim());
        if (!match) {
          return new Response(errorXml("InvalidRange", "Invalid range header"), { status: 416 });
        }
        const rawStart = match[1] ?? "";
        const rawEnd = match[2] ?? "";
        let start = rawStart === "" ? undefined : Number(rawStart);
        let end = rawEnd === "" ? undefined : Number(rawEnd);
        if (start === undefined && end !== undefined) {
          // suffix range: last `end` bytes
          start = Math.max(0, total - end);
          end = total - 1;
        }
        if (start === undefined || start >= total || (end !== undefined && end < start)) {
          return new Response(errorXml("InvalidRange", "The requested range is not satisfiable"), {
            status: 416,
            headers: { "content-range": `bytes */${total}` },
          });
        }
        const clampedEnd = end === undefined ? total - 1 : Math.min(end, total - 1);
        const slice = object.body.subarray(start, clampedEnd + 1);
        const rangeHeaders: Record<string, string> = {
          etag: object.etag,
          "content-range": `bytes ${start}-${clampedEnd}/${total}`,
          "content-type": object.contentType,
        };
        return new Response(req.method === "HEAD" ? null : slice, {
          status: 206,
          headers: rangeHeaders,
        });
      }

      const okHeaders: Record<string, string> = {
        etag: object.etag,
        "content-length": String(total),
        "content-type": object.contentType,
      };
      return new Response(req.method === "HEAD" ? null : object.body, {
        status: 200,
        headers: okHeaders,
      });
    },
  });

  return {
    url: `http://127.0.0.1:${server.port}`,
    bucket,
    requests,
    objects,
    putObject: put,
    getObject: (key: string) => objects.get(key),
    failNext: (count: number, status = 500) => {
      for (let i = 0; i < count; i += 1) {
        failureQueue.push(status);
      }
    },
    failKey: (key: string, count: number, status = 500) => {
      keyFailures.set(key, { count, status });
    },
    stop: () => server.stop(true),
  };
}

function listXml(bucket: string, prefix: string, keys: string[], truncated: boolean): string {
  const contents = keys
    .map((key) => `<Contents><Key>${escapeXml(key)}</Key><Size>0</Size></Contents>`)
    .join("");
  return `<?xml version="1.0" encoding="UTF-8"?>\n<ListBucketResult><Name>${escapeXml(bucket)}</Name><Prefix>${escapeXml(prefix)}</Prefix><KeyCount>${keys.length}</KeyCount><IsTruncated>${truncated}</IsTruncated>${contents}${truncated ? `<NextContinuationToken>${escapeXml(keys.at(-1) ?? "")}</NextContinuationToken>` : ""}</ListBucketResult>\n`;
}

function escapeXml(value: string): string {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function errorXml(code: string, message: string): string {
  return `<?xml version="1.0" encoding="UTF-8"?>\n<Error><Code>${code}</Code><Message>${message}</Message><RequestId>${randomUUID()}</RequestId></Error>\n`;
}
