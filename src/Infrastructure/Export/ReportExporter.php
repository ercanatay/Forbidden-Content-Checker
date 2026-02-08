<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Export;

use PDO;
use ZipArchive;

final class ReportExporter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $reportDir,
        private readonly string $appSecret
    ) {
    }

    /**
     * @return array{path: string, mime: string, name: string}
     */
    public function export(int $scanJobId, string $format): array
    {
        $data = $this->fetchData($scanJobId);
        $format = strtolower($format);

        return match ($format) {
            'csv' => $this->toCsv($scanJobId, $data),
            'json' => $this->toJson($scanJobId, $data),
            'xlsx' => $this->toXlsx($scanJobId, $data),
            'pdf' => $this->toSignedPdf($scanJobId, $data),
            default => throw new \RuntimeException('Unsupported report format.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchData(int $scanJobId): array
    {
        $jobStmt = $this->pdo->prepare('SELECT * FROM scan_jobs WHERE id = :id LIMIT 1');
        $jobStmt->execute([':id' => $scanJobId]);
        $job = $jobStmt->fetch();
        if (!$job) {
            throw new \RuntimeException('Scan job not found.');
        }

        $resultsStmt = $this->pdo->prepare(
            'SELECT sr.id, sr.target, sr.base_url, sr.status, sr.error_code, sr.error_message, sr.fetch_details_json,
                    sm.keyword, sm.title, sm.url, sm.source, sm.severity
             FROM scan_results sr
             LEFT JOIN scan_matches sm ON sm.scan_result_id = sr.id
             WHERE sr.scan_job_id = :scan_job_id
             ORDER BY sr.id ASC, sm.id ASC'
        );
        $resultsStmt->execute([':scan_job_id' => $scanJobId]);

        $rows = $resultsStmt->fetchAll() ?: [];
        return ['job' => $job, 'rows' => $rows];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{path: string, mime: string, name: string}
     */
    private function toCsv(int $scanJobId, array $data): array
    {
        $path = $this->reportDir . '/scan-' . $scanJobId . '.csv';
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Unable to create CSV report.');
        }

        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['scan_job_id', 'target', 'base_url', 'status', 'error_code', 'error_message', 'keyword', 'title', 'url', 'source', 'severity']);

        foreach ($data['rows'] as $row) {
            fputcsv($fh, [
                $scanJobId,
                $row['target'] ?? '',
                $row['base_url'] ?? '',
                $row['status'] ?? '',
                $row['error_code'] ?? '',
                $row['error_message'] ?? '',
                $row['keyword'] ?? '',
                $row['title'] ?? '',
                $row['url'] ?? '',
                $row['source'] ?? '',
                $row['severity'] ?? '',
            ]);
        }

        fclose($fh);

        return ['path' => $path, 'mime' => 'text/csv; charset=utf-8', 'name' => 'scan-' . $scanJobId . '.csv'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{path: string, mime: string, name: string}
     */
    private function toJson(int $scanJobId, array $data): array
    {
        $path = $this->reportDir . '/scan-' . $scanJobId . '.json';
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return ['path' => $path, 'mime' => 'application/json; charset=utf-8', 'name' => 'scan-' . $scanJobId . '.json'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{path: string, mime: string, name: string}
     */
    private function toXlsx(int $scanJobId, array $data): array
    {
        if (!class_exists(ZipArchive::class)) {
            return $this->toCsv($scanJobId, $data);
        }

        $path = $this->reportDir . '/scan-' . $scanJobId . '.xlsx';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create XLSX report.');
        }

        $sheetRows = [];
        $sheetRows[] = ['scan_job_id', 'target', 'base_url', 'status', 'error_code', 'error_message', 'keyword', 'title', 'url', 'source', 'severity'];
        foreach ($data['rows'] as $row) {
            $sheetRows[] = [
                (string) $scanJobId,
                (string) ($row['target'] ?? ''),
                (string) ($row['base_url'] ?? ''),
                (string) ($row['status'] ?? ''),
                (string) ($row['error_code'] ?? ''),
                (string) ($row['error_message'] ?? ''),
                (string) ($row['keyword'] ?? ''),
                (string) ($row['title'] ?? ''),
                (string) ($row['url'] ?? ''),
                (string) ($row['source'] ?? ''),
                (string) ($row['severity'] ?? ''),
            ];
        }

        $sheetXml = $this->buildSheetXml($sheetRows);

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Scan Report" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');

        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        return ['path' => $path, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'name' => 'scan-' . $scanJobId . '.xlsx'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{path: string, mime: string, name: string}
     */
    private function toSignedPdf(int $scanJobId, array $data): array
    {
        $path = $this->reportDir . '/scan-' . $scanJobId . '.pdf';

        $summary = [
            'scan_job_id' => $scanJobId,
            'status' => $data['job']['status'] ?? 'unknown',
            'targets' => (int) ($data['job']['target_count'] ?? 0),
            'finished_at' => $data['job']['finished_at'] ?? null,
            'match_rows' => count($data['rows'] ?? []),
        ];

        $signature = hash_hmac('sha256', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->appSecret);

        $lines = [
            'Forbidden Content Checker v3 - Signed Report',
            'Scan Job ID: ' . $scanJobId,
            'Status: ' . ($summary['status'] ?? 'unknown'),
            'Targets: ' . (string) $summary['targets'],
            'Match Rows: ' . (string) $summary['match_rows'],
            'Generated: ' . gmdate('c'),
            'Signature (HMAC-SHA256): ' . $signature,
        ];

        $pdf = $this->minimalPdf($lines);
        file_put_contents($path, $pdf);

        return ['path' => $path, 'mime' => 'application/pdf', 'name' => 'scan-' . $scanJobId . '.pdf'];
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function buildSheetXml(array $rows): string
    {
        $xmlRows = '';
        foreach ($rows as $index => $row) {
            $r = $index + 1;
            $xmlRows .= '<row r="' . $r . '">';
            foreach ($row as $colIndex => $cell) {
                $col = $this->columnName($colIndex + 1);
                $value = htmlspecialchars($cell, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xmlRows .= '<c r="' . $col . $r . '" t="inlineStr"><is><t>' . $value . '</t></is></c>';
            }
            $xmlRows .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>' . $xmlRows . '</sheetData>
</worksheet>';
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(($index % 26) + 65) . $name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    /**
     * @param array<int, string> $lines
     */
    private function minimalPdf(array $lines): string
    {
        $text = "BT\n/F1 12 Tf\n50 780 Td\n";
        foreach ($lines as $i => $line) {
            $safe = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            if ($i > 0) {
                $text .= "0 -18 Td\n";
            }
            $text .= '(' . $safe . ") Tj\n";
        }
        $text .= "ET";

        $objects = [];
        $objects[] = '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj';
        $objects[] = '2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj';
        $objects[] = '3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj';
        $objects[] = '4 0 obj << /Length ' . strlen($text) . ' >> stream\n' . $text . '\nendstream endobj';
        $objects[] = '5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $obj) {
            $offsets[] = strlen($pdf);
            $pdf .= $obj . "\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        }

        $pdf .= "trailer << /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

        return $pdf;
    }
}
