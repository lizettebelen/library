<?php
// Redirect to admin dashboard
header('Location: dashboard.php');
exit();
?>


$error = '';
$success = '';

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
    if (!isset($_FILES['story_file'])) {
        $error = 'No file input detected. Please select a file to upload.';
        return;
    }
    
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
    
    if (!is_uploaded_file($file['tmp_name'])) {
        $error = 'Invalid file upload. Please try again.';
        return;
    }
    
    $allowed_extensions = [
        'pdf', 'txt', 'doc', 'docx', 'odt', 'rtf',
        'ppt', 'pptx', 'odp',
        'xls', 'xlsx', 'ods', 'csv',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg',
        'mp4', 'avi', 'mov', 'mkv', 'flv', 'wmv', 'webm', 'ogv',
        'mp3', 'wav', 'aac', 'flac', 'ogg', 'm4a',
        'zip', 'rar', '7z', 'tar', 'gz'
    ];
    
    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_ext, $allowed_extensions)) {
        $error = 'File type .' . htmlspecialchars($file_ext) . ' is not supported.';
        return;
    }
    
    $max_size = 100 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        $error = 'File is too large. Maximum size is 100MB.';
        return;
    }
    
    if (!is_dir('uploads')) {
        if (!mkdir('uploads', 0755, true)) {
            $error = 'Failed to create uploads directory.';
            return;
        }
    }
    
    $new_filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file['name']));
    $file_path = 'uploads/' . $new_filename;
    
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        $error = 'Failed to upload file.';
        return;
    }
    
    if (!file_exists($file_path)) {
        $error = 'File was moved but verification failed.';
        return;
    }
    
    $cover_image = createPlaceholderCover($title, $file_ext);
    
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
    }S
    
    $story_id = $stmt->insert_id;
    $stmt->close();
    
    $success = 'File uploaded successfully! Redirecting to your story in 2 seconds... <br><a href="view.php?id=' . $story_id . '" style="color: #6366f1; text-decoration: underline;">Click here if not redirected</a>';
}

function createPlaceholderCover($title, $file_ext = '') {
    $cover_dir = 'uploads/covers/';
    if (!is_dir($cover_dir)) {
        mkdir($cover_dir, 0755, true);
    }
    
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
    <title>Admin Panel - Lindley's Library</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="container nav-content">
            <div class="nav-brand">
                <img src="assets/images/bdd2f027-3b4b-49f9-af69-109f1dec609b.png" alt="Lindley's Library" class="logo-image">
            </div>
            
            <div class="nav-actions">
                <span style="color: #64748b; font-weight: 500;">Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?> (Admin)</span>
                <a href="logout.php" class="btn btn-secondary btn-sm">Logout ↗</a>
            </div>
        </div>
    </nav>

    <!-- Form Header -->
    <div class="form-header">
        <div class="container">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span class="hero-tag">Admin Panel</span>
                    <h1>Create New Story</h1>
                    <p style="color: rgba(255,255,255,0.9); margin-top: 10px;">Only Lindley can add stories to the library.</p>
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
                                <div id="toolbar-0" class="ql-toolbar ql-snow" style="border: 1px solid #e2e8f0; border-bottom: none; border-radius: 6px 6px 0 0; background: #f8fafc;">
                                    <span class="ql-formats">
                                        <select class="ql-font"><option selected>Times New Roman</option><option value="helvetica">Helvetica</option><option value="courier">Courier New</option><option value="georgia">Georgia</option><option value="verdana">Verdana</option></select>
                                        <select class="ql-size"><option value="small"></option><option selected>Normal</option><option value="large"></option><option value="huge"></option></select>
                                    </span>
                                    <span class="ql-formats">
                                        <button class="ql-bold"></button>
                                        <button class="ql-italic"></button>
                                        <button class="ql-underline"></button>
                                        <button class="ql-strike"></button>
                                    </span>
                                    <span class="ql-formats">
                                        <select class="ql-color"></select>
                                        <select class="ql-background"></select>
                                    </span>
                                </div>
                                <div id="editor-0" class="ql-editor" style="border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 6px 6px; min-height: 200px;"></div>
                                <textarea name="pages[]" style="display: none;"></textarea>
                                <button type="button" class="btn-remove-page" onclick="removePage(this)" style="display: none; margin-top: 10px;">Remove This Page</button>
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

    <footer>
        © 2026 Lindley's Library. Crafted with light and ideas.
    </footer>

    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script>
        const editors = {};

        function initQuillEditor(index) {
            if (!editors[index]) {
                editors[index] = new Quill(`#editor-${index}`, {
                    theme: 'snow',
                    placeholder: `Enter content for page ${index + 1}...`,
                    modules: {
                        toolbar: `#toolbar-${index}`
                    }
                });
                
                // Sync content on change
                editors[index].on('text-change', function() {
                    const textarea = document.querySelector(`#editor-${index}`).parentElement.querySelector('textarea');
                    textarea.value = editors[index].root.innerHTML;
                });
            }
        }

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
                <div id="toolbar-${pageCount - 1}" class="ql-toolbar ql-snow" style="border: 1px solid #e2e8f0; border-bottom: none; border-radius: 6px 6px 0 0; background: #f8fafc;">
                    <span class="ql-formats">
                        <select class="ql-font"><option selected>Times New Roman</option><option value="helvetica">Helvetica</option><option value="courier">Courier New</option><option value="georgia">Georgia</option><option value="verdana">Verdana</option></select>
                        <select class="ql-size"><option value="small"></option><option selected>Normal</option><option value="large"></option><option value="huge"></option></select>
                    </span>
                    <span class="ql-formats">
                        <button class="ql-bold"></button>
                        <button class="ql-italic"></button>
                        <button class="ql-underline"></button>
                        <button class="ql-strike"></button>
                    </span>
                    <span class="ql-formats">
                        <select class="ql-color"></select>
                        <select class="ql-background"></select>
                    </span>
                </div>
                <div id="editor-${pageCount - 1}" class="ql-editor" style="border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 6px 6px; min-height: 200px;"></div>
                <textarea name="pages[]" style="display: none;"></textarea>
                <button type="button" class="btn-remove-page" onclick="removePage(this)" style="margin-top: 10px;">Remove This Page</button>
            `;
            
            container.appendChild(pageDiv);
            
            initQuillEditor(pageCount - 1);
            
            document.querySelectorAll('.btn-remove-page').forEach(btn => {
                btn.style.display = container.children.length > 1 ? 'block' : 'none';
            });
        }

        function removePage(button) {
            button.parentElement.remove();
            const container = document.getElementById('pages-container');
            
            if (container.children.length === 1) {
                container.children[0].querySelector('.btn-remove-page').style.display = 'none';
            }
            
            Array.from(container.children).forEach((child, index) => {
                child.querySelector('label').textContent = `Page ${index + 1}`;
                child.querySelector('textarea').placeholder = `Enter content for page ${index + 1}...`;
            });
        }

        function generateCover() {
            alert('Cover generation coming soon!');
        }

        document.getElementById('cover-upload').addEventListener('click', function() {
            document.getElementById('cover_image').click();
        });

        document.getElementById('cover_image').addEventListener('change', function(e) {
            if (this.files.length > 0) {
                const fileName = this.files[0].name;
                document.getElementById('cover-upload').innerHTML = '<p style="color: #10b981; font-weight: 600;">✓ ' + fileName + ' uploaded</p>';
            }
        });

        // Initialize the first editor
        document.addEventListener('DOMContentLoaded', function() {
            initQuillEditor(0);
        });

        // Sync all editors before form submission
        document.getElementById('story-form').addEventListener('submit', function(e) {
            Object.keys(editors).forEach(key => {
                const textarea = editors[key].container.nextElementSibling;
                textarea.value = editors[key].root.innerHTML;
            });
        });

        document.getElementById('story_file').addEventListener('change', function(e) {
            if (this.files.length > 0) {
                const fileName = this.files[0].name;
                const fileSize = (this.files[0].size / (1024 * 1024)).toFixed(2);
                const fileUploadArea = document.querySelector('.file-upload-area');
                fileUploadArea.innerHTML = '<p style="color: #10b981; font-weight: 600;">✓ ' + fileName + ' selected (' + fileSize + 'MB)</p>';
            }
        });

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
