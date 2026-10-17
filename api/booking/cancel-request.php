<?php
declare(strict_types=1);
// Wyłączony publiczny formularz próśb o anulowanie.
// Nie odczytujemy tokena, tenanta, rezerwacji ani bazy danych.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
http_response_code(410);
echo json_encode([
    'success' => false,
    'code' => 'feature_unavailable',
    'message' => 'Prośby o anulowanie nie są przyjmowane online. Skontaktuj się bezpośrednio z firmą.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
