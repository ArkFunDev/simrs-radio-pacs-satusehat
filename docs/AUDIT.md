# Code audit & fixes

Scope: all PHP files in this repository plus the production `imagingstudy.php`.
Method: manual review + grep-based checks. **PHP was not available in the review
environment, so no `php -l` / runtime test was run on the patched files — run
`php -l *.php` and test in STAGING before deploying.**

## Fixed in this version

| # | Severity | Finding | Fix |
|---|---|---|---|
| 1 | High | `imagingstudy.php` had **no login check at all** — anyone who could reach the URL could trigger SatuSehat submissions | `auth_check.php` + `require_role()` |
| 2 | High | **TLS verification disabled** (`VERIFYPEER=false`) in `imagingstudy.php` and `form_imagingstudy.php` (6 places), contradicting the README | Verification enabled everywhere |
| 3 | High | Token request **fell back to the production endpoint** while ImagingStudy used the configured (e.g. staging) base URL — sends credentials to the wrong environment and mixes tokens | Only the configured environment is used |
| 4 | High | Role was stored in session (`user_role`) but **never enforced**; any logged-in user could open radiology pages | `require_role()` on radiology pages |
| 5 | High | `display_errors=1` in two files; `testtoken` action returned token preview, client-id prefix and raw responses | Errors logged only; details removed from responses |
| 6 | Medium | `row`/`send_one` joined `periksa_radiologi` and `permintaan_radiologi` by `no_rawat` and took `LIMIT 1` → a patient with several orders could get **another order's ServiceRequest ID** | Join simplified; prefers the order whose number matches the accession |
| 7 | Medium | Bug: `hasil_radiologi.php` assigned the *text* `'DEFAULT_KD_DOKTER_PJ'` etc. instead of the constants, so placeholders could be saved into reports | Uses `defined()` + constants |
| 8 | Medium | Unescaped output (XSS) throughout `hasil_radiologi.php`; `innerHTML` with server/SatuSehat text in `imagingstudy.php` | `h()` / `esc()` / `json_encode` |
| 9 | Medium | No CSRF protection on AJAX/POST endpoints | Same-origin check for all POSTs (`auth_check.php`); CSRF token on `imagingstudy.php` |
| 10 | Medium | Token cached in the PHP session (per user, in session store) | File cache, mode 0600, server-side only |
| 11 | Low | `schema.sql` lacked columns the code uses (`sr_id`, `is_id`, `studydatetime`, `sopclass`, `dokter_rad_ihs`) and `activity_log` | Added (+ upgrade `ALTER`s) |
| 12 | Low | `error_reporting(0)` in `config.php` hid every error | `E_ALL` with logging, no display |
| 13 | Low | UTF-8 BOM in `form_imagingstudy.php` (breaks `header()`/cookies), mixed CRLF/LF | BOM removed; `.gitattributes` |
| 14 | Low | LIKE wildcards (`%`, `_`) from user filters not escaped; date filters not validated | `likeEscape()`, date regex |
| 15 | Low | Session cookies without `HttpOnly`/`SameSite`/strict mode | Set in `config.php` |
| 17 | Low | `getKodeSNOMEDRadiologi()` (legacy, unused in production) could never return the CT / USG codes: generic X-ray keywords such as `KEPALA`, `THORAX`, `ABDOMEN` matched first | Specific CT/USG/mammography keys moved first; regression tests added |
| 16 | Docs | README pointed to `docs/flow.md` (file was in root) and a placeholder clone URL | Moved / corrected |

## Not changed (needs your decision / testing)

1. **Inconsistent accession scheme.** `hasil_radiologi.php` and `diskom_radiologi.php` use
   `substr($noorder, 4)` + 0-based index (`<base>0`); `DataPermintaanRadio.php` uses
   `substr($noorder, 2)` + `-N` and the legacy `acc` column; `config.php`'s
   `kirimServiceRequestSatuSehat()` uses `substr(…, 2)`. If more than one path is used on
   the same data, the ACSN in ServiceRequest, PACS and ImagingStudy can disagree.
   `accessionBase()` now documents the production rule; migrate or retire the legacy path.
2. **Series/Instance UIDs.** `imagingstudy.php` uses the Study UID for study, series and
   instance. It was accepted by SatuSehat in your environment, but it is not a faithful
   DICOM representation. Next step: fetch real UIDs from the PACS (e.g. QIDO-RS).
3. **ImagingStudy completeness.** No `encounter`, `numberOfSeries`, `numberOfInstances`,
   `series.number`. Add them if SatuSehat validation or reporting requires.
4. **The `PR20` + ACC reconstruction** (to match the right ServiceRequest) assumes order
   numbers `PR` + `YYYYMMDD` + 4 digits and fewer than 10 examinations per order.
5. **`generateNoOrder()`** is not race-safe (read max → +1). Use a DB sequence/lock if
   several users create orders at once.
6. **CSRF**: older pages rely on the same-origin check only; add per-form tokens.
7. `periksa_radiologi_user.php` and `index.php` have their own session check (not
   `auth_check.php`); consider unifying.
