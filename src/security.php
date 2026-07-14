<?php
// ================================================
// Security Helper Functions
// Auto AP Test Scheduler — Viera High School
// ================================================

// ------------------------------------------------
// XSS Prevention
// Always use this when outputting user data to HTML
// ------------------------------------------------
function h($string)
{
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// ------------------------------------------------
// Input Sanitization
// Clean user input before processing
// ------------------------------------------------
function sanitize_string($input)
{
    return trim(strip_tags($input ?? ''));
}

function sanitize_int($input)
{
    return (int) $input;
}

function sanitize_date($input)
{
    $input = trim($input ?? '');
    // Validate date format YYYY-MM-DD
    $d = DateTime::createFromFormat('Y-m-d', $input);
    if ($d && $d->format('Y-m-d') === $input) {
        return $input;
    }
    return null;
}

function sanitize_email($input)
{
    return filter_var(trim($input ?? ''), FILTER_SANITIZE_EMAIL);
}

// ------------------------------------------------
// CSRF Protection
// Generate and validate tokens to prevent
// cross-site request forgery
// ------------------------------------------------
function generate_csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token)
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_input()
{
    return '<input type="hidden" name="csrf_token" 
            value="' . h(generate_csrf_token()) . '"/>';
}

// ------------------------------------------------
// Validate POST action
// Only allow known actions to be processed
// ------------------------------------------------
function validate_action($action, $allowed_actions)
{
    return in_array($action, $allowed_actions, true);
}

// ------------------------------------------------
// Security Headers
// Call this at the top of every page
// ------------------------------------------------
function set_security_headers()
{
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    // XSS protection for older browsers
    header('X-XSS-Protection: 1; mode=block');
    // Content Security Policy
    header("Content-Security-Policy: default-src 'self'; " .
        "style-src 'self' 'unsafe-inline'; " .
        "script-src 'self' 'unsafe-inline';");
    // Referrer policy
    header('Referrer-Policy: same-origin');
}
