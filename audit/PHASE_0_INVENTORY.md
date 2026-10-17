# WebSplend HMS — Phase 0: Read-Only Source Code Audit

**Date:** 2026-10-12
**Scope:** `D:\WebSplend-HMS\working_copy` (source) + `D:\WebSplend-HMS\database_backups\*.sql` (schema)
**Mode:** Static read-only analysis. No application file was modified. No database was connected to or changed. No external/production API was called.
**Confidentiality:** No credential values, API tokens, private keys, or patient-identifying data are reproduced in this document. Secrets are referenced by **variable name, column name, and file:line only**.

---

## 0. How to read this report

Every finding carries a classification:

| Class | Meaning |
|---|---|
| **VERIFIED FROM CODE** | Confirmed by reading the source and/or the SQL dump. The statement is a fact about the codebase. |
| **IMPLEMENTED BUT UNVERIFIED** | Code path exists and is structurally complete, but correctness was **not** proven — no execution, no ABDM gateway call, no integration test. |
| **INCOMPLETE** | Code is a stub, dead, hardcoded to a test value, or has a provable defect that stops the workflow. |
| **BLOCKED** | Cannot be assessed from source alone; requires runtime, server config, a third-party credential, or a missing artefact. |

> **Standing caveat:** per the audit mandate, the *existence* of a table, function, or endpoint is **not** evidence that a feature works. Row counts in the dumps indicate the ABDM layer **has** been exercised against a real gateway at some point — but by whom, with which environment, and with what success rate cannot be established from source alone.

---

## 1. Project Structure & Architecture Inventory — VERIFIED FROM CODE

### 1.1 Scale

| Metric | Value |
|---|---|
| Total files | 1,536 |
| Total size | 50.31 MB |
| PHP files | 1,238 |
| Application PHP (excluding vendor) | ~475 |
| Top-level directories | 17 |
| Composer-managed vendor trees | 2 (`vendor/`, `modules/abdm/m3/vendor/`) |

### 1.2 Architecture classification

**VERIFIED FROM CODE:** This is a **procedural PHP application. There is no framework, no ORM, no router, no DI container, no service layer, no connection wrapper, and no autoloader for application code.**

Direct evidence:
- `eval()`, `unserialize()`, `extract()`, `create_function()`, `shell_exec()`, `system()`, `passthru()`, `proc_open()` → **0 occurrences** each.
- Legacy `mysql_*` API → **0 occurrences**. App is 100% `mysqli`; `PDO` → **0 occurrences**.
- 485 distinct globally-scoped functions defined in page files, many *inside page bodies* (e.g. `wsMoney()` at `dashboard/index.php:2397`).
- `vendor/autoload.php` is required in only **4** files; everything else is loaded via `require` in dependency order.

### 1.3 Directory map

| Directory | Files | Role |
|---|---|---|
| `api/v3/**` | 13 | **ABDM M2/M3 public callback endpoints.** No session, no auth. |
| `assets/` | 14 | CSS, images, APK |
| `communication/` | 5 + vendored PHPMailer | Email/SMS/WhatsApp. PHPMailer is **not** Composer-managed here. |
| `dashboard/` | 2 + 4 ajax | Home page (3,480 lines) + polling endpoints. Contains dead `*_old.php` files. |
| `includes/` | 14 | **The entire shared layer** (see §1.5). |
| `login/` | 1 | `tenant_blocked.php` only. **Guaranteed fatal** — see §7.1. |
| `masters/` | 100+ | Hospital, branch, department, doctor, inventory, charges, rooms, lab masters. |
| `modules/` | 300+ | Registration, appointments, OPD, IPD, billing, pharmacy, inventory, certificates, tickets, **abdm** |
| `patient/` | 5 | Separate patient-portal login + dashboard. |
| `payments/` | 5 + lib | PayU gateway. |
| `profile/`, `reports/`, `settings/`, `swbilling/` | 60+ | User profile; reports; users/rights/setup; **subscription billing**. |
| `uploads/` | — | Patient/doctor/branche file uploads. |
| `vendor/` | — | PhpSpreadsheet, TCPDF, and their deps. |
| `SINGLE TENANT/` | 3 | Standalone-deployment kit. **Never referenced by any runtime file.** |

### 1.4 Entry points — VERIFIED FROM CODE

There is **no front controller**. Every page is a directly reachable PHP file.

| Entry point | Purpose |
|---|---|
| `index.php` (549 lines) | Login page + "Remember Me" auto-login. **Not a router.** |
| `logout.php` (85 bytes) | `session_destroy()` + redirect. |
| `access_denied.php` | Redirect target for failed permission checks. |
| `dashboard/index.php` | Authenticated home. |
| `modules/abdm/callback.php` | **Stub.** Unreferenced by any rewrite rule. |
| `api/v3/**/index.php` (13 files) | ABDM public callbacks. |

### 1.5 `includes/` — the complete shared layer — VERIFIED FROM CODE

| File | Lines | Purpose |
|---|---|---|
| `db.php` | 85 | **The central DB access layer.** Resolves tenant → control DB → HMS DB. |
| `control_db.php` | 11 | **Plaintext credential file.** See §7.1 (critical). |
| `tenant.php` | 80 | `getTenant()`; builds `$controlConn`. |
| `tenant_db.php` | 29 | `connectTenantDatabase()` — the app's **only** DB helper function. |
| `permissions.php` | 97 | `checkPermission()`, `hasAnyPermission()`. |
| `tenant_guard.php` | 87 | `checkTenantAccess()` — returns array, does not throw. |
| `public_tenant.php` | 52 | Sets `$_SESSION['tenant_id']` from `$_GET` for unauthenticated endpoints. |
| `audit_log.php` | 135 | `addAuditLog()`. |
| `header.php` | 582 | **The de facto auth guard** (`$_SESSION['user_id']` check at `:5-8`), included by 109 files. |
| `sidebar.php` | 596 | Navigation + 30 `checkPermission()` menu gates. |
| `barcode_helper.php` | 146 | `generateBarcode()`. |
| `barcode_field_mapper.php` | 33 | Pure switch, no I/O. |
| `ipd_room_charges.php` | 136 | `calculateIPDRoomCharges()`. |
| `prescription_setup_helper.php` | 104 | `getDoctorPrescriptionTabs()` — **raw interpolated SQL**. |

### 1.6 Database access layer — VERIFIED FROM CODE

**24 `new mysqli()` call sites. Two distinct connection patterns:**

- **Control DB** (`$controlConn`): 9 sites — opened, one query, **closed** every request (`includes/db.php:48`).
- **HMS/tenant DB** (`$conn` / `$hospitalConn`): 15 sites — kept open for request lifetime.

> ⚠️ `mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)` is set in only **10 of ~475** files. All others run in **silent mode**, so DB errors are invisible until manually checked — which most modules do not do.

**No connection reuse, no pool, no registry.** `includes/db.php` is `require`d (not `require_once`) by **331 files**.

> 🔴 **`patient/dashboard.php` requires `includes/db.php` 9 times** (lines 46, 94, 275, 399, 438, 477, 603, 690, 758) with no include guard → **~18 database connections per page load.**

---

## 2. PHP Version & Extensions Required — VERIFIED FROM CODE

### 2.1 Version floor: **PHP >= 8.2.0, 64-bit**

**VERIFIED FROM CODE** — `vendor/composer/platform_check.php:7-13`:
```php
if (!(PHP_VERSION_ID >= 80200)) { $issues[] = '... require a PHP version ">= 8.2.0". ...'; }
if (PHP_INT_SIZE !== 8)         { $issues[] = '... require a 64-bit build of PHP.'; }
```

Corroborated by `vendor/phpoffice/phpspreadsheet/composer.json:72` → `"php": "^8.1"`.

**PHP 7 is a hard parse failure**, not a runtime error — `includes/permissions.php:67` uses the `match` expression (PHP 8.0+). Also PHP 8.0-only builtins: `str_contains()` ×5, `str_starts_with()` ×3 (both in `modules/abdm/m3/m3_fhir_validator.php` and the FHIR generators).

> 🟡 **VERIFIED FROM CODE — inconsistency:** the 8.2 floor comes from **Composer resolution of the vendor tree**, not from application code. App code itself requires only **PHP >= 8.0**. There is **no root `composer.json`/`composer.lock`** to record this.

### 2.2 Extensions

| Extension | Status | Evidence |
|---|---|---|
| **mysqli** | **REQUIRED** | Every DB call. `mysqli_query` ×365, `mysqli_real_escape_string` ×189. |
| **curl** | **REQUIRED** | `curl_init()` ×46, `curl_exec` ×46, `curl_setopt` ×61. All ABDM HTTP. |
| **openssl** | **REQUIRED** | ×60 — `openssl_public_encrypt`, `openssl_pkey_get_public/new/export/get_private`, `openssl_pkey_derive`, `openssl_encrypt/decrypt`, `openssl_get_cipher_methods`. |
| **json** | **REQUIRED** | `json_encode` ×700, `json_decode` ×106. |
| **session** | **REQUIRED** (default) | `session_start`, `session_regenerate_id`, `session_set_cookie_params`. |
| mbstring, ctype, filter, hash, dom, libxml, simplexml, xml, gd, zip, fileinfo, iconv | **Transitive** | Required by `phpspreadsheet/composer.json`. **Zero direct app usage.** |
| **sodium** | **NOT USED** | `sodium_*` → 0 matches, despite `modules/abdm/m3/api/test_sodium.php` existing (it uses `openssl_pkey_derive`). |
| **pdo / pdo_mysql** | **NOT USED** | 0 matches. |
| **bcmath**, **soap**, **intl** | **NOT USED** | 0 matches each. |

> **X25519 / Fidelius crypto is implemented on OpenSSL primitives**, not sodium — `openssl_pkey_new(['x25519'=>[]])` and `openssl_pkey_derive`. This requires **OpenSSL 1.1.1+** with X25519 support. **BLOCKED** — cannot be confirmed for the target XAMPP without checking the local OpenSSL version.

### 2.3 Dependencies

**VERIFIED FROM CODE — no root `composer.json` or `composer.lock` exists.** Dependencies are unreproducible and unauditable.

| Tree | Package | Version | Consumers |
|---|---|---|---|
| `vendor/` | phpoffice/phpspreadsheet | 5.7.0 | **1** (`masters/inventory/import/preview.php:6`) |
| `vendor/` | tecnickcom/tcpdf | **not in `installed.json`** — manually placed | **5** (manual `require`, absent from autoloader) |
| `vendor/` | composer/pcre, maennchen/zipstream-php, markbaker/complex, markbaker/matrix, psr/simple-cache | 3.3.2 / 3.1.2 / 3.0.2 / 3.0.1 / 3.0.0 | PhpSpreadsheet deps |
| `communication/phpmailer/` | PHPMailer | unversioned | **1** (`communication/send_mail.php:10-14`, 3 manual requires) |
| `modules/abdm/m3/vendor/` | simplito/elliptic-php | 1.0.12 | **2, both test scripts only** |
| `modules/abdm/m3/vendor/` | simplito/bn-php, simplito/bigint-wrapper-php | 1.1.4 / 1.0.0 | same |

> **VERIFIED FROM CODE:** production M3 crypto uses **OpenSSL only**. The `simplito/*` elliptic-curve libraries are loaded **exclusively by test harnesses** (`m3_fidelius_crypto_test.php:4`, `m3_health_data_push_payload_test.php`) and are **not** on any production path.

---

## 3. Configuration Variable Names (names only, no values) — VERIFIED FROM CODE

> Policy: **names and locations only.** Values were encountered and are redacted throughout.

### 3.1 Control database (`u336643111_control`)

| Variable / key name | Locations |
|---|---|
| `$controlHost` / `'host'` | `includes/db.php:11` · `includes/tenant.php:11` · `includes/tenant_guard.php:14` · `includes/control_db.php:7` · `api/v3/hip/link/care-context/init/index.php:85` · `…/confirm/index.php:54` |
| `$controlDb` / `'name'` | `includes/db.php:12` · `includes/tenant.php:12` · `includes/tenant_guard.php:15` · `includes/control_db.php:8` · `…/init/index.php:86` · `…/confirm/index.php:55` |
| `$controlUser` / `'user'` | `includes/db.php:13` · `includes/tenant.php:13` · `includes/tenant_guard.php:16` · `includes/control_db.php:9` · `…/init/index.php:87` · `…/confirm/index.php:56` |
| `$controlPass` / `$controlPassword` / `'pass'` | `includes/db.php:14` · `includes/tenant.php:14` · `includes/tenant_guard.php:17` · `includes/control_db.php:10` · `…/init/index.php:88` · `…/confirm/index.php:57` |

**HARDCODED INLINE — values redacted:**
- `includes/control_db.php:7-10` — central credential file (see §7.1).
- `api/v3/hip/token/on-generate-token/index.php:25-30` — **4 literals directly inside `new mysqli(...)`.**
- `api/v3/hiu/health-information/data-push/index.php:40-45` — same.
- `api/v3/hiu/health-information/on-request/index.php:27-32` — same.
- `api/v3/link/on_carecontext/index.php:50-55` — same.

> 🔴 **The same credential is duplicated at 12 locations**, 5 of them with literals inline in the constructor call. A partial rotation silently breaks ABDM callbacks.

### 3.2 HMS / tenant database (`u336643111_hms`)

| Variable / key name | Locations |
|---|---|
| `$databaseHost` / `$host` / `'database_host'` | `includes/db.php:74` · `includes/tenant_db.php:4` · `SINGLE TENANT/db.php:4` · `patient/login_process.php:92` |
| `$databaseName` / `$database` / `'database_name'` | `SINGLE TENANT/db.php:5` · `includes/tenant_db.php:8` |
| `$databaseUser` / `$username` / `'database_user'` | `SINGLE TENANT/db.php:6` · `includes/tenant_db.php:9` |
| `$databasePassword` / `$password` / `'database_password'` | `SINGLE TENANT/db.php:7` · `includes/tenant_db.php:10` |

**HMS DB credentials are read from the control DB `projects` table**, not from a config file — columns `database_host`, `database_name`, `database_user`, `database_password`, selected at `includes/db.php:31-34`, `includes/tenant.php:52-55`, `includes/tenant_guard.php:42-45`.

> 🔴 **HARDCODED INLINE into SQL** (values redacted) — the tenant DB name is a **string literal inside SQL**, in a `prepare()` with **zero placeholders** (the `prepare()` is decorative):
> - `api/v3/hip/link/care-context/init/index.php:103`
> - `api/v3/hip/link/care-context/confirm/index.php:72`
> - `api/v3/hip/token/on-generate-token/index.php:50`
> - `api/v3/link/on_carecontext/index.php:75`

### 3.3 Non-DB configuration

| Concept | Name(s) | Status |
|---|---|---|
| Timezone (PHP) | `date_default_timezone_set('Asia/Kolkata')` — hardcoded call, ~68 sites | No variable |
| Timezone (MySQL) | `SET time_zone = '+05:30'` — hardcoded, all connect sites | No variable |
| Charset | `set_charset("utf8mb4")` — hardcoded | No variable |
| **App base URL** | `$base_url` / `$BASE_URL` / `APP_URL` / `SITE_URL` | **0 matches.** No base-URL abstraction exists. |
| Currency | `formatCurrency()` in `swbilling/includes/functions.php:3` | Function only, no config |
| `.env` / `getenv()` / `$_ENV` | — | **0 files.** No environment config whatsoever. |

**ABDM constants** (`modules/abdm/config.php`, `m2_config.php`): `ABDM_M2_CONFIG_LOADED`, `ABDM_M2_BASE_URL`, `ABDM_M2_CM_ID`, `ABDM_M2_SESSION_URL`, `ABDM_M2_BRIDGE_URL`, `ABDM_M2_TIMEOUT`, plus `ABHA_BASE_URL`, `ABHA_PHR_WEB_BASE_URL`.

---

## 4. Database Selection / Tenant Logic — VERIFIED FROM CODE

### 4.1 Two connections, never one

**VERIFIED FROM CODE:** the app does **not** select between the two databases. It **opens two separate connections.** There is no `select_db()` (0 matches), no `db.table` qualification (0 matches), no PDO.

```
$_SESSION['tenant_id']  ──►  CONTROL DB (u336643111_control)     [connection #1]
                            table: projects                       opened → 1 query → CLOSED
                            returns database_host/_name/_user/_pass
                                    │
                                    ▼
                            HMS DB (u336643111_hms)              [connection #2]
                            all 123 application tables            kept open, global $conn
```

**Key consequence — VERIFIED:** every single HTTP request, including every dashboard AJAX poll, pays an extra **control-DB connect + query + disconnect** round trip (`includes/db.php:11-48`).

### 4.2 Tenant resolution sources

| Source | Location | Trust |
|---|---|---|
| `$_SESSION['tenant_id']` | `includes/db.php:6` | Set at login (`index.php:321`) |
| POST `tenant_id` | `index.php:165` | Login form |
| **GET `tenant_id`** | `includes/public_tenant.php:17-34` | **Unauthenticated, attacker-supplied**, regex-gated `^HMS[0-9]+$` |
| `remember_tenant` cookie | `index.php:27` | Attacker-supplied |
| **Literal `'HMS1'`** | 4 ABDM endpoints (below) | **Hardcoded constant** |
| `hospital_id` | `patient/login_process.php:65` | Different key than `tenant_id` |

> 🔴 **4 ABDM callbacks are hardwired to `tenant_id='HMS1'` + `hospital_id=3`:**
> `api/v3/hip/health-information/request/index.php:361,374,482` · `api/v3/hiu/health-information/data-push/index.php:57,70` · `api/v3/hiu/health-information/on-request/index.php:44,57`.
> These **silently route to the wrong tenant** in any other deployment.

### 4.3 The tenancy control plane — VERIFIED FROM CODE + DUMP

Table `projects` (control DB, 2 rows) is the tenancy registry. Notable columns: `tenant_id`, `hospital_id`, `database_host/_name/_user/_password`, `access_status` (`Allowed`/`Blocked`), `deployment_type`, `domain`.

> 🟡 **VERIFIED FROM DUMP — `projects` has NO index other than the PK.** No index on `tenant_id`, `domain`, or `access_status`. Harmless at 2 rows; a full scan as soon as tenants grow.
> 🟡 `deployment_type` and `domain` are selected (`includes/tenant.php:59-60`) but **never read** anywhere — vestigial.
> 🔴 **In the dump, `tenant_id` row 2 does NOT match the `^HMS<digits>$` convention** the code validates against. See §6.4.

### 4.4 `SINGLE TENANT/` — VERIFIED FROM CODE

**A 3-file drop-in replacement kit, never referenced by any runtime file** (0 inbound references). `SINGLE TENANT/db.php:4-7` contains **plaintext HMS credentials in the document root**. `installation.txt` documents swapping out `includes/db.php` and deleting the tenant layer entirely.

---

## 5. Document Root, URL Structure & Rewrite Rules — VERIFIED FROM CODE

### 5.1 The app must BE the document root

**VERIFIED FROM CODE, unambiguous:**
- **313 of 475** files build includes as `$_SERVER['DOCUMENT_ROOT'] . '/includes/…'`.
- All internal links are **root-absolute**: `/dashboard/index.php`, `/modules/…`, `/assets/…`.
- All 20+ `.htaccess` rewrite targets are root-absolute.
- `index.php:9-12` **force-redirects to HTTPS** using unvalidated `$_SERVER['HTTP_HOST']`.

> **The app is not deployable in a subdirectory** without rewriting ~313 includes and every link.

### 5.2 `.htaccess` — the only app-level one (74 lines)

Three families of redundant rules expose each ABDM callback under **three different URL prefixes** (`/api/v3/…`, `/v3/…`, `/api/callbacks/v3/…`), tripling the unauthenticated attack surface.

> 🔴 **The IP allow-list (lines 60-74) is a no-op.** It uses Apache 2.2 `allow from` syntax. Apache 2.4 requires `Require ip`; without `mod_access_compat` these directives are **silently ignored**. Even where honoured, `allow` applies to the **root directory**, and `mod_rewrite` re-authorises against the **rewrite target** (`/api/v3/…`), which has **no `.htaccess`**. **The ABDM callbacks are effectively unprotected.**

> 🔴 **The `.htaccess` contains no `deny`, no `Require`, no `<Files>`, no `<Directory>`, no `Options -Indexes`.** This directly causes the §7.1 credential exposure.

### 5.3 Rewrite rules pointing at **non-existent** files — VERIFIED

| Rule | Line | Target file |
|---|---|---|
| `^api/v3/hiu/consent/request/on-notify/?$` | 24 | **MISSING** |
| `^api/v3/hiu/health-information/on-notify/?$` | 27 | **MISSING** |
| `^api/v3/hiu/patient/care-context/on-discover/?$` | 56 | **MISSING** |
| `^api/v3/hiu/patient/care-context/on-init/?$` | 57 | **MISSING** |
| `^api/v3/hiu/patient/care-context/on-confirm/?$` | 58 | **MISSING** |

**These 5 URLs 404 — and are publicly advertised forms.** Line 30 and line 50 are duplicate `data-push` rules.

---

## 6. Database Dump Analysis — VERIFIED FROM CODE (static text analysis)

> Analysis was **text-file reads only**. No database was connected to. **No row data values are reproduced.**

### 6.1 Dump metadata

| | HMS | Control |
|---|---|---|
| Producer | phpMyAdmin 5.2.2 | phpMyAdmin 5.2.2 |
| Source server | **11.8.9-MariaDB-log** | same |
| Generated | 2026-10-07 16:23 | 2026-10-11 09:34 |
| Tables | **123** | **3** |
| Data rows | **7,812** | **3** |
| Views / Triggers / Routines / Events | **0 / 0 / 0 / 0** | 0 / 0 / 0 / 0 |
| `DEFINER=` clauses | **0** | 0 |
| Engine | 100% InnoDB | 100% InnoDB |
| FK constraints | 25 | 1 |
| Empty tables | 18 | 1 |

**Positives worth stating plainly:** zero triggers, zero views, zero routines, zero `DEFINER` clauses. This eliminates the single most common XAMPP import failure mode. The dump is round-trip safe (FKs are applied as trailing `ALTER TABLE`s after data insert). Estimated import time **< 5 seconds** — this is a very small dataset.

### 6.2 `database_backups` vs `database_work` — the only delta

**VERIFIED:** the two sets differ in **exactly one way**.

- `u336643111_control.sql` — **byte-identical** (SHA-256 match).
- `u336643111_hms.sql` — **8 lines changed**, identical in nature: table-level `COLLATE=utf8mb4_uca1400_ai_ci` → `utf8mb4_unicode_ci`. (−3 bytes × 8 = −24 bytes.)

Affected tables: `abdm_user_linking_otps`, `audit_logs`, `branch_master`, `charges_master`, `charges_type_mapping`, `ipd_payment_items`, `medical_certificates`, `prescribed_medicines`.

No auto-increment changes. No lock statements in either file (neither has `LOCK TABLES`).

> 🔴 **The `database_backups` copy uses `utf8mb4_uca1400_ai_ci`, a MariaDB 10.10+ UCA-14.0 collation.** On XAMPP's bundled MariaDB (typically 10.4/11.4 depending on build), this **will fail `CREATE TABLE` on 8 tables** unless the server is ≥ 10.10 — the first being `abdm_user_linking_otps`, which would leave a **partially created ABDM schema**.
>
> ✅ **Import `D:\WebSplend-HMS\database_work\u336643111_hms.sql` instead.** It is functionally identical and already normalised. This is the recommended source file.

### 6.3 ABDM table inventory — 12 tables, all PRESENT

| Table | Rows | Cols | Schema↔code alignment |
|---|---|---|---|
| `abdm_m1_settings` | 1 | 8 | ✅ No missing columns |
| `abdm_m2_settings` | 1 | 14 | ✅ No missing columns |
| `abdm_m3_settings` | 1 | 15 | ✅ No missing columns |
| `abdm_m3_sessions` | 59 | 13 | ✅ No missing columns |
| `abdm_patient_links` | 2 | 9 | ✅ No missing columns |
| `abdm_care_contexts` | 12 | 9 | ✅ No missing columns |
| `abdm_m3_consent_requests` | 22 | 33 | ✅ No missing columns |
| `abdm_m3_consent_artefacts` | 5 | 10 | ✅ No missing columns |
| `abdm_m3_callbacks` | 112 | 12 | ✅ No missing columns |
| `abdm_m3_health_information_requests` | 23 | 26 | ✅ No missing columns |
| `abdm_user_linking_transactions` | 62 | 11 | ✅ No missing columns |
| `abdm_user_linking_otps` | 9 | 8 | ✅ No missing columns |

> ✅ **Excellent alignment.** All 11 requested tables exist (plus `abdm_user_linking_otps` bonus). **Zero missing columns across all 12 tables** — no `Unknown column` fatal is possible from schema/code drift. Every schema-only column is AUTO_INCREMENT or `DEFAULT current_timestamp()`.
>
> **But per the audit mandate, row counts prove only that the ABDM layer has been *exercised* — not that any given flow *works*.** 112 callback rows with only 22 consent requests and 5 artefacts is consistent with high callback volume against low success.

### 6.4 Tables referenced by code but MISSING from both dumps — VERIFIED

| Table | Files | Effect |
|---|---|---|
| **`software_subscriptions`** | **11 files** | 🔴 **FATAL** — note **plural**; the dump has `software_subscription` (**singular**) |
| **`software_invoices`** | **10 files** | 🔴 **FATAL** — no definition anywhere |
| **`software_payments`** | **3 files** | 🔴 **FATAL** — no definition anywhere |
| `announcement_departments` | 1 | 🟢 Benign — statement is commented out |

Verified across `swbilling/index.php`, `invoices.php`, `invoice_view.php`, `invoice_print.php`, `payments.php`, `subscription_save.php`, `renew_subscription.php`, `renew_subscription_save.php`, `payment_save.php`, `includes/functions.php:66`, and 3 `swbilling/ajax/*` files.

> 🔴 **The entire subscription-billing module (`swbilling/`) will fatal immediately** with `Table 'u336643111_control.software_invoices' doesn't exist`. The control DB export is **incomplete** relative to the code that reads it.

### 6.5 Index gaps on ABDM hot paths — VERIFIED

| Table | Query pattern | Index | Verdict |
|---|---|---|---|
| `abdm_patient_links` | `WHERE abha_address = ?` | **none** (only `abha_number`) | 🔴 **Full table scan on the ABHA confirm/init path** |
| `abdm_m3_callbacks` | `WHERE hospital_id=? AND callback_type=? AND payload LIKE ?` | separate single-col idx | 🟠 No composite; leading-wildcard LIKE forces a scan anyway. **Already 112 rows / 439 KB — largest table in the dump, no retention policy.** |
| `abdm_m3_callbacks` | admin filter on `processing_status` | **none** | 🟠 Full scan |
| `projects` | tenant resolution | **none** beyond PK | 🟠 |
| `opd_prescriptions` | FHIR generator lookups | **PK only — no indexes at all** | 🟠 |
| `abdm_m3_sessions` | `(hospital_id, environment)` | ✅ composite present | 🟢 Correct |
| `abdm_care_contexts` | `(patient_id, care_context_reference)` | ✅ UNIQUE composite | 🟢 Correct |
| `abdm_m3_health_information_requests` | `transaction_id`/`request_id`/`consent_id` | ✅ all indexed | 🟢 Correct |
| `abdm_user_linking_transactions` | `transaction_id`/`request_id`/`patient_id`/`abha_address` | ✅ all indexed | 🟢 Correct |

### 6.6 Secrets at rest in the dumps — VERIFIED (values redacted)

🔴 **Both `.sql` files are secret-bearing artefacts.** They must be handled as confidential:

- `projects.database_password` — 2 non-empty **plaintext** values (control DB).
- `abdm_m1_settings.client_secret`, `abdm_m3_settings.client_secret` — plaintext.
- `abdm_m3_sessions.access_token` / `.refresh_token` — **59 rows of live bearer/refresh tokens.**
- `abdm_m3_health_information_requests.private_key` / `.key_value` — up to 23 ECDH keypairs + data keys.
- `users.password` (7), `patient_master.password` (5), `control_users.password_hash` (1, bcrypt ✅), `remember_tokens.token` (1).

> **Recommendation: treat both dump files as confidential material. Do not commit, email, or place them in a web-served directory.**

### 6.7 Import readiness

| Construct | Result |
|---|---|
| `DEFINER=` | **0** ✅ no grant repair needed |
| `GTID_PURGED` | 0 ✅ |
| `utf8mb4_0900_ai_ci` (MySQL 8 only) | **0** ✅ |
| `utf8mb3` | 0 ✅ |
| **`utf8mb4_uca1400_ai_ci`** | **8** 🔴 **backup copy only — use `database_work`** |
| `utf8mb4_general_ci` (legacy) | 4 — warning only |
| Generated columns / partitions | 0 ✅ |
| `CHECK (json_valid(...))` | 2 — ignored on very old servers |
| `int(11)` display widths | many — deprecation warnings only, never errors |
| `SQL_MODE` | `NO_AUTO_VALUE_ON_ZERO` only — **permissive, import-friendly** |

> ⚠️ **Neither dump contains `CREATE DATABASE` or `USE`.** The target schema must be created and selected first.
> ⚠️ **The two dumps must go into two different databases.** They cannot be concatenated — `software_subscription` appears in **both** with *different* schemas, which is a silent-corruption trap.
> 🟡 `software_subscription` also appears in the **HMS** dump as table #107, distinct from the control-DB table.

---

## 7. Errors, Incomplete Workflows, Risky Assumptions — VERIFIED FROM CODE

> Ranked by severity. Every item is traceable to specific lines.

### 7.1 CRITICAL

**C1 — `includes/control_db.php` is a plaintext credential file in a web-reachable path.**
VERIFIED: the file returns `['host','name','user','pass']` (values redacted). Its own comment at `:4` says *"Never expose this file directly — /includes/ should be blocked in .htaccess."* **The `.htaccess` contains no `deny`, `Require`, `<Files>`, or `<Directory>` directive.** Today the file `return`s rather than `echo`es, so it prints nothing — **that is the entire margin.**

**C2 — Identical control-DB credentials duplicated at 12 locations**, 5 inline in `new mysqli(...)` argument lists (see §3.1). Partial rotation silently breaks ABDM callbacks.

**C3 — Per-tenant DB passwords stored in plaintext** in `projects.database_password` and read into PHP on **every request** (`includes/db.php:26-46`). Read access to the control DB yields every tenant's HMS credentials.

**C4 — Fatal bug: undefined constant `ABDM_M2_X_CM_ID`.**
VERIFIED by direct grep: the constant is defined as `ABDM_M2_CM_ID` at `modules/abdm/m2_config.php:28,31` and used correctly in **10 other places**. The typo appears in exactly one location:
```php
// api/v3/hip/link/care-context/confirm/index.php:558
'X-CM-ID: ' . ABDM_M2_X_CM_ID,
```
On PHP 8 this throws an uncaught `Error`. Critically, the surrounding `try/catch` **ends at line 502**, and the response to the inbound request has **already been flushed** (lines 427-446). Net effect: **the outbound ABDM 4.3.2 on-confirm call silently never happens.** Secondary: `X-BRIDGE-ID` is also omitted from this call, unlike every other M2 call.

**C5 — PHI and bearer tokens logged to web-reachable files.**
VERIFIED: `abdm_callback_debug.txt` sits in the **document root** and is written by `api/v3/hip/token/on-generate-token/index.php:9-23`, which dumps all `HTTP_*` server vars **including `HTTP_AUTHORIZATION`, unredacted**, plus the full raw request body. No `.htaccess` denies `*.log`/`*.txt`.
Categories confirmed present in that file (values withheld): **ABHA identifier, an ABHA link token (JWT), a gateway bearer token (signed JWT with embedded claims), HIP identifier, request/tracing IDs, internal hostname.**

Eight further debug logs, the largest being `api/v3/hiu/health-information/data-push/abdm_data_push_debug.log` at **379 KB**. Also on disk: `modules/abdm/abdm_token_cache.json` (a **live ABDM bearer token**, 1,526 bytes) and `modules/abdm/m2_auth_test.log` (12 KB).

> Note the inconsistency: `consent/request/hip/notify/index.php:10-12` **does** redact Authorization. The others do not.

**C6 — Two unauthenticated debug sinks.**
VERIFIED: `api/v3/hiu/consent/request/on-init/debug.php` and `api/v3/hiu/health-information/on-request/debug.php` have **no session, no auth check**, and return a hardcoded `202 {"success":true}` after dumping full headers + body to disk. They are directly fetchable at a guessed URL.

**C7 — No callback endpoint validates the `Authorization` bearer token.**
VERIFIED across all 13 `api/v3/**/index.php`: the header is read only for a debug "was it present" flag (e.g. `on-generate-token/index.php:18`) or redacted for logging. **No JWT verification, no HMAC, no signature check anywhere in the codebase.**

Source validation degrades to `X-HIP-ID` / `X-HIU-ID` **string equality** — a public, enumerable, non-secret identifier. Worse, **4 endpoints skip the check entirely when the header is absent:**
- `hip/link/care-context/init/index.php:175` — `if ($hipId !== '' && …)`
- `hip/link/care-context/confirm/index.php:215,222`
- `hip/health-information/request/index.php:475-498` — falls back to **`hospital_id = 3`** if no `hip_id` matches
- **`hiu/health-information/data-push/index.php` — NO source validation at all.** Any caller who knows a `transactionId` gets X25519 ECDH + AES-GCM decryption performed.

### 7.2 HIGH

**H1 — `abdm_patient_links.abha_address` has no writer in any UI path — INCOMPLETE.**
VERIFIED: the column is written in exactly one place, `api/v3/hip/token/on-generate-token/index.php:245`, which is a **gateway callback**. Registration writes only `abha_number` (`modules/registration/save_patient_registration.php:261-272`) — `abha_address` is **never** set there.
But M3 consent init **requires** it:
```php
// modules/abdm/m3/api/consent_request_init.php:164
'ABHA address is missing for this patient.'
```
**Therefore every M3 consent request fails unless a gateway `on-generate-token` callback has previously fired for that patient** — and per C4, the system does not reliably trigger that flow itself.

**H2 — `abdm_m3_health_information_requests.data_response` is never persisted — INCOMPLETE.**
VERIFIED by grep across all non-vendor PHP: the column is **only SELECTed** (`health_information_callback_monitor.php:57,147`; `health_information_on_request_monitor.php:58`). The only writer anywhere is the **test harness** `modules/abdm/m3/m3_health_data_push_test.php  old:158`.
The data-push endpoint **decrypts the FHIR bundle successfully** (`api/v3/hiu/health-information/data-push/index.php:377-537`) and then reads the result only via `count()` (`:559`, `:689`). **Decrypted patient health data is discarded.**

**H3 — No working data-push producer.**
VERIFIED: three FHIR R4 generators exist (`m3_prescription_fhir.php`, `m3_opd_consultation_fhir.php`, `m3_discharge_summary_fhir.php`) and two validators, but **nothing on the production path calls a generator to produce an outbound bundle.** The only caller of a generator is `m3_fhir_validator.php`. HMS-as-HIP therefore has **no outbound data-push in production**.

**H4 — OTP is never delivered.**
VERIFIED: `api/v3/hip/link/care-context/init/index.php:544-606` — the SMS gateway calls are **commented out**, and the code logs `"OTP GENERATED (LOG ONLY MODE)"` (`:549`) then writes the OTP to a **plaintext web-reachable log** (`:552`). The care-context confirm flow is therefore not completable by a real user.

**H5 — CSRF protection exists on only 2 of ~475 files.**
VERIFIED: 8 total `csrf` occurrences, all inside `index.php` and `SINGLE TENANT/index.php`. **Every** state-changing POST is unprotected — admissions, billing, discharges, prescriptions, user creation, and **permission grants**.

**H6 — ~266 non-API files have no `$_SESSION['user_id']` guard.** Worst offenders are `ajax/` handlers that return JSON, several of which mutate state or return patient data: `modules/inventory/update_{item,store,uom,party,category}_status.php` (state-mutating), `modules/registration/ajax/get_patient_full_details.php`, `get_abha_details.php`, `modules/pharmacy/ajax/get_sale_patients.php`, `modules/certificates/ajax/{get,search}_*_patient*.php`, `payments/ajax_get_gateway_config.php`.

**H7 — Tenant injection via `includes/public_tenant.php:33`**, which writes an attacker-supplied `$_GET['tenant_id']` straight into `$_SESSION['tenant_id']`. Combined with the 4 ABDM endpoints' hardcoded `'HMS1'`, tenant enumeration is trivial and cross-tenant read is possible on the public appointment endpoints.

**H8 — `display_errors=1` + `error_reporting(E_ALL)`** on entry points and in **157 files**, while `new mysqli` runs in silent mode. DB errors, file paths, and stack traces leak to the browser.

**H9 — Predictable patient portal passwords.**
VERIFIED: `modules/registration/save_patient_registration.php:85` and `:189` generate passwords as `ucfirst(strtolower(first_name)) . '@' . substr(mobile, -4)` — **first name + last 4 of mobile.** Trivially enumerable against a known name/phone.

**H10 — Session-scoped brute-force throttle.** `index.php:202-204` blocks after 5 attempts, but is keyed on `$_SESSION` — bypassed by dropping the cookie. No IP or account lockout.

**H11 — `logout.php` does not clear `remember_token`/`remember_tenant`.** A user who ticked "Remember me" is silently re-authenticated after logout.

### 7.3 MEDIUM

**M1 — No ABDM-standard response envelope anywhere.** VERIFIED: not one endpoint emits the ABDM `{status, error:{code,message}, response:{requestId}}` shape. All return `{"success":false,"message":"..."}`. Incoming ABDM error objects are *parsed* (`on-init/index.php:61-64`) but never *emitted* — so the gateway cannot distinguish error classes from these callbacks.

**M2 — Consent requester identity is hardcoded test data.** VERIFIED, `modules/abdm/m3/api/consent_request_init.php:274-281`:
```php
$requesterName = 'Smith';  $requesterIdentifierType = 'REGN01';
$requesterIdentifierValue = 'MH1001';  $purposeRefUri = 'www.test.com';
```
`INCOMPLETE` — these are not tenant configuration; every tenant on this codebase would issue consent requests under the same fabricated requester identity.

**M3 — Consent matching is fragile.** `hiu/consent/on-fetch/index.php:446-451` locates the consent by `LIKE '%{consentId}%'` over two JSON blob columns, then falls back to scanning the callback log (`:502-511`). Not a correctness guarantee.

**M4 — `facility_id` conflated with HIP.** `consent_request_init.php:507-508` writes the **same `$facilityId` into both `hip_id` and `hip_name`**.

**M5 — No consent-artefact signature verification.** `on-fetch` reads `consent.signature` (`:201`) and stores it verbatim; it is **never verified**. No expiry / `dataEraseAt` enforcement.

**M6 — `rights_type_id === 1` is an unconditional permission bypass** (`includes/permissions.php:27`) with no DB re-verification — a demoted admin retains privileges until session expiry.

**M7 — Permission model ignores branch/hospital scope.** `checkPermission(slug, action)` never consults `$_SESSION['branch_id']` or `['hospital_id']`.

**M8 — Patient portal passwords retained in memory for emailing** (`settings/users/save_user.php:83,136`) with a `// DO NOT STORE` comment at `:208`.

**M9 — `addAuditLog()` makes a blocking external HTTP call to `ip-api.com` on every audit write** (`includes/audit_log.php:25-28`) — adds latency to login, leaks user IPs to a third party over plaintext HTTP.

**M10 — Non-transactional `DELETE`-then-`INSERT` in `settings/rights/save_rights.php:69-116`** → concurrent permission edits lose rows.

**M11 — Non-transactional barcode sequence increment** (`includes/barcode_helper.php:128-143`) → duplicate barcodes under concurrency. Clinical labelling risk.

**M12 — No consent-scope enforcement at data-push.** `careContextReference` is never checked against the consent's permitted care contexts.

**M13 — `display_errors` set inconsistently:** `error_reporting(0)` on 3 consent callbacks, `E_ALL` + `display_errors=1` on 4 ABDM production callbacks (`hiu/consent/on-fetch/index.php:10-11`, `consent_callback_monitor.php:2-4`, `abha_request_mobile_otp.php:2-3`, `m3_discharge_summary_fhir.php:3-4`).

**M14 — Non-CSPRNG request IDs.** `mt_rand`-based `abdmRequest()`/`generateRequestId()` duplicated across **14 files in 7 independent copies**.

### 7.4 LOW / HYGIENE

- **L1 — `login/tenant_blocked.php` is a guaranteed fatal.** VERIFIED: calls `e()` at `:8`, but `e()` is defined only in two *unrelated* page files (`modules/appointments/appointment_list.php:178`, `modules/billing/ipdbilling/ipd_invoice/generate_invoice.php:16`). It also references `$tenantId` at `:7`, **never assigned in that file**. **Every blocked-tenant user gets a 500.**
- **L2 — Dead/duplicate files in the doc root**, unguarded: `dashboard/index_old.php`, `dashboard/ajax/dashboard_stats_old.php`, `modules/abdm/callback.php` (a pure stub returning hardcoded success), `modules/abdm/m3/m3_health_data_push_test.php  old`, `modules/abdm/m3/m3_health_information_notify_test.php old`.
- **L3 — Hardcoded credentials/tenant/hospital in committed code**, values redacted: 8 files with inline control-DB literals; 4 files with the tenant DB name inline in SQL; `hospital_id = 3` in 4 ABDM endpoints + `m3/session_diagnostic.php:12`; hardcoded `hospital_id = 3` session probe.
- **L4 — Real patient identifiers hardcoded in curl test harnesses:** `modules/abdm/test/m2_index.php:70-89`, `modules/abdm/test/test_user_linking_discover.php:7-26`.
- **L5 — A live production hostname is hardcoded in source** at `modules/abdm/test/m2_index.php:60`. **This is a hazard for any local-run test — that harness will target production if executed.**
- **L6 — Hardcoded DB row id:** `modules/abdm/m3/test_x25519_import.php:12` — `WHERE id = 16`.
- **L7 — Forgot-password flow is a no-op** (`index.php:510-529`) — always displays success, calls no backend. Users believe a reset was sent.
- **L8 — 79 files end with a bare `?>`** — any trailing newline is emitted to the client, which can silently break strict JSON parsing.
- **L9 — TLS verification inconsistent.** Only `abha_verify_otp.php:14-15` sets `CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST`. The **outbound ABDM acknowledgements** in `consent/request/hip/notify/index.php:343-356`, `hiu/consent/request/notify/index.php:388-401`, and `hip/health-information/request/index.php:831-848` set **no TLS options at all**.
- **L10 — MD5 used as the data-push integrity primitive** (`data-push/index.php:490`). Matches the sandbox spec but is not collision-resistant.
- **L11 — `remember_tenant` cookie cleared with `httponly => false`** (`index.php:146`) but set with `httponly => true` (`:307`).
- **L12 — Staff and patient sessions share one PHP session** with disjoint key namespaces and no mutual exclusion.
- **L13 — Clean on dangerous functions:** `eval`/`unserialize`/`extract`/`create_function`/`shell_exec`/`system`/`passthru`/`proc_open` = **0 each**. No legacy `mysql_*`. No UTF-8 BOM or mojibake in any file. This is genuinely good hygiene and worth recording.

---

## 8. ABDM M1 / M2 / M3 Implementation Trace

### 8.1 M1 — ABHA (sandbox/ABDM gateway)

> **Note on naming:** the code does **not** use the `/v0.1/sandbox-abha/…` API style. It uses the newer `/v3/profile/*` and `/v3/enrollment/*` paths. Evidence: `modules/abdm/config.php:41-46` defines base URLs and every M1 file posts to those paths.

| File | Endpoint | Input | DB |
|---|---|---|---|
| `config.php` | base URL constants | – | `abdm_m1_settings` SELECT |
| `auth.php` | gateway session | – | `abdm_m1_settings` SELECT |
| `abha_request_otp.php` | `/v3/profile/login/request/otp` | `$_POST['abha_number']` | – |
| `abha_verify_otp.php` | `/v3/profile/login/verify` | `$_POST[txnId,otp]` | – |
| `abha_fetch_request_otp.php` / `abha_fetch_verify_otp.php` / `abha_fetch_profile.php` | fetch-profile flow → X-token | `$_POST` | – |
| `abha_request_aadhaar_otp.php` / `abha_verify_aadhaar_otp.php` | `/v3/enrollment/*` → **ABHA creation** | `$_POST[aadhaar,txnId,otp,mobile]` | – |
| `abha_request_mobile_otp.php` / `abha_verify_mobile_otp.php` | login-by-mobile → T-token → profile (539 lines) | `$_POST[mobile,txnId,otp]` | – |
| `abha_phr_search.php` / `_request_otp.php` / `_verify_otp.php` | PHR-web address flow | `$_POST` | – |

**Crypto:** RSA-OAEP via `openssl_public_encrypt` against the ABDM cert in **11 files**.
**Headers:** `Authorization`, `REQUEST-ID`, `TIMESTAMP`, `X-CM-ID`, `X-token`, `T-token` (names only).

> **Classification — IMPLEMENTED BUT UNVERIFIED.** M1 is the **most complete** ABDM layer: ABHA creation *and* login by three different methods, all with OTP verification and OAEP encryption. The RSA/HTTP plumbing is structurally sound. **But** no response-validation logic was traced, TLS options are inconsistent (L9), and nothing here was executed. The dump contains no M1 transaction tables, so M1 success cannot be inferred from data.

### 8.2 M2 — HIP/HIU linking

| File | Endpoint | Tables |
|---|---|---|
| `m2_config.php` | constants | `abdm_m2_settings` SELECT |
| `m2_auth.php` | `/hiecm/gateway/v3/sessions` | `abdm_m1_settings`, `abdm_m2_settings` |
| `m2_bridge.php` | bridge URL PATCH, bridge-services list/detail | `abdm_m2_settings` |
| `m2_link_token.php` | `/hiecm/v3/token/generate-token` | `patient_master`, `abdm_patient_links` |
| `m2_care_context.php` | `/hiecm/hip/v3/link/carecontext` | `abdm_care_contexts` (INSERT…ON DUPLICATE, UPDATE ×3) |
| `m2_user_linking.php` | patient matching + discovery builder | `patient_master`, `abdm_patient_links`, `appointments`, `opd_prescriptions` |
| `m2_user_linking_api.php` | `/hiecm/user-initiated-linking/v3/patient/care-context/on-discover` | – |

#### Care-context state machine — VERIFIED: **three competing lifecycles**

**Lifecycle A — direct M2 link (fire-and-forget), triggered automatically on prescription save:**
`modules/opd/save_prescription.php:905-911` calls `abdmM2LinkCareContext(..., 'PRESCRIPTION')` whenever `link_status !== 'Linked'`.
`Pending` (INSERT, `m2_care_context.php:143`) → `Linked` on 2xx (`:247`) / `Failed` on cURL error (`:224`) / `Failed` on non-2xx (`:269`).

**Lifecycle B — gateway-driven 4.3.1 / 4.3.2 (the real state machine), on `abdm_user_linking_transactions.status`:**
```
(pending at discover)
  → 'Failed'     api/v3/hip/patient/care-context/discover/index.php:242,285,341
  → 'Discovered' discover/index.php:341,356   (after successful on-discover)
  → 'Linking'    hip/link/care-context/init/index.php:630
  → 'Confirmed'  hip/link/care-context/confirm/index.php:328
```
Guards: `init` requires status ∈ {`Discovered`,`Linking`} (`:235`); `confirm` requires status `Linking` and validates `linkRefNumber` via `hash_equals` on the JSON blob (`:235,259`).

**Lifecycle C — legacy ack:** `api/v3/link/on_carecontext/index.php:200-241` flips **all** `Pending` care contexts for a patient to `Linked`/`Failed` based on a **free-text string match** against `'Successfully Linked care context'`.

> **INCOMPLETE.** `abdm_care_contexts.link_status` is a 3-value enum (`Pending`/`Linked`/`Failed`) with **no per-reference confirm tracking**, and `confirm/index.php:367-420` bulk-upserts **all selected contexts** to `Linked`. Three writers with two different lifecycles and no reconciliation is a data-integrity hazard.
>
> **Lifecycle B is additionally broken at its final step by C4** — the outbound on-confirm never fires.

#### ABHA → patient linking — VERIFIED

Dual-write across two tables:
1. `patient_master.abha_id varchar(50)` — **confirmed present in the dump.** Written at `save_patient_registration.php:107-110,141,203`.
2. `abdm_patient_links` — canonical. **Confirmed present.** Written at `save_patient_registration.php:261-272` — but **only `abha_number`; `abha_address` is never set here** (see H1).

> `abdm_patient_links` has **`UNIQUE unique_patient(patient_id)`** — **one ABHA link per patient, enforced.** A patient cannot hold a second ABHA number without an explicit update. With 2 rows the constraint is untested at scale.

### 8.3 M3 — Consent & health-information exchange

| File | Endpoint | Auth | Tables |
|---|---|---|---|
| `m3/api/gateway_session.php` | `/hiecm/gateway/v3/sessions` | session | `abdm_m3_settings`, `abdm_m3_sessions` (UPDATE+INSERT) |
| `m3/api/openid_configuration.php` | `/.well-known/openid-configuration` | session | `abdm_m3_settings` |
| `m3/api/oauth_certificate.php` | `/gateway/v3/certs` | session | `abdm_m3_settings` |
| `m3/api/consent_request_init.php` | `/consent/v3/request/init` | session | `abdm_m3_consent_requests` INSERT ×2 |
| `m3/api/consent_request_status.php` | `/consent/v3/request/status` | session | `abdm_m3_consent_requests` UPDATE |
| `m3/api/consent_request_fetch.php` | `/consent/v3/fetch` | session | `abdm_m3_consent_requests` |
| `m3/api/health_information_request.php` | `/data-flow/v3/health-information/request` | **session_start only — no user check** | `abdm_m3_health_information_requests` INSERT + UPDATE ×2 |
| `m3/api/consent_callback_monitor.php` | — | **none** | `abdm_m3_consent_requests`, `abdm_m3_callbacks` |
| `m3/api/health_information_callback_monitor.php` | — | **none** | `abdm_m3_health_information_requests` |
| `m3/api/health_information_on_request_monitor.php` | — | **no `session_start` at all** | `abdm_m3_health_information_requests`, `abdm_m3_callbacks` |

**Public callbacks:** `on-init`, `on-status`, `notify`, `on-fetch` (HIU), `consent/request/hip/notify` (HIP), `hip/health-information/request`, `hiu/health-information/on-request`, `hiu/health-information/data-push`.

#### Consent workflow trace — VERIFIED

Observed `abdm_m3_consent_requests.status` values across the tree: `Accepted`, `Failed`, `Callback Received`, `Callback Error`, `Pending Callback`, `Status Checked`, `Status Check Failed`, `GRANTED`, `REVOKED`, `DENIED`, `Initiated`.

1. **init** (`consent_request_init.php:429`) → POST → `202` + INSERT.
2. **on-init** (`hiu/consent/request/on-init/index.php`) → INSERT `abdm_m3_callbacks` (`:129`), match on `response.requestId` then `REQUEST-ID` header (`:177-227`), UPDATE consent (`:293`).
3. **on-status** (`.../on-status/index.php`) → INSERT callback (`:105`), match by `consent_request_id` (`:154`), UPDATE status (`:168`), UPSERT `abdm_m3_consent_artefacts` (`:230`).
4. **notify** (`.../notify/index.php`) → INSERT callback (`:182`), UPDATE (`:300`), then **synchronous outbound ack** to `/consent/v3/request/hiu/on-notify` (`:386`).
5. **fetch** (`consent_request_fetch.php:382`) → expects 4.3.8 callback.
6. **on-fetch** (`hiu/consent/on-fetch/index.php`) → INSERT callback (`:360`), store `consent.consentDetail` into `abdm_m3_consent_artefacts.consent_artifact` (`:715`), UPDATE consent (`:813`).
7. **HIP-side notify** (`consent/request/hip/notify/index.php`) → INSERT callback (`:203`), POST ack to `/consent/v3/request/hip/on-notify` (`:321`).

**Requester/responder model — INCOMPLETE.** The requester is 4 columns, but all **hardcoded literals** (M2 above). There is **no responder table** — HIU is a single `hiu_id` string, and `hip_id` is duplicated into `hip_name` (M4).

#### Crypto inventory — VERIFIED

| Primitive | Function | Location |
|---|---|---|
| RSA-OAEP | `openssl_public_encrypt` | 11 M1 files |
| X25519 keygen | `openssl_pkey_new(['x25519'=>[]])` | `m3/api/health_information_request.php:233` |
| ECDH derive | `openssl_pkey_derive` | `data-push/index.php:377` |
| HKDF-SHA256 | `hash_hkdf('sha256',…)` | `data-push/index.php:403` |
| AES-256-GCM | `openssl_decrypt(…,'aes-256-gcm',…,OPENSSL_RAW_DATA,$iv,$tag)` | `data-push/index.php:468` |
| OTP hashing | `password_hash`/`password_verify` | `init/index.php:517`, `confirm/index.php:152` |
| Integrity | `md5` + `hash_equals` | `data-push/index.php:490-500` |
| **JWT verification** | **NONE** | — |

**Fidelius decryption (VERIFIED, correctly implemented):**
```php
$sharedSecret   = openssl_pkey_derive($hipPublicKey, $hiuPrivateKey, 32);      // :377
$combinedNonce  = $hipNonceRaw ^ $hiuNonceRaw  (32 bytes);                    // :396
$aesKey         = hash_hkdf('sha256', $sharedSecret, 32, '', substr($combinedNonce,0,20)); // :403
$decrypted      = openssl_decrypt($ciphertext,'aes-256-gcm',$aesKey,OPENSSL_RAW_DATA,$iv,$tag); // :468
```
Validates `md5` via `hash_equals` (`:490-500`) and asserts `resourceType === 'Bundle'` (`:537`).

> **IMPLEMENTED BUT UNVERIFIED** for the crypto itself — the construction matches the ABDM Fidelius spec and is internally coherent. **But** it was never executed against a live gateway in this audit, and per H2 the plaintext output is **thrown away**. Also: the **X25519 private key is stored in plaintext** at `abdm_m3_health_information_requests.private_key` (written `health_information_request.php:331,364`; read `data-push/index.php:311`).

> **BLOCKED:** OpenSSL X25519 support on the target host is unverified. `modules/abdm/m3/m3_crypto_env_check.php` and `api/test_sodium.php` exist as probes but have not been run.

### 8.4 Summary judgement

| Layer | Verdict |
|---|---|
| **M1 (ABHA)** | **IMPLEMENTED BUT UNVERIFIED** — most complete layer; 3 linking methods, OAEP crypto. TLS config inconsistent (L9). No data evidence available. |
| **M2 (link/care-context)** | **INCOMPLETE** — fatal undefined constant (C4) kills the outbound on-confirm; OTP never delivered (H4); three competing care-context lifecycles. |
| **M3 (consent/HIE)** | **INCOMPLETE** — consent skeleton is shape-correct; but hardcoded sandbox + hardcoded requester identity (M2), no callback authentication (C7), **decrypted health data discarded (H2)**, and **no working data-push producer (H3)**. |

**Overall: the ABDM integration is structurally broad but functionally partial.** Schema and code align excellently (zero column drift across 12 tables). The failures are in configuration hardcoding, callback authentication, workflow completeness, and two silent data-loss paths — not in the database design.

---

## 9. Findings Classification Summary

### 9.1 VERIFIED FROM CODE
- Architecture: procedural PHP, no framework/ORM/router/DI (§1.2).
- PHP >= 8.2 x64 required; app code needs 8.0+ (`includes/permissions.php:67` `match`) (§2.1).
- Required extensions: mysqli, curl, openssl, json, session. sodium **unused** (§2.2).
- No root `composer.json`/`composer.lock`; TCPDF absent from autoloader (§2.3).
- Two-database architecture: two `mysqli` connections, control DB opened/closed per request (§4.1).
- Control-DB credential duplicated at 12 sites, 5 inline in constructors (§3.1).
- `projects` is the tenancy control plane; `projects` has no index beyond PK (§4.3).
- App must be the document root — 313 files depend on `$_SERVER['DOCUMENT_ROOT']` (§5.1).
- `.htaccess` IP allow-list uses Apache 2.2 `allow from` → no-op on 2.4; callbacks unprotected (§5.2).
- 5 rewrite rules target non-existent files (§5.3).
- All 12 ABDM tables present; **zero missing columns** across all of them (§6.3).
- `software_subscriptions` / `software_invoices` / `software_payments` missing from both dumps → `swbilling/` fatals (§6.4).
- `database_work` copy differs from `database_backups` by 8 collation lines only (§6.2).
- Both dumps contain 59 bearer tokens, 2 client secrets, ECDH private keys, tenant passwords (§6.6).
- Fatal undefined constant `ABDM_M2_X_CM_ID` at `confirm/index.php:558`, outside the try/catch (§7.1 C4).
- `data_response` never persisted by production code (§7.2 H2).
- `abha_address` has no writer outside a gateway callback, yet consent init requires it (§7.2 H1).
- CSRF on 2 of ~475 files (§7.2 H5).
- No callback validates `Authorization`; 4 skip source checks entirely (§7.1 C7).
- No ABDM response envelope emitted by any endpoint (§7.3 M1).
- `eval`/`unserialize`/`extract`/shell-exec/legacy `mysql_*` = 0 occurrences (§7.4 L13).

### 9.2 IMPLEMENTED BUT UNVERIFIED
- **All M1 ABHA flows** (create by Aadhaar, login by ABHA, login by mobile, PHR-web address) — §8.1.
- **Fidelius crypto** (X25519 ECDH + HKDF + AES-256-GCM) — correct against spec, never executed — §8.3.
- **M3 consent request init/status/fetch** outbound calls — structurally complete, gateway responses never validated — §8.3.
- **FHIR R4 bundle generators** (prescription, OP consultation, discharge summary) + 2 validators — never invoked on a production path — §7.2 H3.
- **All `mysqli` DB operations** — no query was executed against either database.
- **TCPDF PDF generation** (5 consumers, library not in `installed.json`) — version provenance unknown.

### 9.3 INCOMPLETE
- **M2 care-context outbound on-confirm** — dead via undefined constant (§7.1 C4).
- **OTP delivery** — SMS calls commented out; logged to a web-reachable file (§7.2 H4).
- **Data-push persistence** — decrypted PHI discarded (§7.2 H2).
- **Data-push producer** — no generator wired into the push path (§7.2 H3).
- **Consent requester identity** — hardcoded literals (`'Smith'`/`'REGN01'`/`'MH1001'`) (§7.3 M2).
- **Care-context state model** — 3 competing lifecycles, 3-value enum, bulk upsert (§8.2).
- **Consent artefact** — signature stored never verified; no expiry/`dataEraseAt` enforcement (§7.3 M5).
- **`modules/abdm/callback.php`** — hardcoded-success stub, unreferenced (§7.4 L2).
- **2 unauthenticated debug sinks** returning hardcoded 202 (§7.1 C6).
- **`swbilling/` module** — 3 tables missing from the export (§6.4).
- **`login/tenant_blocked.php`** — guaranteed fatal, `e()` undefined (§7.4 L1).
- **Forgot-password flow** — no-op (§7.4 L7).
- **`health_information_request.php`** — session-gated but **no user check** (§8.3).

### 9.4 BLOCKED
- Whether `projects.database_user`/`database_password` from the dump authenticate against local XAMPP — requires import + `SELECT` (values withheld here).
- `projects` row 2's `tenant_id` violates the `^HMS<digits>$` convention, and its `database_name` points at a **schema not present in either dump** — cannot be resolved without the source server.
- Whether the FTP source matches production exactly — no production access permitted.
- OpenSSL X25519 availability on the target host — probes exist but were not run.
- Apache 2.2 vs 2.4, and whether `mod_access_compat` is enabled — server config, not source.
- Missing DDL for `software_subscriptions`/`software_invoices`/`software_payments` — requires `SHOW CREATE TABLE` on the source server.
- Whether the M1/M2/M3 `client_id`/`client_secret` values are valid or expired — BLOCKED deliberately; not inspected.
- Actual ABDM workflow success rates — the dumps contain no transaction log that would establish this.

---

## 10. Recommended Next Steps — Running the App Safely Locally

> Ordered. **Nothing below has been performed.** No code change, no migration, no dependency install, no DB write was executed in Phase 0.

### Stage A — Quarantine (before anything touches a web server)

1. **Treat `D:\WebSplend-HMS` as secret-bearing.** Both `.sql` files, `working_copy\abdm_callback_debug.txt`, `modules/abdm/abdm_token_cache.json`, and the 8 `abdm_*.log` files contain live credentials/tokens/PHI. Move them out of any web-served directory and restrict access.
2. **Never execute `modules/abdm/test/m2_index.php`.** It contains a hardcoded **production hostname** (`:60`) and real patient identifiers (`:70-89`). Block it from execution.
3. **Disable outbound network access** from the local PHP environment (firewall / hosts override / no route) so no ABDM or `ip-api.com` call can escape.

### Stage B — Database (additive only; no schema changes)

4. **Import `D:\WebSplend-HMS\database_work\u336643111_hms.sql`** — **not** the `database_backups` copy — to avoid the `utf8mb4_uca1400_ai_ci` failure. Import `u336643111_control.sql` separately (byte-identical either way).
5. **Create both schemas first** — neither dump contains `CREATE DATABASE`/`USE`.
6. **Verify the import:** `SELECT COUNT(*) FROM abdm_m3_callbacks` → expect **112**; `SELECT COUNT(*) FROM cities` → expect **6,115**.
7. **Reconcile `projects` credentials for local use.** The dump's `database_user`/`database_password` are production values. Either grant that local MySQL user, or set local-only values. **This is a data edit to a control-plane table — decide explicitly and record it.**
8. **Obtain the 3 missing `swbilling` tables** (`SHOW CREATE TABLE` from the source server) or accept that `swbilling/` will fatal. Record which.
9. **Decide on the seeded ABDM data** (59 sessions, 112 callbacks, 23 HI requests, 22 consents). **Recommendation: preserve it as evidence — but note it contains live bearer tokens and ECDH private keys. Do not point it at a real gateway.**

### Stage C — Runtime

10. **Set the PHP runtime to 8.2+ (x64)** with `mysqli`, `curl`, `openssl`, `json`, `mbstring`, `gd`, `zip`, `fileinfo`, `iconv`, `xml`.
11. **Point the document root at `working_copy\` directly** — do **not** put it in a subdirectory.
12. **Serve over HTTPS, or accept the forced redirect at `index.php:9-12` will loop.** That redirect uses unvalidated `HTTP_HOST`.
13. **Expect 2 immediate failures, both known:**
    - `login/tenant_blocked.php` → fatal (`e()` undefined, L1).
    - `swbilling/*` → fatal (`Table … software_invoices doesn't exist`).
14. **Do not run any `m3_*_test.php` or `test/*` harness** — several contain hardcoded row ids, `die()`/`exit()` paths, and one targets production.
15. **Before any local run, add a web-server-level deny for `/includes/`, `/vendor/`, `/uploads/`, `/SINGLE TENANT/`, `*.log`, `*.txt`.** This is the single highest-value change and requires **no PHP edits** — it can be done entirely in the local Apache/Nginx config.
16. **Set `display_errors=0` and route to `error_log`.** 157 files currently force errors to the browser.
17. **Establish a known-good local user.** The dump has 7 staff users; confirm which `rights_type_id` you hold (note **M6** — id 1 bypasses all permissions).

### Stage D — Verify (read-only, no writes)

18. Confirm `GET /index.php` renders and the login POST succeeds.
19. Confirm a tenant resolves: `projects` lookup by `tenant_id` returns `access_status='Allowed'` and the credentials connect.
20. **Do NOT exercise ABDM flows against any environment.** There is currently no callback authentication (C7), no consent-scope enforcement (M12), and hardcoded sandbox/production hostnames in 9 files. A local test could emit real consent or health-information requests.

### Stage E — Before any production consideration (Phase 2+)

21. Remediate, in order: **C1** (credential file exposure) → **C5** (logs in doc root) → **C7** (callback authentication) → **C4** (fatal constant) → **H1** (abha_address writer) → **H2** (data loss) → **H3** (no push producer) → **H5** (CSRF).
22. Move all credentials out of source into a non-web-reachable config or env file; then rotate once, across all 12 sites.
23. Establish a real `composer.json`/`composer.lock` so PHP 8.2 and dependency versions are pinned and auditable.

---

## 11. Prioritized Questions & Checks for the Next Phase

### 11.1 Must answer before any code change

| # | Question | Why it blocks |
|---|---|---|
| **Q1** | Do the local XAMPP DB credentials need to match `projects.database_user`/`database_password`, or may we change the control-DB rows? | Blocks Stage B step 7 — every request resolves the tenant through this row. |
| **Q2** | Where is `tenant_id` for the target hospital actually set, and does it match the `^HMS<digits>$` regex at `includes/public_tenant.php:21`? | `projects` row 2 **violates** the convention (§4.3). If that's the target tenant, login breaks. |
| **Q3** | What is `projects` row 2 pointing at? Its `database_name` is a schema not present in either dump. | That tenant is unreachable from the supplied artifacts. |
| **Q4** | Can we obtain DDL for `software_subscriptions`, `software_invoices`, `software_payments` from the source server? | Determines whether `swbilling/` is in scope for Phase 1. |
| **Q5** | **Has the care-context confirm flow ever completed successfully in production, or is `abdm_user_linking_transactions.status='Confirmed'` (62 rows exist) the only evidence?** | If `Confirmed` rows exist, the C4 constant bug was introduced **after** that point — changing the remediation priority substantially. |
| **Q6** | Is `data_response` expected to persist inbound FHIR, or is the data-push endpoint intentionally a sink? | H2 is either a critical data-loss bug or by-design. **This changes the finding's severity entirely.** |
| **Q7** | Should the seeded ABDM data (59 live bearer tokens, 23 ECDH private keys) be **purged** before any further use? | Security decision with no code answer. |

### 11.2 Must verify by reading (not guessing)

| # | Check | Command/approach |
|---|---|---|
| **Q8** | Confirm no `composer.json` is genuinely absent (vs. simply not copied). | Re-check the FTP root; affects reproducibility claims. |
| **Q9** | Confirm the 5 missing `.htaccess` targets are absent, not misnamed. | Verify `api/v3/hiu/consent/request/on-notify/` etc. — decides whether to create handlers or delete rules. |
| **Q10** | Confirm no production ABDM hostnames remain in non-test files. | 9 files hardcode `dev.abdm.gov.in` + `X-CM-ID: sbx`; all in production paths per §7.3 M13. |
| **Q11** | Determine whether `abdm_m1_settings`/`abdm_m2_settings`/`abdm_m3_settings` rows are populated with live or placeholder credentials. | **Check shape/emptiness only — do not print values.** |
| **Q12** | Enumerate which of the ~266 unguarded `ajax/` endpoints are reachable in a real deployment. | Prioritise for Phase 1 hardening. |

### 11.3 Must answer before touching production config

| # | Question |
|---|---|
| **Q13** | Which Apache version and modules (`mod_access_compat`, `mod_authz_core`) does the **production** server run? Determines whether the `allow from` list is a no-op in production or merely in XAMPP. |
| **Q14** | Is `display_errors=1` active in production, or overridden by `php.ini`? 157 files set it in code. |
| **Q15** | Is there a `.htaccess` **above** the document root (host-level) that denies `/includes/`? C1's severity depends entirely on this. |
| **Q16** | Are the 9 debug/log files in the doc root **live and downloadable in production** right now? C5 is an active-incident question, not just a code-quality one. |
| **Q17** | Has `abdm_callback_debug.txt` (containing a bearer token, an ABHA identifier, and a link token) been **fetched by anyone**? Requires server access-log review. |
| **Q18** | Is there a rotation plan for the control-DB password duplicated at 12 sites? |

### 11.4 Runtime checks for Phase 1 (read-only)

| # | Check |
|---|---|
| **Q19** | After import, does `includes/db.php` resolve the tenant without throwing `Tenant database connection failed.`? |
| **Q20** | Which local `rights_type_id` does the test user hold? (id 1 bypasses **all** permission checks — M6.) |
| **Q21** | Confirm the X25519 probe (`modules/abdm/m3/m3_crypto_env_check.php` / `api/test_sodium.php`) passes on the target OpenSSL build — **locally, with network blocked.** |
| **Q22** | Confirm `patient/dashboard.php`'s 9× `include db.php` does not exhaust connections under XAMPP's default `max_user_connections`. |

### 11.5 Explicitly out of scope for Phase 1

- Calling **any** ABDM endpoint (sandbox or production).
- Exercising consent or data-push flows against a live gateway.
- Rotating, reading, or transcribing any credential value.
- Modifying either database schema or data.
- Refactoring, renaming, or "fixing" application source.

---

## 12. Phase 0 Compliance Statement

| Constraint | Compliance |
|---|---|
| Preserve existing PHP/MySQL architecture & workflows | ✅ No architecture change proposed or made. Findings describe existing behaviour only. |
| Do not rebuild or replace modules | ✅ No replacement proposed. |
| Do not edit/delete/rename/refactor source | ✅ **Zero application files modified.** Only `audit/PHASE_0_INVENTORY.md` created. |
| Do not connect to or modify production | ✅ No production connection. **§10 step 2 flags one test file that would.** |
| No migrations, dependency installs, or DB writes | ✅ None executed. §10 steps 7-8 are *recommendations* requiring explicit approval. |
| Never print credentials/tokens/keys/PII | ✅ **Redacted throughout.** Only variable names, column names, and `file:line` given. §6.6 reports *counts* of secret-bearing columns, never values. |
| Do not treat existence as proof of function | ✅ Explicit 4-way classification (§9). H1/H2 are the clearest examples: schema is perfect, behaviour is broken. |
| No external API calls | ✅ Analysis is static file reading only. |
| Create only this audit report | ✅ One file created: `audit/PHASE_0_INVENTORY.md`. |

---

*End of Phase 0 audit. All findings derived from static reading of source files and SQL dump text. No database was connected to or modified; no application file was changed; no external or production API was called.*