# QUICK START GUIDE

## 🚀 Get Started in 3 Minutes

### 1️⃣ Start XAMPP
- Launch XAMPP Control Panel
- Start **Apache** and **MySQL**

### 2️⃣ Setup Database
- Go to: `http://localhost/library/setup.php`
- Click **"🚀 Setup Database"**
- Wait for confirmation message

### 3️⃣ Open Library
- Go to: `http://localhost/library/`
- Start adding and reading stories!

---

## 📝 Common Tasks

### Add an Encoded Story
```
1. Click "+ Add New Story"
2. Select "✍️ Write/Encode Story"
3. Fill in Title & Author
4. Add your story content (page by page)
5. Click "Create Story"
```

### Upload a PDF/TXT Story
```
1. Click "+ Add New Story"
2. Select "📤 Upload File (PDF/TXT)"
3. Fill in Title & Author
4. Select your PDF or TXT file
5. Click "Create Story"
```

### Navigate While Reading
```
- Click "Next" / "Previous" buttons
- OR use Arrow Keys (← / →)
- Page counter shows progress
```

---

## 🔧 If Something Doesn't Work

| Issue | Solution |
|-------|----------|
| Database error | Run `setup.php` again |
| Upload fails | Check files folder permissions |
| Blank page | Clear browser cache (Ctrl+Shift+Del) |
| Connection error | Verify MySQL is running |

---

## 📂 Important Files

| File | Purpose |
|------|---------|
| `setup.php` | Initialize database |
| `index.php` | View all stories |
| `add_story.php` | Create/upload stories |
| `view.php` | Read a story |
| `config/db.php` | Database settings |

---

## 🎨 Customize

### Change Theme Colors
Edit `assets/css/style.css`, modify the `:root` section

### Change Database Name
Edit `config/db.php`, change `DB_NAME`

### Increase Upload Size
Edit your `php.ini`:
```
upload_max_filesize = 100M
post_max_size = 100M
```

---

## 📞 Need Help?

1. Check the **troubleshooting** section in `README.md`
2. Look at browser console for errors (F12)
3. Check XAMPP error logs
4. Verify database is connected via phpMyAdmin

---

**Enjoy! 📚**
