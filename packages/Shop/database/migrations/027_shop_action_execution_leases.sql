-- 프로세스 중단으로 남은 RUNNING 실행을 복구하고 웹훅 재시도 식별자를 고정한다.

ALTER TABLE shop_action_executions
    ADD COLUMN delivery_id CHAR(32) NULL AFTER execution_key,
    ADD INDEX idx_running_lease (status, started_at);

UPDATE shop_action_executions
SET delivery_id = SUBSTRING(SHA2(CONCAT(execution_key, UUID()), 256), 1, 32)
WHERE delivery_id IS NULL;

ALTER TABLE shop_action_executions
    MODIFY COLUMN delivery_id CHAR(32) NOT NULL,
    ADD UNIQUE KEY uq_action_delivery_id (delivery_id);
