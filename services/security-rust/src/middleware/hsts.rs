// The HSTS implementation lives in `crate::security::hsts`; this module
// re-exports it so middleware consumers can use
// `crate::middleware::hsts::*` alongside the other middleware layers.
pub use crate::security::hsts::{
    apply_hsts_headers, csp_header_with_nonce, enforce_https, generate_csp_nonce,
    handle_https_rejection, hsts_headers, is_hsts_preload_compliant, validate_secret_strength,
    with_hsts, HSTS_HEADER_VALUE, HSTS_MAX_AGE,
};
