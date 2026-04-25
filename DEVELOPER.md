# 👨‍💻 Developer Documentation

## Overview

This document provides technical details about the Digital Story Library system for developers who want to understand, modify, or extend the application.

## Architecture

```
Request Flow:
Browser → PHP Script → Database (MySQL) → Response HTML + CSS + JS
```

## 📂 File Structure Explained

### Core Files

| File | Purpose | Key Functions |
|------|---------|---------------|
| `index.php` | Library page | Fetches all stories, displays grid |
| `view.php` | Story viewer | Loads story data, handles page navigation |
| `add_story.php` | Create/upload stories | Validates input, saves to database |
| `setup.php` | Database setup | Creates database and tables |

### Configuration

| File | Purpose |
|------|---------|
| `config/db.php` | Database connection constants and mysqli object |

### Static Assets

| Path | Purpose |
|------|---------|
| `assets/css/style.css` | Main stylesheet (responsive design) |
| `uploads/` | User uploads (PDFs, TXTs, cover images) |

## 🔧 Key Technologies

- **Backend**: PHP 7.2+
- **Database**: MySQL 5.7+ with InnoDB
- **Frontend**: HTML5, CSS3, Vanilla JavaScript
- **Libraries**: None (framework-free)

## 📊 Database Schema

### Stories Table
```
stories (
  id: INT PRIMARY KEY AUTO_INCREMENT,
  title: VARCHAR(255) - Story name
  author: VARCHAR(255) - Author name
  cover_image: VARCHAR(255) - Path to cover image
  type: ENUM('encoded', 'file') - Story type
  file_path: VARCHAR(255) - Path to uploaded file (null if encoded)
  created_at: TIMESTAMP - Creation date
  updated_at: TIMESTAMP - Last update date
)
```

### Pages Table
```
pages (
  id: INT PRIMARY KEY AUTO_INCREMENT,
  story_id: INT FOREIGN KEY - References stories(id)
  page_number: INT - Sequential page number
  content: LONGTEXT - Page content
  created_at: TIMESTAMP - Creation date
)
```

## 🔐 Code Patterns & Security

### 1. Database Connections
```php
// Using prepared statements to prevent SQL injection
$query = "SELECT * FROM stories WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
```

### 2. Input Validation
```php
// Empty check
if (empty(trim($_POST['title']))) {
    $error = 'Title is required.';
}

// File type validation
if (!in_array($file_ext, $allowed_extensions)) {
    $error = 'Unsupported file type.';
}
```

### 3. Output Escaping
```php
// Always escape output to prevent XSS
<?php echo htmlspecialchars($story['title']); ?>
```

### 4. File Upload Safety
```php
// Verify MIME type using finfo
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);

// Validate before moving
if (in_array($mime_type, $allowed_types)) {
    move_uploaded_file($file['tmp_name'], $file_path);
}
```

## 🎯 Function Reference

### index.php

```php
// Fetch all stories
$query = "SELECT id, title, author, cover_image, type FROM stories ORDER BY created_at DESC";
$result = $conn->query($query);
```

**Example Loop:**
```php
while ($row = $result->fetch_assoc()) {
    // Access: $row['id'], $row['title'], etc.
}
```

### view.php

```php
// Get story by ID
$query = "SELECT * FROM stories WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $story_id);
$stmt->execute();

// Get pages for encoded story
$query = "SELECT * FROM pages WHERE story_id = ? ORDER BY page_number ASC";
```

**JavaScript (Page Navigation):**
```javascript
function showPage(index) {
    // Hide all pages
    document.querySelectorAll('.page').forEach(p => p.style.display = 'none');
    
    // Show selected page
    document.getElementById('page-' + pageIds[index]).style.display = 'block';
}
```

### add_story.php

```php
// Insert story
$query = "INSERT INTO stories (title, author, cover_image, type) VALUES (?, ?, ?, 'encoded')";
$stmt = $conn->prepare($query);
$stmt->bind_param("sss", $title, $author, $cover_image);
$stmt->execute();
$story_id = $stmt->insert_id;

// Insert pages
for ($i = 0; $i < count($pages); $i++) {
    $query = "INSERT INTO pages (story_id, page_number, content) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iis", $story_id, $page_number, $content);
    $stmt->execute();
}
```

## 🎨 Frontend Architecture

### CSS Organization

```css
:root {
  --primary-color: #6366f1;
  --secondary-color: #8b5cf6;
  /* ... more colors ... */
}
```

### Responsive Breakpoints

```css
@media (max-width: 768px) { /* Tablets */ }
@media (max-width: 480px) { /* Mobile */ }
```

### JavaScript Patterns

**Event Listeners:**
```javascript
document.addEventListener('keydown', function(e) {
    if (e.key === 'ArrowRight') nextPage();
    if (e.key === 'ArrowLeft') previousPage();
});
```

**DOM Manipulation:**
```javascript
// Show/hide elements
element.style.display = 'block' | 'none';

// Update text
element.textContent = 'New text';

// Add elements
container.appendChild(newElement);
```

## 🔄 Request/Response Flow

### Adding an Encoded Story

```
User fills form → JavaScript validates → Form submits (POST)
↓
add_story.php receives POST data
↓
handleEncodedStory() validates input
↓
INSERT INTO stories (creates story row)
↓
INSERT INTO pages (creates page rows)
↓
Success message with link to view.php?id=...
```

### Reading an Encoded Story

```
User clicks book card → Link to view.php?id=123
↓
view.php fetches story with id=123
↓
Check if type='encoded'
↓
Fetch all pages from pages table
↓
JavaScript renders page selector and navigation
↓
User can navigate with buttons or keyboard
```

## 📈 Performance Optimization

### Database Indexing

```sql
-- Indexes for faster queries
INDEX idx_type (type)
INDEX idx_created_at (created_at)
INDEX idx_story_id (story_id)
```

### Query Optimization

```php
// ❌ Inefficient: N+1 Query Problem
foreach ($stories as $story) {
    $pages = $conn->query("SELECT * FROM pages WHERE story_id = " . $story['id']);
}

// ✅ Efficient: Single query with JOIN
$query = "SELECT s.*, p.* FROM stories s 
          LEFT JOIN pages p ON s.id = p.story_id 
          ORDER BY s.created_at DESC";
```

## 🧪 Testing Notes

### Manual Test Cases

1. **Add Encoded Story**
   - Create story with multiple pages
   - Verify all pages saved
   - Navigate through all pages

2. **Upload PDF/TXT**
   - Upload valid PDF
   - Upload valid TXT
   - Try invalid file type (should reject)

3. **File Size**
   - Upload 5MB file (should work)
   - Try 100MB file (should reject)

4. **Database**
   - Verify story appears in library immediately
   - Delete story from database and refresh (should disappear)

## 🔧 Extending the Application

### Adding a Search Feature

```php
// In index.php
$search = $_GET['search'] ?? '';
$query = "SELECT * FROM stories WHERE title LIKE ? OR author LIKE ? ORDER BY created_at DESC";
$stmt = $conn->prepare($query);
$search_term = "%{$search}%";
$stmt->bind_param("ss", $search_term, $search_term);
$stmt->execute();
```

### Adding User Authentication

```php
// Create users table
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(255) UNIQUE,
    password VARCHAR(255),
    email VARCHAR(255),
    created_at TIMESTAMP
);

// Hash passwords
$hashed = password_hash($_POST['password'], PASSWORD_BCRYPT);
```

### Adding Comments/Reviews

```php
// Create reviews table
CREATE TABLE reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    story_id INT,
    user_id INT,
    rating INT,
    content TEXT,
    created_at TIMESTAMP,
    FOREIGN KEY (story_id) REFERENCES stories(id)
);
```

## 🚀 Deployment Considerations

### Security Checklist
- [ ] Change default database credentials
- [ ] Set proper file permissions (uploads folder: 755)
- [ ] Enable HTTPS if deploying to production
- [ ] Update CORS headers if needed
- [ ] Add rate limiting for file uploads
- [ ] Implement CSRF protection tokens

### Performance Optimization
- [ ] Enable database query caching
- [ ] Minify CSS/JavaScript
- [ ] Add CDN for static assets
- [ ] Implement pagination for large libraries
- [ ] Cache database queries

## 📝 Code Commenting Standards

```php
/**
 * Fetches all stories from database
 * 
 * @param mysqli $conn - Database connection object
 * @return array - Array of story records
 */
function getStories(&$conn) {
    $query = "SELECT * FROM stories ORDER BY created_at DESC";
    $result = $conn->query($query);
    return $result->fetch_all(MYSQLI_ASSOC);
}
```

---

## 🔗 Useful Resources

- [PHP Documentation](https://www.php.net/docs)
- [MySQL Documentation](https://dev.mysql.com/doc/)
- [MDN Web Docs](https://developer.mozilla.org/)

---

**Happy coding! 💻✨**
