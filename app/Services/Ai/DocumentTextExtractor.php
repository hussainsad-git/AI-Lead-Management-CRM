<?php

namespace App\Services\Ai;

use App\Exceptions\DomainRuleException;
use Smalot\PdfParser\Parser as PdfParser;
use ZipArchive;

/** Plain-text extraction for knowledge-base uploads. No macros, scripts or formulas are executed. */
class DocumentTextExtractor
{
    public function extract(string $path, string $ext): string
    {
        $text = match ($ext) {
            'txt', 'md', 'csv', 'json' => (string) file_get_contents($path),
            'html', 'htm' => $this->html((string) file_get_contents($path)),
            'pdf' => (new PdfParser)->parseFile($path)->getText(),
            'docx' => $this->docx($path),
            default => throw new DomainRuleException('Unsupported file type.', 'kb_unsupported'),
        };
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[^\S\n]+/u', ' ', $text)));
    }

    private function html(string $html): string
    {
        $html = preg_replace('#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)[^>]*>#i', "\n", $html);

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function docx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new DomainRuleException('The .docx file could not be opened.', 'kb_unreadable');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new DomainRuleException('The .docx file has no document body.', 'kb_unreadable');
        }
        $xml = preg_replace('#</w:p>#', "\n\n", $xml);
        $xml = preg_replace('#<w:tab/>#', "\t", $xml);
        $xml = preg_replace('#<w:br[^>]*/>#', "\n", $xml);

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
