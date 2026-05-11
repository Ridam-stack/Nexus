<?php

namespace App\Controllers;

use App\Core\Database;
use PDO;

class AuthController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function register() {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['username']) || !isset($data['email']) || !isset($data['password'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            return;
        }

        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = :email OR username = :username");
        $stmt->execute(['email' => $data['email'], 'username' => $data['username']]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'User already exists']);
            return;
        }

        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

        $stmt = $this->db->prepare("INSERT INTO users (username, email, password) VALUES (:username, :email, :password)");
        if ($stmt->execute([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $hashedPassword
        ])) {
            $userId = $this->db->lastInsertId();
            
            // Generate token (simple implementation for now, should use JWT in prod)
            $token = bin2hex(random_bytes(32));
            
            $sessionStmt = $this->db->prepare("INSERT INTO sessions (id, user_id, last_activity) VALUES (:id, :user_id, :last_activity)");
            $sessionStmt->execute([
                'id' => $token,
                'user_id' => $userId,
                'last_activity' => time()
            ]);

            echo json_encode([
                'message' => 'User registered successfully',
                'token' => $token,
                'user' => [
                    'id' => $userId,
                    'username' => $data['username'],
                    'email' => $data['email']
                ]
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to register user']);
        }
    }

    public function login() {
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['email']) || !isset($data['password'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            return;
        }

        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute(['email' => $data['email']]);
        $user = $stmt->fetch();

        if ($user && password_verify($data['password'], $user['password'])) {
            $token = bin2hex(random_bytes(32));
            
            $sessionStmt = $this->db->prepare("INSERT INTO sessions (id, user_id, last_activity) VALUES (:id, :user_id, :last_activity)");
            $sessionStmt->execute([
                'id' => $token,
                'user_id' => $user['id'],
                'last_activity' => time()
            ]);

            echo json_encode([
                'message' => 'Login successful',
                'token' => $token,
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'avatar' => $user['avatar']
                ]
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid credentials']);
        }
    }

    public function me() {
        $headers = apache_request_headers();
        if (!isset($headers['Authorization'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        
        $stmt = $this->db->prepare("SELECT u.id, u.username, u.email, u.avatar, u.status FROM users u JOIN sessions s ON u.id = s.user_id WHERE s.id = :token");
        $stmt->execute(['token' => $token]);
        $user = $stmt->fetch();

        if ($user) {
            echo json_encode(['user' => $user]);
        } else {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid token']);
        }
    }
}

