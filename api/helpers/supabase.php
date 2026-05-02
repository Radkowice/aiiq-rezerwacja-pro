<?php
function supabaseHeaders($key, $schema = 'rezerwacja_pro') {
    return [
        'Content-Type: application/json',
        'apikey: ' . $key,
        'Authorization: Bearer ' . $key,
        'Prefer: resolution=merge-duplicates, return=representation',
        "Accept-Profile: $schema",
        "Content-Profile: $schema"
    ];
}