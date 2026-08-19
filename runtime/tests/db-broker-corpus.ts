// Shared fixture for the MySQL capability broker's differential test: the
// schema, the passthrough Zero endpoint that exposes the Rust engine, and the
// corpus itself. It lives beside the test rather than inside it so the same
// corpus can be driven from a scratch harness without booting the test runner.

// The endpoint is a raw passthrough to the DB host function. The runner hands
// the request body to JavaScript already base64-encoded, and decoding it there
// rather than reading a decoded string keeps the operation text exact without a
// UTF-8 decoder inside QuickJS — operations are held to ASCII for that reason.
export const ECHO_ENDPOINT = `
const request = globalThis.__statticZeroRequest;
const ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
// Charcode-indexed lookup and block-wise fromCharCode, not indexOf and string
// append: the oversized-operation case decodes ~90KB and the endpoint's whole
// CPU budget is 500ms, which the naive form does not fit inside.
const INDEX = [];
for (let i = 0; i < 128; i++) { INDEX[i] = -1; }
for (let i = 0; i < ALPHABET.length; i++) { INDEX[ALPHABET.charCodeAt(i)] = i; }
function decodeBase64(input) {
  const bytes = [];
  let bits = 0;
  let held = 0;
  for (let i = 0; i < input.length; i++) {
    const index = INDEX[input.charCodeAt(i)];
    if (index === undefined || index < 0) { continue; }
    held = (held << 6) | index;
    bits += 6;
    if (bits >= 8) {
      bits -= 8;
      bytes[bytes.length] = (held >> bits) & 255;
    }
  }
  let out = "";
  for (let i = 0; i < bytes.length; i += 4096) {
    out += String.fromCharCode.apply(null, bytes.slice(i, i + 4096));
  }
  return out;
}
let operation = decodeBase64(request.bodyBase64 || "");
// Fixture shorthand for the over-the-limit case. The engine still receives a
// genuine >64KB operation; only the trip into this endpoint is compressed,
// because base64-decoding ~90KB of request body does not fit the 500ms CPU
// budget reliably on a loaded machine.
if (operation.indexOf("__repeat__:") === 0) {
  operation = '{"sql":"' + "x".repeat(parseInt(operation.slice(11), 10)) + '"}';
}
globalThis.__statticZeroResult = JSON.stringify({
  status: 200,
  headers: { "content-type": "application/json; charset=utf-8" },
  body: globalThis.__statticDbHost(operation)
});
`;

export const FIXTURE_DDL = `
    DROP TABLE IF EXISTS dt;
    CREATE TABLE dt (
      id INT PRIMARY KEY,
      c_tinyint TINYINT, c_tinyint1 TINYINT(1), c_tinyint_u TINYINT UNSIGNED,
      c_smallint SMALLINT, c_mediumint MEDIUMINT, c_int INT, c_int_u INT UNSIGNED,
      c_bigint BIGINT, c_bigint_u BIGINT UNSIGNED,
      c_float FLOAT, c_double DOUBLE, c_double_negzero DOUBLE, c_decimal DECIMAL(30,10),
      c_bit1 BIT(1), c_bit8 BIT(8), c_bit17 BIT(17), c_bit64 BIT(64),
      c_date DATE, c_datetime6 DATETIME(6), c_datetime DATETIME, c_timestamp3 TIMESTAMP(3) NULL,
      c_time6 TIME(6), c_time_big TIME, c_time_neg TIME, c_year YEAR,
      c_char CHAR(12), c_varchar VARCHAR(64), c_text TEXT,
      c_binary BINARY(4), c_varbinary VARBINARY(32), c_blob BLOB,
      c_json JSON, c_enum ENUM('alpha','beta'), c_set SET('alpha','beta'),
      c_empty VARCHAR(8), c_null_int INT
    ) ENGINE=InnoDB;
    INSERT INTO dt VALUES (
      1,
      -128, 1, 255,
      -32768, 8388607, -2147483648, 4294967295,
      -9223372036854775808, 18446744073709551615,
      0.1, 0.1, -0e0, '12345678901234567890.1234567890',
      b'1', b'11111111', b'10000000000000001', b'1111111111111111111111111111111111111111111111111111111111111111',
      '2024-01-15', '2024-01-15 10:20:30.123456', '2024-01-15 10:20:30', '2024-01-15 10:20:30.500',
      '10:20:30.123456', '100:20:30', '-00:00:01', 2024,
      'chr', 'héllo · ünï 😀', 'text value',
      UNHEX('00FF0041'), UNHEX('0041000042'), UNHEX('DEADBEEF00'),
      JSON_OBJECT('k', 1, 'a', JSON_ARRAY(1, 2)),
      'beta', 'alpha,beta',
      '', NULL
    );
    INSERT INTO dt (id) VALUES (2);
    INSERT INTO dt (id, c_varbinary, c_text) VALUES (3, UNHEX('C32800FF'), 'invalid-utf8-neighbour');
    DROP TABLE IF EXISTS lifecycle;
    CREATE TABLE lifecycle (id INT PRIMARY KEY, v VARCHAR(64)) ENGINE=InnoDB;
    DROP TABLE IF EXISTS wide;
    CREATE TABLE wide (id INT AUTO_INCREMENT PRIMARY KEY, payload VARCHAR(255)) ENGINE=InnoDB;
    INSERT INTO wide (payload) SELECT REPEAT('x', 200) FROM
      (SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10) a,
      (SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10) b;
`;

/**
 * Operation text is kept pure ASCII so the base64 hop into QuickJS is exact
 * without a UTF-8 decoder there; JSON's \uXXXX escapes mean no information is
 * lost.
 */
export function op(value: unknown): string {
  return JSON.stringify(value).replace(
    /[\u007f-\uffff]/g,
    (character) => `\\u${character.charCodeAt(0).toString(16).padStart(4, "0")}`,
  );
}

/**
 * `bytes` compares the two engines' responses byte for byte. `code` compares
 * only the error code, and is reserved for the inputs whose message text is a
 * JSON-parser diagnostic: Rust reports serde's own wording and byte offset
 * ("invalid type: integer `5`, expected a sequence at line 1 column 28"), which
 * no PHP parser can reproduce. Every server-side failure stays on `bytes` —
 * those messages are rendered in the Rust driver's exact format and do match.
 */
export type CorpusCase = [
  name: string,
  operation: string,
  compare?: "bytes" | "code",
  /** Body to POST to the Rust endpoint when it differs from the operation text. */
  rustBody?: string,
];

export const CORPUS: CorpusCase[] = [
  ["every column type, populated", op({ sql: "SELECT * FROM dt WHERE id = 1" })],
  ["every column type, all NULL", op({ sql: "SELECT * FROM dt WHERE id = 2" })],
  ["invalid UTF-8 in a VARBINARY", op({ sql: "SELECT c_varbinary, c_text FROM dt WHERE id = 3" })],
  ["empty result set", op({ sql: "SELECT id, c_varchar FROM dt WHERE 1 = 0" })],
  ["no parameters at all", op({ sql: "SELECT 1 AS a, 'x' AS b" })],
  ["column name ordering is bytewise", op({ sql: "SELECT 1 AS b, 2 AS A, 3 AS _z, 4 AS a" })],
  ["duplicate column names collapse", op({ sql: "SELECT 1 AS d, 2 AS d, 3 AS e" })],
  ["unsigned BIGINT above 2^53", op({ sql: "SELECT c_bigint_u AS v FROM dt WHERE id = 1" })],
  ["signed BIGINT at the floor", op({ sql: "SELECT c_bigint AS v FROM dt WHERE id = 1" })],
  ["DECIMAL(30,10)", op({ sql: "SELECT c_decimal AS v FROM dt WHERE id = 1" })],
  ["negative zero DOUBLE", op({ sql: "SELECT c_double_negzero AS v FROM dt WHERE id = 1" })],
  ["FLOAT widening", op({ sql: "SELECT c_float AS v FROM dt WHERE id = 1" })],
  ["TINYINT(1)", op({ sql: "SELECT c_tinyint1 AS v FROM dt WHERE id = 1" })],
  ["BIT(1)", op({ sql: "SELECT c_bit1 AS v FROM dt WHERE id = 1" })],
  ["BIT(8) is not valid UTF-8", op({ sql: "SELECT c_bit8 AS v FROM dt WHERE id = 1" })],
  ["BIT(17)", op({ sql: "SELECT c_bit17 AS v FROM dt WHERE id = 1" })],
  ["BIT(64) all ones", op({ sql: "SELECT c_bit64 AS v FROM dt WHERE id = 1" })],
  ["DATE", op({ sql: "SELECT c_date AS v FROM dt WHERE id = 1" })],
  ["DATETIME(6)", op({ sql: "SELECT c_datetime6 AS v FROM dt WHERE id = 1" })],
  ["DATETIME without a fraction", op({ sql: "SELECT c_datetime AS v FROM dt WHERE id = 1" })],
  ["TIMESTAMP(3)", op({ sql: "SELECT c_timestamp3 AS v FROM dt WHERE id = 1" })],
  ["TIME(6)", op({ sql: "SELECT c_time6 AS v FROM dt WHERE id = 1" })],
  ["TIME past 24 hours", op({ sql: "SELECT c_time_big AS v FROM dt WHERE id = 1" })],
  ["negative TIME", op({ sql: "SELECT c_time_neg AS v FROM dt WHERE id = 1" })],
  ["YEAR", op({ sql: "SELECT c_year AS v FROM dt WHERE id = 1" })],
  ["JSON column", op({ sql: "SELECT c_json AS v FROM dt WHERE id = 1" })],
  ["ENUM and SET", op({ sql: "SELECT c_enum AS e, c_set AS s FROM dt WHERE id = 1" })],
  [
    "NULL versus empty string",
    op({ sql: "SELECT c_empty AS empty, c_null_int AS missing FROM dt WHERE id = 1" }),
  ],
  ["0x00 inside a BINARY", op({ sql: "SELECT c_binary AS v FROM dt WHERE id = 1" })],
  ["non-ASCII text round trip", op({ sql: "SELECT c_varchar AS v FROM dt WHERE id = 1" })],

  // Float layout: PHP and serde_json agree on the digits but not on the layout,
  // so every branch of the reformatter is pinned against the real engine.
  ["double 1.0", op({ sql: "SELECT CAST(1 AS DOUBLE) AS v" })],
  ["double 100.0", op({ sql: "SELECT CAST(100 AS DOUBLE) AS v" })],
  [
    "double at the fixed/scientific boundary",
    op({ sql: "SELECT 1e15 AS a, 1e16 AS b, 1e-5 AS c, 1e-6 AS d" }),
  ],
  [
    "double large and small",
    op({ sql: "SELECT 1e25 AS a, 1e-7 AS b, 1.7976931348623157e308 AS c" }),
  ],
  [
    "double with a long mantissa",
    op({ sql: "SELECT 0.1e0 + 0.2e0 AS v, 1234567890.12345e0 AS w" }),
  ],
  ["double negative exponent", op({ sql: "SELECT -1.25e-9 AS v" })],

  // Parameter typing: the wire type of a parameter is observable because
  // `SELECT ?` echoes it back as the result column's type.
  ["parameter null", op({ sql: "SELECT ? AS v", params: [null] })],
  ["parameter true", op({ sql: "SELECT ? AS v", params: [true] })],
  ["parameter false", op({ sql: "SELECT ? AS v", params: [false] })],
  ["parameter zero", op({ sql: "SELECT ? AS v", params: [0] })],
  ["parameter negative integer", op({ sql: "SELECT ? AS v", params: [-1] })],
  [
    "parameter integer beyond 2^53",
    op({ sql: "SELECT ? AS v", params: [Number("9007199254740993")] }),
  ],
  // Written out rather than built from a JS literal: JavaScript rounds the i64
  // floor to -9223372036854776000, which is a different value on both engines.
  ["parameter i64 floor", '{"sql":"SELECT ? AS v","params":[-9223372036854775808]}'],
  ["parameter i64 ceiling", '{"sql":"SELECT ? AS v","params":[9223372036854775807]}'],
  ["parameter float", op({ sql: "SELECT ? AS v", params: [1.5] })],
  ["parameter string", op({ sql: "SELECT ? AS v", params: ["str"] })],
  ["parameter empty string", op({ sql: "SELECT ? AS v", params: [""] })],
  ["parameter non-ASCII string", op({ sql: "SELECT ? AS v", params: ["héllo \u{1f600}"] })],
  [
    "parameter used in a predicate",
    op({ sql: "SELECT id FROM dt WHERE id = ? OR id = ?", params: [1, 3] }),
  ],

  // Errors: same codes, and for server-side failures the same message text.
  ["unknown table", op({ sql: "SELECT * FROM does_not_exist" })],
  ["unknown column", op({ sql: "SELECT nope FROM dt" })],
  ["syntax error", op({ sql: "SELEKT 1" })],
  ["execute mode failure", op({ mode: "execute", sql: "INSERT INTO dt (nope) VALUES (1)" })],
  ["parameter count mismatch", op({ sql: "SELECT ? AS v", params: [1, 2] })],
  ["empty SQL", op({ sql: "   " })],
  ["missing SQL", op({})],
  ["SQL containing NUL", op({ sql: "SELECT 1 " })],
  ["non-scalar parameter", op({ sql: "SELECT ? AS v", params: [[1, 2]] })],
  ["object parameter", op({ sql: "SELECT ? AS v", params: [{ a: 1 }] })],
  ["too many parameters", op({ sql: "SELECT 1", params: Array.from({ length: 257 }, () => 1) })],
  ["commit with no transaction", op({ mode: "transaction_commit" })],
  ["rollback with no transaction", op({ mode: "transaction_rollback" })],
  ["transaction with no statements", op({ mode: "transaction", statements: [] })],
  [
    "transaction with too many statements",
    op({
      mode: "transaction",
      statements: Array.from({ length: 65 }, () => ({ sql: "SELECT 1" })),
    }),
  ],
  ["unparsable operation", "{not json", "code"],
  ["operation is a JSON array", "[1,2,3]", "code"],
  ["params is not an array", op({ sql: "SELECT 1", params: 5 }), "code"],
  ["sql is not a string", op({ sql: 5 }), "code"],
  ["mode is not a string", op({ sql: "SELECT 1", mode: 5 }), "code"],
  ["oversized operation", op({ sql: "x".repeat(70_000) }), "bytes", "__repeat__:70000"],
];
