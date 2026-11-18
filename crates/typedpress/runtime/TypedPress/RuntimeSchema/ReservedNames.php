<?php

declare(strict_types=1);

namespace TypedPress\RuntimeSchema;

/**
 * Names the generated client declares as types (`RESERVED_TYPE_NAMES` in src/client.rs): a global may
 * not take one. A test in src/runtime_schema.rs fails when the two lists differ.
 */
final class ReservedNames
{
    public const CLIENT_TYPES = [
        'Role', 'User', 'Media', 'PrivateFile', 'RevisionSummary', 'Status', 'Locale', 'Collections',
        'Globals', 'Actions', 'Record', 'Partial', 'Pick', 'Omit', 'Extract', 'Exclude',
        'PropertyKey', 'Promise', 'Error', 'Blob', 'URLSearchParams', 'FormData', 'Uint8Array', 'Headers',
        'Request', 'RequestInit', 'Response', 'SchemaDescriptor', 'Descriptor', 'CollectionDescriptors', 'CollectionKey', 'Row',
        'Rels', 'IdOf', 'Requested', 'NestedWith', 'RelationValue', 'WithValue', 'RowWith', 'OptionalKeys',
        'ExactWith', 'StatusQuery', 'LocaleQuery', 'ListQuery', 'Query', 'ReadCall', 'CollectionClient', 'MediaQuery', 'MediaClient', 'FileBucketClient',
        'FilesClient', 'ActionsClient', 'GlobalsClient', 'Session', 'AuthClient', 'Client', 'ClientOptions', 'AuthOptions',
        'Problem', 'TypedPressError', 'Json', 'Send', 'Held', 'Issued', 'OidcTokens', 'BrowserGlobals',
    ];
}
