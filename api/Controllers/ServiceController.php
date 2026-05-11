<?php

namespace App\Controllers;

use App\Core\Database;

class ServerController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    private function getAuthUser() {
        $headers = getallheaders();
        $token = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : null;
        if (!$token) return null;
        $stmt = $this->db->prepare("SELECT u.* FROM users u JOIN sessions s ON u.id = s.user_id WHERE s.id = :token");
        $stmt->execute(['token' => $token]);
        return $stmt->fetch();
    }

    public function index($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $stmt = $this->db->prepare("
            SELECT s.*, sm.role, (SELECT COUNT(*) FROM server_members WHERE server_id = s.id) as member_count
            FROM servers s
            JOIN server_members sm ON s.id = sm.server_id
            WHERE sm.user_id = :user_id
            ORDER BY s.created_at ASC
        ");
        $stmt->execute(['user_id' => $user['id']]);
        echo json_encode(['servers' => $stmt->fetchAll()]);
    }

    public function create($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $data = json_decode(file_get_contents("php://input"), true);
        if (!isset($data['name'])) { http_response_code(400); echo json_encode(['error' => 'Server name required']); return; }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO servers (name, description, owner_id, is_public) VALUES (:name, :description, :owner_id, :is_public)");
            $stmt->execute([
                'name' => htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8'),
                'description' => htmlspecialchars($data['description'] ?? '', ENT_QUOTES, 'UTF-8'),
                'owner_id' => $user['id'],
                'is_public' => $data['is_public'] ?? 0
            ]);
            $serverId = $this->db->lastInsertId();

            // Add owner as member
            $stmt = $this->db->prepare("INSERT INTO server_members (server_id, user_id, role) VALUES (:server_id, :user_id, 'owner')");
            $stmt->execute(['server_id' => $serverId, 'user_id' => $user['id']]);

            // Create default general category & channel
            $stmt = $this->db->prepare("INSERT INTO categories (server_id, name, position) VALUES (:server_id, 'General', 0)");
            $stmt->execute(['server_id' => $serverId]);
            $catId = $this->db->lastInsertId();

            $stmt = $this->db->prepare("INSERT INTO channels (server_id, category_id, name, type) VALUES (:server_id, :category_id, 'general', 'text')");
            $stmt->execute(['server_id' => $serverId, 'category_id' => $catId]);

            $this->db->commit();
            echo json_encode(['message' => 'Server created', 'server_id' => $serverId]);
        } catch (\Exception $e) {
            $this->db->rollBack();
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function show($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $serverId = $params['id'] ?? null;
        $stmt = $this->db->prepare("
            SELECT s.*, sm.role FROM servers s
            JOIN server_members sm ON s.id = sm.server_id
            WHERE s.id = :server_id AND sm.user_id = :user_id
        ");
        $stmt->execute(['server_id' => $serverId, 'user_id' => $user['id']]);
        $server = $stmt->fetch();
        if (!$server) { http_response_code(404); echo json_encode(['error' => 'Server not found']); return; }

        // Fetch categories and channels
        $catStmt = $this->db->prepare("SELECT * FROM categories WHERE server_id = :server_id ORDER BY position ASC");
        $catStmt->execute(['server_id' => $serverId]);
        $categories = $catStmt->fetchAll();

        $chStmt = $this->db->prepare("SELECT * FROM channels WHERE server_id = :server_id ORDER BY position ASC");
        $chStmt->execute(['server_id' => $serverId]);
        $channels = $chStmt->fetchAll();

        echo json_encode(['server' => $server, 'categories' => $categories, 'channels' => $channels]);
    }
}
