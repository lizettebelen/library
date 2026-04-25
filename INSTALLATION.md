# 🔧 Complete Installation Guide

## Prerequisites

Before you start, make sure you have:
- ✅ XAMPP installed (or Apache + MySQL)
- ✅ Windows/Mac/Linux system
- ✅ Modern web browser (Chrome, Firefox, Edge, Safari)
- ✅ Basic knowledge of web browsers

## Step-by-Step Installation

### 1. Extract Project Files

Extract the Digital Story Library to your XAMPP htdocs directory:
```
C:\xampp\htdocs\library\
```

**File Structure Should Look Like:**
```
library/
├── index.php
├── view.php
├── add_story.php
├── setup.php
├── schema.sql
├── README.md
├── QUICK_START.md
├── DEVELOPER.md
├── INSTALLATION.md
├── .gitignore
├── config/
│   └── db.php
├── uploads/
│   └── .gitkeep
└── assets/
    ├── css/
    │   └── style.css
    └── js/
```

### 2. Start Your Local Server

#### Using XAMPP:

**Windows/Mac:**
1. Open **XAMPP Control Panel**
2. Click **Start** next to **Apache**
3. Click **Start** next to **MySQL**

**Linux:**
```bash
sudo /opt/lampp/manager-linux-x64.run
# OR
sudo service apache2 start
sudo service mysql start
```

**Verify:** Both Apache and MySQL should show as running (green)

### 3. Initialize Database

#### Method A: Automatic Setup (Recommended)

1. Open your browser and navigate to:
   ```
   http://localhost/library/setup.php
   ```
2. You'll see the setup page
3. Review the configuration details
4. Click **🚀 Setup Database**
5. Wait for success message
6. You'll see: "✓ Database initialized successfully!"

#### Method B: Manual Setup

If automatic setup doesn't work:

1. Open phpMyAdmin:
   ```
   http://localhost/phpmyadmin/
   ```
2. Click on **"Import"** tab
3. Click **"Choose File"** and select `schema.sql`
4. Click **"Import"**
5. Database is created!

### 4. Verify Installation

Navigate to the main page:
```
http://localhost/library/
```

You should see:
- ✅ Header: "📚 Digital Story Library"
- ✅ Button: "+ Add New Story"
- ✅ Message: "No stories yet. Create the first one!"
- ✅ Footer with copyright info

**If you see this, installation is complete! 🎉**

---

## Database Configuration

### Default Credentials (XAMPP)

```php
Database Host: localhost
Database User: root
Database Name: story_library
Database Password: (empty)
```

### Custom Database Credentials (if needed)

Edit `config/db.php`:

```php
<?php
define('DB_HOST', 'localhost');    // Change your host
define('DB_USER', 'root');         // Change your username
define('DB_PASS', '');             // Change your password
define('DB_NAME', 'story_library'); // Change database name
?>
```

---

## Testing Your Installation

### Test 1: Add an Encoded Story

1. Click **"+ Add New Story"**
2. Select **"✍️ Write/Encode Story"**
3. Fill in:
   - Title: "Test Story"
   - Author: "Your Name"
   - Page 1 Content: "This is my first story page!"
4. Click **"+ Add Another Page"**
5. Add Page 2: "This is the second page!"
6. Click **"Create Story"**
7. You should see success message and link to read

### Test 2: View Story

1. Return to library (click "Back to Library")
2. You should see your new story in the grid
3. Click on it
4. Read Page 1
5. Click **Next** to see Page 2
6. Click **Previous** to go back to Page 1

### Test 3: Test Keyboard Navigation

1. While reading a story
2. Press **→** (Right Arrow) to go to next page
3. Press **←** (Left Arrow) to go to previous page

**If all tests pass, your installation is working perfectly! 🚀**

---

## Troubleshooting Installation

### Problem: "Connection failed"

**Solution:**
1. Make sure MySQL is running (check XAMPP Control Panel)
2. Verify credentials in `config/db.php`
3. Try running setup.php again

### Problem: "Database already exists"

**Solution:**
1. That's fine! Just proceed to step 4 (Verify Installation)
2. Or delete the database from phpMyAdmin and run setup again

### Problem: "Upload folder not writable"

**Solution (Windows):**
1. Right-click `uploads` folder
2. Properties → Security → Edit
3. Select your user → Full Control
4. Apply & OK

**Solution (Linux/Mac):**
```bash
chmod 755 uploads
chmod 755 uploads/covers
```

### Problem: Blank page or error messages

**Solution:**
1. Clear browser cache: **Ctrl+Shift+Del**
2. Check browser console: **F12 → Console**
3. Look for red error messages
4. Take a screenshot and review

### Problem: "File not found" errors

**Verify file structure:**
```bash
# Windows (Command Prompt)
dir C:\xampp\htdocs\library\

# Mac/Linux (Terminal)
ls -la ~/htdocs/library/
```

Should see: `index.php`, `view.php`, `add_story.php`, etc.

---

## File Upload Configuration

### Increase Upload Limit

If you want to upload larger files, edit your `php.ini`:

**Windows:**
```
C:\xampp\php\php.ini
```

**Mac:**
```
/Applications/XAMPP/xamppfiles/etc/php.ini
```

**Linux:**
```
/opt/lampp/etc/php.ini
```

Find and modify these lines:
```ini
upload_max_filesize = 100M
post_max_size = 100M
max_execution_time = 300
```

Restart Apache after changes.

---

## Project Structure Explained

```
library/                    # Root directory
├── index.php              # Main library page (shows all stories)
├── view.php               # Story viewer (read books)
├── add_story.php          # Create/upload stories
├── setup.php              # Database initialization
├── schema.sql             # Database tables definition
│
├── config/                # Configuration directory
│   └── db.php             # Database connection settings
│
├── uploads/               # User uploads storage
│   ├── covers/            # Story cover images
│   └── (PDF files)        # Uploaded story files
│
├── assets/                # Static assets
│   ├── css/
│   │   └── style.css      # All styling (1000+ lines)
│   └── js/                # JavaScript (currently in HTML)
│
├── README.md              # Full documentation
├── QUICK_START.md         # Quick reference guide
├── DEVELOPER.md           # Developer documentation
├── INSTALLATION.md        # This file
└── .gitignore             # Git ignore rules
```

---

## Next Steps

After successful installation:

1. **Add Some Stories**
   - Click "+ Add New Story"
   - Create encoded stories or upload PDFs/TXTs

2. **Invite Others**
   - Share the library URL with others on your network
   - They can access it at: `http://YOUR_IP/library/`

3. **Customize (Optional)**
   - Change colors in `assets/css/style.css`
   - Update database credentials if needed
   - Add more features (see DEVELOPER.md)

4. **Backup**
   - Export database regularly from phpMyAdmin
   - Backup the `uploads/` folder

---

## Security Checklist

- [ ] Database credentials are kept private
- [ ] Uploads folder has proper permissions (755)
- [ ] PHP error display is disabled on production
- [ ] HTML/JS inputs are properly escaped
- [ ] Only PDF/TXT files are allowed for upload
- [ ] File size limits are enforced

---

## Quick Reference

### Common URLs

| Purpose | URL |
|---------|-----|
| Library Home | `http://localhost/library/` |
| Add Story | `http://localhost/library/add_story.php` |
| Setup | `http://localhost/library/setup.php` |
| phpMyAdmin | `http://localhost/phpmyadmin/` |

### Database Info

| Item | Value |
|------|-------|
| Database Name | `story_library` |
| Stories Table | `stories` |
| Pages Table | `pages` |
| Default User | `root` |
| Default Pass | (empty) |

---

## Support & Help

### Check These Files First

1. **QUICK_START.md** - Quick tasks guide
2. **README.md** - Full documentation
3. **DEVELOPER.md** - For technical details

### Common Issues

| Issue | File to Check |
|-------|---------------|
| Database issues | `config/db.php` |
| Styling problems | `assets/css/style.css` |
| Upload issues | File permissions on `uploads/` |

---

## Updating PHP/MySQL

If you update your XAMPP version:
1. Backup your database from phpMyAdmin
2. Re-run `setup.php`
3. Your data will be preserved

---

## Uninstalling

1. Delete the `library` folder from `C:\xampp\htdocs\`
2. (Optional) Drop the database from phpMyAdmin

---

**Installation Complete! Ready to start your digital library! 📚✨**

For questions or issues, refer to the README.md and DEVELOPER.md files.
