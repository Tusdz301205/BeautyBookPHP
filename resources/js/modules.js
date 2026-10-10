// Progressive enhancement: the server remains authoritative for every selection.
export function initializeModuleForms(scope = document) {
    const filterOptions = (select, accept) => {
        if (!select) return;
        for (const option of select.options) {
            const allowed = !option.value || accept(option);
            option.hidden = !allowed;
            option.disabled = !allowed;
            if (!allowed && option.selected) select.value = '';
        }
    };
    const ids = (value = '') => value.split(',').filter(Boolean);

    scope.querySelectorAll('[data-entitlement-booking]').forEach((booking) => {
        const form = booking.closest('form');
        const service = form.querySelector('[data-entitlement-service]');
        const sync = () => filterOptions(service, (option) => option.dataset.booking === booking.value);
        booking.addEventListener('change', sync);
        sync();
    });

    scope.querySelectorAll('[data-combo-items]').forEach((editor) => {
        const rows = editor.querySelector('[data-combo-rows]');
        const add = editor.querySelector('[data-combo-add]');
        const update = () => {
            const items = [...rows.querySelectorAll('[data-combo-row]')];
            items.forEach((row, index) => {
                row.querySelector('legend').textContent = `Dịch vụ ${index + 1}`;
                row.querySelectorAll('[name]').forEach((input) => {
                    input.name = input.name.replace(/items\[\d+\]/, `items[${index}]`);
                    if (input.name.endsWith('[sort_order]')) input.value = index;
                });
                row.querySelector('[data-combo-remove]').disabled = items.length <= 2;
            });
            add.disabled = items.length >= 20;
            editor.querySelector('[data-combo-count]').textContent = `${items.length}/20 dịch vụ`;
        };
        add.addEventListener('click', () => {
            if (rows.children.length >= 20) return;
            const copy = rows.querySelector('[data-combo-row]').cloneNode(true);
            copy.querySelectorAll('input, select').forEach((input) => {
                input.value = input.name.endsWith('[quantity]') ? 1 : input.name.endsWith('[transition_minutes]') ? 0 : '';
            });
            rows.append(copy);
            update();
            copy.querySelector('select').focus();
        });
        editor.addEventListener('click', (event) => {
            const remove = event.target.closest('[data-combo-remove]');
            if (!remove || rows.children.length <= 2) return;
            remove.closest('[data-combo-row]').remove();
            update();
            add.focus();
        });
        update();
    });

    scope.querySelectorAll('[data-recurring-form]').forEach((form) => {
        const offering = form.elements.branch_service_offering_id;
        const combo = form.elements.combo_id;
        const variant = form.elements.service_variant_id;
        const mode = form.elements.staff_mode;
        const staff = form.elements.staff_profile_id;
        const sync = () => {
            offering.required = !combo.value;
            combo.required = !offering.value;
            filterOptions(variant, (option) => option.dataset.offering === offering.value);
            variant.disabled = !offering.value;
            if (!offering.value) variant.value = '';
            const comboData = [...form.querySelectorAll('[data-combo]')].find((item) => item.dataset.combo === combo.value);
            const required = offering.value ? [offering.value] : ids(comboData?.dataset.offerings);
            filterOptions(staff, (option) => required.length > 0 && required.every((id) => ids(option.dataset.offerings).includes(id)));
            staff.disabled = mode.value !== 'SAME_STAFF';
            staff.required = !staff.disabled;
            const count = [...staff.options].filter((option) => option.value && !option.disabled).length;
            form.querySelector('[data-staff-hint]').textContent = mode.value === 'ANY_AVAILABLE'
                ? 'Hệ thống tìm nhân viên phù hợp cho từng kỳ trong bước xem trước.'
                : `${count} nhân viên phù hợp. Khung giờ được kiểm tra trong bước xem trước.`;
        };
        offering.addEventListener('change', () => { if (offering.value) combo.value = ''; sync(); });
        combo.addEventListener('change', () => { if (combo.value) offering.value = ''; sync(); });
        mode.addEventListener('change', sync);
        sync();
    });

    scope.querySelectorAll('[data-waitlist-join]').forEach((form) => {
        const offering = form.elements.branch_service_offering_id;
        const staff = form.elements.staff_profile_id;
        const sync = () => filterOptions(staff, (option) => ids(option.dataset.offerings).includes(offering.value));
        offering.addEventListener('change', sync);
        sync();
    });

    scope.querySelectorAll('[data-impact-resolution]').forEach((form) => {
        const resolution = form.elements.resolution;
        const staff = form.elements.replacement_staff_id;
        const branch = form.elements.replacement_branch_id;
        const time = form.elements.proposed_start_at;
        const showField = (input, visible, required = false) => {
            input.closest('label').hidden = !visible;
            input.disabled = !visible;
            input.required = required;
        };
        const sync = () => {
            const action = resolution.value;
            showField(staff, ['REASSIGN', 'TRANSFER_BRANCH'].includes(action), true);
            showField(branch, action === 'TRANSFER_BRANCH', true);
            showField(time, ['RESCHEDULE', 'TRANSFER_BRANCH'].includes(action), action === 'RESCHEDULE');
            const targetBranch = action === 'TRANSFER_BRANCH' ? branch.value : form.dataset.currentBranch;
            filterOptions(staff, (option) => option.dataset.branch === targetBranch);
            const descriptions = {
                REASSIGN: 'Chọn nhân viên tại chi nhánh hiện tại. Hệ thống kiểm tra khả năng thực hiện và lịch trống trước khi lưu.',
                RESCHEDULE: 'Chọn giờ bắt đầu mới. Dịch vụ và nhân viên được kiểm tra lại theo khung giờ.',
                TRANSFER_BRANCH: 'Chọn chi nhánh đích, sau đó chọn nhân viên tại chi nhánh đó. Dịch vụ tương đương và khung giờ phải còn khả dụng.',
                CANCEL_REFUND: 'Lịch được hủy; khoản hoàn tiền được gửi vào quy trình phê duyệt và đối soát.',
                APPROVED_EXCEPTION: 'Nêu rõ lý do phê duyệt ngoại lệ để tiếp tục giữ lịch hẹn.',
            };
            form.querySelector('[data-resolution-hint]').textContent = descriptions[action] || '';
        };
        resolution.addEventListener('change', sync);
        branch.addEventListener('change', sync);
        sync();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initializeModuleForms(), { once: true });
} else {
    initializeModuleForms();
}
