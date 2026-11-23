<?php
declare(strict_types=1);
namespace KMM\Core;
/** Sender-only trash lifecycle. Recipient ownership is completely independent. */
final class MessageSenderSchema {
    public static function statements():array {return [
        "CREATE TABLE IF NOT EXISTS message_sender_states (message_id BIGINT UNSIGNED PRIMARY KEY,sender_user_id BIGINT UNSIGNED NOT NULL,trashed_at DATETIME NULL,purged_at DATETIME NULL,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_sender_trash(sender_user_id,purged_at,trashed_at,message_id),CONSTRAINT fk_sender_state_message FOREIGN KEY(message_id) REFERENCES messages(id),CONSTRAINT fk_sender_state_user FOREIGN KEY(sender_user_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];}
}
