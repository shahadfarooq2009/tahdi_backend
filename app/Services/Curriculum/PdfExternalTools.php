<?php

namespace App\Services\Curriculum;

use Illuminate\Support\Facades\Process;

class PdfExternalTools
{
    /**
     * @return array{tesseract: string|null, pdftoppm: string|null, pdftotext: string|null, pdfinfo: string|null}
     */
    public function resolve(): array
    {
        $tools = [
            'tesseract' => $this->resolveBinary('tesseract_path', 'tesseract', [
                'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
                'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
            ]),
            'pdftoppm' => $this->resolveBinary('pdftoppm_path', 'pdftoppm', [
                'C:\\poppler\\Library\\bin\\pdftoppm.exe',
                'C:\\Program Files\\poppler\\Library\\bin\\pdftoppm.exe',
                'C:\\Program Files\\poppler-24.08.0\\Library\\bin\\pdftoppm.exe',
                'C:\\xampp\\poppler\\Library\\bin\\pdftoppm.exe',
            ]),
            'pdftotext' => $this->resolveBinary('pdftotext_path', 'pdftotext', [
                'C:\\poppler\\Library\\bin\\pdftotext.exe',
                'C:\\Program Files\\poppler\\Library\\bin\\pdftotext.exe',
                'C:\\Program Files\\poppler-24.08.0\\Library\\bin\\pdftotext.exe',
                'C:\\xampp\\poppler\\Library\\bin\\pdftotext.exe',
            ]),
            'pdfinfo' => $this->resolveBinary('pdfinfo_path', 'pdfinfo', [
                'C:\\poppler\\Library\\bin\\pdfinfo.exe',
                'C:\\Program Files\\poppler\\Library\\bin\\pdfinfo.exe',
                'C:\\Program Files\\poppler-24.08.0\\Library\\bin\\pdfinfo.exe',
                'C:\\xampp\\poppler\\Library\\bin\\pdfinfo.exe',
            ]),
        ];

        if ($tools['pdfinfo'] === null && $tools['pdftotext'] !== null) {
            $tools['pdfinfo'] = $this->deriveSiblingBinary($tools['pdftotext'], 'pdftotext', 'pdfinfo');
        }

        if ($tools['pdftoppm'] === null && $tools['pdftotext'] !== null) {
            $tools['pdftoppm'] = $this->deriveSiblingBinary($tools['pdftotext'], 'pdftotext', 'pdftoppm');
        }

        return $tools;
    }

    public function pdfPageCount(string $absolutePdfPath): ?int
    {
        if (! is_file($absolutePdfPath)) {
            return null;
        }

        $pdfinfo = $this->resolve()['pdfinfo'];

        if ($pdfinfo !== null) {
            try {
                $result = Process::timeout(30)->run([$pdfinfo, $absolutePdfPath]);

                if ($result->successful() && preg_match('/^Pages:\s+(\d+)/m', $result->output(), $matches) === 1) {
                    return max(1, (int) $matches[1]);
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        return null;
    }

    public function ocrAvailable(): bool
    {
        $tools = $this->resolve();

        if ($tools['tesseract'] === null || $tools['pdftoppm'] === null) {
            return false;
        }

        return $this->tesseractListsArabic($tools['tesseract']);
    }

    public function tesseractListsArabic(?string $tesseractPath = null): bool
    {
        return $this->tesseractListsLanguage('ara', $tesseractPath);
    }

    public function tesseractListsLanguage(string $language, ?string $tesseractPath = null): bool
    {
        $tesseractPath ??= $this->resolve()['tesseract'];

        if ($tesseractPath === null) {
            return false;
        }

        try {
            $result = Process::timeout(10)->run([$tesseractPath, '--list-langs']);

            if (! $result->successful()) {
                return false;
            }

            $languages = preg_split('/\R+/', trim($result->output())) ?: [];

            foreach ($languages as $listed) {
                $listed = trim($listed);

                if ($listed === $language || str_starts_with($listed, $language)) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    public function resolveOcrLanguage(): string
    {
        $configured = (string) config('textbook_extraction.ocr_language', 'ara');
        $tesseract = $this->resolve()['tesseract'];

        if ($tesseract === null) {
            return $configured;
        }

        $hasArabic = $this->tesseractListsLanguage('ara', $tesseract);
        $hasEnglish = $this->tesseractListsLanguage('eng', $tesseract);

        if ($hasArabic && $hasEnglish) {
            return 'ara+eng';
        }

        if ($hasArabic) {
            return 'ara';
        }

        return $configured;
    }

    /**
     * @return list<string>
     */
    public function commonTesseractPaths(): array
    {
        return [
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
        ];
    }

    /**
     * @return list<string>
     */
    public function commonPopplerBinDirectories(): array
    {
        return [
            'C:\\poppler\\Library\\bin',
            'C:\\Program Files\\poppler\\Library\\bin',
            'C:\\Program Files\\poppler-24.08.0\\Library\\bin',
            'C:\\xampp\\poppler\\Library\\bin',
        ];
    }

    public function popplerTextAvailable(): bool
    {
        return $this->resolve()['pdftotext'] !== null;
    }

    /**
     * @param  list<string>  $commonPaths
     */
    private function resolveBinary(string $configKey, string $defaultName, array $commonPaths): ?string
    {
        $configured = config("textbook_extraction.{$configKey}");

        if (is_string($configured) && $configured !== '' && $this->isExecutable($configured)) {
            return $configured;
        }

        $which = $this->which($defaultName);
        if ($which !== null) {
            return $which;
        }

        foreach ($commonPaths as $path) {
            if ($this->isExecutable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function which(string $command): ?string
    {
        $finder = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';

        try {
            $result = Process::timeout(5)->run([$finder, $command]);

            if (! $result->successful()) {
                return null;
            }

            $line = trim(strtok($result->output(), PHP_EOL));

            return $line !== '' && $this->isExecutable($line) ? $line : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isExecutable(string $path): bool
    {
        return is_file($path) && is_readable($path);
    }

    private function deriveSiblingBinary(string $knownPath, string $knownBase, string $targetBase): ?string
    {
        $directory = dirname($knownPath);
        $extension = pathinfo($knownPath, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.'.$extension : '';

        $candidates = [
            $directory.DIRECTORY_SEPARATOR.$targetBase.$suffix,
            $directory.DIRECTORY_SEPARATOR.$targetBase.'.exe',
        ];

        foreach ($candidates as $candidate) {
            if ($this->isExecutable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
