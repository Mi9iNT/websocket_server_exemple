<?php
// Création du socket
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

// Liaison du socket au port spécifié
$host = $argv[1];
$port = $argv[2];

if (!socket_bind($socket, $host, $port)) {
    echo "Erreur lors de la liaison du socket au port.\n";
    exit(1);
}

if (!socket_listen($socket)) {
    echo "Erreur lors de l'écoute du socket.\n";
    exit(1);
}

echo "Le serveur WebSocket est démarré sur $host:$port.\n";

// Tableau des clients connectés
$clients = array($socket);

// Tableau des correspondances entre les connexions WebSocket et les sessions utilisateur
$sessionConnections = [];

// Fonction pour envoyer un message à tous les clients connectés
function send_message($message)
{
    global $clients;
    foreach ($clients as $changed_socket) {
        @socket_write($changed_socket, $message, strlen($message));
    }
    return true;
}

// Fonction pour masquer les messages WebSocket
function mask($message)
{
    $b1 = 0x80 | (0x1 & 0x0f);
    $length = strlen($message);
    if ($length <= 125) {
        $header = pack("CC", $b1, $length);
    } elseif ($length > 125 && $length < 65536) {
        $header = pack("CCn", $b1, 126, $length);
    } elseif ($length >= 65536) {
        $header = pack("CCNN", $b1, 127, $length);
    }
    return $header . $message;
}

// Fonction pour décoder les messages WebSocket
function unmask($message)
{
    $length = ord($message[1]) & 127;
    if ($length == 126) {
        $masks = substr($message, 4, 4);
        $data = substr($message, 8);
    } elseif ($length == 127) {
        $masks = substr($message, 10, 4);
        $data = substr($message, 14);
    } else {
        $masks = substr($message, 2, 4);
        $data = substr($message, 6);
    }
    $message = "";
    for ($i = 0; $i < strlen($data); $i++) {
        $message .= $data[$i] ^ $masks[$i % 4];
    }
    return $message;
}

// Fonction pour réaliser le handshake avec les nouveaux clients
function perform_handshaking($received_header, $client_conn, $host, $port)
{
    $headers = array();
    $protocol = (stripos($host, "local.") !== false) ? "ws" : "wss";
    $lines = preg_split("/\r\n/", $received_header);
    foreach ($lines as $line) {
        $line = chop($line);
        if (preg_match("/\A(\S+): (.*)\z/", $line, $matches)) {
            $headers[$matches[1]] = $matches[2];
        }
    }
    // Vérifier si la clé "Sec-WebSocket-Key" est définie avant d'y accéder
    if (isset($headers["Sec-WebSocket-Key"])) {
        $secKey = $headers["Sec-WebSocket-Key"];
        $secAccept = base64_encode(pack("H*", sha1($secKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11")));
        $upgrade =
            "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: WebSocket\r\n" .
            "Connection: Upgrade\r\n" .
            "WebSocket-Origin: $host\r\n" .
            "WebSocket-Location: $protocol://$host:$port/websocket.php\r\n" .
            "Sec-WebSocket-Version: 13\r\n" .
            "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
        socket_write($client_conn, $upgrade, strlen($upgrade));
    } else {
        // Gérer le cas où la clé n'est pas définie
        echo "Erreur: La clé 'Sec-WebSocket-Key' n'est pas définie dans les en-têtes.\n";
    }
}

// Fonction pour extraire le PHPSESSIONID d'un message WebSocket
function parseSessionIdFromMessage($message)
{
    $search = 'PHPSESSIONID :';
    $sessionIdStart = strpos($message, $search);
    if ($sessionIdStart !== false) {
        $sessionIdStart += strlen($search);
        $sessionId = substr($message, $sessionIdStart);
        $sessionId = trim($sessionId);
        return $sessionId;
    } else {
        // Si PHPSESSIONID n'est pas trouvé dans le message, retournez null ou une valeur par défaut
        return null;
    }
}

// Boucle principale du serveur WebSocket
while (true) {
    $changed = $clients;
    $null = NULL;
    socket_select($changed, $null, $null, 0, 10);

    // Vérification des nouvelles connexions
    if (in_array($socket, $changed)) {
        $socket_new = socket_accept($socket);
        var_dump($socker_new);
        $clients[] = $socket_new;
        $header = socket_read($socket_new, 1024);

        // Extraire la PHP session ID des en-têtes de la demande WebSocket
        preg_match('/PHPSESSID=(.*?)(;|$)/', $header, $matches);
        $phpSessionId = isset($matches[1]) ? $matches[1] : null;

        // Associer la connexion WebSocket à la session utilisateur
        if ($phpSessionId) {
            $sessionConnections[$phpSessionId] = $socket_new;
        }

        perform_handshaking($header, $socket_new, $host, $port);
        $found_socket = array_search($socket, $changed);
        unset($changed[$found_socket]);
        echo "Nouvelle connexion acceptée.\n";
    }

    // Traitement des messages des clients connectés
    foreach ($changed as $changed_socket) {
        $buf = '';
        $received = socket_recv($changed_socket, $buf, 1024, 0);

        if ($received === false) {
            $found_socket = array_search($changed_socket, $clients);
            unset($clients[$found_socket]);
            echo "Client déconnecté.\n";
            continue;
        } elseif ($received > 0) {
            // Décoder le message WebSocket
            $decoded_data = unmask($buf);

            // Afficher le message reçu
            echo "Message reçu du client : $decoded_data\n";

            // Traiter les données, par exemple les encoder en JSON et renvoyer
            $response_text = mask(json_encode(["message" => "Message reçu : $decoded_data"]));

            // Utiliser la PHP session ID si nécessaire
            if ($phpSessionId) {
                echo  "PHPSESSIONID : $phpSessionId\n";
            }

            send_message($response_text);
        }
    }
}

// Fermeture du socket
socket_close($socket);
