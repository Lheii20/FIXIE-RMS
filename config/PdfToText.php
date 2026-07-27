<?php
/**
 * CRASH-PROOF Native PHP PDF Text Extractor
 * Extracts strings safely without using heavy PCRE regex on binary blobs.
 */
class PdfToText {
    public static function extract($filename) {
        $content = @file_get_contents($filename);
        if (!$content) return '';

        // SAFETY LIMIT: Basahin lang ang unang 2MB ng PDF
        $content = substr($content, 0, 2097152); 

        $text = '';
        $offset = 0;
        
        while (($start = strpos($content, 'stream', $offset)) !== false) {
            $start += 6; 
            $end = strpos($content, 'endstream', $start);
            if ($end === false) break;

            $stream = trim(substr($content, $start, $end - $start));
            $offset = $end + 9;

            $decoded = @gzuncompress($stream);
            if ($decoded === false) $decoded = @gzinflate($stream);
            if ($decoded === false) $decoded = @gzinflate(substr($stream, 2));

            if ($decoded) {
                // FAST & SAFE: Hatiin ang data gamit ang string functions imbes na Regex
                $parts = explode('(', $decoded);
                foreach ($parts as $part) {
                    $pos = strpos($part, ')');
                    if ($pos !== false) {
                        $inside = substr($part, 0, $pos);
                        // Isama lang kung may totoong letra (iwas binary junk)
                        if (preg_match('/[a-zA-Z]/', $inside)) {
                            $text .= stripslashes($inside) . " ";
                        }
                    }
                }
            }
        }
        
        // I-sanitize at i-limit sa 20,000 chars para magaan ipasa pabalik sa browser
        $clean_text = preg_replace('/[^\x20-\x7E]/', ' ', $text); 
        return strtolower(substr($clean_text, 0, 20000));
    }
}
?>