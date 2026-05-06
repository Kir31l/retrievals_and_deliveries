<?php
// ─────────────────────────────────────────────────────────────────────────────
//  EW BPO — Export API
//  GET /api/export.php?budget_id=3      → full budget ZIP
//  GET /api/export.php?submission_id=5  → single submission ZIP
//
//  ZIP structure:
//    riderlog_<label>/
//      report.xlsx
//      drivers/
//        Juan_Dela_Cruz/
//          delivery_sub5_entry12.jpg
//        Maria_Santos/
//          retrieval_sub7_entry18.jpg
//
//  Pure-PHP — NO ZipArchive extension required
// ─────────────────────────────────────────────────────────────────────────────
define('SKIP_JSON_HEADERS', true);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

// ═════════════════════════════════════════════════════════════════════════════
//  ROW TYPE MARKERS (used as first element to signal style to sheet_xml)
// ═════════════════════════════════════════════════════════════════════════════
const ROW_TITLE         = '__TITLE__';
const ROW_BLANK         = '__BLANK__';
const ROW_META          = '__META__';
const ROW_HEADER        = '__HEADER__';
const ROW_DELIVERY      = '__DELIVERY__';
const ROW_DELIVERY_ALT  = '__DELIVERY_ALT__';
const ROW_RETRIEVAL     = '__RETRIEVAL__';
const ROW_RETRIEVAL_ALT = '__RETRIEVAL_ALT__';
const ROW_TOTALS        = '__TOTALS__';

require_method('GET');

$pdo           = get_db();
$budget_id     = (int)($_GET['budget_id']     ?? 0);
$submission_id = (int)($_GET['submission_id'] ?? 0);

if ($budget_id <= 0 && $submission_id <= 0) {
    respond_error('Provide budget_id or submission_id.', 422);
}

// ── Load data ─────────────────────────────────────────────────────────────────
if ($submission_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM submissions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $submission_id]);
    $submissions = $stmt->fetchAll();
    if (!$submissions) respond_error('Submission not found.', 404);

    $budget_id = (int)$submissions[0]['budget_id'];
    $stmt = $pdo->prepare('SELECT * FROM budgets WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $budget_id]);
    $budget = $stmt->fetch();
    if (!$budget) respond_error('Budget not found.', 404);

    $stmt = $pdo->prepare('SELECT r.* FROM rider r WHERE r.submission_id = :sid ORDER BY r.id ASC');
    $stmt->execute([':sid' => $submission_id]);
    $all_entries = $stmt->fetchAll();

    $label = strtolower($submissions[0]['type']) . '_' . $submissions[0]['date'] . '_sub' . $submission_id;
} else {
    $stmt = $pdo->prepare('SELECT * FROM budgets WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $budget_id]);
    $budget = $stmt->fetch();
    if (!$budget) respond_error('Budget not found.', 404);

    $stmt = $pdo->prepare('SELECT * FROM submissions WHERE budget_id = :bid ORDER BY submitted_at ASC');
    $stmt->execute([':bid' => $budget_id]);
    $submissions = $stmt->fetchAll();
    if (!$submissions) respond_error('No submissions found for this budget.', 404);

    $stmt = $pdo->prepare('SELECT r.* FROM rider r WHERE r.budget_id = :bid ORDER BY r.submission_id ASC, r.id ASC');
    $stmt->execute([':bid' => $budget_id]);
    $all_entries = $stmt->fetchAll();

    $label = 'budget_' . $budget_id . '_' . date('Y-m-d', strtotime($budget['opened_at']));
}

$entries_by_sub = [];
foreach ($all_entries as $e) {
    $entries_by_sub[(int)$e['submission_id']][] = $e;
}

// ── Root folder — everything lives inside this ────────────────────────────────
$root = 'riderlog_' . $label . '/';

// ── Build ZIP file list ───────────────────────────────────────────────────────
$files = [];

// Excel report in root
$files[$root . 'report.xlsx'] = build_xlsx($budget, $submissions, $entries_by_sub);

// Photos organized by driver name
foreach ($all_entries as $e) {
    if (empty($e['photo'])) continue;

    $photos = decode_photos($e['photo']);
    if (empty($photos)) continue;

    // Sanitize driver name → folder slug
    $raw_name    = trim($e['name'] ?? 'Unknown_Driver');
    $driver_slug = preg_replace('/[^A-Za-z0-9_\-]/', '_', $raw_name);
    $driver_slug = preg_replace('/_+/', '_', $driver_slug);
    $driver_slug = trim($driver_slug, '_') ?: 'Unknown_Driver';

    // Find submission type for filename
    $sub_type = 'unknown';
    foreach ($submissions as $s) {
        if ((int)$s['id'] === (int)$e['submission_id']) { $sub_type = $s['type']; break; }
    }

    foreach ($photos as $photo_idx => $photo) {
        $ext = 'jpg';
        $raw = null;

        if (str_starts_with($photo, 'data:')) {
            [$meta, $encoded] = explode(',', $photo, 2);
            preg_match('/data:(image\/(\w+));base64/', $meta, $m);
            $ext = $m[2] ?? 'jpg';
            if ($ext === 'jpeg') $ext = 'jpg';
            $raw = base64_decode($encoded);
        } elseif (file_exists(__DIR__ . '/../../' . ltrim($photo, '/'))) {
            $raw = file_get_contents(__DIR__ . '/../../' . ltrim($photo, '/'));
            $ext = pathinfo($photo, PATHINFO_EXTENSION) ?: 'jpg';
        }

        if ($raw === null || $raw === false) continue;

        // riderlog_.../drivers/Juan_Dela_Cruz/delivery_sub5_entry12_1.jpg
        $suffix   = count($photos) > 1 ? '_' . ($photo_idx + 1) : '';
        $filename = sprintf('%s_sub%d_entry%d%s.%s', $sub_type, $e['submission_id'], $e['id'], $suffix, $ext);
        $files[$root . 'drivers/' . $driver_slug . '/' . $filename] = $raw;
    }
}

// ── Stream ZIP ────────────────────────────────────────────────────────────────
$zip_content = zip_build($files);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="riderlog_' . $label . '.zip"');
header('Content-Length: ' . strlen($zip_content));
header('Cache-Control: no-cache');
echo $zip_content;
exit;

// ═════════════════════════════════════════════════════════════════════════════
//  EXCEL BUILDER — color-coded, styled
// ═════════════════════════════════════════════════════════════════════════════
function build_xlsx(array $budget, array $submissions, array $entries_by_sub): string {
    $sheets = [];

    // ── Summary sheet ──────────────────────────────────────────────────────
    $summary   = [];
    $summary[] = [ROW_TITLE,  'EW BPO — Delivery & Retrieval Report'];
    $summary[] = [ROW_BLANK];
    $summary[] = [ROW_META,   'Budget #',           (int)$budget['id']];
    $summary[] = [ROW_META,   'Initial Funds (₱)',  (float)$budget['initial_amount']];
    $summary[] = [ROW_META,   'Remaining Funds (₱)',(float)$budget['remaining']];
    $summary[] = [ROW_META,   'Opened',              $budget['opened_at']];
    $summary[] = [ROW_META,   'Closed',              $budget['closed_at'] ?? 'Still Active'];
    $summary[] = [ROW_BLANK];
    $summary[] = [ROW_HEADER, '#', 'Date', 'Type', 'Entries',
                               'Total Fee (₱)', 'Total Toll (₱)', 'Total Expenses (₱)',
                               'Budget Before (₱)', 'Budget After (₱)'];

    $grand_fee = $grand_toll = $grand_exp = 0;
    foreach ($submissions as $i => $s) {
        $ec          = count($entries_by_sub[(int)$s['id']] ?? []);
        $grand_fee  += (float)$s['total_fee'];
        $grand_toll += (float)$s['total_toll'];
        $grand_exp  += (float)$s['total_expenses'];
        $type        = strtoupper($s['type']);
        $row_marker  = ($type === 'DELIVERY') ? ROW_DELIVERY : ROW_RETRIEVAL;
        $summary[]   = [$row_marker,
            $i + 1, $s['date'], $type, $ec,
            (float)$s['total_fee'], (float)$s['total_toll'], (float)$s['total_expenses'],
            (float)$s['budget_before'], (float)$s['budget_after'],
        ];
    }
    $summary[] = [ROW_BLANK];
    $summary[] = [ROW_TOTALS, '', '', 'TOTALS', '', $grand_fee, $grand_toll, $grand_exp, '', ''];

    $sheets[] = ['name' => 'Summary', 'rows' => $summary,
        // Col widths: #, Date, Type, Entries, TotalFee, TotalToll, TotalExp, BudgetBefore, BudgetAfter
        'col_widths' => [5, 13, 12, 9, 18, 18, 20, 18, 18],
    ];

    // ── One detailed sheet per submission ──────────────────────────────────
    foreach ($submissions as $s) {
        $sid     = (int)$s['id'];
        $entries = $entries_by_sub[$sid] ?? [];
        $type    = strtoupper($s['type']);
        $is_del  = ($type === 'DELIVERY');

        $rows   = [];
        $rows[] = [ROW_TITLE,  $type . ' SUBMISSION — ' . $s['date'] . ' (Sub #' . $sid . ')'];
        $rows[] = [ROW_BLANK];
        $rows[] = [ROW_META,   'Submission #',        $sid,                        'Type',          $type];
        $rows[] = [ROW_META,   'Date',                 $s['date'],                  'Submitted At',  $s['submitted_at']];
        $rows[] = [ROW_META,   'Budget Before (₱)',   (float)$s['budget_before'],  'Budget After (₱)', (float)$s['budget_after']];
        $rows[] = [ROW_META,   'Total Fee (₱)',       (float)$s['total_fee'],      'Total Toll (₱)',    (float)$s['total_toll']];
        $rows[] = [ROW_META,   'Total Expenses (₱)',  (float)$s['total_expenses']];
        $rows[] = [ROW_BLANK];
        $rows[] = [ROW_HEADER, '#', 'Service', 'Driver Name', 'Vehicle', 'Location',
                               'Date', 'Fee (₱)', 'Toll Entry (₱)', 'Toll Back (₱)', 'Photo'];

        $sub_fee = $sub_toll = 0;
        foreach ($entries as $j => $e) {
            $fee       = (float)$e['fee'];
            $toll_in   = (float)$e['toll_entry'];
            $toll_back = (float)$e['toll_back'];
            $sub_fee  += $fee;
            $sub_toll += $toll_in + $toll_back;

            // Zebra stripe: alternate between primary and alt shade
            if ($is_del) {
                $row_marker = ($j % 2 === 0) ? ROW_DELIVERY : ROW_DELIVERY_ALT;
            } else {
                $row_marker = ($j % 2 === 0) ? ROW_RETRIEVAL : ROW_RETRIEVAL_ALT;
            }

            $rows[] = [$row_marker,
                $j + 1,
                $e['service']  ?? '',
                $e['name']     ?? '',
                $e['vehicle']  ?? '',
                $e['loc']      ?? '',
                $e['date']     ?? $s['date'],
                $fee, $toll_in, $toll_back,
                !empty($e['photo']) ? count(decode_photos($e['photo'])) . ' photo(s)' : 'None',
            ];
        }

        $rows[] = [ROW_BLANK];
        $rows[] = [ROW_TOTALS, '', '', '', '', '', 'TOTALS', $sub_fee, $sub_toll, '', ''];

        $sheet_name = substr($type, 0, 3) . '_' . $s['date'] . '_' . $sid;
        $sheets[]   = ['name' => $sheet_name, 'rows' => $rows,
            // Col widths: #, Service, DriverName, Vehicle, Location, Date, Fee, TollEntry, TollBack, Photo
            'col_widths' => [5, 22, 22, 12, 24, 13, 14, 15, 15, 12],
        ];
    }

    return xlsx_from_sheets($sheets);
}

// ═════════════════════════════════════════════════════════════════════════════
//  XLSX LOW-LEVEL BUILDER — pure PHP, no extensions
// ═════════════════════════════════════════════════════════════════════════════
function xlsx_from_sheets(array $sheets): string {
    $sheet_xmls = [];
    $sheet_rels = [];
    $wb_sheets  = [];

    foreach ($sheets as $i => $sheet) {
        $sn           = $i + 1;
        $name         = xlsx_escape_attr($sheet['name']);
        $sheet_xmls["xl/worksheets/sheet{$sn}.xml"] = sheet_xml($sheet['rows'], $sheet['col_widths'] ?? []);
        $sheet_rels[] = '<sheet name="' . $name . '" sheetId="' . $sn . '" r:id="rId' . $sn . '"/>';
        $wb_sheets[]  = '<Relationship Id="rId' . $sn
            . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet' . $sn . '.xml"/>';
    }

    $workbook_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . implode('', $sheet_rels) . '</sheets>'
        . '</workbook>';

    $wb_rel_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', $wb_sheets)
        . '<Relationship Id="rIdStyles"'
        . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
        . ' Target="styles.xml"/>'
        . '</Relationships>';

    // ── Styles ────────────────────────────────────────────────────────────
    // Fonts:   0=normal  1=bold-white-large(title)  2=bold-white(header)
    //          3=bold-navy(meta-label/totals)        4=navy(meta-value)
    // Fills:   0=none  1=gray125  2=navy(title)  3=mid-blue(header)
    //          4=green(delivery)  5=light-green(delivery-alt)
    //          6=orange(retrieval)  7=light-orange(retrieval-alt)  8=gold(totals)
    // Borders: 0=none  1=thin-blue-gray
    // xf:      0=default  1=title  2=meta-label  3=meta-text  4=meta-num
    //          5=header  6=del-text  7=del-num  8=del-alt-text  9=del-alt-num
    //          10=ret-text  11=ret-num  12=ret-alt-text  13=ret-alt-num
    //          14=totals-text  15=totals-num

    $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="5">'
        .   '<font><sz val="11"/><name val="Calibri"/><color rgb="FF000000"/></font>'
        .   '<font><b/><sz val="14"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'
        .   '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'
        .   '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF1A2D5A"/></font>'
        .   '<font><sz val="11"/><name val="Calibri"/><color rgb="FF1A2D5A"/></font>'
        . '</fonts>'
        . '<fills count="9">'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF1A2D5A"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF264070"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFD6F0E0"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFECF8F2"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFFDE8D8"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF4EC"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF0B3"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
        .   '<border>'
        .     '<left style="thin"><color rgb="FFBDD0E8"/></left>'
        .     '<right style="thin"><color rgb="FFBDD0E8"/></right>'
        .     '<top style="thin"><color rgb="FFBDD0E8"/></top>'
        .     '<bottom style="thin"><color rgb="FFBDD0E8"/></bottom>'
        .     '<diagonal/>'
        .   '</border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs>'
        // 0 default
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        // 1 TITLE: navy bg, bold white large
        .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"><alignment vertical="center"/></xf>'
        // 2 META label: bold navy
        .   '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        // 3 META value text: navy
        .   '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        // 4 META value number: navy + 2dp
        .   '<xf numFmtId="2" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        // 5 HEADER: mid-blue bg, bold white, border, centered
        .   '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        // 6 DELIVERY text: green bg, border, wrap
        .   '<xf numFmtId="0" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        // 7 DELIVERY number: green bg, border, right-align, wrap
        .   '<xf numFmtId="2" fontId="0" fillId="4" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top" wrapText="1"/></xf>'
        // 8 DELIVERY_ALT text: light green bg, border, wrap
        .   '<xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        // 9 DELIVERY_ALT number, wrap
        .   '<xf numFmtId="2" fontId="0" fillId="5" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top" wrapText="1"/></xf>'
        // 10 RETRIEVAL text: orange bg, border, wrap
        .   '<xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        // 11 RETRIEVAL number: orange bg, border, wrap
        .   '<xf numFmtId="2" fontId="0" fillId="6" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top" wrapText="1"/></xf>'
        // 12 RETRIEVAL_ALT text: light orange, wrap
        .   '<xf numFmtId="0" fontId="0" fillId="7" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
        // 13 RETRIEVAL_ALT number, wrap
        .   '<xf numFmtId="2" fontId="0" fillId="7" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top" wrapText="1"/></xf>'
        // 14 TOTALS label: gold bg, bold navy, border
        .   '<xf numFmtId="0" fontId="3" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        // 15 TOTALS number: gold bg, bold navy, border, 2dp
        .   '<xf numFmtId="2" fontId="3" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="right"/></xf>'
        . '</cellXfs>'
        . '</styleSheet>';

    $ct_parts = '';
    for ($i = 1; $i <= count($sheets); $i++) {
        $ct_parts .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }

    $ct_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . $ct_parts . '</Types>';

    $root_rel = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1"'
        . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
        . ' Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $files = [
        '[Content_Types].xml'        => $ct_xml,
        '_rels/.rels'                => $root_rel,
        'xl/workbook.xml'            => $workbook_xml,
        'xl/_rels/workbook.xml.rels' => $wb_rel_xml,
        'xl/styles.xml'              => $styles_xml,
    ];
    foreach ($sheet_xmls as $path => $xml) {
        $files[$path] = $xml;
    }

    return zip_build($files);
}

// ── Render rows to XML with style awareness ───────────────────────────────────
function sheet_xml(array $rows, array $col_widths = []): string {
    // ── Column width definitions ─────────────────────────────────────────
    $cols_xml = '';
    if (!empty($col_widths)) {
        $cols_xml = '<cols>';
        foreach ($col_widths as $ci => $w) {
            $colNum   = $ci + 1;
            $cols_xml .= '<col min="' . $colNum . '" max="' . $colNum
                       . '" width="' . $w . '" customWidth="1" bestFit="0"/>';
        }
        $cols_xml .= '</cols>';
    }

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
         . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
         . '<sheetViews>'
         . '<sheetView workbookViewId="0">'
         . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
         . '</sheetView>'
         . '</sheetViews>'
         . '<sheetFormatPr defaultRowHeight="15"/>'
         . $cols_xml
         . '<sheetData>';

    $rn = 0;
    foreach ($rows as $row) {
        $rn++;

        // Blank sentinel
        if (!is_array($row) || (isset($row[0]) && $row[0] === ROW_BLANK)) {
            $xml .= '<row r="' . $rn . '" ht="6" customHeight="1"/>';
            continue;
        }

        $marker = $row[0];
        $cells  = array_slice($row, 1);

        // Map marker → (row height, text style index, number style index)
        $cfg = [
            ROW_TITLE         => [22, 1,  1],
            ROW_META          => [15, 2,  2],   // label style; value style handled specially
            ROW_HEADER        => [18, 5,  5],
            ROW_DELIVERY      => [15, 6,  7],
            ROW_DELIVERY_ALT  => [15, 8,  9],
            ROW_RETRIEVAL     => [15, 10, 11],
            ROW_RETRIEVAL_ALT => [15, 12, 13],
            ROW_TOTALS        => [16, 14, 15],
        ];

        if (!isset($cfg[$marker])) {
            // No marker — treat entire array as plain cells
            $cells  = $row;
            $marker = null;
            $ht     = 15; $s_txt = 0; $s_num = 0;
        } else {
            [$ht, $s_txt, $s_num] = $cfg[$marker];
        }

        $xml .= '<row r="' . $rn . '" ht="' . $ht . '" customHeight="1">';

        $is_meta = ($marker === ROW_META);

        foreach ($cells as $ci => $val) {
            $col = col_letter($ci) . $rn;
            $is_num = is_float($val) || is_int($val);

            if ($is_meta) {
                // Even columns = bold-navy label (2), odd = value text(3) or number(4)
                $style = ($ci % 2 === 0) ? 2 : ($is_num ? 4 : 3);
            } else {
                $style = $is_num ? $s_num : $s_txt;
            }

            if ($val === null || $val === '') {
                $xml .= '<c r="' . $col . '" s="' . $style . '"/>';
            } elseif ($is_num) {
                $xml .= '<c r="' . $col . '" s="' . $style . '"><v>' . $val . '</v></c>';
            } else {
                $xml .= '<c r="' . $col . '" t="inlineStr" s="' . $style . '"><is><t>'
                      . xlsx_escape((string)$val) . '</t></is></c>';
            }
        }

        $xml .= '</row>';
    }

    $xml .= '</sheetData></worksheet>';
    return $xml;
}

// ═════════════════════════════════════════════════════════════════════════════
//  PURE-PHP ZIP BUILDER — PKZIP spec, STORE method, no extensions needed
// ═════════════════════════════════════════════════════════════════════════════
function zip_build(array $files): string {
    $local_headers = '';
    $central_dir   = '';
    $offset        = 0;
    $count         = 0;

    foreach ($files as $name => $data) {
        $name_len = strlen($name);
        $data_len = strlen($data);
        $crc      = crc32($data);
        $d        = getdate(time());
        $dosdate  = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
        $dostime  = ($d['hours'] << 11) | ($d['minutes'] << 5) | (int)($d['seconds'] / 2);

        $local  = "\x50\x4b\x03\x04";
        $local .= pack('v', 20);
        $local .= pack('v', 0);
        $local .= pack('v', 0);
        $local .= pack('v', $dostime);
        $local .= pack('v', $dosdate);
        $local .= pack('V', $crc);
        $local .= pack('V', $data_len);
        $local .= pack('V', $data_len);
        $local .= pack('v', $name_len);
        $local .= pack('v', 0);
        $local .= $name;
        $local .= $data;

        $cd  = "\x50\x4b\x01\x02";
        $cd .= pack('v', 0x0314);
        $cd .= pack('v', 20);
        $cd .= pack('v', 0);
        $cd .= pack('v', 0);
        $cd .= pack('v', $dostime);
        $cd .= pack('v', $dosdate);
        $cd .= pack('V', $crc);
        $cd .= pack('V', $data_len);
        $cd .= pack('V', $data_len);
        $cd .= pack('v', $name_len);
        $cd .= pack('v', 0);
        $cd .= pack('v', 0);
        $cd .= pack('v', 0);
        $cd .= pack('v', 0);
        $cd .= pack('V', 0);
        $cd .= pack('V', $offset);
        $cd .= $name;

        $local_headers .= $local;
        $central_dir   .= $cd;
        $offset        += strlen($local);
        $count++;
    }

    $cd_size   = strlen($central_dir);
    $cd_offset = $offset;

    $eocd  = "\x50\x4b\x05\x06";
    $eocd .= pack('v', 0);
    $eocd .= pack('v', 0);
    $eocd .= pack('v', $count);
    $eocd .= pack('v', $count);
    $eocd .= pack('V', $cd_size);
    $eocd .= pack('V', $cd_offset);
    $eocd .= pack('v', 0);

    return $local_headers . $central_dir . $eocd;
}

function col_letter(int $n): string {
    $s = '';
    for ($n++; $n > 0; $n = intdiv($n, 26))
        $s = chr(65 + (($n - 1) % 26)) . $s;
    return $s;
}

function xlsx_escape(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_escape_attr(string $s): string {
    return substr(preg_replace('/[\/\\?*:\[\]]/', '_', $s), 0, 31);
}
