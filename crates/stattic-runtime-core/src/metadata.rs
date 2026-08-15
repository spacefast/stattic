use serde_json::Value;

#[derive(Default)]
pub(crate) struct RuntimeArtifactMetadataFields {
    pub(crate) runtime_schema: Option<String>,
    pub(crate) runtime_engine_version: Option<String>,
    pub(crate) generated_at: Option<String>,
}

pub(crate) fn artifact_metadata_fields(
    artifact_metadata: Option<&Value>,
) -> RuntimeArtifactMetadataFields {
    let Some(metadata) = artifact_metadata.and_then(Value::as_object) else {
        return RuntimeArtifactMetadataFields::default();
    };
    RuntimeArtifactMetadataFields {
        runtime_schema: metadata
            .get("runtime_schema")
            .and_then(Value::as_str)
            .map(str::to_string),
        runtime_engine_version: metadata
            .get("runtime_engine_version")
            .and_then(Value::as_str)
            .map(str::to_string),
        generated_at: metadata
            .get("generated_at")
            .and_then(Value::as_str)
            .map(str::to_string),
    }
}
