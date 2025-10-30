<?php
// Lab result upload modal - using working pattern from simple test

// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once '../../config/session/employee_session.php';
require_once '../../config/production_security.php';
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
        $result_text = sanitize_input($_POST['result_text'] ?? '');
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
            $additional_remarks = sanitize_input($_POST['remarks'] ?? '');
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
    /* Import modern fonts */
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap');
    
    * {
        box-sizing: border-box;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
    }

    /* Reset for modal context */
    .upload-form {
        background: transparent;
        padding: 0;
        max-width: 100%;
        width: 100%;
        margin: 0;
        border-radius: 0;
        box-shadow: none;
        border: none;
        overflow: visible;
        animation: none;
        height: auto;
        min-height: auto;
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        letter-spacing: -0.01em;
        line-height: 1.6;
    }

    .form-header {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 0 0 20px 0;
        background: transparent;
        border-radius: 0;
        margin: 0;
        border-bottom: 2px solid #e2e8f0;
    }

    .form-header-icon {
        background: linear-gradient(135deg, #059669 0%, #10b981 100%);
        color: white;
        padding: 12px;
        border-radius: 10px;
        font-size: 1.4em;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 15px rgba(5, 150, 105, 0.3);
        min-width: 48px;
        height: 48px;
    }

    .form-header-content h4 {
        margin: 0 0 8px 0;
        font-size: 1.5em;
        font-weight: 700;
        color: #1f2937;
        background: linear-gradient(135deg, #059669 0%, #10b981 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        letter-spacing: -0.025em;
        font-family: 'Inter', sans-serif;
        line-height: 1.2;
    }

    .patient-info {
        font-size: 0.9em;
        color: #6b7280;
        line-height: 1.5;
        margin: 0;
        font-weight: 400;
        letter-spacing: -0.01em;
    }

    .patient-info strong {
        color: #374151;
        font-weight: 600;
    }

    .form-content {
        padding: 20px 0 0 0;
        background: transparent;
    }

    #alertContainer {
        margin-bottom: 15px;
    }

    .alert {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.9em;
        border: 1px solid;
        position: relative;
        animation: slideInDown 0.3s ease;
        font-family: 'Inter', sans-serif;
        font-weight: 500;
        letter-spacing: -0.01em;
        line-height: 1.5;
    }

    .alert-info {
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        border-color: #60a5fa;
        color: #1e40af;
    }

    .alert-success {
        background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
        border-color: #22c55e;
        color: #15803d;
    }

    .alert-danger {
        background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
        border-color: #ef4444;
        color: #dc2626;
    }

    .btn-close {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        font-size: 1.2em;
        cursor: pointer;
        color: inherit;
        opacity: 0.7;
        padding: 4px;
        line-height: 1;
    }

    .btn-close:hover {
        opacity: 1;
    }

    .form-tip {
        background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        border: 1px solid #f59e0b;
        border-radius: 8px;
        padding: 12px 16px;
        margin-bottom: 20px;
        color: #92400e;
        font-size: 0.9em;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        font-family: 'Inter', sans-serif;
        font-weight: 500;
        letter-spacing: -0.01em;
        line-height: 1.5;
    }

    .form-tip i {
        color: #f59e0b;
        margin-top: 2px;
        font-size: 1.1em;
    }

    .form-group {
        margin-bottom: 20px;
    }

    .form-label {
        display: block;
        font-weight: 600;
        margin-bottom: 8px;
        color: #374151;
        font-size: 0.95em;
        letter-spacing: -0.01em;
        font-family: 'Inter', sans-serif;
        line-height: 1.4;
    }

    .required {
        color: #ef4444;
        font-weight: 700;
        font-size: 1.1em;
    }

    .form-control {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid #d1d5db;
        border-radius: 8px;
        font-size: 0.95em;
        line-height: 1.6;
        background: white;
        transition: all 0.3s ease;
        resize: vertical;
        min-height: 45px;
        font-family: 'Inter', sans-serif;
        font-weight: 400;
        letter-spacing: -0.01em;
        color: #1f2937;
    }

    .form-control:focus {
        outline: none;
        border-color: #10b981;
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        background: #f8fafc;
    }

    .form-control::placeholder {
        color: #9ca3af;
        font-style: italic;
    }

    .character-count {
        font-size: 0.8em;
        color: #74b9ff;
        text-align: right;
        margin-top: 5px;
        font-weight: 500;
        font-family: 'JetBrains Mono', 'SF Mono', 'Monaco', 'Inconsolata', 'Fira Code', monospace;
        letter-spacing: 0.01em;
    }

    .file-upload-area {
        border: 2px dashed #d1d5db;
        border-radius: 12px;
        padding: 30px 20px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        position: relative;
        overflow: hidden;
    }

    .file-upload-area:hover {
        border-color: #10b981;
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(16, 185, 129, 0.15);
    }

    .file-upload-area.drag-over {
        border-color: #10b981;
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        transform: scale(1.02);
        box-shadow: 0 8px 25px rgba(16, 185, 129, 0.2);
    }

    .upload-icon {
        font-size: 2.5em;
        color: #10b981;
        margin-bottom: 12px;
        animation: float 3s ease-in-out infinite;
    }

    @keyframes float {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-8px); }
    }

    .upload-text {
        font-size: 1.1em;
        font-weight: 600;
        color: #374151;
        margin-bottom: 8px;
        font-family: 'Inter', sans-serif;
        letter-spacing: -0.02em;
        line-height: 1.3;
    }

    .file-info {
        font-size: 0.9em;
        color: #6b7280;
        line-height: 1.5;
        font-family: 'Inter', sans-serif;
        letter-spacing: -0.01em;
    }

    .file-info strong {
        color: #374151;
        font-weight: 600;
    }

    .selected-file {
        margin-top: 15px;
        padding: 0;
        background: transparent;
        border: none;
        border-radius: 0;
    }

    .file-preview {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 16px;
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        border: 1px solid #22c55e;
        border-radius: 8px;
        font-size: 0.9em;
    }

    .file-icon {
        color: #22c55e;
        font-size: 1.5em;
        min-width: 24px;
    }

    .file-details {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .file-name {
        font-weight: 600;
        color: #374151;
        word-break: break-all;
        font-family: 'Inter', sans-serif;
        letter-spacing: -0.01em;
        line-height: 1.4;
    }

    .file-size {
        color: #6b7280;
        font-size: 0.85em;
        font-family: 'JetBrains Mono', 'SF Mono', 'Monaco', 'Inconsolata', 'Fira Code', monospace;
        font-weight: 500;
        letter-spacing: 0.01em;
    }

    .remove-file-btn {
        background: #ef4444;
        color: white;
        border: none;
        padding: 6px 8px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.9em;
        transition: all 0.3s ease;
        min-width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .remove-file-btn:hover {
        background: #dc2626;
        transform: scale(1.1);
    }

    .form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 25px;
        padding-top: 20px;
        border-top: 1px solid #e5e7eb;
    }

    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-size: 0.95em;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        min-height: 45px;
        font-family: 'Inter', sans-serif;
        letter-spacing: -0.01em;
        line-height: 1.4;
    }

    .btn-secondary {
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        color: #64748b;
        border: 1px solid #cbd5e1;
    }

    .btn-secondary:hover {
        background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
        color: #475569;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .btn-primary {
        background: linear-gradient(135deg, #059669 0%, #10b981 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(5, 150, 105, 0.3);
    }

    .btn-primary:hover {
        background: linear-gradient(135deg, #047857 0%, #059669 100%);
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(5, 150, 105, 0.4);
    }

    .btn-primary:disabled {
        background: #9ca3af;
        box-shadow: none;
        transform: none;
        cursor: not-allowed;
    }

    /* Animations */
    @keyframes slideInDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes fadeOut {
        from {
            opacity: 1;
            transform: scale(1);
        }
        to {
            opacity: 0;
            transform: scale(0.95);
        }
    }

    /* Responsive Design */
    @media (max-width: 768px) {
        .form-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
            padding-bottom: 15px;
        }

        .form-header-icon {
            font-size: 1.2em;
            padding: 10px;
            min-width: 40px;
            height: 40px;
        }

        .form-header-content h4 {
            font-size: 1.2em;
        }

        .patient-info {
            font-size: 0.85em;
        }

        .form-actions {
            flex-direction: column;
        }

        .btn {
            width: 100%;
            justify-content: center;
        }

        .file-upload-area {
            padding: 20px 15px;
        }

        .upload-icon {
            font-size: 2em;
        }

        .file-preview {
            flex-direction: column;
            align-items: flex-start;
            gap: 8px;
        }
    }

    /* Dark mode support (if parent modal supports it) */
    @media (prefers-color-scheme: dark) {
        .upload-form {
            background: #1f2937;
            border-color: #374151;
            color: #f9fafb;
        }

        .form-control {
            background: #374151;
            border-color: #4b5563;
            color: #f9fafb;
        }

        .form-control:focus {
            background: #1f2937;
            border-color: #10b981;
        }

        .file-upload-area {
            background: linear-gradient(135deg, #374151 0%, #4b5563 100%);
            border-color: #6b7280;
        }

        .file-upload-area:hover {
            background: linear-gradient(135deg, #1f2937 0%, #374151 100%);
        }
    }
</style>

<div class="upload-form">
    <div class="form-header">
        <div class="form-header-icon">
            <i class="fas fa-upload"></i>
        </div>
        <div class="form-header-content">
            <h4>Upload Lab Result</h4>
            <div class="patient-info">
                <strong>Patient:</strong> <?= htmlspecialchars($patientName) ?> (ID: <?= htmlspecialchars($item['patient_id_display']) ?>)<br>
                <strong>Test:</strong> <?= htmlspecialchars($item['test_type']) ?> • <strong>Order:</strong> #<?= $item['lab_order_id'] ?>
            </div>
        </div>
    </div>

    <div class="form-content">
        <div id="alertContainer"></div>
        
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i>
            <span>Accepted file types: PDF, CSV, XLSX. Maximum file size: 10MB. Result text is required for all submissions.</span>
        </div>

        <div class="form-tip">
            <i class="fas fa-lightbulb"></i>
            <strong>Tip:</strong> Ensure your result file is clear, properly labeled, and includes all relevant test information for accurate record keeping.
        </div>

        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="form-group">
                <label class="form-label">Test Results <span class="required">*</span></label>
                <textarea 
                    name="result_text" 
                    class="form-control" 
                    rows="4" 
                    placeholder="Enter detailed test results, findings, measurements, or observations. Include all relevant numerical values, reference ranges, and clinical interpretations..."
                    required
                    maxlength="2000"
                    oninput="updateCharacterCount('result_text', 'resultCharCount', 2000)"
                ></textarea>
                <div id="resultCharCount" class="character-count">0/2000 characters</div>
            </div>

            <div class="form-group">
                <label class="form-label">Upload Result File <small>(Optional)</small></label>
                <div class="file-upload-area" onclick="triggerFileSelect()" ondrop="handleDrop(event)" ondragover="handleDragOver(event)" ondragleave="handleDragLeave(event)">
                    <div class="upload-icon">
                        <i class="fas fa-cloud-upload-alt"></i>
                    </div>
                    <div class="upload-text">
                        Click to upload or drag and drop file here
                    </div>
                    <div class="file-info">
                        <strong>Supported formats:</strong> PDF (reports), CSV (data), XLSX (spreadsheets)<br>
                        <strong>Maximum size:</strong> 10MB per file
                    </div>
                </div>
                <input 
                    type="file" 
                    id="result_file" 
                    name="result_file" 
                    accept=".pdf,.csv,.xlsx,application/pdf,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" 
                    style="display: none;" 
                    onchange="handleFileSelect(event)"
                >
                <div id="selectedFile" class="selected-file" style="display: none;"></div>
            </div>

            <div class="form-group">
                <label class="form-label">Additional Remarks <small>(Optional)</small></label>
                <textarea 
                    name="remarks" 
                    class="form-control" 
                    rows="3" 
                    placeholder="Add any additional observations, notes about the testing process, recommendations, or follow-up instructions..."
                    maxlength="500"
                    oninput="updateCharacterCount('remarks', 'remarksCharCount', 500)"
                ></textarea>
                <div id="remarksCharCount" class="character-count">0/500 characters</div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('uploadResultModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary" onclick="handleFormSubmit(event)">
                    <i class="fas fa-upload"></i> Upload Result
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Character count functionality
function updateCharacterCount(textareaId, counterId, maxLength) {
    const textarea = document.querySelector(`[name="${textareaId}"]`);
    const counter = document.getElementById(counterId);
    
    if (!textarea || !counter) return;
    
    const currentLength = textarea.value.length;
    counter.textContent = `${currentLength}/${maxLength} characters`;
    
    if (currentLength > maxLength * 0.9) {
        counter.style.color = '#ff4757';
    } else if (currentLength > maxLength * 0.7) {
        counter.style.color = '#ff9ff3';
    } else {
        counter.style.color = '#74b9ff';
    }
}

// File upload functionality
function triggerFileSelect() {
    document.getElementById('result_file').click();
}

function handleFileSelect(event) {
    const file = event.target.files[0];
    if (file && validateFile(file)) {
        displaySelectedFile(file);
    }
}

function handleDrop(event) {
    event.preventDefault();
    event.stopPropagation();
    
    const uploadArea = event.currentTarget;
    uploadArea.classList.remove('drag-over');
    
    const files = event.dataTransfer.files;
    if (files.length > 0) {
        const file = files[0];
        if (validateFile(file)) {
            // Set the file to the input element
            const fileInput = document.getElementById('result_file');
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            fileInput.files = dataTransfer.files;
            
            displaySelectedFile(file);
        }
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

function validateFile(file) {
    const allowedTypes = ['application/pdf', 'text/csv', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    const allowedExtensions = ['.pdf', '.csv', '.xlsx'];
    const maxSize = 10 * 1024 * 1024; // 10MB
    
    const isValidType = allowedTypes.includes(file.type) || allowedExtensions.some(ext => file.name.toLowerCase().endsWith(ext));
    
    if (!isValidType) {
        showAlert('Please select a PDF, CSV, or XLSX file.', 'error');
        return false;
    }
    
    if (file.size > maxSize) {
        showAlert('File size must be less than 10MB.', 'error');
        return false;
    }
    
    return true;
}

function displaySelectedFile(file) {
    const selectedFileDiv = document.getElementById('selectedFile');
    const fileSize = (file.size / 1024 / 1024).toFixed(2);
    
    selectedFileDiv.innerHTML = `
        <div class="file-preview">
            <div class="file-icon">
                <i class="fas fa-file-${getFileIcon(file.type, file.name)}"></i>
            </div>
            <div class="file-details">
                <span class="file-name">${file.name}</span>
                <span class="file-size">${fileSize} MB</span>
            </div>
            <button type="button" class="remove-file-btn" onclick="removeSelectedFile()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    selectedFileDiv.style.display = 'block';
}

function getFileIcon(fileType, fileName) {
    if (fileType === 'application/pdf' || fileName.toLowerCase().endsWith('.pdf')) return 'pdf';
    if (fileType === 'text/csv' || fileName.toLowerCase().endsWith('.csv')) return 'csv';
    if (fileType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' || fileName.toLowerCase().endsWith('.xlsx')) return 'excel';
    return 'alt';
}

function removeSelectedFile() {
    document.getElementById('result_file').value = '';
    document.getElementById('selectedFile').style.display = 'none';
}

function showAlert(message, type = 'info') {
    const alertContainer = document.getElementById('alertContainer');
    const alertClass = type === 'error' ? 'alert-danger' : type === 'success' ? 'alert-success' : 'alert-info';
    const iconClass = type === 'error' ? 'fa-exclamation-triangle' : type === 'success' ? 'fa-check-circle' : 'fa-info-circle';
    
    alertContainer.innerHTML = `
        <div class="alert ${alertClass}">
            <i class="fas ${iconClass}"></i>
            <span>${message}</span>
            <button type="button" class="btn-close" onclick="this.parentElement.remove();">&times;</button>
        </div>
    `;
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        const alert = alertContainer.querySelector('.alert');
        if (alert) {
            alert.style.animation = 'fadeOut 0.5s ease';
            setTimeout(() => alert.remove(), 500);
        }
    }, 5000);
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
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
    
    // Initialize character counters
    const resultTextarea = document.querySelector('textarea[name="result_text"]');
    const remarksTextarea = document.querySelector('textarea[name="remarks"]');
    
    if (resultTextarea) {
        updateCharacterCount('result_text', 'resultCharCount', 2000);
    }
    if (remarksTextarea) {
        updateCharacterCount('remarks', 'remarksCharCount', 500);
    }
    
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