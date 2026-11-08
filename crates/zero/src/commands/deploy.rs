//! Local lifecycle adapter. The PHP tool is also runnable without CLI registration.
use std::io::Write;
use std::process::{Command, ExitCode};

/// Build calls this in its private stage before publishing the artifact directory.
pub(crate) fn write_metadata(stage: &std::path::Path, built: &crate::pipeline::Built) -> std::io::Result<()> {
    let value = serde_json::json!({
        "version": 1,
        "snapshot": built.snapshot,
        "destructiveChanges": built.warnings,
    });
    std::fs::write(stage.join("zero-deployment.json"), serde_json::to_vec_pretty(&value)?)
}

pub(crate) fn cli(args: &[String]) -> ExitCode {
    let result = (|| -> std::io::Result<ExitCode> {
        let directory = tempfile::tempdir()?;
        for (name, bytes) in [
            ("local.php", include_bytes!("../../scripts/deploy/local.php").as_slice()),
            ("Json.php", include_bytes!("../../runtime/Zero/Json.php").as_slice()),
            (
                "Artifact.php",
                include_bytes!("../../runtime/Zero/Deployment/Artifact.php").as_slice(),
            ),
            (
                "Local.php",
                include_bytes!("../../runtime/Zero/Deployment/Local.php").as_slice(),
            ),
        ] {
            let mut file = std::fs::File::create(directory.path().join(name))?;
            file.write_all(bytes)?;
        }
        let status = Command::new(std::env::var_os("ZERO_DEPLOY_PHP").unwrap_or_else(|| "php".into()))
            .arg(directory.path().join("local.php"))
            .args(args)
            .status()?;
        Ok(ExitCode::from(status.code().unwrap_or(1).clamp(0, 255) as u8))
    })();
    result.unwrap_or_else(|_| {
        println!(
            "{}",
            serde_json::json!({"error": {
                "code": "deployment_adapter_unavailable",
                "message": "Could not start the local adapter; verify PHP 8.2+, SQLite3, ZipArchive and a writable temporary directory."
            }})
        );
        ExitCode::from(1)
    })
}
