#!/bin/bash

# Simple watch script for transcription logs
watch -n 1 'echo "🎤 TRANSCRIPTION LOG - Last 20 entries (updates every 1 second)"; echo "================================================================"; tail -n 20 /tmp/transcription.log 2>/dev/null || echo "No transcription log yet - make a voice recording to start logging"' 