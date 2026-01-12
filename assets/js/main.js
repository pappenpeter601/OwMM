// Mobile Navigation Toggle
document.addEventListener('DOMContentLoaded', function() {
    const navToggle = document.getElementById('navToggle');
    const navMenu = document.getElementById('navMenu');
    
    if (navToggle && navMenu) {
        navToggle.addEventListener('click', function() {
            navMenu.classList.toggle('active');
        });
        
        // Close menu when clicking outside
        document.addEventListener('click', function(event) {
            if (!navToggle.contains(event.target) && !navMenu.contains(event.target)) {
                navMenu.classList.remove('active');
            }
        });
    }
});

// Smooth Scrolling for Anchor Links
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        if (href !== '#' && href !== '') {
            e.preventDefault();
            const target = document.querySelector(href);
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }
    });
});

// Form Validation Helper
function validateEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

// Image Preview for File Inputs
function handleImagePreview(input, previewElementId) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById(previewElementId);
            if (preview) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Confirm Delete
function confirmDelete(message = 'Sind Sie sicher, dass Sie diesen Eintrag löschen möchten?') {
    return confirm(message);
}

// Toast Notification (Simple)
function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    toast.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 1rem 1.5rem;
        background-color: ${type === 'success' ? '#4caf50' : '#f44336'};
        color: white;
        border-radius: 4px;
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        z-index: 10000;
        animation: slideIn 0.3s ease;
    `;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// Add CSS for toast animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// Load More Functionality for Operations/Events
let currentPage = 1;

function loadMore(type, buttonElement) {
    currentPage++;
    const button = buttonElement;
    button.disabled = true;
    button.textContent = 'Laden...';
    
    fetch(`api/load_more.php?type=${type}&page=${currentPage}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.items.length > 0) {
                appendItems(type, data.items);
                button.disabled = false;
                button.textContent = 'Mehr laden';
                
                if (!data.has_more) {
                    button.style.display = 'none';
                }
            } else {
                button.style.display = 'none';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            button.disabled = false;
            button.textContent = 'Fehler - Erneut versuchen';
        });
}

function appendItems(type, items) {
    const container = document.querySelector(`.${type}-list, .${type}-grid`);
    if (!container) return;
    
    items.forEach(item => {
        const element = createItemElement(type, item);
        container.appendChild(element);
    });
}

function createItemElement(type, item) {
    // This would create the appropriate HTML element based on type
    // Implementation depends on your specific structure
    const div = document.createElement('div');
    div.className = `${type}-item`;
    div.innerHTML = `<!-- Your item HTML here -->`;
    return div;
}

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.animation = 'fadeOut 0.5s ease';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });
});

// Add fade out animation
const fadeStyle = document.createElement('style');
fadeStyle.textContent = `
    @keyframes fadeOut {
        from { opacity: 1; }
        to { opacity: 0; }
    }
`;
document.head.appendChild(fadeStyle);
