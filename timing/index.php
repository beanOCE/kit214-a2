<?php
header('Content-Type: application/json');

// -----------
// DBCONN INFO
// -----------
$host = 'localhost';
$dbname = 'a2';
$user = 'a2';
$password = 'meow';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(["code" => 500, "error" => "Database connection failed"]);
    exit;
}

// ----------
// GET /track
// ----------
function getAllTracks($pdo) {
    $stmt = $pdo->query("SELECT id, name, type, laps, baseLapTime FROM tracks");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// -------------
// GET track/:id
// -------------
function getTrackById($pdo, $id) {
    $stmt = $pdo->prepare("SELECT id, name, type, laps, baseLapTime FROM tracks WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// -----------
// POST /track
// -----------
function addTrack($pdo, $data) {
    $stmt = $pdo->prepare("INSERT INTO tracks (name, type, laps, baseLapTime) VALUES (:name, :type, :laps, :baseLapTime)");
    $stmt->bindParam(':name', $data['name']);
    $stmt->bindParam(':type', $data['type']);
    $stmt->bindParam(':laps', $data['laps'], PDO::PARAM_INT);
    $stmt->bindParam(':baseLapTime', $data['baseLapTime'], PDO::PARAM_STR);
    
    if ($stmt->execute()) {
        return $pdo->lastInsertId();
    } else {
        return false;
    }
}

// -----------------
// DELETE /track/:id
// -----------------
function deleteTrack($pdo, $id) {
    $stmt = $pdo->prepare("DELETE FROM tracks WHERE id = :id");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    return $stmt->execute();
}

// -------------
// F1 SCRAPER!!!
// -------------
function scrapeTrackData() {
    $url = "https://www.formula1.com/en/racing/2024.html";
    $html = file_get_contents($url);

    // Fail check
    if ($html === false) {
        return ["code" => 500, "error" => "Failed to retrieve data from Formula 1 website"];
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html); // Suppresses warnings

    $xpath = new DOMXPath($dom);
    $tracks = [];

    // Finds all track links with associated class
    $trackLinks = $xpath->query("//a[contains(@class, 'outline-offset-4') and contains(@class, 'outline-scienceBlue') and contains(@class, 'group') and contains(@class, 'outline-0') and contains(@class, 'focus-visible:outline-2')]");

    foreach ($trackLinks as $link) {
        // Append "/circuit" for track info
        $trackUrl = "https://www.formula1.com" . $link->getAttribute('href') . "/circuit";
        
        // Scrape each page
        $trackHtml = file_get_contents($trackUrl);
        
        if ($trackHtml === false) {
            continue; // Skip if it doesn't load
        }

        $trackDom = new DOMDocument();
        @$trackDom->loadHTML($trackHtml);
        $trackXpath = new DOMXPath($trackDom);

        // XPath for track name
        $nameNode = $trackXpath->query("/html/body/main/div[2]/div/div[2]/fieldset/legend/div/h2/div")->item(0);
        $name = $nameNode ? $nameNode->textContent : 'Unknown';

        // XPath for lap count
        $lapsNode = $trackXpath->query("/html/body/main/div[2]/div/div[2]/fieldset/div/div[2]/div/div[1]/div/div[2]/h2")->item(0);
        $laps = $lapsNode ? (int) $lapsNode->textContent : 0;

        // XPath for baseLapTime
        $baseLapTimeNode = $trackXpath->query("/html/body/main/div[2]/div/div[2]/fieldset/div/div[2]/div/div[1]/div/div[5]/h2")->item(0);
        $baseLapTimeString = $baseLapTimeNode ? $baseLapTimeNode->textContent : '0:00';
        
        // Convert baseLapTime
        $baseLapTimeParts = explode(':', $baseLapTimeString);
        $baseLapTime = 0;

        if (count($baseLapTimeParts) === 2) {
            $baseLapTime = (int)$baseLapTimeParts[0] * 60 + (float)$baseLapTimeParts[1];
        } elseif (count($baseLapTimeParts) === 1) {
            $baseLapTime = (float)$baseLapTimeParts[0];
        }

        // Round baseLapTime
        $baseLapTime = round($baseLapTime, 3);

        // Append track details
        if ($name !== 'Unknown') {
            $tracks[] = [
                "name" => $name,
                "type" => "street", 
                "laps" => $laps,
                "baseLapTime" => $baseLapTime
            ];
        }
    }

    return ["code" => 200, "result" => $tracks];
}

// ---------
// GET /race
// ---------
function getAllRaces($pdo) {
    $stmt = $pdo->query("
        SELECT r.id AS race_id, t.name AS track_name, COUNT(e.car_uri) AS entrant_count, 
               t.baseLapTime, t.laps
        FROM races r
        LEFT JOIN tracks t ON r.track_id = t.id
        LEFT JOIN race_entrants e ON r.id = e.race_id
        GROUP BY r.id, t.name, t.baseLapTime, t.laps
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ------------
// GET /race:id
// ------------
function getRaceById($pdo, $id) {
    $stmt = $pdo->prepare(
        "SELECT r.id AS race_id, t.name AS track_name, COUNT(e.car_uri) AS entrant_count,
                t.baseLapTime, t.laps
         FROM races r
         LEFT JOIN tracks t ON r.track_id = t.id
         LEFT JOIN race_entrants e ON r.id = e.race_id
         WHERE r.id = :id
         GROUP BY r.id, t.name, t.baseLapTime, t.laps"
    );
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $race = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($race) {
        // Fetch entrants
        $entrantStmt = $pdo->prepare(
            "SELECT car_uri, starting_position 
             FROM race_entrants 
             WHERE race_id = :race_id 
             ORDER BY starting_position"
        );
        $entrantStmt->bindParam(':race_id', $race['race_id'], PDO::PARAM_INT);
        $entrantStmt->execute();
        $entrants = $entrantStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'race_id' => $race['race_id'],
            'track_name' => $race['track_name'],
            'entrant_count' => $race['entrant_count'],
            'base_lap_time' => $race['baseLapTime'],
            'laps' => $race['laps'],
            'entrants' => $entrants,
        ];
    } else {
        return null; // Return null if not found
    }
}

// ----------
// POST /race
// ----------
function addRace($pdo, $trackId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM races WHERE track_id = :track_id");
    $stmt->bindParam(':track_id', $trackId, PDO::PARAM_INT);
    $stmt->execute();
    $raceCount = $stmt->fetchColumn();

    if ($raceCount > 0) {
        return false;
    }

    $stmt = $pdo->prepare("INSERT INTO races (track_id) VALUES (:track_id)");
    $stmt->bindParam(':track_id', $trackId, PDO::PARAM_INT);

    if ($stmt->execute()) {
        return $pdo->lastInsertId();
    } else {
        return false;
    }
}

// --------------------
// GET /track/:id/races
// --------------------
function getRacesByTrackId($pdo, $trackId) {
    // Fetch races
    $stmt = $pdo->prepare("SELECT r.id AS race_id FROM races r WHERE r.track_id = :track_id");
    $stmt->bindParam(':track_id', $trackId, PDO::PARAM_INT);
    $stmt->execute();
    
    $races = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Init array for result
    $result = [];
    foreach ($races as $race) {
        // Fetch entrants
        $entrantStmt = $pdo->prepare("SELECT car_uri, starting_position FROM race_entrants WHERE race_id = :race_id ORDER BY starting_position");
        $entrantStmt->bindParam(':race_id', $race['race_id'], PDO::PARAM_INT);
        $entrantStmt->execute();
        $entrants = $entrantStmt->fetchAll(PDO::FETCH_ASSOC);

        $result[] = [
            'race_id' => $race['race_id'],
            'entrants' => $entrants,
        ];
    }
    
    return $result;
}

// End of endpoint logic!


// -----------------
// ALL ROUTING LOGIC
// -----------------

$request = $_SERVER['REQUEST_URI'];
$requestHandled = false;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/\/track\/?$/', $request)) {
    echo json_encode(["code" => 200, "result" => getAllTracks($pdo)]);
    $requestHandled = true;

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/\/track\/(\d+)$/', $request, $matches)) {
    $track = getTrackById($pdo, (int)$matches[1]);
    echo json_encode($track ? ["code" => 200, "result" => $track] : ["code" => 404, "error" => "Track not found"]);
    $requestHandled = true;

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('/\/track\/?$/', $request)) {
    // Assuming you're sending track data in the request body
    $trackData = json_decode(file_get_contents('php://input'), true);
    if ($trackData && addTrack($pdo, $trackData)) {
        echo json_encode(["code" => 201, "message" => "Track created"]);
    } else {
        echo json_encode(["code" => 400, "error" => "Invalid data"]);
    }
    $requestHandled = true;

} elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE' && preg_match('/\/track\/(\d+)$/', $request, $matches)) {
    echo json_encode(deleteTrack($pdo, (int)$matches[1]) ? ["code" => 200, "message" => "Track deleted"] : ["code" => 404, "error" => "Track not found"]);
    $requestHandled = true;

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('/\/track\/(\d+)\/races$/', $request, $matches)) {
    $trackId = (int)$matches[1];
    if (!getTrackById($pdo, $trackId)) {
        echo json_encode(["code" => 404, "error" => "Track not found"]);
    } elseif ($raceId = addRace($pdo, $trackId)) {
        echo json_encode(["code" => 201, "message" => "Race created", "raceId" => $raceId]);
    } else {
        echo json_encode(["code" => 409, "error" => "Race exists for track"]);
    }
    $requestHandled = true;

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/\/track\/scrape$/', $request)) {
    echo json_encode(scrapeTrackData());
    $requestHandled = true;

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/\/track\/(\d+)\/races$/', $request, $matches)) {
    $trackId = (int)$matches[1];
    $races = getRacesByTrackId($pdo, $trackId);
    
    echo json_encode($races ? ["code" => 200, "result" => $races] : ["code" => 404, "error" => "No races found"]);
    $requestHandled = true;

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/\/race\/?$/', $request)) {
    echo json_encode(["code" => 200, "result" => getAllRaces($pdo)]);
    $requestHandled = true;

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/\/race\/(\d+)$/', $request, $matches)) {
    $raceId = (int)$matches[1];
    $race = getRaceById($pdo, $raceId);
    
    echo json_encode($race ? ["code" => 200, "result" => $race] : ["code" => 404, "error" => "Race not found"]);
    $requestHandled = true;
}

if (!$requestHandled) {
    echo json_encode(["code" => 405, "error" => "Method not allowed."]);
}

?>
