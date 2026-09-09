// =====================================================
// شحن برو - ملف JavaScript الرئيسي
// =====================================================

// فتح نافذة الطلب
function openOrder(serviceId, serviceName, price, minQty, maxQty, fields) {
    document.getElementById('modal-title').textContent = serviceName;
    document.getElementById('modal-service-id').value = serviceId;
    document.getElementById('modal-price').textContent = price;
    document.getElementById('modal-qty').min = minQty;
    document.getElementById('modal-qty').max = maxQty;
    document.getElementById('modal-qty').value = minQty;
    document.getElementById('qty-min').textContent = minQty;
    document.getElementById('qty-max').textContent = maxQty;
    
    // توليد حقول إضافية
    const fieldsContainer = document.getElementById('custom-fields');
    fieldsContainer.innerHTML = '';
    
    if (fields && fields.length > 0) {
        fields.forEach(function(f) {
            const div = document.createElement('div');
            div.className = 'form-group';
            const required = f.is_required == 1 ? 'required' : '';
            const reqStar = f.is_required == 1 ? ' <span style="color:#ff4757">*</span>' : '';
            
            let inputHtml = '';
            if (f.field_type === 'select' && f.field_options) {
                const opts = f.field_options.split('\n').map(o => o.trim()).filter(o => o);
                inputHtml = `<select name="fields[${f.field_name}]" ${required}>
                    <option value="">-- اختر --</option>
                    ${opts.map(o => `<option value="${o}">${o}</option>`).join('')}
                </select>`;
            } else {
                inputHtml = `<input type="${f.field_type}" name="fields[${f.field_name}]" placeholder="${f.field_label}" ${required}>`;
            }
            
            div.innerHTML = `<label>${f.field_label}${reqStar}</label>${inputHtml}`;
            fieldsContainer.appendChild(div);
        });
    }
    
    updateTotal();
    document.getElementById('order-modal').classList.add('active');
}

function closeModal() {
    document.getElementById('order-modal').classList.remove('active');
}

function changeQty(delta) {
    const input = document.getElementById('modal-qty');
    let val = parseInt(input.value) + delta;
    val = Math.max(parseInt(input.min), Math.min(parseInt(input.max), val));
    input.value = val;
    updateTotal();
}

function updateTotal() {
    const qty = parseInt(document.getElementById('modal-qty').value) || 1;
    const price = parseFloat(document.getElementById('modal-price').textContent) || 0;
    document.getElementById('modal-total').textContent = (qty * price).toFixed(2);
}

// تصفية الخدمات حسب القسم
function filterCategory(catId, btn) {
    document.querySelectorAll('.sub-cat-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    document.querySelectorAll('.service-card').forEach(card => {
        if (catId === 'all' || card.dataset.cat == catId) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
}

// إغلاق المودال عند النقر خارجه
window.addEventListener('click', function(e) {
    const modal = document.getElementById('order-modal');
    if (modal && e.target === modal) closeModal();
});

// تأكيد الحذف
function confirmDelete(msg) {
    return confirm(msg || 'هل أنت متأكد من الحذف؟');
}

// تحديث السعر الإجمالي عند تغيير الكمية
document.addEventListener('input', function(e) {
    if (e.target.id === 'modal-qty') {
        const min = parseInt(e.target.min);
        const max = parseInt(e.target.max);
        if (e.target.value < min) e.target.value = min;
        if (e.target.value > max) e.target.value = max;
        updateTotal();
    }
});
