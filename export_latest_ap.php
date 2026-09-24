<?php
// export_latest_ap.php - Export All AP to standard XLSX (OpenXML format)
require_once "config.php";

$filename = "gba_all_ap_summary_" . date('Ymd') . ".xlsx";

// Query SQL untuk mengambil semua data AP
$sql = "SELECT model_name, ap AS latest_ap, cp AS latest_cp, csc AS latest_csc
        FROM gba_tasks 
        WHERE ap IS NOT NULL AND ap != '' 
        ORDER BY model_name ASC, id DESC";
        
$result = $conn->query($sql);

$headers = ["Model Name", "AP Version", "CP Version", "CSC Version"];
$rows = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            $row['model_name'] ?? '',
            $row['latest_ap'] ?? '',
            $row['latest_cp'] ?? '',
            $row['latest_csc'] ?? ''
        ];
    }
}

if (!class_exists('ZipArchive')) {
    header("Content-Disposition: attachment; filename=\"" . str_replace('.xlsx', '.csv', $filename) . "\"");
    header("Content-Type: text/csv; charset=UTF-8");
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit();
}

$temp_file = tempnam(sys_get_temp_dir(), 'xlsx_');
$zip = new ZipArchive();
if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die("Error creating temporary archive for export.");
}

$content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
    '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
    '<Default Extension="xml" ContentType="application/xml"/>' .
    '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
    '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
    '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
    '</Types>';
$zip->addFromString('[Content_Types].xml', $content_types);

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
    '</Relationships>';
$zip->addFromString('_rels/.rels', $rels);

$wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
    '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
    '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
    '</Relationships>';
$zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);

$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
    '<sheets><sheet name="All AP Summary" sheetId="1" r:id="rId1"/></sheets>' .
    '</workbook>';
$zip->addFromString('xl/workbook.xml', $workbook);

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
    '<fonts count="2">' .
    '<font><sz val="10"/><name val="Segoe UI"/></font>' .
    '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>' .
    '</fonts>' .
    '<fills count="3">' .
    '<fill><patternFill patternType="none"/></fill>' .
    '<fill><patternFill patternType="gray125"/></fill>' .
    '<fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/></patternFill></fill>' .
    '</fills>' .
    '<borders count="2">' .
    '<border><left/><right/><top/><bottom/><diagonal/></border>' .
    '<border><left style="thin"><color rgb="FFE2E8F0"/></left><right style="thin"><color rgb="FFE2E8F0"/></right><top style="thin"><color rgb="FFE2E8F0"/></top><bottom style="thin"><color rgb="FFE2E8F0"/></bottom></border>' .
    '</borders>' .
    '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
    '<cellXfs count="2">' .
    '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>' .
    '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' .
    '</cellXfs>' .
    '</styleSheet>';
$zip->addFromString('xl/styles.xml', $styles);

$col_letter = function($index) {
    $name = '';
    $index++;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $name = chr(65 + $mod) . $name;
        $index = (int)(($index - $mod) / 26);
    }
    return $name;
};

$esc = function($val) {
    if ($val === null) return '';
    $str = (string)$val;
    $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $str);
    return htmlspecialchars($str, ENT_QUOTES | ENT_XML1, 'UTF-8');
};

$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
    '<sheetData>';

$sheet .= '<row r="1" ht="24" customHeight="1">';
foreach ($headers as $c_idx => $header_text) {
    $cell_ref = $col_letter($c_idx) . '1';
    $sheet .= '<c r="' . $cell_ref . '" t="inlineStr" s="1"><is><t>' . $esc($header_text) . '</t></is></c>';
}
$sheet .= '</row>';

$row_num = 2;
foreach ($rows as $row_data) {
    $sheet .= '<row r="' . $row_num . '">';
    foreach ($row_data as $c_idx => $val) {
        $cell_ref = $col_letter($c_idx) . $row_num;
        $sheet .= '<c r="' . $cell_ref . '" t="inlineStr" s="0"><is><t>' . $esc($val) . '</t></is></c>';
    }
    $sheet .= '</row>';
    $row_num++;
}

$sheet .= '</sheetData></worksheet>';
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
$zip->close();

header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Content-Length: " . filesize($temp_file));
header("Cache-Control: max-age=0, no-cache, no-store, must-revalidate");
header("Pragma: public");

readfile($temp_file);
@unlink($temp_file);
exit();

