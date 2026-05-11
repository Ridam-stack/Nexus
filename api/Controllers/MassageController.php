<?php

namespace App\Controllers;

use App\Core\Database;

class MessageController {
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

    public function getChannelMessages($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $channelId = $params['channel_id'] ?? null;
        $before = $_GET['before'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 50), 100);

        $sql = "SELECT m.*, u.username as sender_username, u.avatar as sender_avatar,
                    (SELECT JSON_ARRAYAGG(JSON_OBJECT('emoji', r.emoji, 'count', COUNT(*), 'user_reacted', MAX(r.user_id = :uid))) 
                     FROM reactions r WHERE r.message_id = m.id GROUP BY r.emoji) as reactions_data
                FROM messages m 
                JOIN users u ON m.sender_id = u.id 
                WHERE m.channel_id = :channel_id";
        
        $binds = ['channel_id' => $channelId, 'uid' => $user['id']];
        if ($before) { $sql .= " AND m.id < :before"; $binds['before'] = $before; }
        $sql .= " ORDER BY m.created_at DESC LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':channel_id', $channelId, \PDO::PARAM_INT);
        $stmt->bindValue(':uid', $user['id'], \PDO::PARAM_INT);
        if ($before) $stmt->bindValue(':before', $before, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $messages = array_reverse($stmt->fetchAll());

        echo json_encode(['messages' => $messages]);
    }

    public function sendMessage($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $data = json_decode(file_get_contents("php://input"), true);
        $channelId = $params['channel_id'] ?? null;
        
        if (empty(trim($data['content'] ?? ''))) {
            http_response_code(400); echo json_encode(['error' => 'Message cannot be empty']); return;
        }

        // XSS protection
        $content = htmlspecialchars($data['content'], ENT_QUOTES, 'UTF-8');

        $stmt = $this->db->prepare("INSERT INTO messages (channel_id, sender_id, content, reply_to) VALUES (:channel_id, :sender_id, :content, :reply_to)");
        $stmt->execute([
            'channel_id' => $channelId,
            'sender_id' => $user['id'],
            'content' => $content,
            'reply_to' => $data['reply_to'] ?? null
        ]);
        $messageId = $this->db->lastInsertId();
        echo json_encode(['message_id' => $messageId, 'content' => $content, 'sender' => $user['username']]);
    }

    public function editMessage($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $data = json_decode(file_get_contents("php://input"), true);
        $msgId = $params['id'] ?? null;

        $stmt = $this->db->prepare("SELECT * FROM messages WHERE id = :id AND sender_id = :sender_id");
        $stmt->execute(['id' => $msgId, 'sender_id' => $user['id']]);
        if (!$stmt->fetch()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); return; }

        $content = htmlspecialchars($data['content'], ENT_QUOTES, 'UTF-8');
        $stmt = $this->db->prepare("UPDATE messages SET content = :content, is_edited = 1 WHERE id = :id");
        $stmt->execute(['content' => $content, 'id' => $msgId]);
        echo json_encode(['message' => 'Message updated']);
    }

    public function deleteMessage($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $msgId = $params['id'] ?? null;
        $stmt = $this->db->prepare("SELECT * FROM messages WHERE id = :id");
        $stmt->execute(['id' => $msgId]);
        $message = $stmt->fetch();

        if (!$message) { http_response_code(404); echo json_encode(['error' => 'Not found']); return; }

        // Allow message owner or server admin to delete
        if ($message['sender_id'] != $user['id']) {
            http_response_code(403); echo json_encode(['error' => 'Forbidden']); return;
        }

        $stmt = $this->db->prepare("DELETE FROM messages WHERE id = :id");
        $stmt->execute(['id' => $msgId]);
        echo json_encode(['message' => 'Message deleted']);
    }

    public function reactToMessage($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $data = json_decode(file_get_contents("php://input"), true);
        $msgId = $params['id'] ?? null;
        $emoji = htmlspecialchars($data['emoji'] ?? '', ENT_QUOTES, 'UTF-8');

        // Toggle reaction
        $stmt = $this->db->prepare("SELECT id FROM reactions WHERE message_id = :msg_id AND user_id = :user_id AND emoji = :emoji");
        $stmt->execute(['msg_id' => $msgId, 'user_id' => $user['id'], 'emoji' => $emoji]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $this->db->prepare("DELETE FROM reactions WHERE id = :id");
            $stmt->execute(['id' => $existing['id']]);
            echo json_encode(['action' => 'removed']);
        } else {
            $stmt = $this->db->prepare("INSERT INTO reactions (message_id, user_id, emoji) VALUES (:msg_id, :user_id, :emoji)");
            $stmt->execute(['msg_id' => $msgId, 'user_id' => $user['id'], 'emoji' => $emoji]);
            echo json_encode(['action' => 'added']);
        }
    }

    public function getDMs($params = []) {
        $user = $this->getAuthUser();
        if (!$user) { http_response_code(401); echo json_encode(['error' => 'Unauthorized']); return; }

        $otherId = $params['user_id'] ?? null;
        $limit = 50;

        $stmt = $this->db->prepare("
            SELECT m.*, u.username as sender_username, u.avatar as sender_avatar
            FROM messages m
            JOIN users u ON m.sender_id = u.id
            WHERE (m.sender_id = :uid AND m.receiver_id = :other_id)
               OR (m.sender_id = :other_id2 AND m.receiver_id = :uid2)
            ORDER BY m.created_at DESC LIMIT :limit
        ");
        $stmt->bindValue(':uid', $user['id'], \PDO::PARAM_INT);
        $stmt->bindValue(':uid2', $user['id'], \PDO::PARAM_INT);
        $stmt->bindValue(':other_id', $otherId, \PDO::PARAM_INT);
        $stmt->bindValue(':other_id2', $otherId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode(['messages' => array_reverse($stmt->fetchAll())]);
    }
}
