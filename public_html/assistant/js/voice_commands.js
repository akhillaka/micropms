/**
 * Voice Commands - Always-on voice command system for Hotel Assistant
 * Supports Telugu and English commands
 */
class VoiceCommandController {
  constructor() {
    this.isListening = false;
    this.recognition = null;
    this.commands = [];
    this.onCommand = null;
    this.retryTimeout = null;
    this.maxRetries = 3;
    this.retryCount = 0;
    
    this._initCommands();
    this._initRecognition();
  }

  /**
   * Initialize supported voice commands
   */
  _initCommands() {
    this.commands = [
      // Navigation commands
      { patterns: ['new booking', 'కొత్త బుకింగ్', 'create booking', 'బుకింగ్ చేయి'], action: 'new_booking' },
      { patterns: ['show bookings', 'booking history', 'బుకింగ్ హిస్టరీ', 'బుకింగ్స్ చూపించు'], action: 'show_bookings' },
      { patterns: ['check in', 'check-in', 'చెక్ ఇన్', 'చెక్ఇన్'], action: 'check_in' },
      { patterns: ['check out', 'checkout', 'చెక్ అవుట్', 'చెక్అవుట్'], action: 'check_out' },
      { patterns: ['dirty rooms', 'clean rooms', 'housekeeping', 'డర్టీ రూమ్స్', 'హౌస్ కీపింగ్', 'శుభ్రం చేయి'], action: 'housekeeping' },
      { patterns: ['alerts', 'notifications', 'అలర్ట్స్', 'నోటిఫికేషన్స్'], action: 'alerts' },
      { patterns: ['home', 'dashboard', 'హోమ్', 'డాష్‌బోర్డ్'], action: 'home' },
      { patterns: ['profile', 'settings', 'ప్రొఫైల్', 'సెట్టింగ్స్'], action: 'profile' },
      
      // Room-specific commands
      { patterns: ['room (\\d+)', 'రూమ్ (\\d+)'], action: 'room_number', capture: true },
      { patterns: ['mark room (\\d+) clean', 'రూమ్ (\\d+) క్లీన్ చేయి', '(\\d+) క్లీన్'], action: 'mark_clean', capture: true },
      
      // Query commands
      { patterns: ['available rooms', 'అందుబాటులో ఉన్న గదులు', 'how many rooms', 'గదులు ఎన్ని'], action: 'available_rooms' },
      { patterns: ['occupied rooms', 'ఆక్రమించబడిన గదులు'], action: 'occupied_rooms' },
      { patterns: ['pending payments', 'pending dues', 'బాకీ చెల్లింపులు', 'ఎవరు బాకీ'], action: 'pending_payments' },
      { patterns: ['today arrivals', 'arrivals today', 'today check in', 'ఈరోజు చెక్ఇన్', 'ఈరోజు రాకలు'], action: 'today_arrivals' },
      { patterns: ['today departures', 'checkout today', 'ఈరోజు చెక్అవుట్', 'ఈరోజు నిష్క్రమణలు'], action: 'today_departures' },
      
      // Help
      { patterns: ['help', 'what can you do', 'సహాయం', 'ఏమి చేయగలవు'], action: 'help' },
    ];
  }

  /**
   * Initialize speech recognition
   */
  _initRecognition() {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) {
      console.warn('[VoiceCommands] Speech recognition not available - voice commands disabled');
      this.recognition = null;
      return;
    }

    this.recognition = new SpeechRecognition();
    this.recognition.continuous = true;
    this.recognition.interimResults = false;
    this.recognition.lang = 'en-IN'; // English (India) primary
    this.recognition.maxAlternatives = 3;

    this.recognition.onresult = (event) => {
      this.retryCount = 0; // Reset on successful result
      
      for (let i = event.resultIndex; i < event.results.length; i++) {
        if (event.results[i].isFinal) {
          const transcript = event.results[i][0].transcript.trim().toLowerCase();
          console.log('[VoiceCommands] Heard:', transcript);
          this._processCommand(transcript);
        }
      }
    };

    this.recognition.onerror = (event) => {
      // Silently handle non-critical errors
      if (event.error === 'no-speech' || event.error === 'aborted') {
        this._restartListening();
      } else if (event.error === 'not-allowed') {
        console.warn('[VoiceCommands] Microphone permission denied');
        this.isListening = false;
      } else {
        console.warn('[VoiceCommands] Error:', event.error);
        this._restartListening();
      }
    };

    this.recognition.onend = () => {
      if (this.isListening) {
        this._restartListening();
      }
    };
  }

  /**
   * Start listening for voice commands
   */
  start() {
    if (!this.recognition) {
      // Voice commands not available - silently degrade
      return false;
    }

    this.isListening = true;
    this.retryCount = 0;
    
    try {
      this.recognition.start();
      return true;
    } catch (e) {
      console.error('[VoiceCommands] Failed to start:', e);
      this._restartListening();
      return false;
    }
  }

  /**
   * Stop listening
   */
  stop() {
    this.isListening = false;
    if (this.retryTimeout) {
      clearTimeout(this.retryTimeout);
      this.retryTimeout = null;
    }
    if (this.recognition) {
      try {
        this.recognition.stop();
      } catch (e) {
        // Ignore
      }
    }
    console.log('[VoiceCommands] Stopped listening');
  }

  /**
   * Restart listening after error
   */
  _restartListening() {
    if (!this.isListening) return;
    
    if (this.retryCount >= this.maxRetries) {
      console.log('[VoiceCommands] Max retries reached, waiting longer...');
      this.retryTimeout = setTimeout(() => {
        this.retryCount = 0;
        this._doStart();
      }, 5000);
      return;
    }

    this.retryCount++;
    this.retryTimeout = setTimeout(() => {
      this._doStart();
    }, 1000);
  }

  _doStart() {
    if (!this.isListening || !this.recognition) return;
    try {
      this.recognition.start();
    } catch (e) {
      // Already started, ignore
    }
  }

  /**
   * Process voice transcript against command patterns
   */
  _processCommand(transcript) {
    for (const cmd of this.commands) {
      for (const pattern of cmd.patterns) {
        const regex = new RegExp(pattern, 'i');
        const match = transcript.match(regex);
        
        if (match) {
          const captured = cmd.capture ? (match[1] || null) : null;
          console.log(`[VoiceCommands] Matched: ${cmd.action}`, captured ? `(${captured})` : '');
          
          if (this.onCommand) {
            this.onCommand(cmd.action, captured, transcript);
          }
          return;
        }
      }
    }
    
    console.log('[VoiceCommands] No command matched:', transcript);
  }

  /**
   * Get help text for all commands
   */
  getHelpText() {
    return {
      en: [
        '"New booking" - Start a new booking',
        '"Check in" - Go to arrivals',
        '"Check out" - Go to departures',
        '"Dirty rooms" - Go to housekeeping',
        '"Available rooms" - Check room availability',
        '"Pending payments" - Check who owes money',
        '"Alerts" - Show notifications',
        '"Room 101 clean" - Mark room as clean',
        '"Help" - Show this help',
      ],
      te: [
        '"కొత్త బుకింగ్" - కొత్త బుకింగ్ ప్రారంభించండి',
        '"చెక్ ఇన్" - రాకలకు వెళ్ళండి',
        '"చెక్ అవుట్" - నిష్క్రమణలకు వెళ్ళండి',
        '"డర్టీ రూమ్స్" - హౌస్‌కీపింగ్‌కు వెళ్ళండి',
        '"అందుబాటులో ఉన్న గదులు" - గది లభ్యత తనిఖీ',
        '"బాకీ చెల్లింపులు" - ఎవరు బాకీ ఉన్నారో చూడండి',
        '"అలర్ట్స్" - నోటిఫికేషన్లు చూపించు',
        '"రూమ్ 101 క్లీన్" - గదిని శుభ్రంగా గుర్తించండి',
        '"సహాయం" - ఈ సహాయం చూపించు',
      ]
    };
  }
}

// Global instance
window.VoiceCommands = new VoiceCommandController();
