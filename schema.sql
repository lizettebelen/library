-- Digital Story Library Database Schema

-- Drop existing tables if they exist
DROP TABLE IF EXISTS chapter_page_media;
DROP TABLE IF EXISTS story_header_media;
DROP TABLE IF EXISTS pages;
DROP TABLE IF EXISTS stories;
DROP TABLE IF EXISTS users;

-- Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stories Table
CREATE TABLE stories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NOT NULL,
    genre VARCHAR(100) DEFAULT NULL,
    cover_image VARCHAR(255),
    header_media_path VARCHAR(255) DEFAULT NULL,
    header_media_type ENUM('image', 'video') DEFAULT NULL,
    type ENUM('encoded', 'file') NOT NULL,
    file_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_genre (genre),
    INDEX idx_type (type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pages Table (for encoded stories only)
CREATE TABLE pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    story_id INT NOT NULL,
    page_number INT NOT NULL,
    chapter_title VARCHAR(255) DEFAULT NULL,
    content LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE,
    UNIQUE KEY unique_story_page (story_id, page_number),
    INDEX idx_story_id (story_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Story Header Media Table
CREATE TABLE story_header_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    story_id INT NOT NULL,
    media_path VARCHAR(255) NOT NULL,
    media_type ENUM('image','video') NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_story_media_order (story_id, sort_order),
    CONSTRAINT fk_story_header_media_story
        FOREIGN KEY (story_id) REFERENCES stories(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chapter Page Media Table
CREATE TABLE chapter_page_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    story_id INT NOT NULL,
    page_id INT NOT NULL,
    media_path VARCHAR(255) NOT NULL,
    media_type ENUM('image','video') NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chapter_media_page (page_id, sort_order),
    INDEX idx_chapter_media_story (story_id),
    CONSTRAINT fk_chapter_media_story
        FOREIGN KEY (story_id) REFERENCES stories(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_chapter_media_page
        FOREIGN KEY (page_id) REFERENCES pages(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Data (Optional)
-- Uncomment the following to add sample data

/*
INSERT INTO stories (title, author, genre, cover_image, type) VALUES 
('The Lost City', 'John Smith', 'Fantasy', 'uploads/covers/placeholder.jpg', 'encoded'),
('Mystery at Midnight', 'Jane Doe', 'Mystery', 'uploads/covers/placeholder.jpg', 'encoded');

INSERT INTO pages (story_id, page_number, chapter_title, content) VALUES
(1, 1, 'Chapter 1: The Beginning', 'In a land far away, there was a city hidden beneath the desert sands. Nobody knew how it came to be there, and few dared to search for it.'),
(1, 2, 'Chapter 2: The Discovery', 'One day, a young archaeologist named Sarah stumbled upon an ancient map that could lead her to the lost city. Against all odds, she decided to embark on this dangerous journey.'),
(2, 1, 'Part 1: The First Night', 'It was a dark and stormy night. Lightning crackled across the sky as Maria heard a strange noise coming from somewhere in the old mansion.'),
(2, 2, 'Part 2: The Investigation', 'Rather than running away, Maria decided to investigate. What she discovered would change everything she thought she knew about her family.');
*/
