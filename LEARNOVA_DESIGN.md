# Learnova premium redesign notes

This pass introduces a shared, accessible visual layer for the existing portal templates: deep-navy app chrome, teal/cyan accents, calmer surfaces, refined card and form treatments, consistent focus states, and reduced-motion support. It is intentionally additive so current routes, data, and role workflows are not changed.

The shared branding script loads `assets/css/learnova-premium.css` on pages whose templates include the script. It also updates visible legacy product-name mentions from EduSync to Learnova. School/tenant identities, URLs, database records, historical backups, and third-party organization identities are not globally rewritten.

The public marketing pages are legacy standalone documents and need a separate content/layout redesign before their routes can adopt this shared portal layer. No production deployment or database migration is performed by this UI pass.
