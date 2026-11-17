# Workflow

1. **Request**: `data_pasien.php` -> `form_radiologi.php` creates a radiology order
   (`permintaan_radiologi` + `permintaan_pemeriksaan_radiologi`).
2. **Result entry**: `data_radiologi.php` -> `hasil_radiologi.php` records examination
   details (kV, mAs, FFD, ...) and the result text.
3. **Send to PACS**: "Send to PACS" calls `POST /api/orders` on the PACS and stores a row
   in `khanza_pacs_mapping` (one accession number per examination; USG is skipped).
4. **Pull DICOM + report**: `diskom_radiologi.php` (manual) or `diskom_auto_pull.php`
   (cron/Task Scheduler) fetch the study UID and radiologist report from the PACS, build
   the viewer URL, and upsert the report into `hasil_radiologi`.
5. **SatuSehat**: `form_imagingstudy.php` verifies the patient IHS by NIK and posts
   `ServiceRequest` + `ImagingStudy` (FHIR R4).

## Accession numbers
- Base ACC = order number without the `PR` prefix and year digits (`substr($noorder, 4)`).
- Examination index is appended as suffix (`<base>0`, `<base>1`, ...).

## Authentication
`login.php` validates against SIMRS Khanza `user`, `dokter`/`petugas` and `admin` tables
(AES-encrypted fields). Access is split by destination: Radiology vs ER/Outpatient/Inpatient.