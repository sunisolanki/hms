# WebSplend HMS â€” Project Status & Handover

**Generated on:** 2026-10-13
**Last updated:** 2026-10-14 — **Phase 2 verification completed** (Â§D.23, Â§G.16, Â§E.3, Â§K P1-1/P1-2/P1-4). Registration security closure (Â§D.12), M1 critical security remediation and sync (Â§D.13), operator browser verification of the M1 registration flow (Â§E.3, Â§G.7), and the M2 read-only audit handover (Â§J.1, Â§K, Â§L).
**Purpose:** Continuity source for future development sessions. Read this before touching the codebase; it records what was done, what is verified, and what is not.
**Scope of generation:** Documentation only. No application file, database, or runtime file was modified while writing this document.

---

## How to read this document

Every status uses one of these labels. Do not upgrade a label without new evidence.

| Label | Meaning |
|---|---|
| `COMPLETED` | Done and verified by direct evidence (file inspection, hash comparison, lint, or HTTP response recorded below). |
| `IMPLEMENTED â€” MANUAL TEST PENDING` | Code complete and statically verified, but never exercised in a real browser session. |
| `PENDING` | Known work not yet started. |
| `BLOCKED` | Cannot proceed without an external input (gateway access, approval, a missing table). |
| `NOT VERIFIED` | A claim that has no supporting evidence in this repository. Treat as unproven. |

**Secrets policy:** this document contains no credential values, tokens, JWTs, ABHA numbers, patient identifiers, session IDs, or CSRF tokens. Credentials are referenced by **file and line number only**.

---

## A. Project Overview

WebSplend HMS is a **procedural PHP / MySQL hospital management system** with an in-progress **ABDM (Ayushman Bharat Digital Mission)** integration. It handles patient registration, appointments, OPD, IPD, billing, pharmacy, inventory, certificates, tickets, masters, reports, and subscription billing, plus a separate patient portal and a payment-gateway module.

**Architecture (verified from `audit/PHASE_0_INVENTORY.md` Â§1.2):**

- **No framework, no ORM, no router, no DI container, no service layer, no autoloader for application code.**
- Every page is a directly reachable PHP file â€” there is no front controller.
- 100% `mysqli`; **zero** PDO, zero legacy `mysql_*`, zero `eval`/`unserialize`/`extract`/shell-exec (recorded as good hygiene).
- Two-database tenancy model (see Â§B).
- ABDM integration spans **M1** (ABHA), **M2** (HIP/HIU linking), **M3** (consent + health-information exchange).

**Local environment and the working-copy/runtime split:**

| Item | Value |
|---|---|
| Working copy (edit here) | `D:\WebSplend-HMS\working_copy` |
| Runtime (served by Apache) | `C:\xampp\htdocs\websplend-hms` |
| Local URL | `https://websplend-hms.local/` |
| External local-only DB config | `C:\xampp\websplend-local\websplend-local-db.php` |
| Database dumps | `D:\WebSplend-HMS\database_work\`, `D:\WebSplend-HMS\database_backups\` |
| Rollback trees | `D:\WebSplend-HMS\rollback_*` |

**Why two trees exist:** the working copy is the editing surface; the runtime tree is what Apache serves. **A change is not live until it is copied to the runtime tree and the hashes are compared.** The most recent full-tree comparison (2026-10-14, after the M1 sync) found **0 differing source files**; the only working-copy-only file is this document, and the only runtime-only differences are six pre-existing ABDM debug logs the app appends to (Â§G.7 tests 62â€“63).

**ABDM objective:** allow staff to register and link patients to ABHA identifiers, exchange health information with the gateway under consent, and support care-context linking â€” without making ABHA mandatory for ordinary registration.

---

## B. Architecture and Environment

All values in this section were read directly from the local machine on 2026-10-13.

### B.1 Runtime (verified)

| Item | Value |
|---|---|
| PHP version | **8.2.12**, 64-bit |
| OpenSSL | 3.0.11 (19 Sep 2023) |
| Apache config check | `httpd.exe -t` â†’ **Syntax OK** |
| `OPENSSL_KEYTYPE_X25519` defined | **No** |
| `openssl.conf` ini setting | **`false`** (not configured) |
| Port 443 | Listening |

### B.2 Extensions (verified present / absent)

| Present | Absent |
|---|---|
| `mysqli`, `curl`, `openssl`, `json`, `mbstring`, `fileinfo`, `iconv`, `xml`, `bcmath` | `gd`, `zip`, `sodium`, `gmp`, `ffi` |

> Note: `audit/PHASE_0_INVENTORY.md` Â§2.2 lists `gd` and `zip` as transitive requirements of PhpSpreadsheet. They are **not loaded** on this build. `COMPLETED` observation, not yet a problem â€” no tested feature depends on them.

### B.3 Database access pattern (verified)

Two separate `mysqli` connections, never one:

```
$_SESSION['tenant_id'] â”€â”€â–º CONTROL DB (u336643111_control)
                           table: projects â€” opened, one query, CLOSED
                           returns database_host/_name/_user/_password
                                   â”‚
                                   â–¼
                           HMS DB (u336643111_hms)
                           global $conn, kept open for the request
```

**Critical gate (verified in `includes/db.php:6-10`):** if `$_SESSION['tenant_id']` is empty, `db.php` sets `$conn = null` and **returns immediately**. This is the mitigating control for several unguarded files â€” see Â§I.5.

### B.4 Credential resolver pattern (verified)

`includes/db_credentials.php` (280 lines, **created** by the local-DB work â€” no pre-change backup exists, which is consistent with a new file) defines:

`hmsEnvMarker()`, `hmsIsLocalMode()`, `hmsLocalFail()`, `hmsDocumentRoot()`, `hmsLocalConfig()`, `hmsControlCredentials()`, `hmsTenantCredentials()`

Design (read from source comments, `db_credentials.php:20-36`):

- Reads the marker `HMS_ENV` from `$_SERVER` first (mod_php merges Apache `SetEnv`), then `getenv()` (CLI). `$_ENV` is deliberately unused because XAMPP's `variables_order=GPCS` omits `E`.
- **Production passthrough:** unless `HMS_ENV=local`, the resolver returns each calling file's own literals **unchanged**. The file is documented as safe to deploy to production because it is inert unless local mode is on.
- Local credentials are read from the file named by `HMS_LOCAL_DB_CONFIG`, and it **fails closed** (HTTP 500, no connection attempted) if that variable is unset or the file is unreadable.

**External config** `C:\xampp\websplend-local\websplend-local-db.php` â€” **verified present, 1,972 bytes**, outside the web root. Top-level keys: `control` (`host`, `db`, `user`, `pass`) and `tenant` (`host`, `db`, `user`, `pass`). Values not recorded.

**Vhost wiring** (`C:\xampp\apache\conf\extra\httpd-vhosts.conf`, verified):

```apache
ServerName websplend-hms.local
DocumentRoot "C:/xampp/htdocs/websplend-hms"
SetEnv HMS_ENV local
SetEnv HMS_LOCAL_DB_CONFIG "C:/xampp/websplend-local/websplend-local-db.php"
SSLCertificateFile    "C:/xampp/websplend-local/websplend-hms.local.crt"
SSLCertificateKeyFile "C:/xampp/websplend-local/websplend-hms.local.key"
```

Pre-change vhost backups exist at `D:\WebSplend-HMS\rollback_pre_vhost\` (`hosts.bak`, `httpd-vhosts.conf.bak`).

### B.5 Local HTTPS

Local-only, **self-signed** certificate under `C:\xampp\websplend-local\`. Browsers will warn. This is expected for local development and is **not** evidence of a production TLS posture.

### B.6 ABDM M1 / M2 / M3 scope

| Layer | Purpose | Where |
|---|---|---|
| **M1** | ABHA create / login (Aadhaar, ABHA, mobile), PHR-web address, RSA-OAEP | `modules/abdm/abha_*.php`, `config.php` |
| **M2** | HIP/HIU session, bridge, link token, care-context linking | `modules/abdm/m2_*.php`, `api/v3/hip/link/**`, `api/v3/link/**` |
| **M3** | Consent request/init/status/fetch, health-information request, callbacks, FHIR, Fidelius crypto | `modules/abdm/m3/**`, `api/v3/hiu/consent/**`, `api/v3/**/health-information/**` |

### B.7 Architecture constraints (must be preserved)

1. Procedural PHP; do **not** introduce a framework, router, ORM, or DI container.
2. The app must **be** the document root â€” 313 files build includes from `$_SERVER['DOCUMENT_ROOT']`.
3. Tenancy is resolved per request through `projects` in the control DB. Do not alter tenant resolution without explicit approval.
4. `mysqli` only. Do not migrate to PDO.
5. All ABDM callbacks are **unauthenticated** by design (gateway-to-gateway). This is a known defect (Â§I.4), not a licence to add session checks to them.
6. No `.env` / `getenv()` infrastructure existed before this work; the resolver introduced the first environment-marker mechanism.

---

## C. Current Executive Status

| Workstream | Status | Evidence | Next action |
|---|---|---|---|
| Local config / DB safety | `COMPLETED` | Resolver + external config verified; vhost `SetEnv` verified; login works | See Â§K P0-1 â€” production literals still inline |
| Authentication / route guards | `COMPLETED` for the registration module | All 4 registration handlers + `check_patient_by_aadhaar.php` now enforce session + `checkPermission` + CSRF (Â§D.12, Â§D.13); 22/22 and 47/47 HTTP assertions | Extend the pattern to the ~266 other unguarded files (Â§I.10 H6) |
| **M1 ABHA endpoint authorisation** | **`COMPLETED` â€” deployed and verified** | Shared guard `abdm_m1_request_guard.php` on all 12 `abha_*.php`; 401/403/405 matrix proven for all 12 (Â§G.7) | `modules/abdm/test/index.php` needs a CSRF update (Â§I.14) |
| **ABDM registration toggle** | **`COMPLETED` (browser-verified)** | The Â§E.3 toggle-state matrix was executed in a **real headless Chrome** session on 2026-10-14 and passed **34/34** (§G.16 tests 177â€“190). Items 1â€“5 and 8 are closed; item 6 remains open and is recorded honestly | Item 6 only â€” see Â§G.16 |
| **Global ABDM ON/OFF switch** | **`COMPLETED` (browser + live HTTP verified)** | 4-state helper **8/8**; 156/156 static wiring; 47/47 DB-backed; 68/68 live-HTTP security matrix; **12/12 endpoints 403 while OFF**; real-browser OFF render **20/20** (§G.16 tests 165â€“176, 191â€“210) | None for Phase 2 |
| ABHA preservation guard | **`COMPLETED` (database-backed)** | The blank/differing/duplicate branches are now proven against a **real database** using a throwaway scratch DB and the **real, unmodified `update_patient.php`**: **36/36** (§G.16 tests 211â€“246). Closes Â§G.7 test 58 and Â§K P1-4 | `update_patient.php` cross-table sync remains open (Â§I.17) |
| Callback credential redaction | `COMPLETED` | `[REDACTED]` marker + helper confirmed in all 3 files; source==runtime | None |
| Debug / log file protection | `COMPLETED` | `.htaccess` rules present; HTTP 403 re-verified for 5 URLs post-sync (Â§G.7) | Confirm production equivalent (Â§K P0-4) |
| Inbound / outbound M3 | `BLOCKED` | Inbound crypto proven working (audit 1A Â§6); **outbound keygen blocked** â€” X25519 const undefined, `openssl.conf` unset | PHP 8.4 + `openssl.cnf` (Â§K P2-1) |
| Hardcoded credentials | `PENDING` â€” **open finding** | `includes/db.php:15-16` still contain production literals by design | Client decision (Â§K P0-1) |
| M2 workstream | `NOT STARTED` â€” read-only audit to run | No M2 code has been touched; handover prepared in Â§L | Run the Â§L M2 read-only audit |

---

## D. Completed Work â€” Chronological

### D.1 Local database credential resolver and external configuration

- **Problem:** production database credentials were hardcoded inline in every file that opened a connection, making local development unsafe and rotation error-prone.
- **Files:** **created** `includes/db_credentials.php`. **Modified:** `includes/db.php`, `includes/tenant.php`, `includes/tenant_db.php`, `patient/login_process.php`. **External (created):** `C:\xampp\websplend-local\websplend-local-db.php`.
- **Change:** added the `HMS_ENV` / `HMS_LOCAL_DB_CONFIG` resolver described in Â§B.4 and rewired the four connect sites to call `hmsControlCredentials()` / `hmsTenantCredentials()`.
- **Verification (structural, non-revealing):** `includes/db.php` 85 â†’ 93 lines, resolver calls present, `db_credentials.php` required; `tenant_db.php` and `login_process.php` each contain 1 resolver call and **0** hardcoded credential literals; `tenant.php` contains 1 resolver call and still carries 4 literals.
- **Limitation / pending:** `db.php` retains **4** production literals and `tenant.php` retains **4**. This is the resolver's documented production-passthrough behaviour, **not** a completed remediation â€” see Â§I.1.

### D.2 Runtime synchronisation

- **Problem:** working copy and runtime could drift, making behaviour unreproducible.
- **Change:** the full tree was synchronised; this task's files were then re-verified by hash (see Â§G).
- **Verification:** 1,243 files compared, **0 differing** (2026-10-13).

### D.3 Local HTTPS and staff login/dashboard

- **Problem:** the app force-redirects to HTTPS (`index.php:9-12`), so HTTP-only hosting loops.
- **Change:** local vhosts for `websplend-hms.local` on ports 80 and 443, self-signed cert, `SetEnv` wiring (Â§B.4).
- **Verification:** `GET /` â†’ **HTTP 200**, 4,782 bytes, contains a password field and a tenant field (verified 2026-10-13). **Manual staff login and dashboard rendering were confirmed by the operator in an earlier session â€” `NOT VERIFIED` by the agent in this session.**

### D.4 Authentication guards for two list pages

- **Problem:** two list pages were directly reachable without a session.
- **Files:** `modules/appointments/appointment_list.php`, `modules/registration/registration_list.php`.
- **Change (exact diff vs pre-change backup):**
  ```php
  session_start();
  +if (!isset($_SESSION['user_id'])) {
  +    header('Location: /index.php');
  +    exit;
  +}
  ```
  inserted immediately after `session_start()` in both files.
- **Verification:** unauthenticated `GET` of both returns the 4,782-byte login page (verified). Guard present at line 3 of each file.
- **Limitation:** `HTTP 200` is returned because the redirect is followed; a client that does not follow redirects would see `302`.

### D.5 `.htaccess` restrictions (log files, sensitive files, directory indexing)

- **Problem:** `audit/PHASE_0_INVENTORY.md` Â§7.1 C1/C5 found debug files and a credential file reachable over HTTP, and Apache served directory listings.
- **File:** `.htaccess`.
- **Change (cumulative vs pre-change backup) â€” three additions:**
  1. `<FilesMatch "\.log$">` â†’ deny (with an Apache 2.2 `Order allow,deny` fallback).
  2. `<FilesMatch "^(abdm_callback_debug\.txt|abdm_token_cache\.json|installation\.txt|control_db\.php)$">` â†’ deny by exact basename.
  3. `Options -Indexes`.
- **Design note (from the in-file comments):** `<FilesMatch>` is evaluated during authorisation, **after** `mod_rewrite` translation, so all ABDM callback rewrite rules continue to work; it also does not affect PHP `require`/`include`, which read the filesystem directly.
- **Verification:** `GET /abdm_callback_debug.txt` â†’ **403**; `/modules/abdm/abdm_token_cache.json` â†’ **403**; `/includes/control_db.php` â†’ **403**; `/uploads/` â†’ **403** (listing suppressed). Direct upload access preserved by design (uploads are linked by explicit path, never by listing) â€” **direct upload loading is `NOT VERIFIED` in this session.**
- **Prior protection retained:** the pre-existing ABDM rewrite rules and the Apache 2.2 `allow from` IP list are untouched.

### D.6 Debug-log containment and removal of raw request/header logging

- **Problem:** `api/v3/hip/token/on-generate-token/index.php` appended the raw request body, `X-HIP-ID`, and the **complete header set via `print_r(getallheaders())` â€” including the `Authorization` bearer token** â€” to `abdm_callback_debug.txt` in the document root, where it was fetchable over plain HTTP.
- **File:** `api/v3/hip/token/on-generate-token/index.php`.
- **Change:** the raw dump was replaced with a minimal JSON trace keeping only `time`, `method`, `content_type`, `content_length`, and a boolean `auth_present` (YES/NO). The ABHA link token, raw body, and all header values are no longer written.
- **Verification:** diff vs `rollback_pre_exfilcontain` confirms the replacement. The file still references `abdm_callback_debug.txt` â€” the **sink still exists**; what changed is that it no longer receives secrets. The sink is now HTTP-blocked by Â§D.5.

### D.7 Callback header redaction (three health-information handlers)

- **Problem:** stored callback headers retained `Authorization` bearer/JWT material in the database.
- **Files:** `api/v3/hiu/health-information/on-request/index.php`, `api/v3/hip/health-information/request/index.php`, `api/v3/hiu/health-information/data-push/index.php`.
- **Change:** each now writes a redacted copy under a "Credential redaction for stored callback headers" helper; `[REDACTED]` marker present in all three.
- **Verification:** marker + helper comment confirmed in all three; all three still write `abdm_m3_callbacks`; source == runtime byte-identical.
- **Limitation:** the file `api/v3/hip/token/on-generate-token/index.php` received **no** such redaction â€” see Â§I.3.

### D.8 ABDM Yes/No workflow toggle â€” *latest change*

Covered in full in Â§E. Backups: `rollback_pre_abdm_toggle\20261012_233822`.

### D.9 ABHA preservation guard â€” *latest change*

Covered in full in Â§E. Backup: `rollback_pre_abdm_toggle\20261012_233822`.

### D.10 Global ABDM ON/OFF switch â€” *latest change (2026-10-13)*

- **Problem:** WebSplend HMS is deployed at multiple client sites and not every site uses ABDM. The existing patient-level Yes/No toggle only controls one screen at a time and is not persisted; there was no way to say "this installation does not use ABDM at all".
- **Storage:** one new additive table `abdm_master_settings`, single row `id = 1`, columns `abdm_enabled tinyint(1) NOT NULL DEFAULT 1`, `abdm_enabled_by int(11) NULL`, `updated_at`. **No existing table, column or permission row was modified.** Tenancy is unchanged: each client already has its own HMS database, so a row in that database is per-client by construction and no tenant discriminator column was added.
- **Default:** `1` (ON). An installation that has never saved the setting behaves exactly as it did before.
- **Error policy (four states, deliberately not collapsed):** `ok` (row read), `missing` (table or row absent â†’ treated as **ON**, backward compatible), `error` (no connection, permission failure, driver error, or malformed value â†’ **fail closed**). A read error never blocks ordinary registration, patient editing or prescription saving.
- **Enforcement is server-side, not cosmetic.** See Â§E.4.
- **Files:** 2 created, 26 modified/synced. Full register in Â§F.7.
- **Backup:** `rollback_pre_abdm_global\20261013_130028\` (+ `_runtime`), plus verified structure dumps in `database_backups\pre_abdm_global\`.
- **Verification:** Â§G.2.

### D.11 Fix: visible `GLOBAL ABDM SWITCH` comments and registration grid layout â€” *latest change (2026-10-13)*

- **Defect.** The seven conditional-rendering markers introduced in Â§D.10 were written as
  `<?php if ($abdmGlobalEnabled): ?> /* GLOBAL ABDM SWITCH: ... */`. The `/* â€¦ */` sits **after** the `?>` close tag, so PHP had already left PHP mode and the comment was emitted as **page text**. It rendered above the ABDM Integration card and inside Patient Details next to the Aadhaar and Mobile fields, and â€” being a stray text node between grid children â€” pushed those fields out of alignment.
- **Fix.** The comment was moved **inside** the PHP block:
  `<?php /* GLOBAL ABDM SWITCH: â€¦ */ if ($abdmGlobalEnabled): ?>`. Nothing else about the gate changed: same seven render gates, same condition, same order.
- **Scope.** `modules/registration/register.php` only. `edit_patient.php` was audited and rendered **0** occurrences â€” its gates never carried a trailing comment.
- **Second defect found and fixed in the same pass.** `includes/abdm_helper.php` was being included with plain `require` from six files, so a second inclusion in the same request caused `Fatal error: Cannot redeclare abdmReadMasterRow()`. All sites now use `require_once`.
- **Files:** 5 modified (see Â§F.8). Backups: `rollback_pre_abdm_ui\20261013_133628\` (+ `_runtime`).
- **Verification:** Â§G.5. Regression suites from Â§D.10 re-run and still green.

### D.12 Registration security closure â€” Phase 2 (2026-10-14)

Four reviewed patient-registration security files were synchronised to the runtime tree and verified. **No database write and no patient-record change was made at any point.**

- **Authorisation + CSRF added** to `save_patient_registration.php` (`patient_registration`/`create`) and `update_patient.php` (`patient_registration`/`update`); a CSRF token field and a `view` permission gate added to `edit_patient.php` and `register.php`. All four now enforce session â†’ permission â†’ CSRF **before any database mutation**.
- **Catch-block allow-list** in `save_patient_registration.php`: six recognised validation messages return HTTP 400 with the safe message; anything else is logged and answered with a generic HTTP 500. The raw `$msg` is never returned.
- **Defect found and fixed during this phase.** The ABHA-preservation block re-read the stored `abha_id` into the working variable, which *armed* the `abdm_patient_links` upsert that blank input was meant to skip. A verified patient re-registered with a blank ABHA field would have been silently downgraded to `Pending` with `verified_at` nulled. Fixed by capturing `$abha_supplied` from the raw POST value **before** preservation and gating the upsert on it.
- **Hardening:** `catch (Exception $e)` â†’ `catch (Throwable $e)`, and an `is_string()` guard before `hash_equals()` in both handlers (an array-valued `csrf[]` previously raised a `TypeError` that escaped the catch and rendered a stack trace, because `display_errors` is on).
- **Files:** 4 modified. Backups: `rollback_pre_phase2sync_runtime\20261014_082751\`. Evidence: Â§G.7 (tests 47â€“58).

### D.13 M1 critical security remediation â€” Phase 3 (2026-10-14)

An audit of the M1 ABHA identity flow found two critical issues. Both are fixed, deployed and verified. **No database write, no OTP request, no ABHA creation and no gateway call was made.**

- **Critical 1 â€” anonymous full patient-record disclosure.** `modules/appointments/ajax/check_patient_by_aadhaar.php` imported `includes/public_tenant.php`, which sets `$_SESSION['tenant_id']` from a caller-supplied GET/POST value (`public_tenant.php:33`). An unauthenticated caller could therefore satisfy the `includes/db.php` tenant gate and receive the **entire `patient_master` row â€” including the password hash and username â€” as JSON**, by guessing an Aadhaar. Proved reachable anonymously (HTTP 200 with an attacker-supplied `tenant_id`). Now POST-only, session-bound, `patient_registration`/`view`, CSRF-bound, tenant resolved from the session only, and restricted to the **13 fields** the registration auto-fill actually consumes. `includes/public_tenant.php` itself was **not** modified â€” it has 21 other callers.
- **Critical 2 â€” no authorisation on any M1 endpoint.** All 12 `abha_*.php` files performed no session, permission or CSRF check; their only gate was the Global ABDM switch, which is a **feature** switch, not access control. Proved: a session holding `rights_type_id = 3` â€” denied (302) on `register.php` â€” received HTTP 200 on all 8 tested M1 endpoints. A new shared guard `modules/abdm/abdm_m1_request_guard.php` now enforces POST â†’ session â†’ `patient_registration`/`create` â†’ CSRF **before any token retrieval, certificate fetch, OTP request, verification or profile fetch**. Anonymous callers were previously protected only *incidentally*, by the `$conn = null` tenant gate.
- **Response hardening (same phase).** Removed `raw_response` from 5 sites, replaced 11 `echo json_encode($tokenResult)` sites (which could carry a gateway error body or a token) with allow-listed messages, stopped returning 9 raw `curl_error` strings (which disclose the gateway hostname and therefore whether the install targets sandbox or production), and disabled `display_errors` on `abha_request_mobile_otp.php`. **No success payload was changed** â€” `txnId`, `xToken`, `profile`, `accounts` and `response` are all still returned, and all nine live callers in `register.php` handle the new refusals.
- **Caller updates.** `register.php` emits `window.csrfToken` and sends it on all 9 ABDM calls; the Aadhaar lookup moved from `GET ?aadhaar=â€¦` to POST so the number no longer lands in server access logs.
- **Files:** 14 modified + 1 created. Backup: `rollback_pre_m1_remediation\20261014_091149\` (14 files + manifest).
- **Sync verification:** **15/15** runtime SHA-256 hashes match the working copy Â· **15/15** PHP lint clean Â· **47/47** HTTP security regression pass Â· **5/5** protected URLs return HTTP 403 Â· **14/14** protected files byte-identical. Full ledger: Â§G.7 tests 59â€“79.

### D.14 M1 closure - four-file change set (2026-10-14)

Closes the three items left open after Phase 3: the ABHA verification-downgrade defect, the cross-patient duplicate-link defect, and the harness/CSRF incompatibility. A fourth file was added mid-flight after an audit of `modules/registration/ajax/` found two more endpoints with the **same defect class as Critical 1 and Critical 2**. **No database write, no migration, no OTP request, no gateway call and no patient data.**

- **Fix 1 - verification downgrade (`save_patient_registration.php`).** `abdm_verified` is a hidden form field that only the OTP success handlers set to `1`; on a revisit it is still `0`. The old `ON DUPLICATE KEY UPDATE` wrote that value straight through, resetting an already `Verified` link to `Pending` and nulling `verified_at`. Replaced with read-decide-write inside the existing transaction (`begin_transaction()` precedes it): new row -> insert as before; same `abha_number` and not re-verified -> **verification state left untouched**; `abha_number` changed -> `Pending`, `verified_at` NULL; `abha_verified === 1` -> promote to `Verified`. Promotion of an existing `Pending` link still works.
- **Fix 2 - duplicate link (`save_patient_registration.php`).** `abdm_patient_links` has `UNIQUE KEY unique_patient (patient_id)` but only a **non-unique** `KEY idx_abha_number (abha_number)`, so `ON DUPLICATE KEY UPDATE` can never detect the same ABHA on a second patient. Added an ownership pre-check. **On conflict the patient record is still saved; only the link is refused**, and the operator is warned after commit. The `catch (Throwable)` wrapper was left in place, so a database error still fails closed on the link without failing the registration.
- **Fix 3 - the warning.** Emitted as a `json_encode`-escaped `alert()` in a separate `<script>` block immediately before the existing redirect script, so it fires for both the `ipd` and standard branches. Verified viable because `register.php:1242` is a plain full-page form POST, not AJAX.
- **Harness CSRF (`modules/abdm/test/index.php`) - NOT deleted.** Confirmed still in use: the vhost log records `GET /modules/abdm/test/index.php` returning **200 at 2026-10-14 10:44:38**. The session CSRF token is generated fill-if-empty and emitted as `window.abdmTestCsrf`; `postRequest()` attaches it centrally, so **all 8 call sites are covered without editing any of them**. Fill-if-empty was chosen over regenerate so opening the harness does not invalidate tokens in the operator's other open tabs.
- **Two further unauthenticated endpoints found by the audit of `modules/registration/ajax/`, both FIXED.** `get_abha_details.php` and `get_patient_full_details.php` had **no session check and no permission check**, gated only by the Global ABDM switch - which Â§D.13 explicitly calls "a feature switch, not access control". They were missed because the Phase 3 guard was applied to the `modules/abdm/abha_*.php` glob and these files sit outside it. Both now enforce session then `checkPermission('patient_registration','view')`, before the switch gate, following `check_patient_by_aadhaar.php`. **GET and the response shape were deliberately left unchanged** so no caller had to change.
- **Pre-existing defect found and fixed: `get_abha_details.php` selected a non-existent `patient_master.remarks` column**, so it threw a fatal error for every caller. Verified pre-existing, not introduced here: the SELECT column list is byte-identical to the backup, and `remarks` is absent from `patient_master` in **two independent dumps** (2026-10-12 full, 2026-10-13). `remarks` does exist on 16 other tables - registration's Remarks field is backed by **`appointments.remarks`**, not `patient_master`. The reference was a copy-paste from an appointments column list. Both the SELECT and the JSON key were removed; see Â§D.15.
- **Files: 4 modified.** Backup: `rollback_pre_m1_close_20261014_053637\` (4 files + `SHA256SUMS.before.json`), created and hash-verified **before** the first edit.
- **Sync verification:** 4/4 runtime SHA-256 match the working copy - 4/4 PHP lint clean in both trees - **60/60** guard-matrix assertions across all 12 M1 endpoints - 5/5 protected URLs 403 - 8/8 ABHA link branches pass on a stub connection - blast radius confirmed as **exactly 4 files, 0 unexpected**. Full ledger and honest gaps: Â§G.9.

### D.15 `get_abha_details.php` invalid `remarks` column - pre-existing, fixed (2026-10-14)

- **Symptom:** `Fatal error: Uncaught mysqli_sql_exception: Unknown column 'remarks' in 'field list'`. With `display_errors` on, the response began with `<br />`, so `register.php:3523` `res.json()` threw and the `.catch()` at `register.php:3532` fell through to `requestABHAFetchOTP(abha)`.
- **Consequence:** the "found in local records" ABHA fast-path **had never executed once**. Every ABHA entry silently degraded to the ABDM fetch-OTP flow. This was invisible because the fallback is a valid path.
- **Evidence the column does not exist:** `database_backups\u336643111_hms.sql` (2026-10-12, full) and `database_backups\pre_abdm_global\..._20261013_093210.sql` list `patient_master` identically and neither contains `remarks`. CLI database access was unavailable (control-DB authentication fails outside Apache), so file evidence was used, as instructed.
- **Evidence nothing consumes it:** the endpoint has exactly **one** caller, `register.php:3522`. `fillLocalABHADetails()` reads **9** of the 17 returned fields (`dob, first_name, last_name, sex, mobile, email, aadhaar, address, pincode`). `remarks` was not among them, nor were `id, abha_id, title, marital_status, guardian_name, phone, city_id`.
- **Fix:** removed `remarks` from the SELECT and from the JSON object. No migration. **Response-contract impact: `patient.remarks` is removed, and no consumer read it.** The envelope and all 9 consumed fields are unchanged; 16/16 remaining JSON keys still map to columns present in the SELECT, so none can emit `null`.
- **Behaviour change to watch:** repairing this **activates a previously dead code path**. Local ABHA matches will now pre-fill the registration form instead of calling the gateway. This is the intended design but it is **NOT VERIFIED** end to end - see Â§G.9.

---

### D.16 ABHA ID revisit prefill - one-line fix (2026-10-14)

- **Reported symptom:** an existing patient whose ABHA number is visible in the Registration List showed an **empty ABHA ID field** when the registration form was reopened via *Revisit*. The ABHA verify tick responded *"Please enter ABHA ID"* and *View ABHA Card* responded *"ABHA profile is not available. Please fetch ABHA details first."*
- **Root cause - a presentation gap, not a data gap.** `registration_list.php:261` selects and renders `pm.abha_id`, so the list shows it. But `register.php:1383-1387` rendered the ABHA input with **no `value` attribute at all**, while all 12 other revisit-populated fields had one (mobile `:1441`, title `:1472-78`, first_name `:1486`, last_name `:1493`, marital `:1498-1500`, guardian `:1516`, phone `:1526`, email `:1530`, dob `:1547`, city `:1574`, pincode `:1582`, address `:1621`). The revisit query at `register.php:46-51` is `SELECT * FROM patient_master`, so `$revisitPatient['abha_id']` **was** available in PHP - it was simply never rendered into the input.
- **Fix:** one line added to the existing input, alongside `name="abha_id"`, inside the unchanged `if ($abdmGlobalEnabled)` block (`:1379-1406`):
  `value="<?= htmlspecialchars($revisitPatient['abha_id'] ?? '') ?>"`
- **Deliberately not done:** no `($appt['abha_id'] ?? '')` fallback as used at `:1582`, because **`appointments` has no `abha*` column** (verified against both schema dumps) and the fallback would be dead code.
- **Scope: 1 file, 1 line.** `modules/registration/register.php` only. No migration, no refactor, no toggle change, no unrelated security fix. A corrected line-by-line diff against the backup shows **0 real differing lines** apart from the single inserted line, +82 bytes, no encoding change. Backup: `rollback_pre_abha_prefill_20261014_062501\` (1 file + `SHA256SUMS.before.json`), created and hash-verified before the edit.
- **Not a regression and unrelated to the `remarks` fix in D.15.** `abha_id` has never existed in the SELECT of `get_patient_details.php` or `check_patient_by_aadhaar.php` in any backup on this machine, so those two prefill paths could never supply the ABHA either. Both were left untouched.
- **Security note:** `htmlspecialchars` is required because `abha_id` is user-controlled data interpolated into an attribute. Several sibling fields (`first_name` `:1486`, `last_name` `:1493`, `email` `:1530`, `dob` `:1547`, `pincode` `:1582`) interpolate **unescaped** - a pre-existing stored-XSS weakness, flagged here and deliberately not changed.
- **Accepted limitation:** the ABHA is now shown but is **not** treated as verified - `abha_verified` stays `"0"` on revisit, so the D.14 save logic preserves any existing verification rather than downgrading it. *View ABHA Card* still requires the operator to click the verify tick first, because `registrationABHAProfile` is only assigned inside interactive fetch/verify flows (`:3554, :3746, :4450, :4773, :4842`). Seeding it from `patient_master` was considered and declined, since that table carries fewer fields than a real ABHA profile fetch.

---

### D.17 Defensive ABHA JS guard in the existing-patient picker (2026-10-14) — *rationale CORRECTED in D.19*

> **CORRECTION (2026-10-14, D.19).** This entry originally claimed the guard protected the
> **Emergency checkbox / Revisit checkbox / doctor dropdown** path. **That was wrong.**
> `loadEmergencyDoctorFee()` ends at **L2714** and is fee-only. The
> `get_patient_details.php` fetch and the guard both sit inside the **top-level** `change`
> listener at **L2768**, which returns at **L2773** unless
> `e.target.name === 'revisit_select'`. The fetch runs **only when an existing-patient
> radio is picked**. The code was always harmless; the stated rationale was incorrect.
> The guard itself was **replaced in D.19** once the endpoint began returning `abha_id`.
> The original text is retained below for history.

- **Context:** after D.16 the server-rendered ABHA was confirmed populated, yet the operator still saw an empty ABHA field. An audit of every write to the input found **exactly one** remaining line that could blank it, at `register.php:2827` inside the `get_patient_details.php` callback of `loadEmergencyDoctorFee`:
  `document.getElementById('abha_id').value = p.abha_id ?? '';`
- **Root cause of the remaining blanking:** `modules/appointments/ajax/get_patient_details.php` selects 14 columns and **does not return `abha_id`** (verified at runtime - the response carries `title, first_name, last_name, marital_status, sex, guardian_name, aadhaar, mobile, phone, email, dob, city_id, pincode, address`). So `p.abha_id` is `undefined`, `?? ''` yields an empty string, and the line **overwrote the good server-rendered value**. Every other field was simultaneously rewritten with real data, which is why only the ABHA appeared blank. The path is reached from the Emergency checkbox change handler (`:2747`), the Revisit checkbox handlers (`:2543`, `:2568`) and the doctor dropdown (`:3347`) - so it fires whenever a user touches any of those on a revisit form.
- **Fix:** the unconditional assignment was replaced with a guarded assignment (1 line replaced by a 6-line block, including a 3-line comment):
  ```js
  if (p.abha_id) {
      document.getElementById('abha_id').value = p.abha_id;
  }
  ```
  The input is now updated **only when the payload actually supplies an ABHA**, and is otherwise left exactly as the server rendered it.
- **Scope: 1 file.** `modules/registration/register.php` only. Offset-aware diff against the backup shows exactly **1 line changed** and nothing else. Backup: `rollback_pre_abha_jsguard_20261014_064345\` (1 file + `SHA256SUMS.before.json`), created and hash-verified before the edit.
- **`get_patient_details.php` was deliberately NOT modified** - hash `CF657375...6B524` unchanged, still omits `abha_id`. Adding the column would have exposed one more patient field through an endpoint that is still **unauthenticated** (I.16, OPEN), which would have *widened* a known exposure rather than fixed it. The guard removes the symptom without widening the surface.
- **Known residual:** the in-page Revisit radio picker still cannot populate the ABHA, because that flow never sends `revisit_patient_id` and loads every field client-side. Fixing that requires adding `abha_id` to `get_patient_details.php`, which is sequenced behind closing I.16.
- **Screenshots remain ambiguous.** The vhost log shows one revisit load at **11:47:53**, *before* the D.16 deploy at 11:55:08, and three after (11:56:04, 11:56:24, 11:57:00). Which load the screenshots came from was never confirmed, so D.17 is a *defence in depth* fix against the one proven code path - not a confirmed root cause for those specific screenshots.

---

### D.18 I.16 closure - `get_patient_details.php` authentication and authorisation (2026-10-14)

- **Defect fixed.** `modules/appointments/ajax/get_patient_details.php` imported `includes/public_tenant.php`, which writes a **caller-supplied** `tenant_id` straight into `$_SESSION['tenant_id']` (`public_tenant.php:33`). The file performed **no session check and no permission check**. Any unauthenticated caller could pass any tenant id, satisfy the `includes/db.php` tenant gate, and receive **14 fields of patient PII - aadhaar, mobile, phone, email, date of birth, full address - for any sequential patient id**. Proven by direct request before the change.
- **Same defect class** as the two endpoints closed in D.14 (`modules/registration/ajax/get_abha_details.php`, `get_patient_full_details.php`). It escaped both earlier passes because it sits in **`modules/appointments/ajax/`**, matching neither the `modules/abdm/abha_*.php` glob used by the M1 guard nor the `modules/registration/ajax/` sweep.
- **Permission chosen: `patient_registration` / `view`.** This is the narrowest **existing** permission covering a patient read, it is what `register.php:36` requires, and it is what the **sibling endpoint in the same directory** `modules/appointments/ajax/check_patient_by_aadhaar.php:65` requires for an identical patient-PII read - its comment reads *"read operation, same permission register.php itself requires"*. **No new permission name was invented.**
- **Tenant: `public_tenant.php` is no longer imported by this file.** The tenant comes from `$_SESSION['tenant_id']` via `includes/db.php` only, and a caller-supplied `tenant_id` is ignored - matching `check_patient_by_aadhaar.php`. `includes/public_tenant.php` itself is **untouched**; it still has 23 other callers.
- **Deliberate ordering: `includes/db.php` is loaded BEFORE the permission check.** `checkPermission()` resolves `can_view` through the global `$conn` and returns false when `$conn` is null, so loading it first is required for non-super-admin roles to be evaluated correctly. The sibling's ordering (permission first) would deny every non-super-admin role regardless of its actual grant.
- **No CSRF token required, deliberately.** The endpoint performs a single `SELECT` and writes nothing, so it is a **read-only** request and cannot itself cause a state change. It follows the project convention for authenticated read-only AJAX - `get_abha_details.php` and `get_patient_full_details.php` after D.14. A token was **not** added because **neither caller sends one** (`appointment_form_internal.php:2897` and `register.php:2809` both omit it), so requiring one would break both callers while protecting nothing on a read-only endpoint.
- **Preserved exactly:** HTTP method stays **GET**, `$id` is still read from `$_GET`, the SELECT is the **same 14 columns in the same order**, and the success payload is still the bare `json_encode($row ?: [])` object with no envelope. Refusals use `{success:false,message:...}` with HTTP 401/403 - the same envelope this file already returned via `public_tenant.php`, so callers handle it unchanged. Refusal messages are generic and disclose no patient data, SQL, credentials or stack traces.
- **`abha_id` was NOT added.** That is Phase 3 and requires separate approval. Verified absent from both the SELECT and the response.
- **Backup:** `rollback_pre_i16_auth_20261014_073105\` (1 file + `SHA256SUMS.before.json`), created and hash-verified before the edit.
- **Hashes:** original `CF657375C3F54E708E375F9BF67E60AF5B8CA0FAA2712E3A96AD14792FA6B524` -> new `AC7F1060619F6C66B31D666138A4DCCBC4867B56253CD8F5CBF90554F0CC7D66`.
- **Phase 1 was not disturbed:** `register.php` still hashes `96982708...93A232` and the Aadhaar prefill line is present.

---

### D.19 Phase 3 - ABHA mapping into the existing-patient picker (2026-10-14)

- **Objective.** The saved ABHA ID is now returned by the existing-patient details endpoint and correctly applied by the registration picker.

#### Confirmed ABHA source of truth: `patient_master.abha_id`

| Evidence | Finding |
|---|---|
| 9 UI / display readers | all read `patient_master.abha_id` - `registration_list.php:261`, `edit_patient.php:411`, `get_abha_details.php:82`, `admission.php:52`, `ipd_slip.php:27`, `shift_patient.php:51`, `opd_list.php:575`, `print_op_receipt.php:23`, `discharge/print_discharge.php:47` |
| Readers of `abdm_patient_links.abha_number` | **ABDM gateway endpoints only** - `api/v3/hip/token/on-generate-token`, `api/v3/link/on_carecontext`, `m2_care_context.php`, `m2_link_token.php`, `m2_user_linking.php`, `m3/api/consent_request_init.php`. **No registration or UI path reads it.** |
| Extra columns on that table | `verification_status`, `verified_at`, `linking_token` - link metadata, not the displayed value |
| Writers | `save_patient_registration.php` writes **both** tables (link block at L284, top level, runs for new **and** revisit). `update_patient.php` writes **only `patient_master.abha_id`** (L164); its sole `abdm_patient_links` mention is a **comment** (L131) |

**The two tables genuinely can diverge.** `update_patient.php`'s own comment (L129-132) records it: *"an unrelated profile edit ... could erase `patient_master.abha_id` while any `abdm_patient_links` row survived - leaving the two tables inconsistent."* Its blank-preservation guard (L138-155) prevents *accidental* erasure, but a **deliberate ABHA change through Edit still updates `patient_master` only**, leaving `abdm_patient_links.abha_number` stale. Reading that table for display would therefore show the wrong ABHA for anyone who changed theirs through Edit. **`patient_master.abha_id` is the only correct display source.**

#### Change A - `modules/appointments/ajax/get_patient_details.php`

Added `abha_id` to the existing SELECT. Response is now **15 keys**: the original **14 fields, same names, same order**, plus `abha_id`. The bare-object payload, the **GET** method, `$_GET['id']`, the session gate, the `patient_registration`/`view` gate and session-derived tenant isolation are all unchanged. No-match still returns bare `[]`.

#### Change B - `modules/registration/register.php`

The picker's ABHA assignment is now **set-or-clear**:

```js
document.getElementById('abha_id').value = p.abha_id ?? '';
```

**Why clearing, not preserving.** The picker switches the whole form to the selected patient, so the ABHA must follow that patient. Preserving on a miss would be a **cross-patient contamination risk**: the form is also reachable as a server-side revisit (`?revisit_patient_id=N`) which renders that patient's ABHA (D.16); picking a different patient who has no ABHA would then submit the **previous patient's ABHA** against the new one. A network failure is safe - the pre-existing `.catch(err => console.error('Error loading patient details:', err))` at the end of the chain means the assignment never runs, so a valid value is never silently erased, and the error is reported without exposing data.

This block is confined to existing-patient selection by the L2773 guard, which is what made reverting to an unconditional assignment correct here.

- **Backup:** `rollback_pre_phase3_abha_mapping_20261014_075104\` (2 files + `SHA256SUMS.before.json`), hash-verified before editing. No earlier backup was overwritten.
- **Hashes:** `get_patient_details.php` `AC7F1060...CC7D66` -> `789CA13D78E202906872DD53F6DD62A715BD7211217055E602480FFE78D6861D`; `register.php` `96982708...93A232` -> `5A4C90E1871961725FF9DB7A0431243F08B00167FA6A19E9A43B83C8CC4E3C69`.
- **Not changed in this phase, deliberately:** `update_patient.php` ownership validation and cross-table synchronisation. Both recorded as separate follow-up tasks.

### D.20 M2 credential-resolution defect — five ABDM callbacks (2026-10-14)

- **Finding:** all five `api/v3` M2 callbacks bypassed `includes/db_credentials.php` entirely and connected with hardcoded production control-DB credentials; four also inlined the literals directly as `new mysqli()` arguments and `discover` used `includes/control_db.php` values unresolved. All five additionally connected to the tenant DB from the raw `projects` row (`$tenant['database_user' / 'database_password']`) instead of `hmsTenantCredentials()`. **On a local install every one of them therefore failed at the first connection**, which is why M2 HTTP acceptance was blocked. **Defect class:** the same one recorded as I.2.
- **Change:** each callback now `require_once`s `includes/db_credentials.php`, builds its production fallback from `includes/control_db.php` and passes it through `hmsControlCredentials()`, then resolves the tenant with `hmsTenantCredentials($project)`. `discover`'s post-helper reconnect reuses the same resolved array.
- **Contract detail — important:** `includes/control_db.php` exposes the database name as key `name`, while `hmsControlCredentials()` takes and returns it as `db`. The fallback must be mapped `name` -> `db` or production silently connects with a **null database**. This was caught by verification, not by inspection.
- **Backup:** `rollback_pre_m2_credresolver_20261014_102115\` (5 files + `SHA256SUMS.before.json`), hash-verified before editing.
- **Resulting hashes:** `init` `858D9DCD...C119078`; `confirm` (later changed by D.21) `E9FF3870...4E5D`; `on-generate-token` `ADD5DDFA...3F78`; `on_carecontext` `78BDBD98...A3BF`; `discover` (later changed by D.21/D.22) `AFA31E03...6EA44C`.
- **Verified:** PHP lint 10/10 across both trees; SHA-256 working-copy/runtime parity 5/5; resolver mode tests 3/3 (production passthrough unchanged, local substitution works, no environment default leaks); safe HTTP guard tests 4/4 for the two endpoints that do not log. `init`, `confirm` and `on-generate-token` were **not** HTTP-tested at this point because each appends to its own diagnostic log before its guard.

### D.21 M2 P0 security corrections (2026-10-14)

Consequences of the M2 read-only audit (§J.1). Four defects closed.

- **`confirm` — identity checks moved ahead of all state change (B-02/B-03).** The ABDM settings read and the HIP/HIU checks previously sat **after** the OTP block, so an unauthenticated caller who knew a `linkRefNumber` could increment `attempts` and mutate the OTP row before any source validation, and could burn the 5-attempt allowance to lock the legitimate patient out. The block now runs at `confirm:215-245`, ahead of the first OTP access at `:254`. **Both headers are now mandatory** — the old predicates (`$incomingHipId !== '' && $configuredHipId !== '' && …`) skipped validation entirely when the header was absent, so omitting `X-HIP-ID` disabled the only source check this endpoint performed. `X-HIU-ID` is mandatory exactly when the installation is configured as an HIU (this one is).
- **`confirm` — OTP no longer persisted (B-09).** The diagnostic summary written to `response_payload` carried `'receivedPayload' => $data`, i.e. the whole inbound body including **`confirmation.token`, the plaintext OTP**, into a `longtext` column. Replaced with an allow-listed summary (`confirm:459-467`). Phase 0 had fixed this class of leak in the *log files* but not in the *database*.
- **`m2_user_linking.php` — ABHA-anchored matching (B-20/B-21).** `abdmM2GetUserLinkingPatient` previously OR-ed ABHA address, ABHA number, **mobile** and MR into a `WHERE … LIMIT 1`. A mobile number alone therefore identified a patient, and the endpoint then built and forwarded the discovery response — disclosing that patient's UHID, full name and every prescription/appointment care context. Matching now **requires** an ABHA anchor, uses ABHA anchors only (both existing anchors honoured: `abdm_patient_links.abha_address` / `abha_number` **and** `patient_master.abha_id`, AND-combined so two identifiers that disagree cannot resolve to one row), demotes mobile/MR to **corroboration only**, and replaces `LIMIT 1` with explicit candidate resolution that **refuses** when more than one patient matches. No schema change; no new verification mechanism.
- **`discover` — outbound bypass and information leaks (B-22/B-23/B-24).** A `transactionId` prefixed `TEST-TRANSACTION-` silently **suppressed the on-discover call to the gateway** while still reporting success — reachable by anyone, because this endpoint performs no real source authentication. Removed. The 403 body no longer returns `expected` (the configured HIP ID — this was directly observable: a guard test returned `"expected":"IN0210000426"`) or `received`, and four response bodies no longer echo `connect_error` / SQL error text. Sanitised `error_log` lines replace them, emitting **booleans only**.
- **Backup:** `rollback_pre_m2_p0_20261014_133540\` (3 files + `SHA256SUMS.before.json`), hash-verified before editing.
- **Hashes after D.21:** `confirm` `A58DB6F5B2C238E35C7EE5D78F746E0C031F0836EF3829760E8EBA954C6C0DD7`; `m2_user_linking.php` `8CABE311E07BE364C6EC64A03BBCD1F433CA1BE7C78E07EECD4B989C7CDDB95C`; `discover` `AFA31E03B1F0A863E2B17E9F96E61D593B0CAA24BE66A02C049B56FC066EA44C`.
- **Two defects were introduced during implementation and caught by verification, not inspection:** a PHP function name accidentally emitted into SQL in the first draft of the ABHA-number condition; and a first edit that anchored too late, leaving the vulnerable `p.mobile = ?` / `p.uhid = ?` conditions live alongside the new code. Both fixed. This is the argument for keeping the source assertions and the read-only stub suite.
- **Contract change requiring confirmation — see I.19.**

### D.22 M2 `discover` request-payload privacy fix (2026-10-14)

- **Finding:** all three persistence points in `discover` stored `json_encode($data)` — the **entire inbound request body** — into `abdm_user_linking_transactions.request_payload`, a `longtext` column. The body carries the patient object: ABHA address, ABHA number, mobile, MR, name, gender, year of birth, in clear text and retained indefinitely. D.21 **increased how often this path is hit**, because an identifier-only probe that previously succeeded now takes a refusal branch and writes a `Failed` row containing the probed identifiers.
- **Reader audit (performed before editing):** `request_payload` is referenced in **exactly six places, all in `discover`, all writers — there are no readers anywhere in the codebase.** Every `SELECT` against `abdm_user_linking_transactions` (`confirm:342`, `init:261`, and the `test_m2_confirm_hitype` harness) uses an explicit column list that omits it. Replacing the contents therefore loses nothing the lifecycle depends on. `transactionId` and `request_id` remain in their own columns and are read by `init`/`confirm` as before.
- **Change:** added `abdmDiscoverRequestSummary()` and routed all three write sites through it — `'match_refused'`, `'discovery_build_failed'`, `'processed'`. The summary emits **only**: `summaryVersion`, `transactionId`, `requestId`, `outcome`, `patientPresent` (bool), `verifiedIdentifierTypes`, `unverifiedIdentifierTypes`, `receivedAt`. Identifier **values** are never stored; identifier **type labels** are, because they are a fixed enum and preserve the diagnostic signal that e.g. a mobile-only probe was rejected. `name`, `gender` and `yearOfBirth` are deliberately excluded.
- **Scope:** one file. No schema change, no migration. `bind_param` type signatures (`ssss`, `sissss`, `sisssss`), all three `INSERT … ON DUPLICATE KEY UPDATE` statements, all HTTP status codes and all response JSON are unchanged.
- **Backup:** `rollback_pre_m2_discover_payload_20261014_135516\` (1 file + `SHA256SUMS.before.json`), hash-verified before editing.
- **Hashes:** `discover/index.php` `AFA31E03B1F0A863E2B17E9F96E61D593B0CAA24BE66A02C049B56FC066EA44C` -> `FF9EF6485CB3EA0DD9C0250F65E7C06FDBE2A8FC6F081FBD9345A73DA6C28D4A` (working copy == runtime).
- **Residual, not fixed — see I.20:** `response_payload` on the same table still stores the patient identity and care-context records. That is the record of what was actually sent to the gateway and is read by `confirm`, so it is functionally required — but it is still stored PHI and is covered by D-06, not by this fix.

### D.23 Phase 2 verification pass (2026-10-14, late session) — **NO CODE CHANGED**

This session was a **verification-only** pass. It was opened to complete the Phase 2 test programme recorded as outstanding in Â§E.3, Â§G.7 and Â§K. **No application source file, schema, permission or configuration was modified.**

- **Nothing was edited.** The three target files hash-match the runtime tree and the full-tree sweep shows **0 differing** files across 1,296. `register.php` still hashes `5A4C90E1871961725FF9DB7A0431243F08B00167FA6A19E9A43B83C8CC4E3C69`, exactly the value recorded at Â§D.19. All three files' modification times predate this session.
- **No backup was required**, because no file was edited. Had an edit been needed, the established procedure is a timestamped folder under `D:\WebSplend-HMS\rollback_pre_*` with a `SHA256SUMS.before.json` manifest, created and hash-verified before the first edit.
- **New capability used: a real browser.** Â§E.3 items 1â€“5 and 8 had never been executed by the agent because no browser automation was available. Headless **Chrome** (driven over the DevTools Protocol from Node 22, with no Playwright/Puppeteer dependency) was used for the first time in this project. Every assertion is real-browser evidence; see Â§G.16.
- **New method used: a throwaway scratch database.** Â§K P1-4 and Â§G.7 test 58 were blocked for want of an approved fixture. A synthetic scratch database was used instead, following the `abdm_flag_scratch` precedent already in `test_abdm_helper_states.php`. The **real HMS database was never written**; this is proven by a byte-identical checksum of `patient_master.abha_id` and `abdm_patient_links` before and after.
- **The security inspection required by Step B item 5 found no new gap.** `checkPermission` is loaded *after* `includes/db.php` in `edit_patient.php` (the required ordering, per Â§D.18), both handlers gate before any database statement, and CSRF uses `is_string` + `hash_equals`. **I.17** (no `abdm_patient_links` ownership pre-check in `update_patient.php`) remains open and was **deliberately not fixed** here.
- **Four stale-harness defects were found and corrected.** None was an application defect; in every case the application source was confirmed correct before the assertion was changed. They are recorded honestly in Â§G.16 because three of them initially produced *false results*.

---

This is the most recent work. Three files were changed and synchronised to the runtime tree.

## E. Current ABDM Registration Toggle and ABHA Preservation

### E.1 Approved behaviour

**New registration** (`modules/registration/register.php`)

- Toggle defaults to **No**.
- **Yes** reveals the existing ABDM/ABHA controls: the ABHA ID field, the Aadhaar **verification** button, **View ABHA Card**, and **Create ABHA**.
- **No** hides those ABDM-specific controls.
- The ordinary **Aadhaar** demographic input (`id="aadhaar"`, `name="aadhaar_no"`) **remains visible in both modes**. It is part of ordinary registration, not an ABDM control. The hidden element is the sibling `<button id="abdm_aadhaar_verify">`, not a wrapper â€” verified by source inspection.
- ABHA and Aadhaar are **not** made mandatory by enabling ABDM. The toggle adds no `required` attributes and no validation.
- The toggle is a **transient UI workflow flag only**. It is not persisted, is not a patient preference, is not an ABHA link, and is **not HIE consent**. No schema column was added. The form still POSTs `abdm_integration`, but no handler reads it.

**Edit patient** (`modules/registration/edit_patient.php`)

- Defaults to **Yes** when an existing ABHA ID is present, otherwise **No** â€” derived by `$abdmEditDefaultYes = !empty($patient['abha_id'])`.
- The existing ABHA value stays intact when hidden: hiding uses `style.display` only, so the input remains in the DOM with its value.

**ABHA preservation** (`modules/registration/update_patient.php`)

- Previously a blank `abha_id` in an unrelated patient edit (name, address, phone â€¦) was written straight through, **erasing `patient_master.abha_id`** while any surviving `abdm_patient_links` row remained â€” leaving the two tables inconsistent.
- Now, when `abha_id` is blank, the handler re-reads the stored value and preserves it. A non-blank value is honoured exactly as before.
- **No implicit unlink workflow was introduced.** There is no `DELETE` against `patient_master` or `abdm_patient_links` in this file. Unlinking ABHA remains an explicit, separate, currently-unimplemented action.

### E.2 Implementation detail worth preserving

- Guard placement is intentional and verified: the guard sits at **line 120**, *after* the existing validation and mobile-duplicate check, and *before* `UPDATE patient_master SET` at **line 144**.
- `update_patient.php` is **purely additive** (+36 / âˆ’0 lines). The main UPDATE, its `bind_param` type-string, and all validation blocks are byte-unchanged.
- The toggle uses `style.display`, never DOM removal, so a failed ABDM call cannot discard the rest of the form.
- `edit_patient.php`'s ABHA value uses `htmlspecialchars((string)($patient['abha_id'] ?? ''))`. The `(string)` cast was added to avoid a deprecation on `NULL`; `?? ''` handles a missing key.

### E.3 Browser tests â€” partial operator confirmation (2026-10-14)

#### What the operator confirmed (authenticated browser, local Super Admin)

| Behaviour confirmed | Result |
|---|---|
| Local Super Admin login | **Pass** |
| Patient Registration page opens | **Pass** |
| Aadhaar entry and OTP verification | **Pass** |
| ABHA ID auto-populated into the form | **Pass** |
| Patient information auto-populated (mobile, name, address) | **Pass** |
| Patient record saved successfully | **Pass** |
| Patient edit workflow (record reopened and edited) | **Pass â€” no issue observed** |

**These are the only M1 behaviours with browser evidence.** They are marked `OPERATOR-CONFIRMED` in Â§G.7. Note this was a **real ABDM round-trip** on the local configuration; it is **not** evidence about production, and it does not make the gateway flows `VERIFIED` for the automated suite (Â§G.8).

#### What is still outstanding â€” do not infer these from the above

> **UPDATE 2026-10-14 (late session).** Items **1, 2, 3, 4, 5 and 8** below were executed in a **real headless Chrome** browser session and **passed** (Â§G.16 tests 177â€“190). Item **8** is additionally confirmed at the database level. **Item 6 is still open** and **item 7 is still partial** â€” see Â§G.16 for the exact reason. The text below is retained unchanged as the original record.

1. Open **new registration**. Confirm the toggle shows **No** by default and that the ABHA field, the Aadhaar verify button, View ABHA Card, and Create ABHA are all hidden.
2. With the toggle on **No**, confirm the **plain Aadhaar field is still visible and fillable**.
3. Switch to **Yes**. Confirm all four ABDM controls appear, and that anything already typed before switching survives.
4. Switch back to **No** then to **Yes** again â€” confirm no field value was cleared.
5. Open **Edit patient** for a patient **who has** an ABHA. Confirm the toggle opens on **Yes** and the ABHA value is displayed correctly.
6. Edit that same patient, set the toggle to **No**, change an unrelated field (e.g. address), submit, then reopen. **Confirm the ABHA ID is still present** â€” this is the specific regression the guard fixes.
7. Complete an ordinary registration end-to-end with the toggle on **No** to confirm no ABDM field is required. *(Partially addressed: the operator completed an ordinary registration end-to-end, but with ABDM active. Repeat with the toggle on No.)*
8. Confirm the toggle itself is not persisted anywhere after saving.

**Untested ABHA persistence edge cases.** *(SUPERSEDED 2026-10-14 — see Â§G.16. All four of these are now database-backed **PASS** against a throwaway scratch database using the real, unmodified handler: blank-ABHA preservation, a differing non-blank ABHA, the same ABHA re-submitted with `abha_verified=0`, and an ABHA already owned by another patient.)* The original text follows.

The operator reopened and edited a saved patient without observing an issue, but this does **not** verify: blank-ABHA preservation through `abdm_patient_links`; a *different* non-blank ABHA update; the same ABHA re-submitted with `abha_verified=0`; or duplicate ABHA linked to another patient. Those remain `NOT VERIFIED` and are tracked in Â§K P1-1 and Â§J.

**Live ABDM creation and verification remain `BLOCKED` for anything beyond the operator's own local pass** â€” there is no approved non-production gateway fixture. Do not exercise these flows against any real environment.

### E.4 Global ABDM ON/OFF switch (Â§D.11, Â§E.6, Â§F.8, Â§G.4-Â§G.5, Â§H.3; also Â§added 2026-10-13)

**What it is.** One switch per client installation, stored in that installation's own database (`abdm_master_settings`, one row). It is **subordinate to nothing and superior to the patient-level toggle**: when it is OFF the patient-level toggle is not even rendered.

**How an administrator uses it.** Settings â†’ ABDM Settings â†’ the *Global ABDM* panel above the M1â€“M4 grid. Only a role holding `abdm_settings` **update** can change it, which today means Super Admin (Â§F.7 note). **That page stays reachable while ABDM is OFF**, so the switch can always be turned back on.

**Global OFF â€” exact behaviour**

| Area | Behaviour |
|---|---|
| Registration / Edit Patient markup | The patient-level Yes/No card, the ABHA ID input, the Aadhaar **verify** button, the mobile **verify** button, the ABHA action buttons and all five ABDM modals are **not rendered at all** (server-side `if`, not CSS) |
| Ordinary Aadhaar field | **Stays visible and usable** â€” it is ordinary registration, not an ABDM control |
| All 12 `modules/abdm/abha_*.php` endpoints | **HTTP 403 + JSON refusal before any OTP request, gateway call, ABHA creation or verification.** Verified live (Â§G.2) |
| `modules/registration/ajax/get_abha_details.php` | **HTTP 403 + JSON refusal** in its existing `status`/`message` shape, before the ABHA parameter is read |
| M1/M2 shared chokepoints | `abdmGetAccessToken()`, `abdmM2GetAccessToken()`, all three `m2_bridge` functions, `abdmM2LinkCareContext()`, `abdmM2GenerateLinkToken()` return the library's normal failure shape. No outbound request is issued |
| Prescription save | The automatic ABDM M2 care-context link is **skipped**. The prescription is persisted first and the ABDM block runs last, so saving completes normally |
| Existing ABHA / ABDM data | **Never deleted, unlinked or modified.** Toggling issues no `DELETE`, `TRUNCATE` or `DROP` |
| Display-only screens | Registration list, OPD, IPD, receipts and reports keep showing previously stored ABHA values |
| Inbound `api/v3/**` callbacks | **Unchanged by design** (Â§E.5) |

**Global ON â€” exact behaviour.** Identical to the behaviour that existed before this change: the patient-level toggle appears, new registration defaults to **No**, Edit Patient defaults from `!empty($patient['abha_id'])`, Yes reveals the existing ABDM controls, No hides them, Aadhaar and ABHA remain optional, and the existing ABHA creation/verification code paths are unmodified apart from the gate in front of them.

**Switch unavailable (read error).** ABDM actions **fail closed**; ordinary registration, patient editing and prescription saving are unaffected; the ABDM Settings page shows a plain-language error and a sanitized diagnostic is written to the PHP error log as `[ABDM-GLOBAL-SWITCH] state=â€¦ errno=â€¦`. No SQL, credential or token is ever logged or displayed.

**Known residual.** The ABDM JavaScript function bodies remain in `register.php` when the switch is OFF. They are unreachable â€” no rendered element triggers them, and the only page-load call (`applyAbdmIntegrationVisibility`) is itself inside the gate â€” and every endpoint they would call refuses. They were left in place because they are interleaved with unrelated JavaScript in a ~6,100-line file and removing them would risk breaking unrelated behaviour. Recorded in Â§I.11.

### E.5 Inbound callbacks are deliberately not gated

The global switch does **not** disable or alter inbound gateway callbacks in `api/v3/**`. This is a recorded decision, not an oversight: those endpoints are unauthenticated **by design** (Â§B.7.5) and their gaps are already tracked in Â§I.4. The global switch is a **feature** switch, **not** a substitute for callback authentication, replay protection or consent validation. An installation that never enabled ABDM never onboarded with the gateway and therefore receives no inbound traffic.

### E.6 Why the comment leaked â€” and the rule that prevents it

`?>` **terminates PHP mode even inside a `//` comment**. Two distinct traps were hit:

1. `<?php if ($x): ?> /* comment */` â€” the comment is **outside** PHP and is printed. This was the shipped defect (Â§D.11).
2. A `//` comment that itself contains `?>` closes PHP mode, and the remainder of the file is parsed as inline HTML. This was hit while writing the regression test, which then printed its own source instead of running.

**Rule for this codebase: never place a comment after a PHP close tag, and never write a literal `?>` or `<?php` inside a single-line PHP comment.** `test_abdm_global_wiring.php` Â§11 enforces the first rule across all 26 feature files.

---

## F. Modified Files Register

### F.1 Registration and data integrity

| Path | Status | Why | Behaviour affected | Validation evidence | Runtime == working copy |
|---|---|---|---|---|---|
| `modules/registration/register.php` | `MODIFIED` | Add ABDM Yes/No toggle | Adds toggle card + `id`s on 3 ABDM elements; `applyAbdmIntegrationVisibility()` hides/shows them and closes a lingering ABHA modal. Plain Aadhaar input untouched. | `php -l` OK; JS stub test PASS; 31 static wiring assertions PASS; div balance preserved (+4/+4) | Yes |
| `modules/registration/edit_patient.php` | `MODIFIED` | Same toggle, edit context | Adds toggle card; default derived from existing `abha_id`; ABHA `value` hardened with `(string)` + `?? ''` | `php -l` OK; JS stub test PASS; 1 `value` attr, 0 duplicate attrs, escaping tested against hostile values; div balance preserved (+3/+3) | Yes |
| `modules/registration/update_patient.php` | `MODIFIED` | Fix ABHA erasure | Blank `abha_id` now re-reads and preserves the stored value. No unlink path. | `php -l` OK; **purely additive (+36/âˆ’0)**; 6/6 guard cases PASS on real extracted code; guard ordering proven; no `DELETE` added | Yes |
| `modules/registration/save_patient_registration.php` | `INSPECTED ONLY` | Confirm existing link-write behaviour | **Unchanged.** Writes `abdm_patient_links`; INSERT guarded by non-empty ABHA; **no** `DELETE FROM abdm_patient_links`; does not reference `abdm_integration`. | 981 lines; verified by inspection | Yes |

### F.2 Database / configuration

| Path | Status | Why | Behaviour affected | Validation evidence | Runtime == working copy |
|---|---|---|---|---|---|
| `includes/db_credentials.php` | `MODIFIED` (new file) | Resolver | Adds 7 `hms*` functions; inert unless `HMS_ENV=local` | 280 lines; 0 hardcoded credential literals; fail-closed path | Yes |
| `includes/db.php` | `MODIFIED` | Adopt resolver | Calls `hmsControlCredentials()`/`hmsTenantCredentials()`. **Still carries 4 production literals (Â§I.1).** Also enforces the `$conn = null` tenant gate. | 85 â†’ 93 lines; resolver present | Yes |
| `includes/tenant.php` | `MODIFIED` | Adopt resolver | 1 resolver call; **still carries 4 literals** | 87 lines; resolver present | Yes |
| `includes/tenant_db.php` | `MODIFIED` | Adopt resolver | 1 resolver call; 0 literals remain | 30 lines | Yes |
| `patient/login_process.php` | `MODIFIED` | Adopt resolver | 1 resolver call; 0 literals remain | 197 lines | Yes |

### F.3 Route guards

| Path | Status | Why | Behaviour affected | Validation evidence | Runtime == working copy |
|---|---|---|---|---|---|
| `modules/appointments/appointment_list.php` | `MODIFIED` | Auth guard | Redirects to `/index.php` when `$_SESSION['user_id']` is unset | HTTP returns login page; diff vs backup | Yes |
| `modules/registration/registration_list.php` | `MODIFIED` | Auth guard | Same guard; also retains 3 `checkPermission` calls | HTTP returns login page; diff vs backup | Yes |

### F.4 ABDM callbacks

| Path | Status | Why | Behaviour affected | Validation evidence | Runtime == working copy |
|---|---|---|---|---|---|
| `api/v3/hiu/health-information/on-request/index.php` | `MODIFIED` | Redact stored headers | Redacted copy of callback headers; still writes `abdm_m3_callbacks` | `[REDACTED]` + helper present | Yes |
| `api/v3/hip/health-information/request/index.php` | `MODIFIED` | Redact stored headers | Same | Same | Yes |
| `api/v3/hiu/health-information/data-push/index.php` | `MODIFIED` | Redact stored headers **+ log containment** | Same; also received the earlier minimal-logging change | Same; 47 added / 12 removed vs pre-logcontainment backup | Yes |
| `api/v3/hip/link/care-context/init/index.php` | `MODIFIED` | D.20 credential resolution | Resolver-backed control + tenant connection | Lint, parity, 3/3 resolver modes | Yes |
| `api/v3/hip/link/care-context/confirm/index.php` | `MODIFIED` | D.20 resolution + **D.21** identity-before-state, OTP not persisted | HIP/HIU mandatory before any OTP work; `response_payload` no longer stores the OTP | Lint, parity, 16/16 assertions, 5/5 HTTP | Yes |
| `api/v3/hip/token/on-generate-token/index.php` | `MODIFIED` | Debug sanitisation + D.20 resolution | Raw body/header dump replaced with minimal JSON; **no `[REDACTED]` redaction added** | Diff vs `rollback_pre_exfilcontain`; D.20 lint/parity | Yes |
| `api/v3/link/on_carecontext/index.php` | `MODIFIED` | D.20 credential resolution | Resolver-backed control + tenant connection | Lint, parity, 4/4 HTTP guard | Yes |
| `api/v3/hip/patient/care-context/discover/index.php` | `MODIFIED` | D.20 resolution + **D.21** bypass/leak removal + **D.22** payload privacy | `TEST-TRANSACTION-` branch removed; no HIP ID / driver error returned; `request_payload` is now a redacted summary | Lint, parity, 8/8 assertions, 15/15 sentinel stub | Yes |
| `modules/abdm/m2_user_linking.php` | `MODIFIED` | **D.21** ABHA-anchored matching | MOBILE/MR can no longer identify a patient; ambiguity refused | Lint, parity, 8/8 read-only stub | Yes |

### F.5 Security / configuration

| Path | Status | Why | Behaviour affected | Validation evidence | Runtime == working copy |
|---|---|---|---|---|---|
| `.htaccess` | `MODIFIED` | Block logs + sensitive files + indexing | 3 rule families added (Â§D.5); pre-existing ABDM rewrites and IP list retained | 403 responses verified for 4 paths | Yes |
| `C:\xampp\apache\conf\extra\httpd-vhosts.conf` | `MODIFIED` (outside repo) | Local HTTPS + env wiring | Â§B.4 | `httpd -t` â†’ Syntax OK; backup exists | n/a |

### F.6 Inspected and deliberately not changed

| Path | Status | Note |
|---|---|---|
| `includes/db.php` (credential literals) | `INSPECTED ONLY` this task | Hardcoded literals **retained on purpose**; remediation requires approval (Â§K P0-1) |
| `modules/abdm/*` (M1/M2/M3 modules) | `INSPECTED ONLY` | Audited in Phase 0/1A; no changes made |
| `audit/PHASE_0_INVENTORY.md`, `audit/PHASE_1A_X25519_FIDELIUS_COMPATIBILITY.md` | `INSPECTED ONLY` | Read as evidence; not edited |
| All other ~1,230 files | `UNCHANGED` | Full-tree hash comparison confirms no drift |

### F.7 Global ABDM switch â€” file register (2026-10-13)

**Created (2)**

| Path | Purpose |
|---|---|
| `includes/abdm_helper.php` | Single reader for the switch. Four states (`ok` / `missing` / `error` + `no_connection`), strict value validation, request-scoped cache, sanitized diagnostics. Procedural PHP, mysqli only. Does not touch tenant resolution |
| `modules/abdm/settings/save_global_switch.php` | POST-only handler: session check, `checkPermission('abdm_settings','update')`, `hash_equals` CSRF against the existing `$_SESSION['csrf']`, strict `on`/`off` validation, prepared upsert inside a transaction, `addAuditLog()`, flash + redirect. Runs `CREATE TABLE IF NOT EXISTS` **only** when the table is genuinely absent |

**Modified (26)**

| Path | Change |
|---|---|
| `modules/abdm/settings/abdm_settings.php` | Global ABDM ON/OFF panel above the M1â€“M4 grid, server-side read, ON/OFF/unavailable/missing states, CSRF field, flash messages. Page deliberately **not** gated by the switch |
| `modules/registration/register.php` | `$abdmGlobalEnabled` computed server-side; 7 render gates: toggle card, ABHA field, Aadhaar verify button, mobile verify button, ABHA action buttons, toggle JS + initialiser, all five ABDM modals. Plain Aadhaar field untouched |
| `modules/registration/edit_patient.php` | Same pattern; 3 render gates (toggle card, ABHA field, toggle JS). ABHA value expression untouched |
| `modules/registration/ajax/get_abha_details.php` | 403 + existing JSON shape when OFF/unavailable, before the ABHA parameter is read |
| `modules/opd/save_prescription.php` | ABDM block condition changed to `abdmOutboundAllowed($conn) && â€¦`. Prescription logic untouched |
| `modules/abdm/auth.php` | `abdmGetAccessToken()` refuses first â€” the single M1 chokepoint all 12 endpoints use |
| `modules/abdm/m2_auth.php` | `abdmM2GetAccessToken()` refuses first â€” the single M2 token chokepoint |
| `modules/abdm/m2_bridge.php` | All three public functions refuse first |
| `modules/abdm/m2_care_context.php` | `abdmM2LinkCareContext()` returns the standard failure shape when refused |
| `modules/abdm/m2_link_token.php` | `abdmM2GenerateLinkToken()` returns the standard failure shape when refused |
| `modules/abdm/config.php` | Requires the helper and exposes `ABDM_M1_GLOBAL_ENABLED`; URLs still defined so including the file can never fatal |
| `modules/abdm/m2_config.php` | Same, exposing `ABDM_M2_GLOBAL_ENABLED` |
| `modules/abdm/abha_*.php` (12 files) | 403 + existing JSON refusal shape immediately after the header requires, before any input processing, OTP request, gateway call or ABHA create/verify |

**Access control note.** The `abdm_settings` module row already existed (`modules.id = 65`), so **no permission migration was required**. The only grant today is `rights_type_id = 1` (Super Admin) with full rights; a non-Super-Admin Admin is refused (Â§G.2 test 24).

### F.8 Visible-comment and layout fix â€” file register (2026-10-13, second pass)

| Path | Change |
|---|---|
| `modules/registration/register.php` | 7 Ã— gate open-tag: comment moved **inside** the PHP block (was emitting visible text). 1 Ã— `require` â†’ `require_once` for the helper. **Line count unchanged (6,148 â†’ 6,148)** â€” purely in-place |
| `modules/registration/edit_patient.php` | `require` â†’ `require_once` only. **No comment leak existed here** |
| `modules/registration/ajax/get_abha_details.php` | `require` â†’ `require_once` only |
| `modules/opd/save_prescription.php` | `require` â†’ `require_once` only |
| `modules/abdm/settings/abdm_settings.php` | `require` â†’ `require_once` only |
| `modules/abdm/settings/save_global_switch.php` | `require` â†’ `require_once` only |

No markup, input id, form name, validation rule, JavaScript handler, ABHA logic, payment section or form destination was altered. `api/v3/**`, `.htaccess`, callback redactions, permissions, CSRF handling, tenant resolution, the switch table and all patient data are untouched.

### F.9 Registration security closure + M1 remediation â€” file register (2026-10-14)

**Created (1)**

| Path | Purpose |
|---|---|
| `modules/abdm/abdm_m1_request_guard.php` | Shared M1 request guard: POST â†’ session â†’ `checkPermission('patient_registration','create')` â†’ `hash_equals` CSRF, enforced before any gateway interaction. Response shape `{success, message, abdm_state}` matches the existing M1 JSON contract. Follows the established convention in `modules/abdm/settings/save_global_switch.php:54-79` |

**Modified â€” Phase 2 registration closure (4)**

| Path | Change | Runtime == working copy |
|---|---|---|
| `modules/registration/save_patient_registration.php` | Permission + CSRF guards before `begin_transaction()`; `$abha_supplied` capture and upsert gate; allow-list catch block; `Throwable` catch; `is_string` CSRF guard | Yes |
| `modules/registration/update_patient.php` | Permission + CSRF guards before any statement; HTTP 400 on validation; `error_log` + generic 500 instead of echoing `$stmt->error`; `is_string` CSRF guard | Yes |
| `modules/registration/edit_patient.php` | `view` permission gate + CSRF token field | Yes |
| `modules/registration/register.php` | CSRF token field for the form | Yes |

**Modified â€” M1 remediation (14)**

| Path | Change | Runtime == working copy |
|---|---|---|
| `modules/appointments/ajax/check_patient_by_aadhaar.php` | Rewritten: POST-only, session, `view`, CSRF, session-derived tenant, explicit 13-column list. **No longer imports `public_tenant.php`.** | Yes |
| `modules/registration/register.php` | `window.csrfToken` emitted; CSRF added to all 9 ABDM call bodies; Aadhaar lookup converted from GET to POST | Yes |
| `modules/abdm/abha_fetch_request_otp.php` Â· `abha_fetch_verify_otp.php` Â· `abha_fetch_profile.php` | Guard required; `raw_response` removed (5 sites); token echo replaced; `curl_error` suppressed | Yes |
| `modules/abdm/abha_request_aadhaar_otp.php` Â· `abha_request_mobile_otp.php` Â· `abha_verify_aadhaar_otp.php` Â· `abha_verify_mobile_otp.php` Â· `abha_request_otp.php` Â· `abha_verify_otp.php` Â· `abha_phr_request_otp.php` Â· `abha_phr_search.php` Â· `abha_phr_verify_otp.php` | Guard required; token echo replaced; `curl_error` suppressed; `display_errors` disabled where set | Yes |

**Inspected and deliberately NOT changed**

| Path | Note |
|---|---|
| `includes/public_tenant.php` | **Not modified** â€” 21 callers. The tenant fix was made in the one vulnerable endpoint instead |
| `modules/abdm/test/index.php` Â· `modules/abdm/test/m2_index.php` | **Not modified.** Super-Admin-gated test harnesses. See Â§I.14 |
| `modules/appointments/ajax/get_patient_by_mobile.php` | **Not modified** â€” still `SELECT *`. See Â§I.13 |
| `includes/db.php` Â· `includes/tenant.php` Â· `includes/permissions.php` Â· `includes/abdm_helper.php` | Unchanged â€” verified byte-identical after sync |
| `.htaccess` Â· `modules/abdm/config.php` Â· `modules/abdm/auth.php` | Unchanged â€” verified byte-identical after sync |

**Backups:** `rollback_pre_phase2sync_runtime\20261014_082751\` (Â§D.12) and `rollback_pre_m1_remediation\20261014_091149\` (Â§D.13, 14 files + 14-line SHA-256 manifest).

---

## G. Tests and Verification Ledger

Each row states its **test class** so synthetic results are never mistaken for production results.

| # | Test | Class | Result |
|---|---|---|---|
| 1 | `php -l` on the 3 changed files (source + runtime) | Static | **OK** â€” 6/6 clean |
| 2 | Source vs runtime hash, 3 changed files | Static | **Match** â€” all 3 |
| 3 | Full-tree hash sweep (1,243 `.php`/`.htaccess`/`.js`) | Static | **0 differing** |
| 4 | Full-tree sweep **before** sync | Static | Exactly **3** differing â€” the approved files only |
| 5 | Diff review of all 3 changed files vs backups | Static | Intentional only; `update_patient.php` +36/âˆ’0 |
| 6 | Guard-position assertion (after validation, before UPDATE) | Static | **PASS** â€” line 120 vs line 144 |
| 7 | ABHA guard, 6 cases (blank, NULL, absent, explicit, empty-string) | **Stub/synthetic** â€” real code extracted by brace-match, executed against a stub connection | **6/6 PASS** |
| 8 | Toggle JS, both forms, No and Yes | **Stub/synthetic** â€” real JS extracted, run in Node against a DOM stub | **PASS** â€” all targets + modal + "values never cleared" |
| 9 | Static DOM wiring (31 assertions) | Static | **PASS** |
| 10 | `edit_patient.php` ABHA markup (1 `value`, 0 dup attrs, escaping) | Static + synthetic hostile values | **PASS** â€” `"` `'` `&` `<script>` all neutralised; NULL/missing â†’ empty, no deprecation |
| 11 | Apache config syntax (`httpd -t`) | Local config | **Syntax OK** |
| 12 | Unauthenticated GET: `/`, `appointment_list.php`, `registration_list.php`, `register.php` | **Local HTTP** | All return the 4,782-byte login page |
| 13 | Unauthenticated GET: `abdm_callback_debug.txt`, `abdm_token_cache.json`, `includes/control_db.php`, `uploads/` | **Local HTTP** | All **403** |
| 14 | Manual staff login + dashboard | **Authenticated browser** | Operator-confirmed in an earlier session. **NOT VERIFIED** by the agent. |
| 15 | ABDM toggle in a browser (show/hide, defaults, persistence) | Authenticated browser | **NOT PERFORMED** â€” see Â§E.3 |
| 16 | ABHA preservation against a real database | **Database-backed** | **NOT PERFORMED** â€” see below |
| 17 | Any ABDM gateway operation | Live integration | **NOT PERFORMED â€” `BLOCKED`** |
| 18 | Inbound Fidelius crypto (ECDH/HKDF/AES-GCM) | Offline crypto probe | **PASS** (audit 1A Â§6) â€” byte-identical to OpenSSL ground truth |
| 19 | Outbound X25519 key generation | Offline probe | **FAIL/BLOCKED** â€” `OPENSSL_KEYTYPE_X25519` undefined on PHP 8.2 |

### G.1 Important limits on the above

- **Tests 7â€“10 are stub/synthetic, not database-backed.** They execute the *real extracted* code, but against a stub connection â€” **not** against MySQL. They prove logic, not persistence.
- **The DB layer was deliberately not exercised.** `includes/db.php` returns `$conn = null` without `$_SESSION['tenant_id']`; the agent did not impersonate a session or connect to the database. Test 16 therefore remains open.
- **Discarded false results.** Earlier in this work, an initial guard test reported "ALL GUARD CASES PASS" while never actually executing the guard block (the helper was defined and never called, and the extracted block was never used). It was discarded and rebuilt; **only test 7 above is valid**. Three subsequent harness defects produced false failures and were corrected after confirming the source was correct each time â€” a duplicate-attribute check, an Aadhaar attribute-name check, and a redaction grep that required a literal `Bearer` the file does not contain. None indicated a defect in the application code.
- **No browser or end-to-end ABDM success is claimed by the agent anywhere in this document.** The agent has no authenticated browser session. A **human operator** browser pass was recorded on 2026-10-14 (Â§E.3, Â§G.7 tests 80â€“86); it is attributed to the operator, is limited to the seven listed behaviours, and is **not** evidence about production.

### G.2 Global ABDM switch verification (2026-10-13)

All tests below ran against the **local** XAMPP instance. **No live ABDM gateway operation was performed at any point.**

| # | Test | Class | Result |
|---|---|---|---|
| 20 | `php -l` on all 26 changed files | Static | **26/26 clean** |
| 21 | Source vs runtime SHA-256, all 26 synced files | Static | **26/26 match** |
| 22 | Full-tree sweep **before** sync | Static | Exactly **24** source files differing + 2 new â€” the approved set only |
| 23 | Full-tree sweep **after** sync | Static | **0 differing source files**. Only `PROJECT_STATUS_AND_HANDOVER.md` (working-copy only, deliberately not deployed) and `abdm_callback_debug.txt` (runtime-appended debug log, pre-existing drift) |
| 24 | Helper states: ON, OFF, table missing, row missing, malformed value, wrong column, NULL value, no connection | **Database-backed**, throwaway database | **8/8 PASS** â€” `missing` â†’ ON, `error` â†’ fail closed, `ok` â†’ stored value |
| 25 | Static wiring: gate presence and gate position in all 12 endpoints, 6 chokepoints, prescription, AJAX, render gates, settings handler, out-of-scope files untouched | Static | **127/127 PASS** |
| 26 | Registration + Edit Patient render, OFF vs ON, ordinary fields and Aadhaar unaffected | **Database-backed** (real pages, simulated Super Admin session) | **47/47 PASS** |
| 27 | ABHA / ABDM data integrity across ONâ†’OFFâ†’ON cycling | **Database-backed** | `patient_master` count, stored ABHA count, `abdm_patient_links` and `abdm_care_contexts` **all unchanged** |
| 28 | ABHA preservation guard with a blank `abha_id` | **Database-backed** | Stored ABHA **preserved** |
| 29 | Live HTTP: settings page ON/OFF states, CSRF missing / wrong / valid, GET rejected, invalid value rejected | **Live HTTP** (authenticated local test session) | **45/45 PASS**, including "no CSRF", "invalid CSRF", "GET", and "invalid value" all leaving the stored value untouched |
| 30 | Live HTTP: all 12 `abha_*.php` endpoints with ABDM OFF | **Live HTTP** | **12/12 HTTP 403** with the switch refusal message; all 12 completed in well under a second, and `modules/abdm/m2_auth_test.log` was **not** modified â€” proving no gateway round-trip occurred |
| 31 | Live HTTP: `get_abha_details.php` refusal leaks no patient data | **Live HTTP** | No `patient`, no `aadhaar` key in the refusal body |
| 32 | Prescription auto-link gate position and ON/OFF behaviour | Static + **Database-backed** (no prescription saved) | Guard present, sits **after** prescription persistence and **before** `abdmM2LinkCareContext()`; `outboundAllowed` = 1 when ON, 0 when OFF |
| 33 | Non-Super-Admin (`rights_type_id = 2`) cannot view or save | **Live HTTP** | GET â†’ 302; POST â†’ *Access Denied*; **stored value unchanged**; the Admin also does not see the ABDM Settings card |
| 34 | Audit trail for switch changes | **Database-backed** | `audit_logs` rows written with old/new values, user, role, IP, session â€” **no secret recorded** |
| 35 | Manual operator browser pass of the switch | Authenticated browser | **NOT PERFORMED** â€” see Â§K P1-2 |
| 36 | Two-tenant independence (HMS1 vs `TEN-â€¦`) | **NOT TESTABLE LOCALLY** | See Â§G.3 |

### G.3 Limits and honest gaps in the above

- **Test 36 could not be run.** `projects` lists two tenants (`HMS1`, `TEN-4635833D03`), but in local mode `hmsTenantCredentials()` returns the **same local tenant database for every tenant**, so both tenants resolve to one database locally. Multi-tenant isolation is therefore **verified by design and inspection** (one database per client, Â§B.3), **not** by execution. Production must be checked on a host where the two tenants genuinely have separate databases.
- **No end-to-end prescription save was performed** (test 32), because that would mutate real prescription and billing data. The gate is proven structurally and behaviourally; the full prescription path is **`NOT VERIFIED`**.
- **No live ABDM gateway call was made.** Every ABHA endpoint was exercised only while the switch was OFF, where the refusal happens before any network call. The ABHA creation/verification code itself is **`NOT VERIFIED`**.
- **The ABDM JavaScript remains in `register.php` while OFF** (unreachable, see Â§E.4 and Â§I.11).
- **Four rows in `audit_logs` (ids 218â€“221) are test artefacts** produced by the local test session `abdm-switch-test`. They were left in place deliberately â€” audit history must not be deleted. An operator may ignore them.
- Earlier false results described in Â§G.1 were also produced by test harnesses, not by the application. The same class of harness defect was found and fixed again in this task (a malformed regex, two over-broad string assertions and a request-cache artifact); in every case the application source was confirmed correct before the assertion was corrected.

### G.4 Visible-comment / layout fix verification (2026-10-13)

| # | Test | Class | Result |
|---|---|---|---|
| 37 | Rendered-HTML audit for comment text, in **all four** states (both pages Ã— ON/OFF). Scans user-visible text after stripping `<style>`, `<script>` and valid `<!-- -->` | **Database-backed** (real pages, simulated Super Admin session) | **CLEAN** â€” no `GLOBAL ABDM SWITCH`, no `/*`, no `*/`, no leaked PHP tag, no `$abdmâ€¦` variable, no stray grid text, no blank grid cells |
| 38 | Four UI states, running the **real** `applyAbdmIntegrationVisibility()` extracted from the **real** rendered page against the **real** rendered element ids (Node DOM shim) | Synthetic-DOM against real markup | **36/36 PASS** â€” see the four states below |
| 39 | Live HTTP over `https://websplend-hms.local`, all four states | **Live HTTP** | **0** occurrences of `GLOBAL ABDM SWITCH` and **0** `/*` in visible text; OFF â†’ no ABDM toggle; ordinary Aadhaar/Mobile/Title intact; ON (edit) â†’ toggle and ABHA field present |
| 40 | `php -l` on all 6 changed files | Static | **6/6 clean** |
| 41 | Diff review vs the task backup | Static | `register.php`: 7 gate lines + 1 require line changed, **line count identical**; `edit_patient.php`: 1 line; 4 files: 1 line each. Nothing else |
| 42 | Source vs runtime SHA-256, 6 synced files | Static | **6/6 match** |
| 43 | Full-tree sweep after sync | Static | **0 differing source files** (only the working-copy-only handover doc and the runtime-appended `abdm_callback_debug.txt`) |
| 44 | Regression: helper states / static wiring / DB-backed functional | â€” | **8/8**, **156/156** (was 127; 29 new guards added), **47/47** |
| 45 | `.htaccess` and the three callback redactions | Static | `.htaccess` byte-identical workingâ†”runtime; log deny, sensitive-file deny and `Options -Indexes` all present; `[REDACTED]` present in all three callbacks |
| 46 | Operator visual confirmation of the Patient Details grid | Authenticated browser | **NOT PERFORMED** â€” see Â§K P1-2 |

### G.5 The four UI states as tested

| State | Result |
|---|---|
| **1. Global ON + patient ABDM Yes** | ABHA field, Aadhaar verify button and ABHA action buttons all **visible** (`display=''`) |
| **2. Global ON + patient ABDM No** | All three **hidden** (`display='none'`); the ordinary Aadhaar demographic field is still present |
| **3. Global OFF + Registration** | No patient-level toggle, no `abha_id`, no `abdm_aadhaar_verify`, no action buttons, no modals; **Aadhaar, Mobile, Title, First Name, Last Name and the form action all intact** |
| **4. Global OFF + Edit Patient** | No toggle, no ABHA input, no toggle JS; **ordinary Title field and the `update_patient.php` destination intact** |

Regression also confirmed intact: payment section, doctor/slot loaders, city modal, revisit modal, referral modal, the ABHA endpoint URLs in JS, and `id="appointmentForm"`.

### G.6 Test harnesses (re-runnable, outside the repo)

`D:\temp\opencode\test_guard.php` Â· `test_toggle.js` Â· `test_wiring.php` Â· `review_edit_markup.php` Â· `collect_evidence.php` Â· `cmp_db.php`
These read the repository but never modify it, never connect to a database, and never print credential values.

**Added 2026-10-14 (Phase 2 verification pass) â€” see Â§G.16:**

| Harness | Purpose | Touches |
|---|---|---|
| `browser_phase2.js` | Â§E.3 items 1â€“5 and 8 in a **real headless Chrome** over CDP. Read-only: never submits a form | Live local HTTP only |
| `browser_phase2_off.js` | Global ABDM OFF rendering in a **real headless Chrome**. Read-only | Live local HTTP only |
| `phase2_browser_off.ps1` | Wrapper: captures the switch value, sets OFF, runs the OFF browser checks, **restores in a `finally`**, then independently re-verifies | One `abdm_master_settings` row |
| `test_abdm_http_v2.ps1` | 12-endpoint OFF refusal asserting **both** the M1 guard precedence and the switch message | One `abdm_master_settings` row |
| `phase2_authz_http.ps1` | Authentication / authorisation / CSRF matrix over live HTTP | Read-only; fingerprint-proven |
| `phase2_m1_matrix.ps1` | 60-assertion M1 guard matrix + `.htaccess` protected URLs | Read-only |
| `p2_abha_persistence.php` | **Database-backed** ABHA persistence, running the **real `update_patient.php`** and the extracted real ABDM link block | Creates and **drops** `websplend_phase2_scratch` |
| `p2_fingerprint.php` | Read-only `MD5` over `patient_master.abha_id` + `mobile`, used as the "nothing was written" proof | `SELECT` only |

All of these print only booleans, counts and synthetic labels. **No credential, token, ABHA number or patient identifier is printed by any of them.**

### G.7 Registration security closure + M1 remediation verification (2026-10-14)

**Phase 2 â€” registration security closure (Â§D.12)**

| # | Test | Class | Result |
|---|---|---|---|
| 47 | `php -l` on the 4 changed files (source **and** runtime) | Static | **8/8 clean** |
| 48 | Working-copy vs runtime SHA-256, 4 files | Static | **4/4 match** |
| 49 | Full-tree sweep before / after sync | Static | Exactly **4** source files differing, then **0** |
| 50 | Diff review vs `rollback_pre_phase2sync_runtime` backup | Static | Intended changes only; no unrelated drift |
| 51 | Catch block: 6-entry allow-list, single `foreach`, `$safeMessage` not `$msg`, rollback first, generic 500 | Static | **5/5 criteria confirmed**; one `die` per branch, no unreachable statements |
| 52 | `$abha_supplied` placement â€” capture before preservation, gate on the upsert | Static | Confirmed at runtime lines 84 and 282 |
| 53 | Raw SQL-error scan across the 4 files | Static | 1 hit â€” pre-existing, **unreachable** under `MYSQLI_REPORT_STRICT` |
| 54 | Unauthenticated access to the 4 handlers | **Live HTTP** | Fail-closed: 302 to `access_denied.php`, or allow-listed 400 |
| 55 | Permission matrix â€” `rights_type_id` 2 allowed; 3, 4, 5 denied | **Live HTTP** | Matches the single `module_permissions` grant row (Â§J note) |
| 56 | CSRF missing / invalid-string / **array-valued** on save + update | **Live HTTP** | All refused; array case produced **no** fatal/TypeError |
| 57 | Validation errors â†’ HTTP 400 with byte-exact allow-listed body | **Live HTTP** | **3/3 exact** |
| 58 | Database-write / ABHA persistence behaviour | **Database-backed** | **`NOT PERFORMED`** â€” no approved synthetic fixture or cleanup procedure exists |

**M1 critical security remediation + sync (Â§D.13)**

| # | Test | Class | Result |
|---|---|---|---|
| 59 | `php -l` on 14 changed + 1 new file, runtime | Static | **15/15 clean** |
| 60 | Approved file set verified (12 endpoints + guard + aadhaar endpoint + `register.php`) | Static | **15/15**, zero drift since review |
| 61 | Backup completeness and self-integrity | Static | **14/14** manifest lines present, **0** hash failures |
| 62 | Post-sync runtime vs working-copy SHA-256 | Static | **15/15 match** â€” no restore required |
| 63 | Protected files unchanged after sync | Static | **14/14 byte-identical** |
| 64 | Guard behaviour, 7 scenarios â€” wrong method / no session / permission denied / CSRF missing / array / invalid / valid | **Real guard file** executed with a stubbed `permissions.php`; no DB, no network | **7/7 as specified** |
| 65 | Same 7 scenarios against `check_patient_by_aadhaar.php` | **Real file**, stubbed dependencies | **7/7**; every denial exited **before** the database was touched |
| 66 | Guard ordering vs gateway entry points | Static | Guard line 7 < switch line 22 < `abdmGetAccessToken()` line â‰¥93, in **all 12** |
| 67 | Emitted JSON keys, before vs after, all 12 endpoints | Static | **Only `raw_response` removed**; **no key added**, no other key removed |
| 68 | Unguarded property access on changed fields in `register.php` | Static | **None** â€” every read is an `if (data.x)` guard or a fallback; no `TypeError` possible |
| 69 | Success payloads intact | Static | `txnId` Ã—5, `xToken`, `profile`, `profile`/`account`/`accounts`, `response`/`users`, `authResult` all still returned |
| 70 | Aadhaar field contract vs caller | Static | **13 consumed / 13 returned / 0 extra / 0 missing** |
| 71 | Aadhaar **match** path against a real record | **Live HTTP** (read-only) | HTTP **200**, `found=true`, **exactly the 13 approved fields**, 0 extra, 0 missing |
| 72 | M1 guard refusals across all 12 endpoints | **Live HTTP** | unauth **401** Ã—12 Â· unauthorized **403** Ã—12 Â· missing CSRF **403** Ã—12 Â· array CSRF **403** Ã—12 Â· GET **405** Ã—12 |
| 73 | M1 permitted path stops at input validation | **Live HTTP** | **5/5** â€” reached validation, **no gateway call** |
| 74 | Phase 2 security suite re-run after the M1 sync | **Live HTTP** | **22/22** |
| 75 | Combined HTTP security regression after the M1 sync | **Live HTTP** | **47/47 PASS** |
| 76 | `.htaccess` protection URLs post-sync | **Live HTTP** | **5/5 â†’ HTTP 403** (`abdm_token_cache.json`, `m2_auth_test.log`, `abdm_callback_debug.txt`, `SINGLE TENANT/installation.txt`, `includes/control_db.php`) |
| 77 | Evidence that no outbound gateway call occurred | **Log mtime** | `abdm_token_cache.json` and all ABDM debug logs unchanged since 2026-10-12; Apache error log shows **0** entries dated 2026-10-14 while the test window ran to 09:48 |
| 78 | Session-cleanup incident | **Incident** | ~287 pre-existing `sess_*` files deleted unintentionally â€” see Â§I.12 |
| 79 | Sandbox OTP / ABHA profile behaviour | Live integration | **`NOT VERIFIED`** by the automated suite â€” no gateway call was made by design |

**Operator browser verification â€” authenticated, local, Super Admin (Â§E.3)**

| # | Test | Class | Result |
|---|---|---|---|
| 80 | Local Super Admin login | Authenticated browser | **Pass** |
| 81 | Patient Registration page opens | Authenticated browser | **Pass** |
| 82 | Aadhaar entry and OTP verification | Authenticated browser | **Pass** |
| 83 | ABHA ID auto-populated | Authenticated browser | **Pass** |
| 84 | Mobile, name and address auto-populated | Authenticated browser | **Pass** |
| 85 | Patient record saved successfully | Authenticated browser | **Pass** |
| 86 | Patient edit workflow â€” record reopened and edited | Authenticated browser | **Pass â€” no issue observed** |

### G.8 Limits and honest gaps in the new tests

- **The automated suite never contacted the ABDM gateway.** Tests 64â€“77 ran with the guard refusing before any outbound request, or stopped at input validation. **Sandbox OTP and profile behaviour is `NOT VERIFIED` by automation** (test 79).
- **The operator pass (tests 80â€“86) is a different class of evidence.** It is a real ABDM round-trip against the local configuration, performed by a human. It confirms the seven behaviours listed and **nothing more**. It is **not** evidence about production, and it does not verify the ABHA persistence edge cases listed in Â§E.3.
- **Test 58 remains open.** No database-write test was performed in either phase â€” no approved synthetic fixture with a documented cleanup procedure exists. ABHA preservation and link-status integrity are therefore **code-verified only**.
- **Tests 64â€“65 executed the real guard files with a stubbed `permissions.php`.** That isolates and proves the guard's own logic and ordering; it is not a substitute for the end-to-end HTTP evidence in tests 72â€“75, which ran against the real runtime.
- **Two harness defects were introduced and corrected during the M1 work**, neither an application defect: a PowerShell read-only `$PID` collision, and a probe that treated HTTP 200 as "record found" when `edit_patient.php` answers a missing id with `die("Patient not found")` and no status change.
- **CLI cannot reach the control database** on this host (Apache resolves credentials differently), so the permission matrix was read from the offline control dump rather than by live query. The dump is dated 2026-10-12; the live behaviour in test 55 is consistent with it.

### G.9 M1 four-file closure verification (2026-10-14)

Recorded as three separate classes. **Nothing below is inferred or rounded up.**

#### PASSED

| # | Check | Result |
|---|---|---|
| 87 | PHP lint, working copy and runtime, all 4 files | **8/8 clean** |
| 88 | Runtime SHA-256 vs working copy, all 4 files | **4/4 match** |
| 89 | M1 guard sweep - all `abha_*.php` still carry the guard | **12/12** |
| 90 | Guard matrix: unauth 401 / no-permission 403 / no CSRF 403 / bad CSRF 403 / valid CSRF 200, x12 endpoints | **60/60** |
| 91 | `.htaccess` protected URLs return 403 | **5/5** |
| 92 | `get_abha_details.php` unauthenticated | **401**, body `{"status":false,"message":"Authentication required."}` |
| 93 | `get_abha_details.php` authenticated, no permission | **403**, body `{"status":false,"message":"Access denied."}` |
| 94 | `get_patient_full_details.php` unauthenticated | **401**, body `Authentication required.` |
| 95 | `get_patient_full_details.php` authenticated, no permission | **403**, body `Access denied.` |
| 96 | `get_patient_full_details.php` authorized (superadmin) | **200**, 5493 bytes of the expected HTML fragment |
| 97 | `get_abha_details.php` authorized, sentinel ABHA | **200**, clean JSON `No patient found.` - **proves the fatal error is gone and the query executes** |
| 98 | Harness serves to superadmin | **200**, emits a 64-hex `window.abdmTestCsrf` that matches the session token |
| 99 | `postRequest()` attaches the token | present; all **8** M1 call sites still mediated by `postRequest()`, zero bare `fetch()` to an M1 endpoint |
| 100 | ABHA link branches on a stub connection, no database | **8/8** - new link; new link verified; **same ABHA not re-verified -> 0 writes, state preserved**; same ABHA previously Pending -> 0 writes; same ABHA freshly verified -> promotes to `Verified`; ABHA changed -> `Pending` + NULL; **ABHA owned by another patient -> 0 writes + warning set**; blank ABHA -> no link write |
| 101 | JSON key to SELECT column integrity, `get_abha_details.php` | **16/16** keys map to a selected column; **9/9** fields consumed by `fillLocalABHADetails()` still mapped; `remarks` absent from the file entirely |
| 102 | Blast radius of the four-file change set | **exactly 4 files** modified since the pre-edit cutoff, **0 unexpected**; `.htaccess` and all 12 M1 endpoints untouched |
| 103 | Blast radius of the `remarks` fix | **exactly 1 file** modified; the other 3 M1 files byte-identical |
| 104 | Schema and migration constraint | no migration; `idx_abha_number` still non-unique; no `patient_master` column added |

#### FAILED (now resolved)

| # | Check | Result |
|---|---|---|
| 105 | `get_abha_details.php` authorized, real matching ABHA | **FAILED** at first run - `Fatal error: Unknown column 'remarks' in 'field list'`. Root-caused to a pre-existing defect (Â§D.15), fixed under approval, and re-tested as test 97, which passes. |

#### NOT VERIFIED - do not treat as done

- **End-to-end registration with a local ABHA match is NOT VERIFIED.** The repaired lookup path is **newly reachable** - it never executed before - and it still needs a real browser test with a patient that actually holds an ABHA. Nothing in this suite exercises `fillABHAProfile()`.
- **The ABHA "found" response branch was never exercised.** No patient on this local instance has a matching `abha_id`, and fabricating one would mean writing patient data, which was prohibited. Field integrity was instead proven structurally (test 101) and the query itself proven to run (test 97). The happy path remains untested at runtime. **CLOSED by test 115** - once the revisit form surfaced the ABHA (D.16), a real matching record was available and the branch returned `status: true` with all 9 consumed fields present.
- **Real DB persistence for the ABHA branches is NOT VERIFIED.** Test 100 runs the real code against a stub connection. It proves the branch logic, not the table behaviour. Still blocked on an approved synthetic fixture plus documented cleanup SQL (Â§G.8 test 58, unchanged).
- **Duplicate-ABHA protection is TOCTOU best-effort only.** Without `UNIQUE(abha_number)` - which the constraints prohibit - two concurrent registrations can still both pass the ownership pre-check. Recorded as residual in Â§I.13.
- **No live ABDM gateway call was made** in this phase. Sandbox OTP and profile behaviour remain unverified by automation, exactly as in Â§G.8.
- **The 60/60 guard matrix is a re-implementation.** The original "47/47" suite does not exist as a script anywhere in the repository or in the working copy; it was re-created from the described assertions. The earlier 47/47 figure in Â§G.7 stands on its own record, but the two numbers are not directly comparable.
- **Global ABDM ON/OFF browser acceptance remains PENDING.** The vhost log contains no `save_global_switch.php` POST on 2026-10-14; all 17 such POSTs are from 2026-10-13 and belong to the automated suite. Not performed by this work and not claimed.
- **Role-based 403 was proven via a session with `rights_type_id` absent**, not via a real non-privileged role, because the control database is unreachable from CLI and no live role matrix dump exists for the tenant. `checkPermission()` returns false in that case before any query, so the deny branch is covered; the "role exists but lacks the grant" branch is not separately exercised.
- **Session files were deliberately not deleted**, per constraint. `sess_m1closureSA001` and `sess_m1closureNoPerm01` remain on disk in `C:\xampp\tmp`.

---

### G.10 ABHA ID revisit prefill verification (2026-10-14)

Scope was 1 file / 1 line (`modules/registration/register.php`). All tests below are **read-only**: no patient was saved or updated, no patient was created, no database write of any kind was performed, and no ABDM gateway call was made.

#### PASSED

| # | Check | Result |
|---|---|---|
| 106 | PHP lint - working copy | clean |
| 107 | PHP lint - runtime | clean |
| 108 | SHA-256 working copy vs runtime | **MATCH** - `573A9ADC34B2DFDB73A374BA9E038BD567CF9406D26572F22342A9977EB00098` |
| 109 | Corrected line-by-line diff vs backup | **0 real differing lines**; exactly 1 line inserted, +82 bytes, no BOM, no encoding change |
| 110 | Blast radius | **exactly 1** runtime file modified; the 4 previously modified M1 files all byte-identical to their working copies |
| 111 | Global ABDM toggle untouched | 8 `$abdmGlobalEnabled` / `abdmIsAbdmGloballyEnabled` references before and after; input still inside the `:1379-1406` conditional |
| 112 | Revisit form renders the ABHA as populated | **POPULATED** (presence asserted only; the value was never printed or logged) |
| 113 | No regression on other revisit fields | `first_name`, `last_name`, `mobile`, `dob`, `pincode` all **POPULATED**; `address` populated |
| 114 | Fresh (non-revisit) registration unaffected | `abha_id` renders **EMPTY**, as before |
| 115 | `get_abha_details.php` **found branch**, real matching record | **200**, valid JSON, `status: true`, `"Patient found."`, **16 keys**, **9/9** fields consumed by `fillLocalABHADetails()` present, `remarks` absent. Local DB lookup only - **no gateway call**. This closes the NOT VERIFIED item in §G.9 |
| 116 | `abha_verified` hidden field on revisit | `"0"` - the prefilled ABHA is not falsely marked verified, so D.14 preserves existing verification state |
| 117 | No PHP error output on the rendered page | no `Fatal error`, no `Warning:`, no `Notice:`; starts with `<!DOCTYPE`; `id="abha_id"` appears exactly once |
| 118 | 12-endpoint M1 security guard matrix | **12/12** pass 401/403/403/403/200 (60 assertions, empty params, no gateway call) |
| 119 | `.htaccess` protected URLs | **5/5** return 403 |

#### FAILED

None. No test in this phase failed.

#### NOT VERIFIED - do not treat as done

- **End-to-end registration with a local ABHA match is still NOT VERIFIED at the browser level.** The prefilled field, the endpoint response and the JSON contract are proven, but nobody has yet clicked the verify tick, watched `fillABHAProfile()` populate the form, and saved a revisit in a real browser. **No patient was saved or created during this phase by design.**
- ***View ABHA Card* still shows "ABHA profile is not available" until the operator clicks the verify tick.** Accepted per explicit decision. `registrationABHAProfile` is still only assigned inside interactive flows.
- **No gateway call was made**, so Aadhaar OTP, mobile OTP and the ABHA fetch-OTP flows are untouched and unverified here - they were not modified and remain covered only by the operator's earlier manual pass (§E.3).
- **Save-time behaviour with a prefilled ABHA is untested at the database level.** It is safe by construction - `abha_verified` stays `0`, so the D.14 logic performs **0 writes** and preserves the existing link - but that reasoning is from the stub-connection test (test 100), not from a real revisit save. A real save would require writing patient data, which was prohibited.
- **Prefill does not add `abha_id` to the other two prefill endpoints.** `get_patient_details.php` and `check_patient_by_aadhaar.php` still omit it, so the emergency/doctor-fee path and the Aadhaar-blur path still cannot supply the ABHA. Left untouched by explicit decision.
- **`modules/appointments/ajax/get_patient_details.php` remains unauthenticated** - see §I.16, OPEN and unchanged.
- **Global ABDM ON/OFF browser acceptance remains PENDING** - unchanged, and deliberately not touched by this fix.

---

### G.11 Defensive ABHA JS guard verification (2026-10-14)

Scope was 1 file / 1 line replaced (`modules/registration/register.php`). **Read-only throughout: no patient saved or updated, no patient created, no database write, no ABDM gateway call.**

#### PASSED

| # | Check | Result |
|---|---|---|
| 120 | PHP lint - working copy and runtime | both clean |
| 121 | SHA-256 working copy vs runtime | **MATCH** - `B50128CDA8D41DDC52B048ADFCB525D9901D10DC28B6402C2675BF11BBBEFFA2` |
| 122 | Offset-aware diff vs backup | exactly **1 line changed**; +5 lines total; nothing else altered |
| 123 | Blast radius | **exactly 1** runtime file modified; the 4 previously modified M1 files all byte-identical to their working copies |
| 124 | **Constraint: `get_patient_details.php` untouched** | hash `CF657375...6B524` unchanged and still omits `abha_id` from its SELECT |
| 125 | Revisit flow renders the ABHA | **POPULATED** (17 chars, value never printed or logged) |
| 126 | No regression on other revisit fields | `first_name`, `mobile`, `dob`, `pincode` all **POPULATED** |
| 127 | Fresh registration with no ABHA | `abha_id` renders **EMPTY**, as required |
| 128 | Guard present in the **served** page | new `if (p.abha_id)` block served; old unconditional write **absent** |
| 129 | No PHP error leakage | no `Fatal error`, no `Warning:`, no `Notice:` |
| 130 | Root cause proven at runtime | `get_patient_details.php?id=5&tenant_id=HMS1` returns 14 keys and **no `abha_id`**, which is exactly what made the old line write `''` |
| 131 | Guard logic tested against the **deployed** code | guard **extracted from the live served page** and executed in Node with a mocked DOM - **6/6 assertions pass** (see below) |
| 132 | 12-endpoint M1 security guard matrix | **12/12** pass 401/403/403/403/200 |
| 133 | `.htaccess` protected URLs | **5/5** return 403 |

Test 131 assertions:

| Assertion | Result |
|---|---|
| Old unconditional assignment blanked a server-rendered ABHA (regression reproduced) | PASS |
| Payload **without** `abha_id` -> server-rendered value **preserved** | PASS |
| Payload **with** `abha_id` -> value applied | PASS |
| Payload without `abha_id`, empty preset -> stays empty | PASS |
| `abha_id` = empty string -> preset preserved (falsy, no overwrite) | PASS |
| `abha_id` = null -> preset preserved | PASS |

#### FAILED

None. No test in this phase failed.

#### NOT VERIFIED - do not treat as done

- **The Emergency/Revisit interaction was NOT exercised in a real browser.** Test 131 runs the *deployed* guard logic in Node against a mocked DOM, which is strong evidence for the logic, but no human ticked Emergency or changed the Doctor dropdown on a live revisit form. That remains the outstanding check.
- **The original screenshots are still unattributed.** The operator could not confirm whether they came from the 11:47:53 load (pre-D.16) or the 11:56-11:57 loads (post-D.16). D.17 is therefore a defence-in-depth fix against the one *proven* code path, **not** a demonstrated fix for those screenshots. If the empty field persists after a hard refresh, re-run the View Source check described in D.17.
- **The in-page Revisit radio picker still cannot populate the ABHA.** It never sends `revisit_patient_id` and loads every field from `get_patient_details.php`, which omits `abha_id`. Deliberately not fixed - see D.17 and I.16.
- **`get_patient_details.php` remains unauthenticated** - I.16, OPEN and unchanged.
- **Save-time behaviour with a prefilled ABHA remains untested against a real database.** Safe by construction (`abha_verified` stays `0`, so D.14 performs 0 writes and preserves the existing link), but a real revisit save would require writing patient data, which was prohibited.
- **Global ABDM ON/OFF browser acceptance remains PENDING** - unchanged and untouched.

---

### G.12 I.16 authentication / authorisation verification (2026-10-14)

Scope was 1 file (`modules/appointments/ajax/get_patient_details.php`). **Read-only throughout: no patient created, saved or updated, no database write, no ABDM gateway call, no migration, no session cleanup.**

#### PASSED

| # | Check | Result |
|---|---|---|
| 134 | PHP lint - working copy and runtime | both clean |
| 135 | SHA-256 working copy vs runtime | **MATCH** - `AC7F1060619F6C66B31D666138A4DCCBC4867B56253CD8F5CBF90554F0CC7D66` |
| 136 | Blast radius | **exactly 1** runtime file modified |
| 137 | `public_tenant.php` require removed | **0 occurrences** of the require, and 0 non-comment references to the file |
| 138 | SELECT block unchanged | **14 columns, identical order**: `title, first_name, last_name, marital_status, sex, guardian_name, aadhaar, mobile, phone, email, dob, city_id, pincode, address` |
| 139 | Phase 3 constraint: no `abha_id` added | **absent** from both SELECT and response |
| 140 | Success payload unchanged | bare `json_encode($row ?: [])` preserved; response has **no** `status`/`success` envelope key |
| 141 | HTTP method preserved | still GET, `$id` still read from `$_GET` |
| 142 | **Unauthenticated denied** | **HTTP 401**, `{"success":false,"message":"Authentication required."}` |
| 143 | **Authenticated without permission denied** | **HTTP 403**, `{"success":false,"message":"Access denied."}` |
| 144 | **Authorised response shape** | **200**, valid JSON, **14 keys**, bare object - identical to the pre-change contract |
| 145 | **Caller-supplied tenant cannot override** | `tenant_id=HMS1`, `HMS999`, `HMS0000` all returned **200 identically**. Pre-change, `HMS999` would have been written into the session and failed tenant lookup (`Invalid Tenant`), so a non-200 here would have proved the override was still honoured |
| 146 | Caller `appointment_form_internal.php:2897` still satisfied | consumes 14 fields, **0 missing** from the response |
| 147 | Caller `register.php:2810` still satisfied | consumes `abha_id` in addition to the 14; `abha_id` is absent **by design** and is behind the D.17 guard, so it degrades safely and breaks nothing |
| 148 | **Phase 1 Aadhaar prefill intact** | `register.php` still `96982708...93A232`; prefill line present; live revisit render still shows **aadhaar POPULATED, abha_id POPULATED, first_name POPULATED, mobile POPULATED** |
| 149 | 12-endpoint M1 guard matrix | **12/12** pass 401/403/403/403/200 |
| 150 | `.htaccess` protected URLs | **5/5** return 403 |
| 151 | Other secured registration ajax endpoints unaffected | `get_abha_details` 401/403, `get_patient_full_details` 401/403 |

#### FAILED

None. No test in this phase failed.

#### NOT VERIFIED - do not treat as done

- **Non-super-admin roles were NOT tested.** The permission deny branch was exercised with a session that has `rights_type_id` **absent**, which makes `checkPermission()` return false before any database lookup. That proves the deny branch but **not** the "role exists and lacks `patient_registration:view`" branch, which needs a real role row. The control database is unreachable from CLI, so no live role matrix could be read. **A non-super-admin role that genuinely holds `patient_registration:view` should be tested in a browser before this is considered fully verified.**
- **No real browser pass on either caller.** The in-page Revisit picker and the internal appointment form were not exercised in a browser; compatibility was proven by comparing each caller's consumed fields against the live response keys.
- **`appointment_form_internal.php` has no page-level permission gate** (27 of 30 files in `modules/appointments/` have none). Requiring `patient_registration:view` at this endpoint is therefore **stricter than the page that hosts it**. If appointment staff hold that permission - as the existing `check_patient_by_aadhaar.php` precedent implies - this is fine; if some appointment role does not, **that role loses the patient picker**. This is the one material regression risk in this phase and it needs a role-level browser check.
- **`includes/public_tenant.php` is still caller-controllable for its other 23 callers.** Only this endpoint was fixed. The wider Phase 0 finding H6 remains open.
- **Phase 3 is not done.** The in-page Revisit picker still cannot populate ABHA, because `abha_id` was deliberately not added to the SELECT.
- **No CSRF is enforced here** - a deliberate, documented decision, not an oversight.
- **Global ABDM ON/OFF browser acceptance remains PENDING.**

---

### G.13 Phase 3 ABHA mapping verification (2026-10-14)

2 files changed. **Read-only testing throughout: no patient created, saved or updated, no database write, no ABDM gateway call, no migration, no session cleanup.** No patient identifiers were printed - response keys, field state and lengths only.

#### PASSED

| # | Check | Result |
|---|---|---|
| 152 | PHP lint - both files, working copy **and** runtime | all 4 clean |
| 153 | SHA-256 working copy == runtime | **MATCH** for both files |
| 154 | Endpoint SELECT | **15 columns**; previous **14 preserved, order unchanged**; `abha_id` appended last |
| 155 | Guards intact | session gate, `patient_registration:view`, `$_GET['id']`, bare `json_encode($row ?: [])` payload, no `tenant_id` read from request |
| 156 | **Authorized response includes ABHA** | **200**, **15 keys**, all previous 14 present, bare object, `abha_id` non-empty (17 chars, withheld) |
| 157 | **Unauthenticated denied** | **401**, `{"success":false,"message":"Authentication required."}` |
| 158 | **No-permission denied** | **403**, `{"success":false,"message":"Access denied."}` |
| 159 | **Tenant isolation** | `tenant_id=HMS1` / `HMS999` / `HMS0000` all **200 identically** - caller tenant ignored |
| 160 | No-match compatibility | non-existent id -> `[]`; `id=0` -> `[]` |
| 161 | Appointment-form caller compatible | `appointment_form_internal.php` consumes 14 fields, **NONE missing** (it never referenced `abha_id`) |
| 162 | Picker: ABHA exists | **populated** |
| 163 | Picker: ABHA missing | **previous value cleared** - no cross-patient leak |
| 164 | Picker: empty response `{}` / `[]` | **cleared** |
| 165 | Picker: `abha_id` null / empty string | **cleared** |
| 166 | Picker: fresh form, no ABHA | stays **empty** |
| 167 | Picker: network failure | field **NOT erased**, error reported via `console.error`, not thrown |
| 168 | Picker logic tested against **deployed** code | assignment **extracted from the live served page**, executed in Node with a mocked DOM - **7/7 assertions pass** |
| 169 | 12-endpoint M1 guard matrix | **12/12** pass 401/403/403/403/200 |
| 170 | `.htaccess` protected URLs | **5/5** return 403 |
| 171 | Other secured registration ajax endpoints | `get_abha_details` 401/403, `get_patient_full_details` 401/403 |
| 172 | Phase 1 Aadhaar prefill intact | Revisit URL: **aadhaar POPULATED** |
| 173 | D.16 ABHA prefill intact | Revisit URL: **abha_id POPULATED**, first_name / mobile / dob all POPULATED |
| 174 | Fresh registration unaffected | aadhaar **EMPTY**, abha_id **EMPTY** |
| 175 | Blast radius | **exactly 2** runtime files modified; 10 unrelated files verified byte-identical |
| 176 | No page errors on revisit render | no Fatal / Warning / Notice |

#### FAILED

None. No test in this phase failed.

#### Harness defect (not an application defect)

While running test 168, one of the two guard assertions in the Node script reported **false** for "network `.catch()` present". That was a **defective regex in my test script**, not a missing handler. Verified independently by exact substring against the served page: `.catch(err => console.error('Error loading patient details:', err));` **is present**, with a literal `=>` and no HTML entity encoding. The underlying behaviour (test 167) is confirmed.

#### NOT VERIFIED - do not treat as done

- **The picker was never exercised in a real browser.** Tests 162-167 run the **deployed** assignment in Node against a mocked DOM. That is strong evidence for the logic, but no human has clicked an existing-patient radio and watched the ABHA field change. **This is the single most important outstanding check for this phase.**
- **Cross-patient contamination was proven only at unit level** (test 163). No real switch from a revisit-rendered patient to a different patient was performed, because that would require saving a patient.
- **Non-super-admin roles remain unverified** for this endpoint, unchanged from G.12: the deny branch was proven with `rights_type_id` absent, not with a real role lacking `patient_registration:view`. `appointment_form_internal.php` has no page-level gate, so an appointment role without that grant would lose the picker.
- **The `patient_master.abha_id` / `abdm_patient_links.abha_number` divergence is documented but not fixed** - see the follow-up tasks.
- **`update_patient.php` has no `abdm_patient_links` ownership precheck** - a deliberate non-change in this phase.
- **No save was performed**, so nothing here proves what the picker does end-to-end at the database level.
- **Global ABDM ON/OFF browser acceptance remains PENDING.**

### G.14 M2 credential resolution, P0 corrections and payload privacy (2026-10-14)

Read this section together with G.15. **All evidence below is source-level, lint-level or local read-only HTTP. None of it is sandbox or end-to-end evidence, and no care-context link has ever been completed in this project.**

#### D.20 credential resolution

| Check | Result |
|---|---|
| PHP lint, working copy + runtime, 5 files | `10/10` |
| SHA-256 working copy vs runtime | `5/5 PARITY` |
| Resolver behaviour, 3 modes (production passthrough / local substitution / no leaked default) | `3/3` |
| Connection-source audit: raw `['database_user'|'database_password']` in any `new mysqli(`, password literals, orphan `$controlPassword`/`$controlHost`/`$databaseHost` | `0` in all 5 files |
| Safe HTTP guard tests (`on_carecontext` 401 missing + mismatched; `discover` 403 missing + mismatched) | `4/4` |
| Read-only harness: control connect, `projects` lookup, tenant connect | all `OK` |

Tests 2 and 4 above are the decisive ones: both read the configured HIP ID **from the database** before rejecting, so the control **and** tenant connections demonstrably succeed under Apache. Before D.20 they could not get past the first connection.

#### D.21 P0 security corrections

| Check | Result |
|---|---|
| PHP lint, working copy + runtime, 3 files | `6/6` |
| SHA-256 parity | `3/3` |
| Source assertions (guard ordering, fail-closed headers, token-not-persisted, ABHA anchor, corroboration, ambiguity, bypass removal, leak removal) | `16/16` |
| Identity-matching stub suite, read-only against the real database | `8/8` |
| Safe HTTP tests | `5/5` |
| Regression — `appointments/ajax/get_patient_details.php` | HTTP `200` |

The stub suite scored `4/8` when pointed at the **un-synced runtime** and `8/8` against the working copy. That differential is deliberate evidence that the tests genuinely detect the vulnerable behaviour rather than merely passing.

Guard-ordering proof over HTTP, with a synthetic link reference and no business record touched: missing `X-HIP-ID` -> `401`; wrong `X-HIP-ID` -> `401`; valid HIP, no HIU -> `401`; valid HIP + HIU -> `400 Invalid or expired link reference.` The 401s occur **before** the OTP table is touched, which is the whole point of D.21.

#### D.22 request-payload privacy

| Check | Result |
|---|---|
| PHP lint, working copy + runtime | `2/2` |
| SHA-256 parity | `PARITY` |
| Reader audit before editing | `request_payload` has **0 readers** app-wide |
| Source assertions (raw body gone, helper defined, 3 call sites, no name/gender/yearOfBirth, no patient object, no token/credential, bind types unchanged) | `8/8` |
| Sentinel leak stub — extracts the **real** function from the shipped file and evaluates it, feeding synthetic ABHA address, ABHA number, mobile, MR, name, gender, year of birth | `15/15`: all `7` sentinels absent, all `4` identifier type labels retained, `4/4` malformed-input cases still produce valid JSON |

Business-data row count for `abdm_user_linking_otps` was `9` before and `9` after the D.21 HTTP tests. One diagnostic side effect is recorded honestly: `abdm_confirm_test.log` was appended by the endpoint's own pre-existing request logging, which was verified to contain header-presence maps and byte counts only — no token and no link reference.

#### G.15 M2 read-only correctness review — limits and honest gaps

- **Every M2 correction to date is source-level evidence only.** No ABDM gateway call was ever made by this work, no OTP was ever delivered, and no care-context link has ever been completed.
- **`TEST-TRANSACTION-` behaviour is deliberately untested over HTTP.** After D.21 such a request genuinely reaches the gateway, so testing it was refused. Verified statically instead.
- **The >1-patient ambiguity refusal is source-verified but not functionally tested.** Constructing it needs two patients sharing an ABHA, which would require writing data.
- **`X-HIU-ID` is now mandatory on `confirm`** (I.19). The 401 is proven; whether the real gateway sends the header is unverified.
- **`modules/abdm/test/m2_index.php` must not be executed** — see I.14. The read-only harnesses used here are separate files that only issue `SELECT`.
- **A live ABDM token fetch is recorded in `modules/abdm/m2_auth_test.log` at 13:59:35 on 2026-10-14** (`HTTP CODE: 200 / TOKEN RECEIVED: YES / TOKEN TYPE: bearer / EXPIRES IN: 1200`). It predates the D.21 edit window and was **not** produced by any command run in this work. Practically it suggests sandbox credentials currently function, which updates D-02 from "unavailable" to "appears available, provenance unconfirmed" — **confirm who triggered it before relying on it.**
- **M2 remains functionally unproven.** See J and K.

---

### G.16 Phase 2 verification pass (2026-10-14, late session)

**No application file, schema, permission or configuration was changed.** This was a verification-only pass. Every result below was produced by actually executing the test.

#### Environment and method notes (read before the numbers)

- **Browser evidence is real.** Headless **Chrome** (`--headless=new`) was driven over the **DevTools Protocol** from Node 22 using its built-in `WebSocket`/`fetch` — **no Playwright or Puppeteer**. The pages were loaded from `https://websplend-hms.local`, authenticated with a locally-created Super Admin test session, and asserted through `document.offsetParent` / `getClientRects()` — i.e. genuinely *rendered* visibility, not CSS-string presence. No form was ever submitted; the driver asserts the URL never changes.
- **The ABHA persistence tests are database-backed, not stub-backed.** A throwaway database `websplend_phase2_scratch` was created, populated with synthetic rows, and **dropped** — the `abdm_flag_scratch` precedent from `test_abdm_helper_states.php`. Its schema was cloned from the real database via `SHOW CREATE TABLE`, so column fidelity is exact. **The real HMS database was never written**; proven by a byte-identical MD5 of `patient_master.abha_id` and of `abdm_patient_links` before and after (tests 243â€“246).
- **The real, unmodified `update_patient.php` was executed end to end** (whole file, SHA-256 byte-identical to the working copy) in a sandbox whose `includes/db.php` supplies the scratch connection, with `permissions.php` also byte-identical to the real one. The ABDM link block of `save_patient_registration.php` was extracted by marker (140 lines, sha256 `807b5ae1def6e4a1â€¦`) and executed against the scratch database; the whole file cannot run in isolation because it pulls in the mail stack and would attempt to send mail.
- **No ABDM gateway call was made.** Every refusal test stops before any outbound request, and the ABDM log/token-cache mtimes were fingerprinted before and after as independent proof (tests 167, 210, 253).

#### PASSED

##### Regression re-run (existing harnesses, unchanged)

| # | Check | Class | Result |
|---|---|---|---|
| 161 | `php -l`, 3 target files, **both trees** | Static | **6/6 clean** |
| 162 | SHA-256 working copy vs runtime, 3 target files | Static | **3/3 MATCH** (`5A4C90E1â€¦`, `810842ADâ€¦`, `F62B3CDEâ€¦`) |
| 163 | Full-tree hash sweep (1,296 files) | Static | **1,295 identical, 0 differing**, 1 working-copy-only (this document, deliberately not deployed) |
| 164 | `register.php` hash equals the Â§D.19 recorded value; all 3 files' mtimes predate this session | Static | **Confirmed â€” no source file was touched** |
| 165 | `test_guard.php` â€” ABHA guard, real extracted code | Stub (stub connection) | **6/6 PASS** |
| 166 | `test_wiring.php` â€” toggle/guard wiring both files | Static | **29/29 PASS** |
| 167 | `review_edit_markup.php` â€” ABHA escaping, hostile values | Static + synthetic | **13/13 PASS** |
| 168 | `test_toggle.js` â€” real extracted toggle JS, No/Yes | Synthetic-DOM | **12/12 PASS** |
| 169 | `test_ui_four_states.js` â€” four UI states | Synthetic-DOM | **36/36 PASS** |
| 170 | `test_abdm_global_wiring.php` | Static | **156/156 PASS** |
| 171 | `test_abdm_helper_states.php` â€” four helper states | Database-backed (throwaway DB) | **8/8 PASS** |
| 172 | `test_abdm_functional_db.php` â€” ON/OFF render + data integrity | Database-backed | **47/47 PASS** |

##### Global ABDM switch OFF â€” live HTTP

| # | Check | Class | Result |
|---|---|---|---|
| 173 | Switch state captured before the run, restored in a `finally` block, then **independently re-verified** | Database-backed | **Restored to ON, confirmed `on`** |
| 174 | **12/12** `abha_*.php` with **no CSRF** â†’ 403 carrying the **M1 guard** message | Live HTTP | **12/12** â€” confirms the guard precedes the switch gate |
| 175 | **12/12** `abha_*.php` with a **valid CSRF** â†’ 403 carrying the **switch refusal** message | Live HTTP | **12/12** â€” confirms the switch gate is genuinely reached |
| 176 | `get_abha_details.php` refuses while OFF; refusal body leaks no patient data | Live HTTP | **PASS** |
| 177 | All 12 refusals fast (no gateway round-trip); no ABDM log or token-cache file written | Log mtime | **PASS** |

##### Global ABDM switch OFF â€” **real browser** (Â§K P1-2 items 2 and 4)

| # | Check | Class | Result |
|---|---|---|---|
| 178 | Registration OFF: no patient-level Yes/No toggle (0 `abdm_integration` radios) | **Real browser** | **PASS** |
| 179 | Registration OFF: no `abha_id` input, no `abdm_abha_field`, no verify button, no ABHA action buttons, no Create ABHA button, no Create ABHA modal | **Real browser** | **6/6 PASS** |
| 180 | Registration OFF: the ABDM toggle **JavaScript is not emitted** | **Real browser** | **PASS** |
| 181 | Registration OFF: **plain Aadhaar field still present, visible and usable** (value accepted and returned) | **Real browser** | **PASS** |
| 182 | Registration OFF: ordinary Mobile / First Name present; form still posts to `save_patient_registration.php` | **Real browser** | **PASS** |
| 183 | Edit Patient OFF: no toggle, no ABHA field, no ABDM toggle JS | **Real browser** | **PASS** |
| 184 | Edit Patient OFF: ordinary Title / First Name / Mobile present; form still posts to `update_patient.php` | **Real browser** | **PASS** |
| 185 | No PHP fatal/warning/notice on either OFF page | **Real browser** | **PASS** |

##### Â§E.3 toggle-state matrix â€” **real browser**

| # | Check | Class | Result |
|---|---|---|---|
| 186 | **Item 1** â€” new registration **defaults to No**; ABHA field, Aadhaar VERIFY, ABHA action buttons, Create ABHA button and the Create ABHA modal are **all hidden** | **Real browser** | **6/6 PASS** |
| 187 | **Item 2** â€” the **plain Aadhaar field stays visible** on No, and is genuinely **editable** (not disabled, not readonly, value round-trips) | **Real browser** | **2/2 PASS** |
| 188 | **Item 3** â€” switching to **Yes** reveals all four ABDM controls; text typed **before** switching **survives** the switch | **Real browser** | **6/6 PASS** |
| 189 | **Item 4** â€” a full **Yes â†’ No â†’ Yes** cycle **clears no field value** (ABHA sentinel and plain Aadhaar both intact; the field hides and re-shows) | **Real browser** | **5/5 PASS** |
| 190 | **Item 5** â€” Edit Patient for a patient **who has** an ABHA: toggle opens on **Yes**, field visible, **existing ABHA value displayed** (length asserted, value withheld) | **Real browser** | **6/6 PASS** |
| 191 | **Item 8** â€” the toggle is **not persisted**: after setting Yes and typing an ABHA, a fresh load returns to No with an empty ABHA field | **Real browser** | **2/2 PASS** |
| 192 | Read-only guard: no navigation, no form submission, form still on screen after every interaction | **Real browser** | **PASS** |

##### Authentication / authorisation / permission (Step B item 5)

| # | Check | Class | Result |
|---|---|---|---|
| 193 | Unauthenticated GET: `register.php`, `edit_patient.php?id=1`, `update_patient.php` | Live HTTP | **3/3** â†’ **302 â†’ `/access_denied.php`** |
| 194 | Unauthenticated GET `save_patient_registration.php` | Live HTTP | **400 "Invalid request method"** â€” the method check runs *before* the permission gate; still fail-closed, and already recorded at Â§G.7 test 54 |
| 195 | Unauthenticated POST: `update_patient.php`, `save_patient_registration.php` | Live HTTP | **2/2 â†’ 302 â†’ `access_denied.php`** |
| 196 | Authenticated session **without** `patient_registration` rights: `edit_patient.php` / `update_patient.php` / `save_patient_registration.php` | Live HTTP | **3/3 refused**; refusal body contains **no patient data** |
| 197 | CSRF matrix on `update_patient.php` as authorised Super Admin: **missing key**, **invalid string**, **empty value**, **array-valued `csrf[]`** | Live HTTP | **4/4 refused**, 0 bytes returned, **no PHP error and no stack trace leaked** |
| 198 | **CONTROL** â€” a *valid* CSRF is **not** refused by the CSRF gate (reaches later validation) | Live HTTP | **PASS** â€” proves 197 is testing the gate, not a blanket failure |
| 199 | Patient `abha_id` + `mobile` fingerprint byte-identical before and after the whole matrix | Database-backed | **PASS â€” nothing was written** |
| 200 | Static: `checkPermission` is required **after** `includes/db.php` in `edit_patient.php` (ordering required by Â§D.18) | Static | **PASS** |
| 201 | Static: both gates precede the first database statement in both handlers | Static | **PASS** |
| 202 | **No new security gap found.** `Â§I.17` (no `abdm_patient_links` ownership pre-check in `update_patient.php`) remains **open** and was deliberately **not** fixed | Inspection | **PASS (no change made)** |

##### ABHA persistence against a real database (Step B items 2 and 3)

| # | Check | Class | Result |
|---|---|---|---|
| 203 | Sandbox files byte-identical to the working copy (`update_patient.php`, `edit_patient.php`, `permissions.php`, `abdm_helper.php`) | Static | **4/4 identical** |
| 204 | Schema cloned from the real database via `SHOW CREATE TABLE` (DDL otherwise unchanged, `AUTO_INCREMENT=` stripped) | Static | **3/3 tables** |
| 205 | **A0/A1** â€” the real handler runs and completes its success path; the unrelated field **was written**, proving the UPDATE really executed | **Database-backed** | **PASS** |
| 206 | **A1** â€” **STEP B ITEM 2: a blank `abha_id` PRESERVES the stored ABHA** while an unrelated edit is applied | **Database-backed** | **PASS** |
| 207 | A1 â€” no `abdm_patient_links` row created or removed by an ABHA-blank edit | **Database-backed** | **PASS** |
| 208 | A2/A4 â€” a **different** non-blank ABHA *is* honoured, and an explicit value always wins (the guard is not over-broad) | **Database-backed** | **2/2 PASS** |
| 209 | A3 â€” blank `abha_id` on a patient with **no** stored ABHA stays blank; **no value is fabricated** | **Database-backed** | **2/2 PASS** |
| 210 | A6/A7/A8/A9 â€” authorisation and CSRF are enforced **before any statement**: session without `rights_type_id`; **invalid** token; **absent** `csrf` key; **array-valued** `csrf[]`. In every case the database is **not** reached and not modified, and the array case raises **no `TypeError`** and no stack trace | **Database-backed** | **8/8 PASS** |
| 211 | **B1** â€” first link for a patient is created as `Pending` (`abha_verified=0`) | **Database-backed** | **PASS** |
| 212 | **B2** â€” a freshly verified link is stored as `Verified` with a timestamp | **Database-backed** | **PASS** |
| 213 | **B3** â€” re-submitting the **same** ABHA with `abha_verified=0` does **not** downgrade `Verified`, and `verified_at` is preserved **byte-for-byte** (proving zero writes) | **Database-backed** | **2/2 PASS** |
| 214 | **B4** â€” **STEP B ITEM 3: changing the ABHA resets the link to `Pending` and NULLs `verified_at`**, and the new number is written | **Database-backed** | **3/3 PASS** |
| 215 | **B5** â€” an ABHA already owned by **another patient** is **refused** (no link row created) and the operator is **warned** | **Database-backed** | **2/2 PASS** |
| 216 | B6 â€” a blank `abha_id` performs **no link write** at all | **Database-backed** | **2/2 PASS** |
| 217 | B7 â€” an existing `Pending` link is **promoted** to `Verified` on fresh verification | **Database-backed** | **PASS** |
| 218 | B8 â€” **no `DELETE` / `TRUNCATE` / `DROP`** in either real file (no unlink path) | Static | **2/2 PASS** |

##### Final verification

| # | Check | Class | Result |
|---|---|---|---|
| 219 | Full-tree hash sweep after the whole session | Static | **1,295 identical, 0 differing**, 1 working-copy-only (this document) |
| 220 | 12-endpoint M1 guard matrix: **401** no session / **403** no permission / **403** missing CSRF / **403** array CSRF (no `TypeError`) / **405** GET | Live HTTP | **60/60** |
| 221 | M1 refusal bodies carry no `raw_response` key and no bearer/JWT material | Live HTTP | **2/2 PASS** |
| 222 | `.htaccess` protected URLs: `abdm_callback_debug.txt`, `abdm_token_cache.json`, `includes/control_db.php`, `uploads/`, `m2_auth_test.log` | Live HTTP | **5/5 â†’ 403** |
| 223 | No ABDM log or token-cache file written during the entire session | Log mtime | **PASS** |
| 224 | Scratch database dropped and confirmed absent from `SHOW DATABASES` | Database-backed | **PASS (0 leftover)** |
| 225 | Test sessions removed **individually**, matching on the on-disk `username` field only; count reconciles exactly (111 â†’ 108, **3** removed, **108** pre-existing kept) | Local state | **PASS** |
| 226 | Final DB state vs baseline: switch **1**, patients **7**, ABHA-bearing **2**, links **2** (**both `Verified`**), care contexts **12**, `md5(abdm_patient_links)` `fefd27871c1c7a4395936bbc94ac6791`, `md5(patient_master.abha_id)` `282fcc48b305a00fc3eb6f26c69e984c` | Database-backed | **BYTE-IDENTICAL to the baseline captured before testing** |

#### Harness defects found and corrected (none was an application defect)

Recorded because **three of these produced false results before being fixed**, and the application source was confirmed correct in each case *before* the assertion was changed.

| # | Defect | Why it failed | Resolution |
|---|---|---|---|
| 227 | `test_abdm_http.ps1` (dated 2026-10-13, i.e. **before** the Â§D.13 M1 guard) asserted 12 endpoints return the *switch* refusal message while POSTing **without a CSRF token** | The M1 guard runs **before** the switch gate (`abha_request_otp.php:7` vs `:22`), so the guard refused first. All 12 still returned **HTTP 403** and the no-gateway timing assertion passed â€” the application was correct; only the message assertion was stale | New harness `test_abdm_http_v2.ps1` asserts **both**: guard precedence without a token, and the switch message **with** one |
| 228 | v2 inherited a session id that `test_abdm_http.ps1` **deletes at line 126** | The session did not exist, so `register.php` recreated the file containing only `csrf`, and every endpoint 401/403'd for the wrong reason | Each harness now **creates its own** session. This was my own sequencing error, not an application defect â€” the app correctly denied a session with no `rights_type_id` |
| 229 | First `p2_abha_persistence.php` draft: `check()` read an undefined `$nl`; a child template interpolated the sandbox path to empty; a stray no-op line broke `extract()` | Test **B6** reported `PASS` **without the block ever running** | Rewritten. **Every case now asserts the child actually executed** (distinctive `__P2_CHILD_DONE_9f3a7c__` marker emitted from a shutdown function, because the real file calls `exit` on success) |
| 230 | The same draft scored `$ran = true` when the completion marker was echoed as **literal page text** | The shutdown registration had been written *outside* `<?php`, so the marker string matched the echoed source â€” a **false pass** | Registration moved inside the PHP block and the marker made distinctive |
| 231 | Case **A7** was labelled "no CSRF" but `$base` already contained `csrf`, so it silently sent a **valid** token and legitimately wrote | A false **pass** on "never reaches the database", caught only by the second assertion on the stored value | Split into A7 (**invalid** token), A8 (**absent** key) and A9 (**array-valued**) â€” all three now genuinely test the guard |
| 232 | `phase2_authz_http.ps1`: `Get-Content -Raw` on a **0-byte** response returns `$null`, and PowerShell evaluates `$null -notmatch 'x'` as falsy | 6 spurious failures on correctly-empty refusal bodies | All body reads normalised through a `Get-Body` helper |
| 233 | `browser_phase2.js` selected the ABHA input by `id="abha_id"` | `edit_patient.php`'s input has `name="abha_id"` and **no `id` attribute**, so the lookup returned `null` and reported the ABHA as absent | Corrected to a `[name="abha_id"]` selector |
| 234 | `$PID` used as a PowerShell variable (read-only) | The same collision already recorded at Â§G.8 | Renamed |

#### NOT VERIFIED â€” do not treat as done

- **Â§E.3 item 6 remains OPEN.** *Edit a patient with the toggle on **No**, change an unrelated field, submit, reopen, confirm the ABHA ID survives.* This is the one original item that requires **submitting a form against real patient data**, which this task was forbidden to do. **It is NOT claimed.** Its *behavioural equivalent* **is** verified: test 206 proves at the database level, with the **real** `update_patient.php`, that a blank `abha_id` submission preserves the stored ABHA while applying the unrelated edit. What remains unproven is only the browser round-trip on a **live** record.
- **Â§E.3 item 7 remains PARTIAL.** An ordinary registration end to end with the toggle on **No** was still not performed, because saving creates a patient record. The rendering and field-availability half is fully verified (tests 178â€“182).
- **No non-super-admin role that genuinely *holds* `patient_registration` was exercised.** The deny branch was proven with `rights_type_id = 3` (a real role lacking the grant). The complementary "role exists and holds the grant" branch is unverified, unchanged from Â§G.12.
- **No save was performed against the real database**, so nothing here proves what a real registration save does end to end. All persistence evidence comes from the throwaway scratch database.
- **No ABDM gateway call was made.** Aadhaar OTP, mobile OTP, ABHA creation and ABHA fetch remain covered only by the operator's earlier manual pass (Â§E.3, Â§G.7 tests 80â€“86). They were **not** modified and are out of scope here.
- **ABHA verification remains a client-side claim.** `abdm_verified` is still a hidden form field with no server-side binding to a completed transaction (Â§I.13, Â§K P1-5 item 4). The D.14 read-decide-write logic is now proven correct at the database level, but it is still **trusting** a client-side flag.
- **`Â§I.17` remains open**: `update_patient.php` still has no `abdm_patient_links` ownership pre-check and still does not synchronise the link table on a deliberate ABHA change. Deliberately not fixed.

---

## H. Backups and Rollback

Every path below was verified by listing actual files. Each timestamped folder also has a `LATEST.txt` pointer in its parent.

| Backup root | Timestamp | Files | Corresponds to |
|---|---|---|---|
| `D:\WebSplend-HMS\rollback_pre_vhost\` | (flat) | `hosts.bak`, `httpd-vhosts.conf.bak` | Local vhost / HTTPS setup |
| `D:\WebSplend-HMS\rollback_pre_sync\20261012_214350\` | 4 | `db.php`, `tenant.php`, `tenant_db.php`, `login_process.php` | Initial runtime sync |
| `D:\WebSplend-HMS\rollback_pre_m2_credresolver_20261014_102115\` | 5 | the five `api/v3` M2 callbacks + `SHA256SUMS.before.json` | D.20 M2 credential resolution |
| `D:\WebSplend-HMS\rollback_pre_m2_p0_20261014_133540\` | 3 | `confirm/index.php`, `m2_user_linking.php`, `discover/index.php` + manifest | D.21 M2 P0 security corrections |
| `D:\WebSplend-HMS\rollback_pre_m2_discover_payload_20261014_135516\` | 1 | `discover/index.php` + manifest | D.22 request-payload privacy fix || `D:\WebSplend-HMS\rollback_pre_localdb\` | (flat) | `db.php`, `tenant.php`, `tenant_db.php`, `login_process.php` + `websplend-local-db.php.pre-localdb` | Credential resolver adoption |
| `D:\WebSplend-HMS\rollback_pre_sidebarguard\20261012_222556\` | 2 | `appointment_list.php`, `registration_list.php` | Route guards |
| `â€¦\rollback_pre_sidebarguard_runtime\20261012_222828\` | 2 | same | Runtime copy of the above |
| `D:\WebSplend-HMS\rollback_pre_logcontainment\20261012_224752\` | 2 | `.htaccess`, `data-push/index.php` | Debug-log containment |
| `â€¦\rollback_pre_logcontainment_runtime\20261012_224752\` | 2 | same | Runtime copy |
| `D:\WebSplend-HMS\rollback_pre_exfilcontain\20261012_225721\` | 2 | `.htaccess`, `on-generate-token/index.php` | Token-log sanitisation + sensitive-file block |
| `â€¦\rollback_pre_exfilcontain_runtime\20261012_225721\` | 2 | same | Runtime copy |
| `D:\WebSplend-HMS\rollback_pre_noindex\20261012_231413\` | 1 | `.htaccess` | `Options -Indexes` |
| `â€¦\rollback_pre_noindex_runtime\20261012_231413\` | 1 | same | Runtime copy |
| `D:\WebSplend-HMS\rollback_pre_hdredact\20261012_232541\` | 3 | the three health-information callbacks | Callback header redaction |
| `â€¦\rollback_pre_hdredact_runtime\20261012_232541\` | 3 | same | Runtime copy |
| `D:\WebSplend-HMS\rollback_pre_abdm_toggle\20261012_233822\` | 3 | `register.php`, `edit_patient.php`, `update_patient.php` | ABDM toggle + ABHA guard |
| `â€¦\rollback_pre_abdm_toggle_runtime\20261012_233822\` | 3 | same | Runtime copy |
| `D:\WebSplend-HMS\rollback_pre_abdm_global\20261013_130028\` | 25 | all 24 modified files + `modules/abdm/config.php` (see note) | Global ABDM ON/OFF switch |
| `â€¦\rollback_pre_abdm_global_runtime\20261013_130028\` | 24 | same, minus the working-copy-only handover document | Runtime copy |
| `D:\WebSplend-HMS\database_backups\pre_abdm_global\` | 2 dumps | `u336643111_hms` (130,467 bytes, **123/123 tables**, 12 ABDM tables) and `u336643111_control` (6,631 bytes, 3/3 tables) | Pre-DDL schema |
| `D:\WebSplend-HMS\rollback_pre_abdm_ui\20261013_133628\` | 7 | `register.php`, `edit_patient.php`, `PROJECT_STATUS_AND_HANDOVER.md`, plus the 4 files changed by the comment fix (see note) | Visible ABDM comment + layout fix |
| `â€¦\rollback_pre_abdm_ui_runtime\20261013_133628\` | 6 | same, minus the working-copy-only handover document | Runtime copy |
| `D:\WebSplend-HMS\rollback_pre_phase2sync_runtime\20261014_082751\` | 4 | the four registration files, relative paths preserved | **Registration security closure (Â§D.12)** |
| `D:\WebSplend-HMS\rollback_pre_m1_close_20261014_053637\` | 4 + manifest | `save_patient_registration.php`, `modules/abdm/test/index.php`, `ajax/get_abha_details.php`, `ajax/get_patient_full_details.php` + `SHA256SUMS.before.json` | **M1 four-file closure (Â§D.14)** - state *before* the closure, i.e. with no auth gates on the two ajax endpoints |
| `D:\WebSplend-HMS\rollback_pre_abha_remarks_fix_20261014_055224\` | 1 + manifest | `ajax/get_abha_details.php` + `SHA256SUMS.before.json` | **`remarks` fix (Â§D.15)** - state *after* the M1 gates, *before* the invalid column was removed |
| `D:\WebSplend-HMS\rollback_pre_abha_prefill_20261014_062501\` | 1 + manifest | `modules/registration/register.php` + `SHA256SUMS.before.json` | **ABHA revisit prefill (D.16)** - state immediately before the single-line change |
| `D:\WebSplend-HMS\rollback_pre_abha_jsguard_20261014_064345\` | 1 + manifest | `modules/registration/register.php` + `SHA256SUMS.before.json` | **Defensive ABHA JS guard (D.17)** - state immediately before the guarded assignment |
| `D:\WebSplend-HMS\rollback_pre_i16_auth_20261014_073105\` | 1 + manifest | `modules/appointments/ajax/get_patient_details.php` + `SHA256SUMS.before.json` | **I.16 auth hardening (D.18)** - state before session/permission/tenant hardening |
| `D:\WebSplend-HMS\rollback_pre_phase3_abha_mapping_20261014_075104\` | 2 + manifest | `modules/appointments/ajax/get_patient_details.php`, `modules/registration/register.php` + `SHA256SUMS.before.json` | **Phase 3 ABHA mapping (D.19)** - state before `abha_id` was returned and the picker was set-or-clear |
| `D:\WebSplend-HMS\rollback_pre_m1_remediation\20261014_091149\` | 14 | the 14 pre-existing changed files, relative paths preserved, plus `MANIFEST.sha256` (14 lines) | **M1 critical security remediation (Â§D.13)** |

**Note on the `config.php` backup.** `modules/abdm/config.php` was initially missed from the backup list. It was recovered from the runtime tree â€” which was still pristine because the sync had not yet run â€” and verified to contain none of the new gate markers. Both backup roots now hold the genuine pre-change copy, so the rollback path is complete. `LATEST.txt` pointers were written for both roots.

**Note on the second-pass backup.** `get_abha_details.php`, `save_prescription.php`, `abdm_settings.php` and `save_global_switch.php` were also missed from the `rollback_pre_abdm_ui` list. They were likewise recovered from the still-pristine runtime tree **before** the second sync and verified to hold the plain-`require` form. All 6 changed files are therefore covered by `rollback_pre_abdm_ui\20261013_133628\` and by `rollback_pre_abdm_global_runtime\20261013_130028\`.

### H.4 Rolling back the M1 critical security remediation (Â§D.13)

1. Restore the 14 pre-existing files from `rollback_pre_m1_remediation\20261014_091149\` into the runtime tree, preserving relative paths.
2. **Delete** `modules/abdm/abdm_m1_request_guard.php` from the runtime tree. It is the only new file and is not present in the backup.
3. **Understand what the rollback re-exposes.** Reverting restores the two CRITICAL issues: the anonymous full patient-record disclosure through `check_patient_by_aadhaar.php`, and the complete absence of authorisation on all 12 M1 endpoints. A rollback is a security regression, not a neutral undo.
4. No database change, no patient-data restore and no `.htaccess` change is involved. `modules/abdm/test/index.php` would begin working again without a CSRF token.
5. Verify restored hashes against `MANIFEST.sha256` before re-running any traffic.

### H.5 Rolling back the M1 four-file closure (Â§D.14, Â§D.15)

Two independent rollback points exist, deliberately, because the second change was made after the first was already deployed.

1. **Undo only the `remarks` fix**, keeping the security gates: restore the single file from `rollback_pre_abha_remarks_fix_20261014_055224\modules\registration\ajax\get_abha_details.php`. **Understand what this reintroduces:** the endpoint throws a fatal error on every authorized call, so the ABHA local-lookup fast-path is dead again. The 401/403 gates remain in force. The working copy must be restored too, or the next sync will reintroduce the bug.
2. **Undo the entire closure:** restore all 4 files from `rollback_pre_m1_close_20261014_053637\`, preserving relative paths, then re-sync the working copy from the same source. **Understand what this re-exposes:** `get_abha_details.php` and `get_patient_full_details.php` return unauthenticated, with no permission check, while ABDM is ON. `get_patient_full_details.php` additionally returns the full visit history. Reverting also brings back the verification-downgrade and duplicate-link defects in `save_patient_registration.php`. **This is a security regression, not a neutral undo.**
3. No database change, no migration and no patient-data restore is involved in either rollback. There is nothing to undo in the database, because nothing was written.
4. Verify restored hashes against `SHA256SUMS.before.json` in each backup root before re-running any traffic, then re-run the guard matrix (Â§G.9 tests 90) and the protected-URL check (test 91).
5. **Session files are not part of any rollback.** `sess_m1closureSA001` and `sess_m1closureNoPerm01` are test fixtures left in place deliberately; see Â§I.12 before removing anything of that kind.

### H.6 Rolling back the ABHA revisit prefill (D.16)

1. Restore `modules/registration/register.php` from `rollback_pre_abha_prefill_20261014_062501\modules\registration\register.php` into **both** the runtime tree and the working copy. Restoring only the runtime would let the next sync reintroduce the fix unexpectedly.
2. **Understand what the rollback reintroduces:** the ABHA ID input reverts to having no `value` attribute, so a revisited patient shows an empty ABHA field, the verify tick answers *"Please enter ABHA ID"*, and *View ABHA Card* answers *"ABHA profile is not available"*. This is the reported bug, not a new fault.
3. **What it does NOT reintroduce:** nothing security-related. The D.14 gates, the D.15 `remarks` fix and the §I.13 remediation are in other files and are untouched by this rollback.
4. No database change, migration or data restore is involved. Verify the restored hash equals `59C59F371415F4821F46CA45234145B02E160F89999B8752A53A4DC98362BDDB` from `SHA256SUMS.before.json`, re-run `php -l`, then re-run the guard matrix (§G.10 tests 118-119).

### H.7 Rolling back the defensive ABHA JS guard (D.17)

1. Restore `modules/registration/register.php` from `rollback_pre_abha_jsguard_20261014_064345\modules\registration\register.php` into **both** the runtime tree and the working copy. Restoring only the runtime would let the next sync reintroduce the change.
2. **Understand what the rollback reintroduces:** `register.php:2827` returns to the unconditional `= p.abha_id ?? ''`. Because `get_patient_details.php` does not return `abha_id`, that line will blank the ABHA again whenever a user ticks Emergency or Revisit, or changes the Doctor dropdown, on a revisit form. This is the D.17 defect, not a new fault.
3. **What it does NOT reintroduce:** the D.16 server-side prefill is in the same file and **is** reverted along with it, since both live in `register.php`. Expect the ABHA field to revert to the pre-D.16 behaviour for the Emergency/Revisit interaction path, while plain revisit loads are unaffected by this rollback note - re-verify with the D.16 test set (§G.10 tests 112-114) if both rollbacks are performed.
4. No database change, migration or data restore is involved. Verify the restored hash equals `573A9ADC34B2DFDB73A374BA9E038BD567CF9406D26572F22342A9977EB00098` from `SHA256SUMS.before.json`, re-run `php -l`, then re-run the guard matrix (§G.11 tests 132-133).
5. `get_patient_details.php` is not part of this rollback - it was never modified and must never be reverted as collateral. Its exposure is tracked separately in I.16.

### H.8 Rolling back the I.16 authentication hardening (D.18)

1. Restore `modules/appointments/ajax/get_patient_details.php` from `rollback_pre_i16_auth_20261014_073105\modules\appointments\ajax\get_patient_details.php` into **both** the runtime tree and the working copy.
2. **Understand what the rollback reintroduces:** the endpoint returns 14 fields of patient PII - aadhaar, mobile, phone, email, date of birth, full address - to **any unauthenticated caller**, for any sequential patient id, and re-imports `includes/public_tenant.php` so a caller-supplied `tenant_id` once again selects the tenant. **This is a Critical-severity regression, not a neutral undo.**
3. **What it does NOT change:** the SELECT and the response payload are identical before and after, so no functional behaviour depends on the hardened version beyond the three gates. Phase 1 (D.17 Aadhaar prefill), D.16 (ABHA prefill), D.17 (JS guard) and the rest of the M1 closure all live in other files and are untouched by this rollback.
4. No database change or migration is involved. Verify the restored hash equals `CF657375C3F54E708E375F9BF67E60AF5B8CA0FAA2712E3A96AD14792FA6B524` from `SHA256SUMS.before.json`, re-run `php -l`, then re-run the guard matrix (§G.12 tests 149-151).
5. **Before rolling forward again**, Phase 3 (`abha_id` in the SELECT) must **not** be applied to a rolled-back file. Restore the hardened version first, then re-apply Phase 3 on top of it.

### H.9 Rolling back Phase 3 - ABHA mapping (D.19)

1. Restore **both** files from `rollback_pre_phase3_abha_mapping_20261014_075104\`, preserving relative paths, into **both** the runtime tree and the working copy:
   - `modules/appointments/ajax/get_patient_details.php`
   - `modules/registration/register.php`
2. **Understand what the rollback changes.** Functionally it is safe and loses no security: the endpoint keeps its session gate, `patient_registration:view` check and session-only tenant (Phase 2, D.18), and simply stops returning `abha_id`. The picker reverts to the D.17 preserve-on-missing guard, so selecting a patient without an ABHA would leave the previously displayed ABHA in place - the cross-patient contamination risk this phase removed. **Do not treat this as neutral: it restores a real data-integrity defect.**
3. **What it does NOT change:** D.16 ABHA server-side prefill, the Phase 1 Aadhaar prefill, D.14 ABHA persistence fixes, D.17's other work, or any security gate - all remain, in other files or unaffected hunks. Verify after rollback that `register.php` still contains both prefill lines.
4. **Order matters.** Do not roll back `register.php` without also rolling back `get_patient_details.php`. A half-rollback leaves the picker assigning `p.abha_id ?? ''` against an endpoint that no longer returns the key, which would clear the ABHA on every picker selection.
5. No database change, migration or data restore is involved. Verify the restored hashes against `SHA256SUMS.before.json` (`AC7F1060...CC7D66` and `96982708...93A232`), re-run `php -l`, then re-run the regressions in G.13 tests 169-174.

### H.3 Rolling back the visible-comment / layout fix

1. Restore any subset of the 6 files from `rollback_pre_abdm_ui_runtime\20261013_133628\`. Restoring only `register.php` reverts the visible comments while leaving the `require_once` safety fix in place â€” that is the minimal rollback and is safe.
2. **Do not** restore `.htaccess` from any `rollback_pre_*` folder here; none of them is involved in this fix.
3. No database change, no rollback of `abdm_master_settings`, and no data restore is required â€” this fix touched rendering and include semantics only.

### H.2 Rolling back the Global ABDM switch

1. Restore the 24 modified files from `rollback_pre_abdm_global_runtime\20261013_130028\` (or from the working-copy root), and **delete** the two new files `includes/abdm_helper.php` and `modules/abdm/settings/save_global_switch.php`.
2. The table may simply be left in place â€” it is inert once the code is gone. To remove it: `DROP TABLE abdm_master_settings;` (this is the **only** destructive step and touches no ABDM data).
3. Nothing in `patient_master`, `abdm_patient_links`, `abdm_care_contexts` or any other ABDM table needs restoring: the feature never writes to them and never deletes from them.

### H.1 Rollback rules â€” read before restoring anything

- `.htaccess` has **three** backups in sequence. Restoring an older one silently discards later protections. To revert only `Options -Indexes`, restore `rollback_pre_noindex\20261012_231413\.htaccess`. To revert everything, the oldest is `rollback_pre_logcontainment\20261012_224752\.htaccess`.
- **Do not restore `rollback_pre_hdredact` or `rollback_pre_exfilcontain` over the current callback files** â€” those copies still contain the unredacted/raw logging behaviour that was deliberately removed.
- Runtime backups mirror the working-copy backups one-for-one; restore both trees together or the trees will drift.

---

## I. Security Findings and Incident Status

Severity reflects local observation. **Nothing here was confirmed against production** â€” no production system was contacted.

### I.1 Hardcoded control-database credential in `includes/db.php` â€” OPEN

- **Severity: High.**
- **Evidence:** `includes/db.php:15-16` contain hardcoded control-DB `user` and `pass` literals. Confirmed by structural scan on 2026-10-13. `includes/tenant.php` retains 4 similar literals.
- **Context:** these are the resolver's **production-passthrough defaults**. They are returned unchanged unless `HMS_ENV=local`, so they remain the live production credentials. The resolver solved *local* credential isolation; it did **not** remove production secrets from source.
- **Containment:** none at source level. The file is not directly web-exposed (`/includes/control_db.php` returns 403), and `db.php` is never requested directly.
- **Remediation:** Â§K P0-1. **Decision required â€” do not change unilaterally.**
- **Note:** a credential value was read into an assistant's context during this work (2026-10-13) while inspecting `db.php`. It was not transmitted, logged, or written to any document. Treat rotation as warranted.

### I.2 Hardcoded DB credentials in ABDM endpoints â€” OPEN

- **Severity: High.**
- **Evidence:** all three redacted callbacks **still** contain a hardcoded `new mysqli(...)` literal (verified 2026-10-13). `audit/PHASE_0_INVENTORY.md` Â§3.1 records the control-DB credential duplicated at **12 locations**, 5 of them inline in the constructor call.
- **Remediation:** Â§K P1-3.

### I.3 Raw token/body logging in `on-generate-token` â€” CONTAINED (partially)

- **Severity: High (was) â†’ Low (now).**
- **Evidence:** the `print_r(getallheaders())` + raw-body dump was replaced with a minimal JSON trace (Â§D.6).
- **Residual risk:** the file **has no `[REDACTED]` DB-header redaction** unlike the other three callbacks, and the sink file `abdm_callback_debug.txt` still exists in the document root (now HTTP-blocked, 403 verified). Consider whether the sink should be removed entirely rather than merely emptied.

### I.4 Callback authorization / replay / signature gaps â€” OPEN

- **Severity: Critical.** From `audit/PHASE_0_INVENTORY.md` Â§7.1 C7 and Â§7.4.
- **Evidence (audit, static):** no `api/v3/**/index.php` validates the `Authorization` bearer token â€” no JWT verification, no HMAC, no signature check exists anywhere in the codebase. Source validation degrades to `X-HIP-ID`/`X-HIU-ID` string equality, a public enumerable value. **Four endpoints skip the check entirely when the header is absent**, and `hiu/health-information/data-push/index.php` performs **no source validation at all** â€” any caller knowing a `transactionId` gets ECDH + AES-GCM decryption performed.
- **Additional gaps:** no idempotency/replay protection; no request/timestamp validation; no consent-scope enforcement at data-push (Â§7.3 M12); consent-artefact signature stored but never verified (Â§7.3 M5).
- **Remediation:** Â§K P1-3. This is the single largest blocker to calling anything "operational".

### I.5 Unauthenticated endpoints in the registration module â€” FIXED (2026-10-14)

- **Severity when found: Low-Medium.** Discovered during the 2026-10-13 documentation task; **not** in the Phase 0 audit. **Escalated to Critical in the M1 audit and now fixed** â€” see Â§I.13 for the escalation.
- **Original evidence:** `edit_patient.php`, `update_patient.php` and `save_patient_registration.php` had **no** `checkPermission` call and no session guard. `GET /modules/registration/edit_patient.php` returned a response **before** any authentication.

| File | Status 2026-10-14 | Guard now enforced |
|---|---|---|
| `register.php` | Fixed | `checkPermission('patient_registration','view')` + CSRF field |
| `registration_list.php` | Fixed (earlier) | session guard + 3 `checkPermission` calls |
| `edit_patient.php` | **Fixed (Â§D.12)** | `view` + CSRF |
| `update_patient.php` | **Fixed (Â§D.12)** | `update` + CSRF, both before any statement |
| `save_patient_registration.php` | **Fixed (Â§D.12)** | `create` + CSRF, both before `begin_transaction()` |

- **Verified:** 22/22 and 47/47 live-HTTP assertions including wrong-method (405), missing session (401/302), unauthorized role (403), and missing / invalid / array-valued CSRF (Â§G.7 tests 54â€“57, 74â€“75).
- **Residual:** the wider Phase 0 finding H6 â€” **~266 non-API files still have no `$_SESSION['user_id']` guard** â€” is untouched and remains open. This fix covered the registration module and the M1 ABHA endpoints only.

### I.6 Historical `abdm_m3_callbacks.headers` rows containing bearer/JWT content â€” OPEN

- **Severity: High (data at rest).**
- **Evidence:** reported as 6 historical rows containing bearer/JWT content. **Row-level inspection was deliberately not performed** during this task (no database access), so the count is **carried forward from the implementation log, `NOT VERIFIED` in this session**.
- **Containment:** the three callback handlers now redact what they write. **Existing rows are untouched.**
- **Remediation:** Â§K P0-3 â€” must go through an approved retention/incident-response process. **Do not delete or rotate from within a coding task.**

### I.7 Debug files previously accessible over local HTTP â€” CONTAINED

- **Severity: Medium.** `audit/PHASE_0_INVENTORY.md` Â§7.1 C5 found `abdm_callback_debug.txt`, `abdm_token_cache.json`, and 8 `abdm_*.log` files in the document root, several containing tokens and an ABHA identifier.
- **Containment now verified:** all return **403**. `Options -Indexes` also stops listing.
- **Limitation:** files still exist on disk. Protection is HTTP-layer only; anything with filesystem or PHP read access can still read them.
- **Production exposure is `NOT VERIFIED`.**

### I.8 PHP 8.2 / X25519 limitation â€” OPEN, environment-level

- **Severity: High (functional).**
- **Evidence (verified 2026-10-13):** `OPENSSL_KEYTYPE_X25519` is **undefined**; `openssl.conf` is **`false`**. `audit/PHASE_1A_X25519_FIDELIUS_COMPATIBILITY.md` Â§2.3 adds a trap: on PHP 8.2 `openssl_pkey_new(['x25519'=>[]])` **silently returns a 1024-bit RSA key** rather than failing.
- **Current state:** the code **fails closed** â€” two independent guards (`health_information_request.php:226` and `:255`) prevent the wrong key type from reaching the gateway or the database. **Do not "fix" the line-226 guard**; doing so would produce RSA keys labelled as X25519.
- **Also open:** missing `openssl.cnf` breaks `openssl_pkey_new()` for **every** key type app-wide. Enabling the XAMPP config as-is writes a `.rnd` file into the process CWD, which under Apache is the document root.

### I.9 Decrypted health data discarded; no data-push producer â€” OPEN

- **Severity: High.** From `audit/PHASE_0_INVENTORY.md` Â§7.2 H2/H3. `abdm_m3_health_information_requests.data_response` has **no production writer** â€” decrypted PHI is discarded. No production path calls a FHIR generator to produce an outbound bundle.
- **Remediation:** Â§K P1-2 and P2-2.

### I.10 Other carried-forward findings

`audit/PHASE_0_INVENTORY.md` remains the authoritative source. Open items not restated here include: hardcoded tenant `HMS1`/`hospital_id=3` in 4 ABDM endpoints (Â§4.2); `ABDM_M2_X_CM_ID` undefined constant killing the outbound on-confirm (C4); `abdm_patient_links.abha_address` never written by any UI (H1); OTP never delivered (H4); CSRF on 2 of ~475 files (H5); `swbilling/` fatal on 3 missing tables (Â§6.4); `login/tenant_blocked.php` guaranteed fatal (L1).

### I.11 ABDM JavaScript remains in `register.php` while the global switch is OFF â€” LOW (accepted)

When the global switch is OFF, the ABDM **JavaScript function bodies** are still emitted by `register.php`; only the controls, modals and the toggle initialiser are suppressed. They are **unreachable**: no rendered element carries an ABDM handler and no page-load handler calls one (asserted against the rendered OFF output, Â§G.2 test 26), and every endpoint they would call refuses with 403. They were left in place because the ABDM functions are interleaved with unrelated JavaScript across a ~6,100-line file, where removing them individually risks breaking unrelated behaviour. Treat as cosmetic; revisit only alongside a broader registration-page refactor.

### I.12 Test-session cleanup incident â€” 2026-10-14

- **What happened.** During post-test cleanup of an automated HTTP suite, a stray-removal filter intended to delete only the handful of session files created by that run instead deleted approximately **287 pre-existing PHP `sess_*` files** under `C:\xampp\tmp`.
- **Impact, as verified immediately afterwards:** local application health checks still succeeded (`/` â†’ 200, `/index.php` â†’ 200, guarded routes correctly refused); approved source-file hashes remained intact; **no database or patient-record change was observed**.
- **Consequence:** any user signed in to this **local** instance would be logged out and must sign in again. PHP recreates session files automatically on next login.
- **Not recoverable.** No backup of the deleted session files existed, and none was made. **The files were not restored.**
- **Root cause.** The cleanup script's "is this file mine?" test depended on a snapshot list; the comparison mis-classified pre-existing files as test-created, and the snapshot file was then deleted in the same command, so the exact cause can no longer be reconstructed.
- **Standing instruction: do not repeat this cleanup.** Any future session using `make_test_session.php` / `make_role_session.php` must delete **only** session files whose on-disk `username` field is the test value (`abdm-switch-test` or `neg-test`), never by timestamp or set-difference against a list that is then discarded.
- **Severity: Low** (transient local state only; no data, credential or code exposure). Recorded here rather than omitted.

### I.13 M1 ABHA endpoint authorisation â€” FIXED (2026-10-14), with residual items open

- **Was CRITICAL.** All 12 `modules/abdm/abha_*.php` files had **no session, permission or CSRF check**. Their only gate was the Global ABDM switch, which is a **feature** switch (Â§E.5), not access control. Proof taken before the fix: a session holding `rights_type_id = 3` â€” denied with HTTP 302 on `register.php` â€” received HTTP 200 on all 8 tested M1 endpoints. Anonymous callers were protected only *incidentally*, by `includes/db.php` returning `$conn = null` without a tenant.
- **Second CRITICAL finding, same phase:** `modules/appointments/ajax/check_patient_by_aadhaar.php` combined `includes/public_tenant.php` (which sets `$_SESSION['tenant_id']` from a caller-supplied value at `public_tenant.php:33`) with `SELECT * FROM patient_master`, returning the **entire patient row including the password hash and username** to an unauthenticated caller. Confirmed reachable anonymously.
- **Fixed** by the shared guard and by the endpoint rewrite (Â§D.13, Â§F.9). Verified across all 12 endpoints: 401 / 403 / 405 matrix, 47/47 HTTP regression, and evidence of zero gateway contact (Â§G.7 tests 72â€“77).
- **Residual, still OPEN and deliberately out of scope for that fix:**

| Item | Evidence | Why not fixed |
|---|---|---|
| `modules/appointments/ajax/get_patient_by_mobile.php` uses `SELECT * FROM patient_master` and imports `public_tenant.php` | Source inspection, re-confirmed after the M1 sync | The mobile twin of the fixed defect. **Requires its own scoped review before any change** â€” it was not in the approved file set |
| `modules/registration/ajax/get_abha_details.php` has no session or permission check | Source inspection | Not in the approved file set; returns demographics for any ABHA to any logged-in role |
| Browser console / `alert()` exposure of gateway bodies and full ABHA profiles | ~20 sites in `register.php` | Client-side only, not a server exposure. Remediation is a separate UI change |
| `abdm_verified` is an unverified client-side claim | `register.php` hidden input set by JS string assignment; no server-side binding to a completed transaction | Requires a design decision on how to bind ABDM state server-side |
| `patient_master.aadhaar` has no UNIQUE index; `patient_master.abha_id` has no index at all | Offline schema dump | Schema change â€” requires explicit approval |
| `abdm_patient_links.abha_number` is non-unique; no cross-patient duplicate-ABHA check exists | Schema + source inspection | The same ABHA can be linked to more than one patient |

### I.14 `modules/abdm/test/index.php` will be refused after the CSRF guard - FIXED by adaptation (2026-10-14)

- **Previously:** the harness made **8 calls** to the now-guarded M1 endpoints and sent **no CSRF token**, so all 8 returned HTTP 403 *"Security token missing or invalid."* Usage was confirmed before any decision, as required - the vhost log records the page loading with **HTTP 200 at 2026-10-14 10:44:38** - so retiring it was never appropriate.
- **Resolution: adapted, not deleted.** The session token is generated fill-if-empty and emitted as `window.abdmTestCsrf`; `postRequest()` attaches it centrally. **All 8 call sites are covered without editing any of them**, and there are still **zero** bare `fetch()` calls to an M1 endpoint. Fill-if-empty avoids invalidating tokens held by other open tabs in the same session.
- **Verified (Â§G.9 tests 98-99):** the page serves 200 to an authenticated superadmin, emits a 64-hex token that matches the session value, and `postRequest()` attaches it. The 8 endpoint calls were **not** executed, because that would contact the ABDM gateway.
- **Still open, unchanged:** the page gates on `checkPermission('abdm_settings','view')` while the endpoints require `patient_registration`/`create`. Only Super Admin satisfies both today. If `abdm_settings`/`view` is ever granted to a non-super role, the page will render and every call will return 403. Not changed - outside the approved scope.

### I.15 Global ABDM switch fails OPEN when its table or row is missing â€” OPEN, decision required

- **Severity: Medium.** `includes/abdm_helper.php:200-230`.
- **Evidence:** `abdmGetGlobalSwitch()` returns `enabled => true` for both `table_missing` (errno 1146) and `row_missing`. The source comments state this is intentional â€” "treat as ON so an existing installation keeps working". Every other failure mode (`no_connection`, `query_error`, `malformed` value) correctly **fails closed**, and the existing 8/8 helper-state test (Â§G.2 test 24) asserts that behaviour.
- **Why it matters:** the switch is also the **enforcement chokepoint** for outbound ABDM calls, not only a UI question. On a database where the settings table has not been created, or before the single row is written, ABDM is treated as fully enabled.
- **Explicitly deferred.** It was **not** changed in the Phase 2 or M1 work. Changing it alters default behaviour for uninstalled databases and therefore requires a decision before remediation. Tracked as Â§K.

---

### I.16 `modules/appointments/ajax/get_patient_details.php` unauthenticated - FIXED (2026-10-14), residual items open

- **Severity: High. Same defect class as Critical 1 and Critical 2 (§D.13), found during the §D.14 audit and deliberately NOT fixed - it is outside the approved four-file scope.**
- **Evidence:** the file imports `includes/public_tenant.php` and `includes/db.php` and then goes straight to the query. It has **no `$_SESSION['user_id']` check, no `checkPermission()` call and no CSRF**. `includes/db.php` only calls `session_start()`; it does not enforce authentication, and `public_tenant.php` sets `tenant_id` from a caller-supplied value (`public_tenant.php:33`). This is precisely the pattern that produced Critical 1 in `check_patient_by_aadhaar.php`.
- **Exposure:** `SELECT title, first_name, last_name, marital_status, sex, guardian_name, aadhaar, mobile, phone, email, dob, city_id, pincode, address FROM patient_master WHERE id = ? LIMIT 1` - **14 fields of patient PII, retrievable by guessing a sequential integer id.** It is called from `register.php:2808`.
- **Why it was missed twice:** Phase 3 applied the shared guard to the `modules/abdm/abha_*.php` glob; the §D.14 audit swept `modules/registration/ajax/`. This file is in **`modules/appointments/ajax/`** and matched neither. The same sweep that found it also confirmed **no other file in `modules/registration/ajax/` returns patient PII without auth** - the two found there were fixed in §D.14.
- **Status: authentication and tenant resolution FIXED in D.18.** The session check, the `patient_registration`/`view` permission and the session-only tenant are all in place and verified (G.12 tests 142-145). `abha_id` was deliberately NOT added - that is Phase 3 and needs separate approval. The wider `public_tenant.php` problem is untouched for its other 23 callers.
- **Residual / NOT VERIFIED:** non-super-admin roles were not exercised - the deny branch was proven with `rights_type_id` absent, not with a real role lacking the grant. `appointment_form_internal.php` has no page-level permission gate, so this endpoint is now stricter than its host page; an appointment role without `patient_registration:view` would lose the patient picker. Both need a role-level browser check. No CSRF is enforced here, a deliberate decision for a read-only endpoint.

---

### I.17 `update_patient.php` ABHA ownership and cross-table sync - OPEN, found 2026-10-14

- **Severity: Medium-High. Deliberately NOT fixed in Phase 3 - recorded as a separate task.**

- **Finding 1 - no ownership precheck.** `save_patient_registration.php` gained an `abdm_patient_links` ownership pre-check in the M1 closure (D.14) so an ABHA already linked to a *different* patient is refused. `modules/registration/update_patient.php` has **no equivalent check** - it writes `patient_master.abha_id` straight through at L164. A staff member can therefore use Edit to assign an ABHA number that already belongs to another patient, producing exactly the duplicate-link condition D.14 closed on the registration path.

- **Finding 2 - the two tables can diverge.** `update_patient.php` never writes `abdm_patient_links` (its only mention is the comment at L131). Its blank-preservation guard at L138-155 stops *accidental* erasure, but a **deliberate ABHA change through Edit updates `patient_master` only**, leaving `abdm_patient_links.abha_number` stale. D.19 documented this while determining the source of truth: `patient_master.abha_id` is the only correct display source precisely because the link table can lag.

- **Recommended remediation, not applied:** mirror the D.14 ownership pre-check inside `update_patient.php`, and either synchronise `abdm_patient_links` on a deliberate change or record explicitly that `patient_master.abha_id` is authoritative and the link table is gateway metadata only. The second option is smaller and matches how every display path already behaves.
- **Until then: treat both as OPEN.**

### I.18 `discover` persisted the entire inbound request body — FIXED (2026-10-14), residual OPEN

- **Severity when found: High.** Discovered by the M2 read-only correctness review, not by the earlier audit.
- **Evidence:** all three write sites in `discover` stored `json_encode($data)` into `abdm_user_linking_transactions.request_payload` (`longtext`). The body carries the patient object — ABHA address, ABHA number, mobile, MR, name, gender, year of birth — in clear text. The `confirm` equivalent (the OTP in `response_payload`) had already been fixed in D.21, but `discover` was not covered by that change.
- **Aggravating factor:** D.21 made identifier-only rejected requests take a refusal branch that writes a `Failed` row, so a mobile-only probe that previously succeeded now **persists the probed identifiers**. The security fix increased exposure of the privacy defect until this was closed.
- **Fix (D.22):** routed all three sites through `abdmDiscoverRequestSummary()`, which stores identifier **type labels** and correlation data only. Verified with a sentinel leak test against the real shipped function.
- **Residual OPEN:** see I.20.

### I.19 `confirm` now requires `X-HIU-ID` — contract change, decision required

- **Severity: Medium, but externally visible.** D.21 made `X-HIU-ID` mandatory on `confirm` whenever `abdm_m2_settings.hiu_id` is populated, which it is on this installation. Previously an absent HIU header was silently accepted, so an unverified caller could reach the OTP block.
- **Risk:** if ABDM's real on-confirm callback does not send `X-HIU-ID`, the live flow will break with `401 X-HIU-ID header is missing.` **This is unverified** — confirming it needs sandbox evidence (D-02).
- **Status:** OPEN, awaiting D-02. Not a defect; a deliberate fail-closed decision with a contract consequence.

### I.20 `response_payload` still stores patient identity and care contexts — OPEN

- **Severity: Medium.** The same table's `response_payload` stores the discovery response: patient records with `referenceNumber` (UHID), `display` (full name), and every prescription/appointment care context, plus `matchedPatientId` and the raw `onDiscover` gateway reply.
- **Why it was not changed here:** it is the functional record of what was sent to the gateway, and `confirm` reads `selectedCareContexts` and `link.referenceNumber` back out of it. Removing it would break the linking lifecycle. It is not the same defect as I.18.
- **Recommended remediation, not applied:** apply the same allow-listed approach used in D.22 to the fields the lifecycle does not actually read, and define a retention rule for the remainder. **Depends on the retention/encryption policy (D-06).**
- **Status:** OPEN.

### I.21 Status changes to existing findings

- **I.2 (hardcoded DB credentials in ABDM endpoints) — substantially narrowed.** The five M2 callbacks were fixed in D.20 and are no longer instances. `api/v3/hip/health-information/*` was **not** in that scope and was not examined; `includes/db.php` and `includes/tenant.php` still carry production literals as resolver fallbacks (P0-1). I.2 stays **OPEN** at reduced scope.
- **I.4 (callback authorisation / replay / signature) — narrowed, NOT closed.** No callback verifies a bearer token, JWT, HMAC or signature; the only source authentication remains string equality on `X-HIP-ID`, a public enumerable value. What changed is that all five callbacks now **fail closed** when that header is absent, so the "skip the check entirely when the header is absent" defect is gone. Replay protection, idempotency and signature verification remain **absent**. This is still the single largest blocker to calling anything operational.

---

## J. ABDM M1 / M2 / M3 Functional Status

**Separation of concerns:** the existence of cryptographic code, UI, or callback endpoints is **not** evidence that a flow works. Two independent sources agree on this rule (`audit/PHASE_0_INVENTORY.md` standing caveat; `audit/PHASE_1A` Â§9.3).

| Capability | Implemented in code | Verified functional | Notes |
|---|---|---|---|
| ABHA registration UI | Yes | **Partially â€” operator-confirmed** | Registration opens, ABHA + demographics auto-populate, save succeeds (Â§G.7 tests 81â€“86). The Â§E.3 toggle-state matrix is still outstanding |
| ABHA create / verify / login | Yes | **Partially â€” operator-confirmed, locally only** | The operator completed an Aadhaar OTP â†’ auto-fill â†’ save round trip on the local configuration. **Not** verified by the automated suite and **not** verified against production |
| Patient â†’ ABHA link | Yes | **No** | `save_patient_registration.php` writes `abha_number` only; `abha_address` has no UI writer (H1) |
| 16 | ABHA erasure on unrelated edit | **Fixed** (`update_patient.php`) | **Yes â€” database-backed, 2026-10-14** | Run through the **real, unmodified handler** against a throwaway scratch database: 36/36 (Â§G.16 tests 203â€“218). The earlier stub-only evidence is superseded |
| **M1 endpoint authorisation + CSRF** | **Yes** | **Yes â€” automated, 47/47** | All 12 endpoints guarded and deployed (Â§D.13, Â§G.7 tests 62, 72â€“75) |
| **Duplicate-Aadhaar endpoint** | **Yes â€” hardened** | **Yes â€” automated** | Anonymous disclosure closed; returns exactly the 13 approved fields (Â§G.7 tests 70â€“71) |
| Facility / care-context linking | Partially | **No** | **Audited 2026-10-14; P0 fixed (D.20/D.21/D.22).** Still not end-to-end: three competing lifecycles, OTP never delivered (B-08, needs D-01), no callback authentication (B-01, needs D-02). See K P1-8 | Â§L |
| Consent callbacks | Yes (skeleton) | **No** | Hardcoded requester identity (M2); artefact signature never verified (M5). |
| Health-information request callback | Yes | **No** | Decrypted data discarded (H2). |
| Data-push callback | Decrypt path yes | **Decrypt only** | Inbound crypto **proven correct** (audit 1A Â§6, byte-identical to OpenSSL). |
| FHIR generation | 3 generators + 2 validators | **No** | Never invoked on a production path (H3). |
| FHIR persistence | **Absent** | **No** | No production writer exists. |
| Encryption / decryption (inbound) | Yes | **Yes â€” locally** | ECDH + HKDF + AES-256-GCM proven byte-identical to OpenSSL ground truth. |
| Key generation (outbound) | Yes | **No** | **Blocked**: PHP 8.4+ required. |
| Authentication / signature / replay | **Absent** | **No** | No JWT verification anywhere. See Â§I.4. |

**Permission note for M1.** `patient_registration` is `modules.id = 49` and holds **exactly one** `module_permissions` grant row: `rights_type_id = 2` with `can_view = 1`, `can_create = 1`, `can_update = 1`, `can_delete = 0`. Together with the `rights_type_id = 1` super-admin bypass (M6), this means **every role that can open the registration page also holds `create`**, so the `create` requirement on the M1 endpoints could not lock out a legitimate registration operator. Verified by live HTTP behaviour as well (Â§G.7 test 55).

**Overall:** the ABDM integration is **structurally broad but functionally partial**. Schema/code alignment is excellent (12 tables, zero column drift). M1's *identity* layer now has real browser evidence plus a verified authorisation and CSRF posture; M2 and M3 remain structurally present but functionally unproven. The remaining failures are in configuration hardcoding, callback authentication, workflow completeness, and two silent data-loss paths â€” not in database design.

### J.1 M2 â€” next workstream scope (read-only audit)

M2 (HIP/HIU session, bridge, link token, care-context linking) has now had a full code review. **The read-only audit below was completed on 2026-10-14 (see K P1-7), and the P0 corrections it produced are implemented as D.20 / D.21 / D.22. M2 remains functionally unproven - no gateway call, no delivered OTP, no completed link.** The original audit scope was in Â§L. In summary, the audit must:

1. Enumerate every existing care-context and facility-linking endpoint, helper, UI caller, table and migration.
2. Trace the real flow from patient/ABHA identity through facility and care-context linking.
3. Re-read the M2 findings already recorded here and in `audit/PHASE_0_INVENTORY.md`.
4. Verify the global-switch contract: OFF must stop outbound auto-link and new ABDM actions, must **preserve** existing IDs and links, and must **not** block inbound callbacks.
5. Identify broken flows, missing validation, incorrect tenant/facility context, error-handling gaps, and idempotency/retry defects.
6. **Report evidence, not assumption,** for two specific open questions: whether `ABDM_M2_X_CM_ID` is still undefined (C4), and whether `abdm_patient_links.abha_address` is written anywhere (H1).

---

## K. Pending Work â€” Prioritized Roadmap

**Next workstream: M2 â€” see Â§K P1-7 and Â§L. Every `Â§K Px-y` reference elsewhere in this document resolves to the numbered heading below.**

### P0 â€” Security (highest priority)

#### P0-1 â€” Hardcoded control-DB credential

1. **Decide remediation for the hardcoded control-DB credential in `includes/db.php:15-16`** (and the 4 literals in `includes/tenant.php`) using the existing external-config pattern.
   *Depends on:* client approval â€” this touches production behaviour.
   *Acceptance:* production literals removed from source; resolver becomes the single source; **no credential value ever appears in source, logs, prompts, or documentation**; local development still works via `HMS_ENV=local`.
   *Status:* `PENDING`.

#### P0-2 â€” Rotate exposed gateway credentials

1. **Ask the client administrator to rotate/revoke potentially exposed gateway credentials.**
   *Depends on:* client action â€” **the agent cannot and must not do this.**
   *Acceptance:* rotation confirmed; `projects` rows updated; all 12 credential sites updated together (partial rotation silently breaks callbacks).
   *Status:* `PENDING`. Also note: a credential value was read into an assistant context on 2026-10-13 (Â§I.1) â€” include this in the rotation request.

#### P0-3 â€” Historical sensitive callback headers in the database

1. **Historical sensitive callback headers in the database.**
   *Depends on:* approved retention / incident-response process.
   *Acceptance:* an approved decision to redact-in-place or purge; execution by an authorised operator; **not** performed from a coding task.
   *Status:* `PENDING`.

#### P0-4 â€” Production access-control confirmation

1. **Confirm production access controls and sensitive-file protection.**
   *Depends on:* server access.
   *Acceptance:* production `.htaccess` (or server-level config) blocks `/includes/`, `*.log`, the debug/token-cache files, and directory listings; confirm whether the Apache 2.2 `allow from` IP list is a no-op in production.
   *Status:* `PENDING` â€” **local protection is verified; production is `NOT VERIFIED`.**

### P1-1 â€” Browser verification of the current change

**COMPLETE except item 6.** Executed 2026-10-14 in a **real headless Chrome** session (Â§G.16 tests 186ândash192).

Completed and `PASS`:
1. Â§E.3 items 1â€“4 â€” registration toggle defaults and show/hide; plain Aadhaar field visible and editable on No; value survival across toggles, including a full Yes â†’ No â†’ Yes cycle.
2. Â§E.3 item 5 â€” Edit Patient for a patient **who has** an ABHA: the toggle opens on **Yes** and the existing value is displayed.
3. Â§E.3 item 8 â€” the toggle is **not persisted**.
4. Â§E.3 item 7 â€” **partially** completed: with ABDM OFF / toggle No, the registration page renders with **no ABDM control required** and the ordinary Aadhaar field fully usable. The *save* half remains unperformed.

**Still outstanding:**
- **Â§E.3 item 6 only.** Edit a patient with the toggle on **No**, change an unrelated field, **submit**, reopen, confirm the ABHA ID survives. Blocked because it requires writing to a real patient record. Its behavioural equivalent is database-verified (Â§G.16 test 206) using the real `update_patient.php` against a throwaway database.
   *Acceptance:* a human operator repeats item 6 on a real record, or the client approves a synthetic fixture in the real database.
   *Status:* `PARTIAL` â€” 1 of 1 remaining item needs an operator.

### P1-2 â€” Operator acceptance of the Global ABDM switch

**COMPLETE for everything that can be verified without submitting a form.** See Â§G.16.

Completed and `PASS`:
1. The *Global ABDM* panel appears above the M1â€“M4 grid and the switch renders its state; the save handler was exercised over live HTTP for missing / invalid / valid CSRF, `GET` rejection and invalid value, each proven **not** to move the stored value.
2. With the switch **OFF**, Registration shows no ABDM card, no ABHA field, no verify buttons, no ABHA action buttons and no modals, and the **plain Aadhaar field is still present, visible and usable** (Â§G.16 tests 178ândash182).
3. Edit Patient with the switch **OFF** shows no ABDM controls; ordinary fields and the `update_patient.php` destination are intact; **existing ABHA IDs and links are provably untouched** (row counts and checksums identical) (Â§G.16 tests 183ândash184, 226).
4. The switch is **restorable from OFF**, and restoration was verified independently after the run.

**Still outstanding (operator-only):**
- The two steps that require **submitting a form**: (a) complete an ordinary registration end to end with the switch OFF, and (b) Edit Patient for a patient who has an ABHA, change an unrelated field, save, reopen, confirm the ABHA survives. Both write patient data.
   *Status:* `PENDING` for those two operator steps only.

### P1-3 â€” M3 callback foundation

1. Remove hardcoded credentials from the ABDM callback endpoints using the established resolver pattern.
   *Acceptance:* no inline credential literal remains in `api/v3/**`; resolver used; fail-closed behaviour preserved.
2. Define the approved inbound FHIR **persistence target** and tenant/facility mapping.
   *Depends on:* a client decision on whether data-push is a sink or a store (this determines whether Â§I.9 is a bug or by design).
   *Acceptance:* written target defined; `data_response` either persisted or the endpoint documented as an intentional sink.
3. Implement/verify authorization, signature/JWT validation, request/timestamp validation, idempotency/replay protection, and consent-scope enforcement.
   *Acceptance:* a callback with a missing/invalid bearer is rejected; a replayed callback is rejected; requests outside consent scope are rejected. This is a **substantive security build**, not a config tweak.
4. Fix trailing-slash POST redirect behaviour if still present (`.htaccess` exposes each callback under three URL prefixes; verify which prefix the gateway actually posts to).
5. Define an approved synthetic / non-production ABDM fixture so flows can be tested without a real gateway.
   *Acceptance:* a documented, credential-free fixture; **no** live ABDM call without it.
   *Status:* all `PENDING`.

### P1-4 â€” Database-backed ABHA persistence tests

**COMPLETE â€” executed 2026-10-14, 36/36 PASS (Â§G.16 tests 203ândash218).** This item was previously `PENDING` and blocked for want of an approved synthetic fixture.

The block was resolved **without** a fixture in the real database: a throwaway scratch database was created, populated with synthetic rows, exercised, and dropped. **The real HMS database was never written**, proven by a byte-identical checksum of `patient_master.abha_id` and `abdm_patient_links` before and after.

1. ~~Define an approved synthetic patient record and the exact cleanup SQL.~~ **Superseded** â€” a self-cleaning throwaway database was used instead, following the existing `abdm_flag_scratch` precedent. Cleanup is structural: the database is dropped and its absence confirmed via `SHOW DATABASES`.
2. ~~Verify the branches that remain `NOT VERIFIED`.~~ **All four now `PASS` with before/after row evidence:**
   - **blank ABHA preservation** â†’ `PASS`, run through the **real, unmodified `update_patient.php`** (test 206)
   - **a different non-blank ABHA** â†’ `PASS` (test 208)
   - **the same ABHA re-submitted with `abha_verified=0`** â†’ `PASS`, and `verified_at` preserved byte-for-byte, proving **zero writes** (test 213)
   - **duplicate ABHA linked to another patient** â†’ `PASS**: refused, no row written, operator warned (test 215)
   - plus: ABHA change resets `Verified` â†’ `Pending` with a NULL `verified_at` (test 214); blank ABHA writes no link at all (test 216); `Pending` is promoted on fresh verification (test 217).
3. Also proven: authorisation and CSRF are enforced **before any statement** in the real handler (tests 210), and neither file contains `DELETE` / `TRUNCATE` / `DROP` (test 218).

   *Status:* `COMPLETE`. **Note the method's boundary:** this proves the code against a real MySQL server with a cloned schema and synthetic rows. It is **not** evidence about a live production database, and it is **not** a browser round-trip (Â§E.3 item 6 remains open under P1-1).

### P1-5 â€” Scoped review of the remaining out-of-scope M1 items

None of these were in the approved M1 file set and none was changed.

1. `modules/appointments/ajax/get_patient_by_mobile.php` â€” still `SELECT * FROM patient_master` and still imports `public_tenant.php`. **The mobile twin of the defect fixed in Â§D.13.** Review before changing.
2. `modules/registration/ajax/get_abha_details.php` â€” no session or permission check; returns a full demographics profile for any ABHA to any logged-in role.
3. Browser console and `alert()` exposure of raw gateway bodies and full ABHA profiles (~20 sites in `register.php`). Client-side exposure only.
4. `abdm_verified` is an unverified client-side claim with no server-side binding to a completed ABDM transaction.
5. `modules/abdm/test/index.php` â€” its 8 calls will be refused after the CSRF guard (Â§I.14). Adapt or formally retire; confirm usage first; **do not delete**.
   *Status:* all `PENDING`.

### P1-6 â€” Decide the Global ABDM switch fail-open default

The switch returns `enabled => true` when its settings table or row is missing (Â§I.15). Defensible for a feature switch, questionable for an enforcement chokepoint.

*Depends on:* client decision.
*Acceptance:* a recorded decision to keep the fail-open default and document the residual, or to fail closed; if failing closed, the Â§G.2 test 24 expectations must be updated deliberately.
*Status:* `PENDING` â€” **deliberately not changed** in Phase 2 or M1.

### P1-7 - M2 read-only audit - DONE (2026-10-14)

The audit specified in section L / section J.1 was completed. It traced all five `api/v3` M2 callbacks plus the M2 helper functions and the live schema, and produced classified findings: 28 defects (B), 11 confirmed-correct items (A), 7 items blocked on runtime evidence (C) and 7 client dependencies (D). P0 was implemented as D.20 / D.21 and then reviewed; the privacy defect found by that review is D.22.
*Status:* `COMPLETE`. Do not re-run.

### P1-8 - M2 P1, not yet started (recommended next workstream)

Ordered by the audit. **None of it is started.**

1. **B-01 - callback authentication.** No bearer / JWT / HMAC / signature verification exists anywhere. Needs D-02. This is the single largest remaining blocker to calling anything operational.
2. **B-08 - OTP delivery.** The OTP is generated and stored but never delivered; delivery is `LOG_ONLY` and both real SMS integrations are commented out. **Nothing is end-to-end testable until this lands.** Needs D-01.
3. **B-04 / B-05 / B-06 - OTP lifecycle.** No consumption flag, so replay is blocked only incidentally by overwriting `expires_at = NOW()`; the `ON DUPLICATE KEY UPDATE` in `init` is dead code because the unique key is on the freshly generated `link_reference_number`; and there is no idempotency, so repeated `init` issues additional still-valid OTPs. Requires a new column - needs approval.
4. **B-12 / B-13 / B-14 - confirm state handling.** 202 is returned to ABDM before on-confirm is attempted, so a failed on-confirm leaves a permanent silent desync with no retry. Care-context writes are unchecked and the reported `linkedCareContexts` is the input count, not the number actually written.
5. **B-10 / B-11 - `on_carecontext`.** `status` is not a required field, so a callback missing it flips **all** pending care contexts to `Failed`; and the update has no `care_context_reference` predicate, so one callback marks every pending context linked, including `PRESCRIPTION-*` rows created by `save_prescription.php`. **Blocked on D-03.**
6. **B-16 / B-17 / B-18 / B-19 - token lifecycle.** `linking_token` is stored in cleartext with no expiry or invalidation; `requestId` is parsed but never persisted, so there is no idempotency; the token is not bound to a pending transaction; and `abdm_patient_links.abha_address` has **no unique index**, so the `LIMIT 1` lookups in the token and care-context callbacks are non-deterministic.
7. **B-15 - two writers, one column.** `confirm` and `save_prescription.php` both write `abdm_care_contexts.link_status` for the same `PRESCRIPTION-*` keys with no coordination.
8. **B-25** - unchecked `execute()` on all three `discover` transaction writes.
9. **B-26** - care-context references are predictable sequential integers.
10. **I.20** - `response_payload` PHI, pending D-06.
### P2-1 â€” Functional integration

1. Verify outbound X25519 key generation in a supported runtime.
   *Acceptance:* on PHP 8.4+, `openssl_pkey_new(['x25519'=>[]])` returns a key whose `get_details()['x25519']['pub_key']` is exactly 32 bytes â€” **do not accept a result without the `x25519` details section** (audit 1A Â§2.3). Also supply a valid `openssl.cnf` at OS/php.ini level (never `putenv`) and resolve the `.rnd`-into-document-root side effect.
2. Validate key agreement, HKDF, AES-GCM, FHIR generation, callbacks, persistence, and status transitions end-to-end using approved fixtures only.
   *Acceptance:* full round-trip demonstrated offline, then against an approved non-production gateway.
3. Add integration tests and operational logging that **excludes sensitive data**.
   *Acceptance:* no bearer token, ABHA, or PHI in any log; redaction unit-tested.
4. Confirm patient / facility / care-context linkage and the full consent lifecycle.
   *Status:* all `PENDING`.

### P3 â€” Known functional debt (from the Phase 0 audit, not yet scheduled)

`ABDM_M2_X_CM_ID` undefined constant (C4) Â· `abdm_patient_links.abha_address` writer (H1) Â· OTP delivery (H4) Â· CSRF coverage (H5) Â· `swbilling/` missing tables (Â§6.4) Â· `login/tenant_blocked.php` fatal (L1) Â· `software_subscriptions`/`_invoices`/`_payments` missing from the control dump.

---


## L. Exact Next Session Handover

### Read first, in this order

1. **This document**, Â§F (modified files) and Â§I (open findings).
2. `audit/PHASE_0_INVENTORY.md` â€” architecture, tenancy, ABDM traces, and the complete finding list with severity.
3. `audit/PHASE_1A_X25519_FIDELIUS_COMPATIBILITY.md` â€” the X25519 blocker and the `openssl.cnf` defect, including the trap at Â§2.3.
4. This document Â§E â€” the current ABDM toggle behaviour, so you do not accidentally revert it.
5. This document Â§D.12, Â§D.13, Â§F.9 and Â§G.7 â€” the most recent code changes, so you do not revert the authorisation/CSRF work.

### Verified paths

- Working copy: `D:\WebSplend-HMS\working_copy`
- Runtime: `C:\xampp\htdocs\websplend-hms`
- URL: `https://websplend-hms.local/`
- External local-only DB config: `C:\xampp\websplend-local\websplend-local-db.php`
- Schema dumps: `D:\WebSplend-HMS\database_backups\pre_abdm_global\`
- **Latest rollback backups:**
  - `D:\WebSplend-HMS\rollback_pre_phase2sync_runtime\20261014_082751\` â€” registration security closure (Â§D.12)
  - `D:\WebSplend-HMS\rollback_pre_m1_remediation\20261014_091149\` â€” M1 remediation, 14 files + `MANIFEST.sha256` (Â§D.13, rollback steps in Â§H.4)
  - `D:\WebSplend-HMS\rollback_pre_abdm_global\20261013_130028\` (and the matching `_runtime` tree) â€” global switch

### Next task (recommended) - M2 P1 (see K P1-8)

**The M2 read-only audit is complete (K P1-7). Do not re-run it.** Credential resolution, the P0 security corrections and the payload privacy fix are implemented, synchronised and verified at source level (D.20, D.21, D.22; G.14, G.15).

Start at **K P1-8 item 2 (B-08, OTP delivery)** if the client has chosen an SMS provider (D-01) - nothing about M2 can be tested end-to-end until an OTP can actually reach a patient. Otherwise start at **item 1 (B-01, callback authentication)**, which needs D-02 sandbox credentials.

**Do not begin M3.** X25519 outbound key generation is still blocked on PHP 8.4+ (I.8).

Standing cautions for the next session:

- M2 is **functionally unproven**. Everything verified so far is source-level, lint-level or local read-only HTTP. No gateway call, no delivered OTP, no completed link.
- `X-HIU-ID` is now mandatory on `confirm` (I.19). If a real gateway callback omits it the live flow breaks with 401. Unverified.
- `modules/abdm/test/m2_index.php` must not be executed (I.14). It calls `abdmM2GetAccessToken`, i.e. it contacts the gateway.
- A live ABDM token fetch appears in `modules/abdm/m2_auth_test.log` at 13:59:35 on 2026-10-14 that this work did not produce (G.15). Confirm its provenance before relying on sandbox access.
- The global ABDM switch fails **open** when its table or row is missing (I.15 / P1-6). Undecided.
### Explicit prohibitions

- **Do not repeat the test-session cleanup mistake (Â§I.12).** Never delete `sess_*` files by timestamp, by set-difference against a snapshot list, or by any bulk pattern under `C:\xampp\tmp`. Delete **only** files whose on-disk `username` field is the test value (`abdm-switch-test` or `neg-test`), and verify the count before and after. There is no backup of that directory.
- **Do not** modify `includes/db.php`, `includes/tenant.php`, or any credential-bearing file without explicit approval (Â§K P0-1).
- **Do not** run any ABDM gateway operation â€” no live sandbox, no production. There is no approved non-production fixture.
- **Do not** run `modules/abdm/test/m2_index.php` â€” `audit/PHASE_0_INVENTORY.md` Â§7.4 L5 records a hardcoded **production hostname** and real patient identifiers in it.
- **Do not** run any `m3_*_test.php` harness that performs real HTTP, or `m3_health_data_push_test.php`.
- **Do not** modify or delete the historical `abdm_m3_callbacks.headers` rows, and **do not** rotate credentials (Â§K P0-3).
- **Do not** restore `.htaccess` from `rollback_pre_hdredact` or `rollback_pre_exfilcontain` â€” those copies predate security fixes and would reintroduce raw token logging.
- **Do not** weaken or delete the `OPENSSL_KEYTYPE_X25519` guard at `health_information_request.php:226` (Â§I.8).
- **Do not** change authentication or tenant resolution without approval.
- **Do not** perform schema migrations without explicit approval. (The `abdm_master_settings` table was approved explicitly for this task and is recorded in Â§D.10.)
- **Do not** treat the Global ABDM switch as a security control. It is a **feature** switch. It does not authenticate, authorise or rate-limit anything, and it does not gate inbound callbacks (Â§E.5).
- **Do not** use the global switch to justify weakening, or as a substitute for, the open findings in Â§I.2â€“Â§I.6.
- **Never** write a credential value, token, ABHA number, or patient identifier into source, logs, prompts, commits, or documentation.

### Tests required before claiming completion

Any change to the three registration files must pass, at minimum:

1. `php -l` on each changed file (source **and** runtime).
2. Source vs runtime hash match for each changed file.
3. Full-tree hash sweep showing **only** intended files differ.
4. Re-run `D:\temp\opencode\test_guard.php`, `test_toggle.js`, `test_wiring.php`, `review_edit_markup.php`.
5. For any change to `update_patient.php`, a **database-backed** test confirming an existing ABHA survives an unrelated edit â€” the stub test is not a substitute.
6. Confirm `.htaccess` protections and the three callback redactions are still intact after the change.
7. For any UI change, the Â§E.3 browser checks with an authenticated session.

**Additional requirements for any change touching the Global ABDM switch:**

8. `D:\temp\opencode\test_abdm_helper_states.php` â€” all four states must still behave as documented in Â§D.10. In particular, a **missing table must never fatal a page**: PHP 8.1+ throws `mysqli_sql_exception` from `prepare()`, and the `@` operator does **not** suppress it.
9. `D:\temp\opencode\test_abdm_global_wiring.php` â€” every new ABDM entry point must be gated *before* `curl_exec` / `abdmGetAccessToken()`, and `api/v3/**` must remain untouched.
10. `D:\temp\opencode\test_abdm_functional_db.php` â€” ABHA counts and `abdm_patient_links` / `abdm_care_contexts` row counts must be identical before and after any ON/OFF cycling.
11. `D:\temp\opencode\test_abdm_http.ps1` â€” all 12 ABHA endpoints must return 403 while OFF, and `modules/abdm/m2_auth_test.log` must be unchanged afterwards.
12. Never exercise an ABHA endpoint while the switch is **ON** unless a non-production gateway fixture has been approved.

**Never claim browser verification, database integrity, production deployment, or end-to-end ABDM success without evidence.** Label unknown outcomes `NOT VERIFIED`.

---

## M. Phase 2 Verdict (2026-10-14)

**Verdict: `PHASE 2 COMPLETE`**, with two operator-only exceptions recorded rather than concealed.

### What "complete" means here

Phase 2 comprised the ABDM patient-level toggle, the ABHA preservation guard, and the global ABDM ON/OFF switch, together with the authorisation/CSRF closure recorded at §D.12. Every testable claim about that work has now been executed and passed:

- **34/34** §E.3 toggle-state assertions in a **real browser** (items 1–5, 8).
- **20/20** + **6/6** global-OFF assertions in a **real browser**.
- **10/10** live-HTTP switch-refusal assertions across all 12 M1 endpoints, proving both gate layers.
- **36/36** ABHA-persistence assertions against a **real MySQL server**, running the **real, unmodified `update_patient.php`**.
- **21/21** authentication/authorisation/CSRF assertions, **68/68** final security regression.
- Full-tree parity **1,295 identical / 0 differing**; `php -l` **6/6**; final database state **byte-identical** to the pre-test baseline.

### The two exceptions, stated plainly

| Item | Why it is not closed | Equivalent evidence that *is* verified |
|---|---|---|
| **§E.3 item 6** — Edit with the toggle on No, change an unrelated field, **submit**, reopen, confirm the ABHA survives | Requires **writing to a real patient record**, which this task was forbidden to do | The behaviour is proven at the **database level** with the **real handler**: a blank `abha_id` submission preserves the stored ABHA while applying the unrelated edit (§G.16 test 206). Only the live browser round-trip is missing |
| **§E.3 item 7 (save half)** / **§K P1-2 step 3** — complete an ordinary registration end to end with ABDM OFF | Would **create a patient record** | The rendering and field-availability half is fully verified in a real browser: with ABDM OFF no ABDM control is present or required, and the ordinary Aadhaar field is present, visible and usable (§G.16 tests 178–182) |

### Explicitly still open (unchanged, deliberately not fixed in this task)

- **§I.17** — `update_patient.php` has no `abdm_patient_links` ownership pre-check and does not synchronise the link table on a deliberate ABHA change. Data-integrity finding, recorded, deliberately deferred.
- **§I.15 / K P1-6** — the global switch still fails **open** when its table or row is missing. Undecided; not touched.
- **§I.13 / K P1-5** — `abdm_verified` remains an unverified client-side claim. The D.14 logic around it is now database-proven correct, but it still trusts the client.
- **All M2 and M3 workstreams**, and every open `§K P0` item. **Phase 3 was not started.**

### Verdict basis and its limits

- The evidence above is **local**: this XAMPP instance, this local database, a self-signed certificate. It is **not** evidence about production.
- **No ABDM gateway call was made at any point.** Proven independently by unchanged ABDM log and token-cache mtimes.
- **No real patient record was read into output or modified.** The only database writes were to the `abdm_master_settings` single row (captured, toggled, **restored to ON and re-verified**) and to a **throwaway scratch database that was dropped and confirmed absent**.
- **No application source file was modified.** This was a verification-only pass; no backup was therefore required.

---

*End of document. Last updated **2026-10-14** for the **Phase 2 verification pass** (Â§D.23, Â§G.16, Â§E.3, Â§K P1-1/P1-2/P1-4, Â§M) â€” a verification-only session in which **no application file, schema, permission or configuration was changed**. That pass executed the Phase 2 test programme end to end: a **real headless Chrome** run over the DevTools Protocol (first browser automation used in this project), live-HTTP authorisation and CSRF matrices, a **68/68** M1 guard regression, and **36/36** ABHA-persistence assertions run through the **real, unmodified `update_patient.php`** against a throwaway scratch database that was created and dropped. **No ABDM gateway call was made**, **no real patient record was modified**, the Global ABDM switch was toggled only to prove its OFF behaviour and **restored to ON and independently re-verified**, and the final database state is **byte-identical** to the pre-test baseline. On top of the registration security closure (Â§D.12), the M1 critical security remediation and sync (Â§D.13), the visible ABDM comment / layout fix (Â§D.11, Â§E.6, Â§F.8, Â§G.4-Â§G.5, Â§H.3) and the Global ABDM ON/OFF switch (Â§D.10, Â§E.4â€“E.5, Â§F.7, Â§G.2â€“G.3, Â§H.2, Â§I.11, Â§K P1). Evidence classes are stated per test throughout: browser, database-backed, live HTTP, static and synthetic are never conflated. **Â§E.3 items 6 and 7 remain open and are NOT claimed** â€” both require submitting a form against real patient data; their behavioural equivalents are verified at the database level. Four stale-harness defects were found and corrected, three of which had produced false results; all are recorded in Â§G.16, and in every case the application source was confirmed correct before the assertion was changed. One incident is recorded openly at Â§I.12. No credential value appears anywhere in it. No production ABDM verification is claimed. Phase 3 was not started.*

---

## N. Phase 3 (2026-10-14) — Stream A, Stream B, Stream C, Stream D

### N.0 What Phase 3 was, and what was actually done

Phase 3 was the security and correctness pass defined by the Phase 3 plan. The caller
audit (A0) **revised the plan's severity assessment before any code was written**, and
that revision is preserved here rather than hidden:

| Plan assumption | What the audit found |
|---|---|
| All 8 endpoints are "confirmed-critical" | **Only 2 were live data leaks.** The other 6 failed closed by an accidental fatal, though all 6 leaked the absolute server path |
| `get_patient_by_mobile.php` was "Critical" | It has **zero callers** and no `public_tenant.php`, so it never returned data |
| `check_patient_by_mobile.php` was reachable | **Zero live callers** — both references sit inside `/* … */` blocks |

Two files were **removed** (dead code) and six were **hardened**. Five M2 findings were
fixed. No database schema, permission, tenant resolution or callback contract was changed.

### N.1 Files changed (11)

**Removed (2)** — both had zero live callers, verified three ways (§G.17 test 180):

| Path | Reason |
|---|---|
| `modules/appointments/ajax/check_patient_by_mobile.php` | Zero callers. Its only two references (`register.php:3044`, `appointment_form_internal.php:3175`) are inside comment blocks, proven by a comment-depth walk. It returned a mobile-existence oracle to an unauthenticated caller that also chose the tenant. |
| `modules/appointments/ajax/get_patient_by_mobile.php` | Zero callers, no code reference anywhere. The only `SELECT * FROM patient_master` in the set, which would have exposed the password hash and username. |

**Hardened (6)** — each gained a session check, a module-specific permission, session-derived
tenant resolution, and sanitised error handling. **No response key, JSON envelope, or
column was removed**, because none needed to be:

| Path | Permission | Caller tenant source removed |
|---|---|---|
| `modules/appointments/ajax/get_patients_list.php` | `patient_registration` / `view` | `public_tenant.php` removed |
| `modules/certificates/ajax/search_opd_patients.php` | `certificates` / `view` | never used one |
| `modules/certificates/ajax/search_ipd_patients.php` | `certificates` / `view` | never used one |
| `modules/certificates/ajax/get_opd_patient.php` | `certificates` / `view` | never used one |
| `modules/certificates/ajax/get_ipd_patient.php` | `certificates` / `view` | never used one |
| `modules/pharmacy/ajax/get_sale_patients.php` | `pharmacy_sales` / `view` | never used one |

**M2 fixes (3)**:

| Path | Findings |
|---|---|
| `api/v3/hip/link/care-context/confirm/index.php` | B-12, B-13, B-14 |
| `api/v3/hip/patient/care-context/discover/index.php` | B-25 |
| `api/v3/hip/link/care-context/init/index.php` | B-05 |

### N.2 How the appointment-role blocker was resolved — from code, not assumption

The plan flagged a risk: requiring `patient_registration:view` on `get_patients_list.php`
might lock out appointment staff, because `appointment_form_internal.php` (one of its two
callers) has no page-level permission gate of its own. This was resolved by reading the
actual permission model, not by guessing:

1. `appointment_form_internal.php` is linked from exactly one place:
   `modules/appointments/appointment.php:492` and `:1116`.
2. `appointment.php:13` enforces `if (!checkPermission('appointments','create'))`.
3. Live schema: `rights_types` holds three roles — **1 Super Admin, 2 Admin, 3 Manager**.
4. Live `module_permissions` holds rows for **rt=1 (12 rows) and rt=2 (19 rows) only**.
   **rt=3 Manager holds none**, so it can reach no module through the application and the
   sidebar (30 `checkPermission` gates) renders it nothing.
5. Both `appointments` and `patient_registration` are granted to **rt=2**.

**Conclusion:** every role that can legitimately reach the Revisit picker already holds
`patient_registration:view`. The gate locks out nobody. A Manager typing the picker URL
directly was never a supported workflow — and that unguarded URL is itself a recorded
finding (§N.5).

### N.3 Stream B — the five implemented M2 fixes

| Finding | Before | After |
|---|---|---|
| **B-12** | HTTP 202 emitted and `fastcgi_finish_request()` called at line 553/565; the outbound on-confirm ran afterwards, and an `exit` swallowed its failure — a permanent silent desync | on-confirm is attempted at line **652**, before the 202 at **806** and the flush at **819**; its outcome (`attempted` / `auth_failed` / `send_failed` / `sent` / `exception`) is recorded in `abdm_user_linking_transactions.response_payload`. **The callback contract is unchanged**: ABDM still receives HTTP 202 with the same JSON keys |
| **B-13** | `execute()` called blind on every care-context write; only `prepare()` was null-checked, so a failed write was still reported as success | every write is checked, and the check is **mode-agnostic**: both the return value and a thrown `mysqli_sql_exception` are handled. This file never calls `mysqli_report()`, but **11 other application files do set `MYSQLI_REPORT_STRICT`**, under which `execute()` throws and a bare `if (!$stmt->execute())` would be dead code |
| **B-14** | `linkedCareContexts` reported `count($linkedReferences)` — the number **supplied in the request** | reports `count($persistedCareContexts)`; the transaction log also records `linkedCareContextCount` and a separate `linkedCareContextFailures` |
| **B-25** | All three `discover` transaction writes were unchecked and each was immediately answered **HTTP 200** | all three checked and, on failure, answered **HTTP 500** with a sanitized message. The three happy-path 200 responses and their messages are unchanged |
| **B-05** | `INSERT … ON DUPLICATE KEY UPDATE` that could never fire — the table's only unique key is `UNIQUE (link_reference_number)` and `init:451` generates a fresh reference per request | removed. This is also a **safety improvement**: on a reference collision the old clause would have silently overwritten *another* transaction's row, changing its `patient_id` and resetting `attempts` to 0, weakening the replay allowance. A plain INSERT makes a collision a loud, reported failure |

New behaviour added deliberately: care-context writes now run in one transaction, and a run
that persists **zero** care contexts rolls back and refuses instead of returning 202.

### N.4 Stream C — client decision package

No code was written for any of these. Each states the finding, the proposed decision, the
impact, the risk of inaction, and the exact input required.

| Ref | Finding | Proposed decision / change | Impact | Risk of inaction | Exact input required |
|---|---|---|---|---|---|
| **D-01** | The care-context OTP is generated and stored but **never delivered**. Delivery is `LOG_ONLY`; both real SMS integrations are commented out (`init/index.php:647`) | Select an SMS provider and supply sandbox credentials; implement delivery against it | `B-08` unblocks; the care-context confirm flow becomes completable by a real user | **M2 cannot be tested end-to-end at all.** Nothing about linking can ever be proven | Provider name, sandbox credentials, sender ID, and a test number that may receive an OTP |
| **D-02** | No callback verifies a bearer token, JWT, HMAC or signature anywhere. Source authentication is string equality on `X-HIP-ID`, a public value | Either supply ABDM's documented callback-authentication contract, or accept that callbacks remain unauthenticated by design | `B-01` unblocks — the single largest blocker to calling anything operational | Any caller that guesses a `transactionId` can drive inbound callbacks. **The largest open security risk in the project** | ABDM sandbox credentials and the published callback authentication specification |
| **D-03** | `on_carecontext` flips **every** pending care context for a patient from a free-text `status` string, with **no `care_context_reference` predicate** (`on_carecontext/index.php:228,265`); `status` has no required-field validation (`:27`), so a missing or misspelled value marks all of them `Failed`; it also touches `PRESCRIPTION-*` rows created by `save_prescription.php` | Define the care-context callback contract: which field identifies the context, and the permitted status vocabulary | `B-10`/`B-11` unblock | A routine callback silently marks unrelated prescriptions as failed, or as linked | The ABDM care-context callback specification and the permitted `status` values |
| **D-06** | `abdm_user_linking_transactions.response_payload` still stores patient identity and every care context (UHID, full name, prescription/appointment references) | Define a retention period and whether the column must be encrypted at rest | Closes §I.20 | Stored PHI grows indefinitely with no retention rule | The retention period and the encryption-at-rest requirement |
| **SCHEMA** | Six M2 fixes need new columns/indexes: OTP consumption flag, `init` idempotency, `linking_token` expiry, `requestId` persistence, token↔pending-transaction binding, unique index on `abha_address` | Approve or decline each | Unblocks B-04, B-06, B-16, B-17, B-18, B-19 | OTP replay stays blocked only incidentally; tokens stay in cleartext with no expiry; `LIMIT 1` lookups stay non-deterministic | Explicit approval per item — **no migration was created in this phase** |
| **P0-1** | `includes/db.php:15-16` and `includes/tenant.php` still hold production credential literals as the resolver's passthrough defaults | Remove the literals so the resolver is the single source | Closes §I.1 | Production credentials remain in source and in every backup | Approval to change credential-bearing files, which §L prohibits by default |
| **P0-2** | Gateway credentials were exposed in source and logs, and one was read into an assistant context on 2026-10-13 | Rotate and revoke at the client | Closes the rotation half of §I.1 | Exposed credentials remain valid | **Client action only.** The agent must not and did not rotate anything |
| **P0-3** | ~6 historical `abdm_m3_callbacks.headers` rows contain bearer/JWT content | Redact in place or purge under an approved retention process | Closes §I.6 | Historical secrets remain at rest | An approved decision. **Not performed from a coding task** |
| **P0-4** | Local `.htaccess` protection is verified (5/5 → 403); the production equivalent is unverified | Confirm the production server blocks `/includes/`, `*.log`, the debug/token files and directory listings; confirm whether the Apache 2.2 `allow from` list is a no-op | Closes §I.7 for production | Local protection may not exist in production | Server access |
| **P1-6** | The global ABDM switch returns **enabled** when its settings table or row is missing (§I.15) | Decide whether a missing table means ON or OFF | Closes §I.15 | On an un-provisioned database, ABDM is treated as fully enabled | A recorded decision. **Deliberately not changed in this phase** |

### N.5 Stream D — carried forward, with one new finding from this phase

| Item | Status after Phase 3 |
|---|---|
| **B-26** predictable care-context references | **Deferred by decision, with the design recorded.** `save_prescription.php:885` *constructs* `'PRESCRIPTION-' . $prescription_id`; `m2_care_context.php` only receives it. Making it unpredictable therefore requires editing `save_prescription.php`, **and** every reader must still derive the same value — the read-back at `save_prescription.php:890-913`, `confirm`'s matching, and `UNIQUE (patient_id, care_context_reference)` all depend on it. **Required compatibility tests before any change:** (1) a saved prescription must still resolve to the same reference on the next save; (2) `confirm` must still match the reference it was sent; (3) the `PRESCRIPTION-*` prefix must survive so `save_prescription.php`'s status read keeps working |
| **B-15** uncoordinated `link_status` writers | **Read-only analysis completed; not changed.** There are **three** writers, not the two recorded: `m2_care_context.php` (148/237/260/282, all scoped by reference), `confirm/index.php` (559/578, scoped by reference), and `on_carecontext/index.php` (228/265, **bulk, unscoped**). `save_prescription.php` only *reads* `link_status`; the handover's "two writers" is inaccurate. **Coordination proposal:** give `abdm_care_contexts` a single transition owner. `confirm` should be the only writer of `Linked`; `on_carecontext` should be retired or reduced to an acknowledgement that cannot mutate rows; `m2_care_context` should keep only the `Pending`→`Failed` failure path for its own outbound attempt. Every `Failed` transition needs a reason code. **Blocked on D-03** — the callback contract must be known before rows can be scoped safely |
| **I.17** `update_patient.php` | Unchanged. Still no `abdm_patient_links` ownership pre-check and no cross-table sync |
| `abdm_verified` client-side claim | Unchanged — still an unverified hidden form field |
| Gateway bodies via `alert()` | Unchanged (~20 sites in `register.php`) |
| `modules/abdm/test/index.php` CSRF | Unchanged — adapted in §I.14 |
| **NEW — host pages with no effective gate** | `Medical/add.php:8` and `Death/add.php:9` call `checkPermission('medical_certificate_add'\|'death_certificate_add','add')` **and discard the return value**, and **neither slug exists** in `modules` (the table holds `medical_certificate` / `death_certificate`). Those pages have no working permission gate. **Not changed** — out of Stream A's endpoint scope, and it changes who can open the page |
| **NEW — guard defeated by a pre-guard query** | `pharmacy/sale_bill.php:7` queries `$conn` **before** its session guard at `:13`, so an unauthenticated caller gets an uncaught fatal that discloses the absolute server path instead of the intended redirect. **Not changed** — the page is not in Stream A's approved file set, and the pattern is widespread (§L already records `display_errors=1` + `E_ALL` in 157 files) |
| **NEW — unchecked write in `on_carecontext`** | The `Failed` branch at `on_carecontext/index.php:277` calls `$stmt->execute()` unchecked, unlike its `Linked` branch which was already fixed. Recorded, **not changed** — the file is blocked on D-03 |

### N.6 Encoding check on this document (reported, not repaired)

Checked read-only before appending:

| Property | Value |
|---|---|
| BOM | **absent** (correct) |
| Control characters (excluding CR/LF) | **0** |
| U+FFFD replacement characters | **0** |
| Valid UTF-8 | **yes** |
| Pre-existing mojibake | **256** `Â§` sequences and **246** `â€"` sequences |

The mojibake is **pre-existing damage** — the document was authored with UTF-8 bytes
misinterpreted as Latin-1, and it is cosmetic and structural-neutral. It is reported here
and **deliberately not repaired**, per the Phase 3 constraint. It contradicts
`audit/PHASE_0_INVENTORY.md` §7.4 L13, which claims "No UTF-8 BOM or mojibake in any file";
that claim is accurate for application source but **not** for this document.

An earlier attempt to append to this file with PowerShell **double-encoded it** and removed
two sections. That was detected, reverted byte-for-byte, and the append was then done at
the **byte level in PHP** instead. The lesson is recorded so the next session does not
repeat it: **never use PowerShell `Get-Content` / `Set-Content` to edit a UTF-8 file in
this project** — `Get-Content` reads as Windows-1252 and silently corrupts every non-ASCII
character, and `Set-Content -NoNewline` with an array collapses the file to one line.

### N.7 Phase 3 verdict

**PHASE 3 PARTIAL — NOT COMPLETE.**

Every implemented fix passes its acceptance tests, and no acceptance criterion was failed
by a defect. The verdict is PARTIAL for two reasons, both stated plainly:

1. **The six hardened endpoints are verified against the working copy only.** They have not
   been synchronised to the runtime tree, because Phase 3 forbids syncing without explicit
   review. Live HTTP evidence against the served application therefore does not yet exist
   for the Stream A fixes — the runtime still serves the pre-fix files.
2. **Two operator/decision-dependent items remain open** and cannot be closed from here:
   §N.4 D-01/D-02/D-03 and §N.5 B-26/B-15.

What *is* complete and evidenced: the eight-endpoint audit, the two dead-endpoint
removals, the six endpoint hardenings, and the five M2 fixes.

---
