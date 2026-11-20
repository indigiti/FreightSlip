# LR Studio · Shriram Transport

A single-office lorry receipt generator: Excel → column mapping → validation → draft preview → issuance → individual or combined PDF. Next.js runs only at build time; Apache/PHP serves the static frontend and the same-origin API. JSON is the only persistent store. The optional FastAPI assistant suggests mappings by header similarity and does not see the data rows, use an LLM, or control issuance.

## Run locally

1. Install Docker with Compose. Extract the project and open its folder in a terminal.
2. Run `docker compose up --build`. The first build needs internet access to install dependencies.
3. Open `http://localhost:8080`. This example binds to localhost only. Download the in-app example CSV to try the flow; use a separate test instance/volume for demo receipts.

Choosing or dropping an `.xlsx` or a UTF-8 comma-delimited `.csv` automatically starts import. For Excel, the first eligible worksheet is the default; switch tabs using the worksheet selector when multiple eligible tabs exist. The app scans the first 30 rows for recognized headers. Use the header row override and Re-read workbook when needed. Formula cells use Excel's cached values rather than running formulas; recalculate and save before upload. Limit: 5 MB, 5,000 data rows and 80 columns. Select up to 100 LRs per issue or PDF operation; use smaller batches for very large receipts.

## Issue receipts

- Review the matched columns. Known headers are matched even when columns move. Ambiguous or unknown columns are left for review. The untitled column immediately after `TRANSPORT NAME` is suggested only when its nonempty values match the known Shriram Transport address pattern; verify it or give the column the explicit header `TRANSPORT_ADDRESS`. Optional `CONSIGNOR_ADDRESS`, `WEIGHT_KG`, and second reference remain blank when absent. Enter identifiers as text in Excel to preserve leading zeros and avoid Excel's precision limits.
- Confirm that `QTY` is the article count. Choose `LR_DATE`, explicitly approve `SO_DATE`, or provide an LR issue date for the batch. Select whether `FRIGHT` is base freight or final total. If zero freight is legitimate, confirm it only after checking all zero rows.
- Validate and inspect draft previews. Repeated LR numbers are grouped only when receipt-level details, freight and charges agree across rows, and line items are distinct. Freight and fees are treated as **per receipt**, never summed across duplicate LR rows. If your Excel uses per-item freight, aggregate and check it before import. Blocked rows can be corrected in the source and re-uploaded.
- Select ready LRs and issue them. Reprints use saved data, not the most recent upload. Issue operations are atomic under a filesystem lock; any conflict rejects the selection. No void/amend workflow is included. Check the preview and have the template and business rules approved before the first live issue.

## Deploy on an existing PHP host (no Node runtime)

Run `npm install && npm run build` with Node 22+ in a build environment, then publish only `out/` to the document root. Run `composer install --no-dev` in `php/` with PHP 8.2+ and the required extensions (including Zip, GD, DOM, mbstring and XML). Keep the whole `php/` directory outside the document root; add a public `api/index.php` containing `<?php require '/absolute/private/path/php/index.php';`. Set `LR_DATA_DIR` in the PHP server environment to a private writable absolute path outside the document root. Set upload and POST limits at least to 5 MB and 6 MB. For subdirectory hosting, set `NEXT_PUBLIC_BASE_PATH` (e.g. `/lr`) during the frontend build and put `api/index.php` beneath it. The optional assistant can run on a private service with `uvicorn main:app --host 127.0.0.1 --port 8000` from `ai/`; set `LR_AI_URL` in PHP or omit it to disable suggestions.

This is a local/internal MVP without built-in authentication. Protect the entire site, including `/api/`, with HTTPS and authentication or private-network access before deploying it for staff. Never expose the JSON directory or FastAPI to the public. Use one instance, a local writable filesystem, and scheduled backups of `register.json`; the JSON store is not designed for multi-server operation. Upload and review JSON files contain dispatch details; set a retention policy and periodically remove old `upload-*.json` and `review-*.json` files after confirming they are no longer needed. Keep the register and lock file.

## Checks

`python tests/smoke.py` exercises the live PHP API with a CSV and temporary JSON storage. It covers upload, column mapping, date and freight choices, zero freight, draft rendering, issued snapshots and duplicate prevention. `python tests/assistant.py` checks FastAPI suggestions (install `ai/requirements.txt` and `httpx`). Full acceptance on a target host should also cover your original XLSX, front-end build, and the individual and combined PDF output on your printer.

## A4 portrait print layout

The print form uses the geometry and fixed labels from the supplied Shriram Transport PDF. The app places **two different LR numbers per A4 sheet**, one above the other in selection order. It never prints a duplicate copy to fill space. A single receipt or an unmatched final receipt leaves the lower half blank. A continuation starts a new sheet if the top slot already has the same LR number. Receipts with more than four items get a numbered continuation; amounts appear on the final continuation. The live form fills text using the fonts available to the renderer; the sample PDF preserves the original typography. No sample consignor address, sample reference, charge or weight is copied into live data. Print at **Actual size / 100%**, not Fit to page. When printing from a browser, disable browser headers/footers and enable background graphics; the app's PDF export is preferred for printing. Verify output from the deployment host before live use.

### Dispatch dashboard

The LR Register is the landing page. Its counters come from the PHP register and the current selection, not demo data. Use **Selected** to audit the batch, then **Preview selected** to check the exact A4 pairing before exporting. Receipts are printed in selection order. The Classic A4 receipt geometry is retained; each receipt now has its own QR in the Particulars column.

### QR codes and privacy

Each receipt QR includes the recorded parties, addresses, destination, date, all line items, quantities, references and available freight values. It uses plain text, works offline, and does not include blank/unrecorded values or raw spreadsheet rows. Draft QR text is marked DRAFT. Reprints encode the issued snapshot. Anyone who can see the receipt can read an offline QR; handle printed receipts and PDFs as dispatch data.

QR PNGs are generated locally with Composer and PHP GD; no third-party QR API, Python subprocess, or shell access is needed. FastAPI remains optional. If the full payload is too dense for the print box, the app does not truncate it. For issued receipts only, set `LR_QR_DETAILS_ENDPOINT` to the exact HTTPS API endpoint (e.g. `https://your-private-host/lr/api/index.php`, no query string) **only after securing the site and API behind authentication**. The overflow QR then points there, requiring authorized access. Without that setting, an oversized receipt prints an explicit notice, and the complete issued details remain available from the register's Details link. Test a physical A4 print at 100% with your intended scanner or phone app.


## Login, sessions, and modular architecture

FreightSlip now has a first-party `/freightslip/login/` screen. It does **not** use HTTP Basic Authentication or `WWW-Authenticate` and will not show the browser credential popup. Enter the administrator username/password from your existing private `storage/auth.json`, then the PHP API creates an HttpOnly, SameSite=Lax session cookie (Secure for HTTPS hosts). The dashboard checks `/api/index.php?action=session` before fetching dispatch data. Sign out from the sidebar. Mutating requests use an in-memory CSRF token and a same-origin request header; an expired session requires signing in again. Cookies expire after 8 hours of inactivity or 24 hours total, with session IDs rotated on login. Failed logins are rate-limited using short-lived, private server-side files. Existing `password_hash` and PBKDF2 setup credentials work without migration. Previously configured usernames with punctuation continue to work; **new** wizard usernames accept 3–64 letters and digits only.

PHP modules remain separated: `http.php` (request/JSON transport), `auth.php` (authentication, sessions, CSRF), `storage.php` (private JSON persistence), `import.php` (Excel/CSV adapters), `domain.php` (validation/issuance rules), `receipt.php` and `receipt-clean.php` (A4 layout), and `qr.php` (QR content). Next.js exports only static UI and uses `lib/api.ts` for session-aware calls; the host runs no Node server. FastAPI remains optional. The session lock is released before large Excel/PDF jobs so concurrent requests do not block unnecessarily. Changes to credentials invalidate existing sessions automatically.

**For Cloudways:** keep `private_html/freightslip/storage/auth.json` in place when upgrading. Install the new slim DigiOps artifact as a normal overlay. After deployment, visit `/freightslip/login/`, not `/freightslip/api/index.php` for sign-in. Never commit private credentials or put them in `public_html`. The health endpoint is intentionally independent of the login session, but returns HTTP 503 until the runtime, credentials and storage are ready. Enable HTTPS; the app must be loaded over HTTPS outside localhost. For high availability/multiple PHP servers, JSON storage and PHP sessions would need a shared transactional store; this single-server Cloudways build does not claim horizontal scalability.

## DigiOps / Cloudways staging

The main branch workflow builds a static Next.js export for `/freightslip/` and publishes
`digiops-release` with the `DIGIOPS-RELEASE/1` manifest. Set the DigiOps paths to
`public_html/freightslip/` and `private_html/freightslip/`. PHP 8.2+ and the Composer
platform extensions must be enabled on Cloudways (including GD, ZIP, DOM/XML and mbstring).
The API and Composer dependencies live under the private path. FastAPI is optional.

**Routine updates:** `digiops-release` omits Composer dependencies when the resolved package
versions match `deploy/runtime-baseline.json`. DigiOps overlays private files, so it reuses the
matching runtime from the original full package or a versioned runtime directory. It checks
package versions and source/dist references before accepting requests. Do not delete the private
app directory when applying a slim update.

**First install or runtime repair:** In GitHub Actions → FreightSlip DigiOps release → Run workflow,
enable `bundle_runtime` on `main`. Deploy the artifact from that run. The workflow also bundles a
versioned runtime when Composer resolves versions different from the baseline. After a dependency
upgrade is verified and installed on each target, update `deploy/runtime-baseline.json` to the new
installed versions so routine packages are slim again. Keep versioned runtime directories while
any retained release still references them. No Composer install on Cloudways is needed.

**Before using the app**, place `auth.json` in `private_html/freightslip/storage/`
via SFTP (or the host file manager). The PHP user must be able to read it and write the storage
folder. Use `{"username":"operator","password_hash":"<password_hash generated locally>"}`.
Generate the hash locally using PHP `password_hash()` with `PASSWORD_DEFAULT`; do not commit
or upload a plaintext password. The register, upload, issuance, preview, PDF, and details
routes fail closed without valid configuration. Sign in via `/freightslip/login/` and then open the dashboard. Keep HTTPS enabled. The `storage/` folder is persistent across releases.

DigiOps can check `/freightslip/` for dashboard availability. Separately check
`/freightslip/api/index.php?action=health` for PHP extensions, private auth configuration,
and writable storage. This health endpoint returns only a generic status. Verify the PDF and
Excel flow with a non-sensitive test workbook before entering live dispatch data.

### Print layout

Use **Print layout → Print Clean** for a bolder LR number, product names in Particulars, and a single right-aligned freight column. **Classic** remains the default. The selection applies to draft and issued previews, individual PDFs, and batch PDFs. Both retain QR codes and pair different LR numbers on A4; an unpaired LR leaves the bottom slot blank.

### No-terminal Cloudways authentication setup

Open **`/freightslip/setup/`** over HTTPS. Enter a unique username and a strong
password (minimum 14 characters), then download `auth.json`. The browser derives a
600,000-round PBKDF2-SHA256 password hash with a random salt using Web Crypto. The
password is never sent to the FreightSlip server. Using Cloudways' authenticated
file manager (if available) or a desktop SFTP client, upload **only** the generated
file to `private_html/freightslip/storage/auth.json` (outside `public_html`). The PHP
user needs write access to `storage/`. This file is persistent across DigiOps
releases. Return to `/freightslip/api/index.php?action=health` to verify setup,
then sign in with the chosen credentials.

The app continues to support existing PHP `password_hash()` credential files.
The public setup page never writes a server-side file and never offers an
unauthenticated initialization endpoint: credentials cannot be established on
the public internet without authenticated access to private Cloudways files.
If the health endpoint still fails after installing `auth.json`, check PHP
permissions and extensions, the deployed runtime, and host error logs.
