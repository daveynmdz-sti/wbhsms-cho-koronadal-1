// This script assumes Cropper.js is loaded and available as window.Cropper
let cropper = null;
let cropperModal = null;

// User message display function
function showUserMessage(message, type = 'info') {
  // Try to find existing alert container or create one
  let alertContainer = document.querySelector('.alert-container');
  if (!alertContainer) {
    alertContainer = document.createElement('div');
    alertContainer.className = 'alert-container';
    alertContainer.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 9999;';
    document.body.appendChild(alertContainer);
  }

  // Create alert element
  const alert = document.createElement('div');
  alert.className = `alert alert-${type}`;
  alert.style.cssText = `
    padding: 12px 16px;
    margin: 5px 0;
    border-radius: 4px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    max-width: 300px;
    background: ${type === 'success' ? '#d4edda' : type === 'error' ? '#f8d7da' : '#d1ecf1'};
    border: 1px solid ${type === 'success' ? '#c3e6cb' : type === 'error' ? '#f5c6cb' : '#bee5eb'};
    color: ${type === 'success' ? '#155724' : type === 'error' ? '#721c24' : '#0c5460'};
  `;
  alert.textContent = message;

  // Add close button
  const closeBtn = document.createElement('button');
  closeBtn.innerHTML = '×';
  closeBtn.style.cssText = 'float: right; background: none; border: none; font-size: 18px; cursor: pointer; margin-left: 10px;';
  closeBtn.onclick = () => alert.remove();
  alert.appendChild(closeBtn);

  alertContainer.appendChild(alert);

  // Auto remove after 5 seconds
  setTimeout(() => {
    if (alert.parentNode) alert.remove();
  }, 5000);
}

function showCropperModal(imageSrc) {
  console.log('Creating cropper modal');
  
  // Remove existing modal if any
  if (cropperModal) {
    console.log('Removing existing modal');
    if (cropper) {
      cropper.destroy();
      cropper = null;
    }
    cropperModal.remove();
    cropperModal = null;
  }

  cropperModal = document.createElement('div');
  cropperModal.className = 'cropper-modal-bg';
  cropperModal.innerHTML = `
    <div class="cropper-modal-content">
      <div class="cropper-modal-header">
        <h3>Crop Your Profile Photo</h3>
        <button class="cropper-modal-close" type="button" onclick="document.getElementById('cancelCropBtn').click()">&times;</button>
      </div>
      <div class="cropper-container">
        <img id="cropperImage" src="${imageSrc}" alt="Crop Image" />
      </div>
      <div class="cropper-modal-actions">
        <button id="cropBtn" class="btn" type="button">✂️ Crop Image</button>
        <button id="cancelCropBtn" class="btn btn-cancel" type="button">❌ Cancel</button>
      </div>
    </div>
  `;
  document.body.appendChild(cropperModal);
  
  // Prevent body scrolling when modal is open
  document.body.style.overflow = 'hidden';
  
  // Add click outside to close
  cropperModal.addEventListener('click', function(e) {
    if (e.target === cropperModal) {
      document.getElementById('cancelCropBtn').click();
    }
  });

  const image = document.getElementById('cropperImage');
  
  // Wait for image to load before initializing cropper
  image.addEventListener('load', function() {
    console.log('Image loaded, initializing cropper');
    
    if (typeof window.Cropper === 'undefined') {
      showUserMessage('Cropper.js library not loaded. Please refresh the page and try again.', 'error');
      cropperModal.remove();
      cropperModal = null;
      return;
    }
    
    try {
      cropper = new window.Cropper(image, {
        aspectRatio: 1,
        viewMode: 2,
        autoCropArea: 0.9,
        movable: true,
        zoomable: true,
        scalable: true,
        rotatable: true,
        responsive: true,
        background: true,
        checkOrientation: false,
        modal: true,
        guides: true,
        center: true,
        highlight: true,
        cropBoxMovable: true,
        cropBoxResizable: true,
        dragMode: 'crop',
        minContainerWidth: 300,
        minContainerHeight: 300
      });
      console.log('Cropper initialized successfully');
    } catch (error) {
      console.error('Error initializing cropper:', error);
      showUserMessage('Error initializing image cropper. Please try again.', 'error');
      cropperModal.remove();
      cropperModal = null;
    }
  });
  
  image.addEventListener('error', function() {
    console.error('Error loading image for cropper');
    showUserMessage('Error loading image. Please try a different image.', 'error');
    cropperModal.remove();
    cropperModal = null;
  });

  document.getElementById('cropBtn').onclick = function() {
    if (!cropper) {
      showUserMessage('Cropper not initialized. Please try again.', 'error');
      return;
    }
    
    console.log('Cropping image...');
    
    try {
      const canvas = cropper.getCroppedCanvas({
        width: 400,
        height: 400,
        imageSmoothingQuality: 'high',
        fillColor: '#fff'
      });
      
      if (!canvas) {
        throw new Error('Failed to create cropped canvas');
      }
      
      canvas.toBlob(function(blob) {
        if (!blob) {
          showUserMessage('Error processing cropped image. Please try again.', 'error');
          return;
        }
        
        console.log('Cropped image created:', blob.size, 'bytes');
        
        try {
          // Set the blob as the file input for upload
          const fileInput = document.getElementById('profilePhotoInput');
          if (fileInput) {
            const dataTransfer = new DataTransfer();
            const croppedFile = new File([blob], 'cropped_profile.png', {type: 'image/png'});
            dataTransfer.items.add(croppedFile);
            fileInput.files = dataTransfer.files;
            console.log('File input updated with cropped image');
          }
          
          // Update preview
          const previewImg = document.getElementById('profilePhotoPreview');
          if (previewImg) {
            const previewUrl = canvas.toDataURL('image/png');
            previewImg.src = previewUrl;
            console.log('Preview updated');
          }
          
          // Enable save button and show success message
          const saveBtn = document.getElementById('savePhotoBtn');
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.style.backgroundColor = '#28a745';
            saveBtn.style.borderColor = '#28a745';
            console.log('Save button enabled and highlighted');
          }
          
          // Remove modal and restore body scroll
          cropper.destroy();
          cropperModal.remove();
          document.body.style.overflow = 'auto';
          cropperModal = null;
          cropper = null;
          
          console.log('Cropping completed successfully');
          
          // Show success message to user
          showUserMessage('Image cropped successfully! Click Save to update your profile.', 'success');
          
        } catch (error) {
          console.error('Error updating UI after crop:', error);
          showUserMessage('Error updating preview. Please try again.', 'error');
        }
        
      }, 'image/png', 0.9);
      
    } catch (error) {
      console.error('Error during cropping:', error);
      showUserMessage('Error cropping image. Please try again.', 'error');
    }
  };
  document.getElementById('cancelCropBtn').onclick = function() {
    console.log('Canceling crop operation');
    if (cropper) {
      cropper.destroy();
      cropper = null;
    }
    if (cropperModal) {
      cropperModal.remove();
      cropperModal = null;
    }
    document.body.style.overflow = 'auto';
    
    // Clear the file input
    const fileInput = document.getElementById('profilePhotoInput');
    if (fileInput) {
      fileInput.value = '';
    }
    
    // Reset save button
    const saveBtn = document.getElementById('savePhotoBtn');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.style.backgroundColor = '#6c757d';
      saveBtn.style.borderColor = '#6c757d';
    }
    
    showUserMessage('Cropping canceled', 'info');
  };
}

// Hook into the file input
window.addEventListener('DOMContentLoaded', function() {
  const fileInput = document.getElementById('profilePhotoInput');
  if (!fileInput) {
    console.log('Profile photo input not found');
    return;
  }
  
  console.log('Profile photo cropper initialized');
  
  fileInput.addEventListener('change', function(event) {
    const file = event.target.files[0];
    if (!file) return;
    
    // Validate file type
    const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    if (!validTypes.includes(file.type)) {
      showUserMessage('Please select a valid image file (JPEG, PNG, or GIF).', 'error');
      event.target.value = '';
      return;
    }
    
    // Validate file size
    if (file.size > 10 * 1024 * 1024) {
      showUserMessage('File is too large. Maximum size allowed is 10 MB.', 'error');
      event.target.value = '';
      return;
    }
    
    console.log('File selected:', file.name, 'Size:', file.size, 'Type:', file.type);
    
    const reader = new FileReader();
    reader.onload = function(e) {
      console.log('File loaded, showing cropper modal');
      showCropperModal(e.target.result);
    };
    reader.onerror = function() {
      showUserMessage('Error reading file. Please try again.', 'error');
      event.target.value = '';
    };
    reader.readAsDataURL(file);
  });
});
