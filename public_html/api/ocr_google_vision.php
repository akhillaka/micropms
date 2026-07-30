<?php
declare(strict_types=1);

require_once __DIR__ . '/../../pms_core/ApiHandler.php';
require_once __DIR__ . '/../../pms_core/ApiResponse.php';
require_once __DIR__ . '/../../pms_core/config.php';

/**
 * Google Vision OCR endpoint for Aadhaar/ID card extraction
 * Receives base64 image, calls Google Vision API, returns extracted text + parsed fields
 */
ApiHandler::run(function(\PDO $db) {
    AuthHelper::requirePermission('upload_document');
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    
    $imageBase64 = $data['image'] ?? '';
    if (empty($imageBase64)) {
        ApiResponse::error('No image provided');
    }

    // Get API key from settings
    $apiKey = getenv('GOOGLE_VISION_API_KEY') ?: '';
    if (empty($apiKey)) {
        // Try loading from DB settings
        $stmt = $db->prepare("SELECT key_value FROM system_settings WHERE key_name = 'google_vision_api_key'");
        $stmt->execute();
        $apiKey = $stmt->fetchColumn() ?: '';
    }

    if (empty($apiKey)) {
        ApiResponse::error('Google Vision API key not configured. Please add it in Settings > Integrations.');
    }

    // Strip data URI prefix if present
    if (preg_match('/^data:image\/[a-z]+;base64,(.+)$/i', $imageBase64, $matches)) {
        $imageBase64 = $matches[1];
    }

    // Call Google Vision API
    $result = callGoogleVisionOCR($imageBase64, $apiKey);
    
    if ($result === null) {
        ApiResponse::error('Failed to process image with Google Vision');
    }

    // Parse the extracted text
    $parsedData = parseAadhaarText($result['text']);
    $parsedData['raw_text'] = $result['text'];
    $parsedData['confidence'] = $result['confidence'];
    $parsedData['source'] = 'google_vision';

    ApiResponse::success(['ocr' => $parsedData]);
}, true, false, false);

/**
 * Call Google Vision API for text detection
 */
function callGoogleVisionOCR(string $imageBase64, string $apiKey): ?array {
    $url = "https://vision.googleapis.com/v1/images:annotate?key=" . $apiKey;
    
    $payload = [
        'requests' => [
            [
                'image' => ['content' => $imageBase64],
                'features' => [
                    ['type' => 'DOCUMENT_TEXT_DETECTION', 'maxResults' => 1],
                    ['type' => 'TEXT_DETECTION', 'maxResults' => 1]
                ],
                'imageContext' => [
                    'languageHints' => ['en', 'hi', 'te'] // English, Hindi, Telugu
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        error_log("Google Vision API error: HTTP {$httpCode} - {$response}");
        return null;
    }

    $data = json_decode($response, true);
    
    if (!isset($data['responses'][0])) {
        return null;
    }

    $textAnnotations = $data['responses'][0]['textAnnotations'] ?? [];
    
    if (empty($textAnnotations)) {
        return ['text' => '', 'confidence' => 0];
    }

    // First element is the full text
    $fullText = $textAnnotations[0]['description'] ?? '';
    
    // Calculate average confidence from individual words
    $totalConfidence = 0;
    $wordCount = 0;
    for ($i = 1; $i < count($textAnnotations); $i++) {
        $confidence = $textAnnotations[$i]['confidence'] ?? 0.8;
        $totalConfidence += $confidence;
        $wordCount++;
    }
    $avgConfidence = $wordCount > 0 ? ($totalConfidence / $wordCount) * 100 : 80;

    return [
        'text' => $fullText,
        'confidence' => round($avgConfidence, 1)
    ];
}

/**
 * Parse Aadhaar/ID text to extract structured fields
 */
function parseAadhaarText(string $rawText): array {
    $lines = explode("\n", $rawText);
    $lines = array_map('trim', $lines);
    $lines = array_filter($lines, fn($l) => strlen($l) > 0);
    $fullText = str_replace("\n", ' ', $rawText);

    $idNumber = '';
    $dob = '';
    $gender = '';
    $name = '';
    $address = '';
    $mobile = '';
    $idType = 'unknown';
    $pincode = '';
    $city = '';
    $state = '';

    // 1. Aadhaar number (12 digits)
    if (preg_match('/\b([2-9]\d{3}\s?\d{4}\s?\d{4})\b/', $rawText, $m)) {
        $cleanAadhaar = preg_replace('/\s/', '', $m[1]);
        if (preg_match('/^[2-9]\d{11}$/', $cleanAadhaar)) {
            $idType = 'aadhaar';
            $idNumber = $cleanAadhaar;
        }
    }

    // 2. PAN number (5 letters, 4 digits, 1 letter)
    if ($idType === 'unknown' && preg_match('/\b([A-Z]{5}\d{4}[A-Z])\b/', $fullText, $m)) {
        $idType = 'pan';
        $idNumber = $m[1];
    }

    // 3. Driving License (DL) - e.g. TS07 20200001234, DL-1420110012345, KA01 20180001234
    if ($idType === 'unknown' && preg_match('/\b([A-Z]{2}[-\s]?\d{2}[-\s]?(?:19|20)\d{11}|[A-Z]{2}\d{13})\b/i', $fullText, $m)) {
        $idType = 'driving_license';
        $idNumber = strtoupper(str_replace([' ', '-'], '', $m[1]));
    }

    // 4. Passport - e.g. A1234567, Z9876543
    if ($idType === 'unknown' && preg_match('/\b([A-PR-WYA-Z][0-9]{7})\b/', $fullText, $m)) {
        $idType = 'passport';
        $idNumber = $m[1];
    }

    // 5. Voter ID (EPIC) - e.g. ABC1234567, TDF0123456
    if ($idType === 'unknown' && preg_match('/\b([A-Z]{3}\d{7})\b/', $fullText, $m)) {
        $idType = 'voter_id';
        $idNumber = $m[1];
    }

    // DOB / YOB
    if (preg_match('/\b(\d{2}[\/\-]\d{2}[\/\-]\d{4})\b/', $rawText, $m)) {
        $dob = $m[1];
    } elseif (preg_match('/(?:yob|year of birth|dob|date of birth|born)\s?:?\s?(\d{4})/i', $rawText, $m)) {
        $dob = '01/01/' . $m[1];
    }

    // Gender
    if (preg_match('/\b(FEMALE)\b/i', $rawText)) {
        $gender = 'Female';
    } elseif (preg_match('/\b(MALE)\b/i', $rawText)) {
        $gender = 'Male';
    }

    // Mobile number (10 digits starting with 6-9)
    if (preg_match('/\b([6-9]\d{9})\b/', $rawText, $m)) {
        $mobile = $m[1];
    }

    // PIN code extraction
    if (preg_match('/\b(\d{6})\b/', $rawText, $m)) {
        $pincode = $m[1];
    }

    // Name extraction
    $forbidden = ['government', 'india', 'unique', 'identification', 'authority', 'national',
        'card', 'election', 'commission', 'licence', 'driving', 'passport', 'address',
        'father', 'husband', 'signature', 'thumb', 'income', 'permanent', 'account',
        'number', 'date', 'birth', 'gender', 'male', 'female', 'ministry', 'republic',
        'department', 'transport', 'republic', 'identity', 'voter', 'elector'];

    // Try explicit labels
    if (preg_match('/(?:name|పేరు|नाम)\s?:?\s?([A-Z\s.-]{3,40})/i', $rawText, $m)) {
        $candidate = trim(explode('  ', $m[1])[0]);
        if (strlen($candidate) >= 3 && !preg_match('/\d/', $candidate)) {
            $name = $candidate;
        }
    }

    // Fallback: uppercase line candidates
    if (empty($name)) {
        foreach ($lines as $line) {
            if ($line === strtoupper($line) && preg_match('/[A-Z]/', $line)) {
                $words = explode(' ', $line);
                if (count($words) >= 2 && count($words) <= 5) {
                    $forbiddenFound = false;
                    foreach ($forbidden as $kw) {
                        if (stripos($line, $kw) !== false) {
                            $forbiddenFound = true;
                            break;
                        }
                    }
                    if (!$forbiddenFound && !preg_match('/\d/', $line)) {
                        $name = $line;
                        break;
                    }
                }
            }
        }
    }

    // Address extraction
    foreach ($lines as $i => $line) {
        if (preg_match('/address|पता|చిరునామా\s?:/i', $line)) {
            $addrLines = [];
            for ($j = $i + 1; $j < min(count($lines), $i + 6); $j++) {
                if (preg_match('/\d{4}\s?\d{4}\s?\d{4}/', $lines[$j]) || preg_match('/unique|authority|helpdesk/i', $lines[$j])) {
                    break;
                }
                if (strlen($lines[$j]) > 3) {
                    $addrLines[] = $lines[$j];
                }
            }
            if (!empty($addrLines)) {
                $address = implode(', ', $addrLines);
            }
            break;
        }
    }

    // Fallback address via pincode line proximity
    if (empty($address) && !empty($pincode)) {
        foreach ($lines as $i => $line) {
            if (strpos($line, $pincode) !== false) {
                $addrLines = [];
                for ($j = max(0, $i - 3); $j <= $i; $j++) {
                    if (strlen($lines[$j]) > 5 && !preg_match('/government|authority|unique/i', $lines[$j])) {
                        $addrLines[] = $lines[$j];
                    }
                }
                if (!empty($addrLines)) {
                    $address = implode(', ', $addrLines);
                }
                break;
            }
        }
    }

    // Age calculation from DOB
    $age = null;
    if (!empty($dob)) {
        if (preg_match('/(\d{4})/', $dob, $mYear)) {
            $birthYear = (int)$mYear[1];
            $currentYear = (int)date('Y');
            if ($birthYear > 1900 && $birthYear <= $currentYear) {
                $age = $currentYear - $birthYear;
            }
        }
    }

    // State detection heuristics from text
    $indianStates = [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh',
        'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka',
        'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram',
        'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu',
        'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Delhi'
    ];
    foreach ($indianStates as $st) {
        if (stripos($fullText, $st) !== false) {
            $state = $st;
            break;
        }
    }

    return [
        'name' => trim(preg_replace('/[^A-Za-z\s.]/', '', $name)),
        'dob' => $dob,
        'age' => $age,
        'gender' => $gender,
        'id_number' => $idNumber,
        'address' => trim($address),
        'pincode' => $pincode,
        'city' => $city,
        'state' => $state,
        'mobile' => $mobile,
        'id_type' => $idType
    ];
}
