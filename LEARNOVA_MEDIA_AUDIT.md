# Learnova media and asset audit

The repository tree includes several distinct classes of media and binary assets. They should not be treated as disposable branding files:

- `Uploads/profiles/` contains user profile photos.
- `Uploads/Submissions/`, `Uploads/assignments/`, `Uploads/assessments/`, `Uploads/chat/`, and `Uploads/resources/` contain school work, messages, and learning resources. These may be educational records or user-generated content; keep them unless an authorized retention/deletion process identifies specific records.
- `assets/images/` contains the existing school logo, seals/signatures/stamps, academic artwork, and images referenced by the portal and marketing pages. Some are school-specific, not Learnova product branding.
- `assets/fpdf/` and `vendor/fpdf/` contain third-party PDF library assets, including a tutorial logo; these belong to the vendored library and should not be removed as product media.
- Root favicon and Android/iOS icon files are still used by the manifest and pages. Replace them only after new Learnova icon files are generated and validated.
- `backups/` contains database dumps. They are data recovery artifacts, not media; preserve them securely and do not publish their contents.

A Learnova vector brand mark has been added at `assets/images/learnova-mark.svg` for new branded surfaces. The existing logos, school identity assets, user uploads, vendor files, and backups are intentionally retained. Delete or replace those only through a reviewed asset-usage audit and an authorized records-retention plan.
