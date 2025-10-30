// assets/js/medical_print.js
// Handles checkbox selection, preview fetch, PDF generation, print behavior.
// Implements fetch calls to api/medical_print_preview.php and api/medical_print_generate.php

const MedicalPrint = {
    // Configuration
    config: {
        patientId: null,
        rootPath: '',
        csrfToken: null,
        userPermissions: {
            canPrint: false,
            canExport: false,
            canViewAudit: false
        },
        apiEndpoints: {
            preview: '/api/medical_print_preview.php',
            generate: '/api/medical_print_generate.php'
        },
        debounceDelay: 500,
        maxPreviewSections: 10
    },

    // State management
    state: {
        selectedSections: [],
        dateFilters: {
            dateFrom: null,
            dateTo: null
        },
        isLoading: false,
        lastPreviewData: null
    },

    // DOM elements cache
    elements: {},

    // Initialize the medical print system
    init() {
        this.cacheElements();
        this.bindEvents();
        this.setupGroupCheckboxes();
        this.setupDateInputs();
        
        // Get patient data and security configuration from global variable
        if (window.medicalPrint) {
            this.config.patientId = window.medicalPrint.patientId;
            this.config.rootPath = window.medicalPrint.rootPath;
            this.config.csrfToken = window.medicalPrint.csrfToken;
            this.config.userPermissions = window.medicalPrint.userPermissions || {};
        }

        // Update UI based on permissions
        this.updateUIForPermissions();

        console.log('Medical Print System initialized with security');
    },

    // Cache DOM elements for better performance
    cacheElements() {
        this.elements = {
            // Section checkboxes
            sectionCheckboxes: document.querySelectorAll('.section-checkbox'),
            groupCheckboxes: document.querySelectorAll('.group-checkbox'),
            
            // Control buttons
            selectAllBtn: document.getElementById('selectAllBtn'),
            clearAllBtn: document.getElementById('clearAllBtn'),
            previewBtn: document.getElementById('previewBtn'),
            generatePdfBtn: document.getElementById('generatePdfBtn'),
            printBtn: document.getElementById('printBtn'),
            refreshPreviewBtn: document.getElementById('refreshPreviewBtn'),
            
            // Date inputs
            dateFrom: document.getElementById('dateFrom'),
            dateTo: document.getElementById('dateTo'),
            
            // Preview
            previewContent: document.getElementById('previewContent'),
            
            // UI feedback
            loadingOverlay: document.getElementById('loadingOverlay'),
            loadingText: document.getElementById('loadingText'),
            alertContainer: document.getElementById('alertContainer')
        };
    },

    // Bind event listeners
    bindEvents() {
        // Section checkbox events
        this.elements.sectionCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', () => this.handleSectionChange());
        });

        // Group checkbox events
        this.elements.groupCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', (e) => this.handleGroupToggle(e));
        });

        // Control button events
        this.elements.selectAllBtn.addEventListener('click', () => this.selectAll());
        this.elements.clearAllBtn.addEventListener('click', () => this.clearAll());
        this.elements.previewBtn.addEventListener('click', () => this.generatePreview());
        this.elements.generatePdfBtn.addEventListener('click', () => this.generatePDF());
        this.elements.printBtn.addEventListener('click', () => this.printRecord());
        this.elements.refreshPreviewBtn.addEventListener('click', () => this.refreshPreview());

        // Date input events
        this.elements.dateFrom.addEventListener('change', () => this.handleDateChange());
        this.elements.dateTo.addEventListener('change', () => this.handleDateChange());

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => this.handleKeyboardShortcuts(e));
    },

    // Setup group checkbox behavior
    setupGroupCheckboxes() {
        const groups = {
            'patient-info': ['basic', 'personal_information', 'emergency_contacts', 'lifestyle_information'],
            'medical-history': ['past_medical_conditions', 'chronic_illnesses', 'family_history', 'surgical_history', 'immunizations'],
            'current-health': ['allergies', 'current_medications'],
            'healthcare-records': ['consultations', 'appointments', 'referrals', 'prescriptions', 'lab_orders', 'billing']
        };

        this.groupSections = groups;
    },

    // Setup date input defaults
    setupDateInputs() {
        // Set default date range (last 1 year)
        const today = new Date();
        const oneYearAgo = new Date(today.getFullYear() - 1, today.getMonth(), today.getDate());
        
        this.elements.dateFrom.value = this.formatDate(oneYearAgo);
        this.elements.dateTo.value = this.formatDate(today);
        
        this.updateDateFilters();
    },

    // Handle individual section checkbox changes
    handleSectionChange() {
        this.updateSelectedSections();
        this.updateGroupCheckboxes();
        this.updateButtonStates();
        
        // Auto-preview if sections are selected
        if (this.state.selectedSections.length > 0) {
            this.debouncedPreview();
        }
    },

    // Handle group checkbox toggle
    handleGroupToggle(event) {
        const groupName = event.target.dataset.group;
        const isChecked = event.target.checked;
        const sections = this.groupSections[groupName] || [];

        sections.forEach(sectionValue => {
            const checkbox = document.querySelector(`input[value="${sectionValue}"]`);
            if (checkbox) {
                checkbox.checked = isChecked;
            }
        });

        this.handleSectionChange();
    },

    // Update selected sections array
    updateSelectedSections() {
        this.state.selectedSections = Array.from(this.elements.sectionCheckboxes)
            .filter(cb => cb.checked)
            .map(cb => cb.value);
    },

    // Update group checkbox states based on individual selections
    updateGroupCheckboxes() {
        Object.entries(this.groupSections).forEach(([groupName, sections]) => {
            const groupCheckbox = document.querySelector(`[data-group="${groupName}"]`);
            if (!groupCheckbox) return;

            const checkedCount = sections.filter(section => {
                const checkbox = document.querySelector(`input[value="${section}"]`);
                return checkbox && checkbox.checked;
            }).length;

            if (checkedCount === 0) {
                groupCheckbox.checked = false;
                groupCheckbox.indeterminate = false;
            } else if (checkedCount === sections.length) {
                groupCheckbox.checked = true;
                groupCheckbox.indeterminate = false;
            } else {
                groupCheckbox.checked = false;
                groupCheckbox.indeterminate = true;
            }
        });
    },

    // Update button states based on selections
    updateButtonStates() {
        const hasSelections = this.state.selectedSections.length > 0;
        
        this.elements.previewBtn.disabled = !hasSelections;
        this.elements.generatePdfBtn.disabled = !hasSelections;
        this.elements.printBtn.disabled = !hasSelections;
    },

    // Handle date filter changes
    handleDateChange() {
        this.updateDateFilters();
        
        // Refresh preview if we have selections
        if (this.state.selectedSections.length > 0) {
            this.debouncedPreview();
        }
    },

    // Update date filters state
    updateDateFilters() {
        this.state.dateFilters = {
            dateFrom: this.elements.dateFrom.value || null,
            dateTo: this.elements.dateTo.value || null
        };
    },

    // Select all sections
    selectAll() {
        this.elements.sectionCheckboxes.forEach(cb => cb.checked = true);
        this.handleSectionChange();
        this.showAlert('All sections selected', 'success');
    },

    // Clear all selections
    clearAll() {
        this.elements.sectionCheckboxes.forEach(cb => cb.checked = false);
        this.elements.groupCheckboxes.forEach(cb => {
            cb.checked = false;
            cb.indeterminate = false;
        });
        this.handleSectionChange();
        this.clearPreview();
        this.showAlert('All selections cleared', 'info');
    },

    // Generate preview
    async generatePreview() {
        if (this.state.selectedSections.length === 0) {
            this.showAlert('Please select at least one section to preview', 'warning');
            return;
        }

        try {
            this.showLoading('Generating preview...');
            
            const response = await this.fetchPreview();
            
            if (response.success) {
                this.displayPreview(response.data);
                this.state.lastPreviewData = response.data;
                this.showAlert('Preview generated successfully', 'success');
            } else {
                throw new Error(response.message || 'Failed to generate preview');
            }
        } catch (error) {
            console.error('Preview generation error:', error);
            this.showAlert(`Preview generation failed: ${error.message}`, 'error');
            this.showPreviewError(error.message);
        } finally {
            this.hideLoading();
        }
    },

    // Fetch preview from API
    async fetchPreview() {
        const requestData = this.getRequestPayload({
            patient_id: this.config.patientId,
            sections: this.state.selectedSections,
            date_from: this.state.dateFilters.dateFrom,
            date_to: this.state.dateFilters.dateTo,
            limit: 50
        });

        const response = await fetch(this.config.rootPath + this.config.apiEndpoints.preview, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(requestData)
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.message || `HTTP ${response.status}: ${response.statusText}`);
        }

        const result = await response.json();
        
        // Update CSRF token if provided
        if (result.data && result.data.csrf_token) {
            this.updateCsrfToken(result.data.csrf_token);
        }

        return result;
    },

    // Display preview content
    displayPreview(data) {
        const { patient, preview_fragments, metadata } = data;
        
        let html = `
            <div class="preview-header">
                <h4>Medical Record Preview - ${patient.name}</h4>
                <div class="preview-meta">
                    <span><i class="fas fa-layer-group"></i> ${metadata.total_sections} sections</span>
                    <span><i class="fas fa-clock"></i> ${new Date(metadata.generated_at).toLocaleString()}</span>
                </div>
            </div>
            <div class="preview-sections">
        `;

        // Display each section fragment
        Object.entries(preview_fragments).forEach(([section, fragment]) => {
            const sectionTitle = this.formatSectionTitle(section);
            html += `
                <div class="preview-section" data-section="${section}">
                    <h5><i class="fas fa-chevron-right"></i> ${sectionTitle}</h5>
                    <div class="section-content">${fragment}</div>
                </div>
            `;
        });

        html += '</div>';
        
        this.elements.previewContent.innerHTML = html;
        
        // Add section toggle functionality
        this.setupPreviewSectionToggles();
    },

    // Setup preview section toggles
    setupPreviewSectionToggles() {
        const sectionHeaders = this.elements.previewContent.querySelectorAll('.preview-section h5');
        
        sectionHeaders.forEach(header => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', () => {
                const section = header.parentElement;
                const content = section.querySelector('.section-content');
                const icon = header.querySelector('i');
                
                if (content.style.display === 'none') {
                    content.style.display = 'block';
                    icon.className = 'fas fa-chevron-down';
                } else {
                    content.style.display = 'none';
                    icon.className = 'fas fa-chevron-right';
                }
            });
        });
    },

    // Generate PDF
    async generatePDF() {
        if (this.state.selectedSections.length === 0) {
            this.showAlert('Please select at least one section to generate PDF', 'warning');
            return;
        }

        try {
            this.showLoading('Generating PDF document...');
            
            const requestData = this.getRequestPayload({
                patient_id: this.config.patientId,
                sections: this.state.selectedSections,
                date_from: this.state.dateFilters.dateFrom,
                date_to: this.state.dateFilters.dateTo,
                output: 'pdf'
            });

            const response = await fetch(this.config.rootPath + this.config.apiEndpoints.generate, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestData)
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                
                // Handle specific error cases
                if (response.status === 429) {
                    throw new Error('PDF generation rate limit exceeded. Please try again later.');
                } else if (response.status === 403) {
                    throw new Error('You do not have permission to generate PDF documents.');
                } else {
                    throw new Error(errorData.message || `HTTP ${response.status}: ${response.statusText}`);
                }
            }

            // Handle PDF download
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `medical_record_${this.config.patientId}_${this.formatDate(new Date())}.pdf`;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);

            this.showAlert('PDF generated and downloaded successfully', 'success');
        } catch (error) {
            console.error('PDF generation error:', error);
            this.showAlert(`PDF generation failed: ${error.message}`, 'error');
        } finally {
            this.hideLoading();
        }
    },

    // Print record (HTML)
    async printRecord() {
        if (this.state.selectedSections.length === 0) {
            this.showAlert('Please select at least one section to print', 'warning');
            return;
        }

        try {
            this.showLoading('Preparing print document...');
            
            const requestData = {
                patient_id: this.config.patientId,
                sections: this.state.selectedSections,
                date_from: this.state.dateFilters.dateFrom,
                date_to: this.state.dateFilters.dateTo,
                output: 'html'
            };

            const response = await fetch(this.config.rootPath + this.config.apiEndpoints.generate, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestData)
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.message || `HTTP ${response.status}: ${response.statusText}`);
            }

            const htmlContent = await response.text();
            
            // Open print window
            const printWindow = window.open('', '_blank');
            printWindow.document.write(htmlContent);
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();

            this.showAlert('Print document prepared successfully', 'success');
        } catch (error) {
            console.error('Print preparation error:', error);
            this.showAlert(`Print preparation failed: ${error.message}`, 'error');
        } finally {
            this.hideLoading();
        }
    },

    // Refresh preview
    refreshPreview() {
        if (this.state.selectedSections.length > 0) {
            this.generatePreview();
        } else {
            this.clearPreview();
        }
    },

    // Clear preview content
    clearPreview() {
        this.elements.previewContent.innerHTML = `
            <div class="preview-placeholder">
                <i class="fas fa-file-medical-alt"></i>
                <h4>Medical Record Preview</h4>
                <p>Select sections and click "Preview Record" to see the medical record content.</p>
            </div>
        `;
    },

    // Show preview error
    showPreviewError(message) {
        this.elements.previewContent.innerHTML = `
            <div class="preview-error">
                <i class="fas fa-exclamation-triangle"></i>
                <h4>Preview Error</h4>
                <p>${message}</p>
                <button class="btn btn-primary" onclick="MedicalPrint.generatePreview()">
                    <i class="fas fa-redo"></i> Try Again
                </button>
            </div>
        `;
    },

    // Debounced preview generation
    debouncedPreview: (() => {
        let timeout;
        return function() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                this.generatePreview();
            }, this.config.debounceDelay);
        };
    })(),

    // Handle keyboard shortcuts
    handleKeyboardShortcuts(event) {
        if (event.ctrlKey || event.metaKey) {
            switch (event.key) {
                case 'p':
                    event.preventDefault();
                    this.printRecord();
                    break;
                case 'a':
                    if (event.shiftKey) {
                        event.preventDefault();
                        this.selectAll();
                    }
                    break;
                case 'Escape':
                    this.clearAll();
                    break;
            }
        }
    },

    // Utility: Format section title
    formatSectionTitle(section) {
        return section
            .split('_')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ');
    },

    // Utility: Format date
    formatDate(date) {
        return date.toISOString().split('T')[0];
    },

    // UI: Show loading overlay
    showLoading(message = 'Loading...') {
        this.state.isLoading = true;
        this.elements.loadingText.textContent = message;
        this.elements.loadingOverlay.style.display = 'flex';
    },

    // UI: Hide loading overlay
    hideLoading() {
        this.state.isLoading = false;
        this.elements.loadingOverlay.style.display = 'none';
    },

    // UI: Show alert message
    showAlert(message, type = 'info', duration = 5000) {
        const alertId = 'alert_' + Date.now();
        const iconMap = {
            success: 'fas fa-check-circle',
            error: 'fas fa-exclamation-circle',
            warning: 'fas fa-exclamation-triangle',
            info: 'fas fa-info-circle'
        };

        const alertHtml = `
            <div class="alert alert-${type}" id="${alertId}">
                <i class="${iconMap[type]}"></i>
                <span>${message}</span>
                <button type="button" class="btn-close" onclick="this.parentElement.remove();">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        this.elements.alertContainer.insertAdjacentHTML('beforeend', alertHtml);

        // Auto-dismiss
        if (duration > 0) {
            setTimeout(() => {
                const alertElement = document.getElementById(alertId);
                if (alertElement) {
                    alertElement.style.opacity = '0';
                    setTimeout(() => alertElement.remove(), 300);
                }
            }, duration);
        }
    },

    // Update UI based on user permissions
    updateUIForPermissions() {
        // Hide PDF generation button if user can't print
        if (!this.config.userPermissions.canPrint) {
            if (this.elements.generatePdfBtn) {
                this.elements.generatePdfBtn.style.display = 'none';
            }
        }

        // Hide print button if user can't print
        if (!this.config.userPermissions.canPrint) {
            if (this.elements.printBtn) {
                this.elements.printBtn.style.display = 'none';
            }
        }

        // Add permission indicators
        this.addPermissionIndicators();
    },

    // Add visual indicators for permissions
    addPermissionIndicators() {
        const actionButtons = document.querySelector('.action-buttons');
        if (actionButtons && !this.config.userPermissions.canPrint) {
            const notice = document.createElement('div');
            notice.className = 'permission-notice';
            notice.innerHTML = '<i class="fas fa-info-circle"></i> PDF generation requires print permissions';
            actionButtons.appendChild(notice);
        }
    },

    // Update CSRF token from API response
    updateCsrfToken(newToken) {
        if (newToken) {
            this.config.csrfToken = newToken;
        }
    },

    // Get request payload with CSRF token
    getRequestPayload(data) {
        return {
            ...data,
            csrf_token: this.config.csrfToken
        };
    }
};

// Export for global access
window.MedicalPrint = MedicalPrint;