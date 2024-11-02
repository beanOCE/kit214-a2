<?php

header('Content-Type: application/json');


// -------
// DB INFO
// -------
$servername = "localhost"; 
$username = "a2"; 
$password = "meow"; 
$dbname = "a2"; 

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die(json_encode(['code' => 500, 'message' => 'Database connection failed: ' . $conn->connect_error]));
}

// -----------------------------------------------
// Handle GET requests for /cars (to get all cars)
// -----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/\/cars$/', $_SERVER['REQUEST_URI'])) {
    $sql = "SELECT cars.car_id, cars.suitability, cars.reliability_factor, 
                   drivers.number AS driver_number, drivers.shortName AS driver_shortName,
                   drivers.name AS driver_name, drivers.skill AS driver_skill
            FROM cars
            LEFT JOIN drivers ON cars.driver_id = drivers.number";
    
    $result = $conn->query($sql);
    $cars = [];

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Decode suitability field
            $row['suitability'] = json_decode($row['suitability'], true);

            if ($row['driver_number'] !== null) {
                $row['driver'] = [
                    'number' => $row['driver_number'],
                    'shortName' => $row['driver_shortName'],
                    'name' => $row['driver_name'],
                    'skill' => json_decode($row['driver_skill'], true) 
                ];
            } else {
                $row['driver'] = null;
            }

            unset($row['driver_number'], $row['driver_shortName'], $row['driver_name'], $row['driver_skill']);
            
            $cars[] = $row;
        }
        echo json_encode(['code' => 200, 'result' => $cars]);
    } else {
        echo json_encode(['code' => 404, 'message' => 'No cars found.']);
    }
    exit;
}

// -------------------------------------------------
// Handle POST requests for /cars (to add a new car)
// -------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('/\/cars$/', $_SERVER['REQUEST_URI'])) {
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($input['suitability']['race'], $input['suitability']['street'], $input['reliability_factor'])) {
        echo json_encode(['code' => 400, 'message' => 'Invalid car data.']);
        exit;
    }
    
    // Do values sum to 100?
    $race = intval($input['suitability']['race']);
    $street = intval($input['suitability']['street']);
    if ($race + $street !== 100) {
        echo json_encode(['code' => 400, 'message' => 'Suitability values must sum to 100.']);
        exit;
    }
    
    $suitability = json_encode(['race' => $race, 'street' => $street]);
    $reliability_factor = intval($input['reliability_factor']);
    $driver_id = isset($input['driver_id']) ? intval($input['driver_id']) : null;

    // Does driver_id exist? Else null.
    if ($driver_id !== null) {
        $check_driver_sql = "SELECT number FROM drivers WHERE number = ?";
        $check_stmt = $conn->prepare($check_driver_sql);
        $check_stmt->bind_param("i", $driver_id);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows === 0) {
            echo json_encode(['code' => 400, 'message' => 'Invalid driver ID.']);
            $check_stmt->close();
            exit;
        }
        $check_stmt->close();
    }

    // Insert new car
    $stmt = $conn->prepare("INSERT INTO cars (driver_id, suitability, reliability_factor) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $driver_id, $suitability, $reliability_factor);

    if ($stmt->execute()) {
        echo json_encode(['code' => 201, 'message' => 'New car added successfully.']);
    } else {
        echo json_encode(['code' => 500, 'message' => 'Error: ' . $stmt->error]);
    }
    $stmt->close();
    exit;
}

// ---------------------------------
// Handle GET requests for /cars/:id 
// ---------------------------------
if (preg_match('/\/cars\/(\d+)$/', $_SERVER['REQUEST_URI'], $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $car_id = intval($matches[1]);

    // Prepare statement
    $sql = "SELECT * FROM cars WHERE car_id = ?"; 
    $stmt = $conn->prepare($sql);

    // Debug for query errors
    if ($stmt === false) {
        error_log("SQL prepare failed: " . $conn->error); 
        echo json_encode(['code' => 500, 'message' => 'Database query error.']);
        exit;
    }

    $stmt->bind_param("i", $car_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $car = $result->fetch_assoc();
        echo json_encode(['code' => 200, 'result' => $car]);
    } else {
        echo json_encode(['code' => 404, 'message' => 'Car not found.']);
    }

    $stmt->close();
    exit; 
}

// ------------------------------------
// Handle DELETE requests for /cars/:id
// ------------------------------------
if (preg_match('/\/cars\/(\d+)$/', $_SERVER['REQUEST_URI'], $matches) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $car_id = intval($matches[1]);
    
    // Prepare statement
    $stmt = $conn->prepare("DELETE FROM cars WHERE car_id = ?");
    $stmt->bind_param("i", $car_id);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo json_encode(['code' => 200, 'message' => 'Car deleted successfully.']);
        } else {
            echo json_encode(['code' => 404, 'message' => 'Car not found.']);
        }
    } else {
        echo json_encode(['code' => 500, 'message' => 'Error: ' . $stmt->error]);
    }

    $stmt->close();
    exit;
}

// ----------------------------------------
// Handle GET requests for /cars/:id/driver
// ----------------------------------------
if (preg_match('/\/cars\/(\d+)\/driver$/', $_SERVER['REQUEST_URI'], $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $car_id = intval($matches[1]);

    // Prepare statement
    $sql = "SELECT drivers.number AS driver_number, drivers.shortName AS driver_shortName,
                   drivers.name AS driver_name, drivers.skill AS driver_skill
            FROM cars
            LEFT JOIN drivers ON cars.driver_id = drivers.number
            WHERE cars.car_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $car_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $driver = $result->fetch_assoc();
        // Are driver details null?
        if (is_null($driver['driver_number']) && is_null($driver['driver_shortName']) &&
            is_null($driver['driver_name']) && is_null($driver['driver_skill'])) {
            echo json_encode(['code' => 404, 'message' => 'No driver assigned to this car.']);
        } else {
            $driver['car_id'] = $car_id;
            echo json_encode(['code' => 200, 'result' => $driver]);
        }
    } else {
        // 404 if no result
        echo json_encode(['code' => 404, 'message' => 'Car not found.']);
    }

    $stmt->close();
    exit;
}

// ----------------------------------------
// Handle PUT requests for /cars/:id/driver
// ----------------------------------------
if (preg_match('/\/cars\/(\d+)\/driver$/', $_SERVER['REQUEST_URI'], $matches) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
    $car_id = intval($matches[1]);
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate input
    if (!isset($input['driver_id'])) {
        echo json_encode(['code' => 400, 'message' => 'Invalid input. Driver ID is required.']);
        exit;
    }

    $driver_id = intval($input['driver_id']);

    // Does driver_id exist?
    $check_driver_sql = "SELECT number FROM drivers WHERE number = ?";
    $check_stmt = $conn->prepare($check_driver_sql);
    $check_stmt->bind_param("i", $driver_id);
    $check_stmt->execute();
    $check_stmt->store_result();

    if ($check_stmt->num_rows === 0) {
        echo json_encode(['code' => 400, 'message' => 'Invalid driver ID.']);
        $check_stmt->close();
        exit;
    }
    $check_stmt->close();

    // Update driver_id
    $update_sql = "UPDATE cars SET driver_id = ? WHERE car_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ii", $driver_id, $car_id);

    if ($update_stmt->execute()) {
        echo json_encode(['code' => 200, 'message' => 'Driver ID updated successfully.']);
    } else {
        echo json_encode(['code' => 500, 'message' => 'Error: ' . $update_stmt->error]);
    }
    $update_stmt->close();
    exit;
}

// -------------------------------------------
// Handle DELETE requests for /cars/:id/driver
// -------------------------------------------
if (preg_match('/\/cars\/(\d+)\/driver$/', $_SERVER['REQUEST_URI'], $matches) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $car_id = intval($matches[1]);

    // Remove driver (update to null)
    $update_sql = "UPDATE cars SET driver_id = NULL WHERE car_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("i", $car_id);

    if ($update_stmt->execute()) {
        if ($update_stmt->affected_rows > 0) {
            echo json_encode(['code' => 200, 'message' => 'Driver assignment removed successfully.']);
        } else {
            echo json_encode(['code' => 404, 'message' => 'Car not found or driver was already unassigned.']);
        }
    } else {
        echo json_encode(['code' => 500, 'message' => 'Error: ' . $update_stmt->error]);
    }
    $update_stmt->close();
    exit;
}

// ---------------------------------
// Handle PUT requests for /cars/:id
// --------------------------------- 
if (preg_match('/\/cars\/(\d+)$/', $_SERVER['REQUEST_URI'], $matches) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
    $car_id = intval($matches[1]);
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input)) {
        echo json_encode(['code' => 400, 'message' => 'Invalid car data.']);
        exit;
    }

    // Init array for updates
    $updates = [];
    if (isset($input['suitability'])) {
        $suitability = json_encode($input['suitability']);
        $updates[] = "suitability = '$suitability'";
    }
    if (isset($input['reliability_factor'])) {
        $reliability_factor = intval($input['reliability_factor']);
        $updates[] = "reliability_factor = $reliability_factor";
    }
    if (isset($input['driver_id'])) {
        $driver_id = intval($input['driver_id']);
        $updates[] = "driver_id = $driver_id";
    }

    if (count($updates) > 0) {
        // Create update statement
        $sql = "UPDATE cars SET " . implode(", ", $updates) . " WHERE car_id = $car_id";
        if ($conn->query($sql) === TRUE) {
            echo json_encode(['code' => 200, 'message' => 'Car updated successfully.']);
        } else {
            echo json_encode(['code' => 500, 'message' => 'Error: ' . $conn->error]);
        }
    } else {
        echo json_encode(['code' => 400, 'message' => 'No valid fields provided for update.']);
    }
    exit;
}

// -------------------------------
// Handle GET requests for /driver
// -------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && preg_match('/\/driver$/', $_SERVER['REQUEST_URI'])) {
    $sql = "SELECT * FROM drivers";
    $result = $conn->query($sql);
    $drivers = [];

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $drivers[] = $row;
        }
        echo json_encode(['code' => 200, 'result' => $drivers]);
    } else {
        echo json_encode(['code' => 404, 'message' => 'No drivers found.']);
    }
    exit;
}
// -------------------------------
// Get a specific driver by number
// -------------------------------
if (preg_match('/\/driver\/(\d+)$/', $_SERVER['REQUEST_URI'], $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $number = intval($matches[1]);
    $sql = "SELECT * FROM drivers WHERE number = $number";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $driver = $result->fetch_assoc();
        echo json_encode(['code' => 200, 'result' => $driver]);
    } else {
        echo json_encode(['code' => 404, 'message' => 'Driver not found.']);
    }
    exit;
}

// ----------------
// Add a new driver
// ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('/\/driver$/', $_SERVER['REQUEST_URI'])) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['number'], $input['shortName'], $input['name'], $input['skill'])) {
        echo json_encode(['code' => 400, 'message' => 'Invalid driver data.']);
        exit;
    }

    $number = $conn->real_escape_string($input['number']);
    $shortName = $conn->real_escape_string($input['shortName']);
    $name = $conn->real_escape_string($input['name']);
    $skill = $conn->real_escape_string(json_encode($input['skill']));

    $sql = "INSERT INTO drivers (number, shortName, name, skill) VALUES ('$number', '$shortName', '$name', '$skill')";

    if ($conn->query($sql) === TRUE) {
        echo json_encode(['code' => 201, 'message' => 'New driver added successfully.']);
        exit;
    } else {
        echo json_encode(['code' => 500, 'message' => 'Error: ' . $conn->error]);
        exit;
    }
}

// ---------------------------------
// Update a specific driver by numbe
// ---------------------------------r
if (preg_match('/\/driver\/(\d+)$/', $_SERVER['REQUEST_URI'], $matches) && $_SERVER['REQUEST_METHOD'] === 'PUT') {
    $number = intval($matches[1]);
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($input)) {
        echo json_encode(['code' => 400, 'message' => 'Invalid driver data.']);
        exit;
    }

    $updates = [];
    if (isset($input['shortName'])) {
        $shortName = $conn->real_escape_string($input['shortName']);
        $updates[] = "shortName = '$shortName'";
    }
    if (isset($input['name'])) {
        $name = $conn->real_escape_string($input['name']);
        $updates[] = "name = '$name'";
    }
    if (isset($input['skill'])) {
        $skill = $conn->real_escape_string(json_encode($input['skill']));
        $updates[] = "skill = '$skill'";
    }

    if (count($updates) > 0) {
        $sql = "UPDATE drivers SET " . implode(", ", $updates) . " WHERE number = $number";
        if ($conn->query($sql) === TRUE) {
            echo json_encode(['code' => 200, 'message' => 'Driver updated successfully.']);
        } else {
            echo json_encode(['code' => 500, 'message' => 'Error: ' . $conn->error]);
        }
    } else {
        echo json_encode(['code' => 400, 'message' => 'No valid fields provided for update.']);
    }
    exit;
}

// ----------------------------------
// Delete a specific driver by number
// ----------------------------------
if (preg_match('/\/driver\/(\d+)$/', $_SERVER['REQUEST_URI'], $matches) && $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $number = intval($matches[1]);
    $sql = "DELETE FROM drivers WHERE number = $number";

    if ($conn->query($sql) === TRUE) {
        echo json_encode(['code' => 200, 'message' => 'Driver deleted successfully.']);
    } else {
        echo json_encode(['code' => 500, 'message' => 'Error: ' . $conn->error]);
    }
    exit;
}


// ----------------
// GET /car/:id/lap
// ----------------
if (preg_match('/^\/api\/teams\/cars\/(\d+)\/lap$/', $_SERVER['REQUEST_URI'], $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $car_id = intval($matches[1]);
    
    // Retrieve input values
    $trackType = $_GET['trackType'] ?? 'street'; // default to "street" if not provided
    $baseLapTime = isset($_GET['baseLapTime']) ? intval($_GET['baseLapTime']) : 120; // default to 120 seconds if not provided

    // Fetch needed data for formula
    $sql = "SELECT cars.reliability_factor, cars.suitability, drivers.skill 
            FROM cars
            LEFT JOIN drivers ON cars.driver_id = drivers.number
            WHERE cars.car_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $car_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $car = $result->fetch_assoc();

        // Decode JSON 
        $suitability = json_decode($car['suitability'], true);
        $driverSkill = json_decode($car['skill'], true);

        // is driverSkill null?
        if (is_null($driverSkill)) {
            // Teapot code!
            http_response_code(418);
            echo json_encode([
                'code' => 418,
                'message' => 'This car has no assigned driver.'
            ]);
            exit;
        }

        // Determine crash possibility
        $crash = false;
        $crashChance = ($trackType === "street") 
            ? rand(0, $car['reliability_factor'] + 10) 
            : rand(0, $car['reliability_factor'] + 5);
        
        if ($crashChance > $car['reliability_factor']) {
            echo json_encode([
                'code' => 200,
                'result' => [
                    'crashed' => true,
                    'lap_time' => 0,
                    'randomness' => rand(0, 5)
                ]
            ]);
            exit;
        }

        // Calculate speed
        $suitabilityFactor = ($trackType === "street") ? $suitability['street'] : $suitability['race'];
        $driverSkillFactor = ($trackType === "street") ? $driverSkill['street'] : $driverSkill['race'];
        $reliabilityFactor = 100 - $car['reliability_factor'];

        // Average speed
        $speed = ($suitabilityFactor + $driverSkillFactor + $reliabilityFactor) / 3;

        // Final lap time
        $lapTime = $baseLapTime + (10 * ($speed / 100));

        echo json_encode([
            'code' => 200,
            'result' => [
                'crashed' => false,
                'lap_time' => $lapTime,
                'randomness' => rand(0, 5)
            ]
        ]);
    } else {
        echo json_encode(['code' => 404, 'message' => 'Car not found.']);
    }

    $stmt->close();
    exit;
}

// Handling for all other cases.
echo json_encode(['code' => 405, 'message' => 'Method not allowed.']);
$conn->close();
?>
