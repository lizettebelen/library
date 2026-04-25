# 🏗️ System Architecture

## Request Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                         USER'S BROWSER                              │
└─────────────────────────────────────────────────────────────────────┘
                              ↓↑
                     HTTP GET/POST Request
                              ↓↑
┌─────────────────────────────────────────────────────────────────────┐
│                    APACHE WEB SERVER                                │
│               (Runs on localhost:80)                                │
└─────────────────────────────────────────────────────────────────────┘
                              ↓↑
                     PHP Processing
                              ↓↑
┌─────────────────────────────────────────────────────────────────────┐
│                      PHP SCRIPTS                                    │
│                                                                     │
│  index.php ──────→ Fetch stories → Display library grid            │
│  view.php ───────→ Fetch story/pages → Render reader              │
│  add_story.php ──→ Validate form → Insert into DB                 │
│  setup.php ──────→ Create database & tables                        │
└─────────────────────────────────────────────────────────────────────┘
                              ↓↑
                    MySQLi Prepared Statements
                              ↓↑
┌─────────────────────────────────────────────────────────────────────┐
│                    MYSQL DATABASE                                  │
│                                                                     │
│  ┌──────────────────────┐    ┌──────────────────────┐             │
│  │ STORIES TABLE        │    │ PAGES TABLE          │             │
│  ├──────────────────────┤    ├──────────────────────┤             │
│  │ id (PK)              │    │ id (PK)              │             │
│  │ title                │    │ story_id (FK)        │             │
│  │ author               │    │ page_number          │             │
│  │ cover_image          │    │ content              │             │
│  │ type                 │    │ created_at           │             │
│  │ file_path            │    │                      │             │
│  │ created_at           │    │ Foreign Key ─────────┼─→ stories.id│
│  │ updated_at           │    │                      │             │
│  └──────────────────────┘    └──────────────────────┘             │
│                                                                     │
│  File Storage:                                                     │
│  ├── uploads/                                                      │
│  │   ├── covers/                                                   │
│  │   │   └── placeholder.jpg                                       │
│  │   ├── story1.pdf                                                │
│  │   ├── story2.txt                                                │
│  │   └── ...                                                       │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## File Organization

```
PROJECT ROOT (library/)
│
├── Front-end Files (User Interface)
│   ├── index.php ..................... Library page template
│   ├── view.php ...................... Reader page template
│   ├── add_story.php ................. Form page template
│   └── assets/css/style.css .......... Styling (1000+ lines)
│
├── Back-end Files (Logic & Data)
│   ├── config/db.php ................. Database connection
│   ├── setup.php ..................... Database initialization
│   └── schema.sql .................... Database structure
│
├── Data Storage
│   └── uploads/ ...................... Uploaded files & covers
│
└── Documentation
    ├── README.md
    ├── QUICK_START.md
    ├── INSTALLATION.md
    ├── DEVELOPER.md
    ├── FEATURES.md
    └── COMPLETION_SUMMARY.md
```

## Data Flow - Key Scenarios

### Scenario 1: User Views Library

```
User visits index.php
            ↓
PHP Connection to MySQL via config/db.php
            ↓
Query: SELECT * FROM stories ORDER BY created_at DESC
            ↓
MySQL returns story rows
            ↓
PHP loops through rows, generates HTML
            ↓
HTML sent to browser
            ↓
CSS styles the page
            ↓
User sees bookshelf layout
```

### Scenario 2: User Adds Encoded Story

```
User fills form (title, author, pages)
            ↓
Form submits via POST to add_story.php
            ↓
PHP validates inputs
            ↓
INSERT INTO stories (title, author, cover_image, type='encoded')
            ↓
Get INSERT ID (story_id)
            ↓
FOR EACH page:
  INSERT INTO pages (story_id, page_number, content)
            ↓
MySQL stores data
            ↓
PHP returns success message with link
            ↓
User clicks link to view.php?id=[story_id]
```

### Scenario 3: User Reads Encoded Story

```
User clicks story → view.php?id=123
            ↓
SELECT * FROM stories WHERE id = 123
            ↓
Check type = 'encoded'
            ↓
SELECT * FROM pages WHERE story_id = 123 ORDER BY page_number
            ↓
Generate HTML with all pages (hidden by default)
            ↓
JavaScript shows page 1
            ↓
User clicks Next → JavaScript shows page 2
            ↓
User presses → Arrow → JavaScript shows page 3
            ↓
Continue navigation...
```

### Scenario 4: User Uploads PDF

```
User selects PDF file
            ↓
Submits form via multipart/form-data
            ↓
PHP validates file type (MIME check)
            ↓
PHP validates file extension
            ↓
PHP moves file to uploads/ directory
            ↓
INSERT INTO stories (title, author, cover_image, type='file', file_path)
            ↓
MySQL stores file path
            ↓
User redirected to view.php?id=123
            ↓
PHP displays <iframe src="path/to/file.pdf">
            ↓
Browser renders PDF
```

## Component Interaction

```
┌──────────────┐
│   Browser    │
│              │
│ HTML + CSS + │
│ JavaScript   │
└──────┬───────┘
       │ HTTP Request
       ↓
┌──────────────┐
│ Apache/PHP   │
│              │
│ Processes    │
│ PHP code     │
└──────┬───────┘
       │ SQL Query
       ↓
┌──────────────┐
│   MySQL      │
│              │
│ Stores &     │
│ Retrieves    │
│ Data         │
└──────────────┘
```

## Application States

### 1. Initial State (No Stories)
```
index.php displays empty state message
"No stories yet. Create the first one!"
```

### 2. With Stories
```
index.php displays grid of book cards
Each card links to view.php?id=[story_id]
```

### 3. Reading Encoded Story
```
view.php displays booklet viewer
Shows current page content
Navigation buttons enabled/disabled based on position
```

### 4. Reading File (PDF/TXT)
```
view.php displays iframe with file content
Browser handles PDF/TXT rendering
```

## Database Normalization

```
STORIES (1)  ╌─────→  (Many) PAGES

One Story can have Zero or More Pages
Each Page belongs to exactly one Story

Example:
┌─ Story ID 1 ─────────────────────┐
│ Title: "The Adventure"            │
│ Author: "John Smith"              │
├── Page 1: "Once upon a time..."   │
├── Page 2: "In the forest..."      │
└── Page 3: "They found treasure"   │

┌─ Story ID 2 ─────────────────────┐
│ Title: "Mystery.pdf"              │
│ Author: "Jane Doe"                │
│ (No Pages - File based)           │
```

## Security Layers

```
┌─────────────────────────────────────┐
│         USER INPUT                  │
└────────────┬───────────────────────┘
             │
             ↓ Validation Layer
┌─────────────────────────────────────┐
│ • Required fields check             │
│ • File type validation              │
│ • MIME type verification           │
└────────────┬───────────────────────┘
             │
             ↓ Sanitization Layer
┌─────────────────────────────────────┐
│ • htmlspecialchars() for output     │
│ • trim() for inputs                 │
│ • Prepared statements for SQL       │
└────────────┬───────────────────────┘
             │
             ↓ Database & File Storage
┌─────────────────────────────────────┐
│ • MySQL foreign keys                │
│ • File size limits                  │
│ • Directory permissions             │
└─────────────────────────────────────┘
```

## CSS Architecture

```
style.css structure:
│
├── Reset & Base (Normalize browser defaults)
│
├── CSS Variables (Colors, spacing, shadows)
│   └── :root { --primary-color, --text-dark, etc. }
│
├── Layout Components
│   ├── .header
│   ├── .container
│   ├── .library-grid
│   └── .book-card
│
├── Page-Specific Styles
│   ├── Viewer styles
│   ├── Form styles
│   └── Navigation styles
│
├── Responsive Queries
│   ├── Tablet (max-width: 768px)
│   └── Mobile (max-width: 480px)
│
└── Animations & Transitions
    ├── hover effects
    ├── fade transitions
    └── smooth scrolling
```

## Performance Path

```
User clicks → Browser processes → DOM renders → CSS paints → Display
   <1ms          <50ms            <100ms        <50ms       ✓

Cached: CSS, JavaScript (inline), Images
Fresh queries: Database (indexed for <50ms)
```

## Error Handling Flow

```
User Action
    ↓
Try: Execute code
    ├─→ Success: Return result
    └─→ Error: Catch exception
         ↓
    Set error message
         ↓
    Display error to user
         ↓
    User sees helpful message
```

## File Upload Security

```
File Upload Request
    ↓
Verify MIME type using finfo_file()
    ├─→ Invalid: Reject with error
    └─→ Valid: Continue
        ↓
    Verify file extension
    ├─→ Not in whitelist: Reject
    └─→ Valid: Continue
        ↓
    Move to uploads/ directory
    ├─→ Move fails: Error message
    └─→ Move success: Save path to DB
```

---

## Summary

The system follows a classic 3-tier architecture:

1. **Presentation Tier** (Frontend)
   - HTML templates + CSS styling
   - JavaScript for interactivity
   - Responsive design

2. **Business Logic Tier** (Backend)
   - PHP scripts for processing
   - Validation & sanitization
   - File handling

3. **Data Tier** (Database)
   - MySQL tables with relationships
   - Prepared statements
   - Indexed queries

All components work together to create a secure, efficient, and user-friendly digital story library!
