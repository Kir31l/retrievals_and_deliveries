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
9. [User Manual](#user-manual)

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

---

## User Manual

This section is for day-to-day staff who use the app to log deliveries and retrievals. No technical knowledge required.

---

### Getting Started

Open `accounting.html` in your browser. You will see three tabs at the top: **Delivery**, **Retrieval**, and **History**.

Before you can log anything, an active budget must be open. If the budget bar at the top shows **"No active budget"**, ask your administrator to open one.

---

### Understanding the Budget Bar

At the top of both the Delivery and Retrieval tabs, a budget bar shows a live summary of the current batch before you submit:

| Field | What it means |
|---|---|
| **Budget Remaining** | How much money is left in the active budget right now |
| **Tolls (this batch)** | Total toll costs entered in the current table |
| **Fees (this batch)** | Total service fees entered in the current table |
| **Total Expenses** | Fees + Tolls combined — what will be deducted on submit |
| **Budget After Submit** | What the balance will be if you submit this batch now |

The **Budget After Submit** turns **red** if the batch would deplete the budget. You can still submit, but the budget will automatically close afterwards.

---

### Logging a Delivery

1. Click the **Delivery** tab.
2. Click **+ Add Row** for each rider or package in the batch.
3. Fill in each row:

| Field | What to enter |
|---|---|
| **Service** | The delivery service used — e.g. GrabExpress, LBC, J&T, Lalamove |
| **Name** | The driver's name or the recipient's name |
| **Vehicle** | Plate number or vehicle description |
| **Location** | Pickup or drop-off address |
| **Date** | Date of the delivery (defaults to today) |
| **Fee (₱)** | Amount paid to the delivery service |
| **Toll In (₱)** | Toll paid on the way to the destination |
| **Toll Back (₱)** | Toll paid on the return trip |
| **Photo** | Optional — tap the camera icon to attach a photo of the receipt or package |

4. To remove a row, click the **✕** button on the right side of that row.
5. When all entries are filled, click **Submit Delivery**.

---

### Logging a Retrieval

The process is identical to Delivery. Click the **Retrieval** tab and follow the same steps. Retrieval entries are kept separate from Delivery entries in all reports and history views.

---

### Submitting a Batch

When you click **Submit Delivery** or **Submit Retrieval**, a confirmation window appears showing:

- Number of entries
- Current budget balance
- Service Fees and Tolls for this batch
- Total Expenses (what will be deducted)
- Budget balance after submission

Review the summary, then click **Confirm** to save. The table clears and the budget bar updates immediately.

> If a required field is missing, the submission will be blocked and the row with the missing data will be highlighted.

---

### Attaching Photos

Each row has a **📷** button. Click it to:

- Take a photo (on mobile)
- Upload an image from your device

You can attach multiple photos per entry. A badge on the button shows how many photos are attached. Photos are saved together with the entry and can be viewed or downloaded later from the History tab.

---

### Viewing History

Click the **History** tab to see all past budgets.

Each budget in the left panel shows its label, date range, and remaining balance. Click a budget to expand its detail view on the right, which shows:

- **Summary stats** — Initial Budget, Tolls Spent, Service Fees Spent, Total Expenses, Remaining balance.
- **Each submission** as a collapsible row. Click a submission header to expand it and see the full entry table.
- Inside each expanded submission, a **totals bar** shows the Service Fees, Tolls, Total Expenses, and number of entries for that batch.

---

### Editing a Past Entry

In the History tab, expand a submission and click any cell in the entry table to edit it directly. Changed cells are highlighted. When you're done editing, click the **Save** button (or press Enter) to save the change. The submission totals and budget balance update automatically.

---

### Exporting Records

In the History tab, each budget has an **⬇ Export ZIP** button. Clicking it downloads a `.zip` file containing:

- **report.xlsx** — a full Excel spreadsheet with a summary sheet and one sheet per submission, with colour-coded rows (orange for retrieval, green for delivery).
- **drivers/** folder — all attached photos organised by driver name.

Use this for filing, auditing, or sharing records with management.

---

### Tips and Common Questions

**Can I submit without filling every field?**
Service, Name, and Location are the minimum required fields. Fee and Toll fields default to ₱0.00 if left blank.

**What if I added a row by mistake?**
Click the **✕** button on that row before submitting. Rows cannot be deleted after submission, but they can be edited to zero out the amounts.

**The Budget After Submit is showing red — what do I do?**
It means this batch will use up the remaining budget. You can still submit, but the budget will close automatically. Coordinate with your administrator to open a new budget if more transactions are expected.

**The budget bar says "No active budget."**
No submissions can be made until a budget is opened. Contact your administrator.

**Can I submit to a closed budget?**
No. Closed budgets are read-only and visible only in the History tab.

**Where are the photos stored?**
Photos are stored securely in the database (or on the server, depending on configuration). They are only accessible through the app or the ZIP export.

