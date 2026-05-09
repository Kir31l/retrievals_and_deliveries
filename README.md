# RiderLog — EW BPO Accounting System

Internal tool for tracking delivery and retrieval expenses for borrowed company assets. Manages budgets, records per-rider entries, and exports reports as styled Excel sheets or ZIP packages.

---

## Table of Contents

1. [Overview](#overview)
2. [File Structure](#file-structure)
3. [Database Schema](#database-schema)
4. [Frontend — accounting.html](#frontend--accountinghtml)
5. [API Reference](#api-reference)
   - [budget.php](#budgetphp)
   - [submit.php](#submitphp)
   - [submissions.php](#submissionsphp)
   - [update_entry.php](#update_entryphp)
   - [funds.php](#fundsphp)
   - [photo.php](#photophp)
   - [export.php](#exportphp)
   - [export_sheet.php](#export_sheetphp)
6. [Expense Logic](#expense-logic)
7. [Export Format](#export-format)
8. [Configuration](#configuration)

---

## Overview

RiderLog tracks two transaction types — **Delivery** and **Retrieval** — each logged as a batch submission under an active budget. Every submission contains one or more rider entries recording the service used, driver details, service fee, and toll costs. Both fees and tolls are treated as expenses and deducted from the active budget.

**Key concepts:**

- **Budget** — a pool of funds. Only one budget can be active at a time. It auto-closes when it reaches zero.
- **Submission** — a dated batch of rider entries under a budget, either `delivery` or `retrieval`.
- **Rider entry** — one row: service provider, driver name, vehicle, location, fee, toll in, toll back, and optional photos.
- **Expenses** — `fee + toll_entry + toll_back` per entry. Both service fees and tolls are expenses (not income).

---

## File Structure

```
/
├── accounting.html          # Single-page frontend (all UI)
├── api/
│   ├── helpers.php          # Shared constants, response helpers, sanitizers, photo utilities
│   ├── db.php               # PDO connection factory
│   ├── config.php           # Re-exports helpers.php (entry point)
│   ├── budget.php           # Budget CRUD
│   ├── submit.php           # Create submissions + rider entries
│   ├── submissions.php      # Read / delete submissions
│   ├── update_entry.php     # Edit a single rider row in-place
│   ├── funds.php            # Daily funds tracking (separate from budgets)
│   ├── photo.php            # Serve stored photos
│   ├── export.php           # Full ZIP export (XLSX + photos)
│   └── export_sheet.php     # Standalone XLSX export (no photos)
└── uploads/                 # Photo storage (when PHOTO_STORAGE = 'disk')
```

---

## Database Schema

### `budgets`

| Column           | Type          | Description                              |
|------------------|---------------|------------------------------------------|
| `id`             | INT PK        | Auto-increment                           |
| `initial_amount` | DECIMAL       | Opening balance                          |
| `remaining`      | DECIMAL       | Current balance (decremented on submit)  |
| `notes`          | TEXT NULL     | Optional label                           |
| `opened_at`      | DATETIME      | Auto-set on insert                       |
| `closed_at`      | DATETIME NULL | Set when depleted or manually closed     |

One budget is active at a time (`closed_at IS NULL`). Opening a new budget auto-closes any lingering active one.

### `submissions`

| Column           | Type        | Description                                        |
|------------------|-------------|----------------------------------------------------|
| `id`             | INT PK      |                                                    |
| `budget_id`      | INT FK      | Parent budget                                      |
| `type`           | ENUM        | `delivery` or `retrieval`                          |
| `date`           | DATE        | Batch date                                         |
| `total_fee`      | DECIMAL     | Sum of all entry fees                              |
| `total_toll`     | DECIMAL     | Sum of all entry tolls                             |
| `total_expenses` | DECIMAL     | `total_fee + total_toll` — amount deducted         |
| `budget_before`  | DECIMAL     | Budget remaining before this submission            |
| `budget_after`   | DECIMAL     | Budget remaining after this submission             |
| `submitted_at`   | DATETIME    | Auto-set on insert                                 |

### `rider`

| Column          | Type        | Description                                         |
|-----------------|-------------|-----------------------------------------------------|
| `id`            | INT PK      |                                                     |
| `submission_id` | INT FK      | Parent submission                                   |
| `budget_id`     | INT FK      | Denormalized for faster queries                     |
| `type`          | ENUM        | Inherited from submission                           |
| `service`       | VARCHAR     | Delivery service name (e.g. GrabExpress, LBC)       |
| `name`          | VARCHAR     | Driver / recipient name                             |
| `vehicle`       | VARCHAR     | Vehicle plate or description                        |
| `loc`           | VARCHAR     | Pickup / drop-off location                          |
| `date`          | DATE        | Entry date (may differ from submission date)        |
| `fee`           | DECIMAL     | Service fee — treated as an expense                 |
| `toll_entry`    | DECIMAL     | Entry toll                                          |
| `toll_back`     | DECIMAL     | Return toll                                         |
| `photo`         | LONGTEXT    | JSON array of base64 data-URIs, or file paths       |

### `funds`

| Column       | Type     | Description                              |
|--------------|----------|------------------------------------------|
| `id`         | INT PK   |                                          |
| `date`       | DATE     | Unique per date + type                   |
| `type`       | ENUM     | `delivery` or `retrieval`                |
| `amount`     | DECIMAL  | Funds allocated for that day             |
| `updated_at` | DATETIME |                                          |

---

## Frontend — accounting.html

A self-contained single-page app. No build step — plain HTML, CSS, and vanilla JS.

### Tabs

**Delivery / Retrieval tabs** — Active data-entry view. Each tab shows:
- A budget bar with: budget remaining, tolls (this batch), fees (this batch), total expenses, and projected budget after submit.
- A spreadsheet-style entry table. Each row: Service, Name, Vehicle, Location, Date, Fee (₱), Toll In (₱), Toll Back (₱), Photo, Delete.
- Add Row / Submit buttons.

**History tab** — Browse all past budgets. Expand a budget to see:
- Stats: Initial Budget, Tolls Spent, Service Fees Spent, Total Expenses, Remaining.
- Each submission as a collapsible sheet with its entry table and per-batch totals toolbar.
- Export ZIP button per budget.

### Key JS Functions

| Function | Purpose |
|---|---|
| `recalc(type)` | Recalculates fee + toll totals and projected budget after; called on any input change |
| `addRow(type)` | Appends a new empty entry row to the active tab |
| `collectRows(type)` | Reads all row inputs and returns an entries array |
| `openSubmitModal(type)` | Validates, builds a confirm summary (fees, tolls, total expenses, budget impact), stores `pendingSubmit` |
| `confirmSubmit()` | POSTs to `submit.php`, refreshes budget, clears table |
| `loadHistory()` | Fetches all budgets, renders the history sidebar |
| `showBudgetDetail(budgetId)` | Fetches submissions + entries for a budget, renders collapsible sheets |
| `buildSheetRow(e, idx)` | Renders a single editable rider row inside a history sheet |
| `totalsHtml(t)` | Returns the HTML for the per-batch totals toolbar (Service Fees, Tolls, Total Expenses, Entries) |

---

## API Reference

All endpoints return JSON: `{ success: true, data: ..., message: "..." }` or `{ success: false, error: "..." }`.

---

### budget.php

**`GET /api/budget.php`** — Returns the currently active budget, or `null` if none.

**`GET /api/budget.php?all=1`** — Returns all budgets with submission count and total spent.

**`POST /api/budget.php`** — Opens a new budget. Auto-closes any existing active budget.

Request body:
```json
{ "amount": 50000, "notes": "February batch" }
```

**`POST /api/budget.php?close=1`** — Manually closes the active budget.

---

### submit.php

**`POST /api/submit.php`** — Submits a batch of rider entries under an active budget.

Request body:
```json
{
  "budget_id": 3,
  "type": "delivery",
  "date": "2026-02-20",
  "entries": [
    {
      "service": "GrabExpress",
      "name": "Juan Dela Cruz",
      "vehicle": "ABC 123",
      "loc": "Makati",
      "date": "2026-02-20",
      "fee": 150.00,
      "toll_entry": 65.00,
      "toll_back": 65.00,
      "photos": ["data:image/jpeg;base64,..."]
    }
  ]
}
```

Behavior:
- Calculates `total_expenses = total_fee + total_toll`.
- Deducts `total_expenses` from `budget.remaining`.
- Auto-closes the budget if `remaining <= 0` after deduction.

---

### submissions.php

**`GET /api/submissions.php?budget_id=3`** — Lists all submissions for a budget (newest first).

**`GET /api/submissions.php?id=42`** — Returns a single submission with its full `entries` array. Photos are not returned inline — use `photo.php` to retrieve them.

**`DELETE /api/submissions.php?id=42`** — Deletes a submission and refunds its `total_expenses` back to the budget.

> Only works if the budget is still open (`closed_at IS NULL`).

---

### update_entry.php

**`POST /api/update_entry.php`** — Edits a single rider row in place and recalculates the parent submission's totals and the budget's remaining balance.

Request body:
```json
{
  "id": 18,
  "service": "LBC",
  "name": "Maria Santos",
  "vehicle": "XYZ 456",
  "loc": "Quezon City",
  "fee": 200.00,
  "toll_entry": 0,
  "toll_back": 0,
  "photos": ["__keep__"]
}
```

Photo values:
- `"data:image/jpeg;base64,..."` — replace with new photo
- `"__keep__"` — preserve existing photo at that index
- omit / `null` — clear photo

---

### funds.php

**`GET /api/funds.php?date=2026-02-20&type=delivery`** — Returns the allocated funds for a given date and type.

**`POST /api/funds.php`** — Sets (upserts) the daily fund allocation.

```json
{ "date": "2026-02-20", "type": "delivery", "amount": 5000 }
```

> This is a separate ledger from the main budget system and is used for daily planning purposes.

---

### photo.php

**`GET /api/photo.php?id=18`** — Serves the first photo for rider entry `id=18` as raw image bytes.

**`GET /api/photo.php?id=18&idx=1`** — Serves the photo at index 1 (0-based).

**`GET /api/photo.php?id=18&count=1`** — Returns `{ "count": N }` — the number of photos stored for that entry.

---

### export.php

**`GET /api/export.php?budget_id=3`** — Downloads a ZIP for the entire budget.

**`GET /api/export.php?submission_id=5`** — Downloads a ZIP for a single submission.

ZIP structure:
```
riderlog_<label>/
├── report.xlsx         # Styled Excel report (Summary + one sheet per submission)
└── drivers/
    ├── Juan_Dela_Cruz/
    │   └── delivery_sub5_entry12.jpg
    └── Maria_Santos/
        └── retrieval_sub7_entry18.jpg
```

Built with pure PHP — no `ZipArchive` extension required.

---

### export_sheet.php

**`GET /api/export_sheet.php?budget_id=3`** — Downloads only the Excel report for a budget (no photos).

**`GET /api/export_sheet.php?id=42`** — Downloads the Excel report for a single submission.

---

## Expense Logic

Service fees and tolls are both expenses — they are deducted from the budget on submission.

```
total_expenses = total_fee + total_toll
budget_after   = budget_before - total_expenses
```

This is applied in:
- `submit.php` — on initial submission
- `update_entry.php` — recalculated whenever a rider row is edited
- `submissions.php (DELETE)` — refunded when a submission is deleted
- `accounting.html recalc()` — previewed live in the budget bar before submitting

---

## Export Format

The Excel report (`report.xlsx`) contains:

**Summary sheet** — one row per submission with budget flow (Before → After).

**Per-submission sheets** — one row per rider entry, zebra-striped by type:

| Row type | Background |
|---|---|
| Title | Orange `#E8621A` with white bold text |
| Column headers | Light blue `#4BB8E8` with white bold text |
| Delivery rows | Green `#D6F0E0` / `#ECF8F2` alternating |
| Retrieval rows | Orange `#FDE8D8` / `#FFF4EC` alternating |
| Totals row | Gold `#FFF0B3` with bold navy text |
| Cell borders | Light grey `#CCCCCC` |

---

## Configuration

All constants are defined in `helpers.php`:

| Constant | Default | Description |
|---|---|---|
| `DB_HOST` | `localhost` | MySQL host |
| `DB_PORT` | `3306` | MySQL port |
| `DB_NAME` | `accounting` | Database name |
| `DB_USER` | `root` | Database user |
| `DB_PASS` | _(empty)_ | Database password |
| `DB_CHARSET` | `utf8mb4` | Connection charset |
| `PHOTO_STORAGE` | `base64` | `base64` stores data-URIs in DB; `disk` saves files to `UPLOAD_DIR` |
| `UPLOAD_DIR` | `../uploads/` | Path for disk photo storage |
| `MAX_PHOTO_SIZE` | `5242880` | Max photo size in bytes (5 MB) |
| `PRODUCTION` | `false` | Set to `true` to hide raw DB errors in responses |

To deploy:
1. Create the MySQL database and run the schema SQL.
2. Edit the DB constants in `helpers.php`.
3. Set `PRODUCTION` to `true`.
4. Point your web server root at the folder containing `accounting.html`.
5. Ensure `api/` is accessible at `/api/`.
