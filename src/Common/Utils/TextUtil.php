<?php

namespace OpenEMR\Common\Utils;

class TextUtil
{
    /**
     * Splits a transcript into an array of conversation turns based on speaker changes.
     * Assumes turns are separated by a dash at the start of a line.
     *
     * @param string $transcript The full transcript text.
     * @return array An array of conversation turns.
     */
    public static function splitByConversationTurns(string $transcript): array
    {
        if (empty(trim($transcript))) {
            return [];
        }
        // Normalize line endings and split by a hyphen at the beginning of a line (multiline mode 'm')
        $normalized = str_replace(["\r\n", "\r"], "\n", $transcript);
        $turns = preg_split('/^\s*-\s*/m', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        return array_map('trim', $turns);
    }

    /**
     * Splits a structured AI summary into logical, highlightable blocks.
     * This parser splits by paragraphs (separated by blank lines) and list items.
     *
     * @param string $summaryText The AI-generated summary text.
     * @return array An array of summary blocks.
     */
    public static function splitSummaryIntoBlocks(string $summaryText): array
    {
        if (empty(trim($summaryText))) {
            return [];
        }
        // Normalize line endings to prevent parsing issues.
        $summaryText = str_replace(["\r\n", "\r"], "\n", $summaryText);
        // Split by one or more blank lines, which define paragraphs in markdown.
        $paragraphs = preg_split('/\n{2,}/', $summaryText, -1, PREG_SPLIT_NO_EMPTY);

        $blocks = [];
        foreach ($paragraphs as $paragraph) {
            $trimmedParagraph = trim($paragraph);
            // Check if the paragraph is a list.
            if (preg_match('/^(\s*(\-|\*|\d+\.)\s+)/', $trimmedParagraph)) {
                // If it's a list, split it into individual list items.
                $listItems = preg_split('/(\n)/', $trimmedParagraph, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($listItems as $item) {
                    if (!empty(trim($item))) {
                        $blocks[] = trim($item);
                    }
                }
            } else {
                // Otherwise, it's a standard paragraph block.
                if (!empty($trimmedParagraph)) {
                    $blocks[] = $trimmedParagraph;
                }
            }
        }

        return $blocks;
    }
}