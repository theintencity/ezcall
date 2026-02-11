<?php

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header('Access-Control-Max-Age: 86400');
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
    }
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }
    header("HTTP/1.1 200 OK");
    exit();
}


function initialize() {
    $pdo = new PDO('sqlite:contacts.db');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $create_table_sql = <<<EOF
CREATE TABLE IF NOT EXISTS namespaces (
    name TEXT PRIMARY KEY,
    config TEXT NOT NULL,
    vapidkey TEXT NOT NULL,
    privatekey TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS contacts (
    token TEXT PRIMARY KEY, email TEXT NOT NULL, created TEXT NOT NULL,
    namespace TEXT NOT NULL DEFAULT "ezinteract",
    contact TEXT, expires TEXT
);
EOF;

    $pdo->exec($create_table_sql);
    return $pdo;
}

function generate_uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function newtoken($pdo, $email) {
    $token = generate_uuid_v4();
    $stm = $pdo->prepare('INSERT INTO contacts (token, email, created) VALUES (?, ?, DATETIME("now"))');
    $stm->execute([$token, $email]);
}

function getnamespaceemail($pdo, $token) {
    $stm = $pdo->query('SELECT namespace, email FROM contacts WHERE token=?');
    $stm->execute([$token]);
    $row = $stm->fetch(PDO::FETCH_ASSOC);
    return $row ? array($row["namespace"], $row["email"]) : array(NULL, NULL);
}

function getconfigvapidkey($pdo, $namespace) {
    $stm = $pdo->query('SELECT config, vapidkey FROM namespaces WHERE name=?');
    $stm->execute([$namespace]);
    $row = $stm->fetch(PDO::FETCH_ASSOC);
    return $row ? array($row["config"], $row["vapidkey"]) : array(NULL, NULL);
}

function getprivatekey($pdo, $namespace) {
    $stm = $pdo->query('SELECT privatekey FROM namespaces WHERE name=?');
    $stm->execute([$namespace]);
    $row = $stm->fetch(PDO::FETCH_ASSOC);
    return $row ? $row["privatekey"] : NULL;
}

function setcontact($pdo, $token, $contact) {
    $stm = $pdo->prepare('UPDATE contacts SET contact = ?, expires = DATETIME("now", "+270 days") WHERE token = ?');
    $stm->execute([$contact, $token]);
}

function delcontact($pdo, $token) {
    $stm = $pdo->prepare('UPDATE contacts SET contact = NULL, expires = NULL WHERE token = ?');
    $stm->execute([$token]);
}

function getcontact($pdo, $token) {
    $stm = $pdo->query('SELECT contact,expires FROM contacts WHERE token=?');
    $stm->execute([$token]);
    $row = $stm->fetch(PDO::FETCH_ASSOC);
    return $row;
}

function getcontacts($pdo, $email, $instance=null) {
    $stm = $pdo->query('SELECT token,contact,expires FROM contacts WHERE email=? AND contact IS NOT NULL AND expires > DATETIME("now")');
    $stm->execute([$email]);
    $rows = $stm->fetchAll(PDO::FETCH_ASSOC);
    if (isset($instance)) {
        $rows = array_filter($rows, function($row) use ($email, $instance) {
            $checksum = crc32($email . ":" . $row["token"] . ":" . $row["contact"]);
            $ins = sprintf('%08x', $checksum);
            return $instance === $ins;
        });
    }
    return $rows;
}


function rc4($key, $str) {
    $s = array(); for ($i = 0; $i < 256; $i++) { $s[$i] = $i; }
    $j = 0;
    for ($i = 0; $i < 256; $i++) {
        $j = ($j + $s[$i] + ord($key[$i % strlen($key)])) % 256;
        $x = $s[$i]; $s[$i] = $s[$j]; $s[$j] = $x;
    }
    $i = 0; $j = 0; $res = '';
    for ($y = 0; $y < strlen($str); $y++) {
        $i = ($i + 1) % 256; $j = ($j + $s[$i]) % 256;
        $x = $s[$i]; $s[$i] = $s[$j]; $s[$j] = $x;
        $res .= $str[$y] ^ chr($s[($s[$i] + $s[$j]) % 256]);
    }
    return $res;
}

function getToken($secret, $privatekey) {
    $creds = rc4($secret, base64_decode($privatekey));
    $creds = json_decode($creds, true);

    $private_key = $creds["private_key"]; // private_key of JSON file retrieved by creating Service Account
    $client_email = $creds["client_email"]; // client_email of JSON file retrieved by creating Service Account
    $scopes = ["https://www.googleapis.com/auth/firebase.messaging"]; // Sample scope

    $url = "https://oauth2.googleapis.com/token";
    $header = array("alg" => "RS256", "typ" => "JWT");
    $now = floor(time());
    $claim = array(
        "iss" => $client_email,
        "sub" => $client_email,
        "scope" => implode(" ", $scopes),
        "aud" => $url,
        "exp" => (string)($now + 10),
        "iat" => (string)$now,
    );

    $signature = base64_encode(json_encode($header, JSON_UNESCAPED_SLASHES)) . "." . base64_encode(json_encode($claim, JSON_UNESCAPED_SLASHES));
    $b = "";
    openssl_sign($signature, $b, $private_key, "SHA256");
    $jwt = $signature . "." . base64_encode($b);

    $curl_handle = curl_init();

    curl_setopt_array($curl_handle, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => array(
            "assertion" => $jwt,
            "grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer"
        ),
    ]);

    $res = curl_exec($curl_handle);

    curl_close($curl_handle);

    $obj = json_decode($res);
    $accessToken = $obj -> {'access_token'};

    //print($accessToken . "\n");
    return $accessToken;
}

function sendto($target, $notification, $data, $namespace, $privatekey) {
    $apiUrl = 'https://fcm.googleapis.com/v1/projects/ezinteract/messages:send';
    $accessToken = getToken($namespace, $privatekey);

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ];

    // https://firebase.google.com/docs/cloud-messaging/customize-messages/setting-message-lifespan
    $firebase_data = [
        'validate_only' => false,
        'message' => [
            'token' => $target,
            'notification' => $notification,
            'android' => [ 'priority' => 'high', 'ttl' => "60s", 'collapse_key' => 'type_a', ],
            'apns' => [ 'headers' => [ 'apns-expiration' => (time() + 60) . "", ], 'payload' => [ 'aps' => [ 'sound' => 'default', 'badge' => 1, ], ], ],
            //'expiration' => 1735689600,
            'webpush' => [ 'headers' => [ 'TTL' => "60", 'Urgency' => 'high', ], ],
            'data' => $data
        ]
    ];

    // see https://stackoverflow.com/questions/76525522/fcm-http-v1-api-and-multicasting-messages-in-php
    // for curl_multi_init to optimize

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($firebase_data));

    $response = curl_exec($ch);

    curl_close($ch);

    return json_decode($response, true);

    // // Print response as JSON
    // header('Content-Type: application/json');
    // return json_encode(['response' => json_decode($response)]);
}

function senderror($code, $message) {
    http_response_code($code);
    header("Content-Type: text/plain");
    header("Access-Control-Allow-Origin: *");
    echo $message;
}

function getBearerToken() {
    $headers = apache_request_headers(); 
    if (isset($headers['Authorization'])) {
        $header = $headers['Authorization'];
        if (preg_match('/Bearer\s(\S+)/', $header, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

$token = getBearerToken();
if ($token) {
    try {
        $pdo = initialize();
        list($namespace, $email) = getnamespaceemail($pdo, $token);
        if (isset($email)) {
            $method = $_SERVER['REQUEST_METHOD'];
            if ($method === 'GET') {
                list($config, $vapidkey) = getconfigvapidkey($pdo, $namespace);
                if (!isset($config) || !isset($vapidkey)) {
                    senderror(500, "Invalid config for namespace");
                } else {
                    header('Content-Type: application/json; charset=utf-8');
                    header("Access-Control-Allow-Origin: *");
                    echo json_encode([ "email" => $email, "namespace" => $namespace, "config" => $config, "vapidkey" => $vapidkey ]);
                }
            } else if ($method === 'PUT' || $method == "POST") {
                $raw_input = file_get_contents('php://input');
                $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
                if (strpos($content_type, 'application/json') !== false) {
                    $data = json_decode($raw_input, true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        if ($method === "PUT") {
                            if (isset($data["contact"])) {
                                setcontact($pdo, $token, $data["contact"]);
                                $checksum = crc32($email . ":" . $token . ":" . $data["contact"]);
                                $instance = sprintf('%08x', $checksum);
                                header('Content-Type: application/json; charset=utf-8');
                                header("Access-Control-Allow-Origin: *");
                                echo json_encode([ "instance" => $instance ]);
                            } else { // delete
                                delcontact($pdo, $token);
                                header("Access-Control-Allow-Origin: *");
                            }
                        } else if ($method == "POST" && isset($data["to"])) {
                            if (!isset($data["data"])) {
                                $data["data"] = [];
                            }
                            $privatekey = getprivatekey($pdo, $namespace);
                            $row = getcontact($pdo, $token);
                            if (!isset($privatekey)) {
                                senderror(500, "Invalid key for namespace");
                            } else if (!isset($row) || !isset($row["contact"])) {
                                senderror(403, "Sender is not logged in");
                            } else {
                                $checksum = crc32($email . ":" . $token . ":" . $row["contact"]);
                                $instance = sprintf('%08x', $checksum);

                                $parts = explode("/", $data["to"], 2);
                                $rows = getcontacts($pdo, $parts[0], $parts[1] ?? null);
                                $responses = array();
                                $success = 0;
                                foreach ($rows as $row) {
                                    $checksum1 = crc32($parts[0] . ":" . $row["token"] . ":" . $row["contact"]);
                                    $instance1 = sprintf('%08x', $checksum1);
                                    $data1 = $data["data"];
                                    $data1["From"] = $email . "/" . $instance; // "from" is reserved, by "From" is not.
                                    $data1["To"] = $data["to"] . "/" . $instance1;

                                    $response = sendto($row["contact"], $data["notification"] ?? null, $data1, $namespace, $privatekey);
                                    if (!isset($response["error"])) {
                                        $success ++;
                                    }
                                    $responses[] = $response;
                                }

                                if ($success === 0) {
                                    senderror(404, "Target is not logged in or could not send message to any");
                                    echo json_encode($responses);
                                } else {
                                    header('Content-Type: application/json; charset=utf-8');
                                    header("Access-Control-Allow-Origin: *");
                                    echo json_encode(["count" => $success]);
                                }
                            }

                        } else {
                            senderror(400, "Missing or incorrect JSON body");
                        }
                    } else {
                        senderror(400, "Failed to parse JSON body");
                    }
                } else {
                    senderror(415, "The content-type is not supported. Use application/json.");
                }
            } else {
                http_response_code(405);
                header('Allow: GET, PUT, POST');
                header("Access-Control-Allow-Origin: *");
            }
        } else {
            senderror(401, "The authorization token is not valid. Please signup again, or use another access token.");
        }
    } catch (PDOException $e) {
        senderror(500, "Database error: " . $e->getMessage());
    }
} else {
    senderror(401, "The authorization token is missing. Please signup, or use an access token.");
}

?>