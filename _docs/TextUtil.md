<?php

namespace OpenEMR\Common\Utils;

/**
 * Utility class for parsing medical transcripts and summaries into structured blocks
 * for evidence linking and highlighting features.
 */
class TextUtil
{
    /**
     * Splits a transcript into an array of conversation turns based on speaker changes.
     * Handles transcripts with title lines and indented dashes.
     *
     * @param string $transcript The full transcript text.
     * @return array An array of conversation turns.
     */
    public static function splitByConversationTurns(string $transcript): array
    {
        if (empty(trim($transcript))) {
            return [];
        }
        
        // Normalize line endings
        $normalized = str_replace(["\r\n", "\r"], "\n", $transcript);
        
        // Remove common title lines if present
        $normalized = preg_replace('/^.*(?:Transcription|Transcript|Visit Notes?).*\n/i', '', $normalized);
        
        // Split by dash at the beginning of a line (handles indentation)
        $turns = preg_split('/^\s*-\s*/m', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        
        // Clean up each turn
        $cleanedTurns = [];
        foreach ($turns as $turn) {
            $turn = trim($turn);
            if (!empty($turn)) {
                $cleanedTurns[] = $turn;
            }
        }
        
        return $cleanedTurns;
    }

    /**
     * Splits a structured medical summary into logical blocks.
     * Handles numbered sections, bullet points (•), and nested structures.
     * 
     * @param string $summaryText The AI-generated summary text.
     * @return array An array of summary blocks.
     */
    public static function splitSummaryIntoBlocks(string $summaryText): array
    {
        if (empty(trim($summaryText))) {
            return [];
        }
        
        // Normalize line endings
        $summaryText = str_replace(["\r\n", "\r"], "\n", $summaryText);
        
        $blocks = [];
        
        // Split by numbered section headers (e.g., "1. **History of Present Illness**")
        $sections = preg_split('/(?=^\d+\.\s*\*\*[^*]+\*\*)/m', $summaryText, -1, PREG_SPLIT_NO_EMPTY);
        
        foreach ($sections as $section) {
            $section = trim($section);
            if (empty($section)) continue;
            
            // Extract section header if present
            if (preg_match('/^(\d+\.\s*\*\*[^*]+\*\*)/m', $section, $matches)) {
                $header = trim($matches[1]);
                $blocks[] = $header;
                
                // Remove header from section content
                $section = trim(substr($section, strlen($matches[0])));
            }
            
            // Split remaining content by paragraphs
            $paragraphs = preg_split('/\n{2,}/', $section, -1, PREG_SPLIT_NO_EMPTY);
            
            foreach ($paragraphs as $paragraph) {
                $paragraph = trim($paragraph);
                if (empty($paragraph)) continue;
                
                // Check for bullet points (• or -)
                if (preg_match('/^\s*[•\-]/m', $paragraph)) {
                    // Split by bullet points, handling multi-line content
                    $items = preg_split('/\n(?=\s*[•\-])/', $paragraph, -1, PREG_SPLIT_NO_EMPTY);
                    
                    foreach ($items as $item) {
                        // Clean bullet marker and trim
                        $item = preg_replace('/^\s*[•\-]\s*/', '', trim($item));
                        if (!empty($item)) {
                            // Check for sub-sections within bullet (e.g., "Assessment:" or "Plan:")
                            if (preg_match('/^(\*\*[^*]+\*\*):(.*)$/s', $item, $subMatches)) {
                                // Split Assessment/Plan type entries
                                $blocks[] = trim($subMatches[1]) . ':';
                                $remainingContent = trim($subMatches[2]);
                                if (!empty($remainingContent)) {
                                    $blocks[] = $remainingContent;
                                }
                            } else {
                                $blocks[] = $item;
                            }
                        }
                    }
                } else {
                    // Regular paragraph or special format
                    // Check for system review format (e.g., "Respiratory: – Finding 1")
                    if (preg_match('/^([A-Z][a-z]+):\s*–/m', $paragraph)) {
                        $systems = preg_split('/\n(?=[A-Z][a-z]+:\s*–)/', $paragraph);
                        foreach ($systems as $system) {
                            $blocks[] = trim($system);
                        }
                    } else {
                        $blocks[] = $paragraph;
                    }
                }
            }
        }
        
        return $blocks;
    }

    /**
     * Alternative method to split summary preserving more structure.
     * Useful if you need to maintain section hierarchy.
     * 
     * @param string $summaryText The AI-generated summary text.
     * @return array Nested array with sections and their content blocks
     */
    public static function splitSummaryIntoSections(string $summaryText): array
    {
        if (empty(trim($summaryText))) {
            return [];
        }
        
        $summaryText = str_replace(["\r\n", "\r"], "\n", $summaryText);
        $sections = [];
        
        // Split by numbered sections
        $parts = preg_split('/(?=^\d+\.\s*\*\*[^*]+\*\*)/m', $summaryText, -1, PREG_SPLIT_NO_EMPTY);
        
        foreach ($parts as $part) {
            if (preg_match('/^(\d+)\.\s*\*\*([^*]+)\*\*\s*(.*)$/s', trim($part), $matches)) {
                $sectionNumber = $matches[1];
                $sectionTitle = $matches[2];
                $content = trim($matches[3]);
                
                $sections[] = [
                    'number' => $sectionNumber,
                    'title' => $sectionTitle,
                    'blocks' => self::parseSectionContent($content)
                ];
            }
        }
        
        return $sections;
    }

    /**
     * Helper to parse content within a section.
     * 
     * @param string $content Section content
     * @return array Array of content blocks
     */
    private static function parseSectionContent(string $content): array
    {
        $blocks = [];
        
        // Handle bullet points with • marker
        if (strpos($content, '•') !== false) {
            $items = preg_split('/\n(?=\s*•)/', $content, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($items as $item) {
                $item = preg_replace('/^\s*•\s*/', '', trim($item));
                if (!empty($item)) {
                    $blocks[] = $item;
                }
            }
        } else {
            // No bullets, treat as paragraph(s)
            $paragraphs = preg_split('/\n{2,}/', $content, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($paragraphs as $para) {
                if (!empty(trim($para))) {
                    $blocks[] = trim($para);
                }
            }
        }
        
        return $blocks;
    }

    /**
     * Validates the split data for medical summaries.
     * 
     * @param array $turns The conversation turns array
     * @param array $blocks The summary blocks array
     * @return bool True if data appears valid
     */
    public static function validateSplitConsistency(array $turns, array $blocks): bool
    {
        if (empty($turns)) {
            error_log("TextUtil Warning: No conversation turns found after splitting");
            return false;
        }
        
        if (empty($blocks)) {
            error_log("TextUtil Warning: No summary blocks found after splitting");
            return false;
        }
        
        // Medical summaries should have multiple sections
        $sectionHeaders = 0;
        foreach ($blocks as $block) {
            if (preg_match('/^\d+\.\s*\*\*[^*]+\*\*/', $block)) {
                $sectionHeaders++;
            }
        }
        
        if ($sectionHeaders < 3) {
            error_log("TextUtil Warning: Few section headers found ($sectionHeaders) - check summary format");
        }
        
        error_log("TextUtil: Split into " . count($turns) . " turns, " . count($blocks) . " blocks, $sectionHeaders sections");
        
        return true;
    }

    /**
     * Debug helper to visualize how text was split.
     * 
     * @param array $items The array of split items
     * @param string $label Label for the output
     */
    public static function debugPrintSplits(array $items, string $label = "Items"): void
    {
        echo "\n=== $label (" . count($items) . " items) ===\n";
        foreach ($items as $index => $item) {
            $preview = is_array($item) ? json_encode($item) : $item;
            echo "[$index]: " . substr($preview, 0, 100) . (strlen($preview) > 100 ? "..." : "") . "\n";
        }
        echo "=== End $label ===\n\n";
    }
}