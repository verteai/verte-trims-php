# TRIMS Inspection System

A web-based Quality Control and Inspection Management System built with PHP and Microsoft SQL Server. Designed to run on XAMPP (or any PHP-enabled web server) with native MSSQL connectivity.

---

## Features

| Module | Name | Description |
|--------|------|-------------|
| 1 | **Inspection Module** | Record and submit inspection data — IO Number lookup (with fallback when only inspection rows exist), PO selection, supplier/trim lines including **GR number, vessel, voyage, container #, and HBL**, defect entry, and results (PASSED, FAILED, HOLD, REPLACEMENT) |
| 2 | **Dashboard** | Aggregated visual overview of inspection results with date, supplier, and brand filters |
| 3 | **Inspection Report** | Filterable inspection report with PDF export via TCPDF/FPDF |
| 4 | **Download Raw Data** | Bulk import / management of raw inspection data (Excel upload into `TRIMS_TBL_RAWDATA`) |
| 5 | **Performance Summary / Brand** | Performance monitoring summary broken down by brand and trim type |
| 6 | **Dropdown Menu** | Admin management of dropdown list values (categories, descriptions) |
| 7 | **Week / Month** | Calendar-based configuration for inspection weeks and months |
| 8 | **User Maintenance** | Create users (`user_management.dbo.TBL_USER_MANAGEMENT`) and assign **module access** rows in `TRIMS_TBL_USERACCESS` (see below) |
| — | **Analytics Chatbot** | In-app assistant (`chatbot.php`) — natural-language questions over `TRIMS_TBL_INSPECTION` (summaries, defect rates, top suppliers/brands/defects, pass/fail/hold/replacement, IO lookup with shipment columns aligned with the Inspection Module) |

### Module access (per user)

After login, `main.php` loads allowed modules from **`TRIMS_TBL_USERACCESS`** in the application database (same database as `config.php` / `DB_NAME`, typically `QA_FAB_INSP`). Rows are keyed by **`username`** and use **`access_code`** (integer) to match **module numbers**:

| `access_code` | Module / area |
|---------------|----------------|
| 1 | Inspection |
| 2 | Dashboard |
| 3 | Inspection Report |
| 4 | Download Raw Data |
| 5 | Performance Summary / Brand |
| 6 | Dropdown Menu |
| 7 | Week / Month |
| 8 | User Maintenance |

The sidebar and iframe embeds only expose modules the user is allowed to open. Users with **no rows** in `TRIMS_TBL_USERACCESS` see a “no module access” message until an administrator grants access (assign at least code **8** to one account before relying on restrictions, so admins can still open User Maintenance).

---

## Tech Stack

- **Backend:** PHP 5.3+ (native `mssql_*` extension)
- **Database:** Microsoft SQL Server (any edition — Express, Standard, Developer)
- **Frontend:** Vanilla HTML/CSS/JavaScript (no framework dependencies)
- **PDF Generation:** [TCPDF](https://tcpdf.org/) and [FPDF](http://www.fpdf.org/) (bundled in `Library/`)
- **Server:** XAMPP / Apache on Windows

---

## Project Structure

```
/
├── index.php           # Login page
├── main.php            # Main shell — sidebar, tabbed module iframes, `TRIMS_TBL_USERACCESS` filtering, analytics chatbot UI
├── chatbot.php         # JSON API for the analytics assistant (requires authenticated session)
├── config.php          # Database connection & helper functions
├── config - orig.php   # Original config template (for reference)
├── module1.php         # Inspection Module
├── module2.php         # Dashboard module
├── module3.php         # Inspection Report module
├── module4.php         # Raw Data Upload module
├── module5.php         # Performance Summary module
├── module6.php         # Dropdown Menu admin module
├── module7.php         # Week/Month calendar module
├── module8.php         # User Maintenance (accounts + TRIMS_TBL_USERACCESS)
├── assets/             # Static images (e.g. main workspace empty state)
├── sql/                # Optional SQL Server migration scripts (e.g. extra inspection columns)
└── Library/
    ├── tcpdf.php       # TCPDF library (PDF generation)
    ├── fpdf/           # FPDF library
    └── ...
```

---

## Requirements

- **PHP** 5.3 or higher with `mssql` extension enabled
- **Microsoft SQL Server** (any edition)
- **XAMPP** (or Apache + PHP on Windows)
- PHP `mssql` extension (built into PHP 5.3; for PHP 7+ use `sqlsrv` and update `config.php`)

---

## Installation

1. **Clone the repository** into your web root:
   ```bash
   git clone https://github.com/verteai/verte-trims-php.git
   ```
   Or copy the folder to `C:\xampp\htdocs\Trims\`.

2. **Configure the database** — open `config.php` and fill in your SQL Server details:
   ```php
   define('DB_SERVER', 'localhost\SQLEXPRESS'); // or your server name
   define('DB_NAME',   'YourDatabaseName');
   define('DB_USER',   'sa');
   define('DB_PASS',   'YourPassword');
   ```

3. **Enable the mssql extension** in `php.ini`:
   ```ini
   extension=php_mssql.dll
   ```
   Restart Apache after saving.

4. **Set up the database** — ensure the following tables exist in your SQL Server database:
   - `TRIMS_TBL_RAWDATA` — raw inspection data
   - `TRIMS_TBL_INSPECTION` — encoded inspection records (including columns used by the Inspection Module / chatbot, such as `GR_Num` and — if you run the scripts below — `Vessel`, `Voyage`, `Container_Num`, `HBL`)
   - `TRIMS_TBL_DROPDOWN` — dropdown reference values
   - `TRIMS_TBL_WEEKMONTH` — week/month calendar entries (used by the Inspection Module, Module 3, and 7)
   - `user_management.dbo.TBL_USER_MANAGEMENT` — user accounts (separate database; columns include `id`, `username`, `password`)
   - `TRIMS_TBL_USERACCESS` — per-user module rights in the application database (`id`, `username`, `access_code`, `description`); `username` matches `TBL_USER_MANAGEMENT`

5. **Optional: extend `TRIMS_TBL_INSPECTION`** — if your database predates the vessel/HBL fields, run the scripts in `sql/` (adjust schema/database names as needed):
   - `sql/TRIMS_TBL_INSPECTION_add_vessel_columns.sql`
   - `sql/TRIMS_TBL_INSPECTION_add_HBL.sql`

6. **Open in browser:**
   ```
   http://localhost/Trims/
   ```

---

## Usage

1. Log in with a valid username and password from `user_management.dbo.TBL_USER_MANAGEMENT`.
2. Use the sidebar to navigate between modules (only those allowed in `TRIMS_TBL_USERACCESS` for your user).
3. **Inspection Module (Module 1):** Enter an IO Number, select a PO, capture shipment identifiers (GR, vessel, voyage, container, HBL) where applicable, fill in inspection details, and submit.
4. **Analytics Chatbot:** Use the in-app assistant to ask about summaries, defect rates, failures, IO-specific history, recent inspections, and more (same shipment columns as the Inspection Module where lists apply).
5. **Module 4 (Download Raw Data):** Upload an Excel file to bulk-import raw data records.
6. **Module 2 (Dashboard):** Filter by date range, supplier, and brand to view inspection summaries.
7. **Module 3 (Inspection Report):** Generate and download PDF inspection reports.
8. **Modules 6, 7 & 8 (File Maintenance):** Manage dropdown values, week/month calendar entries, and **users + module access** (Module 8).

---

## Notes

- This system uses PHP's legacy `mssql_*` functions. If upgrading to **PHP 7+**, replace all `mssql_*` calls in `config.php` with Microsoft's [`sqlsrv`](https://docs.microsoft.com/en-us/sql/connect/php/sqlsrv-driver-api-reference) extension.
- The `Library/` folder contains the full TCPDF and FPDF libraries for PDF report generation.
- Ensure `config.php` defines `DB_SERVER`, `DB_NAME`, `DB_USER`, and `DB_PASS` correctly for your environment (some checkouts ship a template with `define()` lines commented — uncomment and set them before running).
