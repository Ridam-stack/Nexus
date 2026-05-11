<?php

namespace App\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use App\Core\Database;

class ChatServer implements MessageComponentInterface {
    protected $clients;
    protected $userConnections = []; // userId => [conn, ...]
    protected $channelSubscriptions = []; // channelId => [conn, ...]
    protected $db;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->db = Database::getInstance()->getConnection();
        echo "Nexus Chat WebSocket Server started.\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $conn->userId = null;
        echo "New connection: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!$data || !isset($data['type'])) return;

        switch ($data['type']) {
            case 'auth':
                $this->handleAuth($from, $data);
                break;
            case 'join_channel':
                $this->handleJoinChannel($from, $data);
                break;
            case 'leave_channel':
                $this->handleLeaveChannel($from, $data);
                break;
            case 'message':
                $this->handleMessage($from, $data);
                break;
            case 'typing_start':
                $this->handleTyping($from, $data, true);
                break;
            case 'typing_stop':
                $this->handleTyping($from, $data, false);
                break;
            case 'message_delete':
                $this->handleDeleteMessage($from, $data);
                break;
            case 'message_edit':
                $this->handleEditMessage($from, $data);
                break;
            case 'reaction':
                $this->handleReaction($from, $data);
                break;
            case 'call_invite':
            case 'call_accept':
            case 'call_reject':
            case 'call_end':
            case 'call_signal':
                $this->handleCall($from, $data);
                break;
            case 'status_update':
                $this->handleStatusUpdate($from, $data);
                break;
        }
    }

    private function handleAuth($conn, $data) {
        $token = $data['token'] ?? null;
        if (!$token) return;

        $stmt = $this->db->prepare("SELECT u.* FROM users u JOIN sessions s ON u.id = s.user_id WHERE s.id = :token");
        $stmt->execute(['token' => $token]);
        $user = $stmt->fetch();

        if ($user) {
            $conn->userId = $user['id'];
            $conn->username = $user['username'];
            $conn->userData = $user;

            if (!isset($this->userConnections[$user['id']])) {
                $this->userConnections[$user['id']] = [];
            }
            $this->userConnections[$user['id']][] = $conn;

            // Update user status to online
            $this->db->prepare("UPDATE users SET status = 'online' WHERE id = :id")->execute(['id' => $user['id']]);

            $conn->send(json_encode(['type' => 'auth_success', 'user' => ['id' => $user['id'], 'username' => $user['username']]]));

            // Broadcast online status to friends
            $this->broadcastStatusToFriends($user['id'], 'online');
            echo "User {$user['username']} authenticated\n";
        } else {
            $conn->send(json_encode(['type' => 'auth_error', 'message' => 'Invalid token']));
        }
    }

    private function handleJoinChannel($conn, $data) {
        if (!$conn->userId) return;
        $channelId = $data['channel_id'] ?? null;
        if (!$channelId) return;

        if (!isset($this->channelSubscriptions[$channelId])) {
            $this->channelSubscriptions[$channelId] = new \SplObjectStorage;
        }
        $this->channelSubscriptions[$channelId]->attach($conn);
        $conn->currentChannel = $channelId;
        echo "User {$conn->username} joined channel {$channelId}\n";
    }

    private function handleLeaveChannel($conn, $data) {
        $channelId = $data['channel_id'] ?? null;
        if ($channelId && isset($this->channelSubscriptions[$channelId])) {
            $this->channelSubscriptions[$channelId]->detach($conn);
        }
    }

    private function handleMessage($from, $data) {
        if (!$from->userId) return;

        $channelId = $data['channel_id'] ?? null;
        $content = htmlspecialchars($data['content'] ?? '', ENT_QUOTES, 'UTF-8');
        $replyTo = $data['reply_to'] ?? null;

        if (empty(trim($content))) return;

        // Save to DB
        $stmt = $this->db->prepare("INSERT INTO messages (channel_id, sender_id, content, reply_to) VALUES (:channel_id, :sender_id, :content, :reply_to)");
        $stmt->execute(['channel_id' => $channelId, 'sender_id' => $from->userId, 'content' => $content, 'reply_to' => $replyTo]);
        $messageId = $this->db->lastInsertId();

        $payload = json_encode([
            'type' => 'new_message',
            'message' => [
                'id' => $messageId,
                'channel_id' => $channelId,
                'sender_id' => $from->userId,
                'sender_username' => $from->username,
                'sender_avatar' => $from->userData['avatar'] ?? null,
                'content' => $content,
                'reply_to' => $replyTo,
                'created_at' => date('Y-m-d H:i:s'),
                'is_edited' => false
            ]
        ]);

        $this->broadcastToChannel($channelId, $payload);
    }

    private function handleTyping($from, $data, $isTyping) {
        if (!$from->userId) return;
        $channelId = $data['channel_id'] ?? null;

        $payload = json_encode([
            'type' => $isTyping ? 'typing_start' : 'typing_stop',
            'user_id' => $from->userId,
            'username' => $from->username,
            'channel_id' => $channelId
        ]);

        $this->broadcastToChannel($channelId, $payload, $from);
    }

    private function handleDeleteMessage($from, $data) {
        if (!$from->userId) return;
        $msgId = $data['message_id'] ?? null;

        $stmt = $this->db->prepare("SELECT * FROM messages WHERE id = :id");
        $stmt->execute(['id' => $msgId]);
        $msg = $stmt->fetch();

        if ($msg && $msg['sender_id'] == $from->userId) {
            $this->db->prepare("DELETE FROM messages WHERE id = :id")->execute(['id' => $msgId]);
            $this->broadcastToChannel($msg['channel_id'], json_encode([
                'type' => 'message_deleted',
                'message_id' => $msgId,
                'channel_id' => $msg['channel_id']
            ]));
        }
    }

    private function handleEditMessage($from, $data) {
        if (!$from->userId) return;
        $msgId = $data['message_id'] ?? null;
        $content = htmlspecialchars($data['content'] ?? '', ENT_QUOTES, 'UTF-8');

        $stmt = $this->db->prepare("SELECT * FROM messages WHERE id = :id AND sender_id = :sender_id");
        $stmt->execute(['id' => $msgId, 'sender_id' => $from->userId]);
        $msg = $stmt->fetch();

        if ($msg) {
            $this->db->prepare("UPDATE messages SET content = :content, is_edited = 1 WHERE id = :id")->execute(['content' => $content, 'id' => $msgId]);
            $this->broadcastToChannel($msg['channel_id'], json_encode([
                'type' => 'message_edited',
                'message_id' => $msgId,
                'content' => $content,
                'channel_id' => $msg['channel_id']
            ]));
        }
    }

    private function handleReaction($from, $data) {
        if (!$from->userId) return;
        $msgId = $data['message_id'] ?? null;
        $emoji = htmlspecialchars($data['emoji'] ?? '', ENT_QUOTES, 'UTF-8');

        $stmt = $this->db->prepare("SELECT * FROM messages WHERE id = :id");
        $stmt->execute(['id' => $msgId]);
        $msg = $stmt->fetch();

        if ($msg) {
            $existStmt = $this->db->prepare("SELECT id FROM reactions WHERE message_id = :mid AND user_id = :uid AND emoji = :emoji");
            $existStmt->execute(['mid' => $msgId, 'uid' => $from->userId, 'emoji' => $emoji]);
            $existing = $existStmt->fetch();

            $action = $existing ? 'removed' : 'added';
            if ($existing) {
                $this->db->prepare("DELETE FROM reactions WHERE id = :id")->execute(['id' => $existing['id']]);
            } else {
                $this->db->prepare("INSERT INTO reactions (message_id, user_id, emoji) VALUES (:mid, :uid, :emoji)")->execute(['mid' => $msgId, 'uid' => $from->userId, 'emoji' => $emoji]);
            }

            $this->broadcastToChannel($msg['channel_id'], json_encode([
                'type' => 'reaction_update',
                'message_id' => $msgId,
                'emoji' => $emoji,
                'user_id' => $from->userId,
                'action' => $action
            ]));
        }
    }

    private function handleCall($from, $data) {
        if (!$from->userId) return;
        $targetUserId = $data['target_user_id'] ?? null;
        if (!$targetUserId || !isset($this->userConnections[$targetUserId])) return;

        $payload = json_encode(array_merge($data, ['from_user_id' => $from->userId, 'from_username' => $from->username]));
        foreach ($this->userConnections[$targetUserId] as $conn) {
            $conn->send($payload);
        }
    }

    private function handleStatusUpdate($from, $data) {
        if (!$from->userId) return;
        $status = $data['status'] ?? 'online';
        $allowed = ['online', 'offline', 'idle', 'dnd'];
        if (!in_array($status, $allowed)) return;

        $this->db->prepare("UPDATE users SET status = :status WHERE id = :id")->execute(['status' => $status, 'id' => $from->userId]);
        $this->broadcastStatusToFriends($from->userId, $status);
    }

    private function broadcastToChannel($channelId, $payload, $exclude = null) {
        if (!isset($this->channelSubscriptions[$channelId])) return;
        foreach ($this->channelSubscriptions[$channelId] as $client) {
            if ($exclude && $client === $exclude) continue;
            $client->send($payload);
        }
    }

    private function broadcastStatusToFriends($userId, $status) {
        $stmt = $this->db->prepare("SELECT user_id, friend_id FROM friendships WHERE (user_id = :uid OR friend_id = :uid2) AND status = 'accepted'");
        $stmt->execute(['uid' => $userId, 'uid2' => $userId]);
        $friends = $stmt->fetchAll();

        $payload = json_encode(['type' => 'status_update', 'user_id' => $userId, 'status' => $status]);

        foreach ($friends as $f) {
            $friendId = $f['user_id'] == $userId ? $f['friend_id'] : $f['user_id'];
            if (isset($this->userConnections[$friendId])) {
                foreach ($this->userConnections[$friendId] as $conn) {
                    $conn->send($payload);
                }
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);

        if ($conn->userId) {
            // Remove from user connections
            if (isset($this->userConnections[$conn->userId])) {
                $this->userConnections[$conn->userId] = array_filter(
                    $this->userConnections[$conn->userId],
                    fn($c) => $c !== $conn
                );
                if (empty($this->userConnections[$conn->userId])) {
                    unset($this->userConnections[$conn->userId]);
                    $this->db->prepare("UPDATE users SET status = 'offline' WHERE id = :id")->execute(['id' => $conn->userId]);
                    $this->broadcastStatusToFriends($conn->userId, 'offline');
                }
            }

            // Remove from channel subscriptions
            foreach ($this->channelSubscriptions as $channelId => $subscribers) {
                $subscribers->detach($conn);
            }
        }

        echo "Connection {$conn->resourceId} closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }
}
