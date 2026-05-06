<?php
// ─────────────────────────────────────────────
//  RiderLog — Single Submission Sheet Export
//
//  GET /api/export_sheet.php?id=5
//    → Downloads report_delivery_2026-02-19.xlsx
//
//  GET /api/export_sheet.php?budget_id=3
//    → Downloads all submissions for that budget
//       as one .xlsx with one sheet per submission
//
//  Pure-PHP — NO ZipArchive extension required
// ─────────────────────────────────────────────
define('SKIP_JSON_HEADERS', true);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

require_method('GET');

$pdo = get_db();

// ── Resolve what we're exporting ──────────────
$sub_id    = isset($_GET['id'])        ? (int)$_GET['id']        : 0;
$budget_id = isset($_GET['budget_id']) ? (int)$_GET['budget_id'] : 0;

if ($sub_id <= 0 && $budget_id <= 0) {
    respond_error('Provide id (submission) or budget_id.', 422);
}

// ── Load submissions ───────────────────────────
if ($sub_id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM submissions WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $sub_id]);
    $submissions = $stmt->fetchAll();
    if (!$submissions) respond_error('Submission not found.', 404);

    // Load budget for this submission
    $bid  = (int)$submissions[0]['budget_id'];
    $stmt = $pdo->prepare('SELECT * FROM budgets WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $bid]);
    $budget = $stmt->fetch();

    $filename_label = strtolower($submissions[0]['type']) . '_' . $submissions[0]['date'];
} else {
    $stmt = $pdo->prepare('SELECT * FROM budgets WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $budget_id]);
    $budget = $stmt->fetch();
    if (!$budget) respond_error('Budget not found.', 404);

    $stmt = $pdo->prepare('SELECT * FROM submissions WHERE budget_id = :bid ORDER BY submitted_at ASC');
    $stmt->execute([':bid' => $budget_id]);
    $submissions = $stmt->fetchAll();
    if (!$submissions) respond_error('No submissions found for this budget.', 404);

    $filename_label = 'budget_' . $budget_id . '_' . date('Y-m-d', strtotime($budget['opened_at']));
}

// ── Load all entries ───────────────────────────
$sub_ids       = array_column($submissions, 'id');
$placeholders  = implode(',', array_fill(0, count($sub_ids), '?'));
$stmt          = $pdo->prepare("
    SELECT id, submission_id, service, name, vehicle, loc, date,
           fee, toll_entry, toll_back,
           CASE WHEN photo IS NOT NULL AND photo != '' THEN 'Yes' ELSE 'No' END AS has_photo
    FROM rider
    WHERE submission_id IN ($placeholders)
    ORDER BY submission_id ASC, id ASC
");
$stmt->execute($sub_ids);
$all_entries = $stmt->fetchAll();

$entries_by_sub = [];
foreach ($all_entries as $e) {
    $entries_by_sub[(int)$e['submission_id']][] = $e;
}

// ── Build XLSX sheets ──────────────────────────
$sheets = [];

// ── Summary sheet ──────────────────────────────
$summary   = [];
$summary[] = ['', 'DELIVERY & RETRIEVAL REPORT'];
$summary[] = [];
$summary[] = ['Budget #',        (int)$budget['id']];
$summary[] = ['Initial Funds',   (float)$budget['initial_amount']];
$summary[] = ['Remaining Funds', (float)$budget['remaining']];
$summary[] = ['Opened',          $budget['opened_at']];
$summary[] = ['Closed',          $budget['closed_at'] ?? 'Still Active'];
$summary[] = [];
$summary[] = ['#', 'Date', 'Type', 'Entries', 'Total Fee (₱)', 'Total Toll (₱)', 'Total Expenses (₱)', 'Budget Before (₱)', 'Budget After (₱)'];

$grand_fee      = 0;
$grand_toll     = 0;
$grand_expenses = 0;

foreach ($submissions as $i => $s) {
    $ec              = count($entries_by_sub[(int)$s['id']] ?? []);
    $grand_fee      += (float)$s['total_fee'];
    $grand_toll     += (float)$s['total_toll'];
    $grand_expenses += (float)$s['total_expenses'];
    $summary[] = [
        $i + 1,
        $s['date'],
        strtoupper($s['type']),
        $ec,
        (float)$s['total_fee'],
        (float)$s['total_toll'],
        (float)$s['total_expenses'],
        (float)$s['budget_before'],
        (float)$s['budget_after'],
    ];
}

$summary[] = [];
$summary[] = ['', 'TOTALS', '', '', $grand_fee, $grand_toll, $grand_expenses];

$sheets[] = ['name' => 'Summary', 'rows' => $summary];

// ── One sheet per submission ───────────────────
foreach ($submissions as $s) {
    $sid     = (int)$s['id'];
    $entries = $entries_by_sub[$sid] ?? [];
    $type    = strtoupper($s['type']);

    $rows   = [];
    $rows[] = ['', $type . ' SUBMISSION — ' . $s['date']];
    $rows[] = [];
    $rows[] = ['Submission #',   $sid,                       'Type',           $type];
    $rows[] = ['Date',           $s['date'],                 'Submitted At',   $s['submitted_at']];
    $rows[] = ['Budget Before',  (float)$s['budget_before'], 'Budget After',   (float)$s['budget_after']];
    $rows[] = ['Total Fee',      (float)$s['total_fee'],     'Total Toll',     (float)$s['total_toll']];
    $rows[] = ['Total Expenses', (float)$s['total_expenses']];
    $rows[] = [];

    // Column headers
    $rows[] = ['#', 'Service', 'Driver Name', 'Vehicle', 'Location', 'Date', 'Fee (₱)', 'Toll Entry (₱)', 'Toll Back (₱)', 'Photo'];

    $sub_fee  = 0;
    $sub_toll = 0;
    foreach ($entries as $j => $e) {
        $fee       = (float)$e['fee'];
        $toll_in   = (float)$e['toll_entry'];
        $toll_back = (float)$e['toll_back'];
        $sub_fee  += $fee;
        $sub_toll += $toll_in + $toll_back;
        $rows[]    = [
            $j + 1,
            $e['service'] ?? '',
            $e['name']    ?? '',
            $e['vehicle'] ?? '',
            $e['loc']     ?? '',
            $e['date']    ?? $s['date'],
            $fee,
            $toll_in,
            $toll_back,
            $e['has_photo'],
        ];
    }

    $rows[] = [];
    $rows[] = ['', '', '', '', '', 'TOTALS', $sub_fee, $sub_toll];

    // Sheet name: e.g. DEL_2026-02-19_5
    $sheet_name = substr($type, 0, 3) . '_' . $s['date'] . '_' . $sid;
    $sheets[]   = ['name' => $sheet_name, 'rows' => $rows];
}

// ── Stream XLSX ────────────────────────────────
$xlsx = xlsx_from_sheets($sheets);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="ewbpo_' . $filename_label . '.xlsx"');
header('Content-Length: ' . strlen($xlsx));
header('Cache-Control: no-cache, must-revalidate');
echo $xlsx;
exit;

// ══════════════════════════════════════════════
//  XLSX builder — pure PHP, no extensions needed
// ══════════════════════════════════════════════
function xlsx_from_sheets(array $sheets): string {
    $sheet_xmls = [];
    $sheet_rels = [];
    $wb_sheets  = [];

    foreach ($sheets as $i => $sheet) {
        $sn           = $i + 1;
        $name         = xlsx_escape_attr($sheet['name']);
        $sheet_xmls["xl/worksheets/sheet{$sn}.xml"] = sheet_xml($sheet['rows']);
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

    $styles_xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts>'
        .   '<font><sz val="11"/><name val="Calibri"/></font>'              // 0 normal
        .   '<font><b/><sz val="12"/><name val="Calibri"/></font>'          // 1 bold header
        .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'          // 2 bold normal
        . '</fonts>'
        . '<fills>'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF1A2D5A"/></patternFill></fill>' // 2 navy
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FF172850"/></patternFill></fill>' // 3 mid navy
        . '</fills>'
        . '<borders>'
        .   '<border><left/><right/><top/><bottom/><diagonal/></border>'    // 0 none
        .   '<border>'
        .     '<left style="thin"><color rgb="FF264070"/></left>'
        .     '<right style="thin"><color rgb="FF264070"/></right>'
        .     '<top style="thin"><color rgb="FF264070"/></top>'
        .     '<bottom style="thin"><color rgb="FF264070"/></bottom>'
        .     '<diagonal/>'
        .   '</border>'                                                      // 1 thin blue
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs>'
        .   '<xf numFmtId="0"  fontId="0" fillId="0" borderId="0" xfId="0"/>'                          // 0 default
        .   '<xf numFmtId="0"  fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center"/></xf>' // 1 title
        .   '<xf numFmtId="0"  fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'                                    // 2 col header
        .   '<xf numFmtId="0"  fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'          // 3 data cell
        .   '<xf numFmtId="2"  fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'          // 4 number cell
        .   '<xf numFmtId="0"  fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'            // 5 bold label
        .   '<xf numFmtId="2"  fontId="2" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>' // 6 bold number (totals)
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
        . $ct_parts
        . '</Types>';

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

function sheet_xml(array $rows): string {
    // Row style hints: rows starting with '' in col 0 and a header string get title style
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
         . '<sheetData>';

    foreach ($rows as $ri => $row) {
        if (empty($row)) continue; // skip blank rows but preserve row numbering
        $rn   = $ri + 1;
        $xml .= '<row r="' . $rn . '">';

        // Detect row type for styling
        $is_title    = (count($row) >= 2 && $row[0] === '' && is_string($row[1]) && strtoupper($row[1]) === $row[1] && strlen($row[1]) > 3);
        $is_col_hdr  = (count($row) >= 4 && $row[0] === '#');
        $is_totals   = (count($row) >= 2 && $row[count($row)-1] !== '' && isset($row[array_key_last($row)]) && (array_search('TOTALS', $row) !== false));
        $is_bold_lbl = (count($row) === 2 && is_string($row[0]) && $row[0] !== '' && !is_numeric($row[0]) && !$is_col_hdr);

        foreach ($row as $ci => $val) {
            $col = col_letter($ci) . $rn;

            if ($is_title) {
                $s = 1;
            } elseif ($is_col_hdr) {
                $s = 2;
            } elseif ($is_totals && (is_float($val) || is_int($val))) {
                $s = 6;
            } elseif ($is_bold_lbl && $ci === 0) {
                $s = 5;
            } elseif (is_float($val) || is_int($val)) {
                $s = 4;
            } else {
                $s = 3;
            }

            if ($val === null || $val === '') {
                $xml .= '<c r="' . $col . '" s="' . $s . '"/>';
            } elseif (is_float($val) || is_int($val)) {
                $xml .= '<c r="' . $col . '" s="' . $s . '"><v>' . $val . '</v></c>';
            } else {
                $xml .= '<c r="' . $col . '" t="inlineStr" s="' . $s . '"><is><t>'
                      . xlsx_escape((string)$val)
                      . '</t></is></c>';
            }
        }
        $xml .= '</row>';
    }

    $xml .= '</sheetData></worksheet>';
    return $xml;
}

// ── Pure-PHP ZIP builder ────────────────────────────────────────────────────
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
