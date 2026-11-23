<?php
// Minimal .xlsx writer (no library) + the payables workbook laid out like the team's
// "RM_PM_Inward_Outstanding_Tracker": Invoice Register, Party Outstanding, Dashboard.

const XLSX_STYLE = ['text' => 0, 'head' => 1, 'date' => 2, 'money' => 3, 'title' => 4, 'int' => 5, 'total' => 6];

function register_workbook(array $rows, string $filters): never
{
    [$t, $parties] = summarize($rows);
    $reg = [];
    foreach ($rows as $r) {
        $reg[] = [$r['invoice_date'], $r['invoice_no'] ?: '-', $r['party'], $r['amount'], $r['paid'], $r['outstanding'], $r['due_date'],
                  $r['days_overdue'], $r['pay_status'], $r['products'], $r['days_since_invoice'], ucfirst($r['status']), strtoupper($r['bill_type'])];
    }
    $sum = fn($k) => array_sum(array_map(fn($r) => paise($r[$k]), array_filter($rows, fn($r) => $r['status'] !== 'rejected'))) / 100;
    $reg[] = [['total', null], ['head', 'Total (excl. rejected)'], null, ['total', $sum('amount')], ['total', $sum('paid')], ['total', $sum('outstanding')]];

    $party = [];
    foreach ($parties as $name => $p) $party[] = [$name, $p['invoices'], $p['invoiced'], $p['paid'], $p['outstanding'], $p['overdue'], $p['oldest']];

    $sub = 'Generated ' . date('d M Y H:i') . ($filters !== '' ? " · Filters: $filters" : '');
    xlsx_download('Inward-Outstanding', [
        'Invoice Register' => ['title' => 'INWARD INVOICE & PAYMENT REGISTER', 'sub' => $sub,
            'widths' => [13, 18, 34, 15, 15, 15, 13, 9, 12, 28, 9, 11, 8],
            'blocks' => [[
                'head'  => ['Invoice Date', 'Invoice No.', 'Party Name / Number', 'Invoice Amount', 'Amount Paid', 'Outstanding', 'Due Date',
                            'Days Overdue', 'Payment Status', 'Remarks', 'Days Since Invoice', 'Approval', 'Bill'],
                'types' => ['date', 'text', 'text', 'money', 'money', 'money', 'date', 'int', 'text', 'text', 'int', 'text', 'text'],
                'rows'  => $reg]]],
        'Party Outstanding' => ['title' => 'PARTY-WISE OUTSTANDING', 'sub' => $sub, 'widths' => [36, 10, 18, 16, 18, 16, 12],
            'blocks' => [[
                'head'  => ['Party Name / Number', 'Invoice Count', 'Total Invoice Amount', 'Total Paid', 'Total Outstanding', 'Overdue Amount', 'Oldest Days Overdue'],
                'types' => ['text', 'int', 'money', 'money', 'money', 'money', 'int'],
                'rows'  => $party]]],
        'Dashboard' => ['title' => 'PAYABLES CONTROL DASHBOARD', 'sub' => $sub, 'widths' => [26, 18],
            'blocks' => [
                ['head' => ['Metric', 'Amount'], 'types' => ['text', 'money'], 'rows' => [
                    ['Total Invoiced', $t['invoiced']], ['Total Paid', $t['paid']], ['Total Outstanding', $t['outstanding']],
                    ['Overdue Outstanding', $t['overdue']], ['Invoices With Balance', ['int', $t['with_balance']]], ['Fully Paid Invoices', ['int', $t['fully_paid']]]]],
                ['head' => ['Outstanding Ageing', 'Amount'], 'types' => ['text', 'money'],
                 'rows' => array_map(fn($k, $label) => [$label, $t[$k]], array_keys(AGEING), AGEING)],
            ]],
    ]);
}

function xlsx_download(string $name, array $sheets): never
{
    if (!class_exists('ZipArchive')) fail('Excel export needs the PHP "zip" extension. Turn it on in hPanel → Advanced → PHP Configuration.');
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $ct = $wb = $rels = '';
    $i = 0;
    foreach ($sheets as $sheetName => $sh) {
        $i++;
        $zip->addFromString("xl/worksheets/sheet$i.xml", xlsx_sheet($sh));
        $ct   .= "<Override PartName=\"/xl/worksheets/sheet$i.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        $wb   .= '<sheet name="' . xe($sheetName) . "\" sheetId=\"$i\" r:id=\"rId$i\"/>";
        $rels .= "<Relationship Id=\"rId$i\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet$i.xml\"/>";
    }
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $zip->addFromString('[Content_Types].xml', $x . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $ct . '</Types>');
    $zip->addFromString('_rels/.rels', $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', $x . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wb . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels
        . '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    // style ids match XLSX_STYLE: 0 text, 1 header, 2 date, 3 money, 4 title, 5 integer, 6 bold money
    $zip->addFromString('xl/styles.xml', $x . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd-mmm-yyyy"/></numFmts>'
        . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9E1F2"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="7">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="4" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"$name-" . date('Y-m-d') . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

// sheet layout: title row 1, subtitle row 2, each block = header row + data rows, blocks 2 rows apart, data starts row 5
function xlsx_sheet(array $sh): string
{
    $xml = '';
    $put = function (int $r, array $cells) use (&$xml) {
        $x = '';
        foreach ($cells as $c => $cell) {
            if ($cell === null) continue;
            [$type, $v] = $cell;
            if ($v === null || $v === '') continue;
            $ref = xlsx_col($c) . $r;
            $s = XLSX_STYLE[$type];
            if ($type === 'date') $v = intdiv(strtotime("$v UTC"), 86400) + 25569; // Excel day number
            $x .= in_array($type, ['money', 'int', 'date', 'total'], true)
                ? "<c r=\"$ref\" s=\"$s\"><v>" . ($v + 0) . '</v></c>'
                : "<c r=\"$ref\" s=\"$s\" t=\"inlineStr\"><is><t xml:space=\"preserve\">" . xe((string) $v) . '</t></is></c>';
        }
        $xml .= "<row r=\"$r\">$x</row>";
    };
    $put(1, [['title', $sh['title']]]);
    $put(2, [['text', $sh['sub']]]);
    $r = 4;
    foreach ($sh['blocks'] as $b) {
        $put($r++, array_map(fn($h) => ['head', $h], $b['head']));
        foreach ($b['rows'] as $row) {
            $put($r++, array_map(fn($v, $c) => is_array($v) || $v === null ? $v : [$b['types'][$c], $v], $row, array_keys($row)));
        }
        $r += 2;
    }
    $cols = '';
    foreach ($sh['widths'] as $c => $w) $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . "\" width=\"$w\" customWidth=\"1\"/>";
    $freeze = count($sh['blocks']) === 1 ? '<pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/>' : '';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . "<sheetViews><sheetView workbookViewId=\"0\">$freeze</sheetView></sheetViews><cols>$cols</cols><sheetData>$xml</sheetData>"
        . '<mergeCells count="1"><mergeCell ref="A1:' . xlsx_col(count($sh['widths']) - 1) . '1"/></mergeCells></worksheet>';
}

function xlsx_col(int $i): string // 0 → A, 26 → AA
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

function xe(string $s): string
{
    return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_XML1 | ENT_QUOTES);
}
