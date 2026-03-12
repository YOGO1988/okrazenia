<?php
// ============================================================
// YO&GO - Serwer wyników online
// Wgraj ten plik na serwer (np. CyberFolks)
// ============================================================

// KONFIGURACJA - zmień token na swój własny ciąg znaków
define('SECRET_TOKEN', 'yogo2025');

// Plik z danymi wyników (w tym samym katalogu co wyniki.php)
define('DATA_FILE', __DIR__ . '/wyniki_data.json');

// ============================================================

// Odbij dokładny origin (obsługuje też null z file://)
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Token');
header('Vary: Origin');
header('Content-Type: application/json; charset=utf-8');

// Obsługa OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// POST - zapisz wyniki (tylko z tokenem)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_SERVER['HTTP_X_TOKEN'] ?? $_GET['token'] ?? '';

    if ($token !== SECRET_TOKEN) {
        http_response_code(403);
        echo json_encode(['error' => 'Nieautoryzowany dostęp']);
        exit;
    }

    $input = file_get_contents('php://input');

    // Sprawdź czy to poprawny JSON
    $data = json_decode($input, true);
    if ($data === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Niepoprawny JSON']);
        exit;
    }

    // Dodaj timestamp zapisu
    $data['_savedAt'] = date('c');

    if (file_put_contents(DATA_FILE, json_encode($data)) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Błąd zapisu pliku']);
        exit;
    }

    echo json_encode(['ok' => true, 'savedAt' => $data['_savedAt']]);
    exit;
}

// GET - zwróć ostatnie wyniki
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!file_exists(DATA_FILE)) {
        echo json_encode(['type' => 'no_data', 'message' => 'Brak danych - czekam na wyniki...']);
        exit;
    }

    $content = file_get_contents(DATA_FILE);
    $age = time() - filemtime(DATA_FILE);

    // Dodaj nagłówek cache - nie cachuj, dane są live
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Data-Age: ' . $age . 's');

    echo $content;
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metoda niedozwolona']);
