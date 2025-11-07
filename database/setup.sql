-- TikTok Campaign Launcher Database Schema
-- SQLite Database for storing images and media locally

-- Images table for storing TikTok images locally
CREATE TABLE IF NOT EXISTS images (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    image_id VARCHAR(255) UNIQUE NOT NULL,           -- TikTok image ID
    advertiser_id VARCHAR(255) NOT NULL,             -- Advertiser ID
    file_name VARCHAR(255),                          -- Original filename
    original_url TEXT,                               -- Original TikTok URL (expires)
    local_filename VARCHAR(255),                     -- Local stored filename
    file_path TEXT,                                  -- Full local file path
    file_size INTEGER,                               -- File size in bytes
    width INTEGER,                                   -- Image width
    height INTEGER,                                  -- Image height
    format VARCHAR(50),                              -- Image format (jpg, png, etc)
    mime_type VARCHAR(100),                          -- MIME type
    is_square BOOLEAN DEFAULT FALSE,                 -- Whether image is square (for avatars)
    downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_advertiser_id (advertiser_id),
    INDEX idx_image_id (image_id),
    INDEX idx_is_square (is_square)
);

-- Videos table for storing TikTok videos locally (optional future enhancement)
CREATE TABLE IF NOT EXISTS videos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    video_id VARCHAR(255) UNIQUE NOT NULL,           -- TikTok video ID
    advertiser_id VARCHAR(255) NOT NULL,             -- Advertiser ID
    file_name VARCHAR(255),                          -- Original filename
    thumbnail_url TEXT,                              -- Original thumbnail URL
    local_thumbnail_filename VARCHAR(255),           -- Local thumbnail filename
    thumbnail_path TEXT,                             -- Local thumbnail path
    duration DECIMAL(10,3),                          -- Video duration in seconds
    file_size INTEGER,                               -- File size in bytes
    width INTEGER,                                   -- Video width
    height INTEGER,                                  -- Video height
    format VARCHAR(50),                              -- Video format
    downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_video_advertiser_id (advertiser_id),
    INDEX idx_video_id (video_id)
);

-- Settings table for app configuration
CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key VARCHAR(255) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT OR IGNORE INTO settings (setting_key, setting_value, description) VALUES
('image_storage_path', 'uploads/images/', 'Path where images are stored locally'),
('max_image_size', '10485760', 'Maximum image size in bytes (10MB)'),
('supported_image_formats', 'jpg,jpeg,png,gif,webp,svg', 'Supported image formats'),
('cleanup_old_images_days', '30', 'Days after which unused images are cleaned up'),
('database_version', '1.0', 'Current database schema version');