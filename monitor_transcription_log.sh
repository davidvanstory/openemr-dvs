#!/bin/bash

echo "🎤 Transcription Log Monitor - Live Feed"
echo "========================================"
echo "📁 Monitoring: /tmp/ai_summary.log"
echo "⏹️  Press Ctrl+C to stop"
echo "🔄 Waiting for transcription activity..."
echo ""

# Create the log file if it doesn't exist
touch /tmp/ai_summary.log

# Use tail -f for real-time monitoring (clean output, logs already have timestamps)
tail -f /tmp/ai_summary.log
