<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Db;
use App\Services\XlsxReader;
use Tests\Support\TestCase;

final class XlsxImportTest extends TestCase
{
    /** Build a minimal .xlsx (deflated entries, shared + inline strings) without ext-zip. */
    private function xlsx(array $rows, bool $deflate = true): string
    {
        $strings = [];
        $sheetRows = '';
        foreach ($rows as $r => $row) {
            $cells = '';
            foreach ($row as $c => $val) {
                $ref = chr(65 + $c) . ($r + 1);
                if (is_int($val) || is_float($val)) {
                    $cells .= "<c r=\"$ref\"><v>" . (is_float($val) ? sprintf('%.15G', $val) : $val) . '</v></c>';
                } elseif (str_starts_with((string) $val, 'INLINE:')) {
                    $cells .= "<c r=\"$ref\" t=\"inlineStr\"><is><t>" . htmlspecialchars(substr($val, 7)) . '</t></is></c>';
                } elseif ($val !== '') {
                    $strings[] = $val;
                    $cells .= "<c r=\"$ref\" t=\"s\"><v>" . (count($strings) - 1) . '</v></c>';
                }
            }
            $sheetRows .= '<row r="' . ($r + 1) . "\">$cells</row>";
        }
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/></Types>',
            'xl/workbook.xml' => "<?xml version=\"1.0\"?><workbook xmlns=\"$ns\" xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\"><sheets><sheet name=\"Guests\" sheetId=\"1\" r:id=\"rId1\"/></sheets></workbook>",
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => "<?xml version=\"1.0\"?><worksheet xmlns=\"$ns\"><sheetData>$sheetRows</sheetData></worksheet>",
            'xl/sharedStrings.xml' => "<?xml version=\"1.0\"?><sst xmlns=\"$ns\">" . implode('', array_map(fn ($s) => '<si><t>' . htmlspecialchars($s) . '</t></si>', $strings)) . '</sst>',
        ];
        $zip = '';
        $cd = '';
        $n = 0;
        foreach ($files as $name => $data) {
            $comp = $deflate ? gzdeflate($data) : $data;
            $off = strlen($zip);
            $zip .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, $deflate ? 8 : 0, 0, 0, crc32($data), strlen($comp), strlen($data), strlen($name), 0) . $name . $comp;
            $cd .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, $deflate ? 8 : 0, 0, 0, crc32($data), strlen($comp), strlen($data), strlen($name), 0, 0, 0, 0, 0, $off) . $name;
            $n++;
        }
        return $zip . $cd . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($cd), strlen($zip), 0);
    }

    public function testReaderHandlesSharedInlineNumericAndSparseCells(): void
    {
        $path = sys_get_temp_dir() . '/t-' . bin2hex(random_bytes(3)) . '.xlsx';
        file_put_contents($path, $this->xlsx([['Name', 'Email', 'Phone'], ['Asha Rao', 'INLINE:asha@example.test', 9.19876543210E+11], ['', 'ravi@example.test'], [], ['Priya', '', 42]]));
        $rows = XlsxReader::rows($path);
        $this->assertSame(['Name', 'Email', 'Phone'], $rows[0]);
        $this->assertSame(['Asha Rao', 'asha@example.test', '919876543210'], $rows[1], 'numeric phone read as digits');
        $this->assertSame(['', 'ravi@example.test'], $rows[2], 'sparse row keeps column positions');
        $this->assertSame(4, count($rows), 'blank rows removed');
        $this->assertSame(['Priya', '', '42'], $rows[3]);
        file_put_contents($path, $this->xlsx([['A', 'B'], ['x', 'y']], false));
        $this->assertSame(['x', 'y'], XlsxReader::rows($path)[1], 'stored (uncompressed) entries too');
        @unlink($path);
    }

    public function testReaderRejectsCorruptAndHostileFiles(): void
    {
        $path = sys_get_temp_dir() . '/bad-' . bin2hex(random_bytes(3)) . '.xlsx';
        foreach (['not a zip at all', "PK\x05\x06" . str_repeat("\0", 18), substr($this->xlsx([['a']]), 0, 60)] as $junk) {
            file_put_contents($path, $junk);
            try {
                XlsxReader::rows($path);
                $this->fail('should reject');
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
        @unlink($path);
    }

    public function testUploadingAnExcelFileImportsThroughTheSameBackgroundPipeline(): void
    {
        [$host] = $this->newHost();
        $event = $this->newEvent($host);
        $base = '/app/events/' . $event['uuid'];
        $e1 = 'x1' . bin2hex(random_bytes(3)) . '@example.test';
        $path = sys_get_temp_dir() . '/g-' . bin2hex(random_bytes(3)) . '.xlsx';
        file_put_contents($path, $this->xlsx([['First name', 'Last name', 'Email', 'Mobile'], ['Meera', 'Das', $e1, '+919800000010'], ['Karan', 'Shah', 'INLINE:k' . bin2hex(random_bytes(3)) . '@example.test', 9800000011]]));
        $form = $host->get($base . '/guests/import');
        $r = $host->upload($base . '/guests/import', 'file', $path, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ['_csrf' => $form->csrf()]);
        $this->assertRedirect($base . '/guests/import/', $r);
        $importUrl = $r->location();
        $page = $host->get($importUrl);
        $this->assertContains('Match your columns', $page->body);
        $host->post($importUrl, ['_csrf' => $page->csrf(), 'map' => ['first_name' => '0', 'last_name' => '1', 'email' => '2', 'phone' => '3'], 'on_duplicate' => 'skip']);
        $this->runWorker();
        $this->assertSame(2, (int) Db::value('SELECT created_rows FROM imports WHERE event_id = ?', [$event['id']]));
        $this->assertSame('Meera', Db::value('SELECT g.first_name FROM guests g JOIN event_guests eg ON eg.guest_id = g.id WHERE g.email = ?', [$e1]));
        // A renamed text file is refused.
        file_put_contents($path, 'plain text');
        $form = $host->get($base . '/guests/import');
        $this->assertRedirect($base . '/guests/import', $host->upload($base . '/guests/import', 'file', $path, 'application/octet-stream', ['_csrf' => $form->csrf()]));
        @unlink($path);
    }
}