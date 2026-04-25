# 📚 Digital Story Library System

A modern web-based digital story library system built with PHP and MySQL. Users can browse stories in a bookshelf layout, read encoded stories page-by-page, and upload PDF/TXT files to share with the community.

## 🌟 Features

- **📖 Bookshelf Layout**: Display stories in an attractive grid with cover images and metadata
- **📄 Page-by-Page Reading**: Read encoded stories with smooth navigation controls
- **📤 File Upload Support**: Upload PDF and TXT files to the library
- **✍️ Story Creation**: Add new stories directly via web form or file upload
- **🎨 Responsive Design**: Beautiful, modern UI that works on desktop and mobile devices
- **🔐 Security**: Input validation, file type verification, SQL injection prevention
- **⚡ Fast & Lightweight**: Efficient database queries with proper indexing

## 📋 Requirements

- PHP 7.2+
- MySQL 5.7+
- XAMPP (or Apache + MySQL locally)
- Modern web browser

## 🚀 Installation & Setup

### Step 1: Download/Extract Files
Ensure the project files are in your XAMPP `htdocs` directory:
```
C:\xampp\htdocs\library\
```

### Step 2: Configure Database
1. Open `setup.php` in your browser:
   ```
   http://localhost/library/setup.php
   ```
2. Click **"🚀 Setup Database"** button
3. The script will automatically create the database and tables

**Alternative (Manual Setup):**
1. Open phpMyAdmin: `http://localhost/phpmyadmin/`
2. Import the `schema.sql` file from the project
3. Database will be created with all required tables

### Step 3: Adjust Database Credentials (if needed)
If you're not using the default XAMPP credentials, edit `config/db.php`:
```php
define('DB_HOST', 'localhost');    // Your database host
define('DB_USER', 'root');         // Your database user
define('DB_PASS', '');             // Your database password
define('DB_NAME', 'story_library'); // Database name
```

### Step 4: Set Permissions
Ensure the `uploads` directory is writable:
- Windows: Usually automatic
- Linux: Run `chmod 755 uploads/`

## 📁 Project Structure

```
library/
├── index.php              # Main library/bookshelf page
├── view.php               # Story reader (encoded & files)
├── add_story.php          # Add/create new stories
├── setup.php              # Database initialization
├── schema.sql             # Database schema
├── config/
│   └── db.php             # Database configuration
├── uploads/               # Uploaded files and covers
│   └── covers/            # Story cover images
├── assets/
│   ├── css/
│   │   └── style.css      # Main stylesheet
│   └── js/                # JavaScript files
└── README.md              # This file
```

## 💻 Usage Guide

### Accessing the Application
Open your browser and navigate to:
```
http://localhost/library/
```

### 📚 Viewing Stories
1. Click on any book card in the library
2. **For encoded stories**: Use Next/Previous buttons or arrow keys to navigate between pages
3. **For uploaded files**: View PDF/TXT directly in the viewer

### ✍️ Adding an Encoded Story
1. Click **"+ Add New Story"** button
2. Select **"✍️ Write/Encode Story"**
3. Enter story details:
   - **Title**: Story name
   - **Author**: Author name
   - **Pages**: Add content for each page (click "Add Another Page" to add more)
4. Click **"Create Story"**

### 📤 Uploading a Story File
1. Click **"+ Add New Story"** button
2. Select **"📤 Upload File (PDF/TXT)"**
3. Enter story details:
   - **Title**: Story name
   - **Author**: Author name
   - **File**: Select PDF or TXT file (max 50MB)
4. Click **"Create Story"**

### ⌨️ Keyboard Shortcuts
When reading encoded stories:
- **→ Right Arrow**: Next page
- **← Left Arrow**: Previous page

## 🗄️ Database Schema

### Stories Table
```sql
CREATE TABLE stories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NOT NULL,
    cover_image VARCHAR(255),
    type ENUM('encoded', 'file') NOT NULL,
    file_path VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### Pages Table (for encoded stories)
```sql
CREATE TABLE pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    story_id INT NOT NULL,
    page_number INT NOT NULL,
    content LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE CASCADE
);
```

## 🔒 Security Features

- ✓ **Prepared Statements**: Prevents SQL injection attacks
- ✓ **Input Sanitization**: All user inputs are validated and escaped
- ✓ **File Type Validation**: Only PDF and TXT files are allowed
- ✓ **MIME Type Checking**: Verifies actual file contents
- ✓ **File Size Limits**: 50MB maximum upload size
- ✓ **UTF-8 Encoding**: Proper character set handling

## 🎨 Customization

### Change Colors
Edit `assets/css/style.css` and modify the CSS variables:
```css
:root {
    --primary-color: #6366f1;      /* Primary theme color */
    --secondary-color: #8b5cf6;    /* Secondary theme color */
    --success-color: #10b981;      /* Success color */
    --danger-color: #ef4444;       /* Error/danger color */
    /* ... more colors ... */
}
```

### Change Database Credentials
Edit `config/db.php` and update the connection parameters

### Modify Upload Directory
Edit `add_story.php` and change the `$cover_dir` path in the `createPlaceholderCover()` function

## 🐛 Troubleshooting

### Database Connection Error
- Check if MySQL is running
- Verify credentials in `config/db.php`
- Run the setup script again

### File Upload Not Working
- Ensure `uploads/` directory exists and is writable
- Check `upload_max_filesize` in php.ini (should be ≥ 50MB)
- Verify file is PDF or TXT

### Blank Library Page
- Check database connection
- Run `setup.php` to initialize database
- Check browser console for JavaScript errors

### Pages Not Displaying Correctly
- Clear browser cache (Ctrl+Shift+Del)
- Check that pages have content
- Verify UTF-8 encoding in database

## 📈 Future Enhancements

- [ ] User authentication (login/register)
- [ ] Book rating and reviews
- [ ] Reading progress tracking
- [ ] Advanced search functionality
- [ ] Reading recommendations
- [ ] Dark mode theme
- [ ] Story categories/tags
- [ ] Rich text editor for story creation
- [ ] Page flip animations
- [ ] Bookmarks and favorites
- [ ] Social sharing features
- [ ] Reading statistics dashboard

## 📄 License

This project is open source and available to use freely.

## 🤝 Contributing

Feel free to fork, modify, and improve this project!

## 📞 Support

If you encounter any issues or have questions, please check:
1. The troubleshooting section above
2. Browser console for error messages
3. PHP error logs in XAMPP

---

**Happy reading! 📚✨**