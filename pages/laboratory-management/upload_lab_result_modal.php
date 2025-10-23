<?php
// Lab result upload modal - using working pattern from simple test

// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once '../../config/session/employee_session.php';
require_once '../../config/db.php';

// Check authorization
$authorizedRoleIds = [1, 2, 3, 9]; // admin, doctor, nurse, laboratory_tech
if (!isset($_SESSION['employee_id']) || !in_array($_SESSION['role_id'], $authorizedRoleIds)) {
    http_response_code(403);
    exit('Not authorized');
}

// Validate and sanitize item_id
$item_id = filter_input(INPUT_GET, 'item_id', FILTER_VALIDATE_INT);
if (!$item_id || $item_id <= 0) {
    http_response_code(400);
    exit('Valid Item ID required');
}

// Handle POST request (process upload) - Using working pattern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        error_log("Upload modal POST request received for item_id: $item_id");
        
        // Validate required field with length limits
        $result_text = filter_var(trim($_POST['result_text'] ?? ''), FILTER_SANITIZE_STRING);
        if (empty($result_text)) {
            error_log("Upload failed: result_text is empty");
            echo json_encode(['success' => false, 'message' => 'Result text is required']);
            exit;
        }
        
        if (strlen($result_text) > 2000) {
            echo json_encode(['success' => false, 'message' => 'Lab result text cannot exceed 2000 characters']);
            exit;
        }
        
        // Combine results and remarks like in working version
        $remarks = "Results: " . $result_text;
        if (!empty($_POST['remarks'])) {
            $additional_remarks = filter_var(trim($_POST['remarks']), FILTER_SANITIZE_STRING);
            if (strlen($additional_remarks) > 500) {
                echo json_encode(['success' => false, 'message' => 'Additional remarks cannot exceed 500 characters']);
                exit;
            }
            $remarks .= "\n\nRemarks: " . $additional_remarks;
        }
        
        error_log("Combined remarks: $remarks");
        
        // Handle file upload - same pattern as working version
        $result_file = null;
        if (isset($_FILES['result_file']) && $_FILES['result_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['result_file'];
            error_log("File upload detected: " . $file['name'] . " (" . $file['size'] . " bytes)");
            
            // Validate file type
            $allowedExtensions = ['pdf', 'csv', 'xlsx'];
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                error_log("Upload failed: invalid file extension: $fileExtension");
                echo json_encode(['success' => false, 'message' => 'Only PDF, CSV, and XLSX files are allowed.']);
                exit;
            }
            
            if ($file['size'] > 10 * 1024 * 1024) {
                error_log("Upload failed: file too large: " . $file['size']);
                echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 10MB.']);
                exit;
            }
            
            // Read file data
            $result_file = file_get_contents($file['tmp_name']);
            if ($result_file === false) {
                error_log("Upload failed: could not read file data");
                echo json_encode(['success' => false, 'message' => 'Failed to read uploaded file.']);
                exit;
            }
            error_log("File data read successfully: " . strlen($result_file) . " bytes");
        }
        
        // Update database - exact same pattern as working version
        if ($result_file) {
            error_log("Updating item with file data");
            $sql = "UPDATE lab_order_items SET result_file = ?, remarks = ?, status = 'completed', result_date = NOW(), updated_at = NOW() WHERE item_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ssi', $result_file, $remarks, $item_id);
        } else {
            error_log("Updating item without file data");
            $sql = "UPDATE lab_order_items SET remarks = ?, status = 'completed', result_date = NOW(), updated_at = NOW() WHERE item_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('si', $remarks, $item_id);
        }
        
        error_log("Executing SQL: $sql with item_id: $item_id");
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            error_log("Database update successful. Affected rows: " . $stmt->affected_rows);
            
            // Update lab order status
            require_once '../../utils/LabOrderStatusManager.php';
            $statusUpdated = updateLabOrderStatusFromItem($item_id, $conn);
            
            if ($statusUpdated) {
                error_log("Lab order status updated successfully for item ID: $item_id");
            } else {
                error_log("Warning: Could not update lab order status for item ID: $item_id");
            }
            
            echo json_encode(['success' => true, 'message' => 'Lab result uploaded successfully!']);
        } else {
            error_log("Database update failed. Error: " . $stmt->error);
            echo json_encode(['success' => false, 'message' => 'Database update failed']);
        }
        
    } catch (Exception $e) {
        error_log("Upload exception: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Fetch lab order item details for GET request
try {
    $itemSql = "SELECT loi.item_id, loi.lab_order_id, loi.test_type, loi.status,
                       loi.remarks as special_instructions,
                       lo.patient_id, p.first_name, p.last_name, p.middle_name, p.username as patient_id_display
                FROM lab_order_items loi
                LEFT JOIN lab_orders lo ON loi.lab_order_id = lo.lab_order_id
                LEFT JOIN patients p ON lo.patient_id = p.patient_id
                WHERE loi.item_id = ?";

    $itemStmt = $conn->prepare($itemSql);
    $itemStmt->bind_param("i", $item_id);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();
    $item = $itemResult->fetch_assoc();

    if (!$item) {
        http_response_code(404);
        exit('Lab order item not found.');
    }
    
    $patientName = trim($item['first_name'] . ' ' . $item['middle_name'] . ' ' . $item['last_name']);
} catch (Exception $e) {
    http_response_code(500);
    exit('Database error: ' . $e->getMessage());
}
?>

<style>
    .upload-form {
        padding: 20px;
        max-width: 600px;
        margin: 0 auto;
    }

    .form-header {
        background-color: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }

    .form-header h4 {
        margin: 0 0 10px 0;
        color: #03045e;
    }

    .patient-info {
        font-size: 0.9em;
        color: #666;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-label {
        display: block;
        font-weight: bold;
        color: #03045e;
        margin-bottom: 5px;
    }

    .form-control {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 0.9em;
        box-sizing: border-box;
    }

    .form-control:focus {
        outline: none;
        border-color: #03045e;
        box-shadow: 0 0 5px rgba(3, 4, 94, 0.2);
    }

    .file-upload-area {
        border: 2px dashed #ddd;
        border-radius: 5px;
        padding: 30px;
        text-align: center;
        background-color: #f9f9f9;
        transition: border-color 0.3s;
        cursor: pointer;
    }

    .file-upload-area:hover {
        border-color: #03045e;
    }

    .file-upload-area.dragover {
        border-color: #03045e;
        background-color: rgba(3, 4, 94, 0.1);
    }

    .upload-icon {
        font-size: 2em;
        color: #03045e;
        margin-bottom: 10px;
    }

    .upload-text {
        color: #666;
        margin-bottom: 10px;
    }

    .file-info {
        font-size: 0.8em;
        color: #999;
    }

    .selected-file {
        margin-top: 10px;
        padding: 10px;
        background-color: #e8f5e8;
        border-radius: 5px;
        border: 1px solid #28a745;
    }

    .form-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-top: 20px;
        padding-top: 20px;
        border-top: 1px solid #ddd;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-size: 0.9em;
        transition: all 0.3s;
    }

    .btn-primary {
        background-color: #03045e;
        color: white;
    }

    .btn-primary:hover {
        background-color: #0218A7;
    }

    .btn-secondary {
        background-color: #6c757d;
        color: white;
    }

    .btn-secondary:hover {
        background-color: #545b62;
    }

    .alert {
        padding: 12px 16px;
        margin-bottom: 20px;
        border-radius: 5px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .alert-info {
        background-color: #d1ecf1;
        color: #0c5460;
        border: 1px solid #bee5eb;
    }

    .alert-success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .alert-error {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .file-upload-area.drag-over {
        background-color: #e8f5e8;
        border-color: #28a745;
    }
    
    .selected-file {
        margin-top: 10px;
        padding: 10px;
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 4px;
    }
    
    .selected-file i {
        color: #28a745;
        margin-right: 5px;
    }
</style>

<div class="upload-form">
    <div class="form-header">
        <h4>Upload Lab Result</h4>
        <div class="patient-info">
            <strong>Patient:</strong> <?= htmlspecialchars($patientName) ?> (ID: <?= htmlspecialchars($item['patient_id_display']) ?>)<br>
            <strong>Test:</strong> <?= htmlspecialchars($item['test_type']) ?>
        </div>
    </div>

    <div id="alertContainer"></div>
    
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i>
        <span>Accepted file types: PDF, CSV, XLSX. Maximum file size: 10MB.</span>
    </div>

    <form method="POST" enctype="multipart/form-data" id="uploadForm">
        <div class="form-group">
            <label class="form-label">Result Text *</label>
            <textarea name="result_text" class="form-control" rows="4" placeholder="Enter test results, findings, or observations..." required></textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Upload Result File</label>
            <div class="file-upload-area" onclick="triggerFileSelect()" ondrop="handleDrop(event)" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)">
                <div class="upload-icon">
                    <i class="fas fa-cloud-upload-alt"></i>
                </div>
                <div class="upload-text">
                    Click to upload or drag and drop file here
                </div>
                <div class="file-info">
                    PDF, CSV, XLSX files only, maximum 10MB
                </div>
            </div>
            <input type="file" id="result_file" name="result_file" accept=".pdf,.csv,.xlsx,application/pdf,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" style="display: none;" onchange="handleFileSelect(event)">
            <div id="selectedFile" class="selected-file" style="display: none;"></div>
        </div>

        <div class="form-group">
            <label class="form-label">Additional Remarks (Optional)</label>
            <textarea name="remarks" class="form-control" rows="3" placeholder="Any additional notes or observations..."></textarea>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-secondary" onclick="closeModal('uploadResultModal')">Cancel</button>
            <button type="submit" class="btn btn-primary" onclick="handleFormSubmit(event)">
                <i class="fas fa-upload"></i> Upload Result
            </button>
        </div>
    </form>
</div>

<script>
    function triggerFileSelect() {
        document.getElementById('result_file').click();
    }

    function handleFileSelect(event) {
        const file = event.target.files[0];
        if (file) {
            displaySelectedFile(file);
        }
    }
    
    function handleDragOver(event) {
        event.preventDefault();
        event.stopPropagation();
        event.currentTarget.classList.add('drag-over');
    }
    
    function handleDragLeave(event) {
        event.preventDefault();
        event.stopPropagation();
        event.currentTarget.classList.remove('drag-over');
    }
    
    function handleDrop(event) {
        event.preventDefault();
        event.stopPropagation();
        event.currentTarget.classList.remove('drag-over');
        
        const files = event.dataTransfer.files;
        if (files.length > 0) {
            const file = files[0];
            
            // Validate file type
            const allowedTypes = ['application/pdf', 'text/csv', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
            const allowedExtensions = ['.pdf', '.csv', '.xlsx'];
            
            const isValidType = allowedTypes.includes(file.type) || allowedExtensions.some(ext => file.name.toLowerCase().endsWith(ext));
            
            if (!isValidType) {
                showAlert('Invalid file type. Only PDF, CSV, and XLSX files are allowed.', 'error');
                return;
            }
            
            // Validate file size (10MB = 10 * 1024 * 1024 bytes)
            if (file.size > 10 * 1024 * 1024) {
                showAlert('File size exceeds 10MB limit.', 'error');
                return;
            }
            
            // Set the file to the input element
            const fileInput = document.getElementById('result_file');
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            fileInput.files = dataTransfer.files;
            
            displaySelectedFile(file);
        }
    }
    
    function displaySelectedFile(file) {
        const selectedFileDiv = document.getElementById('selectedFile');
        const fileSize = (file.size / (1024 * 1024)).toFixed(2);
        
        selectedFileDiv.innerHTML = `
            <div>
                <i class="fas fa-file"></i>
                <strong>Selected:</strong> ${file.name} (${fileSize} MB)
            </div>
        `;
        selectedFileDiv.style.display = 'block';
    }

    function showAlert(message, type = 'success') {
        const alertContainer = document.getElementById('alertContainer');
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.innerHTML = `<span>${message}</span>`;
        alertContainer.appendChild(alertDiv);
        
        setTimeout(() => alertDiv.remove(), 5000);
    }
    
    // Backup form submission handler
    function handleFormSubmit(event) {
        event.preventDefault();
        console.log('=== BACKUP FORM SUBMISSION TRIGGERED ===');
        
        const form = document.getElementById('uploadForm');
        if (form) {
            // Trigger the form submit event manually
            const submitEvent = new Event('submit', { bubbles: true, cancelable: true });
            form.dispatchEvent(submitEvent);
        }
    }

    // Form submission - using working pattern
    document.addEventListener('DOMContentLoaded', function() {
        console.log('DOM Content Loaded - Setting up form submission');
        
        try {
            const form = document.getElementById('uploadForm');
            if (!form) {
                console.error('Upload form not found!');
                return;
            }
            
            console.log('Upload form found, attaching event listener');
            
            form.addEventListener('submit', function(event) {
                try {
                    event.preventDefault();
                    
                    console.log('=== UPLOAD FORM SUBMISSION STARTED ===');
                    console.log('Form submission started');
                    
                    const resultText = this.querySelector('textarea[name="result_text"]').value.trim();
                    console.log('Result text:', resultText);
                    
                    if (!resultText) {
                        console.log('Result text validation failed');
                        showAlert('Result text is required.', 'error');
                        return;
                    }
        
        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        
        // Debug form data
        for (let pair of formData.entries()) {
            console.log(pair[0] + ': ' + (pair[1] instanceof File ? pair[1].name : pair[1]));
        }
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response received:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            
            if (data.success) {
                showAlert(data.message, 'success');
                setTimeout(() => {
                    // Check if we're in an iframe
                    if (window !== window.top) {
                        // We're in an iframe, communicate with parent
                        window.top.closeModal('uploadResultModal');
                        window.top.location.reload();
                    } else if (window.parent && window.parent.closeModal) {
                        // Fallback for other modal contexts
                        window.parent.closeModal('uploadResultModal');
                        window.parent.location.reload();
                    } else {
                        // Direct access, just reload
                        window.location.href = 'lab_management.php';
                    }
                }, 1500);
            } else {
                showAlert('Error: ' + data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Upload error:', error);
            showAlert('Upload failed: ' + error.message, 'error');
        })
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        });
        
                } catch (submitError) {
                    console.error('Form submission error:', submitError);
                    showAlert('Form submission failed: ' + submitError.message, 'error');
                }
            }); // Close form event listener
            
        } catch (setupError) {
            console.error('Form setup error:', setupError);
        }
    }); // Close DOMContentLoaded
</script>