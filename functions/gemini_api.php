<?php
// functions/gemini_api.php - Gemini API Integration
// Handles API requests to Google Gemini for AI insights
// Uses cURL for HTTP requests; ensure cURL is enabled in php.ini

require_once __DIR__ . '/../config/gemini.php'; // Relative path from functions to config

function get_gemini_response($prompt) {
    $url = GEMINI_API_URL . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;
    
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Disable for local dev only; enable in production for security

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log('Gemini cURL Error: ' . curl_error($ch));
        curl_close($ch);
        return 'AI insights temporarily unavailable due to network issue.';
    }
    curl_close($ch);

    $json = json_decode($response, true);
    if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
        return $json['candidates'][0]['content']['parts'][0]['text'];
    } else {
        error_log('Gemini API Response Error: ' . print_r($json, true));
        return 'AI insights unavailable at the moment. Please try again later.';
    }
}
?>