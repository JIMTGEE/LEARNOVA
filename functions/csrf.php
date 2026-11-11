<?php
// functions/csrf.php

// Only start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Optional: Regenerate on login
function regenerate_csrf_token() {
    unset($_SESSION['csrf_token']);
    return generate_csrf_token();
}