//! Synchronous host filesystem. Each import returns a zero_alloc-owned, length-prefixed
//! UTF-8 JSON result: {data: value} or {error: {kind, message}}. No WASI dependency.
use crate::loader::Fs;
use serde::de::DeserializeOwned;
use std::{
    io,
    path::{Path, PathBuf},
};

#[link(wasm_import_module = "host")]
unsafe extern "C" {
    fn read_file(path: *const u8, len: usize) -> *mut u8;
    fn stat(path: *const u8, len: usize) -> *mut u8;
    fn canonicalize(path: *const u8, len: usize) -> *mut u8;
    fn read_dir(path: *const u8, len: usize) -> *mut u8;
}

fn call<T: DeserializeOwned>(
    path: &Path,
    operation: unsafe extern "C" fn(*const u8, usize) -> *mut u8,
) -> io::Result<T> {
    let path = path.to_str().ok_or_else(|| io::Error::other("non-UTF-8 path"))?;
    let ptr = unsafe { operation(path.as_ptr(), path.len()) };
    let len = unsafe { u32::from_le_bytes(std::slice::from_raw_parts(ptr, 4).try_into().unwrap()) } as usize;
    let bytes = unsafe { Vec::from_raw_parts(ptr, len + 4, len + 4) };
    let value: serde_json::Value = serde_json::from_slice(&bytes[4..]).map_err(io::Error::other)?;
    if let Some(error) = value.get("error") {
        let kind = match error["kind"].as_str() {
            Some("ENOENT") => io::ErrorKind::NotFound,
            Some("EACCES") => io::ErrorKind::PermissionDenied,
            _ => io::ErrorKind::Other,
        };
        return Err(io::Error::new(
            kind,
            error["message"].as_str().unwrap_or("host filesystem error").to_owned(),
        ));
    }
    serde_json::from_value(value["data"].clone()).map_err(io::Error::other)
}

pub struct Host;
impl Fs for Host {
    fn read_to_string(&self, path: &Path) -> io::Result<String> {
        call(path, read_file)
    }
    fn canonicalize(&self, path: &Path) -> io::Result<PathBuf> {
        call(path, canonicalize)
    }
    fn read_dir(&self, path: &Path) -> io::Result<Vec<PathBuf>> {
        call(path, read_dir)
    }
    fn is_file(&self, path: &Path) -> bool {
        call::<serde_json::Value>(path, stat).is_ok_and(|v| v["file"] == true)
    }
    fn is_dir(&self, path: &Path) -> bool {
        call::<serde_json::Value>(path, stat).is_ok_and(|v| v["directory"] == true)
    }
    fn is_symlink(&self, path: &Path) -> bool {
        call::<serde_json::Value>(path, stat).is_ok_and(|v| v["symlink"] == true)
    }
    fn exists(&self, path: &Path) -> bool {
        call::<serde_json::Value>(path, stat).is_ok()
    }
}
