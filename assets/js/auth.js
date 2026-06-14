/**
 * 登录/注册页面JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    const tabBtn = document.querySelector('.tab-btn');
    const registerForm = document.getElementById('registerForm');
    const loginForm = document.querySelector('.auth-form');
    
    if (tabBtn) {
        tabBtn.addEventListener('click', function() {
            if (registerForm.style.display === 'none') {
                registerForm.style.display = 'block';
                loginForm.style.display = 'none';
                this.classList.add('active');
                this.textContent = '登录';
            } else {
                registerForm.style.display = 'none';
                loginForm.style.display = 'block';
                this.classList.remove('active');
                this.textContent = '注册新账号';
            }
        });

        // 初始状态下按钮文字为“注册新账号”，表示当前在登录表单
        tabBtn.textContent = '注册新账号';
    }
    
    // 表单验证
    const forms = document.querySelectorAll('.auth-form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const inputs = this.querySelectorAll('input[required], select[required]');
            let isValid = true;
            
            inputs.forEach(input => {
                if (!input.value.trim()) {
                    isValid = false;
                    input.style.borderColor = '#ff6b6b';
                } else {
                    input.style.borderColor = 'rgba(255, 255, 255, 0.3)';
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                if (typeof window.showToast === 'function') {
                    window.showToast('请填写所有必填项', 'error');
                }
            }
        });
    });
});
