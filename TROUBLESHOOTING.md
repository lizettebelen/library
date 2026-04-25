# 🐛 Troubleshooting Guide

## Common Issues & Solutions

---

## 🔴 Database Connection Errors

### Error: "Connection failed: Access denied for user 'root'"

**Cause:** Wrong database credentials

**Solution:**
1. Check `config/db.php` for credentials
2. Verify MySQL is running (XAMPP Control Panel)
3. Update credentials to match your MySQL setup:
   ```php
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   ```
4. Save and try again

---

### Error: "Unknown database 'story_library'"

**Cause:** Database hasn't been created yet

**Solution:**
1. Go to: `http://localhost/library/setup.php`
2. Click **"🚀 Setup Database"**
3. Wait for success message
4. Reload the library page

---

### Error: "MySQL has gone away"

**Cause:** MySQL connection lost or timed out

**Solution:**
1. Restart MySQL in XAMPP Control Panel
2. Reload the page
3. If persistent, check MySQL error logs

---

## 🔴 File Upload Issues

### Error: "Failed to upload file"

**Cause:** File permission issues or upload directory missing

**Solution:**
1. Ensure `uploads/` directory exists
2. Check folder permissions (should be 755):
   
   **Windows:** 
   - Right-click folder → Properties → Security → Edit → Allow Full Control
   
   **Mac/Linux:**
   ```bash
   chmod 755 uploads
   chmod 755 uploads/covers
   ```

3. Try uploading again

---

### Error: "Only PDF and TXT files are allowed"

**Cause:** Wrong file type selected

**Solution:**
1. Only PDF and TXT files can be uploaded
2. Convert your file to PDF or TXT
3. Try uploading again

**If your file IS PDF/TXT:**
- The MIME type check might be failing
- Try a different file
- Check if file is actually PDF/TXT (not mislabeled)

---

### Error: "File upload size exceeded"

**Cause:** File is larger than the limit (default 50MB)

**Solution:**
1. Compress your file
2. Or increase PHP limits in `php.ini`:
   ```ini
   upload_max_filesize = 100M
   post_max_size = 100M
   ```
3. Restart Apache after changing php.ini

---

## 🔴 Page Display Issues

### Issue: Blank page / 404 error

**Cause:** File doesn't exist or wrong URL

**Solution:**
1. Check URL: `http://localhost/library/`
2. Verify files are extracted to correct location
3. Check file permissions
4. Restart Apache

---

### Issue: Library page shows but no styling (looks ugly)

**Cause:** CSS file not loading

**Solution:**
1. Check browser console (F12)
2. Look for 404 errors on CSS file
3. Verify `assets/css/style.css` exists
4. Hard refresh: `Ctrl+Shift+Del` then reload
5. Clear browser cache

---

### Issue: Library page works but books not displaying

**Cause:** Stories table is empty or query failed

**Solution:**
1. Run setup.php again
2. Add a test story to library
3. If still blank, check MySQL has data:
   - Go to phpMyAdmin
   - Select story_library database
   - Check if stories table has rows

---

## 🔴 Story Viewing Issues

### Issue: "Page not found" when clicking story

**Cause:** Invalid story ID or story deleted

**Solution:**
1. Go back to library
2. Try another story
3. If no stories exist, add one
4. Check phpMyAdmin to see if story exists

---

### Issue: Pages not displaying (all blank when reading)

**Cause:** No pages in database for that story

**Solution:**
1. The story might be empty
2. Go back and add pages to story:
   - Edit story (if possible, currently not)
   - Or delete and recreate with content
3. For file uploads (PDF/TXT):
   - Recreate the upload

---

### Issue: PDF viewer not working / blank

**Cause:** PDF file corrupted or path wrong

**Solution:**
1. Try uploading PDF again
2. Verify PDF opens in local viewer first
3. Check file size (shouldn't be 0 bytes)
4. Try different PDF file

---

### Issue: TXT file shows as code instead of text

**Cause:** Server returning wrong MIME type

**Solution:**
1. This is normal - code display is acceptable
2. Content is still readable
3. For better formatting, convert to PDF

---

## 🔴 Form Issues

### Issue: "Required fields" error when form looks filled

**Cause:** Field has spaces only (not actual content)

**Solution:**
1. Make sure you actually typed content
2. Not just spaces or tabs
3. Click inside field and verify content
4. Try again

---

### Issue: Cannot add pages to story

**Cause:** JavaScript issue

**Solution:**
1. Refresh the page
2. Hard refresh: `Ctrl+Shift+F5`
3. Try a different browser
4. Check console for JS errors (F12)

---

### Issue: Form keeps showing errors after fixing

**Cause:** Browser cache

**Solution:**
1. Clear browser cache: `Ctrl+Shift+Del`
2. Close and reopen browser
3. Try in private/incognito mode
4. Try different browser

---

## 🔴 Navigation Issues

### Issue: Arrow keys don't work for page navigation

**Cause:** Focus not on page / JavaScript issue

**Solution:**
1. Click inside the page content area first
2. Then try arrow keys
3. Or use the buttons instead
4. Check console for JS errors (F12)

---

### Issue: Buttons disabled when shouldn't be

**Cause:** Page counter incorrect

**Solution:**
1. Refresh page
2. Check story has pages in database
3. Verify page count matches actual pages

---

## 🔴 Database Issues

### Issue: Data not saving / appearing

**Cause:** Database connection issue or query failed

**Solution:**
1. Check database connection in `config/db.php`
2. Run setup.php again
3. Check if MySQL is running
4. Look at MySQL error logs
5. Try simpler action (single page story)

---

### Issue: Duplicate stories appearing

**Cause:** Accidental double submission

**Solution:**
1. Delete duplicate from phpMyAdmin
2. In phpMyAdmin:
   - Select story_library database
   - Click stories table
   - Find duplicate rows
   - Delete manually

---

### Issue: Missing pages for story

**Cause:** Database insertion failed partially

**Solution:**
1. Go to phpMyAdmin
2. Check pages table - see what's there
3. Delete story and recreate with content
4. File type stories don't need pages table

---

## 🔴 Performance Issues

### Issue: Page loads very slowly

**Cause:** 
- Large database
- Slow internet connection
- Server resources

**Solution:**
1. Check internet speed
2. Look for slow queries in MySQL
3. Can't add indexes (already optimized)
4. For production, consider caching

---

### Issue: Timeout when uploading large file

**Cause:** PHP max execution time

**Solution:**
1. Edit `php.ini`:
   ```ini
   max_execution_time = 300
   ```
2. Restart Apache
3. Try uploading again

---

## 🟠 Configuration Issues

### Issue: Want to use different database name

**Solution:**
1. Edit `config/db.php`:
   ```php
   define('DB_NAME', 'my_library');
   ```
2. Run setup.php
3. Database will be created with new name

---

### Issue: Want to change upload directory

**Solution:**
1. Edit `add_story.php`
2. Find `$cover_dir = 'uploads/covers/';`
3. Change path:
   ```php
   $cover_dir = 'my_uploads/';
   ```
4. Create new directory with 755 permissions

---

### Issue: Setup.php won't run / stays blank

**Cause:** PHP errors or setup already ran

**Solution:**
1. Check if database already exists (it works fine)
2. Scroll down on setup page - might have content
3. Check browser console (F12) for errors
4. Try accessing index.php instead

---

## 🟡 Advanced Troubleshooting

### Check Browser Console (F12)

1. Right-click page → Inspect (or F12)
2. Click **Console** tab
3. Look for red error messages
4. Screenshot and note errors
5. Could indicate JavaScript problems

---

### Check MySQL Logs

**XAMPP MySQL error log:**
```
C:\xampp\mysql\data\mysql_error.log
```

**Look for:**
- "gone away" errors
- Permission issues
- Table doesn't exist errors

---

### Check Apache Error Logs

**XAMPP Apache error log:**
```
C:\xampp\apache\logs\error.log
```

**Look for:**
- PHP errors
- File not found
- Permission issues

---

### Database Diagnostic

Open phpMyAdmin: `http://localhost/phpmyadmin/`

Check:
1. Is `story_library` database there?
2. Does it have `stories` table?
3. Does it have `pages` table?
4. Are there any rows in stories table?
5. Are pages linked to stories correctly?

---

## 🔧 Emergency Solutions

### Nuclear Option: Start Fresh

If everything is broken:

1. **Delete database:**
   - Go to phpMyAdmin
   - Select story_library
   - Click "Drop" (delete)

2. **Clear uploads:**
   - Delete all files in `uploads/` folder

3. **Restart everything:**
   - Stop Apache and MySQL in XAMPP
   - Wait 10 seconds
   - Start Apache and MySQL again

4. **Reinitialize:**
   - Go to `http://localhost/library/setup.php`
   - Run setup again

You're back to new fresh installation!

---

### Nuclear Option: Files Corrupted

If files seem corrupted:

1. **Delete library folder completely**
2. **Re-extract project**
3. **Run setup.php**
4. **Test with simple story**

---

## 💡 Prevention Tips

1. **Regular Backups:**
   - Export database weekly from phpMyAdmin
   - Save folder manually or use Git

2. **Monitor Performance:**
   - Watch for slow queries
   - Monitor uploads folder size

3. **Keep Notes:**
   - Document any custom changes
   - Keep record of who added what

4. **Test After Changes:**
   - Always test after modifying code
   - Try adding/reading story after changes

5. **Check Permissions:**
   - Regularly verify folder permissions
   - Especially uploads folder

---

## 📞 Need Help?

### Check Documentation:
1. README.md - Full guide
2. DEVELOPER.md - Technical details
3. QUICK_START.md - Quick reference
4. INSTALLATION.md - Setup steps

### Check Browser:
1. Is JavaScript enabled?
2. What browser version?
3. Any console errors (F12)?

### Check System:
1. Is MySQL running?
2. Is Apache running?
3. What PHP version?
4. Any antivirus blocking?

---

## ✅ Testing Checklist

For each issue:
- [ ] Restart Apache & MySQL
- [ ] Clear browser cache
- [ ] Try different browser
- [ ] Check console (F12)
- [ ] Look at error logs
- [ ] Run setup.php again
- [ ] Try new fresh story

---

## Still Stuck?

1. Note the exact error message
2. Take screenshot
3. Review relevant documentation
4. Check step-by-step guide
5. Verify all prerequisites met
6. Try again with fresh setup

---

**Most issues are resolved by:**
1. Restarting Apache/MySQL
2. Clearing browser cache
3. Running setup.php
4. Checking file permissions

Try these first!

---

**Happy troubleshooting! 🔧**
