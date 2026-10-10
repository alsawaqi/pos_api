# Shared kitchen configuration module

Canonical source: pos_api/src/packages/kitchen-config. This exact versioned source snapshot ships in both applications; do not maintain a separate portal resolver. Run the parity verifier when either changes. App\Kitchen is the shared internal namespace. Both applications use the same database; portal callers must derive tenant, branch scope and audit actor from their authenticated session. Device IDs are reloaded from scoped active records. No browser-supplied identity or API credentials.
