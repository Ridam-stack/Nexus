<?php

namespace App\Controllers;

use App\Core\Database;

class UserController {
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

    public function updateProfile($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $data = json_decode(file_get_contents("php://input"), true);
        $allowed = ['bio', 'custom_status', 'status'];
        $updates = [];
        $binds = ['id' => $user['id']];

        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                $updates[] = "$field = :$field";
                $binds[$field] = htmlspecialchars($data[$field], ENT_QUOTES, 'UTF-8');
            }
        }

        if (empty($updates)) { echo json_encode(['message' => 'Nothing to update']); return; }

        $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($binds);
        echo json_encode(['message' => 'Profile updated']);
    }

    public function uploadAvatar($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        if (!isset($_FILES['avatar'])) { http_response_code(400); echo json_encode(['error' => 'No file uploaded']); return; }

        $file = $_FILES['avatar'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            http_response_code(400); echo json_encode(['error' => 'Invalid file type']); return;
        }

        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxSize) { http_response_code(400); echo json_encode(['error' => 'File too large']); return; }

        $uploadDir = __DIR__ . '/../../public/uploads/avatars/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'avatar_' . $user['id'] . '_' . time() . '.' . $ext;
        $destination = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $avatarUrl = '/public/uploads/avatars/' . $filename;
            $stmt = $this->db->prepare("UPDATE users SET avatar = :avatar WHERE id = :id");
            $stmt->execute(['avatar' => $avatarUrl, 'id' => $user['id']]);
            echo json_encode(['avatar_url' => $avatarUrl]);
        } else {
            http_response_code(500); echo json_encode(['error' => 'Upload failed']);
        }
    }

    public function getFriends($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $stmt = $this->db->prepare("
            SELECT u.id, u.username, u.avatar, u.status, f.status as friendship_status, f.id as friendship_id
            FROM friendships f
            JOIN users u ON (f.friend_id = u.id AND f.user_id = :uid)
                         OR (f.user_id = u.id AND f.friend_id = :uid2)
            WHERE (f.user_id = :uid3 OR f.friend_id = :uid4) AND f.status = 'accepted' AND u.id != :uid5
        ");
        $stmt->execute([
            'uid' => $user['id'], 'uid2' => $user['id'], 'uid3' => $user['id'],
            'uid4' => $user['id'], 'uid5' => $user['id']
        ]);
        echo json_encode(['friends' => $stmt->fetchAll()]);
    }

    public function sendFriendRequest($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $data = json_decode(file_get_contents("php://input"), true);
        $friendId = $data['user_id'] ?? null;

        if ($friendId == $user['id']) { http_response_code(400); echo json_encode(['error' => 'Cannot add yourself']); return; }

        $stmt = $this->db->prepare("SELECT id FROM friendships WHERE (user_id = :uid AND friend_id = :fid) OR (user_id = :fid2 AND friend_id = :uid2)");
        $stmt->execute(['uid' => $user['id'], 'fid' => $friendId, 'fid2' => $friendId, 'uid2' => $user['id']]);
        if ($stmt->fetch()) { http_response_code(409); echo json_encode(['error' => 'Request already exists']); return; }

        $stmt = $this->db->prepare("INSERT INTO friendships (user_id, friend_id, status) VALUES (:uid, :fid, 'pending')");
        $stmt->execute(['uid' => $user['id'], 'fid' => $friendId]);
        echo json_encode(['message' => 'Friend request sent']);
    }

    public function acceptFriendRequest($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $requestId = $params['id'] ?? null;
        $stmt = $this->db->prepare("UPDATE friendships SET status = 'accepted' WHERE id = :id AND friend_id = :uid AND status = 'pending'");
        $stmt->execute(['id' => $requestId, 'uid' => $user['id']]);
        echo json_encode(['message' => $stmt->rowCount() ? 'Friend request accepted' : 'Not found']);
    }

    public function updateStatus($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $data = json_decode(file_get_contents("php://input"), true);
        $status = $data['status'] ?? 'online';
        $stmt = $this->db->prepare("UPDATE users SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $status, 'id' => $user['id']]);
        echo json_encode(['message' => 'Status updated']);
    }

    public function addFriend($params = []) {
        return $this->sendFriendRequest($params);
    }
}
