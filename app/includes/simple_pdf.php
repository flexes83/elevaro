<?php
/**
 * Minimal native PDF renderer for printable teacher tests.
 * Keeps the project independent from browser print dialogs and external PHP extensions.
 */
class SimpleTeacherPdf
{
    private array $objects = [];
    private array $pages = [];
    private string $fontRegular;
    private string $fontBold;
    private float $width = 595.28;   // A4 portrait points
    private float $height = 841.89;
    private float $marginX = 42;
    private float $marginTop = 48;
    private float $marginBottom = 54;
    private float $y;
    private array $stream = [];
    private string $title;
    private string $classLabel;
    private int $pageNo = 0;

    public function __construct(string $title, string $classLabel)
    {
        $this->title = $title;
        $this->classLabel = $classLabel;
        $this->fontRegular = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $this->fontBold = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $this->startPage();
    }

    private function addObject(string $content): string
    {
        $this->objects[] = $content;
        return (string)count($this->objects);
    }

    private function startPage(): void
    {
        if ($this->pageNo > 0) {
            $this->finishPage();
        }
        $this->pageNo++;
        $this->stream = [];
        $this->y = $this->height - $this->marginTop;
        $this->header();
    }

    private function finishPage(): void
    {
        $this->footer();
        $content = implode("\n", $this->stream);
        $contentObj = $this->addObject('<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream");
        $pageObj = $this->addObject('<< /Type /Page /Parent 0 0 R /MediaBox [0 0 ' . $this->width . ' ' . $this->height . '] /Resources << /Font << /F1 ' . $this->fontRegular . ' 0 R /F2 ' . $this->fontBold . ' 0 R >> >> /Contents ' . $contentObj . ' 0 R >>');
        $this->pages[] = $pageObj;
    }

    private function esc(string $text): string
    {
        $text = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text) ?: $text;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    private function text(float $x, float $y, string $text, int $size = 11, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $this->stream[] = 'BT /' . $font . ' ' . $size . ' Tf ' . $x . ' ' . $y . ' Td (' . $this->esc($text) . ') Tj ET';
    }

    private function line(float $x1, float $y1, float $x2, float $y2, float $w = .7): void
    {
        $this->stream[] = $w . ' w ' . $x1 . ' ' . $y1 . ' m ' . $x2 . ' ' . $y2 . ' l S';
    }

    private function rect(float $x, float $y, float $w, float $h): void
    {
        $this->stream[] = '.8 w ' . $x . ' ' . $y . ' ' . $w . ' ' . $h . ' re S';
    }

    private function header(): void
    {
        $this->text($this->marginX, $this->y, $this->title, 22, true);
        $this->text($this->marginX, $this->y - 22, 'Klasse: ' . $this->classLabel, 11, false);
        $this->text($this->width - 220, $this->y - 22, 'Name:', 11, false);
        $this->line($this->width - 180, $this->y - 24, $this->width - $this->marginX, $this->y - 24, .5);
        $this->line($this->marginX, $this->y - 36, $this->width - $this->marginX, $this->y - 36, 1.2);
        $this->y -= 62;
    }

    private function footer(): void
    {
        $this->line($this->marginX, 36, $this->width - $this->marginX, 36, .4);
        $this->text($this->marginX, 22, 'bereitgestellt von elevaro', 9, false);
        $this->text($this->width - 90, 22, 'Seite ' . $this->pageNo, 9, false);
    }

    private function ensure(float $height): void
    {
        if ($this->y - $height < $this->marginBottom) {
            $this->startPage();
        }
    }

    private function wrap(string $text, int $fontSize, float $maxWidth): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') return [''];
        $words = preg_split('/\s+/u', $text) ?: [];
        $lines = [];
        $line = '';
        $avg = $fontSize * 0.52;
        $maxChars = max(18, (int)floor($maxWidth / $avg));
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (mb_strlen($candidate) > $maxChars && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines;
    }

    public function question(int $number, string $questionText, array $options = []): void
    {
        $questionLines = $this->wrap($number . '. ' . $questionText, 12, $this->width - 2 * $this->marginX);
        $needed = 18 + count($questionLines) * 15 + max(34, count($options) * 24) + 12;
        $this->ensure($needed);

        foreach ($questionLines as $line) {
            $this->text($this->marginX, $this->y, $line, 12, true);
            $this->y -= 15;
        }
        $this->y -= 4;

        if ($options) {
            foreach ($options as $option) {
                $this->rect($this->marginX, $this->y - 3, 10, 10);
                foreach ($this->wrap((string)$option, 10, $this->width - 2 * $this->marginX - 22) as $idx => $line) {
                    $this->text($this->marginX + 18, $this->y - ($idx * 12), $line, 10, false);
                }
                $this->y -= max(22, count($this->wrap((string)$option, 10, $this->width - 2 * $this->marginX - 22)) * 12 + 6);
            }
        } else {
            $this->rect($this->marginX, $this->y - 54, $this->width - 2 * $this->marginX, 54);
            $this->y -= 68;
        }
        $this->line($this->marginX, $this->y, $this->width - $this->marginX, $this->y, .35);
        $this->y -= 18;
    }

    public function output(string $filename): void
    {
        $this->finishPage();
        $kids = implode(' ', array_map(static fn($id) => $id . ' 0 R', $this->pages));
        $pagesObjNumber = count($this->objects) + 1;
        foreach ($this->objects as &$obj) {
            $obj = str_replace('/Parent 0 0 R', '/Parent ' . $pagesObjNumber . ' 0 R', $obj);
        }
        unset($obj);
        $this->objects[] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($this->pages) . ' >>';
        $catalogObjNumber = count($this->objects) + 1;
        $this->objects[] = '<< /Type /Catalog /Pages ' . $pagesObjNumber . ' 0 R >>';

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($this->objects as $i => $object) {
            $offsets[$i + 1] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($this->objects) + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($this->objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($this->objects) + 1) . " /Root " . $catalogObjNumber . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }
}
