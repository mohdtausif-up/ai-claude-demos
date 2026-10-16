-- Test database used by tests/run.php (the main database is created by the image).
CREATE DATABASE IF NOT EXISTS ssshare_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON ssshare_test.* TO 'ssshare'@'%';
FLUSH PRIVILEGES;
