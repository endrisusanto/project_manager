<?php
// export_handler.php - Export GBA Tasks Summary to standard XLSX (OpenXML format)
require_once "config.php";

$filename = "gba_tasks_summary_" . date('Ymd') . ".xlsx";

// Query SQL untuk mengambil semua data
$sql = "SELECT * FROM gba_tasks ORDER BY id DESC";
$result = $conn->query($sql);

$headers = [
    "ID",
    "Marketing Name",
    "Model Name",
    "AP",
    "CP",
    "CSC",
    "QB User",
    "QB Userdebug",
    "PIC Email",
    "Test Plan Type",
    "Progress Status",
    "Request Date",
    "Submission Date",
    "Approved Date",
    "Deadline",
    "Sign-Off Date",
    "Base Submission ID",
    "Submission ID",
    "Reviewer Email",
    "Urgent",
    "Notes",
    "Test Items Checklist"
];

$rows = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            $row['id'] ?? '',
            $row['project_name'] ?? '',
            $row['model_name'] ?? '',
            $row['ap'] ?? '',
            $row['cp'] ?? '',
            $row['csc'] ?? '',
            $row['qb_user'] ?? '',
            $row['qb_userdebug'] ?? '',
            $row['pic_email'] ?? '',
            $row['test_plan_type'] ?? '',
            $row['progress_status'] ?? '',
            $row['request_date'] ?? '',
            $row['submission_date'] ?? '',
            $row['approved_date'] ?? '',
            $row['deadline'] ?? '',
            $row['sign_off_date'] ?? '',
            $row['base_submission_id'] ?? '',
            $row['submission_id'] ?? '',
            $row['reviewer_email'] ?? '',
            (isset($row['is_urgent']) && ($row['is_urgent'] == 1 || $row['is_urgent'] === '1')) ? 'Yes' : 'No',
            $row['notes'] ?? '',
            $row['test_items_checklist'] ?? ''
        ];
    }
}

// Generate real XLSX using native ZipArchive
function generate_xlsx_stream($sheet_name, $headers, $rows, $download_filename) {
    if (!class_exists('ZipArchive')) {
        // Fallback to CSV if ZipArchive extension is missing
        header("Content-Disposition: attachment; filename=\"" . str_replace('.xlsx', '.csv', $download_filename) . "\"");
        header("Content-Type: text/csv; charset=UTF-8");
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM
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

    // 1. [Content_Types].xml
    $content_types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
        '</Types>';
    $zip->addFromString('[Content_Types].xml', $content_types);

    // 2. _rels/.rels
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);

    // 3. xl/_rels/workbook.xml.rels
    $wb_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wb_rels);

    // 4. xl/workbook.xml
    $safe_sheet_name = htmlspecialchars(substr($sheet_name, 0, 31), ENT_QUOTES | ENT_XML1, 'UTF-8');
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets>' .
        '<sheet name="' . $safe_sheet_name . '" sheetId="1" r:id="rId1"/>' .
        '</sheets>' .
        '</workbook>';
    $zip->addFromString('xl/workbook.xml', $workbook);

    // 5. xl/styles.xml (header: dark navy filled style s="1", normal cells: s="0")
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
        '<border>' .
        '<left style="thin"><color rgb="FFE2E8F0"/></left>' .
        '<right style="thin"><color rgb="FFE2E8F0"/></right>' .
        '<top style="thin"><color rgb="FFE2E8F0"/></top>' .
        '<bottom style="thin"><color rgb="FFE2E8F0"/></bottom>' .
        '</border>' .
        '</borders>' .
        '<cellStyleXfs count="1">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>' .
        '</cellStyleXfs>' .
        '<cellXfs count="2">' .
        '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>' .
        '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' .
        '</cellXfs>' .
        '</styleSheet>';
    $zip->addFromString('xl/styles.xml', $styles);

    // 6. xl/worksheets/sheet1.xml
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

    // Header row (r="1", style s="1")
    $sheet .= '<row r="1" ht="24" customHeight="1">';
    foreach ($headers as $c_idx => $header_text) {
        $cell_ref = $col_letter($c_idx) . '1';
        $sheet .= '<c r="' . $cell_ref . '" t="inlineStr" s="1"><is><t>' . $esc($header_text) . '</t></is></c>';
    }
    $sheet .= '</row>';

    // Data rows (r >= 2, style s="0")
    $row_num = 2;
    foreach ($rows as $row_data) {
        $sheet .= '<row r="' . $row_num . '">';
        foreach ($row_data as $c_idx => $val) {
            $cell_ref = $col_letter($c_idx) . $row_num;
            if (is_numeric($val) && strlen((string)$val) < 12 && !preg_match('/^0[0-9]/', (string)$val)) {
                $sheet .= '<c r="' . $cell_ref . '" t="n" s="0"><v>' . $val . '</v></c>';
            } else {
                $sheet .= '<c r="' . $cell_ref . '" t="inlineStr" s="0"><is><t>' . $esc($val) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
        $row_num++;
    }

    $sheet .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    // Stream download
    header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
    header("Content-Disposition: attachment; filename=\"$download_filename\"");
    header("Content-Length: " . filesize($temp_file));
    header("Cache-Control: max-age=0, no-cache, no-store, must-revalidate");
    header("Pragma: public");

    readfile($temp_file);
    @unlink($temp_file);
    exit();
}

generate_xlsx_stream("Tasks Summary", $headers, $rows, $filename);