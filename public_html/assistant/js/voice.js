/**
 * Voice Helper - Speech Recognition (STT) and Synthesis (TTS)
 * Telugu primary, with Hindi and English fallbacks
 * Comprehensive translations for all hotel operations
 */
class VoiceController {
  constructor() {
    this.recognition = null;
    this.synthesis = window.speechSynthesis;
    this.isRecording = false;
    this.voicesLoaded = false;
    this.pendingSpeak = null;
    this.teluguVoice = null;
    this.englishVoice = null;
    this.hasTeluguVoice = false;
    this.currentLang = 'en-IN'; // Default to English (India)
    this.voiceEnabled = false; // Disabled audio notifications by default so the system doesn't speak aloud
    
    // Load saved voice preference
    const savedVoiceEnabled = localStorage.getItem('pms_voice_enabled');
    if (savedVoiceEnabled !== null) this.voiceEnabled = savedVoiceEnabled === 'true';
    const savedLang = localStorage.getItem('pms_voice_lang');
    if (savedLang) this.currentLang = savedLang;

    // Initialize Speech Recognition
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (SpeechRecognition) {
      this.recognition = new SpeechRecognition();
      this.recognition.continuous = false;
      this.recognition.interimResults = false;
      this.recognition.lang = this.currentLang;
    }

    // Load voices asynchronously
    if (this.synthesis) {
      this._loadVoices();
      if (this.synthesis.onvoiceschanged !== undefined) {
        this.synthesis.onvoiceschanged = () => this._loadVoices();
      }
    }
  }

  /**
   * Set voice language
   */
  setLanguage(lang) {
    this.currentLang = lang;
    localStorage.setItem('pms_voice_lang', lang);
    this._loadVoices(); // Reload voices for new language
    if (window.VoiceCommands) {
        window.VoiceCommands.setLanguage(lang);
    }
  }

  /**
   * Toggle voice on/off
   */
  toggle() {
    this.voiceEnabled = !this.voiceEnabled;
    localStorage.setItem('pms_voice_enabled', this.voiceEnabled ? 'true' : 'false');
    return this.voiceEnabled;
  }

  /**
   * Load and cache available voices
   */
  _loadVoices() {
    const voices = this.synthesis.getVoices();
    if (voices.length === 0) return;

    this.voicesLoaded = true;
    
    // Find Telugu voice
    this.teluguVoice = voices.find(v => v.lang.includes('te-IN') || v.lang.includes('te_IN')) || null;
    this.hasTeluguVoice = this.teluguVoice !== null;
    
    // Find Hindi voice as fallback
    this.hindiVoice = voices.find(v => v.lang.includes('hi-IN') || v.lang.includes('hi_IN')) || null;
    
    // Find English voice
    this.englishVoice = voices.find(v => v.lang.includes('en-IN') || v.lang.includes('en-US') || v.lang.includes('en-GB')) || null;

    console.log('[Voice] Loaded:', {
      telugu: this.teluguVoice?.name || 'NOT FOUND',
      hindi: this.hindiVoice?.name || 'NOT FOUND',
      english: this.englishVoice?.name || 'NOT FOUND',
      total: voices.length
    });

    // Process pending speak
    if (this.pendingSpeak) {
      const { text } = this.pendingSpeak;
      this.pendingSpeak = null;
      this.speak(text);
    }
  }

  /**
   * Translate and speak text
   */
  speak(text) {
    if (!this.synthesis || !this.voiceEnabled || !text || text.trim() === '') return;
    
    this.synthesis.cancel();

    if (!this.voicesLoaded) {
      this.pendingSpeak = { text };
      this.synthesis.getVoices();
      setTimeout(() => {
        if (this.pendingSpeak) {
          this.voicesLoaded = true;
          const pending = this.pendingSpeak;
          this.pendingSpeak = null;
          this._doSpeak(pending.text);
        }
      }, 500);
      return;
    }

    this._doSpeak(text);
  }

  /**
   * Internal: Perform speech synthesis
   */
  _doSpeak(text) {
    const utterance = new SpeechSynthesisUtterance();
    
    if (this.teluguVoice && this.hasTeluguVoice) {
      // Speak Telugu with Telugu voice
      utterance.text = this.translateToTelugu(text);
      utterance.voice = this.teluguVoice;
      utterance.lang = this.teluguVoice.lang;
      utterance.rate = 0.85;
    } else if (this.hindiVoice) {
      // Fallback: Speak Telugu translation with Hindi voice (close enough)
      utterance.text = this.translateToTelugu(text);
      utterance.voice = this.hindiVoice;
      utterance.lang = this.hindiVoice.lang;
      utterance.rate = 0.85;
    } else if (this.englishVoice) {
      // Fallback: Speak English with English voice
      utterance.text = text;
      utterance.voice = this.englishVoice;
      utterance.lang = this.englishVoice.lang;
      utterance.rate = 0.90;
    } else {
      // Last resort: default voice
      utterance.text = text;
      utterance.lang = 'en-IN';
      utterance.rate = 0.90;
    }
    
    utterance.pitch = 1.0;
    
    try {
      this.synthesis.speak(utterance);
    } catch (e) {
      console.warn("[Voice] Speech synthesis failed", e);
    }
  }

  /**
   * Speak number in Telugu (for amounts, room numbers)
   */
  speakNumber(num) {
    if (!this.hasTeluguVoice) {
      this.speak(num.toString());
      return;
    }
    
    // Telugu number translations
    const teluguNums = {
      0: 'సున్నా', 1: 'ఒకటి', 2: 'రెండు', 3: 'మూడు', 4: 'నాలుగు',
      5: 'ఐదు', 6: 'ఆరు', 7: 'ఏడు', 8: 'ఎనిమిది', 9: 'తొమ్మిది',
      10: 'పది', 11: 'పదకొండు', 12: 'పన్నెండు', 13: 'పదమూడు', 14: 'పద్నాలుగు',
      15: 'పదిహేను', 16: 'పదహారు', 17: 'పదిహేడు', 18: 'పద్దెనిమిది', 19: 'పందొమ్మిది',
      20: 'ఇరవై', 30: 'ముప్పై', 40: 'నలభై', 50: 'యాభై',
      60: 'అరవై', 70: 'డెబ్బై', 80: 'ఎనభై', 90: 'తొంభై',
      100: 'నూరు', 1000: 'వెయ్యి', 100000: 'లక్ష'
    };
    
    if (num <= 20 || teluguNums[num]) {
      this.speak(teluguNums[num] || num.toString());
    } else {
      // For larger numbers, speak digit by digit
      this.speak(num.toString().split('').map(d => teluguNums[parseInt(d)] || d).join(' '));
    }
  }

  /**
   * Translate English to Telugu
   */
  translateToTelugu(text) {
    if (!text) return '';
    let t = text.trim();

    // Static translations for all hotel operations
    const staticMap = {
      // Login
      "Please enter your PIN code.": "దయచేసి మీ పిన్ కోడ్ నమోదు చేయండి.",
      "Incorrect PIN. Try again.": "తప్పు పిన్. మళ్ళీ ప్రయత్నించండి.",
      "Login PIN updated successfully.": "లాగిన్ పిన్ విజయవంతంగా నవీకరించబడింది.",
      
      // Dashboard
      "Welcome to Hotel Assistant. You have": "హోటల్ అసిస్టెంట్‌కు స్వాగతం. మీకు ఉంది",
      "available rooms.": "అందుబాటులో ఉన్న గదులు.",
      "occupied rooms.": "ఆక్రమించబడిన గదులు.",
      "rooms need cleaning.": "శుభ్రం చేయాల్సిన గదులు.",
      "pending payments.": "పెండింగ్ చెల్లింపులు.",
      "No alerts. All good!": "హెచ్చరికలు లేవు. అన్నీ బాగున్నాయి!",
      
      // Booking Wizard
      "New Booking. Step 1. Please search by mobile number or speak the guest's name.": "కొత్త బుకింగ్. మొదటి అడుగు. దయచేసి మొబైల్ నంబర్ ద్వారా వెతకండి లేదా అతిథి పేరు చెప్పండి.",
      "Step 1. Search for a guest by name or mobile number.": "మొదటి అడుగు. అతిథి పేరు లేదా మొబైల్ నంబర్ ద్వారా వెతకండి.",
      "Step 2. Please enter guest mobile number and name.": "రెండవ అడుగు. దయచేసి అతిథి మొబైల్ నంబర్ మరియు పేరు నమోదు చేయండి.",
      "Step 3. Identity Verification. Scan Aadhaar card or choose to skip.": "మూడవ అడుగు. గుర్తింపు ధృవీకరణ. ఆధార్ కార్డును స్కాన్ చేయండి లేదా దాటవేయండి.",
      "Step 4. Select check-in date and time.": "నాల్గవ అడుగు. చెక్-ఇన్ తేదీ మరియు సమయాన్ని ఎంచుకోండి.",
      "Step 5. Select check-out date and time.": "ఐదవ అడుగు. చెక్-అవుట్ తేదీ మరియు సమయాన్ని ఎంచుకోండి.",
      "Step 6. Select guest counts.": "ఆరవ అడుగు. అతిథుల సంఖ్యను ఎంచుకోండి.",
      "Step 7. Select an available room.": "ఏడవ అడుగు. అందుబాటులో ఉన్న గదిని ఎంచుకోండి.",
      "Step 8. Select rate plan.": "ఎనిమిదవ అడుగు. రేట్ ప్లాన్‌ను ఎంచుకోండి.",
      "Step 9. Modify price or apply discount.": "తొమ్మిదవ అడుగు. ధరను మార్చండి లేదా తగ్గింపును వర్తింపజేయండి.",
      "Step 10. Settle advance payment.": "పదవ అడుగు. అడ్వాన్స్ పేమెంట్ చెల్లించండి.",
      "Step 11. Check details and confirm booking.": "పదకొండవ అడుగు. వివరాలను తనిఖీ చేసి బుకింగ్‌ను నిర్ధారించండి.",
      "No guest records found. Create a new guest.": "అతిథి రికార్డులు ఏవీ కనుగొనబడలేదు. కొత్త అతిథిని సృష్టించండి.",
      "Please enter a valid mobile number.": "దయచేసి సరైన మొబైల్ నంబర్ నమోదు చేయండి.",
      "Please speak the guest name.": "దయచేసి అతిథి పేరు చెప్పండి.",
      "Please capture the front of the ID card.": "దయచేసి గుర్తింపు కార్డు ముందు భాగాన్ని ఫోటో తీయండి.",
      "Verification skipped. Enter stay details.": "ధృవీకరణ దాటవేయబడింది. బస వివరాలను నమోదు చేయండి.",
      "Price override active. Please enter custom rate.": "ధర సవరణ యాక్టివ్‌గా ఉంది. దయచేసి అనుకూల రేటును నమోదు చేయండి.",
      "Checkout date must be after check-in date.": "చెక్-అవుట్ తేదీ తప్పనిసరిగా చెక్-ఇన్ తేదీ తర్వాతే ఉండాలి.",
      "Booking completed successfully. Displaying Receipt.": "బుకింగ్ విజయవంతంగా పూర్తయింది. రశీదును ప్రదర్శిస్తున్నాము.",
      
      // Check-in/Check-out
      "Checkout processed successfully.": "చెక్-అవుట్ విజయవంతంగా పూర్తయింది.",
      "Stay extended successfully.": "బస విజయవంతంగా పొడిగించబడింది.",
      "Extra charge is": "అదనపు ఛార్జీ",
      
      // Housekeeping
      "Please say the room number to mark as clean.": "దయచేసి క్లీన్ చేయవలసిన గది నంబర్ చెప్పండి.",
      "I did not catch the room number.": "నేను గది నంబర్ గ్రహించలేకపోయాను.",
      
      // Payments
      "Collected successfully.": "విజయవంతంగా సేకరించబడింది.",
      "Payment recorded.": "చెల్లింపు నమోదు చేయబడింది.",
      
      // Errors
      "Connection error. Please try again.": "కనెక్షన్ లోపం. దయచేసి మళ్ళీ ప్రయత్నించండి.",
      "Something went wrong.": "ఏదో తప్పు జరిగింది.",
      "Please try again.": "దయచేసి మళ్ళీ ప్రయత్నించండి.",
      "Are you sure?": "మీరు ఖచ్చితంగా అనుకుంటున్నారా?",
      "Yes": "అవును",
      "No": "కాదు",
      "Confirm": "నిర్ధారించండి",
      "Cancel": "రద్దు చేయండి",
      
      // ID Verification
      "ID proof uploaded successfully.": "గుర్తింపు కార్డు విజయవంతంగా అప్‌లోడ్ చేయబడింది.",
      "Identity verified.": "గుర్తింపు ధృవీకరించబడింది.",
      "Warning. Names do not match.": "హెచ్చరిక. పేర్లు సరిపోలడం లేదు.",
      
      // Quick checkout durations
      "2 hours": "2 గంటలు",
      "4 hours": "4 గంటలు",
      "6 hours": "6 గంటలు",
      "10 hours": "10 గంటలు",
      "1 day": "1 రోజు",
      "2 days": "2 రోజులు",
      "3 days": "3 రోజులు",
    };

    if (staticMap[t]) return staticMap[t];

    // Dynamic translations with regex
    t = t.replace(/^Welcome back (.*?)\.$/i, "తిరిగి స్వాగతం $1.");
    t = t.replace(/^Marking room (.*?) clean\.$/i, "గది $1 క్లీన్ గా మార్చబడింది.");
    t = t.replace(/^Room (.*?) is not dirty or not found\.$/i, "గది $1 డర్టీగా లేదు లేదా కనుగొనబడలేదు.");
    t = t.replace(/^Found (\d+) guest records\. Select one to proceed\.$/i, "$1 అతిథి రికార్డులు కనుగొనబడ్డాయి. కొనసాగడానికి ఒకదాన్ని ఎంచుకోండి.");
    t = t.replace(/^Selected guest (.*?)\. ID already verified\.$/i, "అతిథి $1 ఎంపిక చేయబడ్డారు. గుర్తింపు ఇప్పటికే ధృవీకరించబడింది.");
    t = t.replace(/^Selected guest (.*?)\. ID proof required\.$/i, "అతిథి $1 ఎంపిక చేయబడ్డారు. గుర్తింపు కార్డు అవసరం.");
    t = t.replace(/^Selected Room (.*?)\.$/i, "గది $1 ఎంపిక చేయబడింది.");
    t = t.replace(/^Selected (.*?) rate plan\. Total ₹(.*?)\.$/i, "$1 రేట్ ప్లాన్ ఎంపిక. మొత్తం ₹$2.");
    t = t.replace(/^(.*?) checked in to Room (.*?) successfully\.$/i, "$1 విజయవంతంగా గది $2 లోకి చెక్-ఇన్ అయ్యారు.");
    t = t.replace(/^Room (.*?) has pending dues of ₹(.*?)\.$/i, "గది $1 కి ₹$2 బకాయి ఉంది.");
    t = t.replace(/^Dues settled\. Ready to checkout Room (.*?)\.$/i, "బకాయిలు చెల్లించబడ్డాయి. గది $1 చెక్-అవుట్ చేయడానికి సిద్ధం.");
    t = t.replace(/^Collected ₹(.*?) successfully\.$/i, "₹$1 విజయవంతంగా సేకరించబడింది.");
    t = t.replace(/^Room (.*?) is clean\.$/i, "గది $1 క్లీన్ గా ఉంది.");
    t = t.replace(/^Booking (.*?) created\.$/i, "బుకింగ్ $1 సృష్టించబడింది.");

    return t;
  }

  /**
   * Speech-to-Text — robust implementation with fresh instance per session
   * Uses auto-restart loop to prevent mobile browser premature stops.
   */
  startListening(onResult, onStart, onError, onEnd) {
    // Prevent double-tap triggering
    const now = Date.now();
    if (this.lastStartTimestamp && (now - this.lastStartTimestamp < 1000)) {
      console.log('[Voice] Blocked rapid double-trigger.');
      return;
    }
    this.lastStartTimestamp = now;

    // If already recording, stop it
    if (this.isRecording) {
      this._abortListening();
      return;
    }

    // Pause global continuous VoiceCommands to prevent mic capture conflict
    if (window.VoiceCommands && window.VoiceCommands.isListening) {
      window.VoiceCommands.stop();
    }

    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
      console.warn('[Voice] Speech recognition not supported');
      if (window.app) window.app.showToast('Voice input not available on this device', 'info');
      return;
    }

    // Always create a FRESH instance — reusing causes silent failures on mobile
    this._recognitionSession = new SpeechRecognition();
    const rec = this._recognitionSession;

    rec.lang = this.currentLang;
    rec.continuous = false;
    rec.interimResults = true;   // Enable real-time interim recognition for instant capture

    this.isRecording = true;
    let finalTranscript = '';
    let shouldRestart = true;
    let silenceTimer = null;

    // When mic opens: notify caller and start silence timer
    rec.onstart = () => {
      console.log('[Voice] Mic opened, listening...');
      clearTimeout(silenceTimer);
      // Auto-close after 6 seconds max if no speech detected
      silenceTimer = setTimeout(() => {
        console.log('[Voice] Max time reached, stopping.');
        shouldRestart = false;
        rec.stop();
      }, 6000);
      if (onStart) onStart();
    };

    // On speech result: capture interim & final text immediately
    rec.onresult = (event) => {
      clearTimeout(silenceTimer);
      let interim = '';
      for (let i = event.resultIndex; i < event.results.length; i++) {
        const text = event.results[i][0].transcript;
        if (event.results[i].isFinal) {
          finalTranscript += text + ' ';
        } else {
          interim += text;
        }
      }
      const trimmed = (finalTranscript.trim() || interim.trim());
      if (trimmed && onResult) {
        onResult(trimmed, 1.0);
      }

      // Stop mic 800ms after speech is detected
      silenceTimer = setTimeout(() => {
        shouldRestart = false;
        try { rec.stop(); } catch(e) {}
      }, 800);
    };

    // On error: only restart for no-speech, stop for real errors
    rec.onerror = (event) => {
      clearTimeout(silenceTimer);
      console.log('[Voice] Error:', event.error);
      if (event.error === 'not-allowed') {
        shouldRestart = false;
        this.isRecording = false;
        if (window.app) window.app.showToast('Please allow microphone access', 'warning');
      } else if (event.error === 'no-speech') {
        // no-speech: browser heard nothing — restart if still in session
        console.log('[Voice] No speech detected, restarting...');
        // let onend handle restart
      } else if (event.error === 'aborted') {
        shouldRestart = false;
      } else {
        shouldRestart = false;
        if (onError) onError(event.error);
      }
    };

    // onend fires after every segment on mobile — auto-restart keeps mic open
    rec.onend = () => {
      clearTimeout(silenceTimer);
      if (shouldRestart && this.isRecording) {
        // Restart the mic to keep listening (handles mobile automatic stops)
        console.log('[Voice] Restarting mic to stay open...');
        try {
          setTimeout(() => rec.start(), 100); // tiny gap prevents InvalidStateError
        } catch(e) {
          console.warn('[Voice] Could not restart:', e);
          this.isRecording = false;
          if (onEnd) onEnd();
        }
      } else {
        this.isRecording = false;
        console.log('[Voice] Mic closed. Final:', finalTranscript);
        if (onEnd) onEnd();
        if (window.VoiceCommands && !window.VoiceCommands.isListening) {
          setTimeout(() => window.VoiceCommands.start(), 500);
        }
      }
    };

    try {
      rec.start();
    } catch(e) {
      this.isRecording = false;
      console.warn('[Voice] Could not start recognition:', e);
      if (onError) onError(e.message);
    }
  }

  _abortListening() {
    if (this._recognitionSession) {
      try { this._recognitionSession.abort(); } catch(e) {}
    }
    this.isRecording = false;
  }

  stopListening() {
    this._abortListening();
  }
}

// Global instance
window.Voice = new VoiceController();
