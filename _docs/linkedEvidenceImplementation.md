# Linked Evidence - Detailed Implementation

### 1. Database Schema Update
A `JSON` column will be added to `form_ai_summary` to store the linking map.

**A. Modify Table Definition**
-   **File to Edit**: `interface/forms/ai_summary/table.sql`
-   **Change**: Add `linking_map_json JSON DEFAULT NULL COMMENT 'JSON array mapping summary blocks to transcript turns'` after `ai_summary`.

**B. Create Migration Script**
-   **New File**: `sql/ai_summary_linked_evidence_upgrade.sql`
-   **Purpose**: To update existing installations without data loss. The script will add the `linking_map_json` column if it does not exist.

### 2. Create Reusable Text Splitting Utility
To ensure consistency, all text splitting logic will live in a central utility class.

-   **New File**: `src/Common/Utils/TextUtil.php`
-   **Purpose**: Provides static methods for splitting transcripts and summaries.
-   **`splitByConversationTurns(string $transcript)`**: Splits transcript based on speaker turns (e.g., lines starting with `-`).
-   **`splitSummaryIntoBlocks(string $summaryText)`**: Splits summary text into paragraphs and list items.

### 3. AI Prompts
-   **New File**: `_docs/LinkedEvidencePrompt.md`.
-   **Purpose**: This prompt instructs the AI to receive structured transcript turns and summary blocks and return a JSON map linking them by index.

### 4. Backend Orchestration
A new `link_evidence.php` script will handle the linking task.

-   **New File**: `interface/forms/ai_summary/link_evidence.php`
-   **Purpose**: To coordinate the linking AI call, validation, and database updates.
-   **Key Functions**:
    *   A custom `log_link_evidence()` function for detailed logging to `/tmp/ai_summary.log` and the standard error log, matching the style of `generate_summary.php`.
    *   `makeOpenAICall()` helper function to interact with the OpenAI API.
-   **Logic**:
    1.  Validate CSRF token and `form_id`.
    2.  Fetch `voice_transcription` and `ai_summary` from `form_ai_summary` table.
    3.  Use `TextUtil` to split the transcript and summary.
    4.  **AI Call**: Generate linking map using `LinkedEvidencePrompt.md`.
    5.  Validate the indices in the returned map against the counts of turns/blocks.
    6.  Save the validated map to the `linking_map_json` column.
    7.  Return a success JSON response to the frontend, which will then trigger a page refresh.

### 5. Frontend Rendering and Interaction
The report view will be updated to support the new two-step, interactive UI.

-   **File to Edit**: `interface/forms/ai_summary/report.php`
-   **Purpose**: Display the side-by-side view and handle the two-button flow and click-to-highlight logic.
-   **Function `ai_summary_report()`**:
    *   Will now use `TextUtil` to split the summary and transcript.
    *   Renders two columns: one for summary blocks and one for transcript turns. Each element will have a unique ID (e.g., `summary-block-{$id}-{$index}`).
    *   Conditionally displays "Generate Summary" or "Link Evidence" button based on whether `ai_summary` and `linking_map_json` data exists.
-   **JavaScript Logic**:
    *   Handles AJAX calls to `generate_summary.php` and `link_evidence.php`.
    *   On success from either call, it reloads the page to display the new state (`window.parent.refreshVisitDisplay()`).
    *   If a `linking_map` is present on page load, it enables a click listener on the summary container for the highlighting interaction.
    *   Includes detailed `console.log` statements for debugging user interactions and API calls.
-   **CSS**: Simple CSS rules for the `.highlight` classes on summary and transcript elements.