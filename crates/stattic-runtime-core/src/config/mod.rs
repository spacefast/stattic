//! Space config surfaces: the JSON-with-comments reader, the strict
//! `sf.jsonc` v1 compiler, and validation/normalization for the current
//! Spacefast config schema.

mod access;
pub mod current;
pub mod diagnostics;
pub mod jsonc;
pub mod strict;
