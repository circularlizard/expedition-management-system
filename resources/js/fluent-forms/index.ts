declare global {
    interface Window {
        emsFormMappings?: Record<string, any>;
        emsUnitMappings?: Record<string, string>;
        emsFields?: {
            scoutField: string;
            unitField: string;
            explorerEmailField: string;
            leaderEmailField: string;
            parentEmailField: string;
            parentOtpField: string;
            explorerOtpField: string;
            firstNameField: string;
            lastNameField: string;
            dobField: string;
            ajaxUrl: string;
            nonce: string;
            isLoggedIn: boolean;
            formId: number;
        };
        jQuery?: any;
    }
}

function emsGetChoices(el: HTMLElement) {
    return (window.jQuery && window.jQuery(el).data('choicesjs')) || null;
}

export function initEmsFormSync(): void {
    if (!window.emsFields) {
        return;
    }

    const fields = window.emsFields;
    const formMappings = window.emsFormMappings || {};
    const unitMappings = window.emsUnitMappings || {};

    const childSelect = document.querySelector<HTMLSelectElement>('select[name="' + fields.scoutField + '"]');
    const unitSelect  = document.querySelector<HTMLSelectElement>('select[name="' + fields.unitField + '"]');
    const unitIdInput = document.querySelector<HTMLInputElement>('input[name="signup_unitid"]');

    function updateLeaderEmail() {
        if (!unitSelect) return;
        const unitVal = unitSelect.value;
        if (!unitVal) return;
        const leaderEmail = unitMappings[unitVal] || unitMappings[unitVal.toLowerCase()] || '';

        (function trySetLeader(deadline: number) {
            const leaderEmailInput = document.querySelector<HTMLInputElement>('input[name="' + fields.leaderEmailField + '"]');
            if (leaderEmailInput) {
                leaderEmailInput.value = leaderEmail;
                leaderEmailInput.dispatchEvent(new Event('change', { bubbles: true }));
            } else if (Date.now() < deadline) {
                setTimeout(function() { trySetLeader(deadline); }, 100);
            }
        })(Date.now() + 3000);
    }

    if (unitSelect) {
        unitSelect.addEventListener('change', updateLeaderEmail);
        updateLeaderEmail();
    }

    function updateUnit() {
        if (!childSelect) return;
        const val = childSelect.value;
        if (!val) return;
        const scoutId = val;
        const mapping = formMappings[scoutId];
        if (!mapping) return;

        // 1. Hidden scout_id field
        const scoutIdInput = document.querySelector<HTMLInputElement>('input[name="signup_scoutid"]');
        if (scoutIdInput) {
            scoutIdInput.value = scoutId;
            scoutIdInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // 2. Name elements
        const firstNameInput = document.querySelector<HTMLInputElement>('input[name="' + fields.firstNameField + '[first_name]"]');
        if (firstNameInput) {
            firstNameInput.value = mapping.firstName || '';
            firstNameInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
        const lastNameInput = document.querySelector<HTMLInputElement>('input[name="' + fields.lastNameField + '[last_name]"]');
        if (lastNameInput) {
            lastNameInput.value = mapping.lastName || '';
            lastNameInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // 3. Unit dropdown
        if (unitSelect && mapping.unitCode) {
            (function trySetUnit(deadline: number) {
                const choices = emsGetChoices(unitSelect);
                if (choices) {
                    try {
                        choices.setChoiceByValue(mapping.unitCode);
                        unitSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    } catch (e) {
                        console.warn('[EMS Sync] Choices.js failed to set unit choice:', mapping.unitCode, e);
                    }
                } else if (Date.now() < deadline) {
                    setTimeout(function() { trySetUnit(deadline); }, 100);
                } else {
                    unitSelect.value = mapping.unitCode;
                    unitSelect.dispatchEvent(new Event('change', { bubbles: true }));
                }
            })(Date.now() + 3000);
        }

        if (unitIdInput && mapping.unitId) {
            unitIdInput.value = mapping.unitId;
            unitIdInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // 4. Emails
        (function trySetExplorerEmail(deadline: number) {
            const explorerEmailInput = document.querySelector<HTMLInputElement>('input[name="' + fields.explorerEmailField + '"]');
            if (explorerEmailInput) {
                explorerEmailInput.value = mapping.explorerEmail || '';
                explorerEmailInput.dispatchEvent(new Event('change', { bubbles: true }));
                if (mapping.explorerEmail) {
                    explorerEmailInput.classList.add('ff-read-only');
                } else {
                    explorerEmailInput.classList.remove('ff-read-only');
                }
            } else if (Date.now() < deadline) {
                setTimeout(function() { trySetExplorerEmail(deadline); }, 100);
            }
        })(Date.now() + 3000);

        (function trySetDob(deadline: number) {
            const dobInput = document.querySelector<HTMLInputElement & { _flatpickr?: any }>('input[name="' + fields.dobField + '"]');
            if (dobInput) {
                const rawDob = mapping.dob || '';
                let formattedDob = rawDob;
                if (rawDob && rawDob.includes('-')) {
                    const parts = rawDob.split('-');
                    if (parts.length === 3) {
                        formattedDob = parts[2] + '/' + parts[1] + '/' + parts[0];
                    }
                }
                console.log('[EMS Sync] Pre-populating DOB. Scout ID:', scoutId, 'Raw:', rawDob, 'Formatted:', formattedDob);
                if (dobInput._flatpickr) {
                    console.log('[EMS Sync] Flatpickr instance found. Setting date.');
                    if (rawDob && rawDob.includes('-')) {
                        const parts = rawDob.split('-');
                        const localDate = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
                        dobInput._flatpickr.setDate(localDate, true);
                    } else {
                        dobInput._flatpickr.setDate(rawDob, true);
                    }
                } else {
                    dobInput.value = formattedDob;
                    dobInput.dispatchEvent(new Event('change', { bubbles: true }));
                    dobInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
                if (rawDob) {
                    dobInput.classList.add('ff-read-only');
                    dobInput.setAttribute('readonly', 'readonly');
                    dobInput.style.pointerEvents = 'none';
                } else {
                    dobInput.classList.remove('ff-read-only');
                    dobInput.removeAttribute('readonly');
                    dobInput.style.pointerEvents = '';
                }
            } else if (Date.now() < deadline) {
                setTimeout(function() { trySetDob(deadline); }, 100);
            }
        })(Date.now() + 3000);
    }

    if (childSelect) {
        childSelect.addEventListener('change', updateUnit);

        const nonPlaceholderOptions = Array.from(childSelect.options).filter(function(o) {
            return o.value && formMappings[o.value];
        });

        if (nonPlaceholderOptions.length === 1) {
            const targetVal = nonPlaceholderOptions[0].value;
            (function trySetChild(deadline: number) {
                const choices = emsGetChoices(childSelect);
                if (choices) {
                    try {
                        choices.setChoiceByValue(targetVal);
                        childSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    } catch (e) {
                        console.warn('[EMS Sync] Choices.js failed to set child choice:', targetVal, e);
                    }
                } else if (Date.now() < deadline) {
                    setTimeout(function() { trySetChild(deadline); }, 100);
                } else {
                    childSelect.value = targetVal;
                    childSelect.dispatchEvent(new Event('change', { bubbles: true }));
                }
            })(Date.now() + 3000);
        } else {
            updateUnit();
        }
    }

    // --- OTP Verification Logic (Event Delegation) ---
    function getCacheKey(suffix: string): string {
        return 'ems_f' + fields.formId + '_' + suffix;
    }

    // Clear form-specific cache keys on successful submission
    if (window.jQuery) {
        window.jQuery(document).on('fluentform_submission_success', function() {
            const prefix = 'ems_f' + fields.formId + '_';
            for (let i = sessionStorage.length - 1; i >= 0; i--) {
                const key = sessionStorage.key(i);
                if (key && key.indexOf(prefix) === 0) {
                    sessionStorage.removeItem(key);
                }
            }
        });
    }

    document.addEventListener('click', function(e) {
        const target = e.target as HTMLElement | null;
        const btn = target ? target.closest<HTMLButtonElement>('.ems-otp-wrap button') : null;
        if (!btn) return;
        e.preventDefault();
        console.log('[EMS Sync] OTP Send Code button clicked:', btn);

        const container = btn.closest<HTMLElement>('.ems-otp-wrap');
        if (!container) {
            console.warn('[EMS Sync] OTP button wrapper not found for click.');
            return;
        }
        const targetField = container.getAttribute('data-target');
        if (!targetField) {
            console.warn('[EMS Sync] data-target attribute missing on OTP wrapper.');
            return;
        }
        console.log('[EMS Sync] Target email field name:', targetField);

        const emailInput = document.querySelector<HTMLInputElement>('input[name="' + targetField + '"]');
        let statusText = container.querySelector<HTMLElement>('.fluent-otp-status');

        if (!emailInput) {
            console.error('[EMS Sync] Email input element not found for name:', targetField);
            return;
        }
        if (!statusText) {
            console.log('[EMS Sync] Status text container missing, creating dynamically...');
            statusText = document.createElement('span');
            statusText.className = 'fluent-otp-status';
            statusText.style.marginLeft = '10px';
            statusText.style.fontSize = '0.9em';
            if (btn.parentNode) {
                btn.parentNode.insertBefore(statusText, btn.nextSibling);
            }
        }

        const email = emailInput.value.trim();
        console.log('[EMS Sync] Retrieved email value:', email);

        if (!email || !email.includes('@')) {
            statusText.style.color = '#dc3545';
            statusText.textContent = 'Please enter a valid email address first.';
            console.warn('[EMS Sync] Invalid email format, blocking dispatch.');
            return;
        }

        btn.disabled = true;
        statusText.style.color = '#6c757d';
        statusText.textContent = 'Sending code...';
        console.log('[EMS Sync] Sending AJAX request to send_fluent_otp...');

        const formData = new FormData();
        formData.append('action', 'send_fluent_otp');
        formData.append('email', email);
        formData.append('field_name', targetField);
        formData.append('security', fields.nonce);

        fetch(fields.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(res) { 
            console.log('[EMS Sync] Received response from send_fluent_otp server:', res);
            return res.json(); 
        })
        .then(function(data) {
            console.log('[EMS Sync] Decoded server response:', data);
            if (data.success) {
                if (statusText) {
                    statusText.style.color = '#28a745';
                    statusText.textContent = data.data.message || 'Code sent! Check your inbox.';
                }
                
                let countdown = 60;
                btn.textContent = 'Resend in ' + countdown + 's';
                const interval = setInterval(function() {
                    countdown--;
                    btn.textContent = 'Resend in ' + countdown + 's';
                    if (countdown <= 0) {
                        clearInterval(interval);
                        btn.disabled = false;
                        btn.textContent = 'Resend Verification Code';
                    }
                }, 1000);
            } else {
                btn.disabled = false;
                if (statusText) {
                    statusText.style.color = '#dc3545';
                    statusText.textContent = data.data.message || 'Error sending code.';
                }
            }
        })
        .catch(function(err) {
            btn.disabled = false;
            if (statusText) {
                statusText.style.color = '#dc3545';
                statusText.textContent = 'Network error. Please try again.';
            }
            console.error('[EMS Sync] Fetch error in send_fluent_otp:', err);
        });
    });

    // Real-time inline verification
    function checkOtp(otpInput: HTMLInputElement, emailFieldName: string) {
        if (fields.isLoggedIn && emailFieldName === fields.parentEmailField) {
            return;
        }
        const emailInput = document.querySelector<HTMLInputElement>('input[name="' + emailFieldName + '"]');
        if (!emailInput) {
            console.warn('[EMS Sync] Email input not found for inline verify name:', emailFieldName);
            return;
        }
        const email = emailInput.value.trim();
        const code = otpInput.value.trim();
        const otpFieldName = (emailFieldName === fields.parentEmailField) ? fields.parentOtpField : fields.explorerOtpField;
        console.log('[EMS Sync] Inline checkOtp triggered. Email:', email, 'Code:', code);

        const container = otpInput.closest('.ff-el-group');
        let statusEl = container ? container.querySelector<HTMLElement>('.ems-inline-otp-status') : null;
        if (container && !statusEl) {
            statusEl = document.createElement('span');
            statusEl.className = 'ems-inline-otp-status';
            statusEl.style.marginLeft = '10px';
            statusEl.style.fontSize = '0.9em';
            if (otpInput.parentNode) {
                otpInput.parentNode.insertBefore(statusEl, otpInput.nextSibling);
            }
        }

        if (!email || code.length !== 6) {
            if (statusEl) statusEl.textContent = '';
            otpInput.style.borderColor = '';
            return;
        }

        if (code === '000000') {
            const explorerEmailInput = document.querySelector<HTMLInputElement>('input[name="' + fields.explorerEmailField + '"]');
            const parentEmailInput   = document.querySelector<HTMLInputElement>('input[name="' + fields.parentEmailField + '"]');
            const explorerEmail = (explorerEmailInput ? explorerEmailInput.value.trim() : sessionStorage.getItem(getCacheKey('val_' + fields.explorerEmailField))) || '';
            const parentEmail   = (parentEmailInput ? parentEmailInput.value.trim() : sessionStorage.getItem(getCacheKey('val_' + fields.parentEmailField))) || '';
            
            if (explorerEmail && explorerEmail === parentEmail && otpFieldName === fields.explorerOtpField) {
                console.log('[EMS Sync] checkOtp: detected duplicate bypass code 000000, skipping AJAX verification.');
                if (statusEl) {
                    statusEl.style.color = '#28a745';
                    statusEl.textContent = '✓ Email verified!';
                }
                otpInput.style.borderColor = '#28a745';
                emailInput.setAttribute('readonly', 'readonly');
                emailInput.classList.add('ff-read-only');
                sessionStorage.setItem(getCacheKey('verified_' + emailFieldName), 'true');
                syncFormState();
                return;
            }
        }

        if (statusEl) {
            statusEl.style.color = '#6c757d';
            statusEl.textContent = 'Verifying code...';
        }
        console.log('[EMS Sync] Sending AJAX request to verify_fluent_otp for code:', code);

        const formData = new FormData();
        formData.append('action', 'verify_fluent_otp');
        formData.append('email', email);
        formData.append('field_name', emailFieldName);
        formData.append('code', code);
        formData.append('security', fields.nonce);

        fetch(fields.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            console.log('[EMS Sync] verify_fluent_otp response:', data);
            if (data.success) {
                sessionStorage.setItem(getCacheKey('verified_' + emailFieldName), 'true');
                sessionStorage.setItem(getCacheKey('val_' + otpFieldName), code);
                if (statusEl) {
                    statusEl.style.color = '#28a745';
                    statusEl.textContent = '✓ ' + (data.data.message || 'Email verified!');
                }
                otpInput.style.borderColor = '#28a745';
                emailInput.setAttribute('readonly', 'readonly');
                emailInput.classList.add('ff-read-only');
                syncFormState();
            } else {
                sessionStorage.removeItem(getCacheKey('verified_' + emailFieldName));
                if (statusEl) {
                    statusEl.style.color = '#dc3545';
                    statusEl.textContent = '✗ ' + (data.data.message || 'Incorrect code.');
                }
                otpInput.style.borderColor = '#dc3545';
                emailInput.removeAttribute('readonly');
                emailInput.classList.remove('ff-read-only');
            }
        })
        .catch(function(err) {
            if (statusEl) {
                statusEl.style.color = '#dc3545';
                statusEl.textContent = 'Verification error.';
            }
            console.error('[EMS Sync] verify_fluent_otp fetch error:', err);
        });
    }

    document.addEventListener('input', function(e) {
        const target = e.target as HTMLInputElement | null;
        if (!target || !target.name) return;
        if (target.name === fields.parentOtpField) {
            checkOtp(target, fields.parentEmailField);
        } else if (target.name === fields.explorerOtpField) {
            checkOtp(target, fields.explorerEmailField);
        }
    });

    // Helper to update React-controlled inputs safely
    function setInputValue(input: HTMLInputElement, value: string) {
        if (!input) return;
        const nativeInputValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value') ? 
            Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')!.set : null;
        if (nativeInputValueSetter) {
            nativeInputValueSetter.call(input, value);
        } else {
            input.value = value;
        }
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Helper to display/hide duplicate bypass warning banner
    function showDuplicateMessage(emailInput: HTMLInputElement | null, show: boolean) {
        if (!emailInput || !emailInput.parentNode) return;
        let dupStatusEl = emailInput.parentNode.querySelector<HTMLElement>('.ems-dup-email-status');
        if (show) {
            if (!dupStatusEl) {
                dupStatusEl = document.createElement('div');
                dupStatusEl.className = 'ems-dup-email-status';
                dupStatusEl.style.marginTop = '8px';
                dupStatusEl.style.padding = '6px 12px';
                dupStatusEl.style.backgroundColor = '#e6f4ea';
                dupStatusEl.style.color = '#137333';
                dupStatusEl.style.borderRadius = '4px';
                dupStatusEl.style.border = '1px solid #ceead6';
                dupStatusEl.style.fontSize = '0.9em';
                dupStatusEl.style.fontWeight = '500';
                emailInput.parentNode.insertBefore(dupStatusEl, emailInput.nextSibling);
            }
            dupStatusEl.style.setProperty('display', 'block', 'important');
            dupStatusEl.textContent = '✓ Email matches verified duplicate field (Verification bypassed).';
        } else {
            if (dupStatusEl) {
                dupStatusEl.style.setProperty('display', 'none', 'important');
                dupStatusEl.textContent = '';
            }
        }
    }

    // Dynamic Deduplication
    function checkDeduplicate() {
        const explorerEmailInput = document.querySelector<HTMLInputElement>('input[name="' + fields.explorerEmailField + '"]');
        const parentEmailInput   = document.querySelector<HTMLInputElement>('input[name="' + fields.parentEmailField + '"]');
        const explorerOtpInput   = document.querySelector<HTMLInputElement>('input[name="' + fields.explorerOtpField + '"]');
        const parentOtpInput     = document.querySelector<HTMLInputElement>('input[name="' + fields.parentOtpField + '"]');
        
        const explorerEmail = (explorerEmailInput ? explorerEmailInput.value.trim() : sessionStorage.getItem(getCacheKey('val_' + fields.explorerEmailField))) || '';
        const parentEmail   = (parentEmailInput ? parentEmailInput.value.trim() : sessionStorage.getItem(getCacheKey('val_' + fields.parentEmailField))) || '';

        console.log('[EMS Sync] checkDeduplicate check. explorerEmailInput:', !!explorerEmailInput, 'parentEmailInput:', !!parentEmailInput, 'explorerOtpInput:', !!explorerOtpInput, 'parentOtpInput:', !!parentOtpInput);
        console.log('[EMS Sync] comparing explorerEmail:', explorerEmail, 'with parentEmail:', parentEmail);

        if (explorerEmail && explorerEmail === parentEmail) {
            console.log('[EMS Sync] Duplicate email detected.');
            
            const parentTime = parseInt(sessionStorage.getItem(getCacheKey('time_' + fields.parentEmailField)) || '0', 10);
            const explorerTime = parseInt(sessionStorage.getItem(getCacheKey('time_' + fields.explorerEmailField)) || '0', 10);
            
            let secondField = fields.explorerEmailField; // Default
            if (parentTime > explorerTime) {
                secondField = fields.parentEmailField;
            }
            
            console.log('[EMS Sync] secondField entered is:', secondField);

            if (secondField === fields.explorerEmailField) {
                // Explorer is second -> bypass explorer
                if (explorerOtpInput) {
                    const otpGroup = explorerOtpInput.closest<HTMLElement>('.ff-el-group') || explorerOtpInput.closest<HTMLElement>('.ff-el-form-element') || explorerOtpInput.parentElement;
                    if (otpGroup) otpGroup.style.setProperty('display', 'none', 'important');
                    setInputValue(explorerOtpInput, '000000');
                }
                const btnWrap  = document.querySelector<HTMLElement>('.ems-otp-wrap[data-target="' + fields.explorerEmailField + '"]');
                if (btnWrap) btnWrap.style.setProperty('display', 'none', 'important');

                // 2. Restore parent OTP (since it is first/verified)
                if (parentOtpInput && !fields.isLoggedIn) {
                    const parentGroup = parentOtpInput.closest<HTMLElement>('.ff-el-group') || parentOtpInput.closest<HTMLElement>('.ff-el-form-element') || parentOtpInput.parentElement;
                    if (parentGroup && !parentOtpInput.classList.contains('ff-read-only') && sessionStorage.getItem(getCacheKey('verified_' + fields.parentEmailField)) !== 'true') {
                        parentGroup.style.removeProperty('display');
                    }
                    if (parentOtpInput.value === '000000') {
                        setInputValue(parentOtpInput, '');
                    }
                }
                const parentBtnWrap = document.querySelector<HTMLElement>('.ems-otp-wrap[data-target="' + fields.parentEmailField + '"]');
                if (parentBtnWrap && !fields.isLoggedIn && sessionStorage.getItem(getCacheKey('verified_' + fields.parentEmailField)) !== 'true') {
                    parentBtnWrap.style.removeProperty('display');
                }

                // 3. Show message on explorer email field, hide message on parent email field
                showDuplicateMessage(explorerEmailInput, true);
                showDuplicateMessage(parentEmailInput, false);

            } else {
                // Parent is second -> bypass parent
                if (fields.isLoggedIn) {
                    showDuplicateMessage(explorerEmailInput, false);
                    showDuplicateMessage(parentEmailInput, false);
                } else {
                    if (parentOtpInput) {
                        const otpGroup = parentOtpInput.closest<HTMLElement>('.ff-el-group') || parentOtpInput.closest<HTMLElement>('.ff-el-form-element') || parentOtpInput.parentElement;
                        if (otpGroup) otpGroup.style.setProperty('display', 'none', 'important');
                        setInputValue(parentOtpInput, '000000');
                    }
                    const btnWrap  = document.querySelector<HTMLElement>('.ems-otp-wrap[data-target="' + fields.parentEmailField + '"]');
                    if (btnWrap) btnWrap.style.setProperty('display', 'none', 'important');

                    if (explorerOtpInput) {
                        const explorerGroup = explorerOtpInput.closest<HTMLElement>('.ff-el-group') || explorerOtpInput.closest<HTMLElement>('.ff-el-form-element') || explorerOtpInput.parentElement;
                        if (explorerGroup && !explorerOtpInput.classList.contains('ff-read-only') && sessionStorage.getItem(getCacheKey('verified_' + fields.explorerEmailField)) !== 'true') {
                            explorerGroup.style.removeProperty('display');
                        }
                        if (explorerOtpInput.value === '000000') {
                            setInputValue(explorerOtpInput, '');
                        }
                    }
                    const explorerBtnWrap = document.querySelector<HTMLElement>('.ems-otp-wrap[data-target="' + fields.explorerEmailField + '"]');
                    if (explorerBtnWrap && sessionStorage.getItem(getCacheKey('verified_' + fields.explorerEmailField)) !== 'true') {
                        explorerBtnWrap.style.removeProperty('display');
                    }

                    showDuplicateMessage(parentEmailInput, true);
                    showDuplicateMessage(explorerEmailInput, false);
                }
            }
        } else {
            // No duplicate: restore both fields to normal
            if (explorerOtpInput) {
                const explorerGroup = explorerOtpInput.closest<HTMLElement>('.ff-el-group') || explorerOtpInput.closest<HTMLElement>('.ff-el-form-element') || explorerOtpInput.parentElement;
                if (explorerGroup) explorerGroup.style.removeProperty('display');
                if (explorerOtpInput.value === '000000') {
                    setInputValue(explorerOtpInput, '');
                }
            }
            const explorerBtnWrap = document.querySelector<HTMLElement>('.ems-otp-wrap[data-target="' + fields.explorerEmailField + '"]');
            if (explorerBtnWrap) explorerBtnWrap.style.removeProperty('display');

            if (parentOtpInput && !fields.isLoggedIn) {
                const parentGroup = parentOtpInput.closest<HTMLElement>('.ff-el-group') || parentOtpInput.closest<HTMLElement>('.ff-el-form-element') || parentOtpInput.parentElement;
                if (parentGroup) parentGroup.style.removeProperty('display');
                if (parentOtpInput.value === '000000') {
                    setInputValue(parentOtpInput, '');
                }
            }
            const parentBtnWrap = document.querySelector<HTMLElement>('.ems-otp-wrap[data-target="' + fields.parentEmailField + '"]');
            if (parentBtnWrap && !fields.isLoggedIn) parentBtnWrap.style.removeProperty('display');

            showDuplicateMessage(explorerEmailInput, false);
            showDuplicateMessage(parentEmailInput, false);
        }
    }

    // Multi-Page State Sync & Restoration
    function syncFormState() {
        const parentEmailInput   = document.querySelector<HTMLInputElement>('input[name="' + fields.parentEmailField + '"]');
        const parentOtpInput     = document.querySelector<HTMLInputElement>('input[name="' + fields.parentOtpField + '"]');
        const explorerEmailInput = document.querySelector<HTMLInputElement>('input[name="' + fields.explorerEmailField + '"]');
        const explorerOtpInput   = document.querySelector<HTMLInputElement>('input[name="' + fields.explorerOtpField + '"]');

        // 0. If user is logged in, make parent name fields read-only and bypass parent OTP code validation
        if (fields.isLoggedIn) {
            const parentNameInputs = document.querySelectorAll<HTMLInputElement>('input[name^="names["], input[name="parent_name"], input[name^="parent_name["]');
            parentNameInputs.forEach(function(input) {
                input.setAttribute('readonly', 'readonly');
                input.classList.add('ff-read-only');
                input.style.pointerEvents = 'none';
            });

            if (parentOtpInput && parentOtpInput.value !== '000000') {
                setInputValue(parentOtpInput, '000000');
            }
        }

        // 1. Sync values to sessionStorage when elements are visible
        if (parentEmailInput) sessionStorage.setItem(getCacheKey('val_' + fields.parentEmailField), parentEmailInput.value.trim());
        if (parentOtpInput) {
            if (parentOtpInput.value.trim()) {
                sessionStorage.setItem(getCacheKey('val_' + fields.parentOtpField), parentOtpInput.value.trim());
            }
        }
        if (explorerEmailInput) sessionStorage.setItem(getCacheKey('val_' + fields.explorerEmailField), explorerEmailInput.value.trim());
        if (explorerOtpInput) {
            if (explorerOtpInput.value !== '000000' && explorerOtpInput.value.trim()) {
                sessionStorage.setItem(getCacheKey('val_' + fields.explorerOtpField), explorerOtpInput.value.trim());
            }
        }

        // 2. Restore verified states (readonly and badge)
        [fields.parentEmailField, fields.explorerEmailField].forEach(function(emailFieldName) {
            const emailInput = document.querySelector<HTMLInputElement>('input[name="' + emailFieldName + '"]');
            if (!emailInput) return;
            
            let isVerified = sessionStorage.getItem(getCacheKey('verified_' + emailFieldName)) === 'true';
            const isOidcParent = fields.isLoggedIn && emailFieldName === fields.parentEmailField;
            if (isOidcParent) {
                isVerified = true;
            }

            if (isVerified) {
                emailInput.setAttribute('readonly', 'readonly');
                emailInput.classList.add('ff-read-only');
                
                // Ensure success badge is drawn
                const otpFieldName = (emailFieldName === fields.parentEmailField) ? fields.parentOtpField : fields.explorerOtpField;
                const otpInput = document.querySelector<HTMLInputElement>('input[name="' + otpFieldName + '"]');
                if (otpInput && (!fields.isLoggedIn || emailFieldName !== fields.parentEmailField)) {
                    const container = otpInput.closest('.ff-el-group');
                    let statusEl = container ? container.querySelector<HTMLElement>('.ems-inline-otp-status') : null;
                    if (container && !statusEl) {
                        statusEl = document.createElement('span');
                        statusEl.className = 'ems-inline-otp-status';
                        statusEl.style.marginLeft = '10px';
                        statusEl.style.fontSize = '0.9em';
                        if (otpInput.parentNode) {
                            otpInput.parentNode.insertBefore(statusEl, otpInput.nextSibling);
                        }
                    }
                    if (statusEl) {
                        statusEl.style.color = '#28a745';
                        statusEl.textContent = '✓ Email verified!';
                    }
                    otpInput.style.borderColor = '#28a745';
                    
                    // Restore code value if empty in DOM
                    const cachedCode = sessionStorage.getItem(getCacheKey('val_' + otpFieldName));
                    if (cachedCode && !otpInput.value) {
                        setInputValue(otpInput, cachedCode);
                    }
                }

                // Show Change link for non-OIDC verified emails
                if (!isOidcParent && emailInput.parentNode) {
                    let changeLink = emailInput.parentNode.querySelector<HTMLButtonElement>('.ems-otp-change-link');
                    if (!changeLink) {
                        changeLink = document.createElement('button');
                        changeLink.type = 'button';
                        changeLink.className = 'ems-otp-change-link ff-btn ff-btn-xs btn-link';
                        changeLink.style.marginLeft = '10px';
                        changeLink.style.padding = '0';
                        changeLink.style.fontSize = '0.9em';
                        changeLink.style.textDecoration = 'underline';
                        changeLink.style.color = '#007bff';
                        changeLink.style.cursor = 'pointer';
                        changeLink.style.border = 'none';
                        changeLink.style.background = 'none';
                        changeLink.textContent = 'Change';
                        
                        changeLink.addEventListener('click', function(evt) {
                            evt.preventDefault();
                            
                            sessionStorage.removeItem(getCacheKey('verified_' + emailFieldName));
                            sessionStorage.removeItem(getCacheKey('val_' + otpFieldName));
                            
                            emailInput.removeAttribute('readonly');
                            emailInput.classList.remove('ff-read-only');
                            
                            if (otpInput) {
                                setInputValue(otpInput, '');
                                otpInput.style.borderColor = '';
                                const otpContainer = otpInput.closest('.ff-el-group');
                                const inlineStatus = otpContainer ? otpContainer.querySelector<HTMLElement>('.ems-inline-otp-status') : null;
                                if (inlineStatus) {
                                    inlineStatus.textContent = '';
                                }
                            }
                            
                            changeLink?.remove();
                            syncFormState();
                        });

                        emailInput.parentNode.insertBefore(changeLink, emailInput.nextSibling);
                    }
                }
            } else {
                // Ensure change link is removed if verification status is false
                if (emailInput.parentNode) {
                    const existingLink = emailInput.parentNode.querySelector('.ems-otp-change-link');
                    if (existingLink) {
                        existingLink.remove();
                    }
                }
            }
        });

        // 3. Deduplicate
        checkDeduplicate();
    }

    document.addEventListener('input', function(e) {
        const target = e.target as HTMLInputElement | null;
        if (!target) return;
        const name = target.name;
        if (name === fields.explorerEmailField || name === fields.parentEmailField) {
            sessionStorage.setItem(getCacheKey('time_' + name), Date.now().toString());
            syncFormState();
        }
    });
    document.addEventListener('change', function(e) {
        const target = e.target as HTMLInputElement | null;
        if (!target) return;
        const name = target.name;
        if (name === fields.explorerEmailField || name === fields.parentEmailField) {
            sessionStorage.setItem(getCacheKey('time_' + name), Date.now().toString());
            syncFormState();
        }
    });
    document.addEventListener('keyup', function(e) {
        const target = e.target as HTMLInputElement | null;
        if (!target) return;
        const name = target.name;
        if (name === fields.explorerEmailField || name === fields.parentEmailField) {
            sessionStorage.setItem(getCacheKey('time_' + name), Date.now().toString());
            syncFormState();
        }
    });

    // Periodic polling handles page-change rendering instantly
    setInterval(syncFormState, 1000);
    setTimeout(syncFormState, 500);
}

// Auto-run when DOM is ready
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initEmsFormSync);
    } else {
        initEmsFormSync();
    }
}
