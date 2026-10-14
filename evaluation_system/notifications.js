document.addEventListener("DOMContentLoaded", function() {
    // 1. Convert Flash Messages to SweetAlert2 Toasts
    const flashSuccess = document.querySelectorAll('.flash-success, .alert-success');
    const flashError = document.querySelectorAll('.flash-error, .alert-danger');

    // 0. Inject Custom CSS for MCNP-ISAP Toast
    const style = document.createElement('style');
    style.innerHTML = `
        /* Positioning the toast lower from the top */
        body.swal2-toast-shown .swal2-container.swal2-top-end {
            top: 25px !important;
            right: 25px !important;
        }

        /* Mobile & Tablet safe positioning */
        @media (max-width: 768px) {
            body.swal2-toast-shown .swal2-container.swal2-top-end,
            body.swal2-toast-shown .swal2-container.swal2-top,
            body.swal2-toast-shown .swal2-container.swal2-top-start {
                top: 10px !important;
                right: 12px !important;
                left: 12px !important;
                width: auto !important;
                max-width: calc(100vw - 24px) !important;
                margin: 0 auto !important;
                align-items: center !important;
                box-sizing: border-box !important;
            }
        }
        
        .mcnp-isap-toast {
            background: #ffffff !important;
            border-radius: 10px !important;
            box-shadow: 0 4px 14px rgba(0,0,0,0.08) !important;
            border-left: 5px solid #1a2f7a !important; /* MCNP Navy Blue */
            padding: 10px 16px !important;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
            width: auto !important;
            max-width: 360px !important;
            box-sizing: border-box !important;
        }
        @media (max-width: 768px) {
            .mcnp-isap-toast {
                width: 100% !important;
                max-width: calc(100vw - 24px) !important;
                margin: 0 auto !important;
            }
        }
        .mcnp-isap-toast .swal2-close {
            font-size: 20px !important;
            color: #64748b !important;
            padding: 4px 8px !important;
            margin: 0 0 0 8px !important;
            align-self: center !important;
            box-shadow: none !important;
            outline: none !important;
            line-height: 1 !important;
        }
        [data-theme="dark"] .mcnp-isap-toast .swal2-close {
            color: #cbd5e1 !important;
        }
        [data-theme="dark"] .mcnp-isap-toast {
            background: #1e1e2f !important;
            border-left: 5px solid #7a0000 !important; /* ISAP Maroon for dark mode */
            color: #ffffff !important;
            box-shadow: 0 4px 14px rgba(0,0,0,0.3) !important;
        }
        .mcnp-isap-toast .swal2-title {
            color: #1e293b !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            margin: 0 !important;
            text-align: left !important;
            line-height: 1.35 !important;
        }
        [data-theme="dark"] .mcnp-isap-toast .swal2-title {
            color: #cbd5e1 !important;
        }
        .mcnp-isap-toast .swal2-html-container {
            color: #1e293b !important;
            font-size: 13.5px !important;
            font-weight: 600 !important;
            margin: 0 !important;
            padding: 0 !important;
            text-align: left !important;
            line-height: 1.35 !important;
        }
        [data-theme="dark"] .mcnp-isap-toast .swal2-html-container {
            color: #cbd5e1 !important;
        }
        
        /* Make icon smaller and match screenshot */
        .mcnp-isap-toast .swal2-icon {
            transform: scale(0.68) !important;
            margin: 0 10px 0 0 !important;
            align-self: center !important;
        }
        
        .mcnp-isap-toast .swal2-icon.swal2-success {
            border-color: #86efac !important;
            color: #1a2f7a !important;
        }
        .mcnp-isap-toast .swal2-icon.swal2-success [class^="swal2-success-line"] {
            background-color: #1a2f7a !important;
        }
        .mcnp-isap-toast .swal2-icon.swal2-success .swal2-success-ring {
            border-color: rgba(134, 239, 172, 0.4) !important;
        }
        [data-theme="dark"] .mcnp-isap-toast .swal2-icon.swal2-success, 
        [data-theme="dark"] .mcnp-isap-toast .swal2-icon.swal2-success [class^="swal2-success-line"] {
            border-color: #7a0000 !important;
            color: #7a0000 !important;
            background-color: #7a0000 !important;
        }
        
        /* MCNP-ISAP Gradient Progress Bar */
        .mcnp-isap-toast .swal2-timer-progress-bar {
            background: linear-gradient(90deg, #1a2f7a, #7a0000) !important;
            height: 3px !important;
        }

        /* Return to Admin link styling */
        .mcnp-isap-toast .impersonate-return-link {
            color: #1a2f7a !important;
            font-weight: 700 !important;
            font-size: 13.5px !important;
            text-decoration: none !important;
            display: inline-block !important;
            margin-top: 4px !important;
            cursor: pointer !important;
            transition: color 0.15s ease, text-decoration 0.15s ease !important;
        }
        .mcnp-isap-toast .impersonate-return-link:hover {
            text-decoration: underline !important;
            color: #0d1b46 !important;
        }
        [data-theme="dark"] .mcnp-isap-toast .impersonate-return-link {
            color: #93c5fd !important;
        }
        [data-theme="dark"] .mcnp-isap-toast .impersonate-return-link:hover {
            color: #bfdbfe !important;
        }
        
        /* Modal Customization */
        .mcnp-isap-modal {
            border-radius: 16px !important;
            padding: 30px !important;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15) !important;
            font-family: 'Inter', sans-serif !important;
            background: rgba(255, 255, 255, 0.95) !important;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0,0,0,0.05);
        }
        [data-theme="dark"] .mcnp-isap-modal {
            background: rgba(30, 30, 47, 0.95) !important;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .mcnp-isap-modal .swal2-title {
            color: #1a2f7a !important; /* MCNP Navy Blue */
            font-weight: 700 !important;
        }
        [data-theme="dark"] .mcnp-isap-modal .swal2-title {
            color: #ffffff !important;
        }
        .mcnp-isap-modal .swal2-html-container {
            color: #475569 !important;
            font-size: 16px !important;
        }
        [data-theme="dark"] .mcnp-isap-modal .swal2-html-container {
            color: #cbd5e1 !important;
        }
        .mcnp-isap-modal .swal2-icon.swal2-warning {
            border-color: #7a0000 !important; /* ISAP Maroon */
            color: #7a0000 !important;
        }
        .mcnp-isap-modal .swal2-actions button {
            border-radius: 8px !important;
            font-weight: 600 !important;
            padding: 10px 24px !important;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .mcnp-isap-modal .swal2-actions button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
    `;
    document.head.appendChild(style);

    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        showCloseButton: true,
        timer: 3000,
        timerProgressBar: true,
        customClass: {
            popup: 'mcnp-isap-toast'
        },
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer)
            toast.addEventListener('mouseleave', Swal.resumeTimer)
        }
    });
    
    // Impersonation Toast - always show on page load / reload when active
    const impEl = document.getElementById('impersonationNotice');
    if (impEl) {
        const impName = impEl.getAttribute('data-name') || 'User';
        const returnUrl = impEl.getAttribute('data-url') || 'logout.php';
        Toast.fire({
            icon: 'success',
            html: `<div style="text-align: left; line-height: 1.35; font-size: 13.5px; font-weight: 600; color: #1e293b;">` +
                  `You are impersonating ${impName}.<br>` +
                  `<a href="${returnUrl}" class="impersonate-return-link" onclick="window.location.href='${returnUrl}'; event.stopPropagation();">Return to Admin</a>` +
                  `</div>`,
            timer: 8000,
            timerProgressBar: true
        });
    }

    flashSuccess.forEach(el => {
        // Prevent duplicate or broken impersonation toasts
        if (el.innerText.toLowerCase().includes('impersonat')) {
            el.style.display = 'none';
            return;
        }
        el.style.display = 'none'; // hide the old bulky banner
        Toast.fire({
            icon: 'success',
            title: el.innerText.trim()
        });
    });

    flashError.forEach(el => {
        el.style.display = 'none'; // hide the old bulky banner
        Toast.fire({
            icon: 'error',
            title: el.innerText.trim()
        });
    });
});

// 2. Intercept inline 'onclick' confirm() calls
document.addEventListener('click', function(e) {
    let el = e.target.closest('[onclick]');
    if (!el) return;
    
    let onclickStr = el.getAttribute('onclick');
    if (onclickStr && onclickStr.includes('confirm(')) {
        e.preventDefault();
        e.stopPropagation();
        
        // Extract the message from confirm('Message')
        let msgMatch = onclickStr.match(/confirm\(\s*(['"])(.*?)\1\s*\)/);
        let msg = msgMatch ? msgMatch[2] : 'Are you sure you want to proceed?';
        
        Swal.fire({
            title: 'Confirmation',
            text: msg,
            icon: 'warning',
            customClass: {
                popup: 'mcnp-isap-modal'
            },
            showCancelButton: true,
            confirmButtonColor: '#1a2f7a', // MCNP Navy Blue
            cancelButtonColor: '#7a0000',  // ISAP Maroon
            confirmButtonText: 'Yes',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                el.removeAttribute('onclick');
                if (el.tagName === 'A' && el.href) {
                    window.location.href = el.href;
                } else if (el.tagName === 'BUTTON' && el.type === 'submit' && el.form) {
                    el.form.submit();
                } else {
                    el.click(); // Trigger the click again without the intercept
                }
            }
        });
    }
}, true);

// 3. Intercept inline 'onsubmit' confirm() calls
document.addEventListener('submit', function(e) {
    let el = e.target;
    let onsubmitStr = el.getAttribute('onsubmit');
    if (onsubmitStr && onsubmitStr.includes('confirm(')) {
        e.preventDefault();
        e.stopPropagation();
        
        let msgMatch = onsubmitStr.match(/confirm\(\s*(['"])(.*?)\1\s*\)/);
        let msg = msgMatch ? msgMatch[2] : 'Are you sure you want to proceed?';
        
        Swal.fire({
            title: 'Confirmation',
            text: msg,
            icon: 'warning',
            customClass: {
                popup: 'mcnp-isap-modal'
            },
            showCancelButton: true,
            confirmButtonColor: '#1a2f7a', // MCNP Navy Blue
            cancelButtonColor: '#7a0000',  // ISAP Maroon
            confirmButtonText: 'Yes',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                el.removeAttribute('onsubmit');
                el.submit();
            }
        });
    }
}, true);

// 4. Override window.alert()
window.alert = function(msg) {
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'info',
        title: msg,
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        customClass: {
            popup: 'mcnp-isap-toast'
        }
    });
};
