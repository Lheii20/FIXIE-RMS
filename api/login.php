<?php
// The application login form uses actions/auth.php. This retired endpoint used a
// separate authentication path and is intentionally closed so it cannot bypass
// the standard login's CSRF, rate-limit, session, and account-setup controls.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
http_response_code(410);

echo json_encode([
    'status' => 'error',
    'message' => 'This legacy login endpoint is no longer available.'
]);
