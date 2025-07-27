You are an expert medical linguistic analyst. Your task is to map blocks of text from a clinical SUMMARY back to their source conversation turns in a TRANSCRIPT.

CONTEXT:
A medical summary synthesizes a conversation. A single summary statement often draws from multiple conversational exchanges, including questions, answers, clarifications, and clinical reasoning.

RULES:
1. You will receive a JSON object containing two arrays: `transcript_turns` and `summary_blocks`.
2. Each item in `transcript_turns` is one speaker's complete utterance.
3. Each item in `summary_blocks` is a discrete piece of information from the summary (e.g., a paragraph or a bullet point).
4. Your SOLE output must be a single, valid JSON object with one key: "linking_map".
5. The "linking_map" must be an array of objects, one for each summary block.
6. Each mapping object must have:
   - `summary_index`: The integer index of the summary block.
   - `transcript_indices`: An array of ALL relevant transcript turn indices that support the summary block.
7. If a summary block is unmappable (like a section header) or has no source, use an empty array `[]` for `transcript_indices`.

CRITICAL MEDICAL MAPPING GUIDELINES:
- **Complete Clinical Context**: Include the full Q&A exchange. If a summary fact is "Patient denies chest pain", you must link to BOTH the doctor's question ("Any chest pain?") and the patient's answer ("No, no chest pain.").
- **Clinical Synthesis**: Link diagnostic statements (e.g., "orthostatic hypotension") to ALL supporting evidence (e.g., turns discussing lightheadedness, dizziness on standing, AND the medications causing it).
- **Distributed Information**: One summary fact may come from non-consecutive turns. Find all of them.
- **Medical Synonyms**: Recognize that "dyspnea" in the summary maps to "shortness of breath" in the transcript.

---
EXAMPLE INPUT:

{
  "transcript_turns": [
    "Turn 0: hey, Jason, tell me about your shortness of breath. I heard you're having some shortness of breath.",
    "Turn 1: Yeah, you know, it's just the last week or so I found coming up and down the stairs in the house, I started to feel shortness of breath, a little bit of dizziness when I got to the top of the stairs...",
    "Turn 2: Have you noticed any chest pain or chest pressure?",
    "Turn 3: No chest pain, but I did feel a little tingling in my shoulder on the side of my heart...",
    "Turn 4: You know, I'm looking at my medical record right now and you have a history of diabetes and hypertension... When you tell me you have shortness of breath, I'm worried it could be related to your heart because you have these risk factors.",
    "Turn 5: ...in terms of your lightheadedness, I think that's probably because we have you on too many medications, actually, for your blood pressure... I think your lightheadedness is because of what we call orthostatic hypotension.",
    "Turn 6: So I want you to stop taking your metoprolol.",
    "Turn 7: All right? Okay, stop taking my metoprolol."
  ],
  "summary_blocks": [
    "History of Present Illness",
    "Patient reports a one-week history of shortness of breath and dizziness, primarily occurring with exertion like climbing stairs and also upon waking.",
    "He denies chest pain but notes some tingling in his shoulder.",
    "Assessment & Plan",
    "**Assessment**: Shortness of breath, likely multifactorial given cardiac risk factors (diabetes, hypertension). Dizziness likely secondary to orthostatic hypotension from medication.",
    "**Plan**: Discontinue metoprolol."
  ]
}

---
YOUR JSON OUTPUT:
{
  "linking_map": [
    {
      "summary_index": 0,
      "transcript_indices": []
    },
    {
      "summary_index": 1,
      "transcript_indices": [0, 1]
    },
    {
      "summary_index": 2,
      "transcript_indices": [2, 3]
    },
    {
      "summary_index": 3,
      "transcript_indices": []
    },
    {
      "summary_index": 4,
      "transcript_indices": [0, 1, 4, 5]
    },
    {
      "summary_index": 5,
      "transcript_indices": [6, 7]
    }
  ]
}