# Testing AI Evidence Linking Accuracy

## What We've Improved:

### 1. **Enhanced Validation Logging**
- Detailed analysis of first 5 mappings showing actual content
- Semantic validation that catches obvious mismatches
- Statistics on suspicious links detected

### 2. **Better Semantic Validation**
- Medical vs Lifestyle content mismatch detection
- Keyword overlap analysis with medical terms
- Word overlap percentage calculations

### 3. **Improved AI Prompt**
- Explicit warnings against poor semantic matches
- Examples of good vs bad mappings
- Validation checklist for the AI

## Testing Process:

1. **Run Link Evidence** - Press the "Relink Evidence" button
2. **Monitor Logs** - Watch `/tmp/ai_summary.log` for detailed analysis
3. **Check UI** - Test clicking summary blocks to see if links make sense

## Key Log Indicators:

### ✅ **Good Signs:**
```
📋 DETAILED MAPPING #1 ANALYSIS:
   📝 Summary Block 3: 'History of diabetes and hypertension'
   🎤 Linked to 1 transcript turns:
      Turn 4: 'you have a history of diabetes and hypertension'
      ✅ Shared medical terms: diabetes, hypertension
```

### ⚠️ **Warning Signs:**
```
📋 DETAILED MAPPING #2 ANALYSIS:
   📝 Summary Block 3: 'History of diabetes and hypertension'  
   🎤 Linked to 1 transcript turns:
      Turn 25: 'I have 2-6 glasses of wine a night'
      ⚠️ SUSPICIOUS: Medical summary (diabetes, hypertension) linked to lifestyle content (wine)
```

## Expected Improvements:

- **Fewer Suspicious Links**: Should see fewer `⚠️ SUSPICIOUS` warnings
- **Better Coverage**: Higher percentage of summary blocks accurately mapped
- **Semantic Relevance**: Summary blocks should link to semantically related transcript content

## If Issues Persist:

The validation system will now clearly identify problematic mappings. If you still see poor accuracy:

1. Check the `⚠️ Suspicious links detected` count
2. Review the detailed mapping analysis for specific problems
3. The system now prefers no links over bad links (empty arrays `[]`)

## Command to Monitor:
```bash
docker exec development-easy-openemr-1 tail -f /tmp/ai_summary.log | grep -E "(📋|⚠️|🎯)"
``` 