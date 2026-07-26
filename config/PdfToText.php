<?php
/**
 * CRASH-PROOF Native PHP PDF Text Extractor
 * Uses memory-safe string operations instead of heavy Regex to prevent PHP fatal errors.
 */
class PdfToText {
    public static function extract($filename) {
        $content = @file_get_contents($filename);
        if (!$content) return '';

        // SAFETY LIMIT: Basahin lang ang unang 2MB ng PDF para hindi maubos ang RAM ng server
        $content = substr($content, 0, 2097152); 

        $text = '';
        $offset = 0;
        
        // Memory-safe loop para hanapin ang mga streams
        while (($start = strpos($content, 'stream', $offset)) !== false) {
            $start += 6; // Lumagpas sa salitang 'stream'
            $end = strpos($content, 'endstream', $start);
            if ($end === false) break;

            $stream = trim(substr($content, $start, $end - $start));
            $offset = $end + 9; // Lumagpas sa 'endstream'

            // Subukang i-decompress nang mabilis
            $decoded = @gzuncompress($stream);
            if ($decoded === false) $decoded = @gzinflate($stream);
            if ($decoded === false) $decoded = @gzinflate(substr($stream, 2));

            if ($decoded) {
                // Kunin lang ang mga letters at numbers nang hindi ginagamit ang mabigat na Regex array
                $clean = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $decoded);
                $text .= " " . $clean;
            }
        }
        
        // Ibalik lang ang unang 20,000 characters para safe sa JSON encoding
        return strtolower(substr($text, 0, 20000));
    }
}
?>