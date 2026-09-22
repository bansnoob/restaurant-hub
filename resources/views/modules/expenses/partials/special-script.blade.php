{{-- Alpine component backing the special-expense drawers. Included once per page that
     hosts them. --}}
    <script>
        function specialExpensesPage(config) {
            const blankForm = (month) => ({
                mode: 'create',
                action: '',
                branch_id: '',
                special_expense_category_id: '',
                new_category_name: '',
                period_month: month,
                paid_date: '',
                description: '',
                vendor_name: '',
                reference_no: '',
                amount: '',
                payment_method: 'cash',
                notes: '',
            });

            return {
                updateUrlTemplate: config.updateUrlTemplate,
                destroyUrlTemplate: config.destroyUrlTemplate,
                csrfToken: config.csrfToken,
                currentMonth: config.currentMonth,
                detailOpen: false,
                detail: {},
                formOpen: false,
                submitting: false,
                form: blankForm(config.currentMonth),

                openCreate() {
                    this.form = blankForm(this.currentMonth);
                    this.formOpen = true;
                    this.submitting = false;
                },
                openDetail(payload) {
                    this.detail = { ...payload };
                    this.detailOpen = true;
                },
                closeDetail() { this.detailOpen = false; },
                openEditFromDetail() {
                    if (!this.detail.id) return;
                    this.form = {
                        mode: 'edit',
                        action: this.updateUrlTemplate.replace('__SPECIAL__', this.detail.id),
                        branch_id: this.detail.branch_id ? String(this.detail.branch_id) : '',
                        special_expense_category_id: this.detail.special_expense_category_id ? String(this.detail.special_expense_category_id) : '',
                        new_category_name: '',
                        period_month: this.detail.period_month || this.currentMonth,
                        paid_date: this.detail.paid_date || '',
                        description: this.detail.description || '',
                        vendor_name: this.detail.vendor_name || '',
                        reference_no: this.detail.reference_no || '',
                        amount: this.detail.amount,
                        payment_method: this.detail.payment_method || 'cash',
                        notes: this.detail.notes || '',
                    };
                    this.detailOpen = false;
                    this.formOpen = true;
                    this.submitting = false;
                },
                closeForm() {
                    this.formOpen = false;
                    this.submitting = false;
                },
                deleteItem() {
                    if (!this.detail.id) return;
                    if (!confirm('Delete this special expense? This cannot be undone.')) return;
                    const f = document.createElement('form');
                    f.method = 'POST';
                    f.action = this.destroyUrlTemplate.replace('__SPECIAL__', this.detail.id);
                    f.innerHTML = `<input type="hidden" name="_token" value="${this.csrfToken}"><input type="hidden" name="_method" value="DELETE">`;
                    document.body.appendChild(f);
                    f.submit();
                },
                closeAll() {
                    this.detailOpen = false;
                    this.formOpen = false;
                },
                formatMethod(m) {
                    return ({ cash: 'Cash', gcash: 'GCash', bank_transfer: 'Bank Transfer', other: 'Other' })[m] || m;
                },
            };
        }
    </script>
