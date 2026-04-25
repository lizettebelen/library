<?php
session_start();

// Redirect to admin dashboard
// Admin work is now done in admin/dashboard.php
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin/login.php');
    exit();
} else {
    // If already admin, redirect to the admin dashboard
    header('Location: admin/dashboard.php');
    exit();
}
?>

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $author = isset($_POST['author']) ? trim($_POST['author']) : '';
    $story_type = isset($_POST['type']) ? $_POST['type'] : '';
    
    // Validate common fields
    if (empty($title) || empty($author)) {
        $error = 'Title and Author are required.';
    } else if ($story_type === 'encoded') {
        // Handle encoded story
        handleEncodedStory($conn, $title, $author, $error, $success);
    } else if ($story_type === 'file') {
        // Handle file upload
        handleFileUpload($conn, $title, $author, $error, $success);
    } else {
        $error = 'Please select a story type.';
    }
}

function handleEncodedStory(&$conn, $title, $author, &$error, &$success) {
    global $_POST;
    
    $pages = isset($_POST['pages']) ? (array) $_POST['pages'] : [];
    
    // Filter out empty pages
    $pages = array_filter($pages, function($p) { return !empty(trim($p)); });
    
    if (empty($pages)) {
        $error = 'Please add at least one page of content.';
        return;
    }
    
    // Create cover image (simple placeholder)
    $cover_image = createPlaceholderCover($title);
    
    // Insert story
    $query = "INSERT INTO stories (title, author, cover_image, type) VALUES (?, ?, ?, 'encoded')";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        $error = "Prepare failed: " . $conn->error;
        return;
    }
    
    $stmt->bind_param("sss", $title, $author, $cover_image);
    
    if (!$stmt->execute()) {
        $error = "Execute failed: " . $stmt->error;
        $stmt->close();
        return;
    }
    
    $story_id = $stmt->insert_id;
    $stmt->close();
    
    // Insert pages
    $page_number = 1;
    foreach ($pages as $content) {
        $query = "INSERT INTO pages (story_id, page_number, content) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("iis", $story_id, $page_number, $content);
        $stmt->execute();
        $stmt->close();
        $page_number++;
    }
    
    $success = 'Story created successfully! <a href="view.php?id=' . $story_id . '">Read it now</a>';
}

function handleFileUpload(&$conn, $title, $author, &$error, &$success) {
    // Check if file was uploaded
    if (!isset($_FILES['story_file'])) {
        $error = 'No file input detected. Please select a file to upload.';
        return;
    }
    
    // Check for upload errors
    if ($_FILES['story_file']['error'] !== UPLOAD_ERR_OK) {
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload blocked by extension'
        ];
        $error = $upload_errors[$_FILES['story_file']['error']] ?? 'Unknown upload error';
        return;
    }
    
    $file = $_FILES['story_file'];
    
    // Validate file was actually uploaded
    if (!is_uploaded_file($file['tmp_name'])) {
        $error = 'Invalid file upload. Please try again.';
        return;
    }
    
    // Allowed file types
    $allowed_extensions = [
        // Documents
        'pdf', 'txt', 'doc', 'docx', 'odt', 'rtf',
        // Presentations
        'ppt', 'pptx', 'odp',
        // Spreadsheets
        'xls', 'xlsx', 'ods', 'csv',
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg',
        // Videos
        'mp4', 'avi', 'mov', 'mkv', 'flv', 'wmv', 'webm', 'ogv',
        // Audio
        'mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a',
        // Archives
        'zip', 'rar', '7z', 'tar', 'gz'
    ];
    
    // Validate file type
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_ext, $allowed_extensions)) {
        $error = 'File type .'. htmlspecialchars($file_ext) . ' is not supported. Supported types: ' . implode(', ', $allowed_extensions);
        return;
    }
    
    // Check file size (100MB max)
    $max_size = 100 * 1024 * 1024; // 100MB
    if ($file['size'] > $max_size) {
        $error = 'File is too large. Maximum size is 100MB. Your file is ' . round($file['size'] / (1024 * 1024), 2) . 'MB.';
        return;
    }
    
    // Create uploads directory if it doesn't exist
    if (!is_dir('uploads')) {
        if (!mkdir('uploads', 0755, true)) {
            $error = 'Failed to create uploads directory. Check server permissions.';
            return;
        }
    }
    
    // Generate unique filename
    $new_filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file['name']));
    $file_path = 'uploads/' . $new_filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        $error = 'Failed to upload file. Check if uploads folder has write permissions.';
        return;
    }
    
    // Verify file was created
    if (!file_exists($file_path)) {
        $error = 'File was moved but verification failed. Please try again.';
        return;
    }
    
    // Verify file was created
    if (!file_exists($file_path)) {
        $error = 'File was moved but verification failed. Please try again.';
        return;
    }
    
    // Create cover image from file type
    $cover_image = createPlaceholderCover($title, $file_ext);
    
    // Insert story into database
    $query = "INSERT INTO stories (title, author, cover_image, type, file_path) VALUES (?, ?, ?, 'file', ?)";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        $error = "Database error: " . $conn->error;
        unlink($file_path);
        return;
    }
    
    $stmt->bind_param("ssss", $title, $author, $cover_image, $file_path);
    
    if (!$stmt->execute()) {
        $error = "Failed to save story to database: " . $stmt->error;
        unlink($file_path);
        $stmt->close();
        return;
    }
    
    $story_id = $stmt->insert_id;
    $stmt->close();
    
    $success = 'File uploaded successfully! Redirecting to your story in 2 seconds... <br><a href="view.php?id=' . $story_id . '" style="color: #6366f1; text-decoration: underline;">Click here if not redirected</a>';
}

function createPlaceholderCover($title, $file_ext = '') {
    // Creates a simple placeholder cover image path
    // In a production app, you could generate actual images
    $cover_dir = 'uploads/covers/';
    if (!is_dir($cover_dir)) {
        mkdir($cover_dir, 0755, true);
    }
    
    // Return different placeholder based on file type
    $type_icon = 'placeholder.jpg';
    
    if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'])) {
        $type_icon = 'image.jpg';
    } elseif (in_array($file_ext, ['mp4', 'avi', 'mov', 'mkv', 'flv', 'wmv', 'webm', 'ogv'])) {
        $type_icon = 'video.jpg';
    } elseif (in_array($file_ext, ['mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a'])) {
        $type_icon = 'audio.jpg';
    } elseif (in_array($file_ext, ['doc', 'docx', 'odt', 'rtf'])) {
        $type_icon = 'document.jpg';
    } elseif (in_array($file_ext, ['ppt', 'pptx', 'odp'])) {
        $type_icon = 'presentation.jpg';
    } elseif (in_array($file_ext, ['xls', 'xlsx', 'ods', 'csv'])) {
        $type_icon = 'spreadsheet.jpg';
    }
    
    return $cover_dir . $type_icon;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Story - Lindley's Library</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="container nav-content">
            <div class="nav-brand">
                <img src="assets/images/bdd2f027-3b4b-49f9-af69-109f1dec609b.png" alt="Lindley's Library" class="logo-image">
            </div>
            
            <div class="nav-actions">
                <a href="index.php" class="btn btn-secondary btn-sm">← Back to Library</a>
            </div>
        </div>
    </nav>

    <!-- Form Header -->
    <div class="form-header">
        <div class="container">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span class="hero-tag">Create Stories</span>
                    <h1>Create New Story</h1>
                    <p style="color: rgba(255,255,255,0.9); margin-top: 10px;">Share your narrative with the world. Every great book starts with a single word.</p>
                </div>
                <div style="font-size: 60px;">📝</div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container" style="margin: 40px auto; max-width: 900px;">
        <?php if ($error): ?>
            <div class="alert alert-error">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?php echo $success; ?>
            </div>
            <script>
                // Auto-redirect to story view after 3 seconds
                setTimeout(function() {
                    const link = document.querySelector('.alert-success a');
                    if (link) {
                        window.location.href = link.href;
                    }
                }, 3000);
            </script>
        <?php endif; ?>

        <div class="story-form-container">
            <form method="POST" enctype="multipart/form-data" id="story-form">
                <div class="form-row">
                    <!-- Left Column: Story Details -->
                    <div class="form-column">
                        <div class="form-group">
                            <label for="title">STORY TITLE</label>
                            <input type="text" id="title" name="title" required placeholder="The Echoes of Tomorrow" value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label for="author">AUTHOR NAME</label>
                            <input type="text" id="author" name="author" required placeholder="Jane Doe" value="<?php echo isset($_POST['author']) ? htmlspecialchars($_POST['author']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <label>STORY TYPE</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="type" value="encoded" checked onchange="switchStoryType()">
                                    <span>Write Story</span>
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="type" value="file" onchange="switchStoryType()">
                                    <span>Upload File</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Cover Upload -->
                    <div class="form-column">
                        <div class="form-group">
                            <label>STORY COVER</label>
                            <div class="cover-upload-area" id="cover-upload">
                                <svg style="width: 48px; height: 48px; color: #cbd5e1; margin-bottom: 10px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                                <p style="color: #64748b; margin: 0; font-weight: 600;">Upload portrait cover (3:4)</p>
                                <p style="color: #94a3b8; font-size: 0.85em; margin: 5px 0 0 0;">Drag or add file</p>
                                <input type="file" id="cover_image" name="cover_image" accept="image/*" style="display: none;">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="form-actions-inline">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('cover_image').click()">
                        <svg style="width: 16px; height: 16px; display: inline-block; margin-right: 6px; vertical-align: middle;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="17 8 12 3 7 8"></polyline>
                            <line x1="12" y1="3" x2="12" y2="15"></line>
                        </svg>
                        Upload Cover
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="generateCover()">
                        <svg style="width: 16px; height: 16px; display: inline-block; margin-right: 6px; vertical-align: middle;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 5v14M5 12h14"></path>
                        </svg>
                        Generate
                    </button>
                </div>

                <!-- Content Section -->
                <div id="encoded-section" style="margin-top: 30px;">
                    <div class="pages-section">
                        <h3 style="color: #1e293b; margin-bottom: 20px; font-size: 1.1em;">
                            <svg style="width: 20px; height: 20px; display: inline-block; margin-right: 8px; vertical-align: middle; color: #6366f1;" viewBox="0 0 24 24" fill="currentColor">
                                <circle cx="12" cy="12" r="10"></circle>
                                <text x="12" y="16" text-anchor="middle" font-size="12" fill="white" font-weight="bold">1</text>
                            </svg>
                            PAGE CONTENT
                        </h3>
                        <div id="pages-container">
                            <div class="page-input">
                                <label>Page 1</label>
                                <textarea name="pages[]" placeholder="Once upon a time on page 1..." required></textarea>
                                <button type="button" class="btn-remove-page" onclick="removePage(this)" style="display: none;">Remove This Page</button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-secondary" onclick="addPage()" style="width: 100%; margin-top: 15px; text-align: center;">
                            + Append Next Page
                        </button>
                    </div>
                </div>

                <div id="file-section" style="display: none; margin-top: 30px;">
                    <div class="form-group">
                        <label for="story_file">SELECT FILE</label>
                        <div class="file-upload-area" onclick="document.getElementById('story_file').click()">
                            <svg style="width: 48px; height: 48px; color: #cbd5e1; margin-bottom: 10px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path>
                                <polyline points="13 2 13 9 20 9"></polyline>
                            </svg>
                            <p style="color: #64748b; margin: 0; font-weight: 600;">Drag your file here or click to browse</p>
                            <p style="color: #94a3b8; font-size: 0.85em; margin: 5px 0 0 0;">Supported: Documents, Images, Videos, Audio, Archives • Max: 100MB</p>
                        </div>
                        <input type="file" id="story_file" name="story_file" accept=".pdf,.txt,.doc,.docx,.odt,.rtf,.ppt,.pptx,.odp,.xls,.xlsx,.ods,.csv,.jpg,.jpeg,.png,.gif,.webp,.bmp,.svg,.mp4,.avi,.mov,.mkv,.flv,.wmv,.webm,.ogv,.mp3,.wav,.aac,.flac,.ogg,.m4a,.zip,.rar,.7z,.tar,.gz" style="display: none;">
                    </div>
                </div>

                <!-- Form Actions -->
                <div class="form-actions-main">
                    <div>
                        <span style="color: #64748b; font-size: 0.9em;">Draft saved</span>
                    </div>
                    <div style="gap: 12px; display: flex;">
                        <a href="index.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Publish Story →</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Features Section -->
    <div class="features-section">
        <div class="container">
            <div class="features-grid">
                <div class="feature">
                    <svg style="width: 40px; height: 40px; color: #6366f1; margin-bottom: 12px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 11l3 3L22 4"></path>
                        <path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <h4>Quality Check</h4>
                    <p>Our AI analyzes your story for readability and provides formatting tips automatically.</p>
                </div>
                <div class="feature">
                    <svg style="width: 40px; height: 40px; color: #6366f1; margin-bottom: 12px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                    <h4>Copyright Policy</h4>
                    <p>Protect your intellectual property. We ensure your story rights are secured.</p>
                </div>
                <div class="feature">
                    <svg style="width: 40px; height: 40px; color: #6366f1; margin-bottom: 12px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="1"></circle>
                        <path d="M12 1v6m0 6v6"></path>
                        <path d="M4.22 4.22l4.24 4.24m3.08 3.08l4.24 4.24"></path>
                        <path d="M1 12h6m6 0h6"></path>
                        <path d="M4.22 19.78l4.24-4.24m3.08-3.08l4.24-4.24"></path>
                        <path d="M12 23v-6m0-6V1"></path>
                        <path d="M19.78 19.78l-4.24-4.24m-3.08-3.08l-4.24-4.24"></path>
                        <path d="M23 12h-6m-6 0H5"></path>
                        <path d="M19.78 4.22l-4.24 4.24m-3.08 3.08l-4.24 4.24"></path>
                    </svg>
                    <h4>Offline Mode</h4>
                    <p>Work on your drafts offline. Changes sync back when you're back online.</p>
                </div>
            </div>
        </div>
    </div>

    <footer>
        © 2024 Lindley's Library. Crafted with light and ideas.
    </footer>

    <script>
        function switchStoryType() {
            const type = document.querySelector('input[name="type"]:checked').value;
            const encodedSection = document.getElementById('encoded-section');
            const fileSection = document.getElementById('file-section');
            
            if (type === 'encoded') {
                encodedSection.style.display = 'block';
                fileSection.style.display = 'none';
                document.querySelectorAll('#encoded-section textarea').forEach(ta => ta.required = true);
                document.getElementById('story_file').required = false;
            } else {
                encodedSection.style.display = 'none';
                fileSection.style.display = 'block';
                document.querySelectorAll('#encoded-section textarea').forEach(ta => ta.required = false);
                document.getElementById('story_file').required = true;
            }
        }

        function addPage() {
            const container = document.getElementById('pages-container');
            const pageCount = container.children.length + 1;
            
            const pageDiv = document.createElement('div');
            pageDiv.className = 'page-input';
            pageDiv.innerHTML = `
                <label>Page ${pageCount}</label>
                <textarea name="pages[]" placeholder="Enter content for page ${pageCount}..."></textarea>
                <button type="button" class="btn-remove-page" onclick="removePage(this)">Remove This Page</button>
            `;
            
            container.appendChild(pageDiv);
            
            // Show remove button on all pages if more than 1
            document.querySelectorAll('.btn-remove-page').forEach(btn => {
                btn.style.display = container.children.length > 1 ? 'block' : 'none';
            });
        }

        function removePage(button) {
            button.parentElement.remove();
            const container = document.getElementById('pages-container');
            
            // Hide remove button if only 1 page left
            if (container.children.length === 1) {
                container.children[0].querySelector('.btn-remove-page').style.display = 'none';
            }
            
            // Update page numbers
            Array.from(container.children).forEach((child, index) => {
                child.querySelector('label').textContent = `Page ${index + 1}`;
                child.querySelector('textarea').placeholder = `Enter content for page ${index + 1}...`;
            });
        }

        function generateCover() {
            alert('Cover generation coming soon!');
        }

        // Handle cover image upload
        document.getElementById('cover-upload').addEventListener('click', function() {
            document.getElementById('cover_image').click();
        });

        document.getElementById('cover_image').addEventListener('change', function(e) {
            if (this.files.length > 0) {
                const fileName = this.files[0].name;
                document.getElementById('cover-upload').innerHTML = '<p style="color: #10b981; font-weight: 600;">✓ ' + fileName + ' uploaded</p>';
            }
        });

        // Handle file upload feedback
        document.getElementById('story_file').addEventListener('change', function(e) {
            if (this.files.length > 0) {
                const fileName = this.files[0].name;
                const fileSize = (this.files[0].size / (1024 * 1024)).toFixed(2);
                const fileUploadArea = document.querySelector('.file-upload-area');
                fileUploadArea.innerHTML = '<p style="color: #10b981; font-weight: 600;">✓ ' + fileName + ' selected (' + fileSize + 'MB)</p>';
            }
        });

        // Prevent form submission on Enter in textarea
        document.querySelectorAll('textarea').forEach(ta => {
            ta.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && e.ctrlKey) {
                    document.getElementById('story-form').submit();
                }
            });
        });
    </script>
</body>
</html>
