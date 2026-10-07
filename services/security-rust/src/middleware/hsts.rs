// The HSTS implementation lives in `crate::security::hsts`; this module
// re-exports it so middleware consumers can use
// `crate::middleware::hsts::*` alongside the other middleware layers.
pub use crate::security::hsts::with_hsts;
