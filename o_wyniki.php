<?php
// ============================================================
// YO&GO - Serwer wyników online
// Wgraj ten plik na serwer (np. CyberFolks)
// ============================================================

// KONFIGURACJA - zmień token na swój własny ciąg znaków
define('SECRET_TOKEN', 'yogo2025');

// Plik z danymi wyników (w tym samym katalogu co o_wyniki.php)
define('DATA_FILE', __DIR__ . '/wyniki_data.json');

// ============================================================

// CORS - musi być przed wszystkim innym
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Token');
header('Vary: Origin');
header('Content-Type: application/json; charset=utf-8');

// Obsługa OPTIONS (preflight CORS) - nie powinno dojść przy text/plain, ale dla pewności
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// POST - zapisz wyniki
// Token może być w URL (?token=...) lub nagłówku X-Token
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_GET['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? '';

    if ($token !== SECRET_TOKEN) {
        http_response_code(403);
        echo json_encode(['error' => 'Nieautoryzowany dostęp']);
        exit;
    }

    $input = file_get_contents('php://input');

    $data = json_decode($input, true);
    if ($data === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Niepoprawny JSON']);
        exit;
    }

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

    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Data-Age: ' . $age . 's');

    echo $content;
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metoda niedozwolona']);
