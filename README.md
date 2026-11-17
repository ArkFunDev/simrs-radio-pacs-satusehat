# SIMRS Radiology ↔ PACS ↔ SatuSehat

An add-on module for **SIMRS Khanza** that connects radiology orders to a **PACS**
(DICOM) and submits **ServiceRequest** and **ImagingStudy** resources to Indonesia's
**SatuSehat** platform (FHIR R4).

> ⚠️ **Disclaimer:** This is a community project, not an official SIMRS Khanza or
> Ministry of Health product. It handles medical data. Review the code, harden it, and
> test it in staging before any production use.

## Features
- Radiology order form (outpatient and inpatient), multi-examination orders
- Result entry: projection, kV, mAs, FFD, BSF, radiation dose, report templates
- Send orders to PACS with a unique accession number per examination (USG skipped)
- Pull DICOM viewer link and the radiologist's report from PACS (manual or scheduled)
- Auto-save combined report into `hasil_radiologi`
- Patient IHS lookup via NIK, then ServiceRequest + ImagingStudy submission to SatuSehat
- Role-based login (radiology vs other units) using Khanza accounts

## Requirements
- PHP 8.0+ with `curl`, `pdo_mysql`, `mbstring`
- MySQL/MariaDB with a working SIMRS Khanza database
- A PACS exposing the order API (`/api/orders`, `/api/orders/{acc}/{mrn}`)
- SatuSehat credentials (Client ID/Secret, Organization ID)

## Installation
```bash
git clone https://github.com/<your-username>/<repo-name>.git
cd <repo-name>

cp config.local.example.php config.local.php   # then edit with your own values
mysql -u <user> -p <database> < schema.sql      # review the file first
```

Serve the folder with Apache/Nginx + PHP and open `login.php`.

### Scheduled PACS pull (optional)
```bash
# every hour (cron)
0 * * * * php /path/to/diskom_auto_pull.php

# test a single patient (verbose)
php diskom_auto_pull.php --no_rawat=2026/01/01/000001
```

## Project structure
| File | Purpose |
|---|---|
| `login.php`, `logout.php`, `index.php`, `auth_check.php` | Authentication and main shell |
| `config.php` | Shared helpers (PACS, SatuSehat, utilities); loads `config.local.php` |
| `data_pasien.php` | Patient list (outpatient/inpatient) |
| `form_radiologi.php` | Create radiology request |
| `data_radiologi.php`, `DataPermintaanRadio.php` | Request lists and PACS submission |
| `hasil_radiologi.php` | Result entry |
| `periksa_radiologi.php`, `periksa_radiologi_user.php` | Examination lists |
| `diskom_radiologi.php`, `diskom_auto_pull.php` | Pull DICOM link and report from PACS |
| `form_imagingstudy.php` | SatuSehat ServiceRequest + ImagingStudy |
| `schema.sql`, `docs/flow.md` | Database additions and workflow notes |

## Security notes
- Never commit `config.local.php`. Rotate any credential that was ever committed.
- Keep SSL verification enabled for all outbound requests.
- Deploy behind HTTPS and restrict access to your hospital network/VPN.
- Disable `display_errors` in production.
- Known limitations: no CSRF tokens on AJAX actions; some output is not yet escaped
  (see the issue tracker). PRs welcome.

## Contributing
Issues and pull requests are welcome. Please never include real patient data,
credentials, or hospital-identifying information in issues or PRs.

## License
MIT. See [LICENSE](LICENSE).