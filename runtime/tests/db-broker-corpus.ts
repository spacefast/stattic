// Shared schema and raw-operation helpers for the parent-process PHP broker.
// Tenant QuickJS uses the native structured capability instead of this protocol.

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
 * Operation text stays pure ASCII so the base64 hop into QuickJS is exact
 * without a UTF-8 decoder there. JSON's \uXXXX escapes lose nothing.
 */
export function op(value: unknown): string {
  return JSON.stringify(value).replace(
    /[\u007f-\uffff]/g,
    (character) => `\\u${character.charCodeAt(0).toString(16).padStart(4, "0")}`,
  );
}
