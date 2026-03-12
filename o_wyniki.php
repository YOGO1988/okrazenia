<?php
// ============================================================
// YO&GO - Serwer wyników online
// Wgraj ten plik na serwer (np. CyberFolks)
// ============================================================

define('SECRET_TOKEN', 'yogo2025');
define('DATA_DIR', __DIR__);

// ============================================================

// CORS
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Token');
header('Vary: Origin');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Sanitize event ID - tylko litery, cyfry, myślnik, podkreślnik (max 60 znaków)
function getEventId() {
    $raw = $_GET['event'] ?? 'default';
    $clean = preg_replace('/[^a-zA-Z0-9_\-]/', '', $raw);
    $clean = substr($clean, 0, 60);
    return $clean ?: 'default';
}

function getDataFile($eventId) {
    return DATA_DIR . '/wyniki_' . $eventId . '.json';
}

$token = $_GET['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? '';
$action = $_GET['action'] ?? '';
$eventId = getEventId();
$dataFile = getDataFile($eventId);

// ---- GET ----
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // Lista wyścigów (wymaga tokena)
    if ($action === 'list') {
        if ($token !== SECRET_TOKEN) { http_response_code(403); echo json_encode(['error' => 'Brak autoryzacji']); exit; }
        $files = glob(DATA_DIR . '/wyniki_*.json') ?: [];
        $races = [];
        foreach ($files as $f) {
            $name = preg_replace('/^wyniki_(.+)\.json$/', '$1', basename($f));
            $data = json_decode(file_get_contents($f), true);
            $races[] = [
                'event'       => $name,
                'savedAt'     => $data['_savedAt'] ?? null,
                'eventName'   => $data['eventName'] ?? $name,
                'participants' => count($data['participants'] ?? []),
                'results'     => count($data['results'] ?? []),
            ];
        }
        usort($races, fn($a,$b) => strcmp($b['savedAt'] ?? '', $a['savedAt'] ?? ''));
        echo json_encode(['races' => $races]);
        exit;
    }

    // Wyniki konkretnego wyścigu
    if (!file_exists($dataFile)) {
        echo json_encode(['type' => 'no_data', 'message' => 'Brak danych dla wyścigu: ' . $eventId]);
        exit;
    }
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo file_get_contents($dataFile);
    exit;
}

// ---- POST - zapisz wyniki ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($token !== SECRET_TOKEN) { http_response_code(403); echo json_encode(['error' => 'Nieautoryzowany dostęp']); exit; }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if ($data === null) { http_response_code(400); echo json_encode(['error' => 'Niepoprawny JSON']); exit; }

    $data['_savedAt'] = date('c');
    $data['_event'] = $eventId;

    if (file_put_contents($dataFile, json_encode($data)) === false) {
        http_response_code(500); echo json_encode(['error' => 'Błąd zapisu']); exit;
    }

    echo json_encode(['ok' => true, 'event' => $eventId, 'savedAt' => $data['_savedAt']]);
    exit;
}

// ---- DELETE - usuń wyścig ----
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if ($token !== SECRET_TOKEN) { http_response_code(403); echo json_encode(['error' => 'Brak autoryzacji']); exit; }
    if (!file_exists($dataFile)) { http_response_code(404); echo json_encode(['error' => 'Nie znaleziono']); exit; }
    unlink($dataFile);
    echo json_encode(['ok' => true, 'deleted' => $eventId]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Metoda niedozwolona']);
