# TRIMS Inspection System

A web-based Quality Control and Inspection Management System built with PHP and Microsoft SQL Server. Designed to run on XAMPP (or any PHP-enabled web server) with native MSSQL connectivity.

---

## Features

| Module | Name | Description |
|--------|------|-------------|
| 1 | **Encoding** | Record and submit inspection data — IO Number lookup, PO selection, trim defect entry, and result encoding |
| 2 | **Dashboard** | Aggregated visual overview of inspection results with date, supplier, and brand filters |
| 3 | **Inspection Report** | Filterable inspection report with PDF export via TCPDF/FPDF |
| 4 | **Raw Data Upload** | Bulk import of raw inspection data from Excel files into the database |
| 5 | **Performance Summary / Brand** | Performance monitoring summary broken down by brand and trim type |
| 6 | **Dropdown Menu** | Admin management of dropdown list values (categories, descriptions) |
| 7 | **Week / Month** | Calendar-based configuration for inspection weeks and months |

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
├── main.php            # Main shell — sidebar navigation + module loader
├── config.php          # Database connection & helper functions
├── config - orig.php   # Original config template (for reference)
├── module1.php         # Encoding module
├── module2.php         # Dashboard module
├── module3.php         # Inspection Report module
├── module4.php         # Raw Data Upload module
├── module5.php         # Performance Summary module
├── module6.php         # Dropdown Menu admin module
├── module7.php         # Week/Month calendar module
├── module8.php         # (Secondary login / utility)
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
   - `TRIMS_TBL_INSPECTION` — encoded inspection records
   - `TRIMS_TBL_DROPDOWN` — dropdown reference values
   - `TRIMS_TBL_WEEK` — week/month calendar entries
   - `user_management.dbo.TBL_USER_MANAGEMENT` — user accounts (separate database)

5. **Open in browser:**
   ```
   http://localhost/Trims/
   ```

---

## Usage

1. Log in with a valid username and password from `user_management.dbo.TBL_USER_MANAGEMENT`.
2. Use the sidebar to navigate between modules.
3. **Module 1 (Encoding):** Enter an IO Number, select a PO, fill in inspection details, and submit.
4. **Module 4 (Raw Data Upload):** Upload an Excel file to bulk-import raw data records.
5. **Module 2 (Dashboard):** Filter by date range, supplier, and brand to view inspection summaries.
6. **Module 3 (Inspection Report):** Generate and download PDF inspection reports.
7. **Modules 6 & 7:** Manage dropdown values and week/month calendar entries (admin use).

---

## Notes

- This system uses PHP's legacy `mssql_*` functions. If upgrading to **PHP 7+**, replace all `mssql_*` calls in `config.php` with Microsoft's [`sqlsrv`](https://docs.microsoft.com/en-us/sql/connect/php/sqlsrv-driver-api-reference) extension.
- The `Library/` folder contains the full TCPDF and FPDF libraries for PDF report generation.
- `config.php` intentionally has the `define()` calls commented out — uncomment and fill in your credentials before running.
