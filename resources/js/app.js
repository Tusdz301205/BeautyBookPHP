import './modules.js';

const wizard = document.querySelector('[data-booking-wizard]')
if (wizard) {
    const form = wizard.querySelector('[data-booking-form]')
    const field = (name) => form.elements.namedItem(name)
    const offering = field('branch_service_offering_id'), staff = field('staff_profile_id'), start = field('start_at')
    const day = form.querySelector('[data-booking-day]'), slots = form.querySelector('[data-booking-slots]')
    const next = form.querySelector('[data-booking-next]'), back = form.querySelector('[data-booking-back]'), confirm = form.querySelector('[data-confirm-booking]'), check = form.querySelector('[data-check-availability]')
    const feedback = form.querySelector('[data-booking-feedback]')
    const allowed = wizard.dataset.selfBookingAllowed !== 'false'
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? ''
    let step = 1, quoteValid = false, available = false, busy = false, submitted = false, key = null
    let cart = []
    let eligibleStaffCount = 0
    let quoteEpoch = 0, slotEpoch = 0, availabilityEpoch = 0
    const titles = ['Chọn dịch vụ', 'Chọn chuyên viên', 'Chọn thời gian', 'Thông tin của bạn', 'Xác nhận lịch hẹn']
    const say = (text, state = '') => { feedback.textContent = text; feedback.dataset.state = state }
    const money = (value, currency) => new Intl.NumberFormat('vi-VN', { style: 'currency', currency, maximumFractionDigits: 0 }).format(Number(value))
    const request = async (url, body, method = 'POST', headers = {}) => {
        const response = await fetch(url, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, ...headers }, ...(method === 'GET' ? {} : { body: JSON.stringify(body) }) })
        const data = await response.json().catch(() => ({}))
        if (!response.ok) { const error = new Error(Object.values(data.errors ?? {}).flat()[0] ?? data.message ?? 'Không thể xử lý yêu cầu. Vui lòng thử lại.'); error.status = response.status; throw error }
        return data
    }
    const selection = () => ({ items: cart.map(item => ({ ...item })), staff_profile_id: staff.value ? Number(staff.value) : null, start_at: start.value, voucher_code: field('voucher_code')?.value?.trim() || null, loyalty_points: Number(field('loyalty_points')?.value || 0) })
    const quoteSelection = () => {
        const { items, voucher_code, loyalty_points } = selection()
        return { items, voucher_code, loyalty_points }
    }
    const syncActions = () => {
        const valid = step === 1 ? cart.length > 0 && quoteValid : step === 2 ? cart.length > 0 && quoteValid && eligibleStaffCount > 0 : step === 3 ? available : quoteValid && available
        next.disabled = !allowed || busy || !valid || submitted
        confirm.disabled = !allowed || busy || !available || !quoteValid || submitted
        check.disabled = !allowed || busy || !start.value || submitted
        back.disabled = busy || submitted
        // Keep all editable fields stable during the final request, including its idempotency payload.
        form.querySelectorAll('input, select, textarea, [data-select-offering], [data-select-staff], [data-slot], [data-cart-remove], [data-cart-move]').forEach((element) => {
            if (element.matches('option')) return
            element.disabled = submitted || (busy && step === 5) || element.dataset.boundary === 'true'
        })
        form.setAttribute('aria-busy', String(busy))
    }
    const resetTime = (clearDay = true) => {
        slotEpoch++; availabilityEpoch++; available = false; start.value = ''; key = null
        if (clearDay) day.value = ''
        slots.replaceChildren(); wizard.querySelector('[data-summary-time]').textContent = '—'; syncActions()
    }
    const syncSelections = () => {
        wizard.querySelectorAll('[data-select-offering]').forEach((button) => button.setAttribute('aria-pressed', String(cart.some(item => String(item.branch_service_offering_id) === button.dataset.selectOffering))))
        wizard.querySelector('[data-select-staff=""]').setAttribute('aria-pressed', String(staff.value === ''))
        let count = 0
        Array.from(staff.options).forEach((option) => {
            if (!option.value) return
            const capabilities = JSON.parse(option.dataset.offerings || '[]').map(Number)
            const eligible = cart.length > 0 && cart.every(item => capabilities.includes(item.branch_service_offering_id))
            option.hidden = option.disabled = !eligible
            const button = wizard.querySelector(`[data-select-staff="${option.value}"]`)
            button.hidden = !eligible; button.setAttribute('aria-pressed', String(option.value === staff.value)); if (eligible) count++
        })
        wizard.querySelector('[data-staff-empty]').hidden = count > 0
        eligibleStaffCount = count
        wizard.querySelector('[data-select-staff=""]').dataset.boundary = String(count === 0)
        wizard.querySelector('[data-summary-staff]').textContent = staff.selectedOptions[0]?.textContent || 'Bất kỳ chuyên viên phù hợp'
    }
    const renderCart = () => {
        const selected = wizard.querySelector('[data-selected-items]'); selected.replaceChildren()
        wizard.querySelector('[data-booking-cart]').hidden = cart.length === 0
        wizard.querySelector('[data-cart-count]').textContent = `(${cart.length}/10)`
        cart.forEach((item, index) => {
            const row = document.createElement('div'); row.className = 'booking-cart-row panel'
            const name = [...offering.options].find(option => Number(option.value) === item.branch_service_offering_id)?.textContent || 'Dịch vụ'
            const title = document.createElement('strong'); title.textContent = `${index + 1}. ${name}`; row.append(title)
            const select = wizard.querySelector(`[data-variants-offering="${item.branch_service_offering_id}"]`).content.firstElementChild.cloneNode(true)
            select.dataset.cartVariant = String(item.branch_service_offering_id); select.value = item.service_variant_id ? String(item.service_variant_id) : ''; row.append(select)
            const price = document.createElement('p'); price.className = 'muted-note'; price.dataset.cartPrice = String(item.branch_service_offering_id); price.textContent = 'Đang cập nhật giá dịch vụ…'; row.append(price)
            const actions = document.createElement('div'); actions.className = 'action-row'
            for (const [label, direction] of [['Lên', -1], ['Xuống', 1]]) {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.dataset.cartMove = String(item.branch_service_offering_id); button.dataset.direction = String(direction); button.dataset.boundary = String(index + direction < 0 || index + direction >= cart.length); button.textContent = label; button.setAttribute('aria-label', `${label === 'Lên' ? 'Đưa lên trước' : 'Đưa xuống sau'}: ${name}`); actions.append(button)
            }
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button'; remove.dataset.cartRemove = String(item.branch_service_offering_id); remove.textContent = 'Bỏ chọn'; remove.setAttribute('aria-label', `Bỏ chọn ${name}`); actions.append(remove); row.append(actions); selected.append(row)
        })
        syncSelections(); syncActions()
    }
    const cartChanged = () => {
        const active = document.activeElement
        const controlId = active?.dataset.cartVariant || active?.dataset.cartMove || active?.dataset.cartRemove
        const direction = active?.dataset.direction
        const isVariant = active?.hasAttribute('data-cart-variant')
        offering.value = cart[0] ? String(cart[0].branch_service_offering_id) : ''; staff.value = ''; resetTime(); renderCart(); refreshQuote()
        if (controlId) {
            const replacement = !isVariant && direction ? wizard.querySelector(`[data-cart-move="${controlId}"][data-direction="${direction}"]:not(:disabled)`) : null
            const fallback = replacement || wizard.querySelector(`[data-cart-variant="${controlId}"]`) || wizard.querySelector('[data-cart-variant]') || wizard.querySelector('[data-select-offering]')
            fallback?.focus({ preventScroll: true })
        }
    }
    const slotParams = () => {
        const params = new URLSearchParams({ date: day.value })
        cart.forEach((item, index) => {
            params.set(`items[${index}][branch_service_offering_id]`, String(item.branch_service_offering_id))
            if (item.service_variant_id) params.set(`items[${index}][service_variant_id]`, String(item.service_variant_id))
        })
        if (staff.value) params.set('staff_profile_id', staff.value)
        return params
    }
    const refreshQuote = async () => {
        const epoch = ++quoteEpoch, snapshot = JSON.stringify(quoteSelection()); quoteValid = false; available = false; availabilityEpoch++; key = null; syncActions()
        wizard.querySelector('[data-quote-price]').textContent = '—'
        wizard.querySelector('[data-quote-duration]').textContent = '—'
        wizard.querySelector('[data-quote-service]').textContent = cart.length ? `${cart.length} dịch vụ đã chọn` : 'Chưa chọn dịch vụ'
        if (!cart.length) return
        say('Đang cập nhật giá…')
        try {
            const response = await request(wizard.dataset.quoteUrl, quoteSelection())
            const data = response.data ?? response
            if (epoch !== quoteEpoch || snapshot !== JSON.stringify(quoteSelection())) return
            wizard.querySelector('[data-quote-service]').textContent = data.items?.map(item => item.service_name + (item.variant_name ? ` · ${item.variant_name}` : '')).join(' + ') || data.service_name || `${cart.length} dịch vụ đã chọn`
            wizard.querySelector('[data-quote-price]').textContent = money(data.final_amount, data.currency)
            wizard.querySelector('[data-quote-duration]').textContent = `${data.duration_minutes} phút`
            for (const item of data.items || []) {
                const price = wizard.querySelector(`[data-cart-price="${item.branch_service_offering_id}"]`)
                if (price) price.textContent = `${money(item.unit_price, data.currency)} · ${item.duration_minutes} phút (trước ưu đãi)`
            }
            quoteValid = true; say('')
            if (step === 5) renderConfirmation()
            // A voucher changes the price, so reconfirm the currently selected time before submission.
            if (start.value) await verifyTime()
            else if (day.value) await loadSlots()
        } catch (error) { if (epoch === quoteEpoch) say(error.message, 'error') }
        finally { if (epoch === quoteEpoch) syncActions() }
    }
    const verifyTime = async () => {
        const epoch = ++availabilityEpoch, snapshot = JSON.stringify(selection()); available = false; syncActions()
        if (!start.value || !quoteValid) return
        say('Đang kiểm tra giờ đã chọn…')
        try {
            const data = await request(`${wizard.dataset.slotsUrl}?${slotParams()}`, null, 'GET')
            if (epoch !== availabilityEpoch || snapshot !== JSON.stringify(selection())) return
            available = data.slots?.some(slot => slot.value === start.value) === true; say(available ? 'Giờ đã chọn còn trống.' : 'Giờ đã chọn không còn trống. Vui lòng chọn giờ khác.', available ? 'success' : 'error')
        } catch (error) { if (epoch === availabilityEpoch) say(error.message, 'error') }
        finally { if (epoch === availabilityEpoch) syncActions() }
    }
    const loadSlots = async () => {
        resetTime(false); const epoch = ++slotEpoch
        if (!day.value || !cart.length || !quoteValid) return
        say('Đang tìm giờ còn trống…')
        const params = slotParams()
        try {
            const data = await request(`${wizard.dataset.slotsUrl}?${params}`, null, 'GET')
            if (epoch !== slotEpoch) return
            for (const slot of data.slots) {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'booking-slot'; button.dataset.slot = slot.value; button.textContent = slot.label; button.setAttribute('aria-pressed', 'false'); button.title = `${slot.label} – ${slot.end}`; slots.append(button)
            }
            say(data.slots.length ? 'Chọn giờ phù hợp với bạn.' : 'Ngày này chưa có giờ phù hợp. Vui lòng chọn ngày khác.', data.slots.length ? '' : 'error')
        } catch (error) { if (epoch === slotEpoch) say(error.message, 'error') }
    }
    const renderConfirmation = () => {
        const details = wizard.querySelector('[data-confirm-details]'); details.replaceChildren()
        for (const [label, value] of [['Dịch vụ', wizard.querySelector('[data-quote-service]').textContent], ['Chuyên viên', staff.selectedOptions[0]?.textContent || '—'], ['Thời gian', wizard.querySelector('[data-summary-time]').textContent], ['Khách hàng', field('contact[full_name]')?.value || wizard.querySelector('.booking-customer-details p')?.textContent || ''], ['Tổng tiền', wizard.querySelector('[data-quote-price]').textContent]]) {
            const row = document.createElement('div'), dt = document.createElement('dt'), dd = document.createElement('dd'); dt.textContent = label; dd.textContent = value; row.append(dt, dd); details.append(row)
        }
    }
    const showStep = (number) => {
        step = number; form.querySelectorAll('[data-booking-step]').forEach((panel) => { panel.hidden = Number(panel.dataset.bookingStep) !== step })
        wizard.querySelectorAll('[data-step-indicator]').forEach((item) => {
            const value = Number(item.dataset.stepIndicator); item.classList.toggle('bb-booking-progress__step--current', value === step)
            item.querySelector('.bb-booking-progress__number').classList.toggle('bb-booking-progress__number--done', value < step)
            if (value === step) item.setAttribute('aria-current', 'step'); else item.removeAttribute('aria-current')
        })
        wizard.querySelector('[data-step-label]').textContent = `Bước ${step} / 5`; wizard.querySelector('[data-step-title]').textContent = titles[step - 1]
        back.hidden = step === 1; next.hidden = step === 5; confirm.hidden = step !== 5
        if (step === 5) renderConfirmation()
        syncActions(); wizard.querySelector('[data-step-title]').focus({ preventScroll: true })
    }
    const validateDetails = () => {
        for (const input of form.querySelector('[data-booking-step="4"]').querySelectorAll('input, select, textarea')) if (!input.reportValidity()) return false
        const name = field('contact[full_name]'), phone = field('contact[phone]'), email = field('contact[email]')
        if (name && !phone.value.trim() && !email.value.trim()) { say('Vui lòng nhập điện thoại hoặc email của khách.', 'error'); phone.focus(); return false }
        return true
    }
    form.addEventListener('click', async (event) => {
        const serviceButton = event.target.closest('[data-select-offering]'), staffButton = event.target.closest('[data-select-staff]'), slotButton = event.target.closest('[data-slot]')
        const remove = event.target.closest('[data-cart-remove]'), move = event.target.closest('[data-cart-move]')
        if (serviceButton || remove) {
            const id = Number(serviceButton?.dataset.selectOffering || remove.dataset.cartRemove)
            const found = cart.findIndex(item => item.branch_service_offering_id === id)
            if (found >= 0) cart.splice(found, 1)
            else if (cart.length < 10) cart.push({ branch_service_offering_id: id, service_variant_id: null })
            else { say('Bạn có thể chọn tối đa 10 dịch vụ trong một lịch hẹn.', 'error'); return }
            cartChanged()
        }
        if (move && !move.disabled) {
            const index = cart.findIndex(item => item.branch_service_offering_id === Number(move.dataset.cartMove)), destination = index + Number(move.dataset.direction)
            if (index >= 0 && destination >= 0 && destination < cart.length) { [cart[index], cart[destination]] = [cart[destination], cart[index]]; cartChanged() }
        }
        if (staffButton) { staff.value = staffButton.dataset.selectStaff; staff.dispatchEvent(new Event('change')) }
        if (slotButton) {
            start.value = slotButton.dataset.slot; key = null
            slots.querySelectorAll('[data-slot]').forEach((button) => button.setAttribute('aria-pressed', String(button === slotButton)))
            const [date, time] = start.value.split('T')
            wizard.querySelector('[data-summary-time]').textContent = `${date.split('-').reverse().join('/')} · ${time}`
            await verifyTime()
        }
    })
    offering.addEventListener('change', () => {
        cart = offering.value ? [{ branch_service_offering_id: Number(offering.value), service_variant_id: null }] : []
        cartChanged()
    })
    form.addEventListener('change', (event) => {
        const select = event.target.closest('[data-cart-variant]')
        if (!select) return
        const item = cart.find(entry => entry.branch_service_offering_id === Number(select.dataset.cartVariant))
        if (item) { item.service_variant_id = select.value ? Number(select.value) : null; cartChanged() }
    })
    staff.addEventListener('change', () => { resetTime(); syncSelections() })
    day.addEventListener('change', loadSlots); check.addEventListener('click', verifyTime)
    for (const name of ['voucher_code', 'loyalty_points']) field(name)?.addEventListener('input', () => { quoteValid = false; available = false; quoteEpoch++; availabilityEpoch++; key = null; syncActions() })
    for (const name of ['voucher_code', 'loyalty_points']) field(name)?.addEventListener('change', refreshQuote)
    form.querySelectorAll('input, textarea, select').forEach((input) => input.addEventListener('input', () => { key = null }))
    next.addEventListener('click', () => { if (next.disabled) return; if (step === 4 && !validateDetails()) return; say(''); showStep(step + 1) })
    back.addEventListener('click', () => { if (step > 1 && !busy) { say(''); showStep(step - 1) } })
    form.addEventListener('submit', async (event) => {
        event.preventDefault(); if (step !== 5 || busy || submitted || !available || !quoteValid || !validateDetails()) return
        const payload = { ...selection(), note: field('note')?.value || null, source: field('source')?.value || null }
        if (field('violation_acknowledged')) payload.violation_acknowledged = field('violation_acknowledged').checked
        if (field('contact[full_name]')) payload.contact = { full_name: field('contact[full_name]').value, phone: field('contact[phone]').value || null, email: field('contact[email]').value || null }
        key ??= crypto.randomUUID(); busy = true; syncActions(); say('Đang gửi lịch hẹn…')
        try {
            const data = await request(wizard.dataset.storeUrl, payload, 'POST', { 'Idempotency-Key': key })
            submitted = true; wizard.querySelector('.booking-grid').hidden = true; wizard.querySelector('[data-booking-success]').hidden = false
            wizard.querySelector('[data-booking-code]').textContent = `Mã lịch: ${data.booking.booking_code}`; wizard.querySelector('[data-success-link]').href = data.redirect || `${wizard.dataset.boardUrl}?booking=${data.booking.id}`
            wizard.querySelector('[data-step-title]').textContent = 'Đặt lịch thành công'; say('')
        } catch (error) {
            if (error.status === 409) { key = null; resetTime(false); showStep(3) }
            say(error.message, 'error')
        } finally { busy = false; syncActions() }
    })
    renderCart(); showStep(1)
    const presetOffering = new URLSearchParams(window.location.search).get('offering')
    if (presetOffering && [...offering.options].some((option) => option.value === presetOffering && option.value)) {
        offering.value = presetOffering
        offering.dispatchEvent(new Event('change'))
    }
}
const bookingBoard = document.querySelector('[data-booking-board]')

document.querySelectorAll('[data-favorite-url]').forEach((button) => {
    const render = () => { button.textContent = button.getAttribute('aria-pressed') === 'true' ? 'Đã lưu · Bỏ lưu' : 'Lưu dịch vụ' }
    render()
    button.addEventListener('click', async () => {
        const saved = button.getAttribute('aria-pressed') === 'true'
        button.disabled = true
        try {
            const response = await fetch(saved ? button.dataset.removeUrl : button.dataset.favoriteUrl, {
                method: saved ? 'DELETE' : 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            })
            const data = await response.json().catch(() => ({}))
            if (!response.ok) throw new Error(data.message || 'Không thể cập nhật dịch vụ đã lưu.')
            button.setAttribute('aria-pressed', saved ? 'false' : 'true')
            render()
        } catch (error) {
            button.textContent = error.message
        } finally {
            button.disabled = false
        }
    })
})

if (bookingBoard) {
    const businessFilter = bookingBoard.querySelector('#booking-business'), branchFilter = bookingBoard.querySelector('#booking-branch')
    if (businessFilter && branchFilter) {
        const syncBranches = () => {
            for (const option of branchFilter.options) {
                if (!option.value) continue
                option.disabled = option.hidden = !!businessFilter.value && option.dataset.businessId !== businessFilter.value
                if (option.disabled && option.selected) branchFilter.value = ''
            }
        }
        businessFilter.addEventListener('change', () => { branchFilter.value = ''; syncBranches() })
        syncBranches()
    }
    const feedback = bookingBoard.querySelector('[data-booking-board-feedback]')
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? ''
    let busy = false

    const messageFrom = (payload, fallback) => {
        if (payload?.message) {
            return payload.message
        }

        const firstError = payload?.errors ? Object.values(payload.errors).flat()[0] : null
        return firstError ?? fallback
    }

    const setFeedback = (message, state = '') => {
        feedback.textContent = message
        feedback.dataset.state = state
    }

    const fetchJson = async (url, body = {}) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify(body),
        })
        const payload = await response.json().catch(() => ({}))

        if (!response.ok) {
            const error = new Error(messageFrom(payload, 'Không thể xử lý yêu cầu.'))
            error.status = response.status
            throw error
        }

        return payload
    }

    const runAction = async (button, body) => {
        if (busy) {
            return
        }

        busy = true
        button.disabled = true
        bookingBoard.setAttribute('aria-busy', 'true')
        setFeedback('Đang cập nhật lịch hẹn…')

        try {
            await fetchJson(button.dataset.url, body)
            setFeedback('Đã cập nhật lịch hẹn.', 'success')
            window.location.reload()
        } catch (error) {
            button.disabled = false
            setFeedback(error.message, 'error')
        } finally {
            busy = false
            bookingBoard.setAttribute('aria-busy', 'false')
        }
    }

    bookingBoard.addEventListener('submit', async (event) => {
        const changeForm = event.target.closest('[data-booking-change-form]')
        if (!changeForm) return
        event.preventDefault()
        if (busy || !changeForm.reportValidity()) return
        const body = { request_type: changeForm.dataset.requestType, reason: changeForm.elements.namedItem('reason').value || null }
        if (body.request_type === 'RESCHEDULE') body.proposed_local_start_at = changeForm.elements.namedItem('proposed_start_at').value
        else body.proposed_staff_profile_id = Number(changeForm.elements.namedItem('proposed_staff_profile_id').value)
        const button = changeForm.querySelector('button[type="submit"]')
        button.dataset.url = changeForm.dataset.url
        await runAction(button, body)
    })

    bookingBoard.addEventListener('click', async (event) => {
        const transitionButton = event.target.closest('[data-booking-transition]')
        if (transitionButton) {
            const body = { status: transitionButton.dataset.status }

            if (transitionButton.dataset.status === 'NO_SHOW') {
                const checkbox = bookingBoard.querySelector(
                    `[data-no-show-confirm="${transitionButton.dataset.bookingId}"]`,
                )

                if (!checkbox?.checked) {
                    setFeedback('Hãy xác nhận điều kiện không đến trước khi tiếp tục.', 'error')
                    checkbox?.focus()
                    return
                }

                body.confirmed_no_show = true
            }

            await runAction(transitionButton, body)
            return
        }

        const changeRequestButton = event.target.closest('[data-booking-change-request]')
        if (changeRequestButton) {
            await runAction(changeRequestButton, {
                request_type: changeRequestButton.dataset.requestType,
            })
            return
        }

        const reviewButton = event.target.closest('[data-change-request-review]')
        if (reviewButton) {
            await runAction(reviewButton, {})
            return
        }

        const itemButton = event.target.closest('[data-booking-item-transition]')
        if (itemButton) {
            await runAction(itemButton, {
                action: itemButton.dataset.action,
                expected_revision: Number(itemButton.dataset.revision),
            })
        }
    })
}

