# Letter tracking tests

Command-line only. Every script exits immediately when loaded over HTTP.

| Script | Needs | Covers |
|---|---|---|
| `php tests/test-imb.php` | PHP | IMb encoder vs the four USPS-B-3200 Appendix C test vectors; input validation; the SVG redraws the exact bars at 203/300/600 dpi, and pitch, width and height stay within the USPS spec |
| `php tests/test-letters-logic.php` | PHP | MID/STID rules, IV-MTR scan normalization, opcode and mail-phase → status mapping, "likely delivered" timing (and never "delivered"), Addresses API v3 parsing (DPV Y/D/N, bad-address fallback), BCG password encryption |
| `tests/test-letters-serial.php` | PHP with pcntl, plus a **scratch** MySQL/MariaDB database | 20 processes × 50 allocations at once all get unique serials (1..1000); wrap 999999 → 1; skips serials used in the last 45 days; safe counter reset |
| `wp eval-file .../tests/e2e-letters.php` | A **throwaway** WordPress + WooCommerce site | Full lifecycle with USPS HTTP mocked and mail captured: create, reprint, void & regenerate, mark mailed + email, IV-MTR gating and test connection, scan-driven statuses, "likely delivered" email, bad address, no-scan and 14-day flags, resend, auto-mark-mailed on first scan, package tracking untouched |

```bash
FST_TEST_DB_HOST=127.0.0.1 FST_TEST_DB_PORT=3306 FST_TEST_DB_USER=u FST_TEST_DB_PASS=p FST_TEST_DB_NAME=scratch php tests/test-letters-serial.php
```

These tests can't cover the following. Do them by hand (handoff checklist 5 and 6):

- Print a label on the RX106HD at 100% / actual size and scan the barcode with a phone IMb reader app.
- Mail 2–3 real letters and watch IV-MTR scans, statuses, emails and "Likely delivered" in WooCommerce > Status > Logs (source `shiptracker-letters`).
