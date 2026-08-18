/**
 * OCR Helper - Client-side ID Proof Scanning & Data Extraction
 * Priority: Google Vision API (server) → Tesseract.js (client fallback)
 */
class OcrController {
  constructor() {
    this.worker = null;
    this.isInitializing = false;
    this.useGoogleVision = true; // Try Google Vision first
  }

  /**
   * Initializes Tesseract Worker (fallback)
   */
  async initWorker() {
    if (this.worker) return this.worker;
    if (this.isInitializing) {
      return new Promise((resolve) => {
        const interval = setInterval(() => {
          if (this.worker) {
            clearInterval(interval);
            resolve(this.worker);
          }
        }, 100);
      });
    }

    this.isInitializing = true;
    try {
      this.worker = await Tesseract.createWorker('eng+hin+tel');
      this.isInitializing = false;
      return this.worker;
    } catch (e) {
      this.isInitializing = false;
      console.error('Tesseract failed:', e);
      try {
        this.worker = await Tesseract.createWorker('eng');
        return this.worker;
      } catch (e2) {
        throw e2;
      }
    }
  }

  /**
   * Preprocess image for better OCR
   */
  preprocessImage(imageSource) {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    
    let width = imageSource.videoWidth || imageSource.naturalWidth || imageSource.width;
    let height = imageSource.videoHeight || imageSource.naturalHeight || imageSource.height;
    
    // Scale to optimal size
    const maxDim = 1200;
    if (width > maxDim || height > maxDim) {
      const scale = maxDim / Math.max(width, height);
      width = Math.round(width * scale);
      height = Math.round(height * scale);
    }
    
    canvas.width = width;
    canvas.height = height;
    ctx.drawImage(imageSource, 0, 0, width, height);
    
    return canvas.toDataURL('image/png');
  }

  /**
   * Main OCR entry point
   * Tries Google Vision first, falls back to Tesseract
   */
  async scanId(source, onProgress) {
    // Preprocess if needed
    let finalSource = source;
    if (typeof source !== 'string') {
      finalSource = this.preprocessImage(source);
    }

    // Try Google Vision API first
    if (this.useGoogleVision) {
      try {
        const result = await this._tryGoogleVision(finalSource);
        if (result) {
          console.log('[OCR] Google Vision success');
          return result;
        }
      } catch (e) {
        console.warn('[OCR] Google Vision failed, falling back to Tesseract:', e.message);
      }
    }

    // Fallback to Tesseract.js
    console.log('[OCR] Using Tesseract.js fallback');
    return this._tryTesseract(finalSource);
  }

  /**
   * Try Google Vision API via server proxy
   */
  async _tryGoogleVision(base64Image) {
    // Strip data URI prefix for API
    const base64Data = base64Image.replace(/^data:image\/[a-z]+;base64,/, '');

    const response = await fetch('/api/system/ocr_google_vision', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': window.__PMS_CSRF || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      },
      body: JSON.stringify({ image: base64Data }),
      signal: AbortSignal.timeout(30000) // 30s timeout
    });

    const data = await response.json();
    
    if (data.success && data.ocr) {
      return {
        name: data.ocr.name || '',
        dob: data.ocr.dob || '',
        gender: data.ocr.gender || '',
        id_number: data.ocr.id_number || '',
        address: data.ocr.address || '',
        mobile: data.ocr.mobile || '',
        id_type: data.ocr.id_type || 'unknown',
        raw_text: data.ocr.raw_text || '',
        confidence: data.ocr.confidence || 0,
        source: 'google_vision'
      };
    }

    return null;
  }

  /**
   * Tesseract.js fallback
   */
  async _tryTesseract(source) {
    try {
      const worker = await this.initWorker();
      const result = await worker.recognize(source);
      
      const parsedData = this.parseExtractedText(result.data.text);
      parsedData.raw_text = result.data.text;
      parsedData.confidence = result.data.confidence;
      parsedData.source = 'tesseract';
      
      return parsedData;
    } catch (e) {
      console.error('[OCR] Tesseract error:', e);
      return {
        name: '',
        dob: '',
        gender: '',
        id_number: '',
        address: '',
        mobile: '',
        id_type: 'unknown',
        raw_text: '',
        confidence: 0,
        source: 'failed',
        error: e.message
      };
    }
  }

  /**
   * Parse extracted text (Tesseract fallback)
   */
  /**
   * Parse extracted text (Tesseract fallback)
   */
  parseExtractedText(rawText) {
    const lines = rawText.split('\n').map(l => l.trim()).filter(l => l.length > 0);
    const fullText = rawText.replace(/\n/g, ' ');
    
    let idNumber = '', dob = '', gender = '', name = '', address = '', mobile = '', idType = 'unknown';
    
    // Aadhaar number (12 digits)
    if (preg_match = rawText.match(/\b([2-9]\d{3}\s?\d{4}\s?\d{4})\b/)) {
      idNumber = preg_match[1].replace(/\s/g, '');
      if (/^[2-9]\d{11}$/.test(idNumber)) idType = 'aadhaar';
    }

    // PAN
    if (idType === 'unknown' && (preg_match = fullText.match(/\b([A-Z]{5}\d{4}[A-Z])\b/))) {
      idType = 'pan';
      idNumber = preg_match[1];
    }

    // Driving License
    if (idType === 'unknown' && (preg_match = fullText.match(/\b([A-Z]{2}[-\s]?\d{2}[-\s]?(?:19|20)\d{11}|[A-Z]{2}\d{13})\b/i))) {
      idType = 'driving_license';
      idNumber = preg_match[1].replace(/[\s-]/g, '').toUpperCase();
    }

    // Passport
    if (idType === 'unknown' && (preg_match = fullText.match(/\b([A-PR-WYA-Z][0-9]{7})\b/))) {
      idType = 'passport';
      idNumber = preg_match[1];
    }

    // Voter ID
    if (idType === 'unknown' && (preg_match = fullText.match(/\b([A-Z]{3}\d{7})\b/))) {
      idType = 'voter_id';
      idNumber = preg_match[1];
    }

    // DOB
    if ((preg_match = rawText.match(/\b(\d{2}[\/\-]\d{2}[\/\-]\d{4})\b/))) {
      dob = preg_match[1];
    } else if ((preg_match = rawText.match(/(?:yob|birth|dob)\s?:?\s?(\d{4})/i))) {
      dob = '01/01/' + preg_match[1];
    }

    // Gender
    if (/female/i.test(rawText)) gender = 'Female';
    else if (/male|m\b/i.test(rawText)) gender = 'Male';

    // Mobile
    if ((preg_match = rawText.match(/\b([6-9]\d{9})\b/))) mobile = preg_match[1];

    // Name
    name = this._extractName(lines, rawText);

    // Address
    address = this._extractAddress(lines, rawText);

    return { name, dob, gender, id_number: idNumber, address, mobile, id_type: idType };
  }

  _extractName(lines, rawText) {
    const forbidden = ['government', 'india', 'unique', 'identification', 'authority', 'national',
      'card', 'election', 'commission', 'licence', 'driving', 'passport', 'address',
      'father', 'husband', 'signature', 'thumb', 'income', 'permanent', 'account',
      'number', 'date', 'birth', 'gender', 'male', 'female', 'ministry', 'republic', 'voter', 'elector'];

    // Method 1: "Name:" label
    const nameMatch = rawText.match(/(?:name|పేరు|नाम)\s?:?\s?(.+)/i);
    if (nameMatch) {
      const extracted = nameMatch[1].trim().split(/\s{2,}/)[0].trim();
      if (extracted.length >= 3 && extracted.length <= 50) return extracted.replace(/[^a-zA-Z\s'-]/g, '').trim();
    }

    // Method 2: All-caps lines
    const candidates = [];
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      if (line === line.toUpperCase() && /[A-Z]/.test(line)) {
        const words = line.split(/\s+/);
        if (words.length >= 2 && words.length <= 5 && !forbidden.some(k => line.toLowerCase().includes(k)) && !/\d/.test(line)) {
          candidates.push({ line, score: (words.length >= 2 && words.length <= 3 ? 2 : 0) + (i < lines.length / 2 ? 1 : 0), index: i });
        }
      }
    }
    if (candidates.length > 0) {
      candidates.sort((a, b) => b.score - a.score || a.index - b.index);
      return candidates[0].line.replace(/[^a-zA-Z\s'-]/g, '').trim();
    }

    return '';
  }

  _extractAddress(lines, rawText) {
    // Look for "Address:" label
    const addrIdx = lines.findIndex(l => /address|पता|చిరునామా\s?:/i.test(l));
    if (addrIdx !== -1) {
      const addrLines = [];
      for (let i = addrIdx + 1; i < Math.min(lines.length, addrIdx + 6); i++) {
        if (/\d{4}\s?\d{4}\s?\d{4}/.test(lines[i]) || /unique|authority|helpdesk/i.test(lines[i])) break;
        if (lines[i].length > 3) addrLines.push(lines[i]);
      }
      if (addrLines.length > 0) return addrLines.join(', ').replace(/\s+/g, ' ').trim();
    }

    // Look for PIN code
    const pinMatch = rawText.match(/\b(\d{6})\b/);
    if (pinMatch) {
      const pinLineIdx = lines.findIndex(l => l.includes(pinMatch[1]));
      if (pinLineIdx !== -1) {
        const addrLines = [];
        for (let i = Math.max(0, pinLineIdx - 3); i <= pinLineIdx; i++) {
          if (lines[i].length > 5 && !/government|authority/i.test(lines[i])) addrLines.push(lines[i]);
        }
        if (addrLines.length > 0) return addrLines.join(', ').replace(/\s+/g, ' ').trim();
      }
    }

    return '';
  }

  /**
   * Similarity calculation (Token-Sort + Levenshtein Hybrid)
   */
  calculateSimilarity(s1, s2) {
    if (!s1 || !s2) return 0;

    const normalize = (str) => {
      return str.toLowerCase()
        .replace(/\b(mr|mrs|ms|dr|shri|smt)\b\.?/gi, '')
        .replace(/[^a-z0-9\s]/g, '')
        .trim();
    };

    const clean1 = normalize(s1);
    const clean2 = normalize(s2);

    if (clean1 === clean2) return 100;
    if (clean1.length === 0 || clean2.length === 0) return 0;

    // 1. Direct Levenshtein score
    const levScore = this._levenshteinScore(clean1.replace(/\s+/g, ''), clean2.replace(/\s+/g, ''));

    // 2. Token sort score (handles reversed names like "RAHUL KUMAR" vs "KUMAR RAHUL")
    const tokens1 = clean1.split(/\s+/).sort().join(' ');
    const tokens2 = clean2.split(/\s+/).sort().join(' ');
    const tokenScore = this._levenshteinScore(tokens1, tokens2);

    return Math.max(levScore, tokenScore);
  }

  _levenshteinScore(str1, str2) {
    if (str1 === str2) return 100;
    if (str1.length === 0 || str2.length === 0) return 0;
    
    const track = Array(str2.length + 1).fill(null).map(() => Array(str1.length + 1).fill(null));
    for (let i = 0; i <= str1.length; i++) track[0][i] = i;
    for (let j = 0; j <= str2.length; j++) track[j][0] = j;
    
    for (let j = 1; j <= str2.length; j++) {
      for (let i = 1; i <= str1.length; i++) {
        track[j][i] = Math.min(
          track[j][i - 1] + 1,
          track[j - 1][i] + 1,
          track[j - 1][i - 1] + (str1[i - 1] === str2[j - 1] ? 0 : 1)
        );
      }
    }
    
    return Math.round(((Math.max(str1.length, str2.length) - track[str2.length][str1.length]) / Math.max(str1.length, str2.length)) * 100);
  }
}

window.Ocr = new OcrController();
