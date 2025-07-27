<?php

namespace OpenEMR\Common\Utils;

class TextUtil
{
    /**
     * Splits a transcript into an array of conversation turns based on speaker changes.
     * Assumes turns are separated by a dash, e.g., "- text...".
     *
     * @param string $transcript The full transcript text.
     * @return array An array of conversation turns.
     */
    public static function splitByConversationTurns(string $transcript): array
    {
        // Split on the dash markers that indicate speaker changes, handling surrounding whitespace.
        $turns = preg_split('/\\s*-\\s*/', $transcript, -1, PREG_SPLIT_NO_EMPTY);
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
        // Normalize line endings to prevent parsing issues.
        $summaryText = str_replace(["\r\n", "\r"], "\n", $summaryText);
        // Split by one or more blank lines, which define paragraphs in markdown.
        $paragraphs = preg_split('/\n{2,}/', $summaryText, -1, PREG_SPLIT_NO_EMPTY);

        $blocks = [];
        foreach ($paragraphs as $paragraph) {
            // Check if the paragraph is a list.
            if (preg_match('/^(\s*(\-|\*|\d+\.)\s+)/', $paragraph)) {
                // If it's a list, split it into individual list items.
                $listItems = preg_split('/(\n)/', $paragraph, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($listItems as $item) {
                    if (!empty(trim($item))) {
                        $blocks[] = trim($item);
                    }
                }
            } else {
                // Otherwise, it's a standard paragraph block.
                if (!empty(trim($paragraph))) {
                    $blocks[] = trim($paragraph);
                }
            }
        }

        return $blocks;
    }
}