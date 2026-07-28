<!-- ================================================
     Loading Spinner Overlay
     Include this file on every page that has forms
     ================================================ -->

<div id="spinner-overlay" style="display:none;">
  <div class="spinner-content">
    <div class="spinner-circle"></div>
    <p class="spinner-message" id="spinner-message">Processing...</p>
  </div>
</div>

<style>
/* ------------------------------------------------
   Spinner Overlay
------------------------------------------------ */
#spinner-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.5);
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
}

.spinner-content {
  background: white;
  border-radius: 12px;
  padding: 2rem 3rem;
  text-align: center;
  border-top: 4px solid #1a5c1a;
  box-shadow: 0 4px 20px rgba(0,0,0,0.3);
  min-width: 250px;
}

.spinner-circle {
  width: 50px;
  height: 50px;
  border: 5px solid #f0f0f0;
  border-top: 5px solid #1a5c1a;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin: 0 auto 1rem auto;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.spinner-message {
  color: #1a5c1a;
  font-size: 1rem;
  font-weight: 600;
  margin: 0;
  font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
</style>

<script>
// ------------------------------------------------
// Spinner Functions
// ------------------------------------------------
function showSpinner(message) {
  document.getElementById('spinner-message').textContent = message || 'Processing...';
  document.getElementById('spinner-overlay').style.display = 'flex';
}

function hideSpinner() {
  document.getElementById('spinner-overlay').style.display = 'none';
}

// ------------------------------------------------
// Auto-attach spinner to forms based on action
// ------------------------------------------------
document.addEventListener('DOMContentLoaded', function() {
  
  // Define messages for each action
  const actionMessages = {
    'save_period':        'Saving testing window...',
    'save_dates':         'Saving test dates...',
    'save_rooms':         'Saving room assignments...',
    'add_test':           'Adding AP test...',
    'delete_test':        'Deleting AP test...',
    'add_teacher':        'Adding teacher...',
    'update_teacher':     'Updating teacher...',
    'toggle_active':      'Updating teacher status...',
    'delete_teacher':     'Deleting teacher...',
    'bulk_upload':        'Uploading teachers...',
    'upload_roster':      'Uploading student roster...',
    'clear_roster':       'Clearing student rosters...',
    'generate_schedule':  'Generating proctor schedule. This may take a moment...',
    'generate_seating':   'Generating seating charts for all AP tests...',
    'download_seating':   'Preparing seating chart download...',
    'download_by_date':   'Preparing schedule download...',
    'download_by_teacher':'Preparing schedule download...',
    'clear_schedule':     'Clearing proctor schedule...',
    'override_proctor':   'Saving proctor assignment...',
    'remove_proctor':     'Removing proctor assignment...',
  };

  // Attach to all forms
  document.querySelectorAll('form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
      
      // Get the action value from hidden input or submit button
      const actionInput = form.querySelector('input[name="action"]');
      const action = actionInput ? actionInput.value : '';
      
      // Get message for this action
      const message = actionMessages[action] || 'Processing...';
      
      // Don't show spinner for download actions
      // (they trigger file download not page reload)
      const downloadActions = [
        'download_seating', 
        'download_by_date', 
        'download_by_teacher'
      ];
      
      if (downloadActions.includes(action)) {
        // Show briefly then hide for downloads
        showSpinner(message);
        setTimeout(hideSpinner, 3000);
        return;
      }

      // Show spinner for all other actions
      showSpinner(message);
    });
  });
});
</script>