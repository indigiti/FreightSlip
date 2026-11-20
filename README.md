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


## DigiOps / Cloudways staging

The main branch workflow builds a static Next.js export for `/freightslip/` and publishes
`digiops-release` with the `DIGIOPS-RELEASE/1` manifest. Set the DigiOps paths to
`public_html/freightslip/` and `private_html/freightslip/`. PHP 8.2+ and the Composer
platform extensions must be enabled on Cloudways (including GD, ZIP, DOM/XML and mbstring).
The bundled API and Composer dependencies live under the private path. FastAPI is optional.

**Before using the app**, place `auth.json` in `private_html/freightslip/storage/`
via SFTP (or the host file manager). The PHP user must be able to read it and write the storage
folder. Use `{"username":"operator","password_hash":"<password_hash generated locally>"}`.
Generate the hash locally using PHP `password_hash()` with `PASSWORD_DEFAULT`; do not commit
or upload a plaintext password. The register, upload, issuance, preview, PDF, and details
routes fail closed without valid configuration. Sign in via `/freightslip/api/index.php`
and then open the dashboard. Keep HTTPS enabled. The `storage/` folder is persistent across releases.

DigiOps can check `/freightslip/` for dashboard availability. Separately check
`/freightslip/api/index.php?action=health` for PHP extensions, private auth configuration,
and writable storage. This health endpoint returns only a generic status. Verify the PDF and
Excel flow with a non-sensitive test workbook before entering live dispatch data.
