export type Schema = ObjectSchema | ArraySchema | StringSchema | NumberSchema | IntegerSchema | BooleanSchema | NullSchema;
export type _JSONSchema = boolean | JSONSchema;
export type SchemaType = "object" | "array" | "string" | "number" | "boolean" | "null" | "integer";
export type JSONSchema = {
	[k: string]: unknown;
	$schema?: "https://json-schema.org/draft/2020-12/schema" | "http://json-schema.org/draft-07/schema#" | "http://json-schema.org/draft-04/schema#";
	$id?: string;
	$anchor?: string;
	$ref?: string;
	$dynamicRef?: string;
	$dynamicAnchor?: string;
	$vocabulary?: Record<string, boolean>;
	$comment?: string;
	$defs?: Record<string, JSONSchema>;
	type?: SchemaType | SchemaType[];
	additionalItems?: _JSONSchema;
	unevaluatedItems?: _JSONSchema;
	prefixItems?: _JSONSchema[];
	items?: _JSONSchema | _JSONSchema[];
	contains?: _JSONSchema;
	additionalProperties?: _JSONSchema;
	unevaluatedProperties?: _JSONSchema;
	properties?: Record<string, _JSONSchema>;
	patternProperties?: Record<string, _JSONSchema>;
	dependentSchemas?: Record<string, _JSONSchema>;
	propertyNames?: _JSONSchema;
	if?: _JSONSchema;
	then?: _JSONSchema;
	else?: _JSONSchema;
	allOf?: JSONSchema[];
	anyOf?: JSONSchema[];
	oneOf?: JSONSchema[];
	not?: _JSONSchema;
	multipleOf?: number;
	maximum?: number;
	exclusiveMaximum?: number | boolean;
	minimum?: number;
	exclusiveMinimum?: number | boolean;
	maxLength?: number;
	minLength?: number;
	pattern?: string;
	maxItems?: number;
	minItems?: number;
	uniqueItems?: boolean;
	maxContains?: number;
	minContains?: number;
	maxProperties?: number;
	minProperties?: number;
	required?: string[];
	dependentRequired?: Record<string, string[]>;
	enum?: Array<string | number | boolean | null>;
	const?: string | number | boolean | null;
	id?: string;
	title?: string;
	description?: string;
	default?: unknown;
	deprecated?: boolean;
	readOnly?: boolean;
	writeOnly?: boolean;
	nullable?: boolean;
	examples?: unknown[];
	format?: string;
	contentMediaType?: string;
	contentEncoding?: string;
	contentSchema?: JSONSchema;
	_prefault?: unknown;
};
export type BaseSchema = JSONSchema;
export interface ObjectSchema extends JSONSchema {
	type: "object";
}
export interface ArraySchema extends JSONSchema {
	type: "array";
}
export interface StringSchema extends JSONSchema {
	type: "string";
}
export interface NumberSchema extends JSONSchema {
	type: "number";
}
export interface IntegerSchema extends JSONSchema {
	type: "integer";
}
export interface BooleanSchema extends JSONSchema {
	type: "boolean";
}
export interface NullSchema extends JSONSchema {
	type: "null";
}
/** The Standard interface. */
export interface StandardTypedV1<Input = unknown, Output = Input> {
	/** The Standard properties. */
	readonly "~standard": StandardTypedV1.Props<Input, Output>;
}
declare namespace StandardTypedV1 {
	/** The Standard properties interface. */
	interface Props<Input = unknown, Output = Input> {
		/** The version number of the standard. */
		readonly version: 1;
		/** The vendor name of the schema library. */
		readonly vendor: string;
		/** Inferred types associated with the schema. */
		readonly types?: Types<Input, Output> | undefined;
	}
	/** The Standard types interface. */
	interface Types<Input = unknown, Output = Input> {
		/** The input type of the schema. */
		readonly input: Input;
		/** The output type of the schema. */
		readonly output: Output;
	}
	/** Infers the input type of a Standard. */
	type InferInput<Schema extends StandardTypedV1> = NonNullable<Schema["~standard"]["types"]>["input"];
	/** Infers the output type of a Standard. */
	type InferOutput<Schema extends StandardTypedV1> = NonNullable<Schema["~standard"]["types"]>["output"];
}
/** The Standard Schema interface. */
export interface StandardSchemaV1<Input = unknown, Output = Input> {
	/** The Standard Schema properties. */
	readonly "~standard": StandardSchemaV1.Props<Input, Output>;
}
declare namespace StandardSchemaV1 {
	/** The Standard Schema properties interface. */
	interface Props<Input = unknown, Output = Input> extends StandardTypedV1.Props<Input, Output> {
		/** Validates unknown input values. */
		readonly validate: (value: unknown, options?: StandardSchemaV1.Options | undefined) => Result<Output> | Promise<Result<Output>>;
	}
	/** The result interface of the validate function. */
	type Result<Output> = SuccessResult<Output> | FailureResult;
	/** The result interface if validation succeeds. */
	interface SuccessResult<Output> {
		/** The typed output value. */
		readonly value: Output;
		/** The absence of issues indicates success. */
		readonly issues?: undefined;
	}
	interface Options {
		/** Implicit support for additional vendor-specific parameters, if needed. */
		readonly libraryOptions?: Record<string, unknown> | undefined;
	}
	/** The result interface if validation fails. */
	interface FailureResult {
		/** The issues of failed validation. */
		readonly issues: ReadonlyArray<Issue>;
	}
	/** The issue interface of the failure output. */
	interface Issue {
		/** The error message of the issue. */
		readonly message: string;
		/** The path of the issue, if any. */
		readonly path?: ReadonlyArray<PropertyKey | PathSegment> | undefined;
	}
	/** The path segment interface of the issue. */
	interface PathSegment {
		/** The key representing a path segment. */
		readonly key: PropertyKey;
	}
	/** The Standard types interface. */
	interface Types<Input = unknown, Output = Input> extends StandardTypedV1.Types<Input, Output> {
	}
	/** Infers the input type of a Standard. */
	type InferInput<Schema extends StandardTypedV1> = StandardTypedV1.InferInput<Schema>;
	/** Infers the output type of a Standard. */
	type InferOutput<Schema extends StandardTypedV1> = StandardTypedV1.InferOutput<Schema>;
}
/** The Standard JSON Schema interface. */
export interface StandardJSONSchemaV1<Input = unknown, Output = Input> {
	/** The Standard JSON Schema properties. */
	readonly "~standard": StandardJSONSchemaV1.Props<Input, Output>;
}
declare namespace StandardJSONSchemaV1 {
	/** The Standard JSON Schema properties interface. */
	interface Props<Input = unknown, Output = Input> extends StandardTypedV1.Props<Input, Output> {
		/** Methods for generating the input/output JSON Schema. */
		readonly jsonSchema: Converter;
	}
	/** The Standard JSON Schema converter interface. */
	interface Converter {
		/** Converts the input type to JSON Schema. May throw if conversion is not supported. */
		readonly input: (options: StandardJSONSchemaV1.Options) => Record<string, unknown>;
		/** Converts the output type to JSON Schema. May throw if conversion is not supported. */
		readonly output: (options: StandardJSONSchemaV1.Options) => Record<string, unknown>;
	}
	/** The target version of the generated JSON Schema.
	 *
	 * It is *strongly recommended* that implementers support `"draft-2020-12"` and `"draft-07"`, as they are both in wide use.
	 *
	 * The `"openapi-3.0"` target is intended as a standardized specifier for OpenAPI 3.0 which is a superset of JSON Schema `"draft-04"`.
	 *
	 * All other targets can be implemented on a best-effort basis. Libraries should throw if they don't support a specified target.
	 */
	type Target = "draft-2020-12" | "draft-07" | "openapi-3.0" | ({} & string);
	/** The options for the input/output methods. */
	interface Options {
		/** Specifies the target version of the generated JSON Schema. Support for all versions is on a best-effort basis. If a given version is not supported, the library should throw. */
		readonly target: Target;
		/** Implicit support for additional vendor-specific parameters, if needed. */
		readonly libraryOptions?: Record<string, unknown> | undefined;
	}
	/** The Standard types interface. */
	interface Types<Input = unknown, Output = Input> extends StandardTypedV1.Types<Input, Output> {
	}
	/** Infers the input type of a Standard. */
	type InferInput<Schema extends StandardTypedV1> = StandardTypedV1.InferInput<Schema>;
	/** Infers the output type of a Standard. */
	type InferOutput<Schema extends StandardTypedV1> = StandardTypedV1.InferOutput<Schema>;
}
export interface StandardSchemaWithJSONProps<Input = unknown, Output = Input> extends StandardSchemaV1.Props<Input, Output>, StandardJSONSchemaV1.Props<Input, Output> {
}
declare const $output: unique symbol;
export type $output = typeof $output;
declare const $input: unique symbol;
export type $input = typeof $input;
export type $replace<Meta, S extends $ZodType> = Meta extends $output ? output<S> : Meta extends $input ? input<S> : Meta extends (infer M)[] ? $replace<M, S>[] : Meta extends (...args: infer P) => infer R ? (...args: {
	[K in keyof P]: $replace<P[K], S>;
}) => $replace<R, S> : Meta extends object ? {
	[K in keyof Meta]: $replace<Meta[K], S>;
} : Meta;
export type MetadataType = object | undefined;
declare class $ZodRegistry<Meta extends MetadataType = MetadataType, Schema extends $ZodType = $ZodType> {
	_meta: Meta;
	_schema: Schema;
	_map: WeakMap<Schema, $replace<Meta, Schema>>;
	_idmap: Map<string, Schema>;
	add<S extends Schema>(schema: S, ..._meta: undefined extends Meta ? [
		$replace<Meta, S>?
	] : [
		$replace<Meta, S>
	]): this;
	clear(): this;
	remove(schema: Schema): this;
	get<S extends Schema>(schema: S): $replace<Meta, S> | undefined;
	has(schema: Schema): boolean;
}
export interface JSONSchemaMeta {
	id?: string | undefined;
	title?: string | undefined;
	description?: string | undefined;
	deprecated?: boolean | undefined;
	[k: string]: unknown;
}
export interface GlobalMeta extends JSONSchemaMeta {
}
declare function registry<T extends MetadataType = MetadataType, S extends $ZodType = $ZodType>(): $ZodRegistry<T, S>;
declare const globalRegistry: $ZodRegistry<GlobalMeta>;
export type Processor<T extends schemas.$ZodType = schemas.$ZodType> = (schema: T, ctx: ToJSONSchemaContext, json: JSONSchema$1.BaseSchema, params: ProcessParams) => void;
/**
 * Called for each schema that has no JSON Schema equivalent. Return a JSON Schema to use in its
 * place, `"any"` to fall back to the `unrepresentable: "any"` behavior, or `"throw"`/`undefined` to
 * throw the default error. Throwing from the handler propagates, so custom errors work too.
 */
export type UnrepresentableHandler<T extends schemas.$ZodType = schemas.$ZodType> = (ctx: {
	zodSchema: T;
	path: (string | number)[];
	/** The error Zod would throw. Distinguishes sites that share a `zodSchema`, e.g. an `undefined`
	 *  vs a `bigint` member of the same literal. */
	message: string;
}) => JSONSchema$1.BaseSchema | "throw" | "any" | undefined;
export interface JSONSchemaGeneratorParams {
	processors: Record<string, Processor>;
	/** A registry used to look up metadata for each schema. Any schema with an `id` property will be extracted as a $def.
	 *  @default globalRegistry */
	metadata?: $ZodRegistry<Record<string, any>>;
	/** The JSON Schema version to target.
	 * - `"draft-2020-12"` — Default. JSON Schema Draft 2020-12
	 * - `"draft-07"` — JSON Schema Draft 7
	 * - `"draft-04"` — JSON Schema Draft 4
	 * - `"openapi-3.0"` — OpenAPI 3.0 Schema Object */
	target?: "draft-04" | "draft-07" | "draft-2020-12" | "openapi-3.0" | ({} & string) | undefined;
	/** How to handle unrepresentable types.
	 * - `"throw"` — Default. Unrepresentable types throw an error
	 * - `"any"` — Unrepresentable types become `{}`
	 * - A function — called once per unrepresentable schema; see {@link UnrepresentableHandler}. */
	unrepresentable?: "throw" | "any" | UnrepresentableHandler<schemas.$ZodTypes>;
	/** Arbitrary custom logic that can be used to modify the generated JSON Schema. */
	override?: (ctx: {
		zodSchema: schemas.$ZodTypes;
		jsonSchema: JSONSchema$1.BaseSchema;
		path: (string | number)[];
	}) => void;
	/** Whether to extract the `"input"` or `"output"` type. Relevant to transforms, defaults, coerced primitives, etc.
	 * - `"output"` — Default. Convert the output schema.
	 * - `"input"` — Convert the input schema. */
	io?: "input" | "output";
	cycles?: "ref" | "throw";
	reused?: "ref" | "inline";
	external?: {
		registry: $ZodRegistry<{
			id?: string | undefined;
		}>;
		uri?: ((id: string) => string) | undefined;
		defs: Record<string, JSONSchema$1.BaseSchema>;
	} | undefined;
}
/**
 * Parameters for the toJSONSchema function.
 */
export type ToJSONSchemaParams = Omit<JSONSchemaGeneratorParams, "processors" | "external">;
/**
 * Parameters for the toJSONSchema function when passing a registry.
 */
export interface RegistryToJSONSchemaParams extends ToJSONSchemaParams {
	uri?: (id: string) => string;
}
export interface ProcessParams {
	schemaPath: schemas.$ZodType[];
	path: (string | number)[];
}
export interface Seen {
	/** JSON Schema result for this Zod schema */
	schema: JSONSchema$1.BaseSchema;
	/** A cached version of the schema that doesn't get overwritten during ref resolution */
	def?: JSONSchema$1.BaseSchema;
	defId?: string | undefined;
	/** Number of times this schema was encountered during traversal */
	count: number;
	/** Cycle path */
	cycle?: (string | number)[] | undefined;
	isParent?: boolean | undefined;
	/** Schema to inherit JSON Schema properties from (set by processor for wrappers) */
	ref?: schemas.$ZodType | null;
	/** JSON Schema property path for this schema */
	path?: (string | number)[] | undefined;
}
export interface ToJSONSchemaContext {
	processors: Record<string, Processor>;
	metadataRegistry: $ZodRegistry<Record<string, any>>;
	target: "draft-04" | "draft-07" | "draft-2020-12" | "openapi-3.0" | ({} & string);
	unrepresentable: "throw" | "any" | UnrepresentableHandler;
	override: (ctx: {
		zodSchema: schemas.$ZodType;
		jsonSchema: JSONSchema$1.BaseSchema;
		path: (string | number)[];
	}) => void;
	io: "input" | "output";
	counter: number;
	seen: Map<schemas.$ZodType, Seen>;
	/** Registry conversions share one `seen` map across every emitted schema. These hold the
	 * `external` the whole-map passes below last ran for, so the passes are not repeated once per
	 * schema — and still re-run if the map grows or `external` is swapped. `sharedEmitDoneFor`
	 * covers both passes in `finalize`: the ref flattening and the `$defs` build.
	 *
	 * The passes are valid only while nothing they read has changed, so both are cleared in
	 * `process()` when the map grows, and in `JSONSchemaGenerator.emit()`, which can also change
	 * the `cycles` and `reused` they branch on.
	 *
	 * One case is deliberately not covered: an `override` callback that writes to
	 * `metadataRegistry` mid-conversion. It runs inside `finalize`, so a registry conversion has
	 * nowhere left to clear the guards, and later schemas keep the ids the first pass saw. That
	 * output was never coherent — before this, whether a shared subschema was inlined or extracted
	 * depended on which registry entry happened to be emitted when the callback fired. */
	sharedDefsExtractedFor?: ToJSONSchemaContext["external"];
	sharedEmitDoneFor?: ToJSONSchemaContext["external"];
	cycles: "ref" | "throw";
	reused: "ref" | "inline";
	/** The `allOf` array of each intersection encountered during traversal, innermost first. `finalize` folds every emitted object holding one; see `foldIntersection`. */
	intersections: JSONSchema$1.BaseSchema[][];
	/** Rewrites a processor deferred to `finalize`, where the flatten has resolved every ref and the union branches are in place. */
	deferred: (() => void)[];
	external?: {
		registry: $ZodRegistry<{
			id?: string | undefined;
		}>;
		uri?: ((id: string) => string) | undefined;
		defs: Record<string, JSONSchema$1.BaseSchema>;
	} | undefined;
}
declare function initializeContext(params: JSONSchemaGeneratorParams): ToJSONSchemaContext;
declare function handleUnrepresentable(schema: schemas.$ZodType, ctx: ToJSONSchemaContext, json: JSONSchema$1.BaseSchema, params: ProcessParams, message: string): boolean;
declare function process<T extends schemas.$ZodType>(schema: T, ctx: ToJSONSchemaContext, _params?: ProcessParams): JSONSchema$1.BaseSchema;
declare function extractDefs<T extends schemas.$ZodType>(ctx: ToJSONSchemaContext, schema: T): void;
declare function finalize<T extends schemas.$ZodType>(ctx: ToJSONSchemaContext, schema: T): ZodStandardJSONSchemaPayload<T>;
export type ZodStandardSchemaWithJSON<T> = StandardSchemaWithJSONProps<core.input<T>, core.output<T>>;
export interface ZodStandardJSONSchemaPayload<T> extends JSONSchema$1.BaseSchema {
	"~standard": ZodStandardSchemaWithJSON<T>;
}
declare const createToJSONSchemaMethod: <T extends schemas.$ZodType>(schema: T, processors?: Record<string, Processor>) => (params?: ToJSONSchemaParams) => ZodStandardJSONSchemaPayload<T>;
/**
 * Creates a toJSONSchema method for a schema instance.
 * This encapsulates the logic of initializing context, processing, extracting defs, and finalizing.
 */
export type StandardJSONSchemaMethodParams = Parameters<StandardJSONSchemaV1["~standard"]["jsonSchema"]["input"]>[0];
declare const createStandardJSONSchemaMethod: <T extends schemas.$ZodType>(schema: T, io: "input" | "output", processors?: Record<string, Processor>) => (params?: StandardJSONSchemaMethodParams) => JSONSchema$1.BaseSchema;
export type JSONType = string | number | boolean | null | JSONType[] | {
	[key: string]: JSONType;
};
export type JWTAlgorithm = "HS256" | "HS384" | "HS512" | "RS256" | "RS384" | "RS512" | "ES256" | "ES384" | "ES512" | "PS256" | "PS384" | "PS512" | "EdDSA" | (string & {});
export type HashAlgorithm = "md5" | "sha1" | "sha256" | "sha384" | "sha512";
export type HashEncoding = "hex" | "base64" | "base64url";
export type HashFormat = `${HashAlgorithm}_${HashEncoding}`;
export type IPVersion = "v4" | "v6";
export type MimeTypes = "application/json" | "application/xml" | "application/x-www-form-urlencoded" | "application/javascript" | "application/pdf" | "application/zip" | "application/vnd.ms-excel" | "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" | "application/msword" | "application/vnd.openxmlformats-officedocument.wordprocessingml.document" | "application/vnd.ms-powerpoint" | "application/vnd.openxmlformats-officedocument.presentationml.presentation" | "application/octet-stream" | "application/graphql" | "text/html" | "text/plain" | "text/css" | "text/javascript" | "text/csv" | "image/png" | "image/jpeg" | "image/gif" | "image/svg+xml" | "image/webp" | "audio/mpeg" | "audio/ogg" | "audio/wav" | "audio/webm" | "video/mp4" | "video/webm" | "video/ogg" | "font/woff" | "font/woff2" | "font/ttf" | "font/otf" | "multipart/form-data" | (string & {});
export type ParsedTypes = "string" | "number" | "bigint" | "boolean" | "symbol" | "undefined" | "object" | "function" | "file" | "date" | "array" | "map" | "set" | "nan" | "null" | "promise";
export type AssertEqual<T, U> = (<V>() => V extends T ? 1 : 2) extends <V>() => V extends U ? 1 : 2 ? true : false;
export type AssertNotEqual<T, U> = (<V>() => V extends T ? 1 : 2) extends <V>() => V extends U ? 1 : 2 ? false : true;
export type AssertExtends<T, U> = T extends U ? T : never;
export type ToZodMismatch<T, S extends schemas.$ZodType> = {
	"types do not match": {
		expected: T;
		received: S["_zod"]["output"];
	};
};
export type ToZodKeyMismatch<Expected, Received> = schemas.$ZodType & {
	"types do not match": {
		expected: Expected;
		received: Received;
	};
};
export type ToZodShape<Shape, T> = {
	[K in keyof Shape]: Shape[K] extends schemas.$ZodType ? K extends keyof T ? AssertEqual<Shape[K]["_zod"]["output"], T[K]> extends true ? Shape[K] : ToZodKeyMismatch<T[K], Shape[K]["_zod"]["output"]> : ToZodKeyMismatch<never, Shape[K]["_zod"]["output"]> : Shape[K];
} & {
	[K in Exclude<keyof T, keyof Shape>]: ToZodKeyMismatch<T[K], never>;
};
export type ToZodExpand<X> = {
	[K in keyof X]: X[K];
} & {};
export type ToZodTarget<S extends schemas.$ZodType, T> = S extends schemas.$ZodObject<infer Shape, infer Config> ? T extends object ? AssertEqual<ToZodExpand<ToZodShape<Shape, T>>, ToZodExpand<Shape>> extends true ? S & ToZodMismatch<T, S> : schemas.$ZodObject<ToZodExpand<ToZodShape<Shape, T>>, Config> : S & ToZodMismatch<T, S> : S & ToZodMismatch<T, S>;
export type IsAny<T> = 0 extends 1 & T ? true : false;
type Omit$1<T, K extends keyof T> = Pick<T, Exclude<keyof T, K>>;
export type OmitKeys<T, K extends string> = Pick<T, Exclude<keyof T, K>>;
export type MakePartial<T, K extends keyof T> = Omit$1<T, K> & InexactPartial<Pick<T, K>>;
export type MakeRequired<T, K extends keyof T> = Omit$1<T, K> & Required<Pick<T, K>>;
export type Exactly<T, X> = T & Record<Exclude<keyof X, keyof T>, never>;
export type NoUndefined<T> = T extends undefined ? never : T;
export type Whatever = {} | undefined | null;
export type LoosePartial<T extends object> = InexactPartial<T> & {
	[k: string]: unknown;
};
export type Mask<Keys extends PropertyKey> = {
	[K in Keys]?: true;
};
export type Writeable<T> = {
	-readonly [P in keyof T]: T[P];
} & {};
export type InexactPartial<T> = {
	[P in keyof T]?: T[P] | undefined;
};
export type EmptyObject = Record<string, never>;
export type BuiltIn = (((...args: any[]) => any) | (new (...args: any[]) => any)) | {
	readonly [Symbol.toStringTag]: string;
} | Date | Error | Generator | Promise<unknown> | RegExp;
export type MakeReadonly<T> = T extends Map<infer K, infer V> ? ReadonlyMap<K, V> : T extends Set<infer V> ? ReadonlySet<V> : T extends [
	infer Head,
	...infer Tail
] ? readonly [
	Head,
	...Tail
] : T extends Array<infer V> ? ReadonlyArray<V> : T extends BuiltIn ? T : Readonly<T>;
export type SomeObject = Record<PropertyKey, any>;
export type Identity<T> = T;
export type Flatten<T> = Identity<{
	[k in keyof T]: T[k];
}>;
export type Mapped<T> = {
	[k in keyof T]: T[k];
};
export type Prettify<T> = {
	[K in keyof T]: T[K];
} & {};
export type NoNeverKeys<T> = {
	[k in keyof T]: [
		T[k]
	] extends [
		never
	] ? never : k;
}[keyof T];
export type NoNever<T> = Identity<{
	[k in NoNeverKeys<T>]: k extends keyof T ? T[k] : never;
}>;
export type Extend<A extends SomeObject, B extends SomeObject> = Flatten<keyof A & keyof B extends never ? A & B : {
	[K in keyof A as K extends keyof B ? never : K]: A[K];
} & {
	[K in keyof B]: B[K];
}>;
export type TupleItems = ReadonlyArray<schemas.SomeType>;
export type AnyFunc = (...args: any[]) => any;
export type IsProp<T, K extends keyof T> = T[K] extends AnyFunc ? never : K;
export type MaybeAsync<T> = T | Promise<T>;
export type KeyOf<T> = keyof OmitIndexSignature<T>;
export type OmitIndexSignature<T> = {
	[K in keyof T as string extends K ? never : K extends string ? K : never]: T[K];
};
export type ExtractIndexSignature<T> = {
	[K in keyof T as string extends K ? K : K extends string ? never : K]: T[K];
};
export type Keys<T extends object> = keyof OmitIndexSignature<T>;
export type SchemaClass<T extends schemas.SomeType> = {
	new (def: T["_zod"]["def"]): T;
};
export type EnumValue = string | number;
export type EnumLike = Readonly<Record<string, EnumValue>>;
export type ToEnum<T extends EnumValue> = Flatten<{
	[k in T]: k;
}>;
export type KeysEnum<T extends object> = ToEnum<Exclude<keyof T, symbol>>;
export type KeysArray<T extends object> = Flatten<(keyof T & string)[]>;
export type Literal = string | number | bigint | boolean | null | undefined;
export type LiteralArray = Array<Literal>;
export type Primitive = string | number | symbol | bigint | boolean | null | undefined;
export type PrimitiveArray = Array<Primitive>;
export type HasSize = {
	size: number;
};
export type HasLength = {
	length: number;
};
export type Numeric = number | bigint | Date;
export type SafeParseResult<T> = SafeParseSuccess<T> | SafeParseError<T>;
export type SafeParseSuccess<T> = {
	success: true;
	data: T;
	error?: never;
};
export type SafeParseError<T> = {
	success: false;
	data?: never;
	error: $ZodError<T>;
};
export type PropValues = Record<string, Set<Primitive>>;
export type PrimitiveSet = Set<Primitive>;
declare function assertEqual<A, B>(val: AssertEqual<A, B>): AssertEqual<A, B>;
declare function assertNotEqual<A, B>(val: AssertNotEqual<A, B>): AssertNotEqual<A, B>;
declare function toZod<T>(): <S extends schemas.$ZodType>(schema: AssertEqual<S["_zod"]["output"], T> extends true ? S : ToZodTarget<S, T>) => S;
declare function assertIs<T>(_arg: T): void;
declare function assertNever(_x: never): never;
declare function assert<T>(_: any): asserts _ is T;
declare function getEnumValues(entries: EnumLike): EnumValue[];
declare function joinValues<T extends Primitive[]>(array: T, separator?: string): string;
declare function jsonStringifyReplacer(_: string, value: any): any;
declare function cached<T>(getter: () => T): {
	value: T;
};
declare function nullish(input: any): boolean;
declare function cleanRegex(source: string): string;
declare function floatSafeRemainder(val: number, step: number): number;
declare function defineLazy<T, K extends keyof T>(object: T, key: K, getter: () => T[K]): void;
declare function objectClone(obj: object): any;
declare function assignProp<T extends object, K extends PropertyKey>(target: T, prop: K, value: K extends keyof T ? T[K] : any): void;
declare function mergeDefs(...defs: Record<string, any>[]): any;
declare function cloneDef(schema: schemas.$ZodType): any;
declare function getElementAtPath(obj: any, path: (string | number)[] | null | undefined): any;
declare function promiseAllObject<T extends object>(promisesObj: T): Promise<{
	[k in keyof T]: Awaited<T[k]>;
}>;
declare function randomString(length?: number): string;
declare function esc(str: string): string;
declare function slugify(input: string): string;
declare const captureStackTrace: (targetObject: object, constructorOpt?: Function) => void;
declare function isObject(data: any): data is Record<PropertyKey, unknown>;
declare const allowsEval: {
	value: boolean;
};
declare function isPlainObject(o: any): o is Record<PropertyKey, unknown>;
declare function shallowClone(o: any): any;
declare function numKeys(data: any): number;
declare const getParsedType: (data: any) => ParsedTypes;
declare const propertyKeyTypes: Set<string>;
declare const primitiveTypes: Set<string>;
declare function escapeRegex(str: string): string;
declare function clone<T extends schemas.$ZodType>(inst: T, def?: T["_zod"]["def"], params?: {
	parent: boolean;
}): T;
export type EmptyToNever<T> = keyof T extends never ? never : T;
export type Normalize<T> = T extends undefined ? never : T extends Record<any, any> ? Flatten<{
	[k in keyof Omit$1<T, "error" | "message">]: T[k];
} & ("error" extends keyof T ? {
	error?: Exclude<T["error"], string>;
} : unknown)> : never;
declare function normalizeParams<T>(_params: T): Normalize<T>;
declare function createTransparentProxy<T extends object>(getter: () => T): T;
declare function stringifyPrimitive(value: any): string;
declare function optionalKeys(shape: schemas.$ZodShape): string[];
export type CleanKey<T extends PropertyKey> = T extends `?${infer K}` ? K : T extends `${infer K}?` ? K : T;
export type ToCleanMap<T extends schemas.$ZodLooseShape> = {
	[k in keyof T]: k extends `?${infer K}` ? K : k extends `${infer K}?` ? K : k;
};
export type FromCleanMap<T extends schemas.$ZodLooseShape> = {
	[k in keyof T as k extends `?${infer K}` ? K : k extends `${infer K}?` ? K : k]: k;
};
declare const NUMBER_FORMAT_RANGES: Record<$ZodNumberFormats, [
	number,
	number
]>;
declare const BIGINT_FORMAT_RANGES: Record<$ZodBigIntFormats, [
	bigint,
	bigint
]>;
declare function pick(schema: schemas.$ZodObject, mask: Record<string, unknown>): any;
declare function omit(schema: schemas.$ZodObject, mask: object): any;
declare function extend(schema: schemas.$ZodObject, shape: schemas.$ZodShape): any;
declare function safeExtend(schema: schemas.$ZodObject, shape: schemas.$ZodShape): any;
declare function merge(a: schemas.$ZodObject, b: schemas.$ZodObject): any;
declare function partial(Class: SchemaClass<schemas.$ZodOptional> | null, schema: schemas.$ZodObject, mask: object | undefined, name?: string): any;
declare function required(Class: SchemaClass<schemas.$ZodNonOptional>, schema: schemas.$ZodObject, mask: object | undefined): any;
export type Constructor<T, Def extends any[] = any[]> = new (...args: Def) => T;
declare function aborted(x: schemas.ParsePayload, startIndex?: number): boolean;
declare function explicitlyAborted(x: schemas.ParsePayload, startIndex?: number): boolean;
declare function prefixIssues(path: PropertyKey, issues: $ZodRawIssue[]): $ZodRawIssue[];
declare function unwrapMessage(message: string | {
	message: string;
} | undefined | null): string | undefined;
declare function attachSchema(issues: $ZodRawIssue[], start: number, inst: schemas.$ZodType): void;
declare function finalizeIssue(iss: $ZodRawIssue, ctx: schemas.ParseContextInternal | undefined, config: $ZodConfig): $ZodIssue;
declare function getSizableOrigin(input: any): "set" | "map" | "file" | "unknown";
declare function codePointLength(str: string): number;
declare function getLengthableOrigin(input: any): "array" | "string" | "unknown";
declare function parsedType(data: unknown): $ZodInvalidTypeExpected;
declare function issue(_iss: string, input: any, inst: any): $ZodRawIssue;
declare function issue(_iss: $ZodRawIssue): $ZodRawIssue;
declare function cleanEnum(obj: Record<string, EnumValue>): EnumValue[];
declare function base64ToUint8Array(base64: string): InstanceType<typeof Uint8Array>;
declare function uint8ArrayToBase64(bytes: Uint8Array): string;
declare function base64urlToUint8Array(base64url: string): InstanceType<typeof Uint8Array>;
declare function uint8ArrayToBase64url(bytes: Uint8Array): string;
declare function hexToUint8Array(hex: string): InstanceType<typeof Uint8Array>;
declare function uint8ArrayToHex(bytes: Uint8Array): string;
declare abstract class Class {
	constructor(..._args: any[]);
}
declare function members(proto: object, table: object): void;
declare function own<T>(inst: object, key: string, value: T, enumerable?: boolean): T;
declare function hide<T>(inst: object, key: string, value: T): T;
/** A trait's prototype members: a partial view of its own interface, with `this` typed as the instance. */
export type ProtoOf<T> = {
	[K in keyof T]?: (T[K] extends (...args: infer A) => infer R ? (...args: A) => R : T[K]) | undefined;
} & ThisType<T>;
declare function defineLazyInternal<T extends {
	_zod: any;
}>(inst: T, key: string, compute: (zod: T["_zod"]) => unknown): void;
declare function installLazyProp(inst: object, key: string, make: (self: any) => unknown, enumerable: boolean): void;
declare const CONSTANT_CATCH = "~constantCatch";
declare function constantCatch<T>(value: T): () => T;
declare const version: {
	readonly major: 4;
	readonly minor: 5;
	readonly patch: number;
};
export interface ParseContext<T extends $ZodIssueBase = never> {
	/** Customize error messages. */
	readonly error?: $ZodErrorMap<T>;
	/** Include the `input` field in issue objects. Default `false`. */
	readonly reportInput?: boolean;
	/** Skip eval-based fast path. Default `false`. */
	readonly jitless?: boolean;
}
/** @internal */
export interface ParseContextInternal<T extends $ZodIssueBase = never> extends ParseContext<T> {
	readonly async?: boolean | undefined;
	readonly direction?: "forward" | "backward";
	readonly skipChecks?: boolean;
}
/** Gives a container cycle support: `attach` wraps its parse, and `alloc` registers the object it builds into before any child is parsed so a reference back to the same input resolves to it. */
export interface $ZodMemoizer {
	attach(inst: $ZodType): void;
	guard(inst: $ZodType): void;
	alloc<T extends object>(inst: $ZodType, payload: ParsePayload, empty: T, ctx: ParseContextInternal): T;
}
export interface ParsePayload<T = unknown> {
	value: T;
	issues: $ZodRawIssue[];
	/** A way to mark a whole payload as aborted. Used in codecs/pipes. */
	aborted?: boolean;
	/** @internal Set when the value came from a repeat visit to a node still being
	 * parsed. Its checks run on the node itself, against the finished value. */
	memo?: boolean | undefined;
}
export type CheckFn<T> = (input: ParsePayload<T>) => util.MaybeAsync<void>;
export interface $ZodTypeDef {
	type: "string" | "number" | "int" | "boolean" | "bigint" | "symbol" | "null" | "undefined" | "void" | "never" | "any" | "unknown" | "date" | "object" | "record" | "file" | "array" | "tuple" | "union" | "intersection" | "map" | "set" | "enum" | "literal" | "nullable" | "optional" | "nonoptional" | "success" | "transform" | "default" | "prefault" | "catch" | "nan" | "pipe" | "readonly" | "template_literal" | "promise" | "lazy" | "function" | "custom";
	error?: $ZodErrorMap<never> | undefined;
	checks?: $ZodCheck<never>[];
}
export interface _$ZodTypeInternals {
	/** The `@zod/core` version of this schema */
	version: typeof version;
	/** Schema definition. */
	def: $ZodTypeDef;
	/** @internal Randomly generated ID for this schema. */
	/** @internal List of deferred initializers. */
	deferred: util.AnyFunc[] | undefined;
	/** @internal Parses input and runs all checks (refinements). */
	run(payload: ParsePayload<any>, ctx: ParseContextInternal): util.MaybeAsync<ParsePayload>;
	/** @internal Parses input, doesn't run checks. */
	parse(payload: ParsePayload<any>, ctx: ParseContextInternal): util.MaybeAsync<ParsePayload>;
	/** @internal  Stores identifiers for the set of traits implemented by this schema. */
	traits: Set<string>;
	/** @internal Indicates that a schema output type should be considered optional inside objects.
	 * @default Required
	 */
	/** @internal Three rungs, each strictly stronger than the last:
	 *   undefined    — required; the container may not omit this slot
	 *   "optional"   — the container may omit it; nothing is supplied in its place
	 *   "defaulted"  — the container may omit it AND this schema substitutes a value
	 * Consumers asking "may the slot be absent?" test `!== undefined`.
	 * $ZodOptional asks the stronger question and tests `=== "defaulted"`. */
	optin?: "optional" | "defaulted" | undefined;
	/** @internal */
	optout?: "optional" | undefined;
	/** @internal The set of literal values that will pass validation. Must be an exhaustive set. Used to determine optionality in z.record().
	 *
	 * Defined on: enum, const, literal, null, undefined
	 * Passthrough: optional, nullable, branded, default, catch, pipe
	 * Todo: unions?
	 */
	values?: util.PrimitiveSet | undefined;
	/** Default value bubbled up from  */
	/** @internal A set of literal discriminators used for the fast path in discriminated unions. */
	propValues?: util.PropValues | undefined;
	/** @internal This flag indicates that a schema validation can be represented with a regular expression. Used to determine allowable schemas in z.templateLiteral(). */
	pattern: RegExp | undefined;
	/** @internal The constructor function of this schema. */
	constr: new (def: any) => $ZodType;
	/** @internal A catchall object for bag metadata related to this schema. Commonly modified by checks using `onattach`. */
	bag: Record<string, unknown>;
	/** @internal The set of issues this schema might throw during type checking. */
	isst: $ZodIssueBase;
	/** @internal Subject to change, not a public API. */
	processJSONSchema?: ((ctx: ToJSONSchemaContext, json: JSONSchema$1.BaseSchema, params: ProcessParams) => void) | undefined;
	/** An optional method used to override `toJSONSchema` logic. */
	toJSONSchema?: () => unknown;
	/** @internal The parent of this schema. Only set during certain clone operations. */
	parent?: $ZodType | undefined;
}
/** @internal */
export interface $ZodTypeInternals<out O = unknown, out I = unknown> extends _$ZodTypeInternals {
	/** @internal The inferred output type */
	output: O;
	/** @internal The inferred input type */
	input: I;
}
export type $ZodStandardSchema<T> = StandardSchemaV1.Props<input<T>, output<T>>;
export type SomeType = {
	_zod: _$ZodTypeInternals;
};
export interface $ZodType<O = unknown, I = unknown, Internals extends $ZodTypeInternals<O, I> = $ZodTypeInternals<O, I>> {
	_zod: Internals;
	"~standard": $ZodStandardSchema<this>;
}
export interface _$ZodType<T extends $ZodTypeInternals = $ZodTypeInternals> extends $ZodType<T["output"], T["input"], T> {
}
declare const $ZodType: $constructor<$ZodType>;
declare function standardProps(inst: $ZodType): StandardSchemaV1.Props<any, any>;
export interface $ZodStringDef extends $ZodTypeDef {
	type: "string";
	coerce?: boolean;
	checks?: $ZodCheck<string>[];
}
export interface $ZodStringInternals<Input> extends $ZodTypeInternals<string, Input> {
	def: $ZodStringDef;
	/** @deprecated Internal API, use with caution (not deprecated) */
	pattern: RegExp;
	/** @deprecated Internal API, use with caution (not deprecated) */
	isst: $ZodIssueInvalidType;
	bag: util.LoosePartial<{
		minimum: number;
		maximum: number;
		patterns: Set<RegExp>;
		format: string;
		contentEncoding: string;
		/** the pattern admits strings its `format` keyword forbids, so `toJSONSchema` must not emit it */
		laxFormat: boolean;
	}>;
}
export interface $ZodString<Input = unknown> extends _$ZodType<$ZodStringInternals<Input>> {
}
declare const $ZodString: $constructor<$ZodString>;
export interface $ZodStringFormatDef<Format extends string = string> extends $ZodStringDef, $ZodCheckStringFormatDef<Format> {
}
export interface $ZodStringFormatInternals<Format extends string = string> extends $ZodStringInternals<string>, $ZodCheckStringFormatInternals {
	def: $ZodStringFormatDef<Format>;
}
export interface $ZodStringFormat<Format extends string = string> extends $ZodType {
	_zod: $ZodStringFormatInternals<Format>;
}
declare const $ZodStringFormat: $constructor<$ZodStringFormat>;
export interface $ZodGUIDDef extends $ZodStringFormatDef<"guid"> {
}
export interface $ZodGUIDInternals extends $ZodStringFormatInternals<"guid"> {
}
export interface $ZodGUID extends $ZodType {
	_zod: $ZodGUIDInternals;
}
declare const $ZodGUID: $constructor<$ZodGUID>;
export interface $ZodUUIDDef extends $ZodStringFormatDef<"uuid"> {
	version?: "v1" | "v2" | "v3" | "v4" | "v5" | "v6" | "v7" | "v8";
}
export interface $ZodUUIDInternals extends $ZodStringFormatInternals<"uuid"> {
	def: $ZodUUIDDef;
}
export interface $ZodUUID extends $ZodType {
	_zod: $ZodUUIDInternals;
}
declare const $ZodUUID: $constructor<$ZodUUID>;
export interface $ZodEmailDef extends $ZodStringFormatDef<"email"> {
}
export interface $ZodEmailInternals extends $ZodStringFormatInternals<"email"> {
}
export interface $ZodEmail extends $ZodType {
	_zod: $ZodEmailInternals;
}
declare const $ZodEmail: $constructor<$ZodEmail>;
export interface $ZodURLDef extends $ZodStringFormatDef<"url"> {
	hostname?: RegExp | undefined;
	protocol?: RegExp | undefined;
	normalize?: boolean | undefined;
}
export interface $ZodURLInternals extends $ZodStringFormatInternals<"url"> {
	def: $ZodURLDef;
}
export interface $ZodURL extends $ZodType {
	_zod: $ZodURLInternals;
}
declare const URL_BAD_FORMAT = 1;
declare const URL_UNPARSEABLE = 2;
declare function parseURLObject(trimmed: string, def: Pick<$ZodURLDef, "protocol" | "normalize">): URL | typeof URL_BAD_FORMAT | typeof URL_UNPARSEABLE;
declare function stripTabAndNewline(value: string): string;
declare function urlHostnameOk(url: URL, hostname: RegExp): boolean;
declare function urlProtocolOk(url: URL, protocol: RegExp): boolean;
declare const $ZodURL: $constructor<$ZodURL>;
export interface $ZodEmojiDef extends $ZodStringFormatDef<"emoji"> {
}
export interface $ZodEmojiInternals extends $ZodStringFormatInternals<"emoji"> {
}
export interface $ZodEmoji extends $ZodType {
	_zod: $ZodEmojiInternals;
}
declare const $ZodEmoji: $constructor<$ZodEmoji>;
export interface $ZodNanoIDDef extends $ZodStringFormatDef<"nanoid"> {
	length?: number | undefined;
}
export interface $ZodNanoIDInternals extends $ZodStringFormatInternals<"nanoid"> {
	def: $ZodNanoIDDef;
}
export interface $ZodNanoID extends $ZodType {
	_zod: $ZodNanoIDInternals;
}
declare const $ZodNanoID: $constructor<$ZodNanoID>;
/**
 * @deprecated CUID v1 is deprecated by its authors due to information leakage
 * (timestamps embedded in the id). Use {@link $ZodCUID2} instead.
 * See https://github.com/paralleldrive/cuid.
 */
export interface $ZodCUIDDef extends $ZodStringFormatDef<"cuid"> {
}
/**
 * @deprecated CUID v1 is deprecated by its authors due to information leakage
 * (timestamps embedded in the id). Use {@link $ZodCUID2} instead.
 * See https://github.com/paralleldrive/cuid.
 */
export interface $ZodCUIDInternals extends $ZodStringFormatInternals<"cuid"> {
}
/**
 * @deprecated CUID v1 is deprecated by its authors due to information leakage
 * (timestamps embedded in the id). Use {@link $ZodCUID2} instead.
 * See https://github.com/paralleldrive/cuid.
 */
export interface $ZodCUID extends $ZodType {
	_zod: $ZodCUIDInternals;
}
declare const $ZodCUID: $constructor<$ZodCUID>;
export interface $ZodCUID2Def extends $ZodStringFormatDef<"cuid2"> {
}
export interface $ZodCUID2Internals extends $ZodStringFormatInternals<"cuid2"> {
}
export interface $ZodCUID2 extends $ZodType {
	_zod: $ZodCUID2Internals;
}
declare const $ZodCUID2: $constructor<$ZodCUID2>;
export interface $ZodULIDDef extends $ZodStringFormatDef<"ulid"> {
}
export interface $ZodULIDInternals extends $ZodStringFormatInternals<"ulid"> {
}
export interface $ZodULID extends $ZodType {
	_zod: $ZodULIDInternals;
}
declare const $ZodULID: $constructor<$ZodULID>;
export interface $ZodXIDDef extends $ZodStringFormatDef<"xid"> {
}
export interface $ZodXIDInternals extends $ZodStringFormatInternals<"xid"> {
}
export interface $ZodXID extends $ZodType {
	_zod: $ZodXIDInternals;
}
declare const $ZodXID: $constructor<$ZodXID>;
export interface $ZodKSUIDDef extends $ZodStringFormatDef<"ksuid"> {
}
export interface $ZodKSUIDInternals extends $ZodStringFormatInternals<"ksuid"> {
}
export interface $ZodKSUID extends $ZodType {
	_zod: $ZodKSUIDInternals;
}
declare const $ZodKSUID: $constructor<$ZodKSUID>;
export interface $ZodISODateTimeDef extends $ZodStringFormatDef<"datetime"> {
	precision: number | null;
	offset: boolean;
	local: boolean;
}
export interface $ZodISODateTimeInternals extends $ZodStringFormatInternals {
	def: $ZodISODateTimeDef;
}
export interface $ZodISODateTime extends $ZodType {
	_zod: $ZodISODateTimeInternals;
}
declare const $ZodISODateTime: $constructor<$ZodISODateTime>;
export interface $ZodISODateDef extends $ZodStringFormatDef<"date"> {
}
export interface $ZodISODateInternals extends $ZodStringFormatInternals<"date"> {
}
export interface $ZodISODate extends $ZodType {
	_zod: $ZodISODateInternals;
}
declare const $ZodISODate: $constructor<$ZodISODate>;
export interface $ZodISOTimeDef extends $ZodStringFormatDef<"time"> {
	precision?: number | null;
}
export interface $ZodISOTimeInternals extends $ZodStringFormatInternals<"time"> {
	def: $ZodISOTimeDef;
}
export interface $ZodISOTime extends $ZodType {
	_zod: $ZodISOTimeInternals;
}
declare const $ZodISOTime: $constructor<$ZodISOTime>;
export interface $ZodISODurationDef extends $ZodStringFormatDef<"duration"> {
}
export interface $ZodISODurationInternals extends $ZodStringFormatInternals<"duration"> {
}
export interface $ZodISODuration extends $ZodType {
	_zod: $ZodISODurationInternals;
}
declare const $ZodISODuration: $constructor<$ZodISODuration>;
export interface $ZodIPv4Def extends $ZodStringFormatDef<"ipv4"> {
	version?: "v4";
}
export interface $ZodIPv4Internals extends $ZodStringFormatInternals<"ipv4"> {
	def: $ZodIPv4Def;
}
export interface $ZodIPv4 extends $ZodType {
	_zod: $ZodIPv4Internals;
}
declare const $ZodIPv4: $constructor<$ZodIPv4>;
export interface $ZodIPv6Def extends $ZodStringFormatDef<"ipv6"> {
	version?: "v6";
}
export interface $ZodIPv6Internals extends $ZodStringFormatInternals<"ipv6"> {
	def: $ZodIPv6Def;
}
export interface $ZodIPv6 extends $ZodType {
	_zod: $ZodIPv6Internals;
}
declare function isValidIPv6(value: string): boolean;
declare const $ZodIPv6: $constructor<$ZodIPv6>;
export interface $ZodMACDef extends $ZodStringFormatDef<"mac"> {
	delimiter?: string;
}
export interface $ZodMACInternals extends $ZodStringFormatInternals<"mac"> {
	def: $ZodMACDef;
}
export interface $ZodMAC extends $ZodType {
	_zod: $ZodMACInternals;
}
declare const $ZodMAC: $constructor<$ZodMAC>;
export interface $ZodCIDRv4Def extends $ZodStringFormatDef<"cidrv4"> {
	version?: "v4";
}
export interface $ZodCIDRv4Internals extends $ZodStringFormatInternals<"cidrv4"> {
	def: $ZodCIDRv4Def;
}
export interface $ZodCIDRv4 extends $ZodType {
	_zod: $ZodCIDRv4Internals;
}
declare const $ZodCIDRv4: $constructor<$ZodCIDRv4>;
export interface $ZodCIDRv6Def extends $ZodStringFormatDef<"cidrv6"> {
	version?: "v6";
}
export interface $ZodCIDRv6Internals extends $ZodStringFormatInternals<"cidrv6"> {
	def: $ZodCIDRv6Def;
}
export interface $ZodCIDRv6 extends $ZodType {
	_zod: $ZodCIDRv6Internals;
}
declare function isValidCIDRv6(value: string): boolean;
declare const $ZodCIDRv6: $constructor<$ZodCIDRv6>;
declare function isValidBase64(data: string): boolean;
export interface $ZodBase64Def extends $ZodStringFormatDef<"base64"> {
}
export interface $ZodBase64Internals extends $ZodStringFormatInternals<"base64"> {
}
export interface $ZodBase64 extends $ZodType {
	_zod: $ZodBase64Internals;
}
declare const $ZodBase64: $constructor<$ZodBase64>;
declare function isValidBase64URL(data: string): boolean;
export interface $ZodBase64URLDef extends $ZodStringFormatDef<"base64url"> {
}
export interface $ZodBase64URLInternals extends $ZodStringFormatInternals<"base64url"> {
}
export interface $ZodBase64URL extends $ZodType {
	_zod: $ZodBase64URLInternals;
}
declare const $ZodBase64URL: $constructor<$ZodBase64URL>;
export interface $ZodE164Def extends $ZodStringFormatDef<"e164"> {
}
export interface $ZodE164Internals extends $ZodStringFormatInternals<"e164"> {
}
export interface $ZodE164 extends $ZodType {
	_zod: $ZodE164Internals;
}
declare const $ZodE164: $constructor<$ZodE164>;
declare function isValidCreditCard(input: string): boolean;
export interface $ZodCreditCardDef extends $ZodStringFormatDef<"credit_card"> {
}
export interface $ZodCreditCardInternals extends $ZodStringFormatInternals<"credit_card"> {
	def: $ZodCreditCardDef;
}
export interface $ZodCreditCard extends $ZodType {
	_zod: $ZodCreditCardInternals;
}
declare const $ZodCreditCard: $constructor<$ZodCreditCard>;
declare function isValidJWT(token: string, algorithm?: util.JWTAlgorithm | null): boolean;
export interface $ZodJWTDef extends $ZodStringFormatDef<"jwt"> {
	alg?: util.JWTAlgorithm | undefined;
}
export interface $ZodJWTInternals extends $ZodStringFormatInternals<"jwt"> {
	def: $ZodJWTDef;
}
export interface $ZodJWT extends $ZodType {
	_zod: $ZodJWTInternals;
}
declare const $ZodJWT: $constructor<$ZodJWT>;
export interface $ZodCustomStringFormatDef<Format extends string = string> extends $ZodStringFormatDef<Format> {
	fn: (val: string) => unknown;
}
export interface $ZodCustomStringFormatInternals<Format extends string = string> extends $ZodStringFormatInternals<Format> {
	def: $ZodCustomStringFormatDef<Format>;
}
export interface $ZodCustomStringFormat<Format extends string = string> extends $ZodStringFormat<Format> {
	_zod: $ZodCustomStringFormatInternals<Format>;
}
declare const $ZodCustomStringFormat: $constructor<$ZodCustomStringFormat>;
export interface $ZodNumberDef extends $ZodTypeDef {
	type: "number";
	coerce?: boolean;
}
export interface $ZodNumberInternals<Input = unknown> extends $ZodTypeInternals<number, Input> {
	def: $ZodNumberDef;
	/** @deprecated Internal API, use with caution (not deprecated) */
	pattern: RegExp;
	/** @deprecated Internal API, use with caution (not deprecated) */
	isst: $ZodIssueInvalidType;
	bag: util.LoosePartial<{
		minimum: number;
		maximum: number;
		exclusiveMinimum: number;
		exclusiveMaximum: number;
		format: string;
		pattern: RegExp;
	}>;
}
export interface $ZodNumber<Input = unknown> extends $ZodType {
	_zod: $ZodNumberInternals<Input>;
}
declare const $ZodNumber: $constructor<$ZodNumber>;
export interface $ZodNumberFormatDef<Format extends $ZodNumberFormats = $ZodNumberFormats> extends $ZodNumberDef, $ZodCheckNumberFormatDef<Format> {
}
export interface $ZodNumberFormatInternals<Format extends $ZodNumberFormats = $ZodNumberFormats> extends $ZodNumberInternals<number>, $ZodCheckNumberFormatInternals<Format> {
	def: $ZodNumberFormatDef<Format>;
	isst: $ZodIssueInvalidType;
}
export interface $ZodNumberFormat<Format extends $ZodNumberFormats = $ZodNumberFormats> extends $ZodType {
	_zod: $ZodNumberFormatInternals<Format>;
}
declare const $ZodNumberFormat: $constructor<$ZodNumberFormat>;
export interface $ZodBooleanDef extends $ZodTypeDef {
	type: "boolean";
	coerce?: boolean;
	checks?: $ZodCheck<boolean>[];
}
export interface $ZodBooleanInternals<T = unknown> extends $ZodTypeInternals<boolean, T> {
	pattern: RegExp;
	def: $ZodBooleanDef;
	isst: $ZodIssueInvalidType;
}
export interface $ZodBoolean<T = unknown> extends $ZodType {
	_zod: $ZodBooleanInternals<T>;
}
declare const $ZodBoolean: $constructor<$ZodBoolean>;
export interface $ZodBigIntDef extends $ZodTypeDef {
	type: "bigint";
	coerce?: boolean;
}
export interface $ZodBigIntInternals<T = unknown> extends $ZodTypeInternals<bigint, T> {
	pattern: RegExp;
	/** @internal Internal API, use with caution */
	def: $ZodBigIntDef;
	isst: $ZodIssueInvalidType;
	bag: util.LoosePartial<{
		minimum: bigint;
		maximum: bigint;
		format: string;
	}>;
}
export interface $ZodBigInt<T = unknown> extends $ZodType {
	_zod: $ZodBigIntInternals<T>;
}
declare const $ZodBigInt: $constructor<$ZodBigInt>;
export interface $ZodBigIntFormatDef<Format extends $ZodBigIntFormats = $ZodBigIntFormats> extends $ZodBigIntDef, $ZodCheckBigIntFormatDef<Format> {
	check: "bigint_format";
}
export interface $ZodBigIntFormatInternals<Format extends $ZodBigIntFormats = $ZodBigIntFormats> extends $ZodBigIntInternals<bigint>, $ZodCheckBigIntFormatInternals<Format> {
	def: $ZodBigIntFormatDef<Format>;
}
export interface $ZodBigIntFormat<Format extends $ZodBigIntFormats = $ZodBigIntFormats> extends $ZodType {
	_zod: $ZodBigIntFormatInternals<Format>;
}
declare const $ZodBigIntFormat: $constructor<$ZodBigIntFormat>;
export interface $ZodSymbolDef extends $ZodTypeDef {
	type: "symbol";
}
export interface $ZodSymbolInternals extends $ZodTypeInternals<symbol, symbol> {
	def: $ZodSymbolDef;
	isst: $ZodIssueInvalidType;
}
export interface $ZodSymbol extends $ZodType {
	_zod: $ZodSymbolInternals;
}
declare const $ZodSymbol: $constructor<$ZodSymbol>;
export interface $ZodUndefinedDef extends $ZodTypeDef {
	type: "undefined";
}
export interface $ZodUndefinedInternals extends $ZodTypeInternals<undefined, undefined> {
	pattern: RegExp;
	def: $ZodUndefinedDef;
	values: util.PrimitiveSet;
	isst: $ZodIssueInvalidType;
}
export interface $ZodUndefined extends $ZodType {
	_zod: $ZodUndefinedInternals;
}
declare const $ZodUndefined: $constructor<$ZodUndefined>;
export interface $ZodNullDef extends $ZodTypeDef {
	type: "null";
}
export interface $ZodNullInternals extends $ZodTypeInternals<null, null> {
	pattern: RegExp;
	def: $ZodNullDef;
	values: util.PrimitiveSet;
	isst: $ZodIssueInvalidType;
}
export interface $ZodNull extends $ZodType {
	_zod: $ZodNullInternals;
}
declare const $ZodNull: $constructor<$ZodNull>;
export interface $ZodAnyDef extends $ZodTypeDef {
	type: "any";
}
export interface $ZodAnyInternals extends $ZodTypeInternals<any, any> {
	def: $ZodAnyDef;
	isst: never;
}
export interface $ZodAny extends $ZodType {
	_zod: $ZodAnyInternals;
}
declare const $ZodAny: $constructor<$ZodAny>;
export interface $ZodUnknownDef extends $ZodTypeDef {
	type: "unknown";
}
export interface $ZodUnknownInternals extends $ZodTypeInternals<unknown, unknown> {
	def: $ZodUnknownDef;
	isst: never;
}
export interface $ZodUnknown extends $ZodType {
	_zod: $ZodUnknownInternals;
}
declare const $ZodUnknown: $constructor<$ZodUnknown>;
export interface $ZodNeverDef extends $ZodTypeDef {
	type: "never";
}
export interface $ZodNeverInternals extends $ZodTypeInternals<never, never> {
	def: $ZodNeverDef;
	isst: $ZodIssueInvalidType;
}
export interface $ZodNever extends $ZodType {
	_zod: $ZodNeverInternals;
}
declare const $ZodNever: $constructor<$ZodNever>;
export interface $ZodVoidDef extends $ZodTypeDef {
	type: "void";
}
export interface $ZodVoidInternals extends $ZodTypeInternals<void, void> {
	def: $ZodVoidDef;
	isst: $ZodIssueInvalidType;
}
export interface $ZodVoid extends $ZodType {
	_zod: $ZodVoidInternals;
}
declare const $ZodVoid: $constructor<$ZodVoid>;
export interface $ZodDateDef extends $ZodTypeDef {
	type: "date";
	coerce?: boolean;
}
export interface $ZodDateInternals<T = unknown> extends $ZodTypeInternals<Date, T> {
	def: $ZodDateDef;
	isst: $ZodIssueInvalidType;
	bag: util.LoosePartial<{
		minimum: Date;
		maximum: Date;
		format: string;
	}>;
}
export interface $ZodDate<T = unknown> extends $ZodType {
	_zod: $ZodDateInternals<T>;
}
declare const $ZodDate: $constructor<$ZodDate>;
export interface $ZodArrayDef<T extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "array";
	element: T;
}
export interface $ZodArrayInternals<T extends SomeType = $ZodType> extends _$ZodTypeInternals {
	def: $ZodArrayDef<T>;
	isst: $ZodIssueInvalidType;
	output: output<T>[];
	input: input<T>[];
}
export interface $ZodArray<T extends SomeType = $ZodType> extends $ZodType<any, any, $ZodArrayInternals<T>> {
}
declare const $ZodArray: $constructor<$ZodArray>;
export type OptionalOutSchema = {
	_zod: {
		optout: "optional";
	};
};
export type OptionalInSchema = {
	_zod: {
		optin: "optional" | "defaulted";
	};
};
export type $InferObjectOutput<T extends $ZodLooseShape, Extra extends Record<string, unknown>> = string extends keyof T ? util.IsAny<T[keyof T]> extends true ? Record<string, unknown> : Record<string, output<T[keyof T]>> : keyof (T & Extra) extends never ? Record<string, never> : util.Prettify<{
	-readonly [k in keyof T as T[k] extends OptionalOutSchema ? never : k]: T[k]["_zod"]["output"];
} & {
	-readonly [k in keyof T as T[k] extends OptionalOutSchema ? k : never]?: T[k]["_zod"]["output"];
} & Extra>;
export type $InferObjectInput<T extends $ZodLooseShape, Extra extends Record<string, unknown>> = string extends keyof T ? util.IsAny<T[keyof T]> extends true ? Record<string, unknown> : Record<string, input<T[keyof T]>> : keyof (T & Extra) extends never ? Record<string, never> : util.Prettify<{
	-readonly [k in keyof T as T[k] extends OptionalInSchema ? never : k]: T[k]["_zod"]["input"];
} & {
	-readonly [k in keyof T as T[k] extends OptionalInSchema ? k : never]?: T[k]["_zod"]["input"];
} & Extra>;
export type $ZodObjectConfig = {
	out: Record<string, unknown>;
	in: Record<string, unknown>;
};
export type $loose = {
	out: Record<string, unknown>;
	in: Record<string, unknown>;
};
export type $strict = {
	out: {};
	in: {};
};
export type $strip = {
	out: {};
	in: {};
};
export type $catchall<T extends SomeType> = {
	out: {
		[k: string]: output<T>;
	};
	in: {
		[k: string]: input<T>;
	};
};
export type $ZodShape = Readonly<{
	[k: string]: $ZodType;
}>;
export interface $ZodObjectDef<Shape extends $ZodShape = $ZodShape> extends $ZodTypeDef {
	type: "object";
	shape: Shape;
	catchall?: $ZodType | undefined;
}
export interface $ZodObjectInternals<
/** @ts-ignore Cast variance */
out Shape extends $ZodShape = $ZodShape, out Config extends $ZodObjectConfig = $ZodObjectConfig> extends _$ZodTypeInternals {
	def: $ZodObjectDef<Shape>;
	config: Config;
	isst: $ZodIssueInvalidType | $ZodIssueUnrecognizedKeys;
	propValues: util.PropValues;
	output: $InferObjectOutput<Shape, Config["out"]>;
	input: $InferObjectInput<Shape, Config["in"]>;
	optin?: "optional" | undefined;
	optout?: "optional" | undefined;
}
export type $ZodLooseShape = Record<string, any>;
export interface $ZodObject<
/** @ts-ignore Cast variance */
out Shape extends Readonly<$ZodShape> = Readonly<$ZodShape>, out Params extends $ZodObjectConfig = $ZodObjectConfig> extends $ZodType<any, any, $ZodObjectInternals<Shape, Params>> {
}
declare const $ZodObject: $constructor<$ZodObject>;
declare const $ZodObjectJIT: $constructor<$ZodObject>;
export type $InferUnionOutput<T extends SomeType> = T extends any ? output<T> : never;
export type $InferUnionInput<T extends SomeType> = T extends any ? input<T> : never;
export interface $ZodUnionDef<Options extends readonly SomeType[] = readonly $ZodType[]> extends $ZodTypeDef {
	type: "union";
	options: Options;
	inclusive?: boolean;
}
export type IsOptionalIn<T extends SomeType> = T extends OptionalInSchema ? true : false;
export type IsOptionalOut<T extends SomeType> = T extends OptionalOutSchema ? true : false;
export interface $ZodUnionInternals<T extends readonly SomeType[] = readonly $ZodType[]> extends _$ZodTypeInternals {
	def: $ZodUnionDef<T>;
	isst: $ZodIssueInvalidUnion;
	pattern: T[number]["_zod"]["pattern"];
	values: T[number]["_zod"]["values"];
	output: $InferUnionOutput<T[number]>;
	input: $InferUnionInput<T[number]>;
	optin: IsOptionalIn<T[number]> extends false ? "optional" | "defaulted" | undefined : "optional" | "defaulted";
	optout: IsOptionalOut<T[number]> extends false ? "optional" | undefined : "optional";
}
export interface $ZodUnion<T extends readonly SomeType[] = readonly $ZodType[]> extends $ZodType<any, any, $ZodUnionInternals<T>> {
	_zod: $ZodUnionInternals<T>;
}
declare const $ZodUnion: $constructor<$ZodUnion>;
export interface $ZodXorInternals<T extends readonly SomeType[] = readonly $ZodType[]> extends $ZodUnionInternals<T> {
}
export interface $ZodXor<T extends readonly SomeType[] = readonly $ZodType[]> extends $ZodType<any, any, $ZodXorInternals<T>> {
	_zod: $ZodXorInternals<T>;
}
declare const $ZodXor: $constructor<$ZodXor>;
export interface $ZodDiscriminatedUnionDef<Options extends readonly SomeType[] = readonly $ZodType[], Disc extends string = string> extends $ZodUnionDef<Options> {
	discriminator: Disc;
	unionFallback?: boolean;
}
export interface $ZodDiscriminatedUnionInternals<Options extends readonly SomeType[] = readonly $ZodType[], Disc extends string = string> extends $ZodUnionInternals<Options> {
	def: $ZodDiscriminatedUnionDef<Options, Disc>;
	propValues: util.PropValues;
	bag: util.LoosePartial<{
		optionsMap: Map<util.Primitive, $ZodType>;
	}>;
}
/** The discriminator values a member of `Options` can declare. An omittable discriminator contributes `undefined`, matching what `propValues` claims for it. */
export type $DiscriminatorValue<Options extends readonly SomeType[], Disc extends string> = {
	[I in keyof Options]: Options[I] extends {
		_zod: {
			output: infer Out;
		};
	} ? Disc extends keyof Out ? Out[Disc] : never : never;
}[number];
/** The member of `Options` that declares `V`. */
export type $DiscriminatedOption<Options extends readonly SomeType[], Disc extends string, V> = {
	[I in keyof Options]: Options[I] extends {
		_zod: {
			output: infer Out;
		};
	} ? Disc extends keyof Out ? V extends Out[Disc] ? Options[I] : never : never : never;
}[number];
declare function getDiscriminatedOption<Options extends readonly SomeType[], Disc extends string, const V extends $DiscriminatorValue<Options, Disc>>(union: $ZodDiscriminatedUnion<Options, Disc>, value: V): $DiscriminatedOption<Options, Disc, V>;
export interface $ZodDiscriminatedUnion<Options extends readonly SomeType[] = readonly $ZodType[], Disc extends string = string> extends $ZodType {
	_zod: $ZodDiscriminatedUnionInternals<Options, Disc>;
}
declare const $ZodDiscriminatedUnion: $constructor<$ZodDiscriminatedUnion>;
export interface $ZodIntersectionDef<Left extends SomeType = $ZodType, Right extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "intersection";
	left: Left;
	right: Right;
}
export interface $ZodIntersectionInternals<A extends SomeType = $ZodType, B extends SomeType = $ZodType> extends _$ZodTypeInternals {
	def: $ZodIntersectionDef<A, B>;
	isst: never;
	optin: A["_zod"]["optin"] | B["_zod"]["optin"];
	optout: A["_zod"]["optout"] | B["_zod"]["optout"];
	output: output<A> & output<B>;
	input: input<A> & input<B>;
}
export interface $ZodIntersection<A extends SomeType = $ZodType, B extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodIntersectionInternals<A, B>;
}
declare const $ZodIntersection: $constructor<$ZodIntersection>;
declare function mergeValues(a: any, b: any): {
	valid: true;
	data: any;
} | {
	valid: false;
	mergeErrorPath: (string | number)[];
};
export interface $ZodTupleDef<T extends util.TupleItems = readonly $ZodType[], Rest extends SomeType | null = $ZodType | null> extends $ZodTypeDef {
	type: "tuple";
	items: T;
	rest: Rest;
}
export type $InferTupleInputType<T extends util.TupleItems, Rest extends SomeType | null> = [
	...TupleInputTypeWithOptionals<T>,
	...(Rest extends SomeType ? input<Rest>[] : [
	])
];
export type TupleInputTypeNoOptionals<T extends util.TupleItems> = {
	[k in keyof T]: input<T[k]>;
};
export type TupleInputTypeWithOptionals<T extends util.TupleItems> = T extends readonly [
	...infer Prefix extends SomeType[],
	infer Tail extends SomeType
] ? Tail["_zod"]["optin"] extends "optional" | "defaulted" ? [
	...TupleInputTypeWithOptionals<Prefix>,
	input<Tail>?
] : TupleInputTypeNoOptionals<T> : [
];
export type $InferTupleOutputType<T extends util.TupleItems, Rest extends SomeType | null> = [
	...TupleOutputTypeWithOptionals<T>,
	...(Rest extends SomeType ? output<Rest>[] : [
	])
];
export type TupleOutputTypeNoOptionals<T extends util.TupleItems> = {
	[k in keyof T]: output<T[k]>;
};
export type TupleOutputTypeWithOptionals<T extends util.TupleItems> = T extends readonly [
	...infer Prefix extends SomeType[],
	infer Tail extends SomeType
] ? Tail["_zod"]["optout"] extends "optional" ? [
	...TupleOutputTypeWithOptionals<Prefix>,
	output<Tail>?
] : TupleOutputTypeNoOptionals<T> : [
];
export interface $ZodTupleInternals<T extends util.TupleItems = readonly $ZodType[], Rest extends SomeType | null = $ZodType | null> extends _$ZodTypeInternals {
	def: $ZodTupleDef<T, Rest>;
	isst: $ZodIssueInvalidType | $ZodIssueTooBig<unknown[]> | $ZodIssueTooSmall<unknown[]>;
	output: $InferTupleOutputType<T, Rest>;
	input: $InferTupleInputType<T, Rest>;
}
export interface $ZodTuple<T extends util.TupleItems = readonly $ZodType[], Rest extends SomeType | null = $ZodType | null> extends $ZodType {
	_zod: $ZodTupleInternals<T, Rest>;
}
declare const $ZodTuple: $constructor<$ZodTuple>;
export type $ZodRecordKey = $ZodType<string | number | symbol, unknown>;
export interface $ZodRecordDef<Key extends $ZodRecordKey = $ZodRecordKey, Value extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "record";
	keyType: Key;
	valueType: Value;
	/** @default "strict" - errors on keys not matching keyType. "loose" passes through non-matching keys unchanged. */
	mode?: "strict" | "loose";
	partial?: boolean;
}
export type $InferZodRecordOutput<Key extends $ZodRecordKey = $ZodRecordKey, Value extends SomeType = $ZodType> = Key extends $partial ? Partial<Record<output<Key>, output<Value>>> : Record<output<Key>, output<Value>>;
export type $InferZodRecordInput<Key extends $ZodRecordKey = $ZodRecordKey, Value extends SomeType = $ZodType> = Key extends $partial ? Partial<Record<input<Key> & PropertyKey, input<Value>>> : [
	Value
] extends [
	OptionalInSchema
] ? Partial<Record<input<Key> & PropertyKey, input<Value>>> : Record<input<Key> & PropertyKey, input<Value>>;
export interface $ZodRecordInternals<Key extends $ZodRecordKey = $ZodRecordKey, Value extends SomeType = $ZodType> extends $ZodTypeInternals<$InferZodRecordOutput<Key, Value>, $InferZodRecordInput<Key, Value>> {
	def: $ZodRecordDef<Key, Value>;
	isst: $ZodIssueInvalidType | $ZodIssueInvalidKey<Record<PropertyKey, unknown>>;
	optin?: "optional" | undefined;
	optout?: "optional" | undefined;
}
export type $partial = {
	"~~partial": true;
};
export interface $ZodRecord<Key extends $ZodRecordKey = $ZodRecordKey, Value extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodRecordInternals<Key, Value>;
}
declare const $ZodRecord: $constructor<$ZodRecord>;
export interface $ZodMapDef<Key extends SomeType = $ZodType, Value extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "map";
	keyType: Key;
	valueType: Value;
}
export interface $ZodMapInternals<Key extends SomeType = $ZodType, Value extends SomeType = $ZodType> extends $ZodTypeInternals<Map<output<Key>, output<Value>>, Map<input<Key>, input<Value>>> {
	def: $ZodMapDef<Key, Value>;
	isst: $ZodIssueInvalidType | $ZodIssueInvalidKey | $ZodIssueInvalidElement<unknown>;
	optin?: "optional" | undefined;
	optout?: "optional" | undefined;
}
export interface $ZodMap<Key extends SomeType = $ZodType, Value extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodMapInternals<Key, Value>;
}
declare const $ZodMap: $constructor<$ZodMap>;
export interface $ZodSetDef<T extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "set";
	valueType: T;
}
export interface $ZodSetInternals<T extends SomeType = $ZodType> extends $ZodTypeInternals<Set<output<T>>, Set<input<T>>> {
	def: $ZodSetDef<T>;
	isst: $ZodIssueInvalidType;
	optin?: "optional" | undefined;
	optout?: "optional" | undefined;
}
export interface $ZodSet<T extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodSetInternals<T>;
}
declare const $ZodSet: $constructor<$ZodSet>;
export type $InferEnumOutput<T extends util.EnumLike> = T[keyof T] & {};
export type $InferEnumInput<T extends util.EnumLike> = T[keyof T] & {};
export interface $ZodEnumDef<T extends util.EnumLike = util.EnumLike> extends $ZodTypeDef {
	type: "enum";
	entries: T;
}
export interface $ZodEnumInternals<
/** @ts-ignore Cast variance */
out T extends util.EnumLike = util.EnumLike> extends $ZodTypeInternals<$InferEnumOutput<T>, $InferEnumInput<T>> {
	def: $ZodEnumDef<T>;
	/** @deprecated Internal API, use with caution (not deprecated) */
	values: util.PrimitiveSet;
	/** @deprecated Internal API, use with caution (not deprecated) */
	pattern: RegExp;
	isst: $ZodIssueInvalidValue;
}
export interface $ZodEnum<T extends util.EnumLike = util.EnumLike> extends $ZodType {
	_zod: $ZodEnumInternals<T>;
}
declare const $ZodEnum: $constructor<$ZodEnum>;
export interface $ZodLiteralDef<T extends util.Literal> extends $ZodTypeDef {
	type: "literal";
	values: T[];
}
export interface $ZodLiteralInternals<T extends util.Literal = util.Literal> extends $ZodTypeInternals<T, T> {
	def: $ZodLiteralDef<T>;
	values: Set<T>;
	pattern: RegExp;
	isst: $ZodIssueInvalidValue;
}
export interface $ZodLiteral<T extends util.Literal = util.Literal> extends $ZodType {
	_zod: $ZodLiteralInternals<T>;
}
declare const $ZodLiteral: $constructor<$ZodLiteral>;
export type _File = typeof globalThis extends {
	File: infer F extends new (...args: any[]) => any;
} ? InstanceType<F> : {};
interface File$1 extends _File {
	readonly type: string;
	readonly size: number;
}
export interface $ZodFileDef extends $ZodTypeDef {
	type: "file";
}
export interface $ZodFileInternals extends $ZodTypeInternals<File$1, File$1> {
	def: $ZodFileDef;
	isst: $ZodIssueInvalidType;
	bag: util.LoosePartial<{
		minimum: number;
		maximum: number;
		mime: util.MimeTypes[];
	}>;
}
export interface $ZodFile extends $ZodType {
	_zod: $ZodFileInternals;
}
declare const $ZodFile: $constructor<$ZodFile>;
export interface $ZodTransformDef extends $ZodTypeDef {
	type: "transform";
	transform: (input: unknown, payload: ParsePayload<unknown>) => util.MaybeAsync<unknown>;
}
export interface $ZodTransformInternals<O = unknown, I = unknown> extends $ZodTypeInternals<O, I> {
	def: $ZodTransformDef;
	isst: never;
}
export interface $ZodTransform<O = unknown, I = unknown> extends $ZodType {
	_zod: $ZodTransformInternals<O, I>;
}
declare const $ZodTransform: $constructor<$ZodTransform>;
export interface $ZodOptionalDef<T extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "optional";
	innerType: T;
}
export interface $ZodOptionalInternals<T extends SomeType = $ZodType> extends $ZodTypeInternals<output<T> | undefined, input<T> | undefined> {
	def: $ZodOptionalDef<T>;
	optin: "optional" | "defaulted";
	optout: "optional";
	isst: never;
	values: T["_zod"]["values"];
	pattern: T["_zod"]["pattern"];
}
export interface $ZodOptional<T extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodOptionalInternals<T>;
}
declare const $ZodOptional: $constructor<$ZodOptional>;
export interface $ZodExactOptionalDef<T extends SomeType = $ZodType> extends $ZodOptionalDef<T> {
}
export interface $ZodExactOptionalInternals<T extends SomeType = $ZodType> extends $ZodOptionalInternals<T> {
	def: $ZodExactOptionalDef<T>;
	output: output<T>;
	input: input<T>;
}
export interface $ZodExactOptional<T extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodExactOptionalInternals<T>;
}
declare const $ZodExactOptional: $constructor<$ZodExactOptional>;
export interface $ZodNullableDef<T extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "nullable";
	innerType: T;
}
export interface $ZodNullableInternals<T extends SomeType = $ZodType> extends $ZodTypeInternals<output<T> | null, input<T> | null> {
	def: $ZodNullableDef<T>;
	optin: T["_zod"]["optin"];
	optout: T["_zod"]["optout"];
	isst: never;
	values: T["_zod"]["values"];
	pattern: T["_zod"]["pattern"];
}
export interface $ZodNullable<T extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodNullableInternals<T>;
}
declare const $ZodNullable: $constructor<$ZodNullable>;
export interface $ZodDefaultDef<T extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "default";
	innerType: T;
	/** The default value. May be a getter. */
	defaultValue: util.NoUndefined<output<T>>;
}
export interface $ZodDefaultInternals<T extends SomeType = $ZodType> extends $ZodTypeInternals<util.NoUndefined<output<T>>, input<T> | undefined> {
	def: $ZodDefaultDef<T>;
	optin: "defaulted";
	optout?: "optional" | undefined;
	isst: never;
	values: T["_zod"]["values"];
}
export interface $ZodDefault<T extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodDefaultInternals<T>;
}
declare const $ZodDefault: $constructor<$ZodDefault>;
export interface $ZodPrefaultDef<T extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "prefault";
	innerType: T;
	/** The default value. May be a getter. */
	defaultValue: input<T>;
}
export interface $ZodPrefaultInternals<T extends SomeType = $ZodType> extends $ZodTypeInternals<util.NoUndefined<output<T>>, input<T> | undefined> {
	def: $ZodPrefaultDef<T>;
	optin: "defaulted";
	optout?: "optional" | undefined;
	isst: never;
	values: T["_zod"]["values"];
}
export interface $ZodPrefault<T extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodPrefaultInternals<T>;
}
declare const $ZodPrefault: $constructor<$ZodPrefault>;
export interface $ZodNonOptionalDef<T extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "nonoptional";
	innerType: T;
}
export interface $ZodNonOptionalInternals<T extends SomeType = $ZodType> extends $ZodTypeInternals<util.NoUndefined<output<T>>, util.NoUndefined<input<T>>> {
	def: $ZodNonOptionalDef<T>;
	isst: $ZodIssueInvalidType;
	values: T["_zod"]["values"];
	optin: "optional" | undefined;
	optout: "optional" | undefined;
}
export interface $ZodNonOptional<T extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodNonOptionalInternals<T>;
}
declare const $ZodNonOptional: $constructor<$ZodNonOptional>;
export interface $ZodSuccessDef<T extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "success";
	innerType: T;
}
export interface $ZodSuccessInternals<T extends SomeType = $ZodType> extends $ZodTypeInternals<boolean, input<T>> {
	def: $ZodSuccessDef<T>;
	isst: never;
	optin: T["_zod"]["optin"];
	optout: "optional" | undefined;
}
export interface $ZodSuccess<T extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodSuccessInternals<T>;
}
declare const $ZodSuccess: $constructor<$ZodSuccess>;
export interface $ZodCatchCtx extends ParsePayload {
	/** @deprecated Use `ctx.issues` */
	error: {
		issues: $ZodIssue[];
	};
	/** @deprecated Use `ctx.value` */
	input: unknown;
}
export interface $ZodCatchDef<T extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "catch";
	innerType: T;
	catchValue: (ctx: $ZodCatchCtx) => unknown;
}
export interface $ZodCatchInternals<T extends SomeType = $ZodType> extends $ZodTypeInternals<output<T>, input<T>> {
	def: $ZodCatchDef<T>;
	optin: T["_zod"]["optin"];
	optout: T["_zod"]["optout"];
	isst: never;
	values: T["_zod"]["values"];
}
export interface $ZodCatch<T extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodCatchInternals<T>;
}
declare const $ZodCatch: $constructor<$ZodCatch>;
export interface $ZodNaNDef extends $ZodTypeDef {
	type: "nan";
}
export interface $ZodNaNInternals extends $ZodTypeInternals<number, number> {
	def: $ZodNaNDef;
	isst: $ZodIssueInvalidType;
}
export interface $ZodNaN extends $ZodType {
	_zod: $ZodNaNInternals;
}
declare const $ZodNaN: $constructor<$ZodNaN>;
export interface $ZodPipeDef<A extends SomeType = $ZodType, B extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "pipe";
	in: A;
	out: B;
	/** Only defined inside $ZodCodec instances. */
	transform?: (value: output<A>, payload: ParsePayload<output<A>>) => util.MaybeAsync<input<B>>;
	/** Only defined inside $ZodCodec instances. */
	reverseTransform?: (value: input<B>, payload: ParsePayload<input<B>>) => util.MaybeAsync<output<A>>;
}
export interface $ZodPipeInternals<A extends SomeType = $ZodType, B extends SomeType = $ZodType> extends $ZodTypeInternals<output<B>, input<A>> {
	def: $ZodPipeDef<A, B>;
	isst: never;
	values: A["_zod"]["values"];
	optin: A["_zod"]["optin"];
	optout: B["_zod"]["optout"];
	propValues: A["_zod"]["propValues"];
}
export interface $ZodPipe<A extends SomeType = $ZodType, B extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodPipeInternals<A, B>;
}
declare const $ZodPipe: $constructor<$ZodPipe>;
export interface $ZodCodecDef<A extends SomeType = $ZodType, B extends SomeType = $ZodType> extends $ZodPipeDef<A, B> {
	transform: (value: output<A>, payload: ParsePayload<output<A>>) => util.MaybeAsync<input<B>>;
	reverseTransform: (value: input<B>, payload: ParsePayload<input<B>>) => util.MaybeAsync<output<A>>;
}
export interface $ZodCodecInternals<A extends SomeType = $ZodType, B extends SomeType = $ZodType> extends $ZodTypeInternals<output<B>, input<A>> {
	def: $ZodCodecDef<A, B>;
	isst: never;
	values: A["_zod"]["values"];
	optin: A["_zod"]["optin"];
	optout: B["_zod"]["optout"];
	propValues: A["_zod"]["propValues"];
}
export interface $ZodCodec<A extends SomeType = $ZodType, B extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodCodecInternals<A, B>;
}
declare const $ZodCodec: $constructor<$ZodCodec>;
export interface $ZodPreprocessDef<B extends SomeType = $ZodType, I = unknown> extends $ZodPipeDef<$ZodTransform<unknown, I>, B> {
	in: $ZodTransform<unknown, I>;
	out: B;
}
export interface $ZodPreprocessInternals<B extends SomeType = $ZodType, I = unknown> extends $ZodPipeInternals<$ZodTransform<unknown, I>, B> {
	def: $ZodPreprocessDef<B, I>;
	optin: B["_zod"]["optin"];
	optout: B["_zod"]["optout"];
}
export interface $ZodPreprocess<B extends SomeType = $ZodType, I = unknown> extends $ZodPipe<$ZodTransform<unknown, I>, B> {
	_zod: $ZodPreprocessInternals<B, I>;
}
declare const $ZodPreprocess: $constructor<$ZodPreprocess>;
export interface $ZodReadonlyDef<T extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "readonly";
	innerType: T;
}
export interface $ZodReadonlyInternals<T extends SomeType = $ZodType> extends $ZodTypeInternals<util.MakeReadonly<output<T>>, util.MakeReadonly<input<T>>> {
	def: $ZodReadonlyDef<T>;
	optin: T["_zod"]["optin"];
	optout: T["_zod"]["optout"];
	isst: never;
	propValues: T["_zod"]["propValues"];
	values: T["_zod"]["values"];
}
export interface $ZodReadonly<T extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodReadonlyInternals<T>;
}
declare const $ZodReadonly: $constructor<$ZodReadonly>;
export interface $ZodTemplateLiteralDef extends $ZodTypeDef {
	type: "template_literal";
	parts: $ZodTemplateLiteralPart[];
	format?: string | undefined;
}
export interface $ZodTemplateLiteralInternals<Template extends string = string> extends $ZodTypeInternals<Template, Template> {
	pattern: RegExp;
	def: $ZodTemplateLiteralDef;
	isst: $ZodIssueInvalidType;
}
export interface $ZodTemplateLiteral<Template extends string = string> extends $ZodType {
	_zod: $ZodTemplateLiteralInternals<Template>;
}
export type LiteralPart = Exclude<util.Literal, symbol>;
export interface SchemaPartInternals extends $ZodTypeInternals<LiteralPart, LiteralPart> {
	pattern: RegExp;
}
export interface SchemaPart extends $ZodType {
	_zod: SchemaPartInternals;
}
export type $ZodTemplateLiteralPart = LiteralPart | SchemaPart;
export type UndefinedToEmptyString<T> = T extends undefined ? "" : T;
export type AppendToTemplateLiteral<Template extends string, Suffix extends LiteralPart | $ZodType> = Suffix extends LiteralPart ? `${Template}${UndefinedToEmptyString<Suffix>}` : Suffix extends $ZodType ? `${Template}${output<Suffix> extends infer T extends LiteralPart ? UndefinedToEmptyString<T> : never}` : never;
export type ConcatenateTupleOfStrings<T extends string[]> = T extends [
	infer First extends string,
	...infer Rest extends string[]
] ? Rest extends string[] ? First extends "" ? ConcatenateTupleOfStrings<Rest> : `${First}${ConcatenateTupleOfStrings<Rest>}` : never : "";
export type ConvertPartsToStringTuple<Parts extends $ZodTemplateLiteralPart[]> = {
	[K in keyof Parts]: Parts[K] extends LiteralPart ? `${UndefinedToEmptyString<Parts[K]>}` : Parts[K] extends $ZodType ? `${output<Parts[K]> extends infer T extends LiteralPart ? UndefinedToEmptyString<T> : never}` : never;
};
export type ToTemplateLiteral<Parts extends $ZodTemplateLiteralPart[]> = ConcatenateTupleOfStrings<ConvertPartsToStringTuple<Parts>>;
export type $PartsToTemplateLiteral<Parts extends $ZodTemplateLiteralPart[]> = [
] extends Parts ? `` : Parts extends [
	...infer Rest,
	infer Last extends $ZodTemplateLiteralPart
] ? Rest extends $ZodTemplateLiteralPart[] ? AppendToTemplateLiteral<$PartsToTemplateLiteral<Rest>, Last> : never : never;
declare const $ZodTemplateLiteral: $constructor<$ZodTemplateLiteral>;
export type $ZodFunctionArgs = $ZodType<unknown[], unknown[]>;
export type $ZodFunctionIn = $ZodFunctionArgs;
export type $ZodFunctionOut = $ZodType;
export type $InferInnerFunctionType<Args extends $ZodFunctionIn, Returns extends $ZodFunctionOut> = (...args: $ZodFunctionIn extends Args ? never[] : output<Args>) => input<Returns>;
export type $InferInnerFunctionTypeAsync<Args extends $ZodFunctionIn, Returns extends $ZodFunctionOut> = (...args: $ZodFunctionIn extends Args ? never[] : output<Args>) => util.MaybeAsync<input<Returns>>;
export type $InferOuterFunctionType<Args extends $ZodFunctionIn, Returns extends $ZodFunctionOut> = (...args: $ZodFunctionIn extends Args ? never[] : input<Args>) => output<Returns>;
export type $InferOuterFunctionTypeAsync<Args extends $ZodFunctionIn, Returns extends $ZodFunctionOut> = (...args: $ZodFunctionIn extends Args ? never[] : input<Args>) => Promise<output<Returns>>;
export interface $ZodFunctionDef<In extends $ZodFunctionIn = $ZodFunctionIn, Out extends $ZodFunctionOut = $ZodFunctionOut> extends $ZodTypeDef {
	type: "function";
	input: In;
	output: Out;
}
export interface $ZodFunctionInternals<Args extends $ZodFunctionIn, Returns extends $ZodFunctionOut> extends $ZodTypeInternals<$InferOuterFunctionType<Args, Returns>, $InferInnerFunctionType<Args, Returns>> {
	def: $ZodFunctionDef<Args, Returns>;
	isst: $ZodIssueInvalidType;
}
export interface $ZodFunction<Args extends $ZodFunctionIn = $ZodFunctionIn, Returns extends $ZodFunctionOut = $ZodFunctionOut> extends $ZodType<any, any, $ZodFunctionInternals<Args, Returns>> {
	/** @deprecated */
	_def: $ZodFunctionDef<Args, Returns>;
	_input: $InferInnerFunctionType<Args, Returns>;
	_output: $InferOuterFunctionType<Args, Returns>;
	implement<F extends $InferInnerFunctionType<Args, Returns>>(func: F): (...args: Parameters<this["_output"]>) => ReturnType<F> extends ReturnType<this["_output"]> ? ReturnType<F> : ReturnType<this["_output"]>;
	implementAsync<F extends $InferInnerFunctionTypeAsync<Args, Returns>>(func: F): F extends $InferOuterFunctionTypeAsync<Args, Returns> ? F : $InferOuterFunctionTypeAsync<Args, Returns>;
	input<const Items extends util.TupleItems, const Rest extends $ZodFunctionOut = $ZodFunctionOut>(args: Items, rest?: Rest): $ZodFunction<$ZodTuple<Items, Rest>, Returns>;
	input<NewArgs extends $ZodFunctionIn>(args: NewArgs): $ZodFunction<NewArgs, Returns>;
	input(...args: any[]): $ZodFunction<any, Returns>;
	output<NewReturns extends $ZodType>(output: NewReturns): $ZodFunction<Args, NewReturns>;
}
export interface $ZodFunctionParams<I extends $ZodFunctionIn, O extends $ZodType> {
	input?: I;
	output?: O;
}
declare const $ZodFunction: $constructor<$ZodFunction>;
export interface $ZodPromiseDef<T extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "promise";
	innerType: T;
}
export interface $ZodPromiseInternals<T extends SomeType = $ZodType> extends $ZodTypeInternals<Promise<output<T>>, util.MaybeAsync<input<T>>> {
	def: $ZodPromiseDef<T>;
	isst: never;
}
export interface $ZodPromise<T extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodPromiseInternals<T>;
}
declare const $ZodPromise: $constructor<$ZodPromise>;
export interface $ZodLazyDef<T extends SomeType = $ZodType> extends $ZodTypeDef {
	type: "lazy";
	getter: () => T;
}
export interface $ZodLazyInternals<T extends SomeType = $ZodType> extends $ZodTypeInternals<output<T>, input<T>> {
	def: $ZodLazyDef<T>;
	isst: never;
	/** Auto-cached way to retrieve the inner schema */
	innerType: T;
	pattern: T["_zod"]["pattern"];
	propValues: T["_zod"]["propValues"];
	optin: T["_zod"]["optin"];
	optout: T["_zod"]["optout"];
}
export interface $ZodLazy<T extends SomeType = $ZodType> extends $ZodType {
	_zod: $ZodLazyInternals<T>;
}
declare const $ZodLazy: $constructor<$ZodLazy>;
export interface $ZodCustomDef<O = unknown> extends $ZodTypeDef, $ZodCheckDef {
	type: "custom";
	check: "custom";
	path?: PropertyKey[] | undefined;
	error?: $ZodErrorMap | undefined;
	params?: Record<string, any> | undefined;
	fn: (arg: O) => unknown;
}
export interface $ZodCustomInternals<O = unknown, I = unknown> extends $ZodTypeInternals<O, I>, $ZodCheckInternals<O> {
	def: $ZodCustomDef;
	issc: $ZodIssue;
	isst: never;
	bag: util.LoosePartial<{
		Class: typeof util.Class;
	}>;
}
export interface $ZodCustom<O = unknown, I = unknown> extends $ZodType {
	_zod: $ZodCustomInternals<O, I>;
}
declare const $ZodCustom: $constructor<$ZodCustom>;
export type $ZodTypes = $ZodString | $ZodNumber | $ZodBigInt | $ZodBoolean | $ZodDate | $ZodSymbol | $ZodUndefined | $ZodNullable | $ZodNull | $ZodAny | $ZodUnknown | $ZodNever | $ZodVoid | $ZodArray | $ZodObject | $ZodUnion | $ZodIntersection | $ZodTuple | $ZodRecord | $ZodMap | $ZodSet | $ZodLiteral | $ZodEnum | $ZodFunction | $ZodPromise | $ZodLazy | $ZodOptional | $ZodDefault | $ZodPrefault | $ZodTemplateLiteral | $ZodCustom | $ZodTransform | $ZodNonOptional | $ZodReadonly | $ZodNaN | $ZodPipe | $ZodSuccess | $ZodCatch | $ZodFile;
export type $ZodStringFormatTypes = $ZodGUID | $ZodUUID | $ZodEmail | $ZodURL | $ZodEmoji | $ZodNanoID | $ZodCUID | $ZodCUID2 | $ZodULID | $ZodXID | $ZodKSUID | $ZodISODateTime | $ZodISODate | $ZodISOTime | $ZodISODuration | $ZodIPv4 | $ZodIPv6 | $ZodMAC | $ZodCIDRv4 | $ZodCIDRv6 | $ZodBase64 | $ZodBase64URL | $ZodE164 | $ZodCreditCard | $ZodJWT | $ZodCustomStringFormat<"hex"> | $ZodCustomStringFormat<util.HashFormat> | $ZodCustomStringFormat<"hostname">;
export interface $ZodCheckDef {
	check: string;
	error?: $ZodErrorMap<never> | undefined;
	/** If true, no later checks will be executed if this check fails. Default `false`. */
	abort?: boolean | undefined;
	/** If provided, the check runs only when this returns `true`. By default, it is skipped if prior parsing produced aborting issues. */
	when?: ((payload: schemas.ParsePayload) => boolean) | undefined;
}
export interface $ZodCheckInternals<T> {
	def: $ZodCheckDef;
	/** The set of issues this check might throw. */
	issc?: $ZodIssueBase;
	check(payload: schemas.ParsePayload<T>): util.MaybeAsync<void>;
	onattach: ((schema: schemas.$ZodType) => void)[];
}
export interface $ZodCheck<in T = never> {
	_zod: $ZodCheckInternals<T>;
}
declare const $ZodCheck: $constructor<$ZodCheck<any>>;
export interface $ZodCheckLessThanDef extends $ZodCheckDef {
	check: "less_than";
	value: util.Numeric;
	inclusive: boolean;
}
export interface $ZodCheckLessThanInternals<T extends util.Numeric = util.Numeric> extends $ZodCheckInternals<T> {
	def: $ZodCheckLessThanDef;
	issc: $ZodIssueTooBig<T>;
}
export interface $ZodCheckLessThan<T extends util.Numeric = util.Numeric> extends $ZodCheck<T> {
	_zod: $ZodCheckLessThanInternals<T>;
}
declare const $ZodCheckLessThan: $constructor<$ZodCheckLessThan>;
export interface $ZodCheckGreaterThanDef extends $ZodCheckDef {
	check: "greater_than";
	value: util.Numeric;
	inclusive: boolean;
}
export interface $ZodCheckGreaterThanInternals<T extends util.Numeric = util.Numeric> extends $ZodCheckInternals<T> {
	def: $ZodCheckGreaterThanDef;
	issc: $ZodIssueTooSmall<T>;
}
export interface $ZodCheckGreaterThan<T extends util.Numeric = util.Numeric> extends $ZodCheck<T> {
	_zod: $ZodCheckGreaterThanInternals<T>;
}
declare const $ZodCheckGreaterThan: $constructor<$ZodCheckGreaterThan>;
export interface $ZodCheckMultipleOfDef<T extends number | bigint = number | bigint> extends $ZodCheckDef {
	check: "multiple_of";
	value: T;
}
export interface $ZodCheckMultipleOfInternals<T extends number | bigint = number | bigint> extends $ZodCheckInternals<T> {
	def: $ZodCheckMultipleOfDef<T>;
	issc: $ZodIssueNotMultipleOf;
}
export interface $ZodCheckMultipleOf<T extends number | bigint = number | bigint> extends $ZodCheck<T> {
	_zod: $ZodCheckMultipleOfInternals<T>;
}
declare const $ZodCheckMultipleOf: $constructor<$ZodCheckMultipleOf<number | bigint>>;
export type $ZodNumberFormats = "int32" | "uint32" | "float32" | "float64" | "safeint";
export interface $ZodCheckNumberFormatDef<Format extends $ZodNumberFormats = $ZodNumberFormats> extends $ZodCheckDef {
	check: "number_format";
	format: Format;
}
export interface $ZodCheckNumberFormatInternals<Format extends $ZodNumberFormats = $ZodNumberFormats> extends $ZodCheckInternals<number> {
	def: $ZodCheckNumberFormatDef<Format>;
	issc: $ZodIssueInvalidType | $ZodIssueTooBig<"number"> | $ZodIssueTooSmall<"number">;
}
export interface $ZodCheckNumberFormat<Format extends $ZodNumberFormats = $ZodNumberFormats> extends $ZodCheck<number> {
	_zod: $ZodCheckNumberFormatInternals<Format>;
}
declare const $ZodCheckNumberFormat: $constructor<$ZodCheckNumberFormat>;
export type $ZodBigIntFormats = "int64" | "uint64";
export interface $ZodCheckBigIntFormatDef<Format extends $ZodBigIntFormats = $ZodBigIntFormats> extends $ZodCheckDef {
	check: "bigint_format";
	format: Format;
}
export interface $ZodCheckBigIntFormatInternals<Format extends $ZodBigIntFormats = $ZodBigIntFormats> extends $ZodCheckInternals<bigint> {
	def: $ZodCheckBigIntFormatDef<Format>;
	issc: $ZodIssueTooBig<"bigint"> | $ZodIssueTooSmall<"bigint">;
}
export interface $ZodCheckBigIntFormat<Format extends $ZodBigIntFormats = $ZodBigIntFormats> extends $ZodCheck<bigint> {
	_zod: $ZodCheckBigIntFormatInternals<Format>;
}
declare const $ZodCheckBigIntFormat: $constructor<$ZodCheckBigIntFormat>;
export interface $ZodCheckMaxSizeDef extends $ZodCheckDef {
	check: "max_size";
	maximum: number;
}
export interface $ZodCheckMaxSizeInternals<T extends util.HasSize = util.HasSize> extends $ZodCheckInternals<T> {
	def: $ZodCheckMaxSizeDef;
	issc: $ZodIssueTooBig<T>;
}
export interface $ZodCheckMaxSize<T extends util.HasSize = util.HasSize> extends $ZodCheck<T> {
	_zod: $ZodCheckMaxSizeInternals<T>;
}
declare const $ZodCheckMaxSize: $constructor<$ZodCheckMaxSize>;
export interface $ZodCheckMinSizeDef extends $ZodCheckDef {
	check: "min_size";
	minimum: number;
}
export interface $ZodCheckMinSizeInternals<T extends util.HasSize = util.HasSize> extends $ZodCheckInternals<T> {
	def: $ZodCheckMinSizeDef;
	issc: $ZodIssueTooSmall<T>;
}
export interface $ZodCheckMinSize<T extends util.HasSize = util.HasSize> extends $ZodCheck<T> {
	_zod: $ZodCheckMinSizeInternals<T>;
}
declare const $ZodCheckMinSize: $constructor<$ZodCheckMinSize>;
export interface $ZodCheckSizeEqualsDef extends $ZodCheckDef {
	check: "size_equals";
	size: number;
}
export interface $ZodCheckSizeEqualsInternals<T extends util.HasSize = util.HasSize> extends $ZodCheckInternals<T> {
	def: $ZodCheckSizeEqualsDef;
	issc: $ZodIssueTooBig<T> | $ZodIssueTooSmall<T>;
}
export interface $ZodCheckSizeEquals<T extends util.HasSize = util.HasSize> extends $ZodCheck<T> {
	_zod: $ZodCheckSizeEqualsInternals<T>;
}
declare const $ZodCheckSizeEquals: $constructor<$ZodCheckSizeEquals>;
export interface $ZodCheckMaxLengthDef extends $ZodCheckDef {
	check: "max_length";
	maximum: number;
}
export interface $ZodCheckMaxLengthInternals<T extends util.HasLength = util.HasLength> extends $ZodCheckInternals<T> {
	def: $ZodCheckMaxLengthDef;
	issc: $ZodIssueTooBig<T>;
}
export interface $ZodCheckMaxLength<T extends util.HasLength = util.HasLength> extends $ZodCheck<T> {
	_zod: $ZodCheckMaxLengthInternals<T>;
}
declare const $ZodCheckMaxLength: $constructor<$ZodCheckMaxLength>;
export interface $ZodCheckMinLengthDef extends $ZodCheckDef {
	check: "min_length";
	minimum: number;
}
export interface $ZodCheckMinLengthInternals<T extends util.HasLength = util.HasLength> extends $ZodCheckInternals<T> {
	def: $ZodCheckMinLengthDef;
	issc: $ZodIssueTooSmall<T>;
}
export interface $ZodCheckMinLength<T extends util.HasLength = util.HasLength> extends $ZodCheck<T> {
	_zod: $ZodCheckMinLengthInternals<T>;
}
declare const $ZodCheckMinLength: $constructor<$ZodCheckMinLength>;
export interface $ZodCheckLengthEqualsDef extends $ZodCheckDef {
	check: "length_equals";
	length: number;
}
export interface $ZodCheckLengthEqualsInternals<T extends util.HasLength = util.HasLength> extends $ZodCheckInternals<T> {
	def: $ZodCheckLengthEqualsDef;
	issc: $ZodIssueTooBig<T> | $ZodIssueTooSmall<T>;
}
export interface $ZodCheckLengthEquals<T extends util.HasLength = util.HasLength> extends $ZodCheck<T> {
	_zod: $ZodCheckLengthEqualsInternals<T>;
}
declare const $ZodCheckLengthEquals: $constructor<$ZodCheckLengthEquals>;
export type $ZodStringFormats = "email" | "url" | "emoji" | "uuid" | "guid" | "nanoid" | "cuid" | "cuid2" | "ulid" | "xid" | "ksuid" | "datetime" | "date" | "time" | "duration" | "ipv4" | "ipv6" | "cidrv4" | "cidrv6" | "base64" | "base64url" | "json_string" | "e164" | "credit_card" | "lowercase" | "uppercase" | "regex" | "jwt" | "starts_with" | "ends_with" | "includes";
export interface $ZodCheckStringFormatDef<Format extends string = string> extends $ZodCheckDef {
	check: "string_format";
	format: Format;
	pattern?: RegExp | undefined;
}
export interface $ZodCheckStringFormatInternals extends $ZodCheckInternals<string> {
	def: $ZodCheckStringFormatDef;
	issc: $ZodIssueInvalidStringFormat;
}
export interface $ZodCheckStringFormat extends $ZodCheck<string> {
	_zod: $ZodCheckStringFormatInternals;
}
declare const $ZodCheckStringFormat: $constructor<$ZodCheckStringFormat>;
export interface $ZodCheckRegexDef extends $ZodCheckStringFormatDef {
	format: "regex";
	pattern: RegExp;
}
export interface $ZodCheckRegexInternals extends $ZodCheckInternals<string> {
	def: $ZodCheckRegexDef;
	issc: $ZodIssueInvalidStringFormat;
}
export interface $ZodCheckRegex extends $ZodCheck<string> {
	_zod: $ZodCheckRegexInternals;
}
declare const $ZodCheckRegex: $constructor<$ZodCheckRegex>;
export interface $ZodCheckLowerCaseDef extends $ZodCheckStringFormatDef<"lowercase"> {
}
export interface $ZodCheckLowerCaseInternals extends $ZodCheckInternals<string> {
	def: $ZodCheckLowerCaseDef;
	issc: $ZodIssueInvalidStringFormat;
}
export interface $ZodCheckLowerCase extends $ZodCheck<string> {
	_zod: $ZodCheckLowerCaseInternals;
}
declare const $ZodCheckLowerCase: $constructor<$ZodCheckLowerCase>;
export interface $ZodCheckUpperCaseDef extends $ZodCheckStringFormatDef<"uppercase"> {
}
export interface $ZodCheckUpperCaseInternals extends $ZodCheckInternals<string> {
	def: $ZodCheckUpperCaseDef;
	issc: $ZodIssueInvalidStringFormat;
}
export interface $ZodCheckUpperCase extends $ZodCheck<string> {
	_zod: $ZodCheckUpperCaseInternals;
}
declare const $ZodCheckUpperCase: $constructor<$ZodCheckUpperCase>;
export interface $ZodCheckIncludesDef extends $ZodCheckStringFormatDef<"includes"> {
	includes: string;
	position?: number | undefined;
}
export interface $ZodCheckIncludesInternals extends $ZodCheckInternals<string> {
	def: $ZodCheckIncludesDef;
	issc: $ZodIssueInvalidStringFormat;
}
export interface $ZodCheckIncludes extends $ZodCheck<string> {
	_zod: $ZodCheckIncludesInternals;
}
declare const $ZodCheckIncludes: $constructor<$ZodCheckIncludes>;
export interface $ZodCheckStartsWithDef extends $ZodCheckStringFormatDef<"starts_with"> {
	prefix: string;
}
export interface $ZodCheckStartsWithInternals extends $ZodCheckInternals<string> {
	def: $ZodCheckStartsWithDef;
	issc: $ZodIssueInvalidStringFormat;
}
export interface $ZodCheckStartsWith extends $ZodCheck<string> {
	_zod: $ZodCheckStartsWithInternals;
}
declare const $ZodCheckStartsWith: $constructor<$ZodCheckStartsWith>;
export interface $ZodCheckEndsWithDef extends $ZodCheckStringFormatDef<"ends_with"> {
	suffix: string;
}
export interface $ZodCheckEndsWithInternals extends $ZodCheckInternals<string> {
	def: $ZodCheckEndsWithDef;
	issc: $ZodIssueInvalidStringFormat;
}
export interface $ZodCheckEndsWith extends $ZodCheckInternals<string> {
	_zod: $ZodCheckEndsWithInternals;
}
declare const $ZodCheckEndsWith: $constructor<$ZodCheckEndsWith>;
export interface $ZodCheckPropertyDef extends $ZodCheckDef {
	check: "property";
	property: string;
	schema: schemas.$ZodType;
}
export interface $ZodCheckPropertyInternals<T extends object = object> extends $ZodCheckInternals<T> {
	def: $ZodCheckPropertyDef;
	issc: $ZodIssue;
}
export interface $ZodCheckProperty<T extends object = object> extends $ZodCheck<T> {
	_zod: $ZodCheckPropertyInternals<T>;
}
declare const $ZodCheckProperty: $constructor<$ZodCheckProperty>;
export interface $ZodCheckMimeTypeDef extends $ZodCheckDef {
	check: "mime_type";
	mime: util.MimeTypes[];
}
export interface $ZodCheckMimeTypeInternals<T extends schemas.File = schemas.File> extends $ZodCheckInternals<T> {
	def: $ZodCheckMimeTypeDef;
	issc: $ZodIssueInvalidValue;
}
export interface $ZodCheckMimeType<T extends schemas.File = schemas.File> extends $ZodCheck<T> {
	_zod: $ZodCheckMimeTypeInternals<T>;
}
declare const $ZodCheckMimeType: $constructor<$ZodCheckMimeType>;
export interface $ZodCheckOverwriteDef<T = unknown> extends $ZodCheckDef {
	check: "overwrite";
	tx(value: T): T;
}
export interface $ZodCheckOverwriteInternals<T = unknown> extends $ZodCheckInternals<T> {
	def: $ZodCheckOverwriteDef<T>;
	issc: never;
}
export interface $ZodCheckOverwrite<T = unknown> extends $ZodCheck<T> {
	_zod: $ZodCheckOverwriteInternals<T>;
}
declare const $ZodCheckOverwrite: $constructor<$ZodCheckOverwrite>;
export type $ZodChecks = $ZodCheckLessThan | $ZodCheckGreaterThan | $ZodCheckMultipleOf | $ZodCheckNumberFormat | $ZodCheckBigIntFormat | $ZodCheckMaxSize | $ZodCheckMinSize | $ZodCheckSizeEquals | $ZodCheckMaxLength | $ZodCheckMinLength | $ZodCheckLengthEquals | $ZodCheckStringFormat | $ZodCheckProperty | $ZodCheckMimeType | $ZodCheckOverwrite;
export type $ZodStringFormatChecks = $ZodCheckRegex | $ZodCheckLowerCase | $ZodCheckUpperCase | $ZodCheckIncludes | $ZodCheckStartsWith | $ZodCheckEndsWith | schemas.$ZodStringFormatTypes;
export interface $ZodIssueBase {
	readonly code?: string;
	readonly input?: unknown;
	readonly path: PropertyKey[];
	readonly message: string;
}
export type $ZodInvalidTypeExpected = "string" | "number" | "int" | "boolean" | "bigint" | "symbol" | "undefined" | "null" | "never" | "void" | "date" | "array" | "object" | "tuple" | "record" | "map" | "set" | "file" | "nonoptional" | "nan" | "function" | (string & {});
export interface $ZodIssueInvalidType<Input = unknown> extends $ZodIssueBase {
	readonly code: "invalid_type";
	readonly expected: $ZodInvalidTypeExpected;
	readonly input?: Input;
}
export interface $ZodIssueTooBig<Input = unknown> extends $ZodIssueBase {
	readonly code: "too_big";
	readonly origin: "number" | "int" | "bigint" | "date" | "string" | "array" | "set" | "file" | (string & {});
	readonly maximum: number | bigint;
	readonly inclusive?: boolean;
	readonly exact?: boolean;
	readonly input?: Input;
}
export interface $ZodIssueTooSmall<Input = unknown> extends $ZodIssueBase {
	readonly code: "too_small";
	readonly origin: "number" | "int" | "bigint" | "date" | "string" | "array" | "set" | "file" | (string & {});
	readonly minimum: number | bigint;
	/** True if the allowable range includes the minimum */
	readonly inclusive?: boolean;
	/** True if the allowed value is fixed (e.g.` z.length(5)`), not a range (`z.minLength(5)`) */
	readonly exact?: boolean;
	readonly input?: Input;
}
export interface $ZodIssueInvalidStringFormat extends $ZodIssueBase {
	readonly code: "invalid_format";
	readonly format: $ZodStringFormats | (string & {});
	readonly pattern?: string;
	readonly input?: string;
}
export interface $ZodIssueNotMultipleOf<Input extends number | bigint = number | bigint> extends $ZodIssueBase {
	readonly code: "not_multiple_of";
	readonly divisor: number;
	readonly input?: Input;
}
export interface $ZodIssueUnrecognizedKeys extends $ZodIssueBase {
	readonly code: "unrecognized_keys";
	readonly keys: string[];
	readonly input?: Record<string, unknown>;
}
export interface $ZodIssueInvalidUnionNoMatch extends $ZodIssueBase {
	readonly code: "invalid_union";
	readonly errors: $ZodIssue[][];
	readonly input?: unknown;
	readonly discriminator?: string | undefined;
	readonly options?: util.Primitive[];
	readonly inclusive?: true;
}
export interface $ZodIssueInvalidUnionMultipleMatch extends $ZodIssueBase {
	readonly code: "invalid_union";
	readonly errors: [
	];
	readonly input?: unknown;
	readonly discriminator?: string | undefined;
	readonly inclusive: false;
	/** Indices of the options that matched */
	readonly matches: number[];
}
export type $ZodIssueInvalidUnion = $ZodIssueInvalidUnionNoMatch | $ZodIssueInvalidUnionMultipleMatch;
export interface $ZodIssueInvalidKey<Input = unknown> extends $ZodIssueBase {
	readonly code: "invalid_key";
	readonly origin: "map" | "record";
	readonly issues: $ZodIssue[];
	readonly input?: Input;
}
export interface $ZodIssueInvalidElement<Input = unknown> extends $ZodIssueBase {
	readonly code: "invalid_element";
	readonly origin: "map" | "set";
	readonly key: unknown;
	readonly issues: $ZodIssue[];
	readonly input?: Input;
}
export interface $ZodIssueInvalidValue<Input = unknown> extends $ZodIssueBase {
	readonly code: "invalid_value";
	readonly values: util.Primitive[];
	readonly input?: Input;
}
export interface $ZodIssueCustom extends $ZodIssueBase {
	readonly code: "custom";
	readonly params?: Record<string, any> | undefined;
	readonly input?: unknown;
}
export interface $ZodIssueStringCommonFormats extends $ZodIssueInvalidStringFormat {
	format: Exclude<$ZodStringFormats, "regex" | "jwt" | "starts_with" | "ends_with" | "includes">;
}
export interface $ZodIssueStringInvalidRegex extends $ZodIssueInvalidStringFormat {
	format: "regex";
	pattern: string;
}
export interface $ZodIssueStringInvalidJWT extends $ZodIssueInvalidStringFormat {
	format: "jwt";
	algorithm?: string;
}
export interface $ZodIssueStringStartsWith extends $ZodIssueInvalidStringFormat {
	format: "starts_with";
	prefix: string;
}
export interface $ZodIssueStringEndsWith extends $ZodIssueInvalidStringFormat {
	format: "ends_with";
	suffix: string;
}
export interface $ZodIssueStringIncludes extends $ZodIssueInvalidStringFormat {
	format: "includes";
	includes: string;
}
export type $ZodStringFormatIssues = $ZodIssueStringCommonFormats | $ZodIssueStringInvalidRegex | $ZodIssueStringInvalidJWT | $ZodIssueStringStartsWith | $ZodIssueStringEndsWith | $ZodIssueStringIncludes;
export type $ZodIssue = $ZodIssueInvalidType | $ZodIssueTooBig | $ZodIssueTooSmall | $ZodIssueInvalidStringFormat | $ZodIssueNotMultipleOf | $ZodIssueUnrecognizedKeys | $ZodIssueInvalidUnion | $ZodIssueInvalidKey | $ZodIssueInvalidElement | $ZodIssueInvalidValue | $ZodIssueCustom;
export type $ZodIssueCode = $ZodIssue["code"];
export type $ZodInternalIssue<T extends $ZodIssueBase = $ZodIssue> = T extends any ? RawIssue<T> : never;
export type RawIssue<T extends $ZodIssueBase> = T extends any ? util.Flatten<util.MakePartial<T, "message" | "path"> & {
	/** The input data */
	readonly input: unknown;
	/** The schema or check that originated this issue. */
	readonly inst?: $ZodType | $ZodCheck;
	/** The schema that owns the issue. Equal to `inst` when a schema originated the issue, and the schema the check was attached to when a check did. */
	readonly schema?: $ZodType | undefined;
	/** If `true`, Zod will continue executing checks/refinements after this issue. */
	readonly continue?: boolean | undefined;
} & Record<string, unknown>> : never;
export type $ZodRawIssue<T extends $ZodIssueBase = $ZodIssue> = $ZodInternalIssue<T>;
export interface $ZodErrorMap<T extends $ZodIssueBase = $ZodIssue> {
	(issue: $ZodRawIssue<T>): {
		message: string;
	} | string | undefined | null;
}
export interface $ZodError<T = unknown> extends Error {
	type: T;
	issues: $ZodIssue[];
	_zod: {
		output: T;
		def: $ZodIssue[];
	};
	stack?: string;
	name: string;
}
declare const $ZodError: $constructor<$ZodError>;
export interface $ZodRealError<T = any> extends $ZodError<T> {
}
declare const $ZodRealError$1: $constructor<$ZodRealError>;
export type $ZodFlattenedError<T, U = string> = _FlattenedError<T, U>;
export type _FlattenedError<T, U = string> = {
	formErrors: U[];
	fieldErrors: {
		[P in keyof T]?: U[];
	};
};
declare function flattenError<T>(error: $ZodError<T>): _FlattenedError<T>;
declare function flattenError<T, U>(error: $ZodError<T>, mapper?: (issue: $ZodIssue) => U): _FlattenedError<T, U>;
export type _ZodFormattedError<T, U = string> = T extends [
	any,
	...any[]
] ? {
	[K in keyof T]?: $ZodFormattedError<T[K], U>;
} : T extends any[] ? {
	[k: number]: $ZodFormattedError<T[number], U>;
} : T extends object ? util.Flatten<{
	[K in keyof T]?: $ZodFormattedError<T[K], U>;
}> : any;
export type $ZodFormattedError<T, U = string> = {
	_errors: U[];
} & util.Flatten<_ZodFormattedError<T, U>>;
declare function formatError<T>(error: $ZodError<T>): $ZodFormattedError<T>;
declare function formatError<T, U>(error: $ZodError<T>, mapper?: (issue: $ZodIssue) => U): $ZodFormattedError<T, U>;
export type $ZodErrorTree<T, U = string> = T extends util.Primitive ? {
	errors: U[];
} : T extends [
	any,
	...any[]
] ? {
	errors: U[];
	items?: {
		[K in keyof T]?: $ZodErrorTree<T[K], U>;
	};
} : T extends any[] ? {
	errors: U[];
	items?: Array<$ZodErrorTree<T[number], U>>;
} : T extends object ? {
	errors: U[];
	properties?: {
		[K in keyof T]?: $ZodErrorTree<T[K], U>;
	};
} : {
	errors: U[];
};
declare function treeifyError<T>(error: $ZodError<T>): $ZodErrorTree<T>;
declare function treeifyError<T, U>(error: $ZodError<T>, mapper?: (issue: $ZodIssue) => U): $ZodErrorTree<T, U>;
declare function toDotPath(_path: readonly (string | number | symbol | StandardSchemaV1.PathSegment)[]): string;
declare function prettifyError(error: StandardSchemaV1.FailureResult): string;
export type ZodTrait = {
	_zod: {
		def: any;
		[k: string]: any;
	};
};
export interface $constructor<T extends ZodTrait, D = T["_zod"]["def"]> {
	new (def: D): T;
	init(inst: T, def: D): asserts inst is T;
}
export interface $constructorParams {
	Parent?: typeof Class;
}
declare const NEVER: never;
declare function $constructor<T extends ZodTrait, D = T["_zod"]["def"]>(name: string, initializer: (inst: T, def: D) => void, 
/** This trait's members, installed once on every prototype that composes it. They cannot be declared in the initializer above: that runs per instance, and the prototype is shared. */
proto?: ProtoOf<T>, params?: $constructorParams): $constructor<T, D>;
declare const $brand: unique symbol;
export type $brand<T extends string | number | symbol = string | number | symbol> = {
	[$brand]: {
		[k in T]: true;
	};
};
export type $ZodBranded<T extends schemas.SomeType, Brand extends string | number | symbol, Dir extends "in" | "out" | "inout" = "out"> = T & (Dir extends "inout" ? {
	_zod: {
		input: input<T> & $brand<Brand>;
		output: output<T> & $brand<Brand>;
	};
} : Dir extends "in" ? {
	_zod: {
		input: input<T> & $brand<Brand>;
	};
} : {
	_zod: {
		output: output<T> & $brand<Brand>;
	};
});
export type $ZodNarrow<T extends schemas.SomeType, Out> = T & {
	_zod: {
		output: Out;
	};
};
declare class $ZodAsyncError extends Error {
	constructor();
}
declare class $ZodEncodeError extends Error {
	constructor(name: string);
}
export type input<T> = T extends {
	_zod: {
		input: any;
	};
} ? T["_zod"]["input"] : unknown;
export type output<T> = T extends {
	_zod: {
		output: any;
	};
} ? T["_zod"]["output"] : unknown;
export interface $ZodConfig {
	/** Custom error map. Overrides `config().localeError`. */
	customError?: $ZodErrorMap | undefined;
	/** Localized error map. Lowest priority. */
	localeError?: $ZodErrorMap | undefined;
	/** Disable JIT schema compilation. Useful in environments that disallow `eval`. */
	jitless?: boolean | undefined;
	/** Enables parsing input that contains reference cycles. Read when a schema is constructed. */
	memoizer?: schemas.$ZodMemoizer | undefined;
	/**
	 * Internal: post-processor invoked on every freshly-constructed schema
	 * instance, after init and deferred fns run. Set by `import "zod/compile"`
	 * to install AOT compilation. Not part of the public API.
	 * @internal
	 */
	postProcessor?: ((inst: any) => void) | undefined;
}
declare const globalConfig: $ZodConfig;
declare function config(newConfig?: Partial<$ZodConfig>): $ZodConfig;
export type $ZodErrorClass = {
	new (issues: $ZodIssue[]): $ZodError;
};
export type $ParseParams = {
	callee?: util.AnyFunc;
	Err?: $ZodErrorClass | undefined;
};
export type $Parse = <T extends schemas.$ZodType>(schema: T, value: unknown, _ctx?: schemas.ParseContext<$ZodIssue>, _params?: $ParseParams) => output<T>;
declare const _parse: (_Err: $ZodErrorClass) => $Parse;
declare const parse: $Parse;
export type $ParseAsync = <T extends schemas.$ZodType>(schema: T, value: unknown, _ctx?: schemas.ParseContext<$ZodIssue>, _params?: $ParseParams) => Promise<output<T>>;
declare const _parseAsync: (_Err: $ZodErrorClass) => $ParseAsync;
declare const parseAsync: $ParseAsync;
export type $SafeParse = <T extends schemas.$ZodType>(schema: T, value: unknown, _ctx?: schemas.ParseContext<$ZodIssue>) => util.SafeParseResult<output<T>>;
declare const _safeParse: (_Err: $ZodErrorClass) => $SafeParse;
declare const safeParse: $SafeParse;
export type $SafeParseAsync = <T extends schemas.$ZodType>(schema: T, value: unknown, _ctx?: schemas.ParseContext<$ZodIssue>) => Promise<util.SafeParseResult<output<T>>>;
declare const _safeParseAsync: (_Err: $ZodErrorClass) => $SafeParseAsync;
declare const safeParseAsync: $SafeParseAsync;
export type $Validate = <T extends schemas.$ZodType>(schema: T, value: unknown, _ctx?: schemas.ParseContext<$ZodIssue>) => value is input<T>;
declare const validate: $Validate;
export type $ValidateAsync = <T extends schemas.$ZodType>(schema: T, value: unknown, _ctx?: schemas.ParseContext<$ZodIssue>) => Promise<boolean>;
declare const validateAsync: $ValidateAsync;
export type $Encode = <T extends schemas.$ZodType>(schema: T, value: output<T>, _ctx?: schemas.ParseContext<$ZodIssue>, _params?: $ParseParams) => input<T>;
declare const _encode: (_Err: $ZodErrorClass) => $Encode;
declare const encode: $Encode;
export type $Decode = <T extends schemas.$ZodType>(schema: T, value: input<T>, _ctx?: schemas.ParseContext<$ZodIssue>, _params?: $ParseParams) => output<T>;
declare const _decode: (_Err: $ZodErrorClass) => $Decode;
declare const decode: $Decode;
export type $EncodeAsync = <T extends schemas.$ZodType>(schema: T, value: output<T>, _ctx?: schemas.ParseContext<$ZodIssue>, _params?: $ParseParams) => Promise<input<T>>;
declare const _encodeAsync: (_Err: $ZodErrorClass) => $EncodeAsync;
declare const encodeAsync: $EncodeAsync;
export type $DecodeAsync = <T extends schemas.$ZodType>(schema: T, value: input<T>, _ctx?: schemas.ParseContext<$ZodIssue>, _params?: $ParseParams) => Promise<output<T>>;
declare const _decodeAsync: (_Err: $ZodErrorClass) => $DecodeAsync;
declare const decodeAsync: $DecodeAsync;
export type $SafeEncode = <T extends schemas.$ZodType>(schema: T, value: output<T>, _ctx?: schemas.ParseContext<$ZodIssue>) => util.SafeParseResult<input<T>>;
declare const _safeEncode: (_Err: $ZodErrorClass) => $SafeEncode;
declare const safeEncode: $SafeEncode;
export type $SafeDecode = <T extends schemas.$ZodType>(schema: T, value: input<T>, _ctx?: schemas.ParseContext<$ZodIssue>) => util.SafeParseResult<output<T>>;
declare const _safeDecode: (_Err: $ZodErrorClass) => $SafeDecode;
declare const safeDecode: $SafeDecode;
export type $SafeEncodeAsync = <T extends schemas.$ZodType>(schema: T, value: output<T>, _ctx?: schemas.ParseContext<$ZodIssue>) => Promise<util.SafeParseResult<input<T>>>;
declare const _safeEncodeAsync: (_Err: $ZodErrorClass) => $SafeEncodeAsync;
declare const safeEncodeAsync: $SafeEncodeAsync;
export type $SafeDecodeAsync = <T extends schemas.$ZodType>(schema: T, value: input<T>, _ctx?: schemas.ParseContext<$ZodIssue>) => Promise<util.SafeParseResult<output<T>>>;
declare const _safeDecodeAsync: (_Err: $ZodErrorClass) => $SafeDecodeAsync;
declare const safeDecodeAsync: $SafeDecodeAsync;
declare class $ZodCyclicError extends Error {
	constructor();
}
declare function isRecursiveSchema(inst: $ZodType): boolean;
declare function memoizer(): $ZodMemoizer;
declare function isBackEdge(ctx: object, value: unknown): boolean;
declare const cuid: RegExp;
declare const cuid2: RegExp;
declare const ulid: RegExp;
declare const xid: RegExp;
declare const ksuid: RegExp;
declare const nanoid: RegExp;
declare function nanoidOfLength(length: number): RegExp;
declare const duration: RegExp;
declare const extendedDuration: RegExp;
declare const guid: RegExp;
declare const uuid: (version?: number | undefined) => RegExp;
declare const uuid4: RegExp;
declare const uuid6: RegExp;
declare const uuid7: RegExp;
declare const email: RegExp;
declare const html5Email: RegExp;
declare const rfc5322Email: RegExp;
declare const unicodeEmail: RegExp;
declare const idnEmail: RegExp;
declare const browserEmail: RegExp;
declare function emoji(): RegExp;
declare const ipv4: RegExp;
declare const ipv6: RegExp;
declare const mac: (delimiter?: string) => RegExp;
declare const cidrv4: RegExp;
declare const cidrv6: RegExp;
declare const base64: RegExp;
declare const base64url: RegExp;
declare const hostname: RegExp;
declare const domain: RegExp;
declare const httpProtocol: RegExp;
declare const e164: RegExp;
declare const creditCard: RegExp;
declare const date: RegExp;
declare function time(args: {
	precision?: number | null;
}): RegExp;
declare function datetime(args: {
	precision?: number | null;
	offset?: boolean;
	local?: boolean;
}): RegExp;
declare const string: (params?: {
	minimum?: number | undefined;
	maximum?: number | undefined;
}) => RegExp;
declare const bigint: RegExp;
declare const integer: RegExp;
declare const number: RegExp;
declare const boolean: RegExp;
declare const _null: RegExp;
declare const _undefined: RegExp;
declare const lowercase: RegExp;
declare const uppercase: RegExp;
declare const hex: RegExp;
declare const md5_hex: RegExp;
declare const md5_base64: RegExp;
declare const md5_base64url: RegExp;
declare const sha1_hex: RegExp;
declare const sha1_base64: RegExp;
declare const sha1_base64url: RegExp;
declare const sha256_hex: RegExp;
declare const sha256_base64: RegExp;
declare const sha256_base64url: RegExp;
declare const sha384_hex: RegExp;
declare const sha384_base64: RegExp;
declare const sha384_base64url: RegExp;
declare const sha512_hex: RegExp;
declare const sha512_base64: RegExp;
declare const sha512_base64url: RegExp;
declare function _default(): {
	localeError: $ZodErrorMap;
};
declare function _default$1(): {
	localeError: $ZodErrorMap;
};
declare function _default$2(): {
	localeError: $ZodErrorMap;
};
declare function _default$3(): {
	localeError: $ZodErrorMap;
};
declare function _default$4(): {
	localeError: $ZodErrorMap;
};
declare function _default$5(): {
	localeError: $ZodErrorMap;
};
declare function _default$6(): {
	localeError: $ZodErrorMap;
};
declare function _default$7(): {
	localeError: $ZodErrorMap;
};
declare function _default$8(): {
	localeError: $ZodErrorMap;
};
declare function _default$9(): {
	localeError: $ZodErrorMap;
};
declare function _default$10(): {
	localeError: $ZodErrorMap;
};
declare function _default$11(): {
	localeError: $ZodErrorMap;
};
declare function _default$12(): {
	localeError: $ZodErrorMap;
};
declare function _default$13(): {
	localeError: $ZodErrorMap;
};
declare function _default$14(): {
	localeError: $ZodErrorMap;
};
declare function _default$15(): {
	localeError: $ZodErrorMap;
};
declare function _default$16(): {
	localeError: $ZodErrorMap;
};
declare function _default$17(): {
	localeError: $ZodErrorMap;
};
declare function _default$18(): {
	localeError: $ZodErrorMap;
};
declare function _default$19(): {
	localeError: $ZodErrorMap;
};
declare function _default$20(): {
	localeError: $ZodErrorMap;
};
declare function _default$21(): {
	localeError: $ZodErrorMap;
};
declare function _default$22(): {
	localeError: $ZodErrorMap;
};
declare function _default$23(): {
	localeError: $ZodErrorMap;
};
declare function _default$24(): {
	localeError: $ZodErrorMap;
};
declare function _default$25(): {
	localeError: $ZodErrorMap;
};
declare function _default$26(): {
	localeError: $ZodErrorMap;
};
declare function _default$27(): {
	localeError: $ZodErrorMap;
};
declare function _default$28(): {
	localeError: $ZodErrorMap;
};
declare function _default$29(): {
	localeError: $ZodErrorMap;
};
declare function _default$30(): {
	localeError: $ZodErrorMap;
};
declare function _default$31(): {
	localeError: $ZodErrorMap;
};
declare function _default$32(): {
	localeError: $ZodErrorMap;
};
declare function _default$33(): {
	localeError: $ZodErrorMap;
};
declare function _default$34(): {
	localeError: $ZodErrorMap;
};
declare function _default$35(): {
	localeError: $ZodErrorMap;
};
declare function _default$36(): {
	localeError: $ZodErrorMap;
};
declare function _default$37(): {
	localeError: $ZodErrorMap;
};
declare function _default$38(): {
	localeError: $ZodErrorMap;
};
declare function _default$39(): {
	localeError: $ZodErrorMap;
};
declare function _default$40(): {
	localeError: $ZodErrorMap;
};
declare function _default$41(): {
	localeError: $ZodErrorMap;
};
declare function _default$42(): {
	localeError: $ZodErrorMap;
};
declare function _default$43(): {
	localeError: $ZodErrorMap;
};
declare function _default$44(): {
	localeError: $ZodErrorMap;
};
declare function _default$45(): {
	localeError: $ZodErrorMap;
};
declare function _default$46(): {
	localeError: $ZodErrorMap;
};
declare function _default$47(): {
	localeError: $ZodErrorMap;
};
declare function _default$48(): {
	localeError: $ZodErrorMap;
};
declare function _default$49(): {
	localeError: $ZodErrorMap;
};
declare function _default$50(): {
	localeError: $ZodErrorMap;
};
declare function _default$51(): {
	localeError: $ZodErrorMap;
};
declare function _default$52(): {
	localeError: $ZodErrorMap;
};
declare function _default$53(): {
	localeError: $ZodErrorMap;
};
declare function _default$54(): {
	localeError: $ZodErrorMap;
};
declare function _default$55(): {
	localeError: $ZodErrorMap;
};
declare function _default$56(): {
	localeError: $ZodErrorMap;
};
declare function _default$57(): {
	localeError: $ZodErrorMap;
};
declare function _default$58(): {
	localeError: $ZodErrorMap;
};
declare function _default$59(): {
	localeError: $ZodErrorMap;
};
declare function _default$60(): {
	localeError: $ZodErrorMap;
};
declare function _default$61(): {
	localeError: $ZodErrorMap;
};
export type ModeWriter = (doc: Doc, modes: {
	execution: "sync" | "async";
}) => void;
declare class Doc {
	args: string[];
	/** Bindings the compiled function closes over, by name. */
	closed: Record<string, unknown>;
	content: string[];
	indent: number;
	constructor(args?: string[], closed?: Record<string, unknown>);
	indented(fn: (doc: Doc) => void): void;
	write(fn: ModeWriter): void;
	write(line: string): void;
	compile(): any;
}
declare const INVALID: unique symbol;
export type INVALID = typeof INVALID;
export interface CompileFnOptions {
	debug?: boolean | undefined;
	/** Emit a validator instead of a parser: skip building the output value where nothing reads it. */
	assertOnly?: boolean | undefined;
}
export type CompiledFn<T> = ((input: unknown) => T | INVALID) & {
	code?: string | undefined;
};
declare class ZodCompileAsyncError extends Error {
	constructor(message?: string);
}
declare class ZodCompileUnsupportedError extends Error {
	/** Whether a container may absorb this refusal by running the child through the runtime (see `compileChild`). False when running only that node on the runtime is not equivalent to running the whole parse there — a runtime island gets no parse context, so a node that *consumes* issues rather than propagating them would finalize them against the wrong error map and still succeed. */
	readonly islandable: boolean;
	constructor(feature: string, islandable?: boolean);
}
export interface CompileOptions {
	/** Throw the refusal instead of returning the schema uncompiled. */
	strict?: boolean | undefined;
}
declare function compile<T extends SomeType>(schema: T, options?: CompileOptions): T;
declare function compileFn<T extends SomeType>(schema: T, options?: CompileFnOptions): CompiledFn<output<T>>;
export type Params<T extends schemas.$ZodType | $ZodCheck, IssueTypes extends $ZodIssueBase, OmitKeys extends keyof T["_zod"]["def"] = never> = util.Flatten<Partial<util.EmptyToNever<Omit<T["_zod"]["def"], OmitKeys> & ([
	IssueTypes
] extends [
	never
] ? {} : {
	error?: string | $ZodErrorMap<IssueTypes> | undefined;
	/** @deprecated This parameter is deprecated. Use `error` instead. */
	message?: string | undefined;
})>>>;
export type TypeParams<T extends schemas.$ZodType = schemas.$ZodType & {
	_isst: never;
}, AlsoOmit extends Exclude<keyof T["_zod"]["def"], "type" | "checks" | "error"> = never> = Params<T, NonNullable<T["_zod"]["isst"]>, "type" | "checks" | "error" | AlsoOmit>;
export type CheckParams<T extends $ZodCheck = $ZodCheck, // & { _issc: never },
AlsoOmit extends Exclude<keyof T["_zod"]["def"], "check" | "error"> = never> = Params<T, NonNullable<T["_zod"]["issc"]>, "check" | "error" | AlsoOmit>;
export type StringFormatParams<T extends schemas.$ZodStringFormat = schemas.$ZodStringFormat, AlsoOmit extends Exclude<keyof T["_zod"]["def"], "type" | "coerce" | "checks" | "error" | "check" | "format"> = never> = Params<T, NonNullable<T["_zod"]["isst"] | T["_zod"]["issc"]>, "type" | "coerce" | "checks" | "error" | "check" | "format" | AlsoOmit>;
export type CheckStringFormatParams<T extends schemas.$ZodStringFormat = schemas.$ZodStringFormat, AlsoOmit extends Exclude<keyof T["_zod"]["def"], "type" | "coerce" | "checks" | "error" | "check" | "format"> = never> = Params<T, NonNullable<T["_zod"]["issc"]>, "type" | "coerce" | "checks" | "error" | "check" | "format" | AlsoOmit>;
export type CheckTypeParams<T extends schemas.$ZodType & $ZodCheck = schemas.$ZodType & $ZodCheck, AlsoOmit extends Exclude<keyof T["_zod"]["def"], "type" | "checks" | "error" | "check"> = never> = Params<T, NonNullable<T["_zod"]["isst"] | T["_zod"]["issc"]>, "type" | "checks" | "error" | "check" | AlsoOmit>;
export type $ZodStringParams = TypeParams<schemas.$ZodString<string>, "coerce">;
declare function _string<T extends schemas.$ZodString>(Class: util.SchemaClass<T>, params?: string | $ZodStringParams): T;
declare function _coercedString<T extends schemas.$ZodString>(Class: util.SchemaClass<T>, params?: string | $ZodStringParams): T;
export type $ZodStringFormatParams = CheckTypeParams<schemas.$ZodStringFormat, "format" | "coerce" | "when" | "pattern">;
export type $ZodCheckStringFormatParams = CheckParams<$ZodCheckStringFormat, "format">;
export type $ZodEmailParams = StringFormatParams<schemas.$ZodEmail, "when">;
export type $ZodCheckEmailParams = CheckStringFormatParams<schemas.$ZodEmail, "when">;
declare function _email<T extends schemas.$ZodEmail>(Class: util.SchemaClass<T>, params?: string | $ZodEmailParams | $ZodCheckEmailParams): T;
export type $ZodGUIDParams = StringFormatParams<schemas.$ZodGUID, "pattern" | "when">;
export type $ZodCheckGUIDParams = CheckStringFormatParams<schemas.$ZodGUID, "pattern" | "when">;
declare function _guid<T extends schemas.$ZodGUID>(Class: util.SchemaClass<T>, params?: string | $ZodGUIDParams | $ZodCheckGUIDParams): T;
export type $ZodUUIDParams = StringFormatParams<schemas.$ZodUUID, "pattern" | "when">;
export type $ZodCheckUUIDParams = CheckStringFormatParams<schemas.$ZodUUID, "pattern" | "when">;
declare function _uuid<T extends schemas.$ZodUUID>(Class: util.SchemaClass<T>, params?: string | $ZodUUIDParams | $ZodCheckUUIDParams): T;
export type $ZodUUIDv4Params = StringFormatParams<schemas.$ZodUUID, "pattern" | "when">;
export type $ZodCheckUUIDv4Params = CheckStringFormatParams<schemas.$ZodUUID, "pattern" | "when">;
declare function _uuidv4<T extends schemas.$ZodUUID>(Class: util.SchemaClass<T>, params?: string | $ZodUUIDv4Params | $ZodCheckUUIDv4Params): T;
export type $ZodUUIDv6Params = StringFormatParams<schemas.$ZodUUID, "pattern" | "when">;
export type $ZodCheckUUIDv6Params = CheckStringFormatParams<schemas.$ZodUUID, "pattern" | "when">;
declare function _uuidv6<T extends schemas.$ZodUUID>(Class: util.SchemaClass<T>, params?: string | $ZodUUIDv6Params | $ZodCheckUUIDv6Params): T;
export type $ZodUUIDv7Params = StringFormatParams<schemas.$ZodUUID, "pattern" | "when">;
export type $ZodCheckUUIDv7Params = CheckStringFormatParams<schemas.$ZodUUID, "pattern" | "when">;
declare function _uuidv7<T extends schemas.$ZodUUID>(Class: util.SchemaClass<T>, params?: string | $ZodUUIDv7Params | $ZodCheckUUIDv7Params): T;
export type $ZodURLParams = StringFormatParams<schemas.$ZodURL, "when">;
export type $ZodCheckURLParams = CheckStringFormatParams<schemas.$ZodURL, "when">;
declare function _url<T extends schemas.$ZodURL>(Class: util.SchemaClass<T>, params?: string | $ZodURLParams | $ZodCheckURLParams): T;
export type $ZodEmojiParams = StringFormatParams<schemas.$ZodEmoji, "when">;
export type $ZodCheckEmojiParams = CheckStringFormatParams<schemas.$ZodEmoji, "when">;
declare function _emoji<T extends schemas.$ZodEmoji>(Class: util.SchemaClass<T>, params?: string | $ZodEmojiParams | $ZodCheckEmojiParams): T;
export type $ZodNanoIDParams = StringFormatParams<schemas.$ZodNanoID, "when" | "pattern">;
export type $ZodCheckNanoIDParams = CheckStringFormatParams<schemas.$ZodNanoID, "when" | "pattern">;
declare function _nanoid<T extends schemas.$ZodNanoID>(Class: util.SchemaClass<T>, params?: string | $ZodNanoIDParams | $ZodCheckNanoIDParams): T;
/**
 * @deprecated CUID v1 is deprecated by its authors due to information leakage
 * (timestamps embedded in the id). Use {@link _cuid2} instead.
 * See https://github.com/paralleldrive/cuid.
 */
export type $ZodCUIDParams = StringFormatParams<schemas.$ZodCUID, "when">;
/**
 * @deprecated CUID v1 is deprecated by its authors due to information leakage
 * (timestamps embedded in the id). Use {@link _cuid2} instead.
 * See https://github.com/paralleldrive/cuid.
 */
export type $ZodCheckCUIDParams = CheckStringFormatParams<schemas.$ZodCUID, "when">;
declare function _cuid<T extends schemas.$ZodCUID>(Class: util.SchemaClass<T>, params?: string | $ZodCUIDParams | $ZodCheckCUIDParams): T;
export type $ZodCUID2Params = StringFormatParams<schemas.$ZodCUID2, "when">;
export type $ZodCheckCUID2Params = CheckStringFormatParams<schemas.$ZodCUID2, "when">;
declare function _cuid2<T extends schemas.$ZodCUID2>(Class: util.SchemaClass<T>, params?: string | $ZodCUID2Params | $ZodCheckCUID2Params): T;
export type $ZodULIDParams = StringFormatParams<schemas.$ZodULID, "when">;
export type $ZodCheckULIDParams = CheckStringFormatParams<schemas.$ZodULID, "when">;
declare function _ulid<T extends schemas.$ZodULID>(Class: util.SchemaClass<T>, params?: string | $ZodULIDParams | $ZodCheckULIDParams): T;
export type $ZodXIDParams = StringFormatParams<schemas.$ZodXID, "when">;
export type $ZodCheckXIDParams = CheckStringFormatParams<schemas.$ZodXID, "when">;
declare function _xid<T extends schemas.$ZodXID>(Class: util.SchemaClass<T>, params?: string | $ZodXIDParams | $ZodCheckXIDParams): T;
export type $ZodKSUIDParams = StringFormatParams<schemas.$ZodKSUID, "when">;
export type $ZodCheckKSUIDParams = CheckStringFormatParams<schemas.$ZodKSUID, "when">;
declare function _ksuid<T extends schemas.$ZodKSUID>(Class: util.SchemaClass<T>, params?: string | $ZodKSUIDParams | $ZodCheckKSUIDParams): T;
export type $ZodIPv4Params = StringFormatParams<schemas.$ZodIPv4, "pattern" | "when" | "version">;
export type $ZodCheckIPv4Params = CheckStringFormatParams<schemas.$ZodIPv4, "pattern" | "when" | "version">;
declare function _ipv4<T extends schemas.$ZodIPv4>(Class: util.SchemaClass<T>, params?: string | $ZodIPv4Params | $ZodCheckIPv4Params): T;
export type $ZodIPv6Params = StringFormatParams<schemas.$ZodIPv6, "pattern" | "when" | "version">;
export type $ZodCheckIPv6Params = CheckStringFormatParams<schemas.$ZodIPv6, "pattern" | "when" | "version">;
declare function _ipv6<T extends schemas.$ZodIPv6>(Class: util.SchemaClass<T>, params?: string | $ZodIPv6Params | $ZodCheckIPv6Params): T;
export type $ZodMACParams = StringFormatParams<schemas.$ZodMAC, "pattern" | "when">;
export type $ZodCheckMACParams = CheckStringFormatParams<schemas.$ZodMAC, "pattern" | "when">;
declare function _mac<T extends schemas.$ZodMAC>(Class: util.SchemaClass<T>, params?: string | $ZodMACParams | $ZodCheckMACParams): T;
export type $ZodCIDRv4Params = StringFormatParams<schemas.$ZodCIDRv4, "pattern" | "when">;
export type $ZodCheckCIDRv4Params = CheckStringFormatParams<schemas.$ZodCIDRv4, "pattern" | "when">;
declare function _cidrv4<T extends schemas.$ZodCIDRv4>(Class: util.SchemaClass<T>, params?: string | $ZodCIDRv4Params | $ZodCheckCIDRv4Params): T;
export type $ZodCIDRv6Params = StringFormatParams<schemas.$ZodCIDRv6, "pattern" | "when">;
export type $ZodCheckCIDRv6Params = CheckStringFormatParams<schemas.$ZodCIDRv6, "pattern" | "when">;
declare function _cidrv6<T extends schemas.$ZodCIDRv6>(Class: util.SchemaClass<T>, params?: string | $ZodCIDRv6Params | $ZodCheckCIDRv6Params): T;
export type $ZodBase64Params = StringFormatParams<schemas.$ZodBase64, "pattern" | "when">;
export type $ZodCheckBase64Params = CheckStringFormatParams<schemas.$ZodBase64, "pattern" | "when">;
declare function _base64<T extends schemas.$ZodBase64>(Class: util.SchemaClass<T>, params?: string | $ZodBase64Params | $ZodCheckBase64Params): T;
export type $ZodBase64URLParams = StringFormatParams<schemas.$ZodBase64URL, "pattern" | "when">;
export type $ZodCheckBase64URLParams = CheckStringFormatParams<schemas.$ZodBase64URL, "pattern" | "when">;
declare function _base64url<T extends schemas.$ZodBase64URL>(Class: util.SchemaClass<T>, params?: string | $ZodBase64URLParams | $ZodCheckBase64URLParams): T;
export type $ZodE164Params = StringFormatParams<schemas.$ZodE164, "when">;
export type $ZodCheckE164Params = CheckStringFormatParams<schemas.$ZodE164, "when">;
declare function _e164<T extends schemas.$ZodE164>(Class: util.SchemaClass<T>, params?: string | $ZodE164Params | $ZodCheckE164Params): T;
export type $ZodCreditCardParams = StringFormatParams<schemas.$ZodCreditCard, "pattern" | "when">;
export type $ZodCheckCreditCardParams = CheckStringFormatParams<schemas.$ZodCreditCard, "pattern" | "when">;
declare function _creditCard<T extends schemas.$ZodCreditCard>(Class: util.SchemaClass<T>, params?: string | $ZodCreditCardParams | $ZodCheckCreditCardParams): T;
export type $ZodJWTParams = StringFormatParams<schemas.$ZodJWT, "pattern" | "when">;
export type $ZodCheckJWTParams = CheckStringFormatParams<schemas.$ZodJWT, "pattern" | "when">;
declare function _jwt<T extends schemas.$ZodJWT>(Class: util.SchemaClass<T>, params?: string | $ZodJWTParams | $ZodCheckJWTParams): T;
declare const TimePrecision: {
	readonly Any: null;
	readonly Minute: -1;
	readonly Second: 0;
	readonly Millisecond: 3;
	readonly Microsecond: 6;
};
export type $ZodISODateTimeParams = StringFormatParams<schemas.$ZodISODateTime, "pattern" | "when">;
export type $ZodCheckISODateTimeParams = CheckStringFormatParams<schemas.$ZodISODateTime, "pattern" | "when">;
declare function _isoDateTime<T extends schemas.$ZodISODateTime>(Class: util.SchemaClass<T>, params?: string | $ZodISODateTimeParams | $ZodCheckISODateTimeParams): T;
export type $ZodISODateParams = StringFormatParams<schemas.$ZodISODate, "pattern" | "when">;
export type $ZodCheckISODateParams = CheckStringFormatParams<schemas.$ZodISODate, "pattern" | "when">;
declare function _isoDate<T extends schemas.$ZodISODate>(Class: util.SchemaClass<T>, params?: string | $ZodISODateParams | $ZodCheckISODateParams): T;
export type $ZodISOTimeParams = StringFormatParams<schemas.$ZodISOTime, "pattern" | "when">;
export type $ZodCheckISOTimeParams = CheckStringFormatParams<schemas.$ZodISOTime, "pattern" | "when">;
declare function _isoTime<T extends schemas.$ZodISOTime>(Class: util.SchemaClass<T>, params?: string | $ZodISOTimeParams | $ZodCheckISOTimeParams): T;
export type $ZodISODurationParams = StringFormatParams<schemas.$ZodISODuration, "when">;
export type $ZodCheckISODurationParams = CheckStringFormatParams<schemas.$ZodISODuration, "when">;
declare function _isoDuration<T extends schemas.$ZodISODuration>(Class: util.SchemaClass<T>, params?: string | $ZodISODurationParams | $ZodCheckISODurationParams): T;
export type $ZodNumberParams = TypeParams<schemas.$ZodNumber<number>, "coerce">;
export type $ZodNumberFormatParams = CheckTypeParams<schemas.$ZodNumberFormat, "format" | "coerce">;
export type $ZodCheckNumberFormatParams = CheckParams<$ZodCheckNumberFormat, "format" | "when">;
declare function _number<T extends schemas.$ZodNumber>(Class: util.SchemaClass<T>, params?: string | $ZodNumberParams): T;
declare function _coercedNumber<T extends schemas.$ZodNumber>(Class: util.SchemaClass<T>, params?: string | $ZodNumberParams): T;
declare function _int<T extends schemas.$ZodNumberFormat>(Class: util.SchemaClass<T>, params?: string | $ZodCheckNumberFormatParams): T;
declare function _float32<T extends schemas.$ZodNumberFormat>(Class: util.SchemaClass<T>, params?: string | $ZodCheckNumberFormatParams): T;
declare function _float64<T extends schemas.$ZodNumberFormat>(Class: util.SchemaClass<T>, params?: string | $ZodCheckNumberFormatParams): T;
declare function _int32<T extends schemas.$ZodNumberFormat>(Class: util.SchemaClass<T>, params?: string | $ZodCheckNumberFormatParams): T;
declare function _uint32<T extends schemas.$ZodNumberFormat>(Class: util.SchemaClass<T>, params?: string | $ZodCheckNumberFormatParams): T;
export type $ZodBooleanParams = TypeParams<schemas.$ZodBoolean<boolean>, "coerce">;
declare function _boolean<T extends schemas.$ZodBoolean>(Class: util.SchemaClass<T>, params?: string | $ZodBooleanParams): T;
declare function _coercedBoolean<T extends schemas.$ZodBoolean>(Class: util.SchemaClass<T>, params?: string | $ZodBooleanParams): T;
export type $ZodBigIntParams = TypeParams<schemas.$ZodBigInt<bigint>>;
export type $ZodBigIntFormatParams = CheckTypeParams<schemas.$ZodBigIntFormat, "format" | "coerce">;
export type $ZodCheckBigIntFormatParams = CheckParams<$ZodCheckBigIntFormat, "format" | "when">;
declare function _bigint<T extends schemas.$ZodBigInt>(Class: util.SchemaClass<T>, params?: string | $ZodBigIntParams): T;
declare function _coercedBigint<T extends schemas.$ZodBigInt>(Class: util.SchemaClass<T>, params?: string | $ZodBigIntParams): T;
declare function _int64<T extends schemas.$ZodBigIntFormat>(Class: util.SchemaClass<T>, params?: string | $ZodBigIntFormatParams): T;
declare function _uint64<T extends schemas.$ZodBigIntFormat>(Class: util.SchemaClass<T>, params?: string | $ZodBigIntFormatParams): T;
export type $ZodSymbolParams = TypeParams<schemas.$ZodSymbol>;
declare function _symbol<T extends schemas.$ZodSymbol>(Class: util.SchemaClass<T>, params?: string | $ZodSymbolParams): T;
export type $ZodUndefinedParams = TypeParams<schemas.$ZodUndefined>;
declare function _undefined$1<T extends schemas.$ZodUndefined>(Class: util.SchemaClass<T>, params?: string | $ZodUndefinedParams): T;
export type $ZodNullParams = TypeParams<schemas.$ZodNull>;
declare function _null$1<T extends schemas.$ZodNull>(Class: util.SchemaClass<T>, params?: string | $ZodNullParams): T;
export type $ZodAnyParams = TypeParams<schemas.$ZodAny>;
declare function _any<T extends schemas.$ZodAny>(Class: util.SchemaClass<T>): T;
export type $ZodUnknownParams = TypeParams<schemas.$ZodUnknown>;
declare function _unknown<T extends schemas.$ZodUnknown>(Class: util.SchemaClass<T>): T;
export type $ZodNeverParams = TypeParams<schemas.$ZodNever>;
declare function _never<T extends schemas.$ZodNever>(Class: util.SchemaClass<T>, params?: string | $ZodNeverParams): T;
export type $ZodVoidParams = TypeParams<schemas.$ZodVoid>;
declare function _void<T extends schemas.$ZodVoid>(Class: util.SchemaClass<T>, params?: string | $ZodVoidParams): T;
export type $ZodDateParams = TypeParams<schemas.$ZodDate, "coerce">;
declare function _date<T extends schemas.$ZodDate>(Class: util.SchemaClass<T>, params?: string | $ZodDateParams): T;
declare function _coercedDate<T extends schemas.$ZodDate>(Class: util.SchemaClass<T>, params?: string | $ZodDateParams): T;
export type $ZodNaNParams = TypeParams<schemas.$ZodNaN>;
declare function _nan<T extends schemas.$ZodNaN>(Class: util.SchemaClass<T>, params?: string | $ZodNaNParams): T;
export type $ZodCheckLessThanParams = CheckParams<$ZodCheckLessThan, "inclusive" | "value" | "when">;
declare function _lt(value: util.Numeric, params?: string | $ZodCheckLessThanParams): $ZodCheckLessThan<util.Numeric>;
declare function _lte(value: util.Numeric, params?: string | $ZodCheckLessThanParams): $ZodCheckLessThan<util.Numeric>;
export type $ZodCheckGreaterThanParams = CheckParams<$ZodCheckGreaterThan, "inclusive" | "value" | "when">;
declare function _gt(value: util.Numeric, params?: string | $ZodCheckGreaterThanParams): $ZodCheckGreaterThan;
declare function _gte(value: util.Numeric, params?: string | $ZodCheckGreaterThanParams): $ZodCheckGreaterThan;
declare function _positive(params?: string | $ZodCheckGreaterThanParams): $ZodCheckGreaterThan;
declare function _negative(params?: string | $ZodCheckLessThanParams): $ZodCheckLessThan;
declare function _nonpositive(params?: string | $ZodCheckLessThanParams): $ZodCheckLessThan;
declare function _nonnegative(params?: string | $ZodCheckGreaterThanParams): $ZodCheckGreaterThan;
export type $ZodCheckMultipleOfParams = CheckParams<$ZodCheckMultipleOf, "value" | "when">;
declare function _multipleOf(value: number | bigint, params?: string | $ZodCheckMultipleOfParams): $ZodCheckMultipleOf;
export type $ZodCheckMaxSizeParams = CheckParams<$ZodCheckMaxSize, "maximum" | "when">;
declare function _maxSize(maximum: number, params?: string | $ZodCheckMaxSizeParams): $ZodCheckMaxSize<util.HasSize>;
export type $ZodCheckMinSizeParams = CheckParams<$ZodCheckMinSize, "minimum" | "when">;
declare function _minSize(minimum: number, params?: string | $ZodCheckMinSizeParams): $ZodCheckMinSize<util.HasSize>;
export type $ZodCheckSizeEqualsParams = CheckParams<$ZodCheckSizeEquals, "size" | "when">;
declare function _size(size: number, params?: string | $ZodCheckSizeEqualsParams): $ZodCheckSizeEquals<util.HasSize>;
export type $ZodCheckMaxLengthParams = CheckParams<$ZodCheckMaxLength, "maximum" | "when">;
declare function _maxLength(maximum: number, params?: string | $ZodCheckMaxLengthParams): $ZodCheckMaxLength<util.HasLength>;
export type $ZodCheckMinLengthParams = CheckParams<$ZodCheckMinLength, "minimum" | "when">;
declare function _minLength(minimum: number, params?: string | $ZodCheckMinLengthParams): $ZodCheckMinLength<util.HasLength>;
export type $ZodCheckLengthEqualsParams = CheckParams<$ZodCheckLengthEquals, "length" | "when">;
declare function _length(length: number, params?: string | $ZodCheckLengthEqualsParams): $ZodCheckLengthEquals<util.HasLength>;
export type $ZodCheckRegexParams = CheckParams<$ZodCheckRegex, "format" | "pattern" | "when">;
declare function _regex(pattern: RegExp, params?: string | $ZodCheckRegexParams): $ZodCheckRegex;
export type $ZodCheckLowerCaseParams = CheckParams<$ZodCheckLowerCase, "format" | "when">;
declare function _lowercase(params?: string | $ZodCheckLowerCaseParams): $ZodCheckLowerCase;
export type $ZodCheckUpperCaseParams = CheckParams<$ZodCheckUpperCase, "format" | "when">;
declare function _uppercase(params?: string | $ZodCheckUpperCaseParams): $ZodCheckUpperCase;
export type $ZodCheckIncludesParams = CheckParams<$ZodCheckIncludes, "includes" | "format" | "when" | "pattern">;
declare function _includes(includes: string, params?: string | $ZodCheckIncludesParams): $ZodCheckIncludes;
export type $ZodCheckStartsWithParams = CheckParams<$ZodCheckStartsWith, "prefix" | "format" | "when" | "pattern">;
declare function _startsWith(prefix: string, params?: string | $ZodCheckStartsWithParams): $ZodCheckStartsWith;
export type $ZodCheckEndsWithParams = CheckParams<$ZodCheckEndsWith, "suffix" | "format" | "pattern" | "when">;
declare function _endsWith(suffix: string, params?: string | $ZodCheckEndsWithParams): $ZodCheckEndsWith;
export type $ZodCheckPropertyParams = CheckParams<$ZodCheckProperty, "property" | "schema" | "when">;
declare function _property<K extends string, T extends schemas.$ZodType>(property: K, schema: T, params?: string | $ZodCheckPropertyParams): $ZodCheckProperty<{
	[k in K]: output<T>;
}>;
declare function _properties<Shape extends schemas.$ZodShape>(shape: Shape): $ZodCheckProperty<{
	-readonly [k in keyof Shape]: output<Shape[k]>;
}>[];
export type $ZodCheckMimeTypeParams = CheckParams<$ZodCheckMimeType, "mime" | "when">;
declare function _mime(types: util.MimeTypes[], params?: string | $ZodCheckMimeTypeParams): $ZodCheckMimeType;
declare function _overwrite<T>(tx: (input: T) => T): $ZodCheckOverwrite<T>;
declare function _normalize(form?: "NFC" | "NFD" | "NFKC" | "NFKD" | (string & {})): $ZodCheckOverwrite<string>;
declare function _trim(): $ZodCheckOverwrite<string>;
declare function _toLowerCase(): $ZodCheckOverwrite<string>;
declare function _toUpperCase(): $ZodCheckOverwrite<string>;
declare function _slugify(): $ZodCheckOverwrite<string>;
export type $ZodArrayParams = TypeParams<schemas.$ZodArray, "element">;
declare function _array<T extends schemas.$ZodType>(Class: util.SchemaClass<schemas.$ZodArray>, element: T, params?: string | $ZodArrayParams): schemas.$ZodArray<T>;
export type $ZodObjectParams = TypeParams<schemas.$ZodObject, "shape" | "catchall">;
export type $ZodUnionParams = TypeParams<schemas.$ZodUnion, "options">;
declare function _union<const T extends readonly schemas.$ZodObject[]>(Class: util.SchemaClass<schemas.$ZodUnion>, options: T, params?: string | $ZodUnionParams): schemas.$ZodUnion<T>;
export type $ZodXorParams = TypeParams<schemas.$ZodXor, "options">;
declare function _xor<const T extends readonly schemas.$ZodObject[]>(Class: util.SchemaClass<schemas.$ZodXor>, options: T, params?: string | $ZodXorParams): schemas.$ZodXor<T>;
export interface $ZodTypeDiscriminableInternals<_Disc extends string = string> extends schemas._$ZodTypeInternals {
	propValues: util.PropValues;
}
export interface $ZodTypeDiscriminable<_Disc extends string = string> extends schemas.SomeType {
	_zod: $ZodTypeDiscriminableInternals;
}
export type $ZodDiscriminatedUnionParams = TypeParams<schemas.$ZodDiscriminatedUnion, "options" | "discriminator">;
declare function _discriminatedUnion<Types extends [
	$ZodTypeDiscriminable,
	...$ZodTypeDiscriminable[]
], Disc extends string>(Class: util.SchemaClass<schemas.$ZodDiscriminatedUnion>, discriminator: Disc, options: Types, params?: string | $ZodDiscriminatedUnionParams): schemas.$ZodDiscriminatedUnion<Types, Disc>;
export type $ZodIntersectionParams = TypeParams<schemas.$ZodIntersection, "left" | "right">;
declare function _intersection<T extends schemas.$ZodObject, U extends schemas.$ZodObject>(Class: util.SchemaClass<schemas.$ZodIntersection>, left: T, right: U): schemas.$ZodIntersection<T, U>;
export type $ZodTupleParams = TypeParams<schemas.$ZodTuple, "items" | "rest">;
declare function _tuple<T extends readonly [
	schemas.$ZodType,
	...schemas.$ZodType[]
]>(Class: util.SchemaClass<schemas.$ZodTuple>, items: T, params?: string | $ZodTupleParams): schemas.$ZodTuple<T, null>;
declare function _tuple<T extends readonly [
	schemas.$ZodType,
	...schemas.$ZodType[]
], Rest extends schemas.$ZodType>(Class: util.SchemaClass<schemas.$ZodTuple>, items: T, rest: Rest, params?: string | $ZodTupleParams): schemas.$ZodTuple<T, Rest>;
export type $ZodRecordParams = TypeParams<schemas.$ZodRecord, "keyType" | "valueType" | "partial">;
declare function _record<Key extends schemas.$ZodRecordKey, Value extends schemas.$ZodObject>(Class: util.SchemaClass<schemas.$ZodRecord>, keyType: Key, valueType: Value, params?: string | $ZodRecordParams): schemas.$ZodRecord<Key, Value>;
export type $ZodMapParams = TypeParams<schemas.$ZodMap, "keyType" | "valueType">;
declare function _map<Key extends schemas.$ZodObject, Value extends schemas.$ZodObject>(Class: util.SchemaClass<schemas.$ZodMap>, keyType: Key, valueType: Value, params?: string | $ZodMapParams): schemas.$ZodMap<Key, Value>;
export type $ZodSetParams = TypeParams<schemas.$ZodSet, "valueType">;
declare function _set<Value extends schemas.$ZodObject>(Class: util.SchemaClass<schemas.$ZodSet>, valueType: Value, params?: string | $ZodSetParams): schemas.$ZodSet<Value>;
export type $ZodEnumParams = TypeParams<schemas.$ZodEnum, "entries">;
declare function _enum<const T extends string[]>(Class: util.SchemaClass<schemas.$ZodEnum>, values: T, params?: string | $ZodEnumParams): schemas.$ZodEnum<util.ToEnum<T[number]>>;
declare function _enum<T extends util.EnumLike>(Class: util.SchemaClass<schemas.$ZodEnum>, entries: T, params?: string | $ZodEnumParams): schemas.$ZodEnum<T>;
declare function _nativeEnum<T extends util.EnumLike>(Class: util.SchemaClass<schemas.$ZodEnum>, entries: T, params?: string | $ZodEnumParams): schemas.$ZodEnum<T>;
export type $ZodLiteralParams = TypeParams<schemas.$ZodLiteral, "values">;
declare function _literal<const T extends Array<util.Literal>>(Class: util.SchemaClass<schemas.$ZodLiteral>, value: T, params?: string | $ZodLiteralParams): schemas.$ZodLiteral<T[number]>;
declare function _literal<const T extends util.Literal>(Class: util.SchemaClass<schemas.$ZodLiteral>, value: T, params?: string | $ZodLiteralParams): schemas.$ZodLiteral<T>;
export type $ZodFileParams = TypeParams<schemas.$ZodFile>;
declare function _file(Class: util.SchemaClass<schemas.$ZodFile>, params?: string | $ZodFileParams): schemas.$ZodFile;
export type $ZodTransformParams = TypeParams<schemas.$ZodTransform, "transform">;
declare function _transform<I = unknown, O = I>(Class: util.SchemaClass<schemas.$ZodTransform>, fn: (input: I, ctx?: schemas.ParsePayload) => O): schemas.$ZodTransform<Awaited<O>, I>;
export type $ZodOptionalParams = TypeParams<schemas.$ZodOptional, "innerType">;
declare function _optional<T extends schemas.$ZodObject>(Class: util.SchemaClass<schemas.$ZodOptional>, innerType: T): schemas.$ZodOptional<T>;
export type $ZodNullableParams = TypeParams<schemas.$ZodNullable, "innerType">;
declare function _nullable<T extends schemas.$ZodObject>(Class: util.SchemaClass<schemas.$ZodNullable>, innerType: T): schemas.$ZodNullable<T>;
export type $ZodDefaultParams = TypeParams<schemas.$ZodDefault, "innerType" | "defaultValue">;
declare function _default$62<T extends schemas.$ZodObject>(Class: util.SchemaClass<schemas.$ZodDefault>, innerType: T, defaultValue: util.NoUndefined<output<T>> | (() => util.NoUndefined<output<T>>)): schemas.$ZodDefault<T>;
export type $ZodNonOptionalParams = TypeParams<schemas.$ZodNonOptional, "innerType">;
declare function _nonoptional<T extends schemas.$ZodObject>(Class: util.SchemaClass<schemas.$ZodNonOptional>, innerType: T, params?: string | $ZodNonOptionalParams): schemas.$ZodNonOptional<T>;
export type $ZodSuccessParams = TypeParams<schemas.$ZodSuccess, "innerType">;
declare function _success<T extends schemas.$ZodObject>(Class: util.SchemaClass<schemas.$ZodSuccess>, innerType: T): schemas.$ZodSuccess<T>;
export type $ZodCatchParams = TypeParams<schemas.$ZodCatch, "innerType" | "catchValue">;
declare function _catch<T extends schemas.$ZodObject>(Class: util.SchemaClass<schemas.$ZodCatch>, innerType: T, catchValue: output<T> | ((ctx: schemas.$ZodCatchCtx) => output<T>)): schemas.$ZodCatch<T>;
export type $ZodPipeParams = TypeParams<schemas.$ZodPipe, "in" | "out">;
declare function _pipe<const A extends schemas.$ZodType, B extends schemas.$ZodType<unknown, output<A>> = schemas.$ZodType<unknown, output<A>>>(Class: util.SchemaClass<schemas.$ZodPipe>, in_: A, out: B | schemas.$ZodType<unknown, output<A>>): schemas.$ZodPipe<A, B>;
export type $ZodReadonlyParams = TypeParams<schemas.$ZodReadonly, "innerType">;
declare function _readonly<T extends schemas.$ZodObject>(Class: util.SchemaClass<schemas.$ZodReadonly>, innerType: T): schemas.$ZodReadonly<T>;
export type $ZodTemplateLiteralParams = TypeParams<schemas.$ZodTemplateLiteral, "parts">;
declare function _templateLiteral<const Parts extends schemas.$ZodTemplateLiteralPart[]>(Class: util.SchemaClass<schemas.$ZodTemplateLiteral>, parts: Parts, params?: string | $ZodTemplateLiteralParams): schemas.$ZodTemplateLiteral<schemas.$PartsToTemplateLiteral<Parts>>;
export type $ZodLazyParams = TypeParams<schemas.$ZodLazy, "getter">;
declare function _lazy<T extends schemas.$ZodType>(Class: util.SchemaClass<schemas.$ZodLazy>, getter: () => T): schemas.$ZodLazy<T>;
export type $ZodPromiseParams = TypeParams<schemas.$ZodPromise, "innerType">;
declare function _promise<T extends schemas.$ZodObject>(Class: util.SchemaClass<schemas.$ZodPromise>, innerType: T): schemas.$ZodPromise<T>;
export type $ZodCustomParams = CheckTypeParams<schemas.$ZodCustom, "fn">;
declare function _custom<O = unknown, I = O>(Class: util.SchemaClass<schemas.$ZodCustom>, fn: (data: O) => unknown, _params: string | $ZodCustomParams | undefined): schemas.$ZodCustom<O, I>;
declare function _refine<O = unknown, I = O>(Class: util.SchemaClass<schemas.$ZodCustom>, fn: (data: O) => unknown, _params: string | $ZodCustomParams | undefined): schemas.$ZodCustom<O, I>;
export type $ZodSuperRefineIssue<T extends $ZodIssueBase = $ZodIssue> = T extends any ? RawIssue$1<T> : never;
type RawIssue$1<T extends $ZodIssueBase> = T extends any ? util.Flatten<util.MakePartial<T, "message" | "path"> & {
	/** The schema or check that originated this issue. */
	readonly inst?: schemas.$ZodType | $ZodCheck;
	/** If `true`, Zod will execute subsequent checks/refinements instead of immediately aborting */
	readonly continue?: boolean | undefined;
} & Record<string, unknown>> : never;
export interface $RefinementCtx<T = unknown> extends schemas.ParsePayload<T> {
	addIssue(arg: string | $ZodSuperRefineIssue): void;
}
export interface $ZodSuperRefineParams {
	/** If provided, the refinement runs only when this returns `true`. By default, it is skipped if prior parsing produced aborting issues. */
	when?: ((payload: schemas.ParsePayload) => boolean) | undefined;
}
declare function _superRefine<T>(fn: (arg: T, payload: $RefinementCtx<T>) => void | Promise<void>, params?: $ZodSuperRefineParams): $ZodCheck<T>;
declare function _check<O = unknown>(fn: schemas.CheckFn<O>, params?: string | $ZodCustomParams): $ZodCheck<O>;
declare function describe<T>(description: string): $ZodCheck<T>;
declare function meta<T>(metadata: GlobalMeta): $ZodCheck<T>;
export interface $ZodStringBoolParams extends TypeParams {
	truthy?: string[];
	falsy?: string[];
	/**
	 * Options: `"sensitive"`, `"insensitive"`
	 *
	 * @default `"insensitive"`
	 */
	case?: "sensitive" | "insensitive" | undefined;
}
declare function _stringbool(Classes: {
	Codec?: typeof schemas.$ZodCodec;
	Boolean?: typeof schemas.$ZodBoolean;
	String?: typeof schemas.$ZodString;
}, _params?: string | $ZodStringBoolParams): schemas.$ZodCodec<schemas.$ZodString, schemas.$ZodBoolean>;
declare function _stringFormat<Format extends string>(Class: typeof schemas.$ZodCustomStringFormat, format: Format, fnOrRegex: ((arg: string) => util.MaybeAsync<unknown>) | RegExp, _params?: string | $ZodStringFormatParams): schemas.$ZodCustomStringFormat<Format>;
declare function toJSONSchema<T extends schemas.$ZodType>(schema: T, params?: ToJSONSchemaParams): ZodStandardJSONSchemaPayload<T>;
declare function toJSONSchema(registry: $ZodRegistry<{
	id?: string | undefined;
}>, params?: RegistryToJSONSchemaParams): {
	schemas: Record<string, ZodStandardJSONSchemaPayload<schemas.$ZodType>>;
};
/**
 * Parameters for the emit method of JSONSchemaGenerator.
 * @deprecated Use toJSONSchema function instead
 */
export type EmitParams = Pick<JSONSchemaGeneratorParams, "cycles" | "reused" | "external">;
/**
 * Parameters for JSONSchemaGenerator constructor.
 * @deprecated Use toJSONSchema function instead
 */
export type JSONSchemaGeneratorConstructorParams = Pick<JSONSchemaGeneratorParams, "metadata" | "target" | "unrepresentable" | "override" | "io">;
declare class JSONSchemaGenerator {
	private ctx;
	/** @deprecated Access via ctx instead */
	get metadataRegistry(): $ZodRegistry<Record<string, any>>;
	/** @deprecated Access via ctx instead */
	get target(): ({} & string) | "draft-2020-12" | "draft-07" | "openapi-3.0" | "draft-04";
	/** @deprecated Access via ctx instead */
	get unrepresentable(): ToJSONSchemaContext["unrepresentable"];
	/** @deprecated Access via ctx instead */
	get override(): (ctx: {
		zodSchema: schemas.$ZodType;
		jsonSchema: JSONSchema$1.BaseSchema;
		path: (string | number)[];
	}) => void;
	/** @deprecated Access via ctx instead */
	get io(): "input" | "output";
	/** @deprecated Access via ctx instead */
	get counter(): number;
	set counter(value: number);
	/** @deprecated Access via ctx instead */
	get seen(): Map<schemas.$ZodType, Seen>;
	constructor(params?: JSONSchemaGeneratorConstructorParams);
	/**
	 * Process a schema to prepare it for JSON Schema generation.
	 * This must be called before emit().
	 */
	process(schema: schemas.$ZodType, _params?: ProcessParams): JSONSchema$1.BaseSchema;
	/**
	 * Emit the final JSON Schema after processing.
	 * Must call process() first.
	 */
	emit(schema: schemas.$ZodType, _params?: EmitParams): JSONSchema$1.BaseSchema;
}
/** @deprecated Use `z.core.$ZodIssue` from `@zod/core` instead, especially if you are building a library on top of Zod. */
export type ZodIssue = core.$ZodIssue;
/** An Error-like class used to store Zod validation issues.  */
export interface ZodError<T = unknown> extends $ZodError<T> {
	/** @deprecated Use the `z.treeifyError(err)` function instead. */
	format(): core.$ZodFormattedError<T>;
	format<U>(mapper: (issue: core.$ZodIssue) => U): core.$ZodFormattedError<T, U>;
	/** @deprecated Use the `z.treeifyError(err)` function instead. */
	flatten(): core.$ZodFlattenedError<T>;
	flatten<U>(mapper: (issue: core.$ZodIssue) => U): core.$ZodFlattenedError<T, U>;
	/** @deprecated Push directly to `.issues` instead. */
	addIssue(issue: core.$ZodIssue): void;
	/** @deprecated Push directly to `.issues` instead. */
	addIssues(issues: core.$ZodIssue[]): void;
	/** @deprecated Check `err.issues.length === 0` instead. */
	isEmpty: boolean;
}
declare const ZodError: core.$constructor<ZodError>;
declare const ZodRealError: core.$constructor<ZodError>;
/** @deprecated Use `z.core.$ZodFlattenedError` instead. */
export type ZodFlattenedError<T, U = string> = core.$ZodFlattenedError<T, U>;
/** @deprecated Use `z.core.$ZodFormattedError` instead. */
export type ZodFormattedError<T, U = string> = core.$ZodFormattedError<T, U>;
/** @deprecated Use `z.core.$ZodErrorMap` instead. */
export type ZodErrorMap<T extends core.$ZodIssueBase = core.$ZodIssue> = core.$ZodErrorMap<T>;
/** @deprecated Use `z.core.$ZodRawIssue` instead. */
export type IssueData = core.$ZodRawIssue;
export type ZodSafeParseResult<T> = ZodSafeParseSuccess<T> | ZodSafeParseError<T>;
export type ZodSafeParseSuccess<T> = {
	success: true;
	data: T;
	error?: never;
};
export type ZodSafeParseError<T> = {
	success: false;
	data?: never;
	error: ZodError<T>;
};
declare const parse$1: <T extends core.$ZodType>(schema: T, value: unknown, _ctx?: core.ParseContext<core.$ZodIssue>, _params?: {
	callee?: core.util.AnyFunc;
	Err?: core.$ZodErrorClass;
}) => core.output<T>;
declare const parseAsync$1: <T extends core.$ZodType>(schema: T, value: unknown, _ctx?: core.ParseContext<core.$ZodIssue>, _params?: {
	callee?: core.util.AnyFunc;
	Err?: core.$ZodErrorClass;
}) => Promise<core.output<T>>;
declare const safeParse$1: <T extends core.$ZodType>(schema: T, value: unknown, _ctx?: core.ParseContext<core.$ZodIssue>) => ZodSafeParseResult<core.output<T>>;
declare const safeParseAsync$1: <T extends core.$ZodType>(schema: T, value: unknown, _ctx?: core.ParseContext<core.$ZodIssue>) => Promise<ZodSafeParseResult<core.output<T>>>;
declare const encode$1: <T extends core.$ZodType>(schema: T, value: core.output<T>, _ctx?: core.ParseContext<core.$ZodIssue>, _params?: {
	callee?: core.util.AnyFunc;
	Err?: core.$ZodErrorClass;
}) => core.input<T>;
declare const decode$1: <T extends core.$ZodType>(schema: T, value: core.input<T>, _ctx?: core.ParseContext<core.$ZodIssue>, _params?: {
	callee?: core.util.AnyFunc;
	Err?: core.$ZodErrorClass;
}) => core.output<T>;
declare const encodeAsync$1: <T extends core.$ZodType>(schema: T, value: core.output<T>, _ctx?: core.ParseContext<core.$ZodIssue>, _params?: {
	callee?: core.util.AnyFunc;
	Err?: core.$ZodErrorClass;
}) => Promise<core.input<T>>;
declare const decodeAsync$1: <T extends core.$ZodType>(schema: T, value: core.input<T>, _ctx?: core.ParseContext<core.$ZodIssue>, _params?: {
	callee?: core.util.AnyFunc;
	Err?: core.$ZodErrorClass;
}) => Promise<core.output<T>>;
declare const safeEncode$1: <T extends core.$ZodType>(schema: T, value: core.output<T>, _ctx?: core.ParseContext<core.$ZodIssue>) => ZodSafeParseResult<core.input<T>>;
declare const safeDecode$1: <T extends core.$ZodType>(schema: T, value: core.input<T>, _ctx?: core.ParseContext<core.$ZodIssue>) => ZodSafeParseResult<core.output<T>>;
declare const safeEncodeAsync$1: <T extends core.$ZodType>(schema: T, value: core.output<T>, _ctx?: core.ParseContext<core.$ZodIssue>) => Promise<ZodSafeParseResult<core.input<T>>>;
declare const safeDecodeAsync$1: <T extends core.$ZodType>(schema: T, value: core.input<T>, _ctx?: core.ParseContext<core.$ZodIssue>) => Promise<ZodSafeParseResult<core.output<T>>>;
type ZodStandardSchemaWithJSON$1<T> = StandardSchemaWithJSONProps<core.input<T>, core.output<T>>;
export interface ZodType<out Output = unknown, out Input = unknown, out Internals extends core.$ZodTypeInternals<Output, Input> = core.$ZodTypeInternals<Output, Input>> extends core.$ZodType<Output, Input, Internals> {
	def: Internals["def"];
	type: Internals["def"]["type"];
	/** @deprecated Use `.def` instead. */
	_def: Internals["def"];
	/** @deprecated Use `z.output<typeof schema>` instead. */
	_output: Internals["output"];
	/** @deprecated Use `z.input<typeof schema>` instead. */
	_input: Internals["input"];
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	/** Converts this schema to a JSON Schema representation. */
	toJSONSchema(params?: core.ToJSONSchemaParams): core.ZodStandardJSONSchemaPayload<this>;
	check(...checks: (core.CheckFn<core.output<this>> | core.$ZodCheck<core.output<this>>)[]): this;
	with(...checks: (core.CheckFn<core.output<this>> | core.$ZodCheck<core.output<this>>)[]): this;
	clone(def?: Internals["def"], params?: {
		parent: boolean;
	}): this;
	register<R extends core.$ZodRegistry>(registry: R, ...meta: this extends R["_schema"] ? undefined extends R["_meta"] ? [
		core.$replace<R["_meta"], this>?
	] : [
		core.$replace<R["_meta"], this>
	] : [
		"Incompatible schema"
	]): this;
	brand<T extends PropertyKey = PropertyKey, Dir extends "in" | "out" | "inout" = "out">(value?: T): PropertyKey extends T ? this : core.$ZodBranded<this, T, Dir>;
	parse(data: unknown, params?: core.ParseContext<core.$ZodIssue>): core.output<this>;
	safeParse(data: unknown, params?: core.ParseContext<core.$ZodIssue>): ZodSafeParseResult<core.output<this>>;
	parseAsync(data: unknown, params?: core.ParseContext<core.$ZodIssue>): Promise<core.output<this>>;
	safeParseAsync(data: unknown, params?: core.ParseContext<core.$ZodIssue>): Promise<ZodSafeParseResult<core.output<this>>>;
	spa: (data: unknown, params?: core.ParseContext<core.$ZodIssue>) => Promise<ZodSafeParseResult<core.output<this>>>;
	encode(data: core.output<this>, params?: core.ParseContext<core.$ZodIssue>): core.input<this>;
	decode(data: core.input<this>, params?: core.ParseContext<core.$ZodIssue>): core.output<this>;
	encodeAsync(data: core.output<this>, params?: core.ParseContext<core.$ZodIssue>): Promise<core.input<this>>;
	decodeAsync(data: core.input<this>, params?: core.ParseContext<core.$ZodIssue>): Promise<core.output<this>>;
	safeEncode(data: core.output<this>, params?: core.ParseContext<core.$ZodIssue>): ZodSafeParseResult<core.input<this>>;
	safeDecode(data: core.input<this>, params?: core.ParseContext<core.$ZodIssue>): ZodSafeParseResult<core.output<this>>;
	safeEncodeAsync(data: core.output<this>, params?: core.ParseContext<core.$ZodIssue>): Promise<ZodSafeParseResult<core.input<this>>>;
	safeDecodeAsync(data: core.input<this>, params?: core.ParseContext<core.$ZodIssue>): Promise<ZodSafeParseResult<core.output<this>>>;
	refine<Ch extends (arg: core.output<this>) => unknown | Promise<unknown>>(check: Ch, params?: string | core.$ZodCustomParams): Ch extends (arg: any) => arg is infer R ? this & ZodType<R, core.input<this>> : this;
	superRefine(refinement: (arg: core.output<this>, ctx: core.$RefinementCtx<core.output<this>>) => void | Promise<void>, params?: core.$ZodSuperRefineParams): this;
	overwrite(fn: (x: core.output<this>) => core.output<this>): this;
	optional(): ZodOptional<this>;
	exactOptional(): ZodExactOptional<this>;
	nonoptional(params?: string | core.$ZodNonOptionalParams): ZodNonOptional<this>;
	nullable(): ZodNullable<this>;
	nullish(): ZodOptional<ZodNullable<this>>;
	default(def: util.NoUndefined<core.output<this>>): ZodDefault<this>;
	default(def: () => util.NoUndefined<core.output<this>>): ZodDefault<this>;
	prefault(def: () => core.input<this>): ZodPrefault<this>;
	prefault(def: core.input<this>): ZodPrefault<this>;
	array(): ZodArray<this>;
	or<T extends core.SomeType>(option: T): ZodUnion<[
		this,
		T
	]>;
	and<T extends core.SomeType>(incoming: T): ZodIntersection<this, T>;
	transform<NewOut>(transform: (arg: core.output<this>, ctx: core.$RefinementCtx<core.output<this>>) => NewOut | Promise<NewOut>): ZodPipe<this, ZodTransform<Awaited<NewOut>, core.output<this>>>;
	catch(def: core.output<this>): ZodCatch<this>;
	catch(def: (ctx: core.$ZodCatchCtx) => core.output<this>): ZodCatch<this>;
	pipe<T extends core.$ZodType<any, core.output<this>>>(target: T | core.$ZodType<any, core.output<this>>): ZodPipe<this, T>;
	readonly(): ZodReadonly<this>;
	/** Returns a new instance that has been registered in `z.globalRegistry` with the specified description */
	describe(description: string): this;
	description?: string;
	/** Returns the metadata associated with this instance in `z.globalRegistry` */
	meta(): core.$replace<core.GlobalMeta, this> | undefined;
	/** Returns a new instance that has been registered in `z.globalRegistry` with the specified metadata */
	meta(data: core.$replace<core.GlobalMeta, this>): this;
	/** @deprecated Try safe-parsing `undefined` (this is what `isOptional` does internally):
	 *
	 * ```ts
	 * const schema = z.string().optional();
	 * const isOptional = schema.safeParse(undefined).success; // true
	 * ```
	 */
	isOptional(): boolean;
	/**
	 * @deprecated Try safe-parsing `null` (this is what `isNullable` does internally):
	 *
	 * ```ts
	 * const schema = z.string().nullable();
	 * const isNullable = schema.safeParse(null).success; // true
	 * ```
	 */
	isNullable(): boolean;
	apply<T, TArgs extends unknown[] = [
	]>(fn: (schema: this, ...args: TArgs) => T, ...args: TArgs): T;
}
export interface _ZodType<out Internals extends core.$ZodTypeInternals = core.$ZodTypeInternals> extends ZodType<any, any, Internals> {
}
declare const ZodType: core.$constructor<ZodType>;
export interface _ZodString<T extends core.$ZodStringInternals<unknown> = core.$ZodStringInternals<unknown>> extends _ZodType<T> {
	format: string | null;
	minLength: number | null;
	maxLength: number | null;
	regex(regex: RegExp, params?: string | core.$ZodCheckRegexParams): this;
	includes(value: string, params?: string | core.$ZodCheckIncludesParams): this;
	startsWith(value: string, params?: string | core.$ZodCheckStartsWithParams): this;
	endsWith(value: string, params?: string | core.$ZodCheckEndsWithParams): this;
	min(minLength: number, params?: string | core.$ZodCheckMinLengthParams): this;
	max(maxLength: number, params?: string | core.$ZodCheckMaxLengthParams): this;
	length(len: number, params?: string | core.$ZodCheckLengthEqualsParams): this;
	nonempty(params?: string | core.$ZodCheckMinLengthParams): this;
	lowercase(params?: string | core.$ZodCheckLowerCaseParams): this;
	uppercase(params?: string | core.$ZodCheckUpperCaseParams): this;
	trim(): this;
	normalize(form?: "NFC" | "NFD" | "NFKC" | "NFKD" | (string & {})): this;
	toLowerCase(): this;
	toUpperCase(): this;
	slugify(): this;
}
declare const _ZodString: core.$constructor<_ZodString>;
export interface ZodString extends _ZodString<core.$ZodStringInternals<string>> {
	/** @deprecated Use `z.email()` instead. */
	email(params?: string | core.$ZodCheckEmailParams): this;
	/** @deprecated Use `z.url()` instead. */
	url(params?: string | core.$ZodCheckURLParams): this;
	/** @deprecated Use `z.jwt()` instead. */
	jwt(params?: string | core.$ZodCheckJWTParams): this;
	/** @deprecated Use `z.emoji()` instead. */
	emoji(params?: string | core.$ZodCheckEmojiParams): this;
	/** @deprecated Use `z.guid()` instead. */
	guid(params?: string | core.$ZodCheckGUIDParams): this;
	/** @deprecated Use `z.uuid()` instead. */
	uuid(params?: string | core.$ZodCheckUUIDParams): this;
	/** @deprecated Use `z.uuid()` instead. */
	uuidv4(params?: string | core.$ZodCheckUUIDParams): this;
	/** @deprecated Use `z.uuid()` instead. */
	uuidv6(params?: string | core.$ZodCheckUUIDParams): this;
	/** @deprecated Use `z.uuid()` instead. */
	uuidv7(params?: string | core.$ZodCheckUUIDParams): this;
	/** @deprecated Use `z.nanoid()` instead. */
	nanoid(params?: string | core.$ZodCheckNanoIDParams): this;
	/** @deprecated Use `z.guid()` instead. */
	guid(params?: string | core.$ZodCheckGUIDParams): this;
	/**
	 * @deprecated CUID v1 is deprecated by its authors due to information leakage
	 * (timestamps embedded in the id). Use `z.cuid2()` instead.
	 * See https://github.com/paralleldrive/cuid.
	 */
	cuid(params?: string | core.$ZodCheckCUIDParams): this;
	/** @deprecated Use `z.cuid2()` instead. */
	cuid2(params?: string | core.$ZodCheckCUID2Params): this;
	/** @deprecated Use `z.ulid()` instead. */
	ulid(params?: string | core.$ZodCheckULIDParams): this;
	/** @deprecated Use `z.base64()` instead. */
	base64(params?: string | core.$ZodCheckBase64Params): this;
	/** @deprecated Use `z.base64url()` instead. */
	base64url(params?: string | core.$ZodCheckBase64URLParams): this;
	/** @deprecated Use `z.xid()` instead. */
	xid(params?: string | core.$ZodCheckXIDParams): this;
	/** @deprecated Use `z.ksuid()` instead. */
	ksuid(params?: string | core.$ZodCheckKSUIDParams): this;
	/** @deprecated Use `z.ipv4()` instead. */
	ipv4(params?: string | core.$ZodCheckIPv4Params): this;
	/** @deprecated Use `z.ipv6()` instead. */
	ipv6(params?: string | core.$ZodCheckIPv6Params): this;
	/** @deprecated Use `z.cidrv4()` instead. */
	cidrv4(params?: string | core.$ZodCheckCIDRv4Params): this;
	/** @deprecated Use `z.cidrv6()` instead. */
	cidrv6(params?: string | core.$ZodCheckCIDRv6Params): this;
	/** @deprecated Use `z.e164()` instead. */
	e164(params?: string | core.$ZodCheckE164Params): this;
	/** @deprecated Use `z.iso.datetime()` instead. */
	datetime(params?: string | core.$ZodCheckISODateTimeParams): this;
	/** @deprecated Use `z.iso.date()` instead. */
	date(params?: string | core.$ZodCheckISODateParams): this;
	/** @deprecated Use `z.iso.time()` instead. */
	time(params?: string | core.$ZodCheckISOTimeParams): this;
	/** @deprecated Use `z.iso.duration()` instead. */
	duration(params?: string | core.$ZodCheckISODurationParams): this;
}
declare const ZodString: core.$constructor<ZodString>;
declare function string$1(params?: string | core.$ZodStringParams): ZodString;
declare function string$1<T extends string>(params?: string | core.$ZodStringParams): core.$ZodType<T, T>;
export interface ZodStringFormat<Format extends string = string> extends _ZodString<core.$ZodStringFormatInternals<Format>> {
}
declare const ZodStringFormat: core.$constructor<ZodStringFormat>;
export interface ZodISODateTime extends ZodStringFormat {
	_zod: core.$ZodISODateTimeInternals;
}
declare const ZodISODateTime: core.$constructor<ZodISODateTime>;
export interface ZodISODate extends ZodStringFormat {
	_zod: core.$ZodISODateInternals;
}
declare const ZodISODate: core.$constructor<ZodISODate>;
export interface ZodISOTime extends ZodStringFormat {
	_zod: core.$ZodISOTimeInternals;
}
declare const ZodISOTime: core.$constructor<ZodISOTime>;
export interface ZodISODuration extends ZodStringFormat {
	_zod: core.$ZodISODurationInternals;
}
declare const ZodISODuration: core.$constructor<ZodISODuration>;
export interface ZodEmail extends ZodStringFormat<"email"> {
	_zod: core.$ZodEmailInternals;
}
declare const ZodEmail: core.$constructor<ZodEmail>;
declare function email$1(params?: string | core.$ZodEmailParams): ZodEmail;
export interface ZodGUID extends ZodStringFormat<"guid"> {
	_zod: core.$ZodGUIDInternals;
}
declare const ZodGUID: core.$constructor<ZodGUID>;
declare function guid$1(params?: string | core.$ZodGUIDParams): ZodGUID;
export interface ZodUUID extends ZodStringFormat<"uuid"> {
	_zod: core.$ZodUUIDInternals;
}
declare const ZodUUID: core.$constructor<ZodUUID>;
declare function uuid$1(params?: string | core.$ZodUUIDParams): ZodUUID;
declare function uuidv4(params?: string | core.$ZodUUIDv4Params): ZodUUID;
declare function uuidv6(params?: string | core.$ZodUUIDv6Params): ZodUUID;
declare function uuidv7(params?: string | core.$ZodUUIDv7Params): ZodUUID;
export interface ZodURL extends ZodStringFormat<"url"> {
	_zod: core.$ZodURLInternals;
}
declare const ZodURL: core.$constructor<ZodURL>;
declare function url(params?: string | core.$ZodURLParams): ZodURL;
declare function httpUrl(params?: string | Omit<core.$ZodURLParams, "protocol" | "hostname">): ZodURL;
export interface ZodEmoji extends ZodStringFormat<"emoji"> {
	_zod: core.$ZodEmojiInternals;
}
declare const ZodEmoji: core.$constructor<ZodEmoji>;
declare function emoji$1(params?: string | core.$ZodEmojiParams): ZodEmoji;
export interface ZodNanoID extends ZodStringFormat<"nanoid"> {
	_zod: core.$ZodNanoIDInternals;
}
declare const ZodNanoID: core.$constructor<ZodNanoID>;
declare function nanoid$1(params?: string | core.$ZodNanoIDParams): ZodNanoID;
/**
 * @deprecated CUID v1 is deprecated by its authors due to information leakage
 * (timestamps embedded in the id). Use {@link ZodCUID2} instead.
 * See https://github.com/paralleldrive/cuid.
 */
export interface ZodCUID extends ZodStringFormat<"cuid"> {
	_zod: core.$ZodCUIDInternals;
}
declare const ZodCUID: core.$constructor<ZodCUID>;
declare function cuid$1(params?: string | core.$ZodCUIDParams): ZodCUID;
export interface ZodCUID2 extends ZodStringFormat<"cuid2"> {
	_zod: core.$ZodCUID2Internals;
}
declare const ZodCUID2: core.$constructor<ZodCUID2>;
declare function cuid2$1(params?: string | core.$ZodCUID2Params): ZodCUID2;
export interface ZodULID extends ZodStringFormat<"ulid"> {
	_zod: core.$ZodULIDInternals;
}
declare const ZodULID: core.$constructor<ZodULID>;
declare function ulid$1(params?: string | core.$ZodULIDParams): ZodULID;
export interface ZodXID extends ZodStringFormat<"xid"> {
	_zod: core.$ZodXIDInternals;
}
declare const ZodXID: core.$constructor<ZodXID>;
declare function xid$1(params?: string | core.$ZodXIDParams): ZodXID;
export interface ZodKSUID extends ZodStringFormat<"ksuid"> {
	_zod: core.$ZodKSUIDInternals;
}
declare const ZodKSUID: core.$constructor<ZodKSUID>;
declare function ksuid$1(params?: string | core.$ZodKSUIDParams): ZodKSUID;
export interface ZodIPv4 extends ZodStringFormat<"ipv4"> {
	_zod: core.$ZodIPv4Internals;
}
declare const ZodIPv4: core.$constructor<ZodIPv4>;
declare function ipv4$1(params?: string | core.$ZodIPv4Params): ZodIPv4;
export interface ZodMAC extends ZodStringFormat<"mac"> {
	_zod: core.$ZodMACInternals;
}
declare const ZodMAC: core.$constructor<ZodMAC>;
declare function mac$1(params?: string | core.$ZodMACParams): ZodMAC;
export interface ZodIPv6 extends ZodStringFormat<"ipv6"> {
	_zod: core.$ZodIPv6Internals;
}
declare const ZodIPv6: core.$constructor<ZodIPv6>;
declare function ipv6$1(params?: string | core.$ZodIPv6Params): ZodIPv6;
export interface ZodCIDRv4 extends ZodStringFormat<"cidrv4"> {
	_zod: core.$ZodCIDRv4Internals;
}
declare const ZodCIDRv4: core.$constructor<ZodCIDRv4>;
declare function cidrv4$1(params?: string | core.$ZodCIDRv4Params): ZodCIDRv4;
export interface ZodCIDRv6 extends ZodStringFormat<"cidrv6"> {
	_zod: core.$ZodCIDRv6Internals;
}
declare const ZodCIDRv6: core.$constructor<ZodCIDRv6>;
declare function cidrv6$1(params?: string | core.$ZodCIDRv6Params): ZodCIDRv6;
export interface ZodBase64 extends ZodStringFormat<"base64"> {
	_zod: core.$ZodBase64Internals;
}
declare const ZodBase64: core.$constructor<ZodBase64>;
declare function base64$1(params?: string | core.$ZodBase64Params): ZodBase64;
export interface ZodBase64URL extends ZodStringFormat<"base64url"> {
	_zod: core.$ZodBase64URLInternals;
}
declare const ZodBase64URL: core.$constructor<ZodBase64URL>;
declare function base64url$1(params?: string | core.$ZodBase64URLParams): ZodBase64URL;
export interface ZodE164 extends ZodStringFormat<"e164"> {
	_zod: core.$ZodE164Internals;
}
declare const ZodE164: core.$constructor<ZodE164>;
declare function e164$1(params?: string | core.$ZodE164Params): ZodE164;
export interface ZodCreditCard extends ZodStringFormat<"credit_card"> {
	_zod: core.$ZodCreditCardInternals;
}
declare const ZodCreditCard: core.$constructor<ZodCreditCard>;
declare function creditCard$1(params?: string | core.$ZodCreditCardParams): ZodCreditCard;
export interface ZodJWT extends ZodStringFormat<"jwt"> {
	_zod: core.$ZodJWTInternals;
}
declare const ZodJWT: core.$constructor<ZodJWT>;
declare function jwt(params?: string | core.$ZodJWTParams): ZodJWT;
export interface ZodCustomStringFormat<Format extends string = string> extends ZodStringFormat<Format>, core.$ZodCustomStringFormat<Format> {
	_zod: core.$ZodCustomStringFormatInternals<Format>;
	"~standard": ZodStandardSchemaWithJSON$1<this>;
}
declare const ZodCustomStringFormat: core.$constructor<ZodCustomStringFormat>;
declare function stringFormat<Format extends string>(format: Format, fnOrRegex: ((arg: string) => util.MaybeAsync<unknown>) | RegExp, _params?: string | core.$ZodStringFormatParams): ZodCustomStringFormat<Format>;
declare function hostname$1(_params?: string | core.$ZodStringFormatParams): ZodCustomStringFormat<"hostname">;
declare function hex$1(_params?: string | core.$ZodStringFormatParams): ZodCustomStringFormat<"hex">;
declare function hash<Alg extends util.HashAlgorithm, Enc extends util.HashEncoding = "hex">(alg: Alg, params?: {
	enc?: Enc;
} & core.$ZodStringFormatParams): ZodCustomStringFormat<`${Alg}_${Enc}`>;
export interface _ZodNumber<Internals extends core.$ZodNumberInternals = core.$ZodNumberInternals> extends _ZodType<Internals> {
	gt(value: number, params?: string | core.$ZodCheckGreaterThanParams): this;
	/** Identical to .min() */
	gte(value: number, params?: string | core.$ZodCheckGreaterThanParams): this;
	min(value: number, params?: string | core.$ZodCheckGreaterThanParams): this;
	lt(value: number, params?: string | core.$ZodCheckLessThanParams): this;
	/** Identical to .max() */
	lte(value: number, params?: string | core.$ZodCheckLessThanParams): this;
	max(value: number, params?: string | core.$ZodCheckLessThanParams): this;
	/** Consider `z.int()` instead. This API is considered *legacy*; it will never be removed but a better alternative exists. */
	int(params?: string | core.$ZodCheckNumberFormatParams): this;
	/** @deprecated This is now identical to `.int()`. Only numbers in the safe integer range are accepted. */
	safe(params?: string | core.$ZodCheckNumberFormatParams): this;
	positive(params?: string | core.$ZodCheckGreaterThanParams): this;
	nonnegative(params?: string | core.$ZodCheckGreaterThanParams): this;
	negative(params?: string | core.$ZodCheckLessThanParams): this;
	nonpositive(params?: string | core.$ZodCheckLessThanParams): this;
	multipleOf(value: number, params?: string | core.$ZodCheckMultipleOfParams): this;
	/** @deprecated Use `.multipleOf()` instead. */
	step(value: number, params?: string | core.$ZodCheckMultipleOfParams): this;
	/** @deprecated In v4 and later, z.number() does not allow infinite values by default. This is a no-op. */
	finite(params?: unknown): this;
	minValue: number | null;
	maxValue: number | null;
	/** @deprecated Check the `format` property instead.  */
	isInt: boolean;
	/** @deprecated Number schemas no longer accept infinite values, so this always returns `true`. */
	isFinite: boolean;
	format: string | null;
}
export interface ZodNumber extends _ZodNumber<core.$ZodNumberInternals<number>> {
}
declare const ZodNumber: core.$constructor<ZodNumber>;
declare function number$1(params?: string | core.$ZodNumberParams): ZodNumber;
export interface ZodNumberFormat<Format extends $ZodNumberFormats = $ZodNumberFormats> extends ZodNumber {
	_zod: core.$ZodNumberFormatInternals<Format>;
}
declare const ZodNumberFormat: core.$constructor<ZodNumberFormat>;
export interface ZodInt extends ZodNumberFormat<"safeint"> {
}
declare function int(params?: string | core.$ZodCheckNumberFormatParams): ZodInt;
export interface ZodFloat32 extends ZodNumberFormat<"float32"> {
}
declare function float32(params?: string | core.$ZodCheckNumberFormatParams): ZodFloat32;
export interface ZodFloat64 extends ZodNumberFormat<"float64"> {
}
declare function float64(params?: string | core.$ZodCheckNumberFormatParams): ZodFloat64;
export interface ZodInt32 extends ZodNumberFormat<"int32"> {
}
declare function int32(params?: string | core.$ZodCheckNumberFormatParams): ZodInt32;
export interface ZodUInt32 extends ZodNumberFormat<"uint32"> {
}
declare function uint32(params?: string | core.$ZodCheckNumberFormatParams): ZodUInt32;
export interface _ZodBoolean<T extends core.$ZodBooleanInternals = core.$ZodBooleanInternals> extends _ZodType<T> {
}
export interface ZodBoolean extends _ZodBoolean<core.$ZodBooleanInternals<boolean>> {
}
declare const ZodBoolean: core.$constructor<ZodBoolean>;
declare function boolean$1(params?: string | core.$ZodBooleanParams): ZodBoolean;
export interface _ZodBigInt<T extends core.$ZodBigIntInternals = core.$ZodBigIntInternals> extends _ZodType<T> {
	gte(value: bigint, params?: string | core.$ZodCheckGreaterThanParams): this;
	/** Alias of `.gte()` */
	min(value: bigint, params?: string | core.$ZodCheckGreaterThanParams): this;
	gt(value: bigint, params?: string | core.$ZodCheckGreaterThanParams): this;
	/** Alias of `.lte()` */
	lte(value: bigint, params?: string | core.$ZodCheckLessThanParams): this;
	max(value: bigint, params?: string | core.$ZodCheckLessThanParams): this;
	lt(value: bigint, params?: string | core.$ZodCheckLessThanParams): this;
	positive(params?: string | core.$ZodCheckGreaterThanParams): this;
	negative(params?: string | core.$ZodCheckLessThanParams): this;
	nonpositive(params?: string | core.$ZodCheckLessThanParams): this;
	nonnegative(params?: string | core.$ZodCheckGreaterThanParams): this;
	multipleOf(value: bigint, params?: string | core.$ZodCheckMultipleOfParams): this;
	minValue: bigint | null;
	maxValue: bigint | null;
	format: string | null;
}
export interface ZodBigInt extends _ZodBigInt<core.$ZodBigIntInternals<bigint>> {
}
declare const ZodBigInt: core.$constructor<ZodBigInt>;
declare function bigint$1(params?: string | core.$ZodBigIntParams): ZodBigInt;
export interface ZodBigIntFormat<Format extends $ZodBigIntFormats = $ZodBigIntFormats> extends ZodBigInt {
	_zod: core.$ZodBigIntFormatInternals<Format>;
}
declare const ZodBigIntFormat: core.$constructor<ZodBigIntFormat>;
export interface ZodInt64 extends ZodBigIntFormat<"int64"> {
}
declare function int64(params?: string | core.$ZodBigIntFormatParams): ZodInt64;
export interface ZodUInt64 extends ZodBigIntFormat<"uint64"> {
}
declare function uint64(params?: string | core.$ZodBigIntFormatParams): ZodUInt64;
export interface ZodSymbol extends _ZodType<core.$ZodSymbolInternals> {
}
declare const ZodSymbol: core.$constructor<ZodSymbol>;
declare function symbol(params?: string | core.$ZodSymbolParams): ZodSymbol;
export interface ZodUndefined extends _ZodType<core.$ZodUndefinedInternals> {
}
declare const ZodUndefined: core.$constructor<ZodUndefined>;
declare function _undefined$2(params?: string | core.$ZodUndefinedParams): ZodUndefined;
export interface ZodNull extends _ZodType<core.$ZodNullInternals> {
}
declare const ZodNull: core.$constructor<ZodNull>;
declare function _null$2(params?: string | core.$ZodNullParams): ZodNull;
export interface ZodAny extends _ZodType<core.$ZodAnyInternals> {
}
declare const ZodAny: core.$constructor<ZodAny>;
declare function any(): ZodAny;
export interface ZodUnknown extends _ZodType<core.$ZodUnknownInternals> {
}
declare const ZodUnknown: core.$constructor<ZodUnknown>;
declare function unknown(): ZodUnknown;
export interface ZodNever extends _ZodType<core.$ZodNeverInternals> {
}
declare const ZodNever: core.$constructor<ZodNever>;
declare function never(params?: string | core.$ZodNeverParams): ZodNever;
export interface ZodVoid extends _ZodType<core.$ZodVoidInternals> {
}
declare const ZodVoid: core.$constructor<ZodVoid>;
declare function _void$1(params?: string | core.$ZodVoidParams): ZodVoid;
export interface _ZodDate<T extends core.$ZodDateInternals = core.$ZodDateInternals> extends _ZodType<T> {
	min(value: number | Date, params?: string | core.$ZodCheckGreaterThanParams): this;
	max(value: number | Date, params?: string | core.$ZodCheckLessThanParams): this;
	/** @deprecated Not recommended. */
	minDate: Date | null;
	/** @deprecated Not recommended. */
	maxDate: Date | null;
}
export interface ZodDate extends _ZodDate<core.$ZodDateInternals<Date>> {
}
declare const ZodDate: core.$constructor<ZodDate>;
declare function date$1(params?: string | core.$ZodDateParams): ZodDate;
export interface ZodArray<T extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodArrayInternals<T>>, core.$ZodArray<T> {
	element: T;
	min(minLength: number, params?: string | core.$ZodCheckMinLengthParams): this;
	nonempty(params?: string | core.$ZodCheckMinLengthParams): this;
	max(maxLength: number, params?: string | core.$ZodCheckMaxLengthParams): this;
	length(len: number, params?: string | core.$ZodCheckLengthEqualsParams): this;
	unwrap(): T;
	"~standard": ZodStandardSchemaWithJSON$1<this>;
}
declare const ZodArray: core.$constructor<ZodArray>;
declare function array<T extends core.SomeType>(element: T, params?: string | core.$ZodArrayParams): ZodArray<T>;
declare function keyof<T extends ZodObject>(schema: T): ZodEnum<util.KeysEnum<T["_zod"]["output"]>>;
export type SafeExtendShape<Base extends core.$ZodShape, Ext extends core.$ZodLooseShape> = {
	[K in keyof Ext]: K extends keyof Base ? core.output<Ext[K]> extends core.output<Base[K]> ? core.input<Ext[K]> extends core.input<Base[K]> ? Ext[K] : never : never : Ext[K];
};
export interface ZodObject<
/** @ts-ignore Cast variance */
out Shape extends core.$ZodShape = core.$ZodLooseShape, out Config extends core.$ZodObjectConfig = core.$strip> extends _ZodType<core.$ZodObjectInternals<Shape, Config>>, core.$ZodObject<Shape, Config> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	shape: Shape;
	keyof(): ZodEnum<util.ToEnum<keyof Shape & string>>;
	/** Define a schema to validate all unrecognized keys. This overrides the existing strict/loose behavior. */
	catchall<T extends core.SomeType>(schema: T): ZodObject<Shape, core.$catchall<T>>;
	/** @deprecated Use `z.looseObject()` or `.loose()` instead. */
	passthrough(): ZodObject<Shape, core.$loose>;
	/** Consider `z.looseObject(A.shape)` instead */
	loose(): ZodObject<Shape, core.$loose>;
	/** Consider `z.strictObject(A.shape)` instead */
	strict(): ZodObject<Shape, core.$strict>;
	/** This is the default behavior. This method call is likely unnecessary. */
	strip(): ZodObject<Shape, core.$strip>;
	extend<U extends core.$ZodLooseShape>(shape: U): ZodObject<util.Extend<Shape, util.Writeable<U>>, Config>;
	safeExtend<U extends core.$ZodLooseShape>(shape: SafeExtendShape<Shape, U> & Partial<Record<keyof Shape, core.SomeType>>): ZodObject<util.Extend<Shape, util.Writeable<U>>, Config>;
	/**
	 * @deprecated Use [`A.extend(B.shape)`](https://zod.dev/api?id=extend) instead.
	 */
	merge<U extends ZodObject>(other: U): ZodObject<util.Extend<Shape, U["shape"]>, U["_zod"]["config"]>;
	pick<M extends util.Mask<keyof Shape>>(mask: M & Record<Exclude<keyof M, keyof Shape>, never>): ZodObject<util.Flatten<Pick<Shape, Extract<keyof Shape, keyof M>>>, Config>;
	omit<M extends util.Mask<keyof Shape>>(mask: M & Record<Exclude<keyof M, keyof Shape>, never>): ZodObject<util.Flatten<Omit<Shape, Extract<keyof Shape, keyof M>>>, Config>;
	partial(): ZodObject<{
		-readonly [k in keyof Shape]: ZodOptional<Shape[k]>;
	}, Config>;
	partial<M extends util.Mask<keyof Shape>>(mask: M & Record<Exclude<keyof M, keyof Shape>, never>): ZodObject<{
		-readonly [k in keyof Shape]: k extends keyof M ? ZodOptional<Shape[k]> : Shape[k];
	}, Config>;
	exactPartial(): ZodObject<{
		-readonly [k in keyof Shape]: ZodExactOptional<Shape[k]>;
	}, Config>;
	exactPartial<M extends util.Mask<keyof Shape>>(mask: M & Record<Exclude<keyof M, keyof Shape>, never>): ZodObject<{
		-readonly [k in keyof Shape]: k extends keyof M ? ZodExactOptional<Shape[k]> : Shape[k];
	}, Config>;
	required(): ZodObject<{
		-readonly [k in keyof Shape]: ZodNonOptional<Shape[k]>;
	}, Config>;
	required<M extends util.Mask<keyof Shape>>(mask: M & Record<Exclude<keyof M, keyof Shape>, never>): ZodObject<{
		-readonly [k in keyof Shape]: k extends keyof M ? ZodNonOptional<Shape[k]> : Shape[k];
	}, Config>;
}
declare const ZodObject: core.$constructor<ZodObject>;
declare function object<T extends core.$ZodLooseShape = Partial<Record<never, core.SomeType>>>(shape?: T, params?: string | core.$ZodObjectParams): ZodObject<util.Writeable<T>, core.$strip>;
declare function strictObject<T extends core.$ZodLooseShape>(shape: T, params?: string | core.$ZodObjectParams): ZodObject<util.Writeable<T>, core.$strict>;
declare function looseObject<T extends core.$ZodLooseShape>(shape: T, params?: string | core.$ZodObjectParams): ZodObject<util.Writeable<T>, core.$loose>;
export interface ZodUnion<T extends readonly core.SomeType[] = readonly core.$ZodType[]> extends _ZodType<core.$ZodUnionInternals<T>>, core.$ZodUnion<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	options: T;
}
declare const ZodUnion: core.$constructor<ZodUnion>;
declare function union<const T extends readonly core.SomeType[]>(options: T, params?: string | core.$ZodUnionParams): ZodUnion<T>;
export interface ZodXor<T extends readonly core.SomeType[] = readonly core.$ZodType[]> extends _ZodType<core.$ZodXorInternals<T>>, core.$ZodXor<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	options: T;
}
declare const ZodXor: core.$constructor<ZodXor>;
declare function xor<const T extends readonly core.SomeType[]>(options: T, params?: string | core.$ZodXorParams): ZodXor<T>;
export interface ZodDiscriminatedUnion<Options extends readonly core.SomeType[] = readonly core.$ZodType[], Disc extends string = string> extends ZodUnion<Options>, core.$ZodDiscriminatedUnion<Options, Disc> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	_zod: core.$ZodDiscriminatedUnionInternals<Options, Disc>;
	def: core.$ZodDiscriminatedUnionDef<Options, Disc>;
}
declare const ZodDiscriminatedUnion: core.$constructor<ZodDiscriminatedUnion>;
declare function discriminatedUnion<Types extends readonly [
	core.$ZodTypeDiscriminable,
	...core.$ZodTypeDiscriminable[]
], Disc extends string>(discriminator: Disc, options: Types, params?: string | core.$ZodDiscriminatedUnionParams): ZodDiscriminatedUnion<Types, Disc>;
export interface ZodIntersection<A extends core.SomeType = core.$ZodType, B extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodIntersectionInternals<A, B>>, core.$ZodIntersection<A, B> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
}
declare const ZodIntersection: core.$constructor<ZodIntersection>;
declare function intersection<T extends core.SomeType, U extends core.SomeType>(left: T, right: U): ZodIntersection<T, U>;
export interface ZodTuple<T extends util.TupleItems = readonly core.$ZodType[], Rest extends core.SomeType | null = core.$ZodType | null> extends _ZodType<core.$ZodTupleInternals<T, Rest>>, core.$ZodTuple<T, Rest> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	rest<Rest extends core.SomeType = core.$ZodType>(rest: Rest): ZodTuple<T, Rest>;
	partial(): ZodTuple<{
		-readonly [k in keyof T]: ZodOptional<T[k]>;
	}, Rest>;
}
declare const ZodTuple: core.$constructor<ZodTuple>;
declare function tuple<T extends readonly [
	core.SomeType,
	...core.SomeType[]
]>(items: T, params?: string | core.$ZodTupleParams): ZodTuple<T, null>;
declare function tuple<T extends readonly [
	core.SomeType,
	...core.SomeType[]
], Rest extends core.SomeType>(items: T, rest: Rest, params?: string | core.$ZodTupleParams): ZodTuple<T, Rest>;
declare function tuple(items: [
], params?: string | core.$ZodTupleParams): ZodTuple<[
], null>;
export interface ZodRecord<Key extends core.$ZodRecordKey = core.$ZodRecordKey, Value extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodRecordInternals<Key, Value>>, core.$ZodRecord<Key, Value> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	keyType: Key;
	valueType: Value;
}
declare const ZodRecord: core.$constructor<ZodRecord>;
declare function record<Key extends core.$ZodRecordKey, Value extends core.SomeType>(keyType: Key, valueType: Value, params?: string | core.$ZodRecordParams): ZodRecord<Key, Value>;
declare function partialRecord<Key extends core.$ZodRecordKey, Value extends core.SomeType>(keyType: Key, valueType: Value, params?: string | core.$ZodRecordParams): ZodRecord<Key & core.$partial, Value>;
declare function looseRecord<Key extends core.$ZodRecordKey, Value extends core.SomeType>(keyType: Key, valueType: Value, params?: string | core.$ZodRecordParams): ZodRecord<Key, Value>;
export interface ZodMap<Key extends core.SomeType = core.$ZodType, Value extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodMapInternals<Key, Value>>, core.$ZodMap<Key, Value> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	keyType: Key;
	valueType: Value;
	min(minSize: number, params?: string | core.$ZodCheckMinSizeParams): this;
	nonempty(params?: string | core.$ZodCheckMinSizeParams): this;
	max(maxSize: number, params?: string | core.$ZodCheckMaxSizeParams): this;
	size(size: number, params?: string | core.$ZodCheckSizeEqualsParams): this;
}
declare const ZodMap: core.$constructor<ZodMap>;
declare function map<Key extends core.SomeType, Value extends core.SomeType>(keyType: Key, valueType: Value, params?: string | core.$ZodMapParams): ZodMap<Key, Value>;
export interface ZodSet<T extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodSetInternals<T>>, core.$ZodSet<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	min(minSize: number, params?: string | core.$ZodCheckMinSizeParams): this;
	nonempty(params?: string | core.$ZodCheckMinSizeParams): this;
	max(maxSize: number, params?: string | core.$ZodCheckMaxSizeParams): this;
	size(size: number, params?: string | core.$ZodCheckSizeEqualsParams): this;
}
declare const ZodSet: core.$constructor<ZodSet>;
declare function set<Value extends core.SomeType>(valueType: Value, params?: string | core.$ZodSetParams): ZodSet<Value>;
export interface ZodEnum<
/** @ts-ignore Cast variance */
out T extends util.EnumLike = util.EnumLike> extends _ZodType<core.$ZodEnumInternals<T>>, core.$ZodEnum<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	enum: T;
	options: Array<T[keyof T]>;
	extract<const U extends readonly (keyof T)[]>(values: U, params?: string | core.$ZodEnumParams): ZodEnum<util.Flatten<Pick<T, U[number]>>>;
	exclude<const U extends readonly (keyof T)[]>(values: U, params?: string | core.$ZodEnumParams): ZodEnum<util.Flatten<Omit<T, U[number]>>>;
}
declare const ZodEnum: core.$constructor<ZodEnum>;
declare function _enum$1<const T extends readonly string[]>(values: T, params?: string | core.$ZodEnumParams): ZodEnum<util.ToEnum<T[number]>>;
declare function _enum$1<const T extends util.EnumLike>(entries: T, params?: string | core.$ZodEnumParams): ZodEnum<T>;
declare function nativeEnum<T extends util.EnumLike>(entries: T, params?: string | core.$ZodEnumParams): ZodEnum<T>;
export interface ZodLiteral<T extends util.Literal = util.Literal> extends _ZodType<core.$ZodLiteralInternals<T>>, core.$ZodLiteral<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	values: Set<T>;
	/** @legacy Use `.values` instead. Accessing this property will throw an error if the literal accepts multiple values. */
	value: T;
}
declare const ZodLiteral: core.$constructor<ZodLiteral>;
declare function literal<const T extends ReadonlyArray<util.Literal>>(value: T, params?: string | core.$ZodLiteralParams): ZodLiteral<T[number]>;
declare function literal<const T extends util.Literal>(value: T, params?: string | core.$ZodLiteralParams): ZodLiteral<T>;
export interface ZodFile extends _ZodType<core.$ZodFileInternals>, core.$ZodFile {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	min(size: number, params?: string | core.$ZodCheckMinSizeParams): this;
	max(size: number, params?: string | core.$ZodCheckMaxSizeParams): this;
	mime(types: util.MimeTypes | Array<util.MimeTypes>, params?: string | core.$ZodCheckMimeTypeParams): this;
}
declare const ZodFile: core.$constructor<ZodFile>;
declare function file(params?: string | core.$ZodFileParams): ZodFile;
export interface ZodTransform<O = unknown, I = unknown> extends _ZodType<core.$ZodTransformInternals<O, I>>, core.$ZodTransform<O, I> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
}
declare const ZodTransform: core.$constructor<ZodTransform>;
declare function transform<I = unknown, O = I>(fn: (input: I, ctx: core.$RefinementCtx) => O): ZodTransform<Awaited<O>, I>;
export interface ZodOptional<T extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodOptionalInternals<T>>, core.$ZodOptional<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	unwrap(): T;
}
declare const ZodOptional: core.$constructor<ZodOptional>;
declare function optional<T extends core.SomeType>(innerType: T): ZodOptional<T>;
export interface ZodExactOptional<T extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodExactOptionalInternals<T>>, core.$ZodExactOptional<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	unwrap(): T;
}
declare const ZodExactOptional: core.$constructor<ZodExactOptional>;
declare function exactOptional<T extends core.SomeType>(innerType: T): ZodExactOptional<T>;
export interface ZodNullable<T extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodNullableInternals<T>>, core.$ZodNullable<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	unwrap(): T;
}
declare const ZodNullable: core.$constructor<ZodNullable>;
declare function nullable<T extends core.SomeType>(innerType: T): ZodNullable<T>;
declare function nullish$1<T extends core.SomeType>(innerType: T): ZodOptional<ZodNullable<T>>;
export interface ZodDefault<T extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodDefaultInternals<T>>, core.$ZodDefault<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	unwrap(): T;
	/** @deprecated Use `.unwrap()` instead. */
	removeDefault(): T;
}
declare const ZodDefault: core.$constructor<ZodDefault>;
declare function _default$63<T extends core.SomeType>(innerType: T, defaultValue: util.NoUndefined<core.output<T>> | (() => util.NoUndefined<core.output<T>>)): ZodDefault<T>;
export interface ZodPrefault<T extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodPrefaultInternals<T>>, core.$ZodPrefault<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	unwrap(): T;
}
declare const ZodPrefault: core.$constructor<ZodPrefault>;
declare function prefault<T extends core.SomeType>(innerType: T, defaultValue: core.input<T> | (() => core.input<T>)): ZodPrefault<T>;
export interface ZodNonOptional<T extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodNonOptionalInternals<T>>, core.$ZodNonOptional<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	unwrap(): T;
}
declare const ZodNonOptional: core.$constructor<ZodNonOptional>;
declare function nonoptional<T extends core.SomeType>(innerType: T, params?: string | core.$ZodNonOptionalParams): ZodNonOptional<T>;
export interface ZodSuccess<T extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodSuccessInternals<T>>, core.$ZodSuccess<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	unwrap(): T;
}
declare const ZodSuccess: core.$constructor<ZodSuccess>;
declare function success<T extends core.SomeType>(innerType: T): ZodSuccess<T>;
export interface ZodCatch<T extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodCatchInternals<T>>, core.$ZodCatch<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	unwrap(): T;
	/** @deprecated Use `.unwrap()` instead. */
	removeCatch(): T;
}
declare const ZodCatch: core.$constructor<ZodCatch>;
declare function _catch$1<T extends core.SomeType>(innerType: T, catchValue: core.output<T> | ((ctx: core.$ZodCatchCtx) => core.output<T>)): ZodCatch<T>;
export interface ZodNaN extends _ZodType<core.$ZodNaNInternals>, core.$ZodNaN {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
}
declare const ZodNaN: core.$constructor<ZodNaN>;
declare function nan(params?: string | core.$ZodNaNParams): ZodNaN;
export interface ZodPipe<A extends core.SomeType = core.$ZodType, B extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodPipeInternals<A, B>>, core.$ZodPipe<A, B> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	in: A;
	out: B;
}
declare const ZodPipe: core.$constructor<ZodPipe>;
declare function pipe<const A extends core.SomeType, B extends core.$ZodType<unknown, core.output<A>> = core.$ZodType<unknown, core.output<A>>>(in_: A, out: B | core.$ZodType<unknown, core.output<A>>): ZodPipe<A, B>;
export interface ZodCodec<A extends core.SomeType = core.$ZodType, B extends core.SomeType = core.$ZodType> extends ZodPipe<A, B>, core.$ZodCodec<A, B> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	_zod: core.$ZodCodecInternals<A, B>;
	def: core.$ZodCodecDef<A, B>;
}
declare const ZodCodec: core.$constructor<ZodCodec>;
declare function codec<const A extends core.SomeType, B extends core.SomeType = core.$ZodType>(in_: A, out: B, params: {
	decode: (value: core.output<A>, payload: core.ParsePayload<core.output<A>>) => core.util.MaybeAsync<core.input<B>>;
	encode: (value: core.input<B>, payload: core.ParsePayload<core.input<B>>) => core.util.MaybeAsync<core.output<A>>;
}): ZodCodec<A, B>;
declare function invertCodec<A extends core.SomeType, B extends core.SomeType>(codec: ZodCodec<A, B>): ZodCodec<B, A>;
export interface ZodPreprocess<B extends core.SomeType = core.$ZodType, I = unknown> extends ZodPipe<core.$ZodTransform<unknown, I>, B>, core.$ZodPreprocess<B, I> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	_zod: core.$ZodPreprocessInternals<B, I>;
	def: core.$ZodPreprocessDef<B, I>;
}
declare const ZodPreprocess: core.$constructor<ZodPreprocess>;
export interface ZodReadonly<T extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodReadonlyInternals<T>>, core.$ZodReadonly<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	unwrap(): T;
}
declare const ZodReadonly: core.$constructor<ZodReadonly>;
declare function readonly<T extends core.SomeType>(innerType: T): ZodReadonly<T>;
export interface ZodTemplateLiteral<Template extends string = string> extends _ZodType<core.$ZodTemplateLiteralInternals<Template>>, core.$ZodTemplateLiteral<Template> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
}
declare const ZodTemplateLiteral: core.$constructor<ZodTemplateLiteral>;
declare function templateLiteral<const Parts extends core.$ZodTemplateLiteralPart[]>(parts: Parts, params?: string | core.$ZodTemplateLiteralParams): ZodTemplateLiteral<core.$PartsToTemplateLiteral<Parts>>;
export interface ZodLazy<T extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodLazyInternals<T>>, core.$ZodLazy<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	unwrap(): T;
}
declare const ZodLazy: core.$constructor<ZodLazy>;
declare function lazy<T extends core.SomeType>(getter: () => T): ZodLazy<T>;
export interface ZodPromise<T extends core.SomeType = core.$ZodType> extends _ZodType<core.$ZodPromiseInternals<T>>, core.$ZodPromise<T> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	unwrap(): T;
}
declare const ZodPromise: core.$constructor<ZodPromise>;
declare function promise<T extends core.SomeType>(innerType: T): ZodPromise<T>;
export interface ZodFunction<Args extends core.$ZodFunctionIn = core.$ZodFunctionIn, Returns extends core.$ZodFunctionOut = core.$ZodFunctionOut> extends _ZodType<core.$ZodFunctionInternals<Args, Returns>>, core.$ZodFunction<Args, Returns> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
	_def: core.$ZodFunctionDef<Args, Returns>;
	_input: core.$InferInnerFunctionType<Args, Returns>;
	_output: core.$InferOuterFunctionType<Args, Returns>;
	input<const Items extends util.TupleItems, const Rest extends core.$ZodFunctionOut = core.$ZodFunctionOut>(args: Items, rest?: Rest): ZodFunction<core.$ZodTuple<Items, Rest>, Returns>;
	input<NewArgs extends core.$ZodFunctionIn>(args: NewArgs): ZodFunction<NewArgs, Returns>;
	input(...args: any[]): ZodFunction<any, Returns>;
	output<NewReturns extends core.$ZodType>(output: NewReturns): ZodFunction<Args, NewReturns>;
}
declare const ZodFunction: core.$constructor<ZodFunction>;
declare function _function(): ZodFunction;
declare function _function<const In extends ReadonlyArray<core.$ZodType>>(params: {
	input: In;
}): ZodFunction<ZodTuple<In, null>, core.$ZodFunctionOut>;
declare function _function<const In extends ReadonlyArray<core.$ZodType>, const Out extends core.$ZodFunctionOut = core.$ZodFunctionOut>(params: {
	input: In;
	output: Out;
}): ZodFunction<ZodTuple<In, null>, Out>;
declare function _function<const In extends core.$ZodFunctionIn = core.$ZodFunctionIn>(params: {
	input: In;
}): ZodFunction<In, core.$ZodFunctionOut>;
declare function _function<const Out extends core.$ZodFunctionOut = core.$ZodFunctionOut>(params: {
	output: Out;
}): ZodFunction<core.$ZodFunctionIn, Out>;
declare function _function<In extends core.$ZodFunctionIn = core.$ZodFunctionIn, Out extends core.$ZodType = core.$ZodType>(params?: {
	input: In;
	output: Out;
}): ZodFunction<In, Out>;
export interface ZodCustom<O = unknown, I = unknown> extends _ZodType<core.$ZodCustomInternals<O, I>>, core.$ZodCustom<O, I> {
	"~standard": ZodStandardSchemaWithJSON$1<this>;
}
declare const ZodCustom: core.$constructor<ZodCustom>;
declare function check<O = unknown>(fn: core.CheckFn<O>): core.$ZodCheck<O>;
declare function custom<O>(fn?: (data: unknown) => unknown, _params?: string | core.$ZodCustomParams | undefined): ZodCustom<O, O>;
declare function refine<T>(fn: (arg: NoInfer<T>) => util.MaybeAsync<unknown>, _params?: string | core.$ZodCustomParams): core.$ZodCheck<T>;
declare function superRefine<T>(fn: (arg: T, payload: core.$RefinementCtx<T>) => void | Promise<void>, params?: core.$ZodSuperRefineParams): core.$ZodCheck<T>;
declare const describe$1: typeof core.describe;
declare const meta$1: typeof core.meta;
export type ZodInstanceOfParams = core.Params<ZodCustom, core.$ZodIssueCustom, "type" | "check" | "checks" | "fn" | "abort" | "error" | "params" | "path">;
declare function _instanceof<T extends typeof util.Class>(cls: T, params?: ZodInstanceOfParams): ZodCustom<InstanceType<T>, InstanceType<T>>;
declare const stringbool: (_params?: string | core.$ZodStringBoolParams) => ZodCodec<ZodString, ZodBoolean>;
export type _ZodJSONSchema = ZodUnion<[
	ZodString,
	ZodNumber,
	ZodBoolean,
	ZodNull,
	ZodArray<ZodJSONSchema>,
	ZodRecord<ZodString, ZodJSONSchema>
]>;
export type _ZodJSONSchemaInternals = _ZodJSONSchema["_zod"];
export interface ZodJSONSchemaInternals extends _ZodJSONSchemaInternals {
	output: util.JSONType;
	input: util.JSONType;
}
export interface ZodJSONSchema extends _ZodJSONSchema {
	_zod: ZodJSONSchemaInternals;
}
declare function json(params?: string | core.$ZodCustomParams): ZodJSONSchema;
declare function preprocess<A, U extends core.SomeType, B = unknown>(fn: (arg: B, ctx: core.$RefinementCtx) => A, schema: U): ZodPreprocess<U, B>;
/** @deprecated Use `z.output<T>` instead. */
export type TypeOf<T> = core.output<T>;
/** @deprecated Use `z.output<T>` instead. */
export type Infer<T> = core.output<T>;
/** @deprecated Use `z.core.$ZodTypes` instead */
export type ZodFirstPartySchemaTypes = core.$ZodTypes;
declare const ZodIssueCode: {
	readonly invalid_type: "invalid_type";
	readonly too_big: "too_big";
	readonly too_small: "too_small";
	readonly invalid_format: "invalid_format";
	readonly not_multiple_of: "not_multiple_of";
	readonly unrecognized_keys: "unrecognized_keys";
	readonly invalid_union: "invalid_union";
	readonly invalid_key: "invalid_key";
	readonly invalid_element: "invalid_element";
	readonly invalid_value: "invalid_value";
	readonly custom: "custom";
};
/** @deprecated Use `z.core.$ZodFlattenedError<z.output<T>>` instead; it takes an inferred type, not a schema. */
export type inferFlattenedErrors<T extends core.$ZodType, U = string> = core.$ZodFlattenedError<core.output<T>, U>;
/** @deprecated Use `z.core.$ZodFormattedError<z.output<T>>` instead; it takes an inferred type, not a schema. */
export type inferFormattedError<T extends core.$ZodType<any, any>, U = string> = core.$ZodFormattedError<core.output<T>, U>;
/** Use `z.$brand` instead */
export type BRAND<T extends string | number | symbol = string | number | symbol> = {
	[core.$brand]: {
		[k in T]: true;
	};
};
declare function setErrorMap(map: core.$ZodErrorMap): void;
declare function getErrorMap(): core.$ZodErrorMap<core.$ZodIssue> | undefined;
/** @deprecated Use z.ZodType (without generics) instead. */
export type ZodTypeAny<Output = unknown, Input = unknown, Internals extends core.$ZodTypeInternals<Output, Input> = core.$ZodTypeInternals<Output, Input>> = ZodType<Output, Input, Internals>;
/** @deprecated Use `z.ZodType` */
export type ZodSchema<Output = unknown, Input = unknown, Internals extends core.$ZodTypeInternals<Output, Input> = core.$ZodTypeInternals<Output, Input>> = ZodType<Output, Input, Internals>;
type Schema$1<Output = unknown, Input = unknown, Internals extends core.$ZodTypeInternals<Output, Input> = core.$ZodTypeInternals<Output, Input>> = ZodType<Output, Input, Internals>;
/** Included for Zod 3 compatibility */
export type ZodRawShape = core.$ZodShape;
declare enum ZodFirstPartyTypeKind {
}
export type JSONSchemaVersion = "draft-2020-12" | "draft-7" | "draft-4" | "openapi-3.0";
export interface FromJSONSchemaParams {
	defaultTarget?: JSONSchemaVersion;
	registry?: $ZodRegistry<any>;
}
declare function fromJSONSchema(schema: JSONSchema$1.JSONSchema | boolean, params?: FromJSONSchemaParams): ZodType;
/** Distributes `.partial()` through every object in a schema type, preserving the wrappers around them. */
export type DeepPartial<T extends core.SomeType> = T extends schemas$1.ZodObject<infer Shape, infer Config> ? schemas$1.ZodObject<{
	-readonly [K in keyof Shape]: schemas$1.ZodOptional<DeepPartial<Shape[K]>>;
}, Config> : T extends schemas$1.ZodDiscriminatedUnion<infer Options, any> ? schemas$1.ZodUnion<{
	-readonly [I in keyof Options]: Options[I] extends core.SomeType ? DeepPartial<Options[I]> : Options[I];
}> : T extends schemas$1.ZodUnion<infer Options> ? schemas$1.ZodUnion<{
	-readonly [I in keyof Options]: Options[I] extends core.SomeType ? DeepPartial<Options[I]> : Options[I];
}> : T extends schemas$1.ZodArray<infer El> ? schemas$1.ZodArray<DeepPartial<El>> : T extends schemas$1.ZodTuple<infer Items, infer Rest> ? schemas$1.ZodTuple<{
	-readonly [K in keyof Items]: Items[K] extends core.SomeType ? DeepPartial<Items[K]> : Items[K];
}, Rest extends core.SomeType ? DeepPartial<Rest> : Rest> : T extends schemas$1.ZodIntersection<infer A, infer B> ? schemas$1.ZodIntersection<DeepPartial<A>, DeepPartial<B>> : T extends schemas$1.ZodRecord<infer K, infer V> ? schemas$1.ZodRecord<K, DeepPartial<V>> : T extends schemas$1.ZodMap<infer K, infer V> ? schemas$1.ZodMap<K, DeepPartial<V>> : T extends schemas$1.ZodSet<infer El> ? schemas$1.ZodSet<DeepPartial<El>> : T extends schemas$1.ZodOptional<infer Inner> ? schemas$1.ZodOptional<DeepPartial<Inner>> : T extends schemas$1.ZodNullable<infer Inner> ? schemas$1.ZodNullable<DeepPartial<Inner>> : T extends schemas$1.ZodDefault<infer Inner> ? schemas$1.ZodDefault<DeepPartial<Inner>> : T extends schemas$1.ZodPrefault<infer Inner> ? schemas$1.ZodPrefault<DeepPartial<Inner>> : T extends schemas$1.ZodNonOptional<infer Inner> ? schemas$1.ZodNonOptional<DeepPartial<Inner>> : T extends schemas$1.ZodCatch<infer Inner> ? schemas$1.ZodCatch<DeepPartial<Inner>> : T extends schemas$1.ZodReadonly<infer Inner> ? schemas$1.ZodReadonly<DeepPartial<Inner>> : T extends schemas$1.ZodSuccess<infer Inner> ? schemas$1.ZodSuccess<DeepPartial<Inner>> : T extends schemas$1.ZodPromise<infer Inner> ? schemas$1.ZodPromise<DeepPartial<Inner>> : T extends schemas$1.ZodCodec<infer A, infer B> ? schemas$1.ZodCodec<DeepPartial<A>, DeepPartial<B>> : T extends schemas$1.ZodPreprocess<infer B> ? schemas$1.ZodPreprocess<DeepPartial<B>> : T extends schemas$1.ZodPipe<infer A, infer B> ? schemas$1.ZodPipe<DeepPartial<A>, DeepPartial<B>> : T extends schemas$1.ZodLazy<infer Inner> ? schemas$1.ZodLazy<DeepPartial<Inner>> : T;
declare function deepPartial<T extends core.SomeType>(schema: T): DeepPartial<T>;
type input$1<T> = core.input<T>;
type output$1<T> = core.output<T>;
declare function input$1<T extends core.$ZodType>(schema: T): schemas$1.ZodType<core.input<T>, core.input<T>>;
declare function output$1<T extends core.$ZodType>(schema: T): schemas$1.ZodType<core.output<T>, core.output<T>>;
declare function datetime$1(params?: string | core.$ZodISODateTimeParams): ZodISODateTime;
declare function date$2(params?: string | core.$ZodISODateParams): ZodISODate;
declare function time$1(params?: string | core.$ZodISOTimeParams): ZodISOTime;
declare function duration$1(params?: string | core.$ZodISODurationParams): ZodISODuration;
export interface ZodCoercedString<T = unknown> extends schemas$1._ZodString<core.$ZodStringInternals<T>> {
}
declare function string$2<T = unknown>(params?: string | core.$ZodStringParams): ZodCoercedString<T>;
export interface ZodCoercedNumber<T = unknown> extends schemas$1._ZodNumber<core.$ZodNumberInternals<T>> {
}
declare function number$2<T = unknown>(params?: string | core.$ZodNumberParams): ZodCoercedNumber<T>;
export interface ZodCoercedBoolean<T = unknown> extends schemas$1._ZodBoolean<core.$ZodBooleanInternals<T>> {
}
declare function boolean$2<T = unknown>(params?: string | core.$ZodBooleanParams): ZodCoercedBoolean<T>;
export interface ZodCoercedBigInt<T = unknown> extends schemas$1._ZodBigInt<core.$ZodBigIntInternals<T>> {
}
declare function bigint$2<T = unknown>(params?: string | core.$ZodBigIntParams): ZodCoercedBigInt<T>;
export interface ZodCoercedDate<T = unknown> extends schemas$1._ZodDate<core.$ZodDateInternals<T>> {
}
declare function date$3<T = unknown>(params?: string | core.$ZodDateParams): ZodCoercedDate<T>;
declare const ConnectorCallStateSchema: z.ZodObject<{
	role: z.ZodOptional<z.ZodString>;
	code: z.ZodEnum<{
		running: "running";
		ok: "ok";
		unavailable: "unavailable";
		connect_required: "connect_required";
		reconnect_required: "reconnect_required";
		approval_pending: "approval_pending";
		forbidden: "forbidden";
		unbound: "unbound";
	}>;
	message: z.ZodString;
	runId: z.ZodOptional<z.ZodString>;
	approvalUrl: z.ZodOptional<z.ZodString>;
	pendingReason: z.ZodOptional<z.ZodEnum<{
		running: "running";
		approval: "approval";
	}>>;
	connectUrl: z.ZodOptional<z.ZodString>;
}, z.core.$strip>;
export type ConnectorCallState = z.infer<typeof ConnectorCallStateSchema>;
export type UploadedObject = {
	id: string;
	public: boolean;
	contentType: string;
	size: number;
	url: string;
};
export type StorageClient = {
	upload(file: Blob, options?: {
		public?: boolean;
	}): Promise<UploadedObject>;
	get(id: string): Promise<Response | null>;
	delete(id: string): Promise<void>;
};
export type AuthProfile = {
	subject?: string;
	identityAliases?: string[];
	displayName: string;
	email?: string;
	emailVerified?: boolean;
	picture?: string;
};
export type SignedOutAuth = AuthProfile & {
	userId: null;
	provider: null;
	isAuthenticated: false;
	isGuest: false;
	isSignedIn: false;
};
export type GuestAuth = AuthProfile & {
	userId: string;
	provider: "guest";
	isAuthenticated: false;
	isGuest: true;
	isSignedIn: false;
};
export type SignedInAuth = AuthProfile & {
	userId: string;
	provider: "account" | "gravatar" | "google" | "service" | "spacefast" | "space-users";
	isAuthenticated: true;
	isGuest: false;
	isSignedIn: true;
};
export type Auth = SignedOutAuth | GuestAuth | SignedInAuth;
export type AuthValue = Auth & {
	identity?: ZeroIdentity;
	isLoading: boolean;
	requireSignIn: boolean;
	error?: string;
	profileUrl?: string;
	user: unknown;
	loading?: boolean;
};
export type ZeroIdentity = {
	userId: string | null;
	provider?: string;
	isGuest?: boolean;
	isAuthenticated?: boolean;
	email?: string;
	displayName?: string;
	picture?: string;
	profileUrl?: string;
	emailVerified?: boolean;
	token?: string;
	expired?: boolean;
};
export type SignInWithGravatarOptions = {
	authBaseUrl?: string;
	callbackPath?: string;
	clientId?: string;
	redirectUri?: string;
	requestPii?: boolean;
	requestProfile?: boolean;
	returnTo?: string;
};
export type SignInWithGoogleOptions = SignInWithGravatarOptions;
export type SignInWithSpacefastOptions = SignInWithGravatarOptions;
export type SignOutOptions = {
	returnTo?: string;
};
export declare function getAuthValue(): AuthValue;
export declare function canAccessApp(): boolean;
export declare function getIdentity(): ZeroIdentity;
export declare function signInWithGravatar(options?: SignInWithGravatarOptions): Promise<{
	url: string | null;
}>;
export declare function signInWithGoogle(options?: SignInWithGoogleOptions): Promise<{
	url: string | null;
}>;
export declare function signInWithSpacefast(options?: SignInWithSpacefastOptions): Promise<{
	url: string | null;
}>;
export declare const storage: StorageClient;
export declare function signOut(options?: SignOutOptions): Promise<{
	url: string | null;
}>;
/** Returns the connector state carried by a query or mutation result. */
export declare function useConnectorState(result: unknown, role?: string): (ConnectorCallState & {
	disconnect: () => Promise<void>;
}) | null;
export declare function retryConnectorQueries(): Promise<void>;
/** The worst connector state recorded for a role, or the first when all are ok. */
export declare function readConnectorRoleState(role: string): ConnectorCallState | null;
declare const collabThemeSchema: z.ZodObject<{
	accent: z.ZodNullable<z.ZodString>;
	background: z.ZodNullable<z.ZodString>;
	font: z.ZodNullable<z.ZodString>;
	name: z.ZodNullable<z.ZodString>;
	logo: z.ZodNullable<z.ZodString>;
	hideBranding: z.ZodBoolean;
}, z.core.$strip>;
export type CollabTheme = z.infer<typeof collabThemeSchema>;
declare const collabManifestSchema: z.ZodObject<{
	version: z.ZodLiteral<5>;
	environment: z.ZodEnum<{
		preview: "preview";
		production: "production";
	}>;
	space: z.ZodObject<{
		id: z.ZodString;
		name: z.ZodNullable<z.ZodString>;
		liveUrl: z.ZodNullable<z.ZodString>;
	}, z.core.$strip>;
	artifact: z.ZodObject<{
		id: z.ZodNullable<z.ZodString>;
		current: z.ZodBoolean;
		url: z.ZodNullable<z.ZodString>;
	}, z.core.$strip>;
	cast: z.ZodNullable<z.ZodObject<{
		wsUrl: z.ZodString;
		resourceKey: z.ZodString;
	}, z.core.$strip>>;
	ticketUrl: z.ZodNullable<z.ZodString>;
	apiBase: z.ZodNullable<z.ZodString>;
	accountUrl: z.ZodNullable<z.ZodString>;
	returningAccountCookie: z.ZodOptional<z.ZodNullable<z.ZodString>>;
	features: z.ZodObject<{
		picker: z.ZodBoolean;
		drawing: z.ZodBoolean;
		capture: z.ZodBoolean;
		attachments: z.ZodBoolean;
	}, z.core.$strip>;
	uploads: z.ZodNullable<z.ZodObject<{
		base: z.ZodString;
		key: z.ZodString;
	}, z.core.$strip>>;
	ui: z.ZodEnum<{
		default: "default";
		custom: "custom";
	}>;
	theme: z.ZodObject<{
		accent: z.ZodNullable<z.ZodString>;
		background: z.ZodNullable<z.ZodString>;
		font: z.ZodNullable<z.ZodString>;
		name: z.ZodNullable<z.ZodString>;
		logo: z.ZodNullable<z.ZodString>;
		hideBranding: z.ZodBoolean;
	}, z.core.$strip>;
}, z.core.$strip>;
export type CollabManifest = z.infer<typeof collabManifestSchema>;
/** Where the anchor was captured. Identifies the page, not the DOM. */
export type AnchorSource = {
	space_id: string;
	version_id: string | null;
	canonical_path: string;
	url: string;
	/** The document title when the anchor was captured; notifications name the page by it. */
	title?: string;
};
export type TextQuoteSelector = {
	exact: string;
	prefix: string;
	suffix: string;
};
export type TextPositionSelector = {
	start: number;
	end: number;
};
export type RangeSelector = {
	start_container: string;
	start_offset: number;
	end_container: string;
	end_offset: number;
};
export type ElementFingerprint = {
	tag: string;
	role: string | null;
	accessible_name: string | null;
	own_text: string | null;
	ancestor_text: string | null;
	neighbor_texts: string[];
	attrs: Record<string, string>;
};
export type RelativePoint = {
	x: number;
	y: number;
};
export type RelativeBox = {
	x: number;
	y: number;
	width: number;
	height: number;
};
export type CaptureViewport = {
	width: number;
	height: number;
	dpr: number;
	scroll_x: number;
	scroll_y: number;
	doc_width: number;
	doc_height: number;
};
export type SelectorSet = {
	stable_key?: string;
	element_id?: string;
	css?: string;
	css_variants?: string[];
	range?: RangeSelector;
	text_position?: TextPositionSelector;
	text_quote?: TextQuoteSelector;
	fingerprint?: ElementFingerprint;
	relative_point?: RelativePoint;
	capture_box?: RelativeBox;
};
export type AnchorTargetType = "element" | "text";
export type Anchor = {
	v: 2;
	resolver_version: number;
	type: AnchorTargetType;
	source: AnchorSource;
	selectors: SelectorSet;
	viewport: CaptureViewport;
};
/**
 * A comment about the page as a whole. The source IS the locator: there is no
 * spot on the page to find, so it carries no selectors and no viewport, and the
 * overlay lists it with the page comments instead of pinning it.
 */
export type PageAnchor = {
	v: 2;
	resolver_version: number;
	type: "page";
	source: AnchorSource;
};
export type ResolveStrategy = "stable_key" | "element_id" | "css" | "css_variant" | "range" | "text_position" | "text_quote_exact" | "text_quote_fuzzy" | "fingerprint_scan";
export type ResolvedAttachment = {
	kind: "element";
	element: Element;
	point: RelativePoint;
} | {
	kind: "range";
	range: Range;
};
export type ResolveResult = {
	status: "attached";
	target: ResolvedAttachment;
	strategy: ResolveStrategy;
	confidence: number;
	margin: number;
	resolver_version: number;
	elapsed_ms: number;
	nodes_scanned: number;
} | {
	status: "orphaned";
	reason: "no_candidates" | "below_threshold" | "ambiguous" | "quote_mismatch" | "unsupported_anchor_version";
	best_confidence: number;
	resolver_version: number;
	elapsed_ms: number;
	nodes_scanned: number;
};
declare const analyticsQueryResponseSchema: z.ZodObject<{
	data: z.ZodObject<{
		results: z.ZodArray<z.ZodUnion<readonly [
			z.ZodObject<{
				previous: z.ZodOptional<z.ZodObject<{
					since: z.ZodISODateTime;
					until: z.ZodISODateTime;
					rows: z.ZodArray<z.ZodRecord<z.ZodString, z.ZodUnion<readonly [
						z.ZodNumber,
						z.ZodString,
						z.ZodBoolean,
						z.ZodNull,
						z.ZodArray<z.ZodObject<{
							amountMinor: z.ZodNumber;
							currency: z.ZodString;
						}, z.core.$strict>>
					]>>>;
					totals: z.ZodRecord<z.ZodString, z.ZodUnion<readonly [
						z.ZodNumber,
						z.ZodString,
						z.ZodBoolean,
						z.ZodNull,
						z.ZodArray<z.ZodObject<{
							amountMinor: z.ZodNumber;
							currency: z.ZodString;
						}, z.core.$strict>>
					]>>;
					truncated: z.ZodBoolean;
					other: z.ZodNullable<z.ZodRecord<z.ZodString, z.ZodUnion<readonly [
						z.ZodNumber,
						z.ZodString,
						z.ZodBoolean,
						z.ZodNull,
						z.ZodArray<z.ZodObject<{
							amountMinor: z.ZodNumber;
							currency: z.ZodString;
						}, z.core.$strict>>
					]>>>;
				}, z.core.$strict>>;
				since: z.ZodISODateTime;
				until: z.ZodISODateTime;
				rows: z.ZodArray<z.ZodRecord<z.ZodString, z.ZodUnion<readonly [
					z.ZodNumber,
					z.ZodString,
					z.ZodBoolean,
					z.ZodNull,
					z.ZodArray<z.ZodObject<{
						amountMinor: z.ZodNumber;
						currency: z.ZodString;
					}, z.core.$strict>>
				]>>>;
				totals: z.ZodRecord<z.ZodString, z.ZodUnion<readonly [
					z.ZodNumber,
					z.ZodString,
					z.ZodBoolean,
					z.ZodNull,
					z.ZodArray<z.ZodObject<{
						amountMinor: z.ZodNumber;
						currency: z.ZodString;
					}, z.core.$strict>>
				]>>;
				truncated: z.ZodBoolean;
				other: z.ZodNullable<z.ZodRecord<z.ZodString, z.ZodUnion<readonly [
					z.ZodNumber,
					z.ZodString,
					z.ZodBoolean,
					z.ZodNull,
					z.ZodArray<z.ZodObject<{
						amountMinor: z.ZodNumber;
						currency: z.ZodString;
					}, z.core.$strict>>
				]>>>;
				id: z.ZodString;
				timezone: z.ZodLiteral<"UTC">;
				granularity: z.ZodEnum<{
					none: "none";
					hour: "hour";
					day: "day";
				}>;
				columns: z.ZodArray<z.ZodDiscriminatedUnion<[
					z.ZodObject<{
						kind: z.ZodLiteral<"time">;
						id: z.ZodLiteral<"bucket">;
						label: z.ZodString;
					}, z.core.$strict>,
					z.ZodObject<{
						kind: z.ZodLiteral<"dimension">;
						id: z.ZodEnum<{
							path: "path";
							country: "country";
							currency: "currency";
							source_host: "source_host";
							campaign: "campaign";
							device: "device";
							browser: "browser";
							os: "os";
							event_name: "event_name";
							event_label: "event_label";
							scroll_depth_bucket: "scroll_depth_bucket";
						}>;
						label: z.ZodString;
						valueType: z.ZodEnum<{
							path: "path";
							host: "host";
							text: "text";
							country: "country";
							scroll_bucket: "scroll_bucket";
							currency: "currency";
						}>;
					}, z.core.$strict>,
					z.ZodObject<{
						kind: z.ZodLiteral<"metric">;
						id: z.ZodEnum<{
							visitors: "visitors";
							page_views: "page_views";
							unique_visitors_range: "unique_visitors_range";
							visitor_days: "visitor_days";
							engaged_time: "engaged_time";
							scroll_depth: "scroll_depth";
							event_occurrences: "event_occurrences";
							revenue: "revenue";
							bot_traffic: "bot_traffic";
						}>;
						label: z.ZodString;
						definition: z.ZodString;
						unit: z.ZodEnum<{
							count: "count";
							ms: "ms";
							percent_points: "percent_points";
							minor_units: "minor_units";
						}>;
						type: z.ZodEnum<{
							count: "count";
							duration: "duration";
							unique: "unique";
							rate: "rate";
							money: "money";
						}>;
						additive: z.ZodBoolean;
						bots: z.ZodEnum<{
							excluded: "excluded";
							only: "only";
						}>;
						wholeDays: z.ZodBoolean;
						polarity: z.ZodEnum<{
							up_is_good: "up_is_good";
							down_is_good: "down_is_good";
							neutral: "neutral";
						}>;
					}, z.core.$strict>
				], "kind">>;
			}, z.core.$strict>,
			z.ZodObject<{
				id: z.ZodString;
				problem: z.ZodObject<{
					type: z.ZodString;
					title: z.ZodString;
					status: z.ZodNumber;
					detail: z.ZodString;
					code: z.ZodString;
					pointer: z.ZodOptional<z.ZodString>;
					details: z.ZodOptional<z.ZodRecord<z.ZodString, z.ZodUnknown>>;
					fix: z.ZodOptional<z.ZodString>;
					suggestions: z.ZodOptional<z.ZodArray<z.ZodString>>;
					next: z.ZodOptional<z.ZodArray<z.ZodString>>;
					requestId: z.ZodString;
				}, z.core.$strip>;
			}, z.core.$strict>
		]>>;
	}, z.core.$strip>;
}, z.core.$strip>;
export type AnalyticsQueryResponse = z.infer<typeof analyticsQueryResponseSchema>;
declare const hudFrameSchema: z.ZodObject<{
	asOf: z.ZodNumber;
	source: z.ZodEnum<{
		analytics: "analytics";
		presence: "presence";
	}>;
	visitors: z.ZodNumber;
	people: z.ZodNullable<z.ZodNumber>;
	pages: z.ZodArray<z.ZodObject<{
		path: z.ZodString;
		visitors: z.ZodNumber;
		scrollPercent: z.ZodNullable<z.ZodNumber>;
		engagedMs: z.ZodNullable<z.ZodNumber>;
	}, z.core.$strip>>;
	seats: z.ZodArray<z.ZodObject<{
		id: z.ZodString;
		kind: z.ZodEnum<{
			visitor: "visitor";
			member: "member";
		}>;
		name: z.ZodNullable<z.ZodString>;
		path: z.ZodString;
		scrollPercent: z.ZodNullable<z.ZodNumber>;
		enteredAt: z.ZodNullable<z.ZodNumber>;
		referrer: z.ZodNullable<z.ZodObject<{
			host: z.ZodString;
			utm: z.ZodObject<{
				source: z.ZodOptional<z.ZodString>;
				medium: z.ZodOptional<z.ZodString>;
				campaign: z.ZodOptional<z.ZodString>;
			}, z.core.$strip>;
		}, z.core.$strip>>;
		country: z.ZodNullable<z.ZodString>;
		consent: z.ZodEnum<{
			undecided: "undecided";
			counted: "counted";
		}>;
	}, z.core.$strip>>;
	sources: z.ZodArray<z.ZodObject<{
		key: z.ZodString;
		count: z.ZodNumber;
	}, z.core.$strip>>;
	countries: z.ZodArray<z.ZodObject<{
		key: z.ZodString;
		count: z.ZodNumber;
	}, z.core.$strip>>;
	arrivals: z.ZodNullable<z.ZodObject<{
		bucketMs: z.ZodNumber;
		counts: z.ZodArray<z.ZodNumber>;
	}, z.core.$strip>>;
	moments: z.ZodNullable<z.ZodArray<z.ZodObject<{
		id: z.ZodString;
		at: z.ZodNumber;
		name: z.ZodString;
		path: z.ZodNullable<z.ZodString>;
		money: z.ZodNullable<z.ZodObject<{
			amountMinor: z.ZodNumber;
			currency: z.ZodString;
		}, z.core.$strict>>;
	}, z.core.$strip>>>;
	engagedMs: z.ZodNullable<z.ZodNumber>;
	consent: z.ZodNullable<z.ZodObject<{
		counted: z.ZodNumber;
		undecided: z.ZodNumber;
		declined: z.ZodNullable<z.ZodNumber>;
	}, z.core.$strip>>;
	health: z.ZodNullable<z.ZodObject<{
		lagMs: z.ZodNullable<z.ZodNumber>;
		droppedBatches: z.ZodNullable<z.ZodNumber>;
	}, z.core.$strip>>;
}, z.core.$strip>;
export type HudFrame = z.infer<typeof hudFrameSchema>;
declare const seatSchema: z.ZodObject<{
	phx_ref: z.ZodString;
	path: z.ZodOptional<z.ZodNullable<z.ZodString>>;
	actor: z.ZodOptional<z.ZodNullable<z.ZodString>>;
	name: z.ZodOptional<z.ZodNullable<z.ZodString>>;
	scroll: z.ZodOptional<z.ZodNullable<z.ZodNumber>>;
	entered_at: z.ZodOptional<z.ZodNullable<z.ZodNumber>>;
	consent: z.ZodString;
	referrer: z.ZodOptional<z.ZodNullable<z.ZodObject<{
		host: z.ZodString;
	}, z.core.$catchall<z.ZodString>>>>;
	country: z.ZodOptional<z.ZodNullable<z.ZodString>>;
}, z.core.$strip>;
export type LiveSeat = z.infer<typeof seatSchema>;
/** Presence keyed by session: one entry per tab, each holding its metas. */
export type LivePresence = Readonly<Record<string, {
	metas: readonly LiveSeat[];
}>>;
export type AttachmentKind = "screenshot" | "image" | "file";
/** One entry of `metadata.attachments`, exactly as Cast persists it. */
export type CommentAttachment = {
	id: string;
	name: string;
	content_type: string;
	size: number;
	kind: AttachmentKind;
	/** Document-normalized region of interest; screenshot evidence only. */
	crop?: RelativeBox;
};
export type AttachmentUploadFailure = "too_large" | "blocked" | "network";
/** Strokes in CSS pixels from the comment's spot, each flat: x0, y0, x1, y1… */
export type Sketch = {
	color: string;
	width: number;
	strokes: number[][];
};
/**
 * The slice of an analytics profile the Telemetry drawer reads. Structural rather
 * than the profile type, so the SDK does not depend on the whole contract.
 */
export type HudProfileLike = {
	metrics: readonly {
		id: string;
		tier: string;
	}[];
};
/** The ranges Trends can show. */
export type TrendsWindow = "7d" | "30d" | "90d";
/** One query's outcome: its answer, or the problem that refused it. */
export type TrendsAnswer = AnalyticsQueryResponse["data"]["results"][number];
export type CollabTool = "none" | "picker" | "text" | "draw";
export type CollabMode = "idle" | "picking" | "drawing" | "composing";
export type EvidenceTarget = {
	kind: "element";
	element: Element;
} | {
	kind: "range";
	range: Range;
} | {
	kind: "region";
	rect: DOMRect;
} | {
	kind: "page";
};
export type DrawStyle = {
	color: string;
	width: number;
};
/** A peer's live in-progress stroke (`drawing:preview`), document-normalized. */
export type RemoteDrawingPreview = {
	id: string;
	session_id: string;
	actor_id: string;
	color: string;
	width: number;
	points: RelativePoint[];
	doc_width: number;
	doc_height: number;
};
/** A committed stroke (`drawing.stroke.created`), document-normalized. */
export type DrawingStrokeState = {
	id: string;
	actor_id: string;
	color: string;
	width: number;
	points: RelativePoint[];
	doc_width: number;
	doc_height: number;
};
export type ConnectionStatus = "idle" | "connecting" | "connected" | "reconnecting" | "disconnected" | "room_deleted" | "error";
export type CollabSession = {
	id: string;
	external_id: string | null;
	actor_id: string;
	actor_kind: "user" | "guest" | "anonymous";
	/** Server-authoritative Cast identity, bound to the current ticket for guests. */
	anonymous_id: string | null;
	room_id: string;
	capabilities: {
		comments_create: boolean;
		/** Resolve and reopen anyone's thread (Cast's `comments:moderate`): the people who run the Space. */
		comments_moderate: boolean;
		drawing_create: boolean;
		/** The live roster panel: grant-backed team authorities only. */
		telemetry_read: boolean;
	};
	config: Record<string, unknown>;
	config_version: number | null;
	locks: Record<string, unknown>;
};
export type CollabIdentity = {
	anonymous_id: string;
	name: string;
	color: string;
	named_by_user: boolean;
	/** SHA-256 of the normalized email — the ONLY email derivative kept client-side. */
	gravatar_hash?: string;
	username?: string;
	avatar_url?: string;
};
export type PresenceActor = {
	actor_id: string;
	session_id: string;
	kind: "member" | "anonymous";
	name: string;
	color: string;
	username?: string;
	avatar_url?: string;
	is_self: boolean;
	typing?: boolean;
	last_seen: number;
};
export type RemoteCursor = {
	session_id: string;
	actor_id: string;
	name: string;
	color: string;
	/** The wire anchor: element path plus the fraction of its box. */
	target: {
		el: string;
		x: number;
		y: number;
	};
	last_update: number;
	/** A few words they are typing or just typed at their cursor, and when the
	 *  last of them arrived: they show for a moment, then go. */
	chat?: string;
	chat_at?: number;
};
export type CommentAuthor = {
	id: string;
	kind: "user" | "anonymous";
	external_id: string | null;
	anonymous_id: string | null;
	display_name: string;
	profile: {
		name?: string;
		username?: string;
		handle?: string;
		color?: string;
		avatar?: string;
		avatar_url?: string;
		image_url?: string;
	};
};
export type CommentReply = {
	id: string;
	thread_id: string;
	room_id: string;
	actor_id: string;
	author: CommentAuthor | null;
	body: string | null;
	mentions: string[];
	metadata: Record<string, unknown>;
	deleted_at: string | null;
	inserted_at: string;
	updated_at: string;
};
export type CommentReactionEntry = {
	count: number;
	actors: Record<string, CommentAuthor>;
};
export type AnchorLike = Anchor | Record<string, unknown> | null;
export type CommentThread = {
	id: string;
	room_id: string;
	actor_id: string;
	author: CommentAuthor | null;
	anchor: AnchorLike;
	body: string | null;
	status: "open" | "archived";
	mentions: string[];
	/** Attachments ride here as `metadata.attachments` — never hydrated apart. */
	metadata: Record<string, unknown>;
	reactions: Record<string, CommentReactionEntry>;
	unread: boolean;
	mentioned: boolean;
	comments: CommentReply[];
	inserted_at: string;
};
export type ThreadState = CommentThread & {
	resolve: ResolveResult | null;
};
/**
 * Someone this viewer can @mention: a Spacefast user on the Space's team.
 * `name` is what the picker shows and what the body carries as `@name`.
 */
export type MentionCandidate = {
	userId: string;
	name: string;
	avatarUrl: string | null;
};
export type PinRect = {
	thread_id: string;
	x: number;
	y: number;
	visible: boolean;
	resolve: ResolveResult;
};
/** A user-chosen file riding a draft: uploaded eagerly, referenced at send. */
export type DraftAttachment = {
	/** Client-only identity for the chip; the storage id arrives on success. */
	localId: string;
	name: string;
	contentType: string;
	size: number;
	kind: "image" | "file";
	status: "pending" | "done" | "failed";
	id: string | null;
	/** Object URL for image thumbs; revoked when the chip is removed. */
	previewUrl: string | null;
	failure: AttachmentUploadFailure | null;
};
/** Which composer a draft attachment belongs to. */
export type DraftScope = {
	kind: "new";
} | {
	kind: "reply";
	threadId: string;
};
/** A point on the page, in document pixels. */
export type PagePoint = {
	x: number;
	y: number;
};
/** The drawing a comment is being written for, and the page spot it hangs from. */
export type DraftDrawing = {
	sketch: Sketch;
	spot: PagePoint;
};
export type NewThreadDraft = {
	/** Which comment this is. Re-aiming it or editing its words keeps it; a new
	 *  comment gets its own. */
	id: number;
	/** A page comment carries the explicit page anchor: every thread names where it is. */
	anchor: Anchor | PageAnchor;
	target: EvidenceTarget;
	body: string;
	attachments: DraftAttachment[];
	drawing: DraftDrawing | null;
};
export type ReplyDraft = {
	body: string;
	attachments: DraftAttachment[];
};
export type DraftsState = {
	newThread: NewThreadDraft | null;
	newThreadError: string | null;
	replies: Map<string, ReplyDraft>;
	/** Strokes drawn and not yet posted, in page coordinates. They outlive the
	 *  pen while their comment is written, so backing out hands them back. */
	ink: PagePoint[][];
};
export type OrbEdge = "left" | "right" | "top" | "bottom";
/**
 * Free orb placement (v2, supersedes D5 corner snap): anchored to the nearest
 * edge so it survives resizes sanely. `along` is the orb center's fraction
 * along that edge; `inset` is px from the edge (ORB_FLUSH_INSET = magnetized
 * flush margin; anything larger is a free position the user chose).
 */
export type OrbPlacement = {
	edge: OrbEdge;
	along: number;
	inset: number;
};
export type Toast = {
	id: number;
	text: string;
	kind: "info" | "success" | "error";
	action?: {
		label: string;
		run: () => void;
	};
};
/** Which drawer the expanded layer is showing, if any. Telemetry is the live
 * roster, shown when the ticket grants telemetry_read. */
export type CollabPanel = "comments" | "activity" | "telemetry";
/** Whether the comments list is scoped to this page or the whole space. */
export type CollabPageFilter = "page" | "all";
export type UiState = {
	orb: OrbPlacement;
	openThreadId: string | null;
	/** The pin being pointed at or focused: its drawing shows, as a preview. */
	peekThreadId: string | null;
	/** Others' cursors (and your own label) show even with the comments shut. */
	cursorsAlways: boolean;
	/** A new comment carries a screenshot of what it is about, where the space
	 *  keeps them. On unless this visitor turned it off. */
	screenshots: boolean;
	toasts: Toast[];
	repinThreadId: string | null;
	/** The comments are open: the orb's tools are out and the pins are on the page. Shut is the rest state. */
	expanded: boolean;
	panel: CollabPanel | null;
	/** The thread the panel is showing, as opposed to the floating card's. */
	panelThreadId: string | null;
	pageFilter: CollabPageFilter;
};
/** The adaptive engine's current verdict — one appearance for the whole layer:
 *  every surface the overlay paints dresses from this one answer. */
export type CollabAppearance = {
	mode: "dark" | "light";
};
export type CollabState = {
	/** The space's manifest — the one config there is. */
	config: CollabManifest | null;
	/** Null until the adaptive engine has read the page's background. */
	appearance: CollabAppearance | null;
	connection: ConnectionStatus;
	session: CollabSession | null;
	identity: CollabIdentity;
	roster: Map<string, PresenceActor>;
	cursors: Map<string, RemoteCursor>;
	/** The Space-wide live roster while an editor watches it; null otherwise. */
	live: LivePresence | null;
	/** What the Telemetry HUD renders: the live roster folded into one frame
	 *  today, an analytics frame tomorrow. The HUD reads this and nothing else. */
	frame: HudFrame | null;
	/** The Space's analytics profile, when it has one. Decides which views the
	 *  Telemetry drawer shows; null means show the generic set. */
	analyticsProfile: HudProfileLike | null;
	/** Who this session may @mention, from the ticket. Null unless the session is
	 *  an editor: a share-link visitor never learns who is on the team. */
	mentionable: readonly MentionCandidate[] | null;
	/** Page views over the last 7 days and the 7 before, while the drawer is
	 *  open and the session holds a telemetry ticket; null otherwise. */
	insights: {
		pageviews: number;
		previous: number;
	} | null;
	/** The analytics query API's answers for one range, while the drawer shows
	 *  Trends; `answers` is null when the service couldn't be reached. */
	trends: {
		window: TrendsWindow;
		answers: TrendsAnswer[] | null;
	} | null;
	threads: Map<string, ThreadState>;
	/** The threads this visitor made since the page loaded, oldest first. While
	 *  they comment, these are the pins that stay: their own trail, without
	 *  everyone else's comments in the way of what they are pointing at. */
	sessionThreadIds: string[];
	pinRects: Map<string, PinRect>;
	strokes: Map<string, DrawingStrokeState>;
	remoteDrawings: Map<string, RemoteDrawingPreview>;
	unread_count: number;
	mention_unread_count: number;
	tool: CollabTool;
	mode: CollabMode;
	drawStyle: DrawStyle;
	drafts: DraftsState;
	ui: UiState;
};
export type CollabActions = {
	openThread(id: string | null): void;
	createThread(anchor: Anchor | PageAnchor, body: string, attachments?: CommentAttachment[], mentions?: readonly string[], drawing?: Sketch | null): Promise<string>;
	/** `mentions` are Spacefast user ids; Cast stores them as `user:<id>`. */
	reply(threadId: string, body: string, mentions: readonly string[]): Promise<void>;
	react(threadId: string, emoji: string): void;
	archive(threadId: string): void;
	unarchive(threadId: string): void;
	deleteComment(threadId: string, commentId: string): void;
	markRead(threadId: string): void;
	reanchor(threadId: string, anchor: Anchor): void;
	/** Attach best-effort screenshot evidence to a created thread post-hoc via
	 *  `thread:attach` — the capture races the create, so this lane exists. */
	attachEvidence(threadId: string, attachment: CommentAttachment): void;
	/** Stage files onto a composer draft and start their uploads eagerly. */
	addDraftAttachments(scope: DraftScope, files: File[]): void;
	removeDraftAttachment(scope: DraftScope, localId: string): void;
	beginRepin(threadId: string): void;
	setTool(tool: CollabTool): void;
	commitAnchor(anchor: Anchor, target: EvidenceTarget): void;
	/** Point the comment being written at something else, keeping its words. */
	retargetComposer(anchor: Anchor, target: EvidenceTarget): void;
	commitTextSelection(anchor: Anchor, target: Extract<EvidenceTarget, {
		kind: "range";
	}>): void;
	commitDrawing(anchor: Anchor | PageAnchor, target: Extract<EvidenceTarget, {
		kind: "region";
	}>, drawing: DraftDrawing): void;
	setInk(ink: PagePoint[][]): void;
	/** Create the thread, then reset the FSM. Rejects while preserving the draft
	 *  when Cast refuses the write. */
	submitComposer(mentions: readonly string[]): Promise<string | null>;
	cancelComposer(): void;
	/** End a commenting run from wherever it is: the picker or the pen is put
	 *  down, and a comment still being written is discarded with it. */
	stopCommenting(): void;
	setIdentityName(name: string): void;
	setIdentityGravatarHash(hash: string | null): void;
	setExpanded(expanded: boolean): void;
	/** Show others' cursors all the time, or only with the comments open. */
	setCursorsAlways(always: boolean): void;
	/** Attach a screenshot to new comments, or not. Kept across visits. */
	setScreenshots(on: boolean): void;
	setPanel(panel: CollabPanel | null, threadId?: string | null): void;
	setPageFilter(filter: CollabPageFilter): void;
	setOrbPlacement(placement: OrbPlacement): void;
	toast(t: Omit<Toast, "id" | "kind"> & {
		kind?: Toast["kind"];
	}): void;
};
export type SendableDurableEvent = DurableEvent;
export type CollabActionEffects = {
	sendDurable(event: SendableDurableEvent, payload: Record<string, unknown>): Promise<unknown>;
	setIdentityName?(name: string): CollabIdentity;
	setIdentityGravatarHash?(hash: string | null): CollabIdentity;
};
export type CollabStore = {
	getState(): Readonly<CollabState>;
	subscribe<T>(selector: (state: CollabState) => T, cb: (value: T, prev: T) => void): () => void;
	actions: CollabActions;
	setState(patch: Partial<CollabState> | ((state: CollabState) => Partial<CollabState>)): void;
	bindEffects(effects: CollabActionEffects): void;
};
declare const DURABLE_EVENTS: readonly [
	"thread:create",
	"comment:create",
	"thread:update",
	"comment:update",
	"comment:delete",
	"thread:read",
	"thread:react",
	"thread:attach",
	"thread:archive",
	"thread:unarchive",
	"drawing:stroke",
	"drawing:delete",
	"state:patch",
	"lock:acquire",
	"lock:release"
];
export type DurableEvent = (typeof DURABLE_EVENTS)[number];
declare const EPHEMERAL_EVENTS: readonly [
	"presence:update",
	"cursor:update",
	"cursor:chat",
	"drawing:preview",
	"live:watch",
	"live:unwatch"
];
export type EphemeralEvent = (typeof EPHEMERAL_EVENTS)[number];
/** The Cast binding, plus the HTTP origin the same host answers REST on. */
export type CollabCast = {
	wsUrl: string;
	resourceKey: string;
	/** `wsUrl`'s origin over http(s). Derived, never served. */
	apiBase: string;
};
/**
 * The manifest as a running collab uses it: page identity resolved from the
 * document, and `cast` / `ticketUrl` narrowed to the non-null values that made
 * this collab creatable in the first place.
 */
export type PlatformCollabManifest = Omit<CollabManifest, "cast" | "ticketUrl"> & {
	cast: CollabCast;
	ticketUrl: string;
	/** Normalized: the room this page's Comments live in. */
	pagePath: string;
	/**
	 * The document's raw `location.pathname`, unnormalized — what the ticket lane
	 * is handed so the control plane's own single decode lands on `pagePath`.
	 * Sending the normalized form instead decodes it a second time and mints a
	 * ticket for a different room (`/foo%2520bar` is the case that catches it).
	 */
	requestPath: string;
	sourceUrl: string;
	/** Client-derived (the manifest is one document per space, not per page). */
	roomKey: string;
};
export type DurableAck = Record<string, unknown>;
export type CollabTransport = {
	connect(signal?: AbortSignal): Promise<void>;
	/** Stops reconnecting and rejects every queued durable write. */
	disconnect(): void;
	sendDurable(event: SendableDurableEvent, payload: Record<string, unknown>): Promise<DurableAck>;
	sendEphemeral(event: EphemeralEvent, payload: Record<string, unknown>): Promise<Record<string, unknown>>;
	updateIdentity(identity: CollabIdentity): void;
};
/**
 * The page path this document is a Comments room for.
 *
 * Mirrors `normalizeAccessScopePath` (common/contracts/access.ts), which is
 * what the control plane runs on the path the ticket lane is given — ONE
 * percent-decode, applied to the raw `location.pathname`. That is what lets the
 * room key be derived here instead of shipped per page: one manifest serves
 * every page of the Space and cannot know which one it is on.
 *
 * The corollary binds the ticket request: it must send the RAW path, not this
 * one. Feeding this output back through the server's decode is not a no-op —
 * `/foo%2520bar` normalizes here to `/foo%20bar`, which the server would then
 * decode a second time into `/foo bar` and mint a ticket for a different room
 * than the one this SDK joined.
 */
export declare function canonicalPagePath(pathname: string): string;
/**
 * The Cast room this page's Comments live in — `commentRouteRoomKey` and
 * `draftCommentRoomKey` (control-plane comments/rooms.ts), mirrored exactly.
 *
 * Which of the two applies is not a property of the page but of the artifact
 * being served: threads on the live artifact are the Space's permanent record,
 * threads on any other are that artifact's draft. The ticket lane decides it
 * the same way, so deriving it differently here is a join Cast refuses.
 */
export declare function commentRoomKey(spaceId: string, pagePath: string, artifact: {
	id: string | null;
	current: boolean;
}): string;
export type CollabOptions = {
	manifest: CollabManifest;
	/** Defaults to `location.pathname`; tests pass one. */
	pagePath?: string;
	/** Where interaction shields (picker/draw) and floating chrome mount.
	 *  Defaults to a shadow host the core creates on first read. */
	host?: HTMLElement;
	/** The ticket lane refused this visitor outright. Comments are not available
	 *  to them at all, so whatever is painted goes away — this is not an outage
	 *  and there is nothing to retry. */
	onRefusal?: (code: string) => void;
	/** A returning account's way back in, asked at most once, when the first
	 *  ticket answer would make this browser a visitor. True when the page is
	 *  leaving to renew its session: the collab goes quiet instead of refusing.
	 *  The default UI passes `startAccountRenewal`; a custom UI opts in. */
	renewAccount?: () => boolean;
};
export type Collab = {
	readonly store: CollabStore;
	readonly manifest: PlatformCollabManifest;
	readonly theme: CollabTheme;
	readonly identity: CollabIdentity;
	/** Where a UI layer mounts. Created on first read, removed by `destroy()`. */
	readonly host: HTMLElement;
	/** The current collaboration bearer, for the authenticated HTTP lanes. */
	getToken(): string | null;
	/** The insights service's read-only ticket and origin, for the Telemetry
	 *  HUD's history. Null unless this session is an editor on a deployment that
	 *  runs insights. */
	getTelemetryTicket(): {
		token: string;
		url: string;
	} | null;
	/** Beacon's read-only ticket for this Space's live site, where to spend it,
	 *  and the site, for the Telemetry HUD's Now tab. Null unless this session
	 *  is an editor on a deployment that runs Beacon. */
	getLiveTicket(): {
		token: string;
		url: string;
		site: string;
	} | null;
	/** Hear each mint that replaces the session's tickets, and access lost: a
	 *  ticket read once goes stale, and this says when to read it again. */
	onTicketsChange(cb: () => void): () => void;
	/** Whispers: presence, cursors, drawing previews. Durable writes go through
	 *  `store.actions`, which is the only path that can be replayed. */
	sendEphemeral: CollabTransport["sendEphemeral"];
	/** Expand the UI layer. */
	open(): Promise<void>;
	/** Collapse it back into the orb. */
	close(): Promise<void>;
	destroy(): void;
};
/**
 * A live collaboration over one page.
 *
 * Connecting is not a thing the visitor asks for: the orb wears presence and
 * the unread count at rest, so the room is joined as soon as this exists.
 * `open()`/`close()` are the UI layer's expansion, nothing more — which is why
 * they are synchronous underneath and still return promises: the public
 * `window.Spacefast.collab` contract is async and callers await it.
 */
export declare function createCollab(options: CollabOptions): Collab;
export declare function fetchCollabManifest(url?: string): Promise<CollabManifest>;

declare namespace JSONSchema$1 {
	export { ArraySchema, BaseSchema, BooleanSchema, IntegerSchema, JSONSchema, NullSchema, NumberSchema, ObjectSchema, Schema, SchemaType, StringSchema, _JSONSchema };
}
declare namespace util {
	export { AnyFunc, AssertEqual, AssertExtends, AssertNotEqual, BIGINT_FORMAT_RANGES, BuiltIn, CONSTANT_CATCH, Class, CleanKey, Constructor, EmptyObject, EmptyToNever, EnumLike, EnumValue, Exactly, Extend, ExtractIndexSignature, Flatten, FromCleanMap, HasLength, HasSize, HashAlgorithm, HashEncoding, HashFormat, IPVersion, Identity, InexactPartial, IsAny, IsProp, JSONType, JWTAlgorithm, KeyOf, Keys, KeysArray, KeysEnum, Literal, LiteralArray, LoosePartial, MakePartial, MakeReadonly, MakeRequired, Mapped, Mask, MaybeAsync, MimeTypes, NUMBER_FORMAT_RANGES, NoNever, NoNeverKeys, NoUndefined, Normalize, Numeric, Omit$1 as Omit, OmitIndexSignature, OmitKeys, ParsedTypes, Prettify, Primitive, PrimitiveArray, PrimitiveSet, PropValues, ProtoOf, SafeParseError, SafeParseResult, SafeParseSuccess, SchemaClass, SomeObject, ToCleanMap, ToEnum, TupleItems, Whatever, Writeable, aborted, allowsEval, assert, assertEqual, assertIs, assertNever, assertNotEqual, assignProp, attachSchema, base64ToUint8Array, base64urlToUint8Array, cached, captureStackTrace, cleanEnum, cleanRegex, clone, cloneDef, codePointLength, constantCatch, createTransparentProxy, defineLazy, defineLazyInternal, esc, escapeRegex, explicitlyAborted, extend, finalizeIssue, floatSafeRemainder, getElementAtPath, getEnumValues, getLengthableOrigin, getParsedType, getSizableOrigin, hexToUint8Array, hide, installLazyProp, isObject, isPlainObject, issue, joinValues, jsonStringifyReplacer, members, merge, mergeDefs, normalizeParams, nullish, numKeys, objectClone, omit, optionalKeys, own, parsedType, partial, pick, prefixIssues, primitiveTypes, promiseAllObject, propertyKeyTypes, randomString, required, safeExtend, shallowClone, slugify, stringifyPrimitive, toZod, uint8ArrayToBase64, uint8ArrayToBase64url, uint8ArrayToHex, unwrapMessage };
}
declare namespace coerce {
	export { ZodCoercedBigInt, ZodCoercedBoolean, ZodCoercedDate, ZodCoercedNumber, ZodCoercedString, bigint$2 as bigint, boolean$2 as boolean, date$3 as date, number$2 as number, string$2 as string };
}
declare namespace schemas {
	export { $DiscriminatedOption, $DiscriminatorValue, $InferEnumInput, $InferEnumOutput, $InferInnerFunctionType, $InferInnerFunctionTypeAsync, $InferObjectInput, $InferObjectOutput, $InferOuterFunctionType, $InferOuterFunctionTypeAsync, $InferTupleInputType, $InferTupleOutputType, $InferUnionInput, $InferUnionOutput, $InferZodRecordInput, $InferZodRecordOutput, $PartsToTemplateLiteral, $ZodAny, $ZodAnyDef, $ZodAnyInternals, $ZodArray, $ZodArrayDef, $ZodArrayInternals, $ZodBase64, $ZodBase64Def, $ZodBase64Internals, $ZodBase64URL, $ZodBase64URLDef, $ZodBase64URLInternals, $ZodBigInt, $ZodBigIntDef, $ZodBigIntFormat, $ZodBigIntFormatDef, $ZodBigIntFormatInternals, $ZodBigIntInternals, $ZodBoolean, $ZodBooleanDef, $ZodBooleanInternals, $ZodCIDRv4, $ZodCIDRv4Def, $ZodCIDRv4Internals, $ZodCIDRv6, $ZodCIDRv6Def, $ZodCIDRv6Internals, $ZodCUID, $ZodCUID2, $ZodCUID2Def, $ZodCUID2Internals, $ZodCUIDDef, $ZodCUIDInternals, $ZodCatch, $ZodCatchCtx, $ZodCatchDef, $ZodCatchInternals, $ZodCodec, $ZodCodecDef, $ZodCodecInternals, $ZodCreditCard, $ZodCreditCardDef, $ZodCreditCardInternals, $ZodCustom, $ZodCustomDef, $ZodCustomInternals, $ZodCustomStringFormat, $ZodCustomStringFormatDef, $ZodCustomStringFormatInternals, $ZodDate, $ZodDateDef, $ZodDateInternals, $ZodDefault, $ZodDefaultDef, $ZodDefaultInternals, $ZodDiscriminatedUnion, $ZodDiscriminatedUnionDef, $ZodDiscriminatedUnionInternals, $ZodE164, $ZodE164Def, $ZodE164Internals, $ZodEmail, $ZodEmailDef, $ZodEmailInternals, $ZodEmoji, $ZodEmojiDef, $ZodEmojiInternals, $ZodEnum, $ZodEnumDef, $ZodEnumInternals, $ZodExactOptional, $ZodExactOptionalDef, $ZodExactOptionalInternals, $ZodFile, $ZodFileDef, $ZodFileInternals, $ZodFunction, $ZodFunctionArgs, $ZodFunctionDef, $ZodFunctionIn, $ZodFunctionInternals, $ZodFunctionOut, $ZodFunctionParams, $ZodGUID, $ZodGUIDDef, $ZodGUIDInternals, $ZodIPv4, $ZodIPv4Def, $ZodIPv4Internals, $ZodIPv6, $ZodIPv6Def, $ZodIPv6Internals, $ZodISODate, $ZodISODateDef, $ZodISODateInternals, $ZodISODateTime, $ZodISODateTimeDef, $ZodISODateTimeInternals, $ZodISODuration, $ZodISODurationDef, $ZodISODurationInternals, $ZodISOTime, $ZodISOTimeDef, $ZodISOTimeInternals, $ZodIntersection, $ZodIntersectionDef, $ZodIntersectionInternals, $ZodJWT, $ZodJWTDef, $ZodJWTInternals, $ZodKSUID, $ZodKSUIDDef, $ZodKSUIDInternals, $ZodLazy, $ZodLazyDef, $ZodLazyInternals, $ZodLiteral, $ZodLiteralDef, $ZodLiteralInternals, $ZodLooseShape, $ZodMAC, $ZodMACDef, $ZodMACInternals, $ZodMap, $ZodMapDef, $ZodMapInternals, $ZodMemoizer, $ZodNaN, $ZodNaNDef, $ZodNaNInternals, $ZodNanoID, $ZodNanoIDDef, $ZodNanoIDInternals, $ZodNever, $ZodNeverDef, $ZodNeverInternals, $ZodNonOptional, $ZodNonOptionalDef, $ZodNonOptionalInternals, $ZodNull, $ZodNullDef, $ZodNullInternals, $ZodNullable, $ZodNullableDef, $ZodNullableInternals, $ZodNumber, $ZodNumberDef, $ZodNumberFormat, $ZodNumberFormatDef, $ZodNumberFormatInternals, $ZodNumberInternals, $ZodObject, $ZodObjectConfig, $ZodObjectDef, $ZodObjectInternals, $ZodObjectJIT, $ZodOptional, $ZodOptionalDef, $ZodOptionalInternals, $ZodPipe, $ZodPipeDef, $ZodPipeInternals, $ZodPrefault, $ZodPrefaultDef, $ZodPrefaultInternals, $ZodPreprocess, $ZodPreprocessDef, $ZodPreprocessInternals, $ZodPromise, $ZodPromiseDef, $ZodPromiseInternals, $ZodReadonly, $ZodReadonlyDef, $ZodReadonlyInternals, $ZodRecord, $ZodRecordDef, $ZodRecordInternals, $ZodRecordKey, $ZodSet, $ZodSetDef, $ZodSetInternals, $ZodShape, $ZodStandardSchema, $ZodString, $ZodStringDef, $ZodStringFormat, $ZodStringFormatDef, $ZodStringFormatInternals, $ZodStringFormatTypes, $ZodStringInternals, $ZodSuccess, $ZodSuccessDef, $ZodSuccessInternals, $ZodSymbol, $ZodSymbolDef, $ZodSymbolInternals, $ZodTemplateLiteral, $ZodTemplateLiteralDef, $ZodTemplateLiteralInternals, $ZodTemplateLiteralPart, $ZodTransform, $ZodTransformDef, $ZodTransformInternals, $ZodTuple, $ZodTupleDef, $ZodTupleInternals, $ZodType, $ZodTypeDef, $ZodTypeInternals, $ZodTypes, $ZodULID, $ZodULIDDef, $ZodULIDInternals, $ZodURL, $ZodURLDef, $ZodURLInternals, $ZodUUID, $ZodUUIDDef, $ZodUUIDInternals, $ZodUndefined, $ZodUndefinedDef, $ZodUndefinedInternals, $ZodUnion, $ZodUnionDef, $ZodUnionInternals, $ZodUnknown, $ZodUnknownDef, $ZodUnknownInternals, $ZodVoid, $ZodVoidDef, $ZodVoidInternals, $ZodXID, $ZodXIDDef, $ZodXIDInternals, $ZodXor, $ZodXorInternals, $catchall, $loose, $partial, $strict, $strip, CheckFn, ConcatenateTupleOfStrings, ConvertPartsToStringTuple, File$1 as File, ParseContext, ParseContextInternal, ParsePayload, SomeType, ToTemplateLiteral, URL_BAD_FORMAT, URL_UNPARSEABLE, _$ZodType, _$ZodTypeInternals, clone, getDiscriminatedOption, isValidBase64, isValidBase64URL, isValidCIDRv6, isValidCreditCard, isValidIPv6, isValidJWT, mergeValues, parseURLObject, standardProps, stripTabAndNewline, urlHostnameOk, urlProtocolOk };
}
declare namespace regexes {
	export { _null as null, _undefined as undefined, base64, base64url, bigint, boolean, browserEmail, cidrv4, cidrv6, creditCard, cuid, cuid2, date, datetime, domain, duration, e164, email, emoji, extendedDuration, guid, hex, hostname, html5Email, httpProtocol, idnEmail, integer, ipv4, ipv6, ksuid, lowercase, mac, md5_base64, md5_base64url, md5_hex, nanoid, nanoidOfLength, number, rfc5322Email, sha1_base64, sha1_base64url, sha1_hex, sha256_base64, sha256_base64url, sha256_hex, sha384_base64, sha384_base64url, sha384_hex, sha512_base64, sha512_base64url, sha512_hex, string, time, ulid, unicodeEmail, uppercase, uuid, uuid4, uuid6, uuid7, xid };
}
declare namespace locales {
	export { _default as ar, _default$1 as az, _default$10 as el, _default$11 as en, _default$12 as eo, _default$13 as es, _default$14 as fa, _default$15 as fi, _default$16 as fr, _default$17 as frCA, _default$18 as gu, _default$19 as he, _default$2 as be, _default$20 as hi, _default$21 as hr, _default$22 as hu, _default$23 as hy, _default$24 as id, _default$25 as is, _default$26 as it, _default$27 as ja, _default$28 as ka, _default$29 as kh, _default$3 as bg, _default$30 as km, _default$31 as kn, _default$32 as ko, _default$33 as lt, _default$34 as mk, _default$35 as ms, _default$36 as ne, _default$37 as nl, _default$38 as nn, _default$39 as no, _default$4 as bn, _default$40 as ota, _default$41 as ps, _default$42 as pl, _default$43 as pt, _default$44 as ptBR, _default$45 as ro, _default$46 as ru, _default$47 as sk, _default$48 as sl, _default$49 as sv, _default$5 as ca, _default$50 as ta, _default$51 as th, _default$52 as tk, _default$53 as tr, _default$54 as ua, _default$55 as uk, _default$56 as ur, _default$57 as uz, _default$58 as vi, _default$59 as zhCN, _default$6 as ckb, _default$60 as zhTW, _default$61 as yo, _default$7 as cs, _default$8 as da, _default$9 as de };
}
declare namespace schemas$1 {
	export { SafeExtendShape, ZodAny, ZodArray, ZodBase64, ZodBase64URL, ZodBigInt, ZodBigIntFormat, ZodBoolean, ZodCIDRv4, ZodCIDRv6, ZodCUID, ZodCUID2, ZodCatch, ZodCodec, ZodCreditCard, ZodCustom, ZodCustomStringFormat, ZodDate, ZodDefault, ZodDiscriminatedUnion, ZodE164, ZodEmail, ZodEmoji, ZodEnum, ZodExactOptional, ZodFile, ZodFloat32, ZodFloat64, ZodFunction, ZodGUID, ZodIPv4, ZodIPv6, ZodISODate, ZodISODateTime, ZodISODuration, ZodISOTime, ZodInt, ZodInt32, ZodInt64, ZodIntersection, ZodJSONSchema, ZodJSONSchemaInternals, ZodJWT, ZodKSUID, ZodLazy, ZodLiteral, ZodMAC, ZodMap, ZodNaN, ZodNanoID, ZodNever, ZodNonOptional, ZodNull, ZodNullable, ZodNumber, ZodNumberFormat, ZodObject, ZodOptional, ZodPipe, ZodPrefault, ZodPreprocess, ZodPromise, ZodReadonly, ZodRecord, ZodSet, ZodStandardSchemaWithJSON$1 as ZodStandardSchemaWithJSON, ZodString, ZodStringFormat, ZodSuccess, ZodSymbol, ZodTemplateLiteral, ZodTransform, ZodTuple, ZodType, ZodUInt32, ZodUInt64, ZodULID, ZodURL, ZodUUID, ZodUndefined, ZodUnion, ZodUnknown, ZodVoid, ZodXID, ZodXor, _ZodBigInt, _ZodBoolean, _ZodDate, _ZodNumber, _ZodString, _ZodType, _catch$1 as catch, _default$63 as _default, _enum$1 as enum, _function, _function as function, _instanceof as instanceof, _null$2 as null, _undefined$2 as undefined, _void$1 as void, any, array, base64$1 as base64, base64url$1 as base64url, bigint$1 as bigint, boolean$1 as boolean, check, cidrv4$1 as cidrv4, cidrv6$1 as cidrv6, codec, creditCard$1 as creditCard, cuid$1 as cuid, cuid2$1 as cuid2, custom, date$1 as date, describe$1 as describe, discriminatedUnion, e164$1 as e164, email$1 as email, emoji$1 as emoji, exactOptional, file, float32, float64, guid$1 as guid, hash, hex$1 as hex, hostname$1 as hostname, httpUrl, int, int32, int64, intersection, invertCodec, ipv4$1 as ipv4, ipv6$1 as ipv6, json, jwt, keyof, ksuid$1 as ksuid, lazy, literal, looseObject, looseRecord, mac$1 as mac, map, meta$1 as meta, nan, nanoid$1 as nanoid, nativeEnum, never, nonoptional, nullable, nullish$1 as nullish, number$1 as number, object, optional, partialRecord, pipe, prefault, preprocess, promise, readonly, record, refine, set, strictObject, string$1 as string, stringFormat, stringbool, success, superRefine, symbol, templateLiteral, transform, tuple, uint32, uint64, ulid$1 as ulid, union, unknown, url, uuid$1 as uuid, uuidv4, uuidv6, uuidv7, xid$1 as xid, xor };
}
declare namespace core {
	export { $Decode, $DecodeAsync, $DiscriminatedOption, $DiscriminatorValue, $Encode, $EncodeAsync, $InferEnumInput, $InferEnumOutput, $InferInnerFunctionType, $InferInnerFunctionTypeAsync, $InferObjectInput, $InferObjectOutput, $InferOuterFunctionType, $InferOuterFunctionTypeAsync, $InferTupleInputType, $InferTupleOutputType, $InferUnionInput, $InferUnionOutput, $InferZodRecordInput, $InferZodRecordOutput, $Parse, $ParseAsync, $PartsToTemplateLiteral, $RefinementCtx, $SafeDecode, $SafeDecodeAsync, $SafeEncode, $SafeEncodeAsync, $SafeParse, $SafeParseAsync, $Validate, $ValidateAsync, $ZodAny, $ZodAnyDef, $ZodAnyInternals, $ZodAnyParams, $ZodArray, $ZodArrayDef, $ZodArrayInternals, $ZodArrayParams, $ZodAsyncError, $ZodBase64, $ZodBase64Def, $ZodBase64Internals, $ZodBase64Params, $ZodBase64URL, $ZodBase64URLDef, $ZodBase64URLInternals, $ZodBase64URLParams, $ZodBigInt, $ZodBigIntDef, $ZodBigIntFormat, $ZodBigIntFormatDef, $ZodBigIntFormatInternals, $ZodBigIntFormatParams, $ZodBigIntFormats, $ZodBigIntInternals, $ZodBigIntParams, $ZodBoolean, $ZodBooleanDef, $ZodBooleanInternals, $ZodBooleanParams, $ZodBranded, $ZodCIDRv4, $ZodCIDRv4Def, $ZodCIDRv4Internals, $ZodCIDRv4Params, $ZodCIDRv6, $ZodCIDRv6Def, $ZodCIDRv6Internals, $ZodCIDRv6Params, $ZodCUID, $ZodCUID2, $ZodCUID2Def, $ZodCUID2Internals, $ZodCUID2Params, $ZodCUIDDef, $ZodCUIDInternals, $ZodCUIDParams, $ZodCatch, $ZodCatchCtx, $ZodCatchDef, $ZodCatchInternals, $ZodCatchParams, $ZodCheck, $ZodCheckBase64Params, $ZodCheckBase64URLParams, $ZodCheckBigIntFormat, $ZodCheckBigIntFormatDef, $ZodCheckBigIntFormatInternals, $ZodCheckBigIntFormatParams, $ZodCheckCIDRv4Params, $ZodCheckCIDRv6Params, $ZodCheckCUID2Params, $ZodCheckCUIDParams, $ZodCheckCreditCardParams, $ZodCheckDef, $ZodCheckE164Params, $ZodCheckEmailParams, $ZodCheckEmojiParams, $ZodCheckEndsWith, $ZodCheckEndsWithDef, $ZodCheckEndsWithInternals, $ZodCheckEndsWithParams, $ZodCheckGUIDParams, $ZodCheckGreaterThan, $ZodCheckGreaterThanDef, $ZodCheckGreaterThanInternals, $ZodCheckGreaterThanParams, $ZodCheckIPv4Params, $ZodCheckIPv6Params, $ZodCheckISODateParams, $ZodCheckISODateTimeParams, $ZodCheckISODurationParams, $ZodCheckISOTimeParams, $ZodCheckIncludes, $ZodCheckIncludesDef, $ZodCheckIncludesInternals, $ZodCheckIncludesParams, $ZodCheckInternals, $ZodCheckJWTParams, $ZodCheckKSUIDParams, $ZodCheckLengthEquals, $ZodCheckLengthEqualsDef, $ZodCheckLengthEqualsInternals, $ZodCheckLengthEqualsParams, $ZodCheckLessThan, $ZodCheckLessThanDef, $ZodCheckLessThanInternals, $ZodCheckLessThanParams, $ZodCheckLowerCase, $ZodCheckLowerCaseDef, $ZodCheckLowerCaseInternals, $ZodCheckLowerCaseParams, $ZodCheckMACParams, $ZodCheckMaxLength, $ZodCheckMaxLengthDef, $ZodCheckMaxLengthInternals, $ZodCheckMaxLengthParams, $ZodCheckMaxSize, $ZodCheckMaxSizeDef, $ZodCheckMaxSizeInternals, $ZodCheckMaxSizeParams, $ZodCheckMimeType, $ZodCheckMimeTypeDef, $ZodCheckMimeTypeInternals, $ZodCheckMimeTypeParams, $ZodCheckMinLength, $ZodCheckMinLengthDef, $ZodCheckMinLengthInternals, $ZodCheckMinLengthParams, $ZodCheckMinSize, $ZodCheckMinSizeDef, $ZodCheckMinSizeInternals, $ZodCheckMinSizeParams, $ZodCheckMultipleOf, $ZodCheckMultipleOfDef, $ZodCheckMultipleOfInternals, $ZodCheckMultipleOfParams, $ZodCheckNanoIDParams, $ZodCheckNumberFormat, $ZodCheckNumberFormatDef, $ZodCheckNumberFormatInternals, $ZodCheckNumberFormatParams, $ZodCheckOverwrite, $ZodCheckOverwriteDef, $ZodCheckOverwriteInternals, $ZodCheckProperty, $ZodCheckPropertyDef, $ZodCheckPropertyInternals, $ZodCheckPropertyParams, $ZodCheckRegex, $ZodCheckRegexDef, $ZodCheckRegexInternals, $ZodCheckRegexParams, $ZodCheckSizeEquals, $ZodCheckSizeEqualsDef, $ZodCheckSizeEqualsInternals, $ZodCheckSizeEqualsParams, $ZodCheckStartsWith, $ZodCheckStartsWithDef, $ZodCheckStartsWithInternals, $ZodCheckStartsWithParams, $ZodCheckStringFormat, $ZodCheckStringFormatDef, $ZodCheckStringFormatInternals, $ZodCheckStringFormatParams, $ZodCheckULIDParams, $ZodCheckURLParams, $ZodCheckUUIDParams, $ZodCheckUUIDv4Params, $ZodCheckUUIDv6Params, $ZodCheckUUIDv7Params, $ZodCheckUpperCase, $ZodCheckUpperCaseDef, $ZodCheckUpperCaseInternals, $ZodCheckUpperCaseParams, $ZodCheckXIDParams, $ZodChecks, $ZodCodec, $ZodCodecDef, $ZodCodecInternals, $ZodConfig, $ZodCreditCard, $ZodCreditCardDef, $ZodCreditCardInternals, $ZodCreditCardParams, $ZodCustom, $ZodCustomDef, $ZodCustomInternals, $ZodCustomParams, $ZodCustomStringFormat, $ZodCustomStringFormatDef, $ZodCustomStringFormatInternals, $ZodCyclicError, $ZodDate, $ZodDateDef, $ZodDateInternals, $ZodDateParams, $ZodDefault, $ZodDefaultDef, $ZodDefaultInternals, $ZodDefaultParams, $ZodDiscriminatedUnion, $ZodDiscriminatedUnionDef, $ZodDiscriminatedUnionInternals, $ZodDiscriminatedUnionParams, $ZodE164, $ZodE164Def, $ZodE164Internals, $ZodE164Params, $ZodEmail, $ZodEmailDef, $ZodEmailInternals, $ZodEmailParams, $ZodEmoji, $ZodEmojiDef, $ZodEmojiInternals, $ZodEmojiParams, $ZodEncodeError, $ZodEnum, $ZodEnumDef, $ZodEnumInternals, $ZodEnumParams, $ZodError, $ZodErrorClass, $ZodErrorMap, $ZodErrorTree, $ZodExactOptional, $ZodExactOptionalDef, $ZodExactOptionalInternals, $ZodFile, $ZodFileDef, $ZodFileInternals, $ZodFileParams, $ZodFlattenedError, $ZodFormattedError, $ZodFunction, $ZodFunctionArgs, $ZodFunctionDef, $ZodFunctionIn, $ZodFunctionInternals, $ZodFunctionOut, $ZodFunctionParams, $ZodGUID, $ZodGUIDDef, $ZodGUIDInternals, $ZodGUIDParams, $ZodIPv4, $ZodIPv4Def, $ZodIPv4Internals, $ZodIPv4Params, $ZodIPv6, $ZodIPv6Def, $ZodIPv6Internals, $ZodIPv6Params, $ZodISODate, $ZodISODateDef, $ZodISODateInternals, $ZodISODateParams, $ZodISODateTime, $ZodISODateTimeDef, $ZodISODateTimeInternals, $ZodISODateTimeParams, $ZodISODuration, $ZodISODurationDef, $ZodISODurationInternals, $ZodISODurationParams, $ZodISOTime, $ZodISOTimeDef, $ZodISOTimeInternals, $ZodISOTimeParams, $ZodInternalIssue, $ZodIntersection, $ZodIntersectionDef, $ZodIntersectionInternals, $ZodIntersectionParams, $ZodInvalidTypeExpected, $ZodIssue, $ZodIssueBase, $ZodIssueCode, $ZodIssueCustom, $ZodIssueInvalidElement, $ZodIssueInvalidKey, $ZodIssueInvalidStringFormat, $ZodIssueInvalidType, $ZodIssueInvalidUnion, $ZodIssueInvalidValue, $ZodIssueNotMultipleOf, $ZodIssueStringCommonFormats, $ZodIssueStringEndsWith, $ZodIssueStringIncludes, $ZodIssueStringInvalidJWT, $ZodIssueStringInvalidRegex, $ZodIssueStringStartsWith, $ZodIssueTooBig, $ZodIssueTooSmall, $ZodIssueUnrecognizedKeys, $ZodJWT, $ZodJWTDef, $ZodJWTInternals, $ZodJWTParams, $ZodKSUID, $ZodKSUIDDef, $ZodKSUIDInternals, $ZodKSUIDParams, $ZodLazy, $ZodLazyDef, $ZodLazyInternals, $ZodLazyParams, $ZodLiteral, $ZodLiteralDef, $ZodLiteralInternals, $ZodLiteralParams, $ZodLooseShape, $ZodMAC, $ZodMACDef, $ZodMACInternals, $ZodMACParams, $ZodMap, $ZodMapDef, $ZodMapInternals, $ZodMapParams, $ZodMemoizer, $ZodNaN, $ZodNaNDef, $ZodNaNInternals, $ZodNaNParams, $ZodNanoID, $ZodNanoIDDef, $ZodNanoIDInternals, $ZodNanoIDParams, $ZodNarrow, $ZodNever, $ZodNeverDef, $ZodNeverInternals, $ZodNeverParams, $ZodNonOptional, $ZodNonOptionalDef, $ZodNonOptionalInternals, $ZodNonOptionalParams, $ZodNull, $ZodNullDef, $ZodNullInternals, $ZodNullParams, $ZodNullable, $ZodNullableDef, $ZodNullableInternals, $ZodNullableParams, $ZodNumber, $ZodNumberDef, $ZodNumberFormat, $ZodNumberFormatDef, $ZodNumberFormatInternals, $ZodNumberFormatParams, $ZodNumberFormats, $ZodNumberInternals, $ZodNumberParams, $ZodObject, $ZodObjectConfig, $ZodObjectDef, $ZodObjectInternals, $ZodObjectJIT, $ZodObjectParams, $ZodOptional, $ZodOptionalDef, $ZodOptionalInternals, $ZodOptionalParams, $ZodPipe, $ZodPipeDef, $ZodPipeInternals, $ZodPipeParams, $ZodPrefault, $ZodPrefaultDef, $ZodPrefaultInternals, $ZodPreprocess, $ZodPreprocessDef, $ZodPreprocessInternals, $ZodPromise, $ZodPromiseDef, $ZodPromiseInternals, $ZodPromiseParams, $ZodRawIssue, $ZodReadonly, $ZodReadonlyDef, $ZodReadonlyInternals, $ZodReadonlyParams, $ZodRealError$1 as $ZodRealError, $ZodRecord, $ZodRecordDef, $ZodRecordInternals, $ZodRecordKey, $ZodRecordParams, $ZodRegistry, $ZodSet, $ZodSetDef, $ZodSetInternals, $ZodSetParams, $ZodShape, $ZodStandardSchema, $ZodString, $ZodStringBoolParams, $ZodStringDef, $ZodStringFormat, $ZodStringFormatChecks, $ZodStringFormatDef, $ZodStringFormatInternals, $ZodStringFormatIssues, $ZodStringFormatParams, $ZodStringFormatTypes, $ZodStringFormats, $ZodStringInternals, $ZodStringParams, $ZodSuccess, $ZodSuccessDef, $ZodSuccessInternals, $ZodSuccessParams, $ZodSuperRefineIssue, $ZodSuperRefineParams, $ZodSymbol, $ZodSymbolDef, $ZodSymbolInternals, $ZodSymbolParams, $ZodTemplateLiteral, $ZodTemplateLiteralDef, $ZodTemplateLiteralInternals, $ZodTemplateLiteralParams, $ZodTemplateLiteralPart, $ZodTransform, $ZodTransformDef, $ZodTransformInternals, $ZodTransformParams, $ZodTuple, $ZodTupleDef, $ZodTupleInternals, $ZodTupleParams, $ZodType, $ZodTypeDef, $ZodTypeDiscriminable, $ZodTypeDiscriminableInternals, $ZodTypeInternals, $ZodTypes, $ZodULID, $ZodULIDDef, $ZodULIDInternals, $ZodULIDParams, $ZodURL, $ZodURLDef, $ZodURLInternals, $ZodURLParams, $ZodUUID, $ZodUUIDDef, $ZodUUIDInternals, $ZodUUIDParams, $ZodUUIDv4Params, $ZodUUIDv6Params, $ZodUUIDv7Params, $ZodUndefined, $ZodUndefinedDef, $ZodUndefinedInternals, $ZodUndefinedParams, $ZodUnion, $ZodUnionDef, $ZodUnionInternals, $ZodUnionParams, $ZodUnknown, $ZodUnknownDef, $ZodUnknownInternals, $ZodUnknownParams, $ZodVoid, $ZodVoidDef, $ZodVoidInternals, $ZodVoidParams, $ZodXID, $ZodXIDDef, $ZodXIDInternals, $ZodXIDParams, $ZodXor, $ZodXorInternals, $ZodXorParams, $brand, $catchall, $constructor, $constructorParams, $input, $loose, $output, $partial, $replace, $strict, $strip, CheckFn, CheckParams, CheckStringFormatParams, CheckTypeParams, CompileOptions, ConcatenateTupleOfStrings, ConvertPartsToStringTuple, Doc, File$1 as File, GlobalMeta, INVALID, JSONSchema$1 as JSONSchema, JSONSchemaGenerator, JSONSchemaGeneratorParams, JSONSchemaMeta, NEVER, Params, ParseContext, ParseContextInternal, ParsePayload, ProcessParams, Processor, RegistryToJSONSchemaParams, Seen, SomeType, StringFormatParams, TimePrecision, ToJSONSchemaContext, ToJSONSchemaParams, ToTemplateLiteral, TypeParams, URL_BAD_FORMAT, URL_UNPARSEABLE, UnrepresentableHandler, ZodCompileAsyncError, ZodCompileUnsupportedError, ZodStandardJSONSchemaPayload, ZodStandardSchemaWithJSON, _$ZodType, _$ZodTypeInternals, _any, _array, _base64, _base64url, _bigint, _boolean, _catch, _check, _cidrv4, _cidrv6, _coercedBigint, _coercedBoolean, _coercedDate, _coercedNumber, _coercedString, _creditCard, _cuid, _cuid2, _custom, _date, _decode, _decodeAsync, _default$62 as _default, _discriminatedUnion, _e164, _email, _emoji, _encode, _encodeAsync, _endsWith, _enum, _file, _float32, _float64, _gt, _gte, _gte as _min, _guid, _includes, _int, _int32, _int64, _intersection, _ipv4, _ipv6, _isoDate, _isoDateTime, _isoDuration, _isoTime, _jwt, _ksuid, _lazy, _length, _literal, _lowercase, _lt, _lte, _lte as _max, _mac, _map, _maxLength, _maxSize, _mime, _minLength, _minSize, _multipleOf, _nan, _nanoid, _nativeEnum, _negative, _never, _nonnegative, _nonoptional, _nonpositive, _normalize, _null$1 as _null, _nullable, _number, _optional, _overwrite, _parse, _parseAsync, _pipe, _positive, _promise, _properties, _property, _readonly, _record, _refine, _regex, _safeDecode, _safeDecodeAsync, _safeEncode, _safeEncodeAsync, _safeParse, _safeParseAsync, _set, _size, _slugify, _startsWith, _string, _stringFormat, _stringbool, _success, _superRefine, _symbol, _templateLiteral, _toLowerCase, _toUpperCase, _transform, _trim, _tuple, _uint32, _uint64, _ulid, _undefined$1 as _undefined, _union, _unknown, _uppercase, _url, _uuid, _uuidv4, _uuidv6, _uuidv7, _void, _xid, _xor, clone, compile, compileFn, config, createStandardJSONSchemaMethod, createToJSONSchemaMethod, decode, decodeAsync, describe, encode, encodeAsync, extractDefs, finalize, flattenError, formatError, getDiscriminatedOption, globalConfig, globalRegistry, handleUnrepresentable, initializeContext, input, isBackEdge, isRecursiveSchema, isValidBase64, isValidBase64URL, isValidCIDRv6, isValidCreditCard, isValidIPv6, isValidJWT, locales, memoizer, mergeValues, meta, output, output as infer, parse, parseAsync, parseURLObject, prettifyError, process, regexes, registry, safeDecode, safeDecodeAsync, safeEncode, safeEncodeAsync, safeParse, safeParseAsync, standardProps, stripTabAndNewline, toDotPath, toJSONSchema, toZod, treeifyError, urlHostnameOk, urlProtocolOk, util, validate, validateAsync, version };
}
declare namespace iso {
	export { ZodISODate, ZodISODateTime, ZodISODuration, ZodISOTime, date$2 as date, datetime$1 as datetime, duration$1 as duration, time$1 as time };
}
declare namespace z {
	export { $RefinementCtx as RefinementCtx, $brand, $input, $output, BRAND, CompileOptions, GlobalMeta, Infer, IssueData, JSONType, NEVER, SafeExtendShape, Schema$1 as Schema, TimePrecision, TypeOf, ZodAny, ZodArray, ZodBase64, ZodBase64URL, ZodBigInt, ZodBigIntFormat, ZodBoolean, ZodCIDRv4, ZodCIDRv6, ZodCUID, ZodCUID2, ZodCatch, ZodCodec, ZodCoercedBigInt, ZodCoercedBoolean, ZodCoercedDate, ZodCoercedNumber, ZodCoercedString, ZodCompileAsyncError, ZodCompileUnsupportedError, ZodCreditCard, ZodCustom, ZodCustomStringFormat, ZodDate, ZodDefault, ZodDiscriminatedUnion, ZodE164, ZodEmail, ZodEmoji, ZodEnum, ZodError, ZodErrorMap, ZodExactOptional, ZodFile, ZodFirstPartySchemaTypes, ZodFirstPartyTypeKind, ZodFlattenedError, ZodFloat32, ZodFloat64, ZodFormattedError, ZodFunction, ZodGUID, ZodIPv4, ZodIPv6, ZodISODate, ZodISODateTime, ZodISODuration, ZodISOTime, ZodInt, ZodInt32, ZodInt64, ZodIntersection, ZodIssue, ZodIssueCode, ZodJSONSchema, ZodJSONSchemaInternals, ZodJWT, ZodKSUID, ZodLazy, ZodLiteral, ZodMAC, ZodMap, ZodNaN, ZodNanoID, ZodNever, ZodNonOptional, ZodNull, ZodNullable, ZodNumber, ZodNumberFormat, ZodObject, ZodOptional, ZodPipe, ZodPrefault, ZodPreprocess, ZodPromise, ZodRawShape, ZodReadonly, ZodRealError, ZodRecord, ZodSafeParseError, ZodSafeParseResult, ZodSafeParseSuccess, ZodSchema, ZodSet, ZodStandardSchemaWithJSON$1 as ZodStandardSchemaWithJSON, ZodString, ZodStringFormat, ZodSuccess, ZodSymbol, ZodTemplateLiteral, ZodTransform, ZodTuple, ZodType, ZodTypeAny, ZodUInt32, ZodUInt64, ZodULID, ZodURL, ZodUUID, ZodUndefined, ZodUnion, ZodUnknown, ZodVoid, ZodXID, ZodXor, _ZodBigInt, _ZodBoolean, _ZodDate, _ZodNumber, _ZodString, _ZodType, _catch$1 as catch, _default$63 as _default, _endsWith as endsWith, _enum$1 as enum, _function, _function as function, _gt as gt, _gte as gte, _includes as includes, _instanceof as instanceof, _length as length, _lowercase as lowercase, _lt as lt, _lte as lte, _maxLength as maxLength, _maxSize as maxSize, _mime as mime, _minLength as minLength, _minSize as minSize, _multipleOf as multipleOf, _negative as negative, _nonnegative as nonnegative, _nonpositive as nonpositive, _normalize as normalize, _null$2 as null, _overwrite as overwrite, _positive as positive, _properties as properties, _property as property, _regex as regex, _size as size, _slugify as slugify, _startsWith as startsWith, _toLowerCase as toLowerCase, _toUpperCase as toUpperCase, _trim as trim, _undefined$2 as undefined, _uppercase as uppercase, _void$1 as void, any, array, base64$1 as base64, base64url$1 as base64url, bigint$1 as bigint, boolean$1 as boolean, check, cidrv4$1 as cidrv4, cidrv6$1 as cidrv6, clone, codec, coerce, compile, config, core, creditCard$1 as creditCard, cuid$1 as cuid, cuid2$1 as cuid2, custom, date$1 as date, decode$1 as decode, decodeAsync$1 as decodeAsync, deepPartial, describe$1 as describe, discriminatedUnion, e164$1 as e164, email$1 as email, emoji$1 as emoji, encode$1 as encode, encodeAsync$1 as encodeAsync, exactOptional, file, flattenError, float32, float64, formatError, fromJSONSchema, getDiscriminatedOption, getErrorMap, globalRegistry, guid$1 as guid, hash, hex$1 as hex, hostname$1 as hostname, httpUrl, inferFlattenedErrors, inferFormattedError, input$1 as input, int, int32, int64, intersection, invertCodec, ipv4$1 as ipv4, ipv6$1 as ipv6, iso, json, jwt, keyof, ksuid$1 as ksuid, lazy, literal, locales, looseObject, looseRecord, mac$1 as mac, map, memoizer, meta$1 as meta, nan, nanoid$1 as nanoid, nativeEnum, never, nonoptional, nullable, nullish$1 as nullish, number$1 as number, object, optional, output as infer, output$1 as output, parse$1 as parse, parseAsync$1 as parseAsync, partialRecord, pipe, prefault, preprocess, prettifyError, promise, readonly, record, refine, regexes, registry, safeDecode$1 as safeDecode, safeDecodeAsync$1 as safeDecodeAsync, safeEncode$1 as safeEncode, safeEncodeAsync$1 as safeEncodeAsync, safeParse$1 as safeParse, safeParseAsync$1 as safeParseAsync, set, setErrorMap, strictObject, string$1 as string, stringFormat, stringbool, success, superRefine, symbol, templateLiteral, toJSONSchema, toZod, transform, treeifyError, tuple, uint32, uint64, ulid$1 as ulid, union, unknown, url, util, uuid$1 as uuid, uuidv4, uuidv6, uuidv7, validate, validateAsync, xid$1 as xid, xor };
}

export {};
